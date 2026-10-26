<?php
/**
 * Class — Attendance: ?id=N[&date=YYYY-MM-DD][&view=report]
 * Per designs/pages/createAttendence.html (sheet) and AttendencReports.html (report).
 * Teacher
 *   Take attendance  pick a date, mark every student Present / Late / Absent /
 *                    Official Present (+ remarks), "Mark all present", edit any past day
 *   Report           per student counts + attendance rate, CSV (attendance_export.php)
 *   POST save_attendance | clear_date   (class teacher, CSRF, date not in the future)
 * Student
 *   Their own record only: rate, counts, every marked day with its remark.
 * Students marked absent are notified.
 * Rules: every approved student on the sheet must get a status before it saves;
 * a student is only on the sheet for days on/after the day they joined the class.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!defined('ATT_MODULE') && $_SERVER['REQUEST_METHOD'] === 'GET') {   // the old address: attendance now lives in the Attendance module (every link and bookmark keeps working)
    redirect(APP_URL . '/pages/attendance/class.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
}
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$statuses = attendanceStatuses();
$today = date('Y-m-d');

/** A valid Y-m-d date that is not in the future, else null. */
function attendanceDate($value) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
    return ($d && $d->format('Y-m-d') === $value && $value <= date('Y-m-d')) ? $value : null;
}

$date = attendanceDate($_GET['date'] ?? '') ?? $today;
$session = attendanceSession($_GET['session'] ?? ($_POST['session'] ?? 1));   // the session (period) of the day being shown / saved
$view = !$is_owner ? 'sheet' : (($_GET['view'] ?? '') === 'report' ? 'report' : (($_GET['view'] ?? '') === 'edit' ? 'edit' : 'sheet'));
$task_result = null;
$sheet_errors = [];   // student id => message

