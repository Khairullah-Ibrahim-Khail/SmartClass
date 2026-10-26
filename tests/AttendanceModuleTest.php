<?php
test('attendance module: a class\'s attendance opens inside the module, every link stays in the module, saving works and the class tab still works too', function () {
    $db = TW('db'); $t = mkUser('AmT', 'teacher'); $s1 = mkUser('AmS1'); $s2 = mkUser('AmS2'); $c = mkClass($t, 'Mod Class', [$s1, $s2]); $c2 = mkClass($t, 'Mod Class Two', [$s1]); $h = client($t);
    $h->get("pages/attendance/class.php?id=$c"); ok($h->shown(), 'the module page opens'); contains('att-module-top', $h->body); contains('Mod Class', $h->body); contains('attClassSwitch', $h->body, 'a class switcher (the teacher has two classes)');
    notContains('class-banner', $h->body, 'no class banner — the module heading is used'); contains('Take attendance', $h->body);
    contains("pages/attendance/class.php?id=$c&amp;view=report", $h->body, 'the Report tab stays in the module'); notContains("pages/classes/attendance.php?id=$c", $h->body, 'no link goes back to the class page');
    // save a sheet through the module address
    $today = date('Y-m-d'); $h->get("pages/attendance/class.php?id=$c&date=$today"); $tok = $h->token("pages/attendance/class.php?id=$c&date=$today");
    $h->post("pages/attendance/class.php?id=$c&date=$today", ['csrf_token' => $tok, 'action' => 'save_attendance', 'date' => $today, 'session' => '1', 'status' => [$s1 => 'present', $s2 => 'absent'], 'remark' => [$s1 => '', $s2 => '']]);
    contains("pages/attendance/class.php?id=$c", $h->redirect, 'after saving you stay in the module');
    eq(2, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE class_id = ? AND date = ?', [$c, $today])['c'], 'the marks were saved');
    $h->get("pages/attendance/class.php?id=$c&view=report"); ok($h->shown()); contains('pages/classes/attendance_print.php?id=' . $c, $h->body, 'print / export links work from here'); contains('pages/classes/attendance_export.php?id=' . $c, $h->body);
    // the old class address: reading it goes to the module (query kept); posting to it still works and ends in the module
    $h->get("pages/classes/attendance.php?id=$c&date=$today&session=1"); contains("pages/attendance/class.php?id=$c&date=$today&session=1", $h->redirect, 'the old address forwards, keeping the date and session'); eq(302, $h->code);
    $h->get("pages/classes/attendance.php?id=$c&view=report"); contains("pages/attendance/class.php?id=$c&view=report", $h->redirect);
    $h->post("pages/classes/attendance.php?id=$c", ['csrf_token' => $tok, 'action' => 'save_attendance', 'date' => $today, 'session' => '1', 'status' => [$s1 => 'late', $s2 => 'absent'], 'remark' => [$s1 => '', $s2 => '']]);
    contains("pages/attendance/class.php?id=$c", $h->redirect, 'a form still posting to the old address saves and lands in the module'); eq('late', $db->fetchOne('SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?', [$c, $s1, $today])['status']);
    // the class's own pages link to the module
    $h->get("pages/classes/stream.php?id=$c"); contains("pages/attendance/class.php?id=$c", $h->body, 'the class\'s Attendance tab opens the module'); notContains("pages/classes/attendance.php?id=$c", $h->body);
    // permissions are the page's own
    $o = client(mkUser('AmO', 'teacher')); $o->get("pages/attendance/class.php?id=$c"); ok(!$o->shown(), 'another teacher cannot open it');
    $st = client($s1); $st->get("pages/attendance/class.php?id=$c"); ok($st->shown(), 'a member student opens their own record'); notContains('id="attendanceSheet"', $st->body, 'no sheet for a student'); notContains('att-today', $st->body);
    $out = client(mkUser('AmOut')); $out->get("pages/attendance/class.php?id=$c"); ok(!$out->shown(), 'a student outside the class cannot'); ok(!anon()->get("pages/attendance/class.php?id=$c")->shown());
});

test('attendance sheet v2: day arrows, one keyboard-reachable choice group per student, reason and minutes fields, tools box; future day has no next arrow', function () {
    $t = mkUser('AvT', 'teacher'); $s1 = mkUser('AvS1'); $s2 = mkUser('AvS2'); $c = mkClass($t, 'V2 Class', [$s1, $s2]); $h = client($t);
    $today = date('Y-m-d'); $yest = date('Y-m-d', strtotime('-1 day'));
    $h->get("pages/attendance/class.php?id=$c&date=$today"); ok($h->shown());
    contains('att-topbar', $h->body); contains('aria-label="Previous day"', $h->body); contains("date=$yest", $h->body, 'the previous-day arrow points at yesterday');
    notContains('aria-label="Next day"', $h->body, 'today has no next-day arrow');
    eq(2, substr_count($h->body, 'role="radiogroup"'), 'one choice group per student');
    eq(8, substr_count($h->body, 'type="radio"'), 'four choices each, real radio inputs (keyboard accessible)');
    contains('name="reason[' . $s1 . ']"', $h->body); contains('name="late_min[' . $s1 . ']"', $h->body); contains('att-toolbox', $h->body); contains('id="markAllPresent"', $h->body);
    $h->get("pages/attendance/class.php?id=$c&date=$yest"); contains('aria-label="Next day"', $h->body, 'a past day can go forward');
});

test('student attendance page: rate ring, one chip per status, latest-marks strip and the record, with no teacher controls', function () {
    $db = TW('db'); $t = mkUser('SpT', 'teacher'); $s = mkUser('SpS'); $c = mkClass($t, 'Student Page', [$s]);
    foreach ([[-2, 'present'], [-1, 'absent']] as [$off, $st]) $db->query("INSERT INTO attendance (class_id, student_id, date, session, status, marked_by) VALUES (?, ?, ?, 1, ?, ?)", [$c, $s, date('Y-m-d', strtotime("$off day")), $st, $t]);
    $h = client($s); $h->get("pages/attendance/class.php?id=$c"); ok($h->shown());
    contains('att-me-ring', $h->body); contains('att-chip absent', $h->body); contains('att-me-strip', $h->body); contains('Your record', $h->body); contains('Explain this absent', $h->body);
    contains('Your attendance is 50% — below', $h->body, 'the low-attendance warning still shows'); notContains('id="attendanceSheet"', $h->body); notContains('att-topbar', $h->body);
});

test('attendance › Edit a student: change one mark, add a missing one, remove a wrong one — logged, the student told about a new absence, locked days and outsiders refused', function () {
    $db = TW('db'); $t = mkUser('EdT', 'teacher'); $s1 = mkUser('EdS1'); $s2 = mkUser('EdS2'); $c = mkClass($t, 'Edit Class', [$s1, $s2]); $h = client($t);
    $d1 = date('Y-m-d', strtotime('-3 day')); $d2 = date('Y-m-d', strtotime('-2 day'));
    // d1: both marked; d2: only s2 marked → s1 has a day without a mark
    foreach ([[$s1, $d1, 'present'], [$s2, $d1, 'present'], [$s2, $d2, 'present']] as [$sid, $d, $st]) $db->query("INSERT INTO attendance (class_id, student_id, date, session, status, marked_by) VALUES (?, ?, ?, 1, ?, ?)", [$c, $sid, $d, $st, $t]);
    $url = "pages/attendance/class.php?id=$c&view=edit&student=$s1&from=" . date('Y-m-d', strtotime('-10 day')) . '&to=' . date('Y-m-d');
    $h->get($url); ok($h->shown()); contains('Edit a student', $h->body); contains('att-edit-list', $h->body); contains('name="action" value="edit_mark"', $h->body); contains('name="action" value="add_mark"', $h->body, 'the day without a mark can be added');
    $tok = $h->token($url);
    $post = fn($c2, array $f) => $c2->post("pages/attendance/class.php?id=$c", $f + ['csrf_token' => $tok, 'student_id' => $s1, 'session' => '1', 'from' => '', 'to' => '']);
    // change present → absent
    $post($h, ['action' => 'edit_mark', 'date' => $d1, 'status' => 'absent', 'reason' => 'sick', 'note' => 'fever']);
    $r = $db->fetchOne("SELECT status, excused, reason, note FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1]); eq('absent', $r['status']); eq('sick', $r['reason']); eq(1, (int) $r['excused']); eq('fever', $r['note']);
    $log = $db->fetchOne("SELECT old_status, new_status FROM attendance_log WHERE class_id = ? AND student_id = ? AND date = ? ORDER BY id DESC LIMIT 1", [$c, $s1, $d1]); eq('present', $log['old_status']); eq('absent', $log['new_status'], 'the change is in the edit history');
    eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'attendance_absent'", [$s1])['c'], 'the student is told about the new absence');
    eq('present', $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s2, $d1])['status'], 'other students are untouched');
    $post($h, ['action' => 'edit_mark', 'date' => $d1, 'status' => 'absent', 'reason' => 'sick', 'note' => 'fever']); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'attendance_absent'", [$s1])['c'], 'saving the same absence again does not notify twice');
    // add the missing day
    $post($h, ['action' => 'add_mark', 'date' => $d2, 'status' => 'late', 'late_min' => '12']); $r = $db->fetchOne("SELECT status, late_minutes FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d2]); eq('late', $r['status']); eq(12, (int) $r['late_minutes']);
    $post($h, ['action' => 'add_mark', 'date' => $d2, 'status' => 'present']); eq('late', $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d2])['status'], 'adding over an existing mark is refused');
    // invalid
    $post($h, ['action' => 'edit_mark', 'date' => $d1, 'status' => 'nonsense']); eq('absent', $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'], 'an invalid status changes nothing');
    $post($h, ['action' => 'edit_mark', 'date' => date('Y-m-d', strtotime('+2 day')), 'status' => 'present']); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM attendance WHERE class_id = ? AND student_id = ? AND date > CURDATE()", [$c, $s1])['c'], 'the future cannot be marked');
    // remove
    $post($h, ['action' => 'delete_mark', 'date' => $d2]); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d2])['c'], 'the wrong mark is removed');
    $log = $db->fetchOne("SELECT old_status, new_status FROM attendance_log WHERE class_id = ? AND student_id = ? AND date = ? ORDER BY id DESC LIMIT 1", [$c, $s1, $d2]); eq('late', $log['old_status']); eq(null, $log['new_status'], 'the removal is logged');
    // no CSRF, other teacher, a student
    $before = $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'];
    $h->post("pages/attendance/class.php?id=$c", ['action' => 'edit_mark', 'student_id' => $s1, 'date' => $d1, 'session' => '1', 'status' => 'present', 'csrf_token' => 'bad']); eq($before, $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'], 'no CSRF token, no change');
    $o = client(mkUser('EdO', 'teacher')); $ot = $o->token('pages/dashboard.php'); $o->post("pages/attendance/class.php?id=$c", ['csrf_token' => $ot, 'action' => 'edit_mark', 'student_id' => $s1, 'date' => $d1, 'session' => '1', 'status' => 'present']);
    eq($before, $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'], 'another teacher cannot change it');
    $st = client($s1); $stt = $st->token('pages/dashboard.php'); $st->post("pages/attendance/class.php?id=$c", ['csrf_token' => $stt, 'action' => 'edit_mark', 'student_id' => $s1, 'date' => $d1, 'session' => '1', 'status' => 'present']);
    eq($before, $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'], 'a student cannot change their own mark');
    $st->get("pages/attendance/class.php?id=$c&view=edit&student=$s1"); notContains('att-edit-list', $st->body, 'a student does not get the edit screen');
    // locked day: only the administrator
    setSetting('attendance_lock_days', '1'); $post($h, ['action' => 'edit_mark', 'date' => $d1, 'status' => 'present']); eq('absent', $db->fetchOne("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND date = ?", [$c, $s1, $d1])['status'], 'a locked day cannot be edited by the teacher'); setSetting('attendance_lock_days', '0');
});

test('attendance › Edit a student: the subject is chosen once (module heading), then the student and an exact day (any past day, not only today); bad or future dates are ignored with a notice', function () {
    $db = TW('db'); $t = mkUser('EpT', 'teacher'); $s = mkUser('EpS'); $c1 = mkClass($t, 'Subject One', [$s]); $c2 = mkClass($t, 'Subject Two', [$s]); $h = client($t);
    $d = date('Y-m-d', strtotime('-5 day')); $d2 = date('Y-m-d', strtotime('-6 day'));
    foreach ([$d, $d2] as $x) $db->query("INSERT INTO attendance (class_id, student_id, date, session, status, marked_by) VALUES (?, ?, ?, 1, 'present', ?)", [$c1, $s, $x, $t]);
    $h->get('pages/attendance/view.php'); ok($h->shown()); notContains('pages/attendance/edit.php', $h->body, 'the hub has no separate edit button');
    $h->get("pages/attendance/class.php?id=$c1&view=edit&student=$s"); contains('id="attClassSwitch"', $h->body, 'the subject is chosen once, in the module heading'); contains('data-keep="&amp;view=edit"', $h->body, 'switching subject stays on the Edit screen'); notContains('aria-label="Subject (class)"', $h->body, 'no second subject choice on the Edit screen'); contains('name="day"', $h->body);
    $h->get("pages/attendance/class.php?id=$c1&view=edit&student=$s&day=$d"); eq(1, substr_count($h->body, 'class="att-edit-form"'), 'one exact day shows only that day'); contains($d, $h->body);
    $h->get("pages/attendance/class.php?id=$c1&view=edit&student=$s&day=" . date('Y-m-d', strtotime('+3 day'))); contains('were in the future', $h->body, 'a future day is ignored with a notice');
    $h->get("pages/attendance/class.php?id=$c1&view=edit&student=$s&day=not-a-date"); contains('were not valid', $h->body, 'a bad date is ignored with a notice');
    $h->get("pages/attendance/class.php?id=$c2&view=edit&student=$s"); notContains('class="att-edit-form"', $h->body, 'the other subject has no marks of this student');
    $o = client(mkUser('EpO', 'teacher')); $o->get("pages/attendance/class.php?id=$c1&view=edit&student=$s"); ok(!$o->shown(), 'another teacher cannot open it');
});