$members = $db->fetchAll(
    "SELECT u.id, u.name, u.email, DATE(cm.joined_at) AS joined_on FROM class_members cm
     INNER JOIN users u ON cm.student_id = u.id
     WHERE cm.class_id = ? ORDER BY u.name", [$class_id]);

/** Students who belong on the sheet for a day: in the class on or before that day. */
function sheetMembers($members, $date) {
    return array_values(array_filter($members, fn($m) => $m['joined_on'] <= $date));
}

// ---------------------------------------------------------------
// Actions (class teacher)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    // A student enters the code the teacher opened: marks them present for today (never overwrites a mark the teacher made)
    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $open = attendanceActiveCheckin($class_id);
    $fails = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM attendance_checkin_tries WHERE class_id = ? AND student_id = ? AND ok = 0 AND tried_at > NOW() - INTERVAL 10 MINUTE", [$class_id, $current_user_id])['c'];
    if (!verifyCsrf()) {
        Session::flash('class_msg', ['error', 'Session expired', 'Please reload the page and try again.']);
    } elseif ($is_owner) {
        Session::flash('class_msg', ['error', 'Not for teachers', 'Students check in with the code you open.']);
    } elseif ($fails >= 5) {
        Session::flash('class_msg', ['error', 'Too many wrong codes', 'Wait a few minutes, then ask your teacher for the code again.']);
    } elseif (!$open) {
        Session::flash('class_msg', ['error', 'Check-in is closed', 'Your teacher has not opened check-in, or it already ended.']);
    } elseif (strlen($code) !== 6 || !hash_equals($open['code'], $code)) {
        $db->insert('attendance_checkin_tries', ['class_id' => $class_id, 'student_id' => $current_user_id, 'ok' => 0]);
        Session::flash('class_msg', ['error', 'Wrong code', 'Check the 6 digits and try again.']);
    } elseif ($db->fetchOne("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = CURDATE() AND session = ?", [$class_id, $current_user_id, (int) $open['session']])) {
        Session::flash('class_msg', ['info', 'Already marked', 'You already have a mark for this session.']);
    } else {
        $db->insert('attendance_checkin_tries', ['class_id' => $class_id, 'student_id' => $current_user_id, 'ok' => 1]);
        $db->insert('attendance', ['class_id' => $class_id, 'student_id' => $current_user_id, 'date' => date('Y-m-d'), 'session' => (int) $open['session'], 'status' => 'present', 'note' => 'Self check-in', 'marked_by' => $current_user_id]);
        attendanceLogChange($class_id, $current_user_id, date('Y-m-d'), null, ['present', 'Self check-in'], $current_user_id, (int) $open['session']);
        Session::flash('class_msg', ['success', 'You are marked present', 'Thank you — ' . date('g:i A') . '.']);
    }
    redirect(classUrl('attendance'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_excuse') {
    // A student explains one of their own absent / late marks (last 60 days); the teachers are told
    $xd = attendanceDate($_POST['date'] ?? '');
    $msg = trim((string) ($_POST['message'] ?? ''));
    $mark = $xd && !$is_owner ? $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND status IN ('absent', 'late')", [$class_id, $current_user_id, $xd]) : null;
    $old = $mark ? $db->fetchOne("SELECT status FROM attendance_excuses WHERE class_id = ? AND student_id = ? AND date = ?", [$class_id, $current_user_id, $xd]) : null;
    if (!verifyCsrf()) {
        Session::flash('class_msg', ['error', 'Session expired', 'Please reload the page and try again.']);
    } elseif (!$mark || $xd < date('Y-m-d', strtotime('-60 days'))) {
        Session::flash('class_msg', ['error', 'Not possible', 'You can explain an absence or late mark from the last 60 days.']);
    } elseif (mb_strlen($msg) < 3 || mb_strlen($msg) > 255) {
        Session::flash('class_msg', ['error', 'Write a short reason', 'Between 3 and 255 characters.']);
    } elseif ($old && $old['status'] !== 'pending') {
        Session::flash('class_msg', ['error', 'Already decided', 'Your teacher already answered this explanation.']);
    } else {
        $db->query("INSERT INTO attendance_excuses (class_id, student_id, date, message) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE message = VALUES(message), created_at = NOW()", [$class_id, $current_user_id, $xd, $msg]);
        notifyClassTeachers($class_id, 'attendance_excuse', $current_user_name . ' explained an absence', date('M d', strtotime($xd)) . ': ' . mb_strimwidth($msg, 0, 120, '…'),
            'pages/attendance/class.php?id=' . $class_id . '&date=' . $xd, $current_user_id);
        Session::flash('class_msg', ['success', 'Explanation sent', 'Your teacher can now accept it.']);
    }
    redirect(classUrl('attendance'));
}

// ---------------------------------------------------------------
// Edit ONE student's mark (Edit a student): change, add a missing one, or remove a wrong one — each logged in the edit history
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['edit_mark', 'add_mark', 'delete_mark'], true)) {
    $ea = $_POST['action']; $esid = (int) ($_POST['student_id'] ?? 0); $ed = attendanceDate($_POST['date'] ?? ''); $esess = attendanceSession($_POST['session'] ?? 1);
    $eback = classUrl('attendance', '&view=edit&student=' . $esid . '&from=' . urlencode((string) attendanceDate($_POST['from'] ?? '')) . '&to=' . urlencode((string) attendanceDate($_POST['to'] ?? '')));
    $emem = array_values(array_filter($members, fn($m) => (int) $m['id'] === $esid))[0] ?? null;
    $eold = $emem && $ed ? $db->fetchOne("SELECT * FROM attendance WHERE class_id = ? AND student_id = ? AND date = ? AND session = ?", [$class_id, $esid, $ed, $esess]) : null;
    $est = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $enote = trim((string) ($_POST['note'] ?? ''));
    $err = null;
    if (!verifyCsrf()) $err = ['Session expired', 'Please reload the page and try again.'];
    elseif (!$is_owner) $err = ['Not allowed', 'Only the class teacher can change attendance.'];
    elseif (!$emem || !$ed) $err = ['Not found', 'That student or day is not valid.'];
    elseif (attendanceLocked($ed)) $err = ['Day is locked', 'Attendance older than ' . attendanceLockDays() . ' days can only be changed by the school administrator.'];
    elseif ($ea !== 'delete_mark' && !isset($statuses[$est])) $err = ['Choose a status', 'Choose Present, Late, Absent or Official Present.'];
    elseif ($ea !== 'delete_mark' && mb_strlen($enote) > 255) $err = ['Remark too long', 'Remark must be under 255 characters.'];
    elseif ($ea === 'add_mark' && $eold) $err = ['Already marked', 'This student already has a mark for that session. Edit it instead.'];
    elseif ($ea === 'add_mark' && $emem['joined_on'] > $ed) $err = ['Not in the class yet', 'This student had not joined the class on that day.'];
    elseif ($ea === 'add_mark' && $db->fetchOne("SELECT id FROM attendance_days WHERE class_id = ? AND date = ?", [$class_id, $ed])) $err = ['No-class day', 'That day is marked as a no-class day.'];
    elseif ($ea !== 'add_mark' && !$eold) $err = ['Not found', 'There is no saved mark to change.'];
    if ($err) {
        Session::flash('class_msg', ['error', $err[0], $err[1]]);
        redirect($eback);
    }
    $reason_keys = attendanceReasons();
    $why = fn($ex, $rs, $nt) => trim(($ex === null ? '' : ((int) $ex === 0 ? 'Unexcused' : 'Excused' . (isset($reason_keys[$rs]) ? ' (' . $reason_keys[$rs] . ')' : ''))) . (($ex !== null && (string) $nt !== '') ? ' · ' : '') . $nt);
    if ($ea === 'delete_mark') {
        attendanceLogChange($class_id, $esid, $ed, [$eold['status'], $why($eold['excused'], $eold['reason'], $eold['note'])], null, $current_user_id, $esess);
        $db->query("DELETE FROM attendance WHERE id = ?", [$eold['id']]);
        logActivity('attendance_mark_removed', 'attendance', "Removed {$emem['name']}'s mark in {$class['class_name']} on {$ed}");
        Session::flash('class_msg', ['success', 'Mark removed', $emem['name'] . ' — ' . date('M d, Y', strtotime($ed)) . '.']);
        redirect($eback);
    }
    $rs = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
    $excused = null; $reason = null;
    if (in_array($est, ['late', 'absent'], true)) {
        if ($rs === 'unexcused') $excused = 0;
        elseif (isset($reason_keys[$rs])) { $excused = 1; $reason = $rs; }
    }
    $lm = ($est === 'late' && ctype_digit(trim((string) ($_POST['late_min'] ?? ''))) && (int) trim($_POST['late_min']) >= 1) ? min(240, (int) trim($_POST['late_min'])) : null;
    attendanceLogChange($class_id, $esid, $ed, $eold ? [$eold['status'], $why($eold['excused'], $eold['reason'], $eold['note'])] : null, [$est . ($lm ? ' (' . $lm . ' min)' : ''), $why($excused, $reason, $enote)], $current_user_id, $esess);
    $db->query(
        "INSERT INTO attendance (class_id, student_id, date, session, status, note, excused, reason, late_minutes, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), excused = VALUES(excused), reason = VALUES(reason), late_minutes = VALUES(late_minutes), marked_by = VALUES(marked_by)",
        [$class_id, $esid, $ed, $esess, $est, $enote !== '' ? $enote : null, $excused, $reason, $lm, $current_user_id]);
    if ($est === 'absent' && (!$eold || $eold['status'] !== 'absent')) {
        notify($esid, 'attendance_absent', 'Marked absent in ' . $class['class_name'],
            'You were marked absent on ' . date('D, M d, Y', strtotime($ed)) . ($esess > 1 ? ' (session ' . $esess . ')' : '') . ($enote !== '' ? ' — ' . $enote : '') . '.', 'pages/attendance/class.php?id=' . $class_id, $current_user_id, $class_id);
        waOnAbsence($class_id, $esid, $ed, $esess, $class['class_name'], $current_user_id);
    }
    logActivity('attendance_mark_edited', 'attendance', "{$emem['name']}: " . ($eold ? $eold['status'] . ' → ' : 'added ') . "{$est} in {$class['class_name']} on {$ed}");
    Session::flash('class_msg', ['success', $eold ? 'Mark changed' : 'Mark added', $emem['name'] . ' — ' . date('M d, Y', strtotime($ed)) . ': ' . $statuses[$est][0] . '.']);
    redirect($eback);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $post_date = attendanceDate($_POST['date'] ?? '');

    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!$is_owner) {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can take attendance.'];
    } elseif (!$post_date && !in_array($action, ['warn_student', 'decide_excuse', 'open_checkin', 'close_checkin'], true)) {
        $task_result = ['error', 'Invalid date', 'Pick today or an earlier day.'];
    } elseif (in_array($action, ['save_attendance', 'clear_date', 'mark_noclass', 'unmark_noclass'], true) && attendanceLocked($post_date)) {
        $task_result = ['error', 'Day is locked', 'Attendance older than ' . attendanceLockDays() . ' days can only be changed by the school administrator.'];
    } elseif ($action === 'set_policy') {
        if (!isAdmin()) {
            $task_result = ['error', 'Not allowed', 'Only the school administrator can change these rules.'];
        } else {
            $lock = max(0, min(3650, (int) ($_POST['lock_days'] ?? 0)));
            $off = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['off_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
            setSetting('attendance_lock_days', $lock, $current_user_id);
            setSetting('attendance_off_weekdays', implode(',', $off), $current_user_id);
            logActivity('attendance_policy', 'attendance', "Attendance lock set to {$lock} days; weekly off days: " . ($off ? implode(',', $off) : 'none'));
            Session::flash('class_msg', ['success', 'Rules saved', $lock ? "Days older than {$lock} can only be edited by you." : 'Days are never locked.']);
            redirect(classUrl('attendance', '&date=' . $date));
        }
    } elseif ($action === 'mark_noclass') {
        $label = trim($_POST['label'] ?? '');
        if ($label === '' || mb_strlen($label) > 100) {
            $task_result = ['error', 'Name the day', 'Give the day a short name (e.g. Eid holiday), up to 100 characters.'];
        } elseif ($db->fetchOne("SELECT id FROM attendance WHERE class_id = ? AND date = ? LIMIT 1", [$class_id, $post_date])) {
            $task_result = ['error', 'Day already has marks', 'Clear this day first, then mark it as a no-class day.'];
        } else {
            $db->query("INSERT INTO attendance_days (class_id, date, label, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)", [$class_id, $post_date, $label, $current_user_id]);
            logActivity('attendance_noclass', 'attendance', "{$class['class_name']}: {$post_date} marked as no class ({$label})");
            Session::flash('class_msg', ['success', 'No-class day saved', date('M d, Y', strtotime($post_date)) . ' will not count in attendance.']);
            redirect(classUrl('attendance', '&date=' . $post_date));
        }
    } elseif ($action === 'unmark_noclass') {
        $db->delete('attendance_days', 'class_id = ? AND date = ?', [$class_id, $post_date]);
        Session::flash('class_msg', ['success', 'Day reopened', 'You can take attendance for ' . date('M d, Y', strtotime($post_date)) . ' now.']);
        redirect(classUrl('attendance', '&date=' . $post_date));
    } elseif ($action === 'save_attendance' && $db->fetchOne("SELECT id FROM attendance_days WHERE class_id = ? AND date = ?", [$class_id, $post_date])) {
        $task_result = ['error', 'No-class day', 'This day is marked as a no-class day. Reopen it first to take attendance.'];
    } elseif ($action === 'save_attendance' && !sheetMembers($members, $post_date)) {
        $task_result = ['error', 'Nothing to save', 'No students had joined the class by this day.'];
    } elseif ($action === 'save_attendance') {
        $date = $post_date;
        $marks = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
        $notes = is_array($_POST['note'] ?? null) ? $_POST['note'] : [];
        $reasons_in = is_array($_POST['reason'] ?? null) ? $_POST['reason'] : [];
        $late_in = is_array($_POST['late_min'] ?? null) ? $_POST['late_min'] : [];
        $reason_keys = attendanceReasons();
        $previous = [];      // student => status
        $prev_full = [];     // student => [status, note, excused, reason]
        foreach ($db->fetchAll("SELECT student_id, status, note, excused, reason, late_minutes FROM attendance WHERE class_id = ? AND date = ? AND session = ?", [$class_id, $date, $session]) as $p) {
            $previous[(int) $p['student_id']] = $p['status'];
            $prev_full[(int) $p['student_id']] = $p;
        }

        $members_by_id = array_column($members, 'name', 'id');
        $rows = [];
        foreach (array_map('intval', array_column(sheetMembers($members, $date), 'id')) as $sid) {
            $st = is_string($marks[$sid] ?? null) ? $marks[$sid] : '';
            $note = trim(is_string($notes[$sid] ?? null) ? $notes[$sid] : '');
            if ($st === '') {
                $sheet_errors[$sid] = 'Choose Present, Late, Absent or Official Present';
            } elseif (!isset($statuses[$st])) {
                $sheet_errors[$sid] = 'Choose a valid status';
            } elseif (mb_strlen($note) > 255) {
                $sheet_errors[$sid] = 'Remark must be under 255 characters';
            } else {
                // reason only means something for late / absent: a reason key = excused, "unexcused" = not excused
                $rs = is_string($reasons_in[$sid] ?? null) ? $reasons_in[$sid] : '';
                $excused = null; $reason = null;
                if (in_array($st, ['late', 'absent'], true)) {
                    if ($rs === 'unexcused') $excused = 0;
                    elseif (isset($reason_keys[$rs])) { $excused = 1; $reason = $rs; }
                }
                $lm = null;   // minutes late: only for a late mark, 1–240
                if ($st === 'late' && is_string($late_in[$sid] ?? null) && ctype_digit(trim($late_in[$sid])) && (int) trim($late_in[$sid]) >= 1) $lm = min(240, (int) trim($late_in[$sid]));
                $rows[$sid] = [$st, $note, $excused, $reason, $lm];
            }
        }

        if (!$sheet_errors) {
            $changed = 0;
            foreach ($rows as $sid => [$st, $note, $excused, $reason, $lm]) {
                $pf = $prev_full[$sid] ?? null;
                if (!$pf || $pf['status'] !== $st || (string) $pf['note'] !== $note || ($pf['excused'] === null ? null : (int) $pf['excused']) !== $excused || $pf['reason'] !== $reason || ($pf['late_minutes'] === null ? null : (int) $pf['late_minutes']) !== $lm) {
                    $why = fn($ex, $rs, $nt) => trim(($ex === null ? '' : ((int) $ex === 0 ? 'Unexcused' : 'Excused' . (isset($reason_keys[$rs]) ? ' (' . $reason_keys[$rs] . ')' : ''))) . (($ex !== null && (string) $nt !== '') ? ' · ' : '') . $nt);
                    attendanceLogChange($class_id, $sid, $date, $pf ? [$pf['status'], $why($pf['excused'], $pf['reason'], $pf['note'])] : null, [$st . ($lm ? ' (' . $lm . ' min)' : ''), $why($excused, $reason, $note)], $current_user_id, $session);
                    $changed++;
                }
                // One row per (class, student, day, session): insert or update
                $db->query(
                    "INSERT INTO attendance (class_id, student_id, date, session, status, note, excused, reason, late_minutes, marked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), excused = VALUES(excused), reason = VALUES(reason), late_minutes = VALUES(late_minutes), marked_by = VALUES(marked_by)",
                    [$class_id, $sid, $date, $session, $st, $note !== '' ? $note : null, $excused, $reason, $lm, $current_user_id]
                );
                if ($st === 'absent' && ($previous[$sid] ?? null) !== 'absent') {
                    queueGuardianEmail($sid, ($members_by_id[$sid] ?? 'Your child') . ' was absent from ' . $class['class_name'],
                        'On ' . date('l, F j, Y', strtotime($date)) . ($session > 1 ? ' (session ' . $session . ')' : '') . ($note !== '' ? ' — ' . $note : '') . '. If you know the reason, please tell the class teacher (' . $class['teacher_name'] . ').');
                    notify($sid, 'attendance_absent', 'Marked absent in ' . $class['class_name'],
                        'You were marked absent on ' . date('D, M d, Y', strtotime($date)) . ($session > 1 ? ' (session ' . $session . ')' : '') . ($note !== '' ? ' — ' . $note : '') . '.',
                        'pages/attendance/class.php?id=' . $class_id, $current_user_id, $class_id);
                    waOnAbsence($class_id, $sid, $date, $session, $class['class_name'], $current_user_id);
                }
            }
            logActivity('mark_attendance', 'attendance', "Marked attendance for {$class['class_name']} on {$date}" . ($session > 1 ? " (session {$session})" : '') . ': ' . count($rows) . ' students');
            Session::flash('class_msg', ['success', 'Attendance saved',
                'All ' . count($rows) . ' student' . (count($rows) == 1 ? '' : 's') . ' marked for ' . date('M d, Y', strtotime($date)) . ($session > 1 ? ', session ' . $session : '') . '.']);
            redirect(classUrl('attendance', '&date=' . $date . ($session > 1 ? '&session=' . $session : '')));
        }
        $task_result = ['error', 'Not saved yet', count($sheet_errors) . ' student' . (count($sheet_errors) == 1 ? ' needs' : 's need')
            . ' a status — every student must be marked (use "Mark all present" and change the exceptions).'];
    } elseif ($action === 'warn_student') {
        $sid = (int) ($_POST['student_id'] ?? 0);
        $mem = array_values(array_filter($members, fn($m) => (int) $m['id'] === $sid))[0] ?? null;
        $sum = getAttendanceSummary($class_id, $sid)[$sid] ?? null;
        if (!$mem || !$sum || $sum['rate'] === null) {
            $task_result = ['error', 'Not sent', 'That student is not in this class or has no attendance yet.'];
        } elseif ($sum['rate'] >= attLow() && count(array_filter(array_column($db->fetchAll(
                    "SELECT status FROM attendance WHERE class_id = ? AND student_id = ? ORDER BY date DESC LIMIT " . attStreak(), [$class_id, $sid]), 'status'), fn($x) => $x === 'absent')) < attStreak()) {
            $task_result = ['info', 'Not needed', $mem['name'] . ' is not below ' . attLow() . '% and has no absence streak.'];
        } elseif ($db->fetchOne("SELECT id FROM notifications WHERE user_id = ? AND class_id = ? AND type = 'attendance_warning' AND created_at >= ?", [$sid, $class_id, date('Y-m-d 00:00:00')])) {
            $task_result = ['info', 'Already reminded', $mem['name'] . ' already got an attendance reminder today.'];
        } else {
            notify($sid, 'attendance_warning', 'Attendance reminder — ' . $class['class_name'],
                'Your attendance is ' . $sum['rate'] . '% (' . $sum['absent'] . ' absent of ' . $sum['total'] . ' days). Please attend regularly or speak to your teacher.',
                'pages/attendance/class.php?id=' . $class_id, $current_user_id, $class_id);
            queueGuardianEmail($sid, $mem['name'] . ': attendance reminder — ' . $class['class_name'], 'Attendance is ' . $sum['rate'] . '% (' . $sum['absent'] . ' absent of ' . $sum['total'] . ' days). Please help your child attend regularly.');
            logActivity('attendance_warning', 'attendance', "Reminded {$mem['name']} about attendance in {$class['class_name']}");
            waOnWarning($class_id, $sid, $class['class_name'], $sum['rate'], attLow(), $current_user_id);
            $task_result = ['success', 'Reminder sent', $mem['name'] . ' was notified.'];
        }
    } elseif ($action === 'open_checkin') {
        $mins = in_array((int) ($_POST['minutes'] ?? 0), [5, 10, 15, 30], true) ? (int) $_POST['minutes'] : 10;
        if ($db->fetchOne("SELECT id FROM attendance_days WHERE class_id = ? AND date = CURDATE()", [$class_id])) {
            $task_result = ['error', 'No-class day', 'Today is marked as a no-class day.'];
        } elseif (!sheetMembers($members, date('Y-m-d'))) {
            $task_result = ['error', 'No students', 'No students have joined this class yet.'];
        } else {
            $db->query("UPDATE attendance_checkin SET closed_at = NOW() WHERE class_id = ? AND closed_at IS NULL", [$class_id]);
            $db->insert('attendance_checkin', ['class_id' => $class_id, 'session' => $session, 'code' => (string) random_int(100000, 999999), 'opened_by' => $current_user_id, 'expires_at' => date('Y-m-d H:i:s', time() + $mins * 60)]);
            logActivity('attendance_checkin', 'attendance', "Opened attendance check-in for {$class['class_name']} ({$mins} min)");
            Session::flash('class_msg', ['success', 'Check-in is open', "Read the code to the class — it works for {$mins} minutes" . ($session > 1 ? " and marks session {$session}." : '.')]);
            redirect(classUrl('attendance', '&date=' . date('Y-m-d') . ($session > 1 ? '&session=' . $session : '')));
        }
    } elseif ($action === 'close_checkin') {
        $db->query("UPDATE attendance_checkin SET closed_at = NOW() WHERE class_id = ? AND closed_at IS NULL", [$class_id]);
        Session::flash('class_msg', ['success', 'Check-in closed', 'Students can no longer use the code.']);
        redirect(classUrl('attendance', '&date=' . date('Y-m-d')));
    } elseif ($action === 'decide_excuse') {
        $ex = $db->fetchOne("SELECT e.*, u.name FROM attendance_excuses e INNER JOIN users u ON e.student_id = u.id WHERE e.id = ? AND e.class_id = ?", [(int) ($_POST['excuse_id'] ?? 0), $class_id]);
        $accept = ($_POST['decision'] ?? '') === 'accept';
        if (!$ex || $ex['status'] !== 'pending') {
            $task_result = ['error', 'Not found', 'That explanation was already answered or does not exist.'];
        } elseif (attendanceLocked($ex['date'])) {
            $task_result = ['error', 'Day is locked', 'Only the school administrator can change this day.'];
        } else {
            $db->update('attendance_excuses', ['status' => $accept ? 'accepted' : 'declined', 'decided_by' => $current_user_id, 'decided_at' => date('Y-m-d H:i:s')], 'id = ?', [$ex['id']]);
            if ($accept) {   // the absence / late mark becomes excused (an existing reason is kept)
                $db->query("UPDATE attendance SET excused = 1, reason = COALESCE(reason, 'other') WHERE class_id = ? AND student_id = ? AND date = ? AND status IN ('absent', 'late') AND (excused IS NULL OR excused = 0)", [$class_id, $ex['student_id'], $ex['date']]);
            }
            notify((int) $ex['student_id'], 'attendance_excuse', 'Your explanation was ' . ($accept ? 'accepted' : 'declined'), $class['class_name'] . ' — ' . date('M d', strtotime($ex['date'])),
                'pages/attendance/class.php?id=' . $class_id, $current_user_id, $class_id);
            logActivity('attendance_excuse', 'attendance', ($accept ? 'Accepted' : 'Declined') . " the explanation of {$ex['name']} for {$ex['date']} in {$class['class_name']}");
            Session::flash('class_msg', ['success', $accept ? 'Accepted' : 'Declined', $ex['name'] . ' was told.']);
            redirect(classUrl('attendance', '&date=' . $ex['date']));
        }
    } elseif ($action === 'clear_date') {
        foreach ($db->fetchAll("SELECT student_id, status, note FROM attendance WHERE class_id = ? AND date = ? AND session = ?", [$class_id, $post_date, $session]) as $o) {
            attendanceLogChange($class_id, $o['student_id'], $post_date, [$o['status'], $o['note']], null, $current_user_id, $session);
        }
        $db->delete('attendance', 'class_id = ? AND date = ? AND session = ?', [$class_id, $post_date, $session]);
        logActivity('clear_attendance', 'attendance', "Cleared attendance for {$class['class_name']} on {$post_date}" . ($session > 1 ? " (session {$session})" : ''));
        Session::flash('class_msg', ['success', $session > 1 ? 'Session cleared' : 'Day cleared', 'Attendance for ' . date('M d, Y', strtotime($post_date)) . ($session > 1 ? ', session ' . $session : '') . ' was removed.']);
        redirect(classUrl('attendance', '&date=' . $post_date));
    } else {
        $task_result = ['error', 'Not allowed', 'Unknown action.'];
    }
}

$task_result = Session::flash('class_msg') ?? $task_result;

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$summary = getAttendanceSummary($class_id, $is_owner ? null : $current_user_id);
$sheet_members = sheetMembers($members, $date);
$joined_later = count($members) - count($sheet_members);

if ($is_owner) {
    $marked = [];
    $day_sessions = attendanceSessionsOn($class_id, $date);
    foreach ($db->fetchAll("SELECT student_id, status, note, excused, reason, late_minutes FROM attendance WHERE class_id = ? AND date = ? AND session = ?", [$class_id, $date, $session]) as $m) {
        $marked[(int) $m['student_id']] = $m;
    }
    $locked = attendanceLocked($date);
    $noclass = $db->fetchOne("SELECT label FROM attendance_days WHERE class_id = ? AND date = ?", [$class_id, $date])['label'] ?? null;
    $off_weekdays = attendanceOffWeekdays();
    $is_off_day = in_array((int) date('N', strtotime($date)), $off_weekdays, true);
    $checkin = attendanceActiveCheckin($class_id);
    $checked_in = $checkin ? (int) $db->fetchOne("SELECT COUNT(*) AS c FROM attendance WHERE class_id = ? AND date = CURDATE() AND session = ? AND note = 'Self check-in' AND updated_at >= ?", [$class_id, (int) $checkin['session'], $checkin['opened_at']])['c'] : 0;
    $day_excuses = $db->fetchAll("SELECT e.id, e.message, e.status, u.name FROM attendance_excuses e INNER JOIN users u ON e.student_id = u.id WHERE e.class_id = ? AND e.date = ? ORDER BY e.created_at", [$class_id, $date]);
    $pending_excuses = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM attendance_excuses WHERE class_id = ? AND status = 'pending'", [$class_id])['c'];
    $history_log = $db->fetchAll(
        "SELECT l.*, u.name AS student, w.name AS editor FROM attendance_log l
         INNER JOIN users u ON l.student_id = u.id LEFT JOIN users w ON l.changed_by = w.id
         WHERE l.class_id = ? AND l.date = ? AND l.session = ? ORDER BY l.changed_at DESC, l.id DESC LIMIT 40", [$class_id, $date, $session]);
    $recent_days = $db->fetchAll(
        "SELECT date, COUNT(*) AS n, COUNT(DISTINCT session) AS sessions, SUM(" . attendedSql('status') . ") AS attended,
                SUM(status = 'present') AS p, SUM(status = 'late') AS l, SUM(status = 'absent') AS a, SUM(status = 'official') AS o
         FROM attendance WHERE class_id = ? GROUP BY date ORDER BY date DESC LIMIT 90", [$class_id]);
    $today_marked = (bool) array_filter($recent_days, fn($d) => $d['date'] === $today);
    // At risk: low rate, or the last attStreak() marked days all absent
    $at_risk = [];
    $streaks = [];
    foreach ($db->fetchAll("SELECT student_id, status FROM attendance WHERE class_id = ? ORDER BY date DESC", [$class_id]) as $r) {
        $sid = (int) $r['student_id'];
        if (!isset($streaks[$sid])) $streaks[$sid] = ['n' => 0, 'open' => true];
        if ($streaks[$sid]['open'] && $r['status'] === 'absent') $streaks[$sid]['n']++; else $streaks[$sid]['open'] = false;
    }
    foreach ($members as $m) {
        $s = $summary[(int) $m['id']] ?? null;
        if (!$s || $s['rate'] === null) continue;
        $st = $streaks[(int) $m['id']]['n'] ?? 0;
        if ($s['rate'] < attLow() || $st >= attStreak()) $at_risk[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'rate' => $s['rate'], 'absent' => $s['absent'], 'streak' => $st];
    }
    usort($at_risk, fn($a, $b) => $a['rate'] <=> $b['rate']);
    $at_risk_ids = array_column($at_risk, 'rate', 'id');
    // The last day marked before the open date, for "Copy previous day"
    $prev_day = $db->fetchOne("SELECT MAX(date) AS d FROM attendance WHERE class_id = ? AND date < ? AND session = ?", [$class_id, $date, $session])['d'] ?? null;
    $prev_marks = [];
    if ($prev_day) foreach ($db->fetchAll("SELECT student_id, status FROM attendance WHERE class_id = ? AND date = ? AND session = ?", [$class_id, $prev_day, $session]) as $r) $prev_marks[(int) $r['student_id']] = $r['status'];
    // Month calendar: jump to any day, past months included
    $cal = DateTime::createFromFormat('Y-m-d', ($_GET['m'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] . '-01' : substr($date, 0, 7) . '-01');
    if (!$cal || $cal->format('Y-m') > date('Y-m')) $cal = new DateTime(date('Y-m-01'));
    $cal_start = $cal->format('Y-m-01');
    $cal_end = $cal->format('Y-m-t');
    $cal_noclass = attendanceNoClassDays($class_id, $cal_start, $cal_end);
    $cal_days = [];
    foreach ($db->fetchAll("SELECT date, COUNT(*) AS n, SUM(" . attendedSql('status') . ") AS ok FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? GROUP BY date",
        [$class_id, $cal_start, $cal_end]) as $r) $cal_days[$r['date']] = round($r['ok'] / max(1, $r['n']) * 100);
    $cal_prev = (clone $cal)->modify('-1 month')->format('Y-m');
    $cal_next = (clone $cal)->modify('+1 month')->format('Y-m');
    $first_marked = $db->fetchOne("SELECT MIN(date) AS d FROM attendance WHERE class_id = ?", [$class_id])['d'] ?? null;
    // Report period
    [$from, $to] = attendancePeriod($class_id, $_GET['from'] ?? '', $_GET['to'] ?? '');
    $period_summary = $view === 'report' ? getAttendanceSummary($class_id, null, $from, $to) : $summary;
    $period_days = $from ? (int) $db->fetchOne("SELECT COUNT(DISTINCT date) AS c FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ?", [$class_id, $from, $to])['c'] : 0;
    $days_marked = (int) $db->fetchOne("SELECT COUNT(DISTINCT date) AS c FROM attendance WHERE class_id = ?", [$class_id])['c'];
    $rates = array_values(array_filter(array_column($period_summary, 'rate'), fn($r) => $r !== null));
    $class_rate = $rates ? round(array_sum($rates) / count($rates), 1) : null;
} else {
    $mine = $summary[(int) $current_user_id] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0, 'total' => 0, 'rate' => null];
    $checkin = attendanceActiveCheckin($class_id);
    $already_today = $checkin ? (bool) $db->fetchOne("SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND date = CURDATE() AND session = ?", [$class_id, $current_user_id, (int) $checkin['session']]) : false;
    $my_excuses = [];
    foreach ($db->fetchAll("SELECT date, message, status FROM attendance_excuses WHERE class_id = ? AND student_id = ?", [$class_id, $current_user_id]) as $x) $my_excuses[$x['date']] = $x;
    $history = $db->fetchAll(
        "SELECT date, session, status, note, excused, reason, late_minutes FROM attendance WHERE class_id = ? AND student_id = ? ORDER BY date DESC, session DESC", [$class_id, $current_user_id]);
}

// "Edit a student": one student's marks in a period, plus the class days they have no mark for
$edit_student = null; $edit_rows = []; $edit_from = null; $edit_to = null;
if ($is_owner && $view === 'edit') {
    $edit_day = attendanceDate($_GET['day'] ?? '');   // one exact day, any past day (not only today)
    $edit_notice = '';
    foreach (['from', 'to', 'day'] as $k) if (($_GET[$k] ?? '') !== '' && !attendanceDate($_GET[$k])) $edit_notice = 'Some dates were not valid or were in the future, so they were ignored.';
    $edit_from = $edit_day ?? attendanceDate($_GET['from'] ?? '') ?? date('Y-m-d', strtotime('-60 days')); $edit_to = $edit_day ?? attendanceDate($_GET['to'] ?? '') ?? $today;
    if ($edit_from > $edit_to) [$edit_from, $edit_to] = [$edit_to, $edit_from];
    $edit_student = array_values(array_filter($members, fn($m) => (int) $m['id'] === (int) ($_GET['student'] ?? 0)))[0] ?? null;
    if ($edit_student) {
        foreach ($db->fetchAll("SELECT date, session, status, note, excused, reason, late_minutes, updated_at FROM attendance WHERE class_id = ? AND student_id = ? AND date BETWEEN ? AND ?", [$class_id, $edit_student['id'], $edit_from, $edit_to]) as $r) $edit_rows[$r['date'] . '|' . $r['session']] = $r + ['has' => true];
        $skip = array_column($db->fetchAll("SELECT date FROM attendance_days WHERE class_id = ? AND date BETWEEN ? AND ?", [$class_id, $edit_from, $edit_to]), 'date');
        foreach ($db->fetchAll("SELECT DISTINCT date, session FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ?", [$class_id, $edit_from, $edit_to]) as $r) {
            $k = $r['date'] . '|' . $r['session'];
            if (!isset($edit_rows[$k]) && $edit_student['joined_on'] <= $r['date'] && !in_array($r['date'], $skip, true)) $edit_rows[$k] = ['date' => $r['date'], 'session' => $r['session'], 'status' => '', 'note' => '', 'excused' => null, 'reason' => null, 'late_minutes' => null, 'has' => false];
        }
        krsort($edit_rows);
    }
}

$page_title = $class['class_name'] . ' - Attendance';
if (defined('ATT_MODULE')) { $active_menu = 'attendance'; $hide_page_head = true; }   // hosted by the Attendance module: sidebar item and heading of the module
$page_script = (!empty($is_owner) && $view === 'edit' ? "var ejs = document.createElement('script'); ejs.src = " . json_encode(APP_URL . '/assets/js/attendance-edit.js') . "; document.body.appendChild(ejs);" : '') . (!empty($is_owner) ? "var sjs = document.createElement('script'); sjs.src = " . json_encode(APP_URL . '/assets/js/attendance-sheet.js') . "; document.body.appendChild(sjs);" : '') . classResultScript($task_result) . "
    const sheet = document.getElementById('attendanceSheet');
    if (sheet) {
        function counts() {
            const c = { present: 0, late: 0, absent: 0, official: 0, none: 0 };
            sheet.querySelectorAll('.att-row').forEach(function(row) {
                const r = row.querySelector('input[type=radio]:checked');
                c[r ? r.value : 'none']++;
                row.classList.toggle('unmarked', !r);
                row.classList.toggle('has-mark', !!r);
                const rs = row.querySelector('.att-reason');
                if (rs) rs.style.visibility = (r && (r.value === 'late' || r.value === 'absent')) ? 'visible' : 'hidden';
                const lm = row.querySelector('.att-late-min');
                if (lm) lm.style.display = (r && r.value === 'late') ? 'block' : 'none';
            });
            Object.keys(c).forEach(function(k) { const el = document.getElementById('count-' + k); if (el) el.textContent = c[k]; });
            const tot = c.present + c.late + c.absent + c.official + c.none, pg = document.getElementById('attProg');
            if (pg) pg.style.width = (tot ? (tot - c.none) / tot * 100 : 0) + '%';
        }
        sheet.addEventListener('change', counts);
        document.getElementById('markAllPresent').addEventListener('click', function() {
            sheet.querySelectorAll('input[type=radio][value=present]').forEach(function(r) { r.checked = true; });
            counts();
        });
        const prev = document.getElementById('copyPrev');
        if (prev) prev.addEventListener('click', function() {
            const marks = JSON.parse(prev.dataset.marks);
            let n = 0;
            Object.keys(marks).forEach(function(id) {
                const r = sheet.querySelector('input[type=radio][name=\"status[' + id + ']\"][value=' + marks[id] + ']');
                if (r) { r.checked = true; n++; }
            });
            counts();
        });
        counts();
    }
    const monthPick = document.querySelector('.ac-jump input');
    if (monthPick) monthPick.addEventListener('change', function() { if (monthPick.value) monthPick.form.submit(); });
    const dateInput = document.getElementById('attDate');
    if (dateInput) dateInput.addEventListener('change', function() { if (dateInput.value) dateInput.form.submit(); });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
if (defined('ATT_MODULE')) attendanceModuleTop(); else classTop('attendance');
?>
<div id="attWrap" data-ajax-region>

<?php if ($is_owner): ?>
<div class="page-toolbar">
    <div class="tab-pills">
        <a href="<?php echo classUrl('attendance', '&amp;date=' . $date); ?>" class="<?php echo $view === 'sheet' ? 'active' : ''; ?>"><i class="fas fa-clipboard-check"></i> Take attendance<?php echo !empty($pending_excuses) ? ' <span class="count-pill">' . (int) $pending_excuses . '</span>' : ''; ?></a>
        <a href="<?php echo classUrl('attendance', '&amp;view=report'); ?>" class="<?php echo $view === 'report' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Report</a>
        <a href="<?php echo classUrl('attendance', '&amp;view=edit'); ?>" class="<?php echo $view === 'edit' ? 'active' : ''; ?>"><i class="fas fa-user-pen"></i> Edit a student</a>
    </div>
    <?php if ($view === 'report' && $from): ?>
    <div class="toolbar-actions">
        <a href="<?php echo APP_URL; ?>/pages/classes/attendance_print.php?id=<?php echo $class_id; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>&amp;layout=summary" target="_blank" class="btn-app btn-sm"><i class="fas fa-file-pdf"></i> PDF report</a>
        <a href="<?php echo APP_URL; ?>/pages/classes/attendance_print.php?id=<?php echo $class_id; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>&amp;layout=register" target="_blank" class="btn-app btn-sm btn-outline"><i class="fas fa-table"></i> Register (PDF)</a>
        <a href="<?php echo APP_URL; ?>/pages/classes/attendance_print.php?id=<?php echo $class_id; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>&amp;layout=compact" target="_blank" class="btn-app btn-sm btn-outline" title="Compact register with P / L / A / O letters"><i class="fas fa-border-all"></i> Compact register</a>
        <a href="<?php echo APP_URL; ?>/pages/classes/attendance_export.php?id=<?php echo $class_id; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-file-csv"></i> CSV</a>
        <a href="<?php echo APP_URL; ?>/pages/classes/attendance_xlsx.php?id=<?php echo $class_id; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>" class="btn-app btn-sm btn-outline" data-no-ajax><i class="fas fa-file-excel"></i> Excel</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$members): ?>
<div class="empty-state">
    <i class="fas fa-user-plus"></i>
    <h3>No students yet</h3>
    <p style="margin-top:6px;">Share the class code or invite link — attendance can be taken once students join.</p>
</div>

<?php elseif ($view === 'sheet'): ?>
<div class="attendance-layout att-v2">
        <div class="content-card att-topbar">
            <div class="att-datenav">
                <a class="icon-btn" href="<?php echo classUrl('attendance', '&amp;date=' . date('Y-m-d', strtotime('-1 day', strtotime($date)))); ?>" aria-label="Previous day" title="Previous day"><i class="fas fa-chevron-left"></i></a>
                <form method="GET" class="att-datepick">
                    <input type="hidden" name="id" value="<?php echo $class_id; ?>">
                    <label for="attDate" class="sr-only">Date</label>
                    <input type="date" id="attDate" name="date" value="<?php echo htmlspecialchars($date); ?>" max="<?php echo $today; ?>">
                    <noscript><button type="submit" class="btn-app btn-sm">Open</button></noscript>
                </form>
                <?php if ($date < $today): ?><a class="icon-btn" href="<?php echo classUrl('attendance', '&amp;date=' . date('Y-m-d', strtotime('+1 day', strtotime($date)))); ?>" aria-label="Next day" title="Next day"><i class="fas fa-chevron-right"></i></a>
                <?php else: ?><span class="icon-btn is-off" aria-hidden="true"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
                <?php if ($date !== $today): ?><a class="btn-app btn-sm btn-outline" href="<?php echo classUrl('attendance', '&amp;date=' . $today); ?>">Today</a><?php endif; ?>
            </div>
            <div class="att-daytext"><strong><?php echo date('l, F j, Y', strtotime($date)); ?></strong>
                <span class="form-hint"><?php echo $noclass ? 'Not a class day' : ($marked ? 'Already marked — you are editing it' : 'Not marked yet'); ?></span></div>
        <?php if (!$noclass): $shown = array_values(array_unique(array_merge($day_sessions, [$session]))); sort($shown); $next_session = max($shown) + 1; ?>
        <div class="att-sessions">
            <span class="att-tb-label"><i class="fas fa-layer-group"></i> Session</span>
            <div class="as-pills" role="tablist" aria-label="Sessions of <?php echo date('M j', strtotime($date)); ?>">
                <?php foreach ($shown as $n): $has = in_array($n, $day_sessions, true) && $db->fetchOne("SELECT id FROM attendance WHERE class_id = ? AND date = ? AND session = ? LIMIT 1", [$class_id, $date, $n]); ?>
                <a class="as-pill<?php echo $n === $session ? ' on' : ''; ?>" role="tab" aria-selected="<?php echo $n === $session ? 'true' : 'false'; ?>" href="<?php echo classUrl('attendance', '&amp;date=' . $date . '&amp;session=' . $n); ?>">
                    <?php echo $n; ?><?php if ($has): ?><i class="fas fa-check" aria-label="marked"></i><?php endif; ?></a>
                <?php endforeach; ?>
                <?php if ($next_session <= 8): ?><a class="as-pill add" href="<?php echo classUrl('attendance', '&amp;date=' . $date . '&amp;session=' . $next_session); ?>" title="Add session <?php echo $next_session; ?> (another lesson or period on this day)" aria-label="Add session <?php echo $next_session; ?>"><i class="fas fa-plus"></i></a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        </div>

    <div class="att-main">
    <?php if ($locked): ?>
    <div class="att-warning" role="status"><i class="fas fa-lock"></i><div><strong>This day is locked.</strong> Attendance older than <?php echo attendanceLockDays(); ?> days can only be changed by the school administrator. You can still view it.</div></div>
    <?php endif; ?>
    <?php if ($noclass): ?>
    <div class="content-card att-noclass">
        <i class="fas fa-umbrella-beach"></i>
        <div><h2>No class — <?php echo htmlspecialchars($noclass); ?></h2>
            <p class="form-hint" style="margin:4px 0 0;"><?php echo date('l, F j, Y', strtotime($date)); ?> does not count in anyone's attendance.</p></div>
        <?php if (!$locked): ?>
        <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="unmark_noclass"><input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-undo"></i> Reopen this day</button></form>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <form method="POST" class="content-card<?php echo $locked ? ' is-locked' : ''; ?>" id="attendanceSheet"<?php echo ($marked && $_SERVER['REQUEST_METHOD'] !== 'POST') ? ' data-guard="1"' : ''; ?> data-editing="<?php echo ($marked && $_SERVER['REQUEST_METHOD'] !== 'POST') ? '1' : '0'; ?>"
          data-labels="<?php echo htmlspecialchars(json_encode(['notMarked' => t('Not marked'), 'changed' => t('changed'), 'saveChanges' => t('Save changes'), 'nothingTitle' => t('Nothing changed'),
              'nothingText' => t('This sheet is the same as the saved one.'), 'confirmTitle' => t('Save these changes?'), 'confirmButton' => t('Save changes'), 'cancelButton' => t('Keep editing')], JSON_UNESCAPED_UNICODE)); ?>">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_attendance">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
        <input type="hidden" name="session" value="<?php echo $session; ?>">
        <div class="card-head">
            <h2><i class="fas fa-clipboard-check"></i> <?php echo date('M d, Y', strtotime($date)); ?><?php echo $session > 1 || count($day_sessions) > 1 ? ' · session ' . $session : ''; ?></h2>
            <div class="att-counts" aria-live="polite">
                <span class="status-badge graded">Present <b id="count-present">0</b></span>
                <span class="status-badge late">Late <b id="count-late">0</b></span>
                <span class="status-badge missing">Absent <b id="count-absent">0</b></span>
                <span class="status-badge submitted">Official <b id="count-official">0</b></span>
                <span class="status-badge pending">Not marked <b id="count-none">0</b></span>
            </div>
        </div>
        <?php if ($marked && $sheet_members): $ls = $db->fetchOne("SELECT a.updated_at, u.name FROM attendance a LEFT JOIN users u ON u.id = a.marked_by WHERE a.class_id = ? AND a.date = ? AND a.session = ? ORDER BY a.updated_at DESC LIMIT 1", [$class_id, $date, $session]); ?>
        <div class="att-subbar">
            <?php if ($ls): ?><span class="form-hint" style="margin:0;"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> <span>Last saved</span> <?php echo date('M j, g:i A', strtotime($ls['updated_at'])); ?><?php echo $ls['name'] ? ' · ' . htmlspecialchars($ls['name']) : ''; ?></span><?php endif; ?>
            <span class="att-changed-note" id="attChangedNote" hidden></span>
        </div>
        <?php endif; ?>
        <?php if (count($sheet_members) > 8): ?>
        <div class="att-find"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="attFind" placeholder="Find a student" autocomplete="off" aria-label="Find a student"></div>
        <?php endif; ?>
        <div class="att-progress" aria-hidden="true"><i id="attProg"></i></div>
        <?php if ($joined_later): ?>
        <p class="form-hint att-joined-note"><i class="fas fa-info-circle"></i>
            <?php echo $joined_later; ?> student<?php echo $joined_later == 1 ? '' : 's'; ?> joined after this day, so <?php echo $joined_later == 1 ? 'is' : 'are'; ?> not on this sheet.</p>
        <?php endif; ?>
        <?php if (!$sheet_members): ?>
        <div class="empty-state" style="border:none;"><i class="fas fa-calendar-times"></i><p>No students had joined the class by this day.</p></div>
        <?php else: ?>
        <div class="att-list" role="list">
            <?php foreach ($sheet_members as $i => $m):
                $sid = (int) $m['id'];
                $cur = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['status'][$sid] ?? '') : ($marked[$sid]['status'] ?? '');
                $note = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['note'][$sid] ?? '') : ($marked[$sid]['note'] ?? '');
                $err = $sheet_errors[$sid] ?? '';
                $cur_r = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['reason'][$sid] ?? '')
                    : (($marked[$sid]['excused'] ?? null) === null ? '' : ((int) $marked[$sid]['excused'] === 0 ? 'unexcused' : ($marked[$sid]['reason'] ?? 'other')));
                $cur_lm = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['late_min'][$sid] ?? '') : (string) ($marked[$sid]['late_minutes'] ?? '');
                $risk_row = $at_risk_ids[$sid] ?? null;
            ?>
            <div class="att-row<?php echo $err ? ' row-error' : ''; ?>" role="listitem" data-student="<?php echo $sid; ?>">
                <span class="att-no"><?php echo $i + 1; ?></span>
                <div class="att-who">
                    <?php echo avatarHtml($sid, $m['name']); ?>
                    <span class="att-name"><a class="person-link" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo $sid; ?>"><strong><?php echo htmlspecialchars($m['name']); ?></strong></a>
                        <span class="att-edited"><?php echo t('edited'); ?></span><?php if ($risk_row !== null): ?><span class="att-flag" title="Attendance rate <?php echo (int) $risk_row; ?>%"><i class="fas fa-exclamation-triangle"></i> <?php echo (int) $risk_row; ?>%</span><?php endif; ?></span>
                    <?php if (($marked[$sid]['status'] ?? '') === 'absent') echo waButton(waClickUrl($sid, 'absence', ['class' => $class['class_name'], 'date' => date('M j, Y', strtotime($date))], $current_user_id), 'Message on WhatsApp'); ?>
                </div>
                <div class="att-seg" role="radiogroup" aria-label="<?php echo htmlspecialchars($m['name']); ?>">
                    <?php foreach ($statuses as $key => [$label, $badge]): ?>
                    <label class="att-radio <?php echo $badge; ?>" title="<?php echo $label; ?>">
                        <input type="radio" name="status[<?php echo $sid; ?>]" value="<?php echo $key; ?>" <?php echo $cur === $key ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>
                            aria-label="<?php echo $label . ': ' . htmlspecialchars($m['name']); ?>"><span><?php echo $label; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="att-detail">
                    <select class="att-reason" name="reason[<?php echo $sid; ?>]" <?php echo $locked ? 'disabled' : ''; ?> aria-label="Reason for <?php echo htmlspecialchars($m['name']); ?>">
                        <option value="">No reason</option>
                        <optgroup label="Excused">
                            <?php foreach (attendanceReasons() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo $cur_r === $rk ? 'selected' : ''; ?>><?php echo $rl; ?></option><?php endforeach; ?>
                        </optgroup>
                        <option value="unexcused" <?php echo $cur_r === 'unexcused' ? 'selected' : ''; ?>>Unexcused</option>
                    </select>
                    <input type="number" class="att-late-min" name="late_min[<?php echo $sid; ?>]" min="1" max="240" step="1" placeholder="min late" value="<?php echo htmlspecialchars($cur_lm); ?>" <?php echo $locked ? 'disabled' : ''; ?> aria-label="Minutes late: <?php echo htmlspecialchars($m['name']); ?>">
                    <input type="text" class="att-note" name="note[<?php echo $sid; ?>]" maxlength="255" placeholder="Remarks (optional)" <?php echo $locked ? 'disabled' : ''; ?>
                        value="<?php echo htmlspecialchars(is_string($note) ? $note : ''); ?>" aria-label="Remarks for <?php echo htmlspecialchars($m['name']); ?>">
                    <?php if ($err): ?><div class="invalid-feedback show"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!$locked): ?>
        <div class="att-actions">
            <?php if ($prev_marks): ?>
            <button type="button" class="btn-app btn-sm btn-outline" id="copyPrev" data-marks="<?php echo htmlspecialchars(json_encode($prev_marks)); ?>"
                    title="Fill the sheet from <?php echo date('D, M d', strtotime($prev_day)); ?>, then change the exceptions"><i class="fas fa-copy"></i> Copy <?php echo date('M d', strtotime($prev_day)); ?></button>
            <?php endif; ?>
            <button type="button" class="btn-app btn-sm btn-outline" id="markAllPresent"><i class="fas fa-check-double"></i> Mark all present</button>
            <button type="submit" class="btn-app btn-sm" id="attSave"><i class="fas fa-save"></i> <span class="att-save-text">Save attendance</span></button>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    </div>
    <details class="content-card att-toolbox"<?php echo ($checkin ?? null) ? ' open' : ''; ?>>
        <summary><i class="fas fa-toolbox"></i> More tools <span class="form-hint" style="margin:0;">self check-in · not a class day · calendar · recent days</span></summary>
        <div class="att-tools-grid">
        <?php if ($date === $today && !$noclass): ?>
        <div class="content-card side-card att-checkin">
            <label><i class="fas fa-qrcode"></i> Self check-in</label>
            <?php if ($checkin): ?>
            <div class="ci-code" aria-live="polite"><?php echo htmlspecialchars(substr($checkin['code'], 0, 3) . ' ' . substr($checkin['code'], 3)); ?></div>
            <p class="form-hint" style="margin:0 0 8px;">Open until <?php echo date('g:i A', strtotime($checkin['expires_at'])); ?> · marks session <?php echo (int) $checkin['session']; ?> · <?php echo $checked_in; ?> checked in. Read it out or write it on the board.</p>
            <form method="POST" style="margin:0;"><?php echo csrfField(); ?><input type="hidden" name="action" value="close_checkin"><button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-lock"></i> Close now</button></form>
            <?php else: ?>
            <p class="form-hint" style="margin:0 0 8px;">Students type a code to mark themselves present. You can still correct anyone.</p>
            <form method="POST" class="nc-row"><?php echo csrfField(); ?><input type="hidden" name="action" value="open_checkin"><input type="hidden" name="session" value="<?php echo $session; ?>">
                <select name="minutes" aria-label="Minutes open"><option value="5">5 min</option><option value="10" selected>10 min</option><option value="15">15 min</option><option value="30">30 min</option></select>
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-play"></i> Open</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$noclass && !$marked && !$locked): ?>
        <form method="POST" class="content-card side-card att-noclass-form">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="mark_noclass"><input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
            <label for="ncLabel"><i class="fas fa-umbrella-beach"></i> Not a class day?</label>
            <div class="nc-row"><input type="text" id="ncLabel" name="label" maxlength="100" placeholder="<?php echo $is_off_day ? 'Weekly day off' : 'e.g. Eid holiday, exam week'; ?>" value="<?php echo $is_off_day ? 'Weekly day off' : ''; ?>">
                <button type="submit" class="btn-app btn-sm btn-outline">Mark</button></div>
        </form>
        <?php endif; ?>

        <div class="content-card side-card att-cal">
            <div class="rd-head">
                <a class="icon-btn" href="<?php echo classUrl('attendance', '&amp;date=' . $date . '&amp;m=' . $cal_prev); ?>" aria-label="Previous month"><i class="fas fa-chevron-left"></i></a>
                <form method="GET" class="ac-jump"><input type="hidden" name="id" value="<?php echo $class_id; ?>"><input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
                    <input type="month" name="m" value="<?php echo $cal->format('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" aria-label="Jump to month"></form>
                <?php if ($cal->format('Y-m') < date('Y-m')): ?><a class="icon-btn" href="<?php echo classUrl('attendance', '&amp;date=' . $date . '&amp;m=' . $cal_next); ?>" aria-label="Next month"><i class="fas fa-chevron-right"></i></a>
                <?php else: ?><span class="icon-btn" aria-hidden="true" style="opacity:.35;"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
            </div>
            <div class="ac-grid" role="grid" aria-label="<?php echo $cal->format('F Y'); ?>">
                <?php foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $wd): ?><span class="ac-wd"><?php echo $wd; ?></span><?php endforeach; ?>
                <?php for ($i = 1; $i < (int) $cal->format('N'); $i++): ?><span></span><?php endfor; ?>
                <?php for ($dn = 1; $dn <= (int) $cal->format('t'); $dn++):
                    $dd = $cal->format('Y-m-') . str_pad($dn, 2, '0', STR_PAD_LEFT);
                    $future = $dd > $today;
                    $r = $cal_days[$dd] ?? null;
                    $cls = 'ac-day' . ($r !== null ? ' marked ' . ($r >= 85 ? 'good' : ($r >= 70 ? 'mid' : 'low')) : '') . ($dd === $date ? ' current' : '') . ($dd === $today ? ' today' : '')
                        . (isset($cal_noclass[$dd]) ? ' noclass' : '') . (in_array((int) date('N', strtotime($dd)), $off_weekdays, true) ? ' offday' : '');
                    if ($future): ?><span class="<?php echo $cls; ?> future"><?php echo $dn; ?></span>
                <?php else: ?><a class="<?php echo $cls; ?>" href="<?php echo classUrl('attendance', '&amp;date=' . $dd . '&amp;m=' . $cal->format('Y-m')); ?>"
                    title="<?php echo date('D, M d, Y', strtotime($dd)) . (isset($cal_noclass[$dd]) ? ' · no class: ' . $cal_noclass[$dd] : ($r !== null ? ' · ' . $r . '% attended' : ' · not marked')); ?>"><?php echo $dn; ?></a><?php endif; ?>
                <?php endfor; ?>
            </div>
            <p class="form-hint" style="margin:8px 2px 0;"><span class="ac-key good"></span>85%+ <span class="ac-key mid"></span>70–84% <span class="ac-key low"></span>below 70% <span class="ac-key off"></span>no class · any past day can be opened and corrected.</p>
        </div>

        <div class="content-card side-card recent-days">
            <div class="rd-head">
                <h3>Recent days</h3>
                <span class="form-hint" style="margin:0;"><?php echo $days_marked; ?> day<?php echo $days_marked == 1 ? '' : 's'; ?><?php echo $class_rate !== null ? ' · avg ' . $class_rate . '%' : ''; ?></span>
            </div>
            <?php if (!$today_marked && $date !== $today): ?>
            <a class="rd-item rd-todo" href="<?php echo classUrl('attendance', '&amp;date=' . $today); ?>">
                <span class="rd-date"><b><?php echo date('j', strtotime($today)); ?></b><small><?php echo date('M', strtotime($today)); ?></small></span>
                <span class="rd-main"><strong>Today</strong><span class="form-hint" style="margin:0;">Not taken yet</span></span>
                <span class="rd-go">Take <i class="fas fa-arrow-right"></i></span>
            </a>
            <?php endif; ?>
            <?php if (!$recent_days): ?>
            <p class="form-hint" style="margin:8px 0 0;">No attendance taken yet.</p>
            <?php endif; ?>
            <div class="rd-scroll" tabindex="0" aria-label="Recent days">
            <?php foreach ($recent_days as $ri => $d):
                $n = max(1, (int) $d['n']);
                $rate = round($d['attended'] / $n * 100);
                $rel = $d['date'] === $today ? 'Today' : ($d['date'] === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('l', strtotime($d['date'])));
                $tone = $rate >= 85 ? 'good' : ($rate >= 70 ? 'mid' : 'low'); ?>
            <a class="rd-item rd-compact<?php echo $d['date'] === $date ? ' current' : ''; ?>" href="<?php echo classUrl('attendance', '&amp;date=' . $d['date']); ?>"
               title="Present <?php echo (int) $d['p']; ?> · Late <?php echo (int) $d['l']; ?> · Absent <?php echo (int) $d['a']; ?> · Official <?php echo (int) $d['o']; ?>">
                <span class="rd-day"><strong><?php echo $rel === 'Today' || $rel === 'Yesterday' ? $rel : date('D', strtotime($d['date'])); ?></strong> <?php echo date('M j', strtotime($d['date'])); ?></span>
                <span class="rd-bar" aria-hidden="true">
                    <i class="p" style="width:<?php echo $d['p'] / $n * 100; ?>%"></i><i class="l" style="width:<?php echo $d['l'] / $n * 100; ?>%"></i><i class="o" style="width:<?php echo $d['o'] / $n * 100; ?>%"></i><i class="a" style="width:<?php echo $d['a'] / $n * 100; ?>%"></i>
                </span>
                <span class="rd-rate <?php echo $tone; ?>"><?php echo $rate; ?>%<?php echo (int) $d['sessions'] > 1 ? '<small> · ' . (int) $d['sessions'] . ' sessions</small>' : ''; ?></span>
            </a>
            <?php endforeach; ?>
            </div>
            <?php if ($days_marked > count($recent_days)): ?>
            <a class="link-btn rd-more" href="<?php echo classUrl('attendance', '&amp;view=report'); ?>">All <?php echo $days_marked; ?> days in the report <i class="fas fa-arrow-right"></i></a>
            <?php endif; ?>
        </div>

        </div>
    </details>
        <?php if ($at_risk): ?>
        <div class="content-card side-card at-risk">
            <div class="rd-head"><h3><i class="fas fa-exclamation-triangle" style="color:#e9a23b;"></i> Needs attention</h3>
                <span class="form-hint" style="margin:0;"><?php echo count($at_risk); ?></span></div>
            <?php foreach (array_slice($at_risk, 0, 8) as $r): ?>
            <div class="rd-item risk-item">
                <?php echo avatarHtml($r['id'], $r['name']); ?>
                <span class="rd-main"><a class="person-link" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo $r['id']; ?>"><strong><?php echo htmlspecialchars($r['name']); ?></strong></a>
                    <span class="form-hint" style="margin:0;"><?php echo $r['absent']; ?> absent<?php echo $r['streak'] >= attStreak() ? ' · ' . $r['streak'] . ' in a row' : ''; ?></span></span>
                <span class="rd-rate <?php echo $r['rate'] < 60 ? 'low' : 'mid'; ?>"><?php echo $r['rate']; ?>%</span>
                <form method="POST" class="risk-form" data-confirm="Send an attendance reminder to <?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>?" data-confirm-button="Send reminder">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="warn_student"><input type="hidden" name="student_id" value="<?php echo $r['id']; ?>">
                    <button type="submit" class="icon-btn" title="Send reminder" aria-label="Send reminder to <?php echo htmlspecialchars($r['name']); ?>"><i class="fas fa-bell"></i></button>
                </form>
                <?php echo waButton(waClickUrl($r['id'], 'warning', ['class' => $class['class_name'], 'percent' => rtrim(rtrim(number_format((float) $r['rate'], 1), '0'), '.'), 'limit' => attLow()], $current_user_id), 'Message on WhatsApp'); ?>
            </div>
            <?php endforeach; ?>
            <p class="form-hint" style="margin:8px 4px 0;">Below <?php echo attLow(); ?>% or <?php echo attStreak(); ?>+ absences in a row.</p>
        </div>
        <?php endif; ?>
</div>
<?php if ($marked && !$locked): ?>
<form method="POST" class="att-clear" data-confirm="Clear attendance for <?php echo date('M d, Y', strtotime($date)) . ($session > 1 || count($day_sessions) > 1 ? ', session ' . $session : ''); ?>?"
      data-confirm-text="Every mark and remark for this <?php echo $session > 1 || count($day_sessions) > 1 ? 'session' : 'day'; ?> will be removed." data-confirm-button="Clear">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="clear_date">
    <input type="hidden" name="date" value="<?php echo htmlspecialchars($date); ?>">
    <input type="hidden" name="session" value="<?php echo $session; ?>">
    <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-eraser"></i> Clear <?php echo count($day_sessions) > 1 || $session > 1 ? 'session ' . $session : 'this day'; ?></button>
</form>
<?php endif; ?>

<?php if ($view === 'sheet'): ?>
<div class="att-extra">
    <?php if ($day_excuses): ?>
    <section class="content-card att-excuses">
        <div class="card-head"><h2><i class="fas fa-comment-medical"></i> Explanations for <?php echo date('M d', strtotime($date)); ?> <span class="count-pill"><?php echo count($day_excuses); ?></span></h2></div>
        <?php foreach ($day_excuses as $ex): ?>
        <div class="rd-item" style="cursor:default;">
            <span class="rd-main"><strong><?php echo htmlspecialchars($ex['name']); ?></strong><span class="form-hint" style="margin:0;">“<?php echo htmlspecialchars($ex['message']); ?>”</span></span>
            <?php if ($ex['status'] === 'pending'): ?>
            <form method="POST" style="display:flex;gap:6px;margin:0;"><?php echo csrfField(); ?><input type="hidden" name="action" value="decide_excuse"><input type="hidden" name="excuse_id" value="<?php echo (int) $ex['id']; ?>">
                <button type="submit" name="decision" value="accept" class="btn-app btn-sm"><i class="fas fa-check"></i> Accept</button>
                <button type="submit" name="decision" value="decline" class="btn-app btn-sm btn-outline">Decline</button></form>
            <?php else: ?><span class="status-badge <?php echo $ex['status'] === 'accepted' ? 'graded' : 'missing'; ?>"><?php echo $ex['status']; ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php if ($history_log): ?>
    <details class="content-card att-history">
        <summary><i class="fas fa-history"></i> Edit history for <?php echo date('M d, Y', strtotime($date)); ?> <span class="form-hint" style="margin:0;">(<?php echo count($history_log); ?>)</span></summary>
        <div class="table-wrap"><table class="app-table"><thead><tr><th>When</th><th>Who</th><th>Student</th><th>Change</th></tr></thead><tbody>
        <?php $stl = attendanceStatuses(); foreach ($history_log as $h): ?>
            <tr><td><?php echo formatDate($h['changed_at'], 'M d, h:i A'); ?></td><td><?php echo htmlspecialchars($h['editor'] ?? 'Unknown'); ?></td><td><?php echo htmlspecialchars($h['student']); ?></td>
                <td><?php echo $h['old_status'] ? htmlspecialchars($stl[$h['old_status']][0] ?? $h['old_status']) : '<em>not marked</em>'; ?>
                    <i class="fas fa-arrow-right" style="opacity:.5;font-size:11px;"></i>
                    <?php echo $h['new_status'] ? '<strong>' . htmlspecialchars($stl[$h['new_status']][0] ?? $h['new_status']) . '</strong>' : '<em>cleared</em>'; ?>
                    <?php if ((string) $h['old_note'] !== (string) $h['new_note']): ?><span class="form-hint" style="margin:0;display:block;">
                        <?php echo htmlspecialchars(($h['old_note'] !== null && $h['old_note'] !== '' ? $h['old_note'] : '—') . '  →  ' . ($h['new_note'] !== null && $h['new_note'] !== '' ? $h['new_note'] : '—')); ?></span><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    </details>
    <?php endif; ?>
    <?php if (isAdmin()): ?>
    <p class="form-hint" style="margin:0;"><i class="fas fa-shield-alt"></i> Locking and weekly days off are in <a href="<?php echo APP_URL; ?>/pages/settings/attendance.php">Settings › Attendance rules</a>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($view === 'edit'): ?>
<!-- ===== Edit a student: one student's marks, change / add / remove each one ===== -->
<section class="content-card att-edit-pick">
    <form method="GET" class="att-edit-filter">
        <input type="hidden" name="id" value="<?php echo $class_id; ?>"><input type="hidden" name="view" value="edit">
        <label class="grow"><span>Student</span>
            <select name="student" onchange="this.form.submit()" aria-label="Student">
                <option value="">Choose a student…</option>
                <?php foreach ($members as $m): ?><option value="<?php echo (int) $m['id']; ?>" <?php echo $edit_student && (int) $edit_student['id'] === (int) $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['name']); ?></option><?php endforeach; ?>
            </select></label>
        <label><span>Day</span><input type="date" name="day" value="<?php echo !empty($edit_day) ? htmlspecialchars($edit_day) : ''; ?>" max="<?php echo $today; ?>" title="Pick one exact day to see only that day"></label>
        <label><span>From</span><input type="date" name="from" value="<?php echo htmlspecialchars((string) $edit_from); ?>" max="<?php echo $today; ?>"></label>
        <label><span>To</span><input type="date" name="to" value="<?php echo htmlspecialchars((string) $edit_to); ?>" max="<?php echo $today; ?>"></label>
        <button type="submit" class="btn-app btn-sm"><i class="fas fa-search"></i> Show</button>
    </form>
    <?php if (!empty($edit_notice)): ?><p class="form-hint" style="margin:10px 0 0;color:#b45309;"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars(t($edit_notice)); ?></p><?php endif; ?>
    <p class="form-hint" style="margin:10px 0 0;">Pick a Day to see one exact date, or a From – To period. Only today and earlier days can be changed.</p>
</section>
<?php if (!$edit_student): ?>
<div class="empty-state"><i class="fas fa-user-pen"></i><h3>Choose a student</h3><p style="margin-top:6px;">You will see every day of theirs in the period. Change one mark, add a missing one, or remove a wrong one — each change is kept in the edit history.</p></div>
<?php else:
    $e_labels = json_encode(['confirmTitle' => t('Change this mark?'), 'confirmButton' => t('Save change'), 'cancelButton' => t('Keep as it was'), 'notMarked' => t('Not marked')], JSON_UNESCAPED_UNICODE); ?>
<section class="content-card att-edit-list" id="attEdit" data-labels="<?php echo htmlspecialchars($e_labels); ?>">
    <div class="card-head"><h2><i class="fas fa-user-pen"></i> <?php echo htmlspecialchars($edit_student['name']); ?></h2>
        <span class="form-hint" style="margin:0;"><span><?php echo count($edit_rows); ?> day<?php echo count($edit_rows) == 1 ? '' : 's'; ?></span> · <span><?php echo date('M j, Y', strtotime($edit_from)); ?> – <?php echo date('M j, Y', strtotime($edit_to)); ?></span></span></div>
    <?php if (!$edit_rows): ?><div class="empty-state" style="border:none;"><i class="fas fa-calendar-times"></i><p>No attendance was taken in this period.</p></div><?php endif; ?>
    <?php foreach ($edit_rows as $er): $lock = attendanceLocked($er['date']);
        $cur_r = $er['excused'] === null ? '' : ((int) $er['excused'] === 0 ? 'unexcused' : ($er['reason'] ?? 'other')); $uid = md5($er['date'] . $er['session']); ?>
    <div class="att-edit-row<?php echo $er['has'] ? '' : ' missing'; ?>">
        <form method="POST" class="att-edit-form"<?php echo $er['has'] ? ' data-guard="1"' : ''; ?> data-date="<?php echo date('D, M j', strtotime($er['date'])); ?>" data-has="<?php echo $er['has'] ? '1' : '0'; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="<?php echo $er['has'] ? 'edit_mark' : 'add_mark'; ?>">
            <input type="hidden" name="student_id" value="<?php echo (int) $edit_student['id']; ?>"><input type="hidden" name="date" value="<?php echo $er['date']; ?>"><input type="hidden" name="session" value="<?php echo (int) $er['session']; ?>">
            <input type="hidden" name="from" value="<?php echo $edit_from; ?>"><input type="hidden" name="to" value="<?php echo $edit_to; ?>">
            <div class="aer-day"><strong><?php echo date('D, M j, Y', strtotime($er['date'])); ?></strong><?php echo (int) $er['session'] > 1 ? '<span class="form-hint" style="margin:0;">session ' . (int) $er['session'] . '</span>' : ''; ?>
                <?php if (!$er['has']): ?><span class="status-badge pending">Not marked</span><?php endif; ?><?php if ($lock): ?><span class="form-hint" style="margin:0;"><i class="fas fa-lock"></i> locked</span><?php endif; ?></div>
            <div class="att-seg" role="radiogroup" aria-label="<?php echo date('M j', strtotime($er['date'])); ?>">
                <?php foreach ($statuses as $key => [$label, $badge]): ?>
                <label class="att-radio <?php echo $badge; ?>"><input type="radio" name="status" value="<?php echo $key; ?>" <?php echo $er['status'] === $key ? 'checked' : ''; ?> <?php echo $lock ? 'disabled' : ''; ?> aria-label="<?php echo $label . ', ' . date('M j', strtotime($er['date'])); ?>"><span><?php echo $label; ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="att-detail">
                <select class="att-reason" name="reason" <?php echo $lock ? 'disabled' : ''; ?> aria-label="Reason">
                    <option value="">No reason</option>
                    <optgroup label="Excused"><?php foreach (attendanceReasons() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo $cur_r === $rk ? 'selected' : ''; ?>><?php echo $rl; ?></option><?php endforeach; ?></optgroup>
                    <option value="unexcused" <?php echo $cur_r === 'unexcused' ? 'selected' : ''; ?>>Unexcused</option>
                </select>
                <input type="number" class="att-late-min" name="late_min" min="1" max="240" step="1" placeholder="min late" value="<?php echo htmlspecialchars((string) ($er['late_minutes'] ?? '')); ?>" <?php echo $lock ? 'disabled' : ''; ?> aria-label="Minutes late">
                <input type="text" class="att-note" name="note" maxlength="255" placeholder="Remarks (optional)" value="<?php echo htmlspecialchars((string) $er['note']); ?>" <?php echo $lock ? 'disabled' : ''; ?> aria-label="Remarks">
            </div>
            <div class="aer-actions">
                <?php if (!$lock): ?><button type="submit" class="btn-app btn-sm" disabled><i class="fas fa-save"></i> <?php echo $er['has'] ? 'Save change' : 'Add mark'; ?></button><?php endif; ?>
            </div>
        </form>
        <?php if ($er['has'] && !$lock): ?>
        <form method="POST" class="aer-remove" data-confirm="Remove this mark for <?php echo htmlspecialchars($edit_student['name'], ENT_QUOTES); ?> on <?php echo date('M j', strtotime($er['date'])); ?>?" data-confirm-text="The day will count as not marked for them. This is kept in the edit history." data-confirm-button="Remove">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_mark"><input type="hidden" name="student_id" value="<?php echo (int) $edit_student['id']; ?>"><input type="hidden" name="date" value="<?php echo $er['date']; ?>"><input type="hidden" name="session" value="<?php echo (int) $er['session']; ?>">
            <input type="hidden" name="from" value="<?php echo $edit_from; ?>"><input type="hidden" name="to" value="<?php echo $edit_to; ?>">
            <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-trash"></i> Remove</button></form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php else: ?>
<!-- ===== Report (designs/pages/AttendencReports.html) ===== -->
<form method="GET" class="content-card report-filter">
    <input type="hidden" name="id" value="<?php echo $class_id; ?>"><input type="hidden" name="view" value="report">
    <label>From <input type="date" name="from" value="<?php echo htmlspecialchars((string) $from); ?>" max="<?php echo $today; ?>"></label>
    <label>To <input type="date" name="to" value="<?php echo htmlspecialchars((string) $to); ?>" max="<?php echo $today; ?>"></label>
    <button type="submit" class="btn-app btn-sm"><i class="fas fa-filter"></i> Apply</button>
    <span class="presets">
        <?php foreach ([['This month', date('Y-m-01'), $today], ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
                        ['Last 3 months', date('Y-m-d', strtotime('-3 months +1 day')), $today], ['Last 6 months', date('Y-m-d', strtotime('-6 months +1 day')), $today],
                        ['This year', date('Y-01-01'), $today], ['All', '', '']] as [$pl, $pf, $pt]): ?>
        <a class="chip" href="<?php echo classUrl('attendance', '&amp;view=report&amp;from=' . $pf . '&amp;to=' . $pt); ?>"><?php echo $pl; ?></a>
        <?php endforeach; ?>
    </span>
</form>
<?php if (!$from): ?>
<div class="empty-state"><i class="fas fa-calendar"></i><h3>No attendance yet</h3><p>Reports appear once attendance has been taken.</p></div>
<?php else: ?>
<div class="stats-grid insight-tiles">
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Days marked</h3><p class="stat-number"><?php echo $period_days; ?></p><span class="stat-change muted"><?php echo date('M j, Y', strtotime($from)); ?> – <?php echo date('M j, Y', strtotime($to)); ?></span></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-percentage" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Class attendance</h3><p class="stat-number"><?php echo $class_rate === null ? '—' : $class_rate . '%'; ?></p><span class="stat-change muted">average of students</span></div></div>
    <div class="stat-card"><div class="stat-icon"><i class="fas fa-user-times" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Below <?php echo attLow(); ?>%</h3>
            <p class="stat-number"><?php echo count(array_filter($period_summary, fn($s) => $s['rate'] !== null && $s['rate'] < attLow())); ?></p>
            <span class="stat-change muted">students with low attendance</span></div></div>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-users"></i> Students</h2></div>
    <div class="table-wrap">
        <table class="app-table insight-table">
            <thead><tr><th>Student</th><th style="min-width:200px;">Attendance</th><th>Present</th><th>Late</th><th title="Minutes late, where the teacher wrote them">Late min</th><th>Absent</th><th title="Absences with an accepted reason">Excused</th><th>Official</th><th>Days</th></tr></thead>
            <tbody>
                <?php foreach ($members as $m): $s = $period_summary[(int) $m['id']] ?? null; ?>
                <tr>
                    <td><a class="person-link" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo (int) $m['id']; ?>"><strong><?php echo htmlspecialchars($m['name']); ?></strong></a>
                        <a class="icon-btn" style="margin-left:6px;" target="_blank" title="PDF record of <?php echo htmlspecialchars($m['name']); ?>" aria-label="PDF record of <?php echo htmlspecialchars($m['name']); ?>"
                           href="<?php echo APP_URL; ?>/pages/classes/attendance_print.php?id=<?php echo $class_id; ?>&amp;student=<?php echo (int) $m['id']; ?>&amp;from=<?php echo $from; ?>&amp;to=<?php echo $to; ?>"><i class="fas fa-file-pdf"></i></a></td>
                    <td>
                        <?php if ($s && $s['rate'] !== null): ?>
                        <div class="meter" title="<?php echo $s['present'] + $s['late'] + $s['official']; ?> of <?php echo $s['total']; ?> days attended">
                            <div class="meter-track"><div class="meter-fill att-rate-<?php echo $s['rate'] >= 85 ? 'good' : ($s['rate'] >= attLow() ? 'mid' : 'low'); ?>" style="width: <?php echo $s['rate']; ?>%;"></div></div>
                            <span class="meter-value"><?php echo $s['rate']; ?>%</span>
                        </div>
                        <?php if ($s['rate'] < attLow()): ?><span class="status-badge missing"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> low</span><?php endif; ?>
                        <?php else: ?><span class="form-hint" style="margin:0;">Not marked yet</span><?php endif; ?>
                    </td>
                    <td><?php echo $s['present'] ?? 0; ?></td>
                    <td><?php echo $s['late'] ?? 0; ?></td>
                    <td><?php echo !empty($s['late_minutes']) ? (int) $s['late_minutes'] : '—'; ?></td>
                    <td><?php echo $s['absent'] ?? 0; ?></td>
                    <td><?php echo $s['excused'] ?? 0; ?></td>
                    <td><?php echo $s['official'] ?? 0; ?></td>
                    <td><?php echo $s['total'] ?? 0; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php else: ?>
<!-- ===== Student: own record ===== -->
<?php if ($checkin && !$already_today): ?>
<div class="content-card att-checkin-student">
    <form method="POST" class="ci-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="checkin">
        <span><i class="fas fa-qrcode"></i> <strong>Check-in is open</strong> until <?php echo date('g:i A', strtotime($checkin['expires_at'])); ?> — type the code from your teacher</span>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="000 000" required aria-label="Check-in code">
        <button type="submit" class="btn-app btn-sm"><i class="fas fa-check"></i> I am here</button></form>
</div>
<?php endif; ?>
<?php if ($mine['rate'] !== null && $mine['rate'] < attLow()): ?>
<div class="att-warning" role="alert"><i class="fas fa-exclamation-triangle"></i>
    <div><strong>Your attendance is <?php echo $mine['rate']; ?>% — below <?php echo attLow(); ?>%.</strong>
    You were absent <?php echo $mine['absent']; ?> of <?php echo $mine['total']; ?> days. Please attend regularly or talk to your teacher.</div></div>
<?php endif; ?>
<?php $m_tone = $mine['rate'] === null ? 'none' : ($mine['rate'] >= 85 ? 'good' : ($mine['rate'] >= attLow() ? 'mid' : 'low'));
      $strip = array_reverse(array_slice($history, 0, 30)); ?>
<section class="content-card att-me">
    <span class="ac-ring att-me-ring <?php echo $m_tone; ?>" style="--p:<?php echo (float) ($mine['rate'] ?? 0); ?>"><b><?php echo $mine['rate'] === null ? '—' : round($mine['rate']) . '%'; ?></b></span>
    <div class="att-me-main">
        <h2>Your attendance</h2>
        <p class="form-hint" style="margin:0;"><span><?php echo $mine['total']; ?> day<?php echo $mine['total'] == 1 ? '' : 's'; ?> marked</span> · <span>limit <?php echo attLow(); ?>%</span></p>
        <div class="att-me-chips">
            <?php foreach ($statuses as $key => [$label, $badge, $icon]): ?>
            <span class="att-chip <?php echo $key; ?>"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?> <b><?php echo (int) $mine[$key]; ?></b></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($strip): ?>
    <div class="att-me-strip" aria-label="Your latest days">
        <span class="form-hint" style="margin:0 0 6px;display:block;">Latest <?php echo count($strip); ?> marks</span>
        <div class="ams-row"><?php foreach ($strip as $h): ?><i class="ams <?php echo $h['status']; ?>" title="<?php echo date('D, M j', strtotime($h['date'])) . ' · ' . $statuses[$h['status']][0]; ?>"></i><?php endforeach; ?></div>
    </div>
    <?php endif; ?>
</section>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-calendar-alt"></i> Your record</h2>
        <?php if ($history): ?><a class="btn-app btn-sm btn-outline" target="_blank" href="<?php echo APP_URL; ?>/pages/classes/attendance_print.php?id=<?php echo $class_id; ?>"><i class="fas fa-file-pdf"></i> PDF</a><?php endif; ?></div>
    <?php if (!$history): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-calendar"></i><p>Your teacher has not taken attendance yet.</p></div>
    <?php endif; ?>
    <?php foreach ($history as $h): [$label, $badge, $icon] = $statuses[$h['status']];
          $mx = $my_excuses[$h['date']] ?? null;
          $can_explain = in_array($h['status'], ['absent', 'late'], true) && $h['date'] >= date('Y-m-d', strtotime('-60 days')) && (!$mx || $mx['status'] === 'pending'); ?>
    <div class="classwork-item">
        <span class="work-icon att-st <?php echo $h['status']; ?>"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i></span>
        <span class="classwork-main">
            <span class="classwork-title"><?php echo date('l, M d, Y', strtotime($h['date'])); ?><?php echo (int) $h['session'] > 1 ? ' · session ' . (int) $h['session'] : ''; ?></span>
            <?php $why = $h['excused'] === null ? '' : ((int) $h['excused'] === 0 ? 'Unexcused' : 'Excused — ' . (attendanceReasons()[$h['reason']] ?? 'reason given'));
                  if ($why || !empty($h['note'])): ?><span class="notif-meta"><?php echo htmlspecialchars(trim($why . (($why && !empty($h['note'])) ? ' · ' : '') . ($h['note'] ?? ''))); ?></span><?php endif; ?>
        </span>
        <span class="status-badge <?php echo $badge; ?>"><?php echo $label; ?><?php echo !empty($h['late_minutes']) ? ' · ' . (int) $h['late_minutes'] . ' min' : ''; ?></span>
    </div>
    <?php if ($mx || $can_explain): ?>
    <div class="att-explain">
        <?php if ($mx): ?><span class="status-badge <?php echo $mx['status'] === 'accepted' ? 'graded' : ($mx['status'] === 'declined' ? 'missing' : 'pending'); ?>"><i class="fas fa-comment-medical"></i>
            Your explanation: <?php echo $mx['status'] === 'pending' ? 'sent, waiting for your teacher' : $mx['status']; ?></span> <span class="form-hint" style="margin:0;">“<?php echo htmlspecialchars($mx['message']); ?>”</span><?php endif; ?>
        <?php if ($can_explain): ?>
        <details><summary><?php echo $mx ? 'Change it' : 'Explain this ' . strtolower($label); ?></summary>
            <form method="POST" class="att-explain-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="send_excuse"><input type="hidden" name="date" value="<?php echo htmlspecialchars($h['date']); ?>">
                <input type="text" name="message" maxlength="255" required placeholder="Why were you <?php echo strtolower($label) === 'late' ? 'late' : 'absent'; ?>? (e.g. I was sick)" value="<?php echo htmlspecialchars($mx['message'] ?? ''); ?>" aria-label="Reason">
                <button type="submit" class="btn-app btn-sm">Send</button></form>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
