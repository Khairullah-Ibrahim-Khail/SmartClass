<?php
/**
 * Research projects — a process between ONE student and the teachers of the class (loaded by includes/functions.php).
 *
 *   draft ──submit──▶ proposed ──approve──▶ active ──all milestones accepted + teacher completes──▶ completed
 *                        │  ▲                  ▲
 *                 request changes ◀── student edits & resubmits          (a teacher can also close a project)
 *   inside "active": the teacher sets milestones; the student submits work for each; the teacher accepts it or asks for changes.
 * Everything that happens is written to research_updates (the timeline) and the other side is notified.
 *
 *   researchStatuses()                                  status => [label, badge class, icon]
 *   researchProject($id, $user_id, $role)               the project with names, or null when this person may not see it
 *   researchMilestones($pid) / researchUpdates($pid)    the rows
 *   researchProgress($pid)                              [accepted, total]
 *   researchSave / researchSubmitProposal / researchDecide / researchAddMilestone / researchDeleteMilestone /
 *   researchSubmitMilestone / researchReviewMilestone / researchComment / researchComplete / researchClose / researchDeleteDraft
 *       each returns ['ok' => bool, 'error' => ?string, 'errors' => [field => message]]
 */

const RESEARCH_MAX_MILESTONES = 12;

function researchStatuses() {
    return [
        'draft'     => ['Draft',              'pending',   'fa-pen'],
        'proposed'  => ['Waiting for the teacher', 'late',  'fa-hourglass-half'],
        'changes'   => ['Changes requested',  'missing',   'fa-rotate-left'],
        'active'    => ['In progress',        'submitted', 'fa-person-running'],
        'completed' => ['Completed',          'graded',    'fa-circle-check'],
        'closed'    => ['Closed',             'pending',   'fa-ban'],
    ];
}

function researchMilestoneStatuses() {
    return ['open' => ['To do', 'pending'], 'submitted' => ['Waiting for review', 'late'], 'accepted' => ['Accepted', 'graded'], 'changes' => ['Changes requested', 'missing']];
}

/** The project when this person may see it: its student, or a teacher of its class. */
function researchProject($id, $user_id, $role) {
    global $db;
    $p = $db->fetchOne(
        "SELECT p.*, c.class_name, s.name AS student_name, sv.name AS supervisor_name FROM research_projects p
         INNER JOIN classes c ON c.id = p.class_id INNER JOIN users s ON s.id = p.student_id LEFT JOIN users sv ON sv.id = p.supervisor_id WHERE p.id = ?", [(int) $id]);
    if (!$p) return null;
    if ($role === 'student') return (int) $p['student_id'] === (int) $user_id ? $p : null;
    return $db->fetchOne("SELECT 1 FROM class_teachers WHERE class_id = ? AND teacher_id = ?", [(int) $p['class_id'], (int) $user_id]) ? $p : null;
}

function researchMilestones($pid) {
    global $db;
    return $db->fetchAll("SELECT * FROM research_milestones WHERE project_id = ? ORDER BY position, id", [(int) $pid]);
}

function researchUpdates($pid) {
    global $db;
    return $db->fetchAll("SELECT u.*, a.name AS author_name, a.role AS author_role, m.title AS milestone_title FROM research_updates u
        LEFT JOIN users a ON a.id = u.author_id LEFT JOIN research_milestones m ON m.id = u.milestone_id WHERE u.project_id = ? ORDER BY u.id", [(int) $pid]);
}

function researchProgress($pid) {
    global $db;
    $r = $db->fetchOne("SELECT COUNT(*) AS t, SUM(status = 'accepted') AS a FROM research_milestones WHERE project_id = ?", [(int) $pid]);
    return [(int) ($r['a'] ?? 0), (int) ($r['t'] ?? 0)];
}

function researchLog($pid, $author_id, $kind, $body, $milestone_id = null, $file = null) {
    global $db;
    return (int) $db->insert('research_updates', ['project_id' => (int) $pid, 'milestone_id' => $milestone_id ?: null, 'author_id' => $author_id ?: null, 'kind' => $kind, 'body' => $body,
        'file_path' => $file['path'] ?? null, 'file_name' => $file['original_name'] ?? null, 'file_type' => $file['ext'] ?? null]);
}

/** Tell the teachers of the project's class (all of them) — or the student. */
function researchTellTeachers($p, $title, $text, $sender_id, $type = 'research_update') {
    global $db;
    foreach ($db->fetchAll("SELECT teacher_id id FROM class_teachers WHERE class_id = ?", [(int) $p['class_id']]) as $t) {
        if ((int) $t['id'] !== (int) $sender_id) notify((int) $t['id'], $type, $title, $text, 'pages/research/project.php?id=' . (int) $p['id'], $sender_id, (int) $p['class_id']);
    }
}
function researchTellStudent($p, $title, $text, $sender_id) {
    notify((int) $p['student_id'], 'research_update', $title, $text, 'pages/research/project.php?id=' . (int) $p['id'], $sender_id, (int) $p['class_id']);
}

function researchFail($errors, $msg = null) { return ['ok' => false, 'error' => $msg ?? (is_array($errors) ? reset($errors) : $errors), 'errors' => is_array($errors) ? $errors : []]; }
function researchOk() { return ['ok' => true, 'error' => null, 'errors' => []]; }

/** Validate the three proposal fields. */
function researchValidate($title, $question, $plan) {
    $e = [];
    $title = trim($title); $question = trim($question); $plan = trim($plan);
    if (mb_strlen($title) < 4) $e['title'] = 'Give the project a title (at least 4 characters).';
    elseif (mb_strlen($title) > 200) $e['title'] = 'The title must be under 200 characters.';
    if (mb_strlen($question) > 500) $e['question'] = 'The research question must be under 500 characters.';
    if (mb_strlen($plan) > 8000) $e['plan'] = 'The plan is too long (8000 characters at most).';
    return $e;
}

/** Create (id = 0) or update a draft / changes-requested project of this student. */
function researchSave($student_id, $class_id, $id, $title, $question, $plan) {
    global $db;
    $e = researchValidate($title, $question, $plan);
    if ($e) return researchFail($e);
    if (!$db->fetchOne("SELECT 1 FROM class_members WHERE class_id = ? AND student_id = ?", [(int) $class_id, (int) $student_id])) return researchFail(['class' => 'Choose one of your classes.']);
    $row = ['title' => trim($title), 'question' => trim($question), 'plan' => trim($plan)];
    if ($id) {
        $p = $db->fetchOne("SELECT * FROM research_projects WHERE id = ? AND student_id = ?", [(int) $id, (int) $student_id]);
        if (!$p) return researchFail('Project not found.');
        if (!in_array($p['status'], ['draft', 'changes'], true)) return researchFail('This project can no longer be edited here — use the discussion below.');
        $db->update('research_projects', $row, 'id = ?', [(int) $id]);
        return researchOk() + ['id' => (int) $id];
    }
    $n = (int) $db->fetchOne("SELECT COUNT(*) c FROM research_projects WHERE student_id = ? AND class_id = ? AND status IN ('draft','proposed','changes','active')", [(int) $student_id, (int) $class_id])['c'];
    if ($n >= 5) return researchFail('You already have 5 open projects in this class. Finish one first.');
    $new = (int) $db->insert('research_projects', $row + ['class_id' => (int) $class_id, 'student_id' => (int) $student_id, 'status' => 'draft']);
    return researchOk() + ['id' => $new];
}

function researchSubmitProposal($p, $student_id) {
    global $db;
    if ((int) $p['student_id'] !== (int) $student_id) return researchFail('Not your project.');
    if (!in_array($p['status'], ['draft', 'changes'], true)) return researchFail('This proposal was already sent.');
    $e = [];
    if (trim((string) $p['question']) === '') $e['question'] = 'Write the research question your project will answer.';
    if (mb_strlen(trim((string) $p['plan'])) < 20) $e['plan'] = 'Describe your plan in a few sentences (at least 20 characters).';
    if ($e) return researchFail($e);
    $again = $p['status'] === 'changes';
    $db->update('research_projects', ['status' => 'proposed', 'submitted_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $p['id']]);
    researchLog($p['id'], $student_id, 'system', $again ? 'Proposal sent again after the changes.' : 'Proposal sent to the teacher.');
    researchTellTeachers($p, 'Research proposal to review', ($p['student_name'] ?? 'A student') . ': ' . $p['title'], $student_id);
    return researchOk();
}

/** A teacher answers a proposal: approve | changes | close. */
function researchDecide($p, $teacher_id, $decision, $note) {
    global $db;
    $note = trim($note);
    if ($p['status'] !== 'proposed') return researchFail('This proposal is not waiting for a decision.');
    if (!in_array($decision, ['approve', 'changes', 'close'], true)) return researchFail('Choose approve, request changes or close.');
    if ($decision !== 'approve' && mb_strlen($note) < 3) return researchFail(['note' => $decision === 'changes' ? 'Tell the student what to change.' : 'Write the reason for closing this project.']);
    if (mb_strlen($note) > 3000) return researchFail(['note' => 'Keep the note under 3000 characters.']);
    if ($decision === 'approve') {
        $db->update('research_projects', ['status' => 'active', 'approved_at' => date('Y-m-d H:i:s'), 'supervisor_id' => (int) $teacher_id], 'id = ?', [(int) $p['id']]);
        researchLog($p['id'], $teacher_id, 'decision', 'Proposal approved.' . ($note !== '' ? "\n" . $note : ''));
        researchTellStudent($p, 'Your research proposal was approved', $p['title'] . ' — you can start on the milestones.', $teacher_id);
    } elseif ($decision === 'changes') {
        $db->update('research_projects', ['status' => 'changes'], 'id = ?', [(int) $p['id']]);
        researchLog($p['id'], $teacher_id, 'decision', "Changes requested:\n" . $note);
        researchTellStudent($p, 'Changes requested on your research proposal', $p['title'] . ': ' . mb_strimwidth($note, 0, 120, '…'), $teacher_id);
    } else {
        $db->update('research_projects', ['status' => 'closed'], 'id = ?', [(int) $p['id']]);
        researchLog($p['id'], $teacher_id, 'decision', "Project closed:\n" . $note);
        researchTellStudent($p, 'Your research project was closed', $p['title'] . ': ' . mb_strimwidth($note, 0, 120, '…'), $teacher_id);
    }
    return researchOk();
}

function researchAddMilestone($p, $teacher_id, $title, $due) {
    global $db;
    $title = trim($title); $due = trim($due);
    if ($p['status'] !== 'active') return researchFail('Milestones can be set while the project is in progress.');
    $e = [];
    if (mb_strlen($title) < 3) $e['milestone_title'] = 'Give the milestone a short title.';
    elseif (mb_strlen($title) > 150) $e['milestone_title'] = 'The title must be under 150 characters.';
    if ($due !== '') { $d = DateTime::createFromFormat('Y-m-d', $due); if (!$d || $d->format('Y-m-d') !== $due) $e['due_date'] = 'Pick a valid date.'; }
    if ($e) return researchFail($e);
    $count = (int) $db->fetchOne("SELECT COUNT(*) c FROM research_milestones WHERE project_id = ?", [(int) $p['id']])['c'];
    if ($count >= RESEARCH_MAX_MILESTONES) return researchFail(['milestone_title' => 'A project can have at most ' . RESEARCH_MAX_MILESTONES . ' milestones.']);
    $mid = (int) $db->insert('research_milestones', ['project_id' => (int) $p['id'], 'title' => $title, 'due_date' => $due !== '' ? $due : null, 'position' => $count + 1]);
    researchLog($p['id'], $teacher_id, 'system', 'Milestone added: ' . $title . ($due !== '' ? ' (due ' . date('M j, Y', strtotime($due)) . ')' : '') . '.', $mid);
    researchTellStudent($p, 'New research milestone', $p['title'] . ': ' . $title . ($due !== '' ? ' — due ' . date('M j', strtotime($due)) : ''), $teacher_id);
    return researchOk();
}

function researchDeleteMilestone($p, $teacher_id, $mid) {
    global $db;
    $m = $db->fetchOne("SELECT * FROM research_milestones WHERE id = ? AND project_id = ?", [(int) $mid, (int) $p['id']]);
    if (!$m) return researchFail('Milestone not found.');
    if ($p['status'] !== 'active') return researchFail('Milestones can only be removed while the project is in progress.');
    if ($m['status'] !== 'open') return researchFail('This milestone already has work on it, so it cannot be removed.');
    $db->query("UPDATE research_updates SET milestone_id = NULL WHERE milestone_id = ?", [(int) $mid]);
    $db->delete('research_milestones', 'id = ?', [(int) $mid]);
    researchLog($p['id'], $teacher_id, 'system', 'Milestone removed: ' . $m['title'] . '.');
    return researchOk();
}

/** The student hands in work for one milestone (text and/or a file). */
function researchSubmitMilestone($p, $student_id, $mid, $body, $file) {
    global $db;
    $body = trim($body);
    if ((int) $p['student_id'] !== (int) $student_id) return researchFail('Not your project.');
    if ($p['status'] !== 'active') return researchFail('You can hand in work while the project is in progress.');
    $m = $db->fetchOne("SELECT * FROM research_milestones WHERE id = ? AND project_id = ?", [(int) $mid, (int) $p['id']]);
    if (!$m) return researchFail('Milestone not found.');
    if (!in_array($m['status'], ['open', 'changes'], true)) return researchFail($m['status'] === 'submitted' ? 'This milestone is already waiting for your teacher.' : 'This milestone was already accepted.');
    $has_file = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (mb_strlen($body) < 3 && !$has_file) return researchFail(['body_' . (int) $mid => 'Write what you did, or attach a file.']);
    if (mb_strlen($body) > 8000) return researchFail(['body_' . (int) $mid => 'Keep it under 8000 characters (attach a file for more).']);
    $stored = null;
    if ($has_file) {
        $chk = storeUploadCheck($file);
        if ($chk) return researchFail(['file_' . (int) $mid => $chk]);
        $stored = storeUpload($file, 'research');
        if (!$stored['ok']) return researchFail(['file_' . (int) $mid => $stored['error']]);
    }
    researchLog($p['id'], $student_id, 'submission', $body, (int) $mid, $stored);
    $db->update('research_milestones', ['status' => 'submitted'], 'id = ?', [(int) $mid]);
    researchTellTeachers($p, 'Research work to review', ($p['student_name'] ?? 'A student') . ' — ' . $m['title'], $student_id);
    return researchOk();
}

/** A teacher accepts a milestone's work or asks for changes. */
function researchReviewMilestone($p, $teacher_id, $mid, $decision, $note) {
    global $db;
    $note = trim($note);
    $m = $db->fetchOne("SELECT * FROM research_milestones WHERE id = ? AND project_id = ?", [(int) $mid, (int) $p['id']]);
    if (!$m) return researchFail('Milestone not found.');
    if ($p['status'] !== 'active' || $m['status'] !== 'submitted') return researchFail('There is no submitted work to review on this milestone.');
    if (!in_array($decision, ['accept', 'changes'], true)) return researchFail('Choose accept or request changes.');
    if ($decision === 'changes' && mb_strlen($note) < 3) return researchFail(['note_' . (int) $mid => 'Tell the student what to improve.']);
    if (mb_strlen($note) > 3000) return researchFail(['note_' . (int) $mid => 'Keep the feedback under 3000 characters.']);
    $db->update('research_milestones', ['status' => $decision === 'accept' ? 'accepted' : 'changes'], 'id = ?', [(int) $mid]);
    researchLog($p['id'], $teacher_id, 'decision', ($decision === 'accept' ? 'Accepted: ' : 'Changes requested: ') . $m['title'] . ($note !== '' ? "\n" . $note : ''), (int) $mid);
    researchTellStudent($p, $decision === 'accept' ? 'Research milestone accepted' : 'Changes requested on your research work', $p['title'] . ' — ' . $m['title'] . ($note !== '' ? ': ' . mb_strimwidth($note, 0, 100, '…') : ''), $teacher_id);
    return researchOk();
}

/** A message in the project's discussion (either side), with an optional file. */
function researchComment($p, $user_id, $role, $body, $file) {
    global $db;
    $body = trim($body);
    if (in_array($p['status'], ['draft'], true)) return researchFail('Send the proposal first — then you can talk with your teacher here.');
    $has_file = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (mb_strlen($body) < 1 && !$has_file) return researchFail(['comment' => 'Write a message or attach a file.']);
    if (mb_strlen($body) > 4000) return researchFail(['comment' => 'Keep the message under 4000 characters.']);
    $stored = null;
    if ($has_file) {
        $chk = storeUploadCheck($file); if ($chk) return researchFail(['comment_file' => $chk]);
        $stored = storeUpload($file, 'research'); if (!$stored['ok']) return researchFail(['comment_file' => $stored['error']]);
    }
    researchLog($p['id'], $user_id, 'comment', $body, null, $stored);
    $who = $role === 'student' ? ($p['student_name'] ?? 'Your student') : 'Your teacher';
    if ($role === 'student') researchTellTeachers($p, 'New message on a research project', $who . ': ' . mb_strimwidth($body, 0, 100, '…'), $user_id);
    else researchTellStudent($p, 'New message on your research project', mb_strimwidth($body, 0, 100, '…'), $user_id);
    return researchOk();
}

/** Finish the project: every milestone accepted (at least one), optional grade 0–100 and final feedback. */
function researchComplete($p, $teacher_id, $grade, $feedback) {
    global $db;
    $feedback = trim($feedback); $grade = trim((string) $grade);
    if ($p['status'] !== 'active') return researchFail('Only a project in progress can be completed.');
    [$acc, $tot] = researchProgress($p['id']);
    if ($tot < 1) return researchFail(['grade' => 'Add at least one milestone before completing the project.']);
    if ($acc < $tot) return researchFail(['grade' => ($tot - $acc) . ' milestone' . ($tot - $acc == 1 ? '' : 's') . ' still need to be accepted.']);
    if ($grade !== '' && (!is_numeric($grade) || $grade < 0 || $grade > 100)) return researchFail(['grade' => 'The grade must be a number from 0 to 100 (or empty).']);
    if (mb_strlen($feedback) > 4000) return researchFail(['feedback' => 'Keep the final feedback under 4000 characters.']);
    $db->update('research_projects', ['status' => 'completed', 'grade' => $grade !== '' ? round((float) $grade, 2) : null, 'final_feedback' => $feedback !== '' ? $feedback : null, 'completed_at' => date('Y-m-d H:i:s'), 'supervisor_id' => $p['supervisor_id'] ?: (int) $teacher_id], 'id = ?', [(int) $p['id']]);
    researchLog($p['id'], $teacher_id, 'decision', 'Project completed' . ($grade !== '' ? ' — grade ' . round((float) $grade, 2) . '/100' : '') . '.' . ($feedback !== '' ? "\n" . $feedback : ''));
    researchTellStudent($p, 'Your research project is complete', $p['title'] . ($grade !== '' ? ' — grade ' . round((float) $grade, 2) . '/100' : ''), $teacher_id);
    return researchOk();
}

/** Close an in-progress project (teacher). */
function researchClose($p, $teacher_id, $note) {
    global $db;
    $note = trim($note);
    if (!in_array($p['status'], ['active', 'changes', 'proposed'], true)) return researchFail('This project is already finished.');
    if (mb_strlen($note) < 3) return researchFail(['close_note' => 'Write the reason for closing this project.']);
    $db->update('research_projects', ['status' => 'closed'], 'id = ?', [(int) $p['id']]);
    researchLog($p['id'], $teacher_id, 'decision', "Project closed:\n" . $note);
    researchTellStudent($p, 'Your research project was closed', $p['title'] . ': ' . mb_strimwidth($note, 0, 120, '…'), $teacher_id);
    return researchOk();
}

/** The student removes a project that was never sent. */
function researchDeleteDraft($p, $student_id) {
    global $db;
    if ((int) $p['student_id'] !== (int) $student_id || $p['status'] !== 'draft') return researchFail('Only a draft that was not sent can be deleted.');
    $db->delete('research_projects', 'id = ?', [(int) $p['id']]);
    return researchOk();
}

/** Projects a person can see, newest activity first. $status: '' for all. */
function researchList($user_id, $role, $class_id = 0, $status = '') {
    global $db;
    $where = ''; $params = [];
    if ($role === 'student') { $where = 'p.student_id = ?'; $params[] = (int) $user_id; }
    else { $where = 'p.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = ?)'; $params[] = (int) $user_id; }
    if ($class_id) { $where .= ' AND p.class_id = ?'; $params[] = (int) $class_id; }
    if ($status !== '' && isset(researchStatuses()[$status])) { $where .= ' AND p.status = ?'; $params[] = $status; }
    return $db->fetchAll(
        "SELECT p.*, c.class_name, s.name AS student_name,
                (SELECT COUNT(*) FROM research_milestones m WHERE m.project_id = p.id) AS ms_total,
                (SELECT COUNT(*) FROM research_milestones m WHERE m.project_id = p.id AND m.status = 'accepted') AS ms_done,
                (SELECT COUNT(*) FROM research_milestones m WHERE m.project_id = p.id AND m.status = 'submitted') AS ms_review,
                (SELECT MIN(m.due_date) FROM research_milestones m WHERE m.project_id = p.id AND m.status IN ('open','changes') AND m.due_date IS NOT NULL) AS next_due
         FROM research_projects p INNER JOIN classes c ON c.id = p.class_id INNER JOIN users s ON s.id = p.student_id
         WHERE {$where} ORDER BY p.updated_at DESC, p.id DESC LIMIT 200", $params);
}

/** What waits for this person: teacher = proposals + submitted milestones, student = changes requested (proposal or milestones). */
function researchWaiting($user_id, $role) {
    global $db;
    if ($role === 'student') {
        return (int) $db->fetchOne("SELECT COUNT(DISTINCT p.id) c FROM research_projects p LEFT JOIN research_milestones m ON m.project_id = p.id AND m.status = 'changes'
            WHERE p.student_id = ? AND (p.status = 'changes' OR (p.status = 'active' AND m.id IS NOT NULL))", [(int) $user_id])['c'];
    }
    return (int) $db->fetchOne("SELECT COUNT(DISTINCT p.id) c FROM research_projects p LEFT JOIN research_milestones m ON m.project_id = p.id AND m.status = 'submitted'
        WHERE p.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = ?) AND (p.status = 'proposed' OR (p.status = 'active' AND m.id IS NOT NULL))", [(int) $user_id])['c'];
}

// ============================================================ reminders (G1) =====
/**
 * Nightly (cron/housekeeping.php): tell people about milestones that need them. Each reminder is sent once per situation:
 *   soon     the student: a milestone is due within 2 days
 *   overdue  the student (again every 7 days) + the teachers (once): a milestone is past its date
 *   stale    the teachers (again every 14 days): an active project had no activity for 14 days
 * $today lets the tests pick the day. Returns ['soon' => n, 'overdue' => n, 'stale' => n].
 */
function researchReminders($today = null) {
    global $db;
    $today = $today ?: date('Y-m-d'); $now = $today . ' 08:00:00'; $out = ['soon' => 0, 'overdue' => 0, 'stale' => 0];
    $base = "SELECT m.id AS mid, m.title AS mtitle, m.due_date, m.remind_late_at, p.id AS pid, p.title AS ptitle, p.class_id, p.student_id, s.name AS student_name
             FROM research_milestones m INNER JOIN research_projects p ON p.id = m.project_id INNER JOIN users s ON s.id = p.student_id
             WHERE m.status IN ('open','changes') AND m.due_date IS NOT NULL AND p.status = 'active'";
    $link = fn($pid) => 'pages/research/project.php?id=' . (int) $pid;
    foreach ($db->fetchAll($base . " AND m.due_date >= ? AND m.due_date <= DATE_ADD(?, INTERVAL 2 DAY) AND m.remind_soon_at IS NULL", [$today, $today]) as $r) {
        $when = $r['due_date'] === $today ? 'today' : ($r['due_date'] === date('Y-m-d', strtotime($today . ' +1 day')) ? 'tomorrow' : 'on ' . date('M j', strtotime($r['due_date'])));
        notify((int) $r['student_id'], 'research_reminder', 'Research milestone due ' . $when, $r['ptitle'] . ' — ' . $r['mtitle'], $link($r['pid']), null, (int) $r['class_id']);
        try { waQueue((int) $r['student_id'], 'research_due', 'rd:' . $r['mid'], ['title' => $r['mtitle'], 'date' => date('M j, Y', strtotime($r['due_date']))], (int) $r['class_id']); } catch (Throwable $e) { error_log('whatsapp: ' . $e->getMessage()); }
        $db->query("UPDATE research_milestones SET remind_soon_at = ? WHERE id = ?", [$now, $r['mid']]); $out['soon']++;
    }
    foreach ($db->fetchAll($base . " AND m.due_date < ? AND (m.remind_late_at IS NULL OR m.remind_late_at <= DATE_SUB(?, INTERVAL 7 DAY))", [$today, $now]) as $r) {
        $days = (int) floor((strtotime($today) - strtotime($r['due_date'])) / 86400);
        notify((int) $r['student_id'], 'research_reminder', 'Research milestone is overdue', $r['ptitle'] . ' — ' . $r['mtitle'] . ' (' . $days . ' day' . ($days == 1 ? '' : 's') . ' late)', $link($r['pid']), null, (int) $r['class_id']);
        if ($r['remind_late_at'] === null) researchTellTeachers(['id' => $r['pid'], 'class_id' => $r['class_id']], 'Research milestone is overdue', $r['student_name'] . ' — ' . $r['mtitle'] . ' (' . $days . ' day' . ($days == 1 ? '' : 's') . ' late)', null, 'research_reminder');
        $db->query("UPDATE research_milestones SET remind_late_at = ? WHERE id = ?", [$now, $r['mid']]); $out['overdue']++;
    }
    foreach ($db->fetchAll("SELECT p.id, p.title, p.class_id, s.name AS student_name,
                COALESCE((SELECT MAX(u.created_at) FROM research_updates u WHERE u.project_id = p.id), p.approved_at, p.created_at) AS last_at
            FROM research_projects p INNER JOIN users s ON s.id = p.student_id
            WHERE p.status = 'active' AND (p.stale_notified_at IS NULL OR p.stale_notified_at <= DATE_SUB(?, INTERVAL 14 DAY))
              AND COALESCE((SELECT MAX(u.created_at) FROM research_updates u WHERE u.project_id = p.id), p.approved_at, p.created_at) < DATE_SUB(?, INTERVAL 14 DAY)", [$now, $now]) as $r) {
        $days = (int) floor((strtotime($today) - strtotime($r['last_at'])) / 86400);
        researchTellTeachers(['id' => $r['id'], 'class_id' => $r['class_id']], 'Research project with no activity', $r['student_name'] . ' — ' . $r['title'] . ' (' . $days . ' days quiet)', null, 'research_reminder');
        $db->query("UPDATE research_projects SET stale_notified_at = ? WHERE id = ?", [$now, $r['id']]); $out['stale']++;
    }
    return $out;
}

/** Milestones of active projects due in the next $days days (and the overdue ones, flagged) for the Dashboard: student = own, teacher = their classes. */
function researchDueSoon($user_id, $role, $days = 14, $limit = 4) {
    global $db;
    $scope = $role === 'student' ? 'p.student_id = ?' : 'p.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = ?)';
    return $db->fetchAll("SELECT m.id, m.title AS mtitle, m.due_date, p.id AS pid, p.title AS ptitle, c.class_name, s.name AS student_name
        FROM research_milestones m INNER JOIN research_projects p ON p.id = m.project_id INNER JOIN classes c ON c.id = p.class_id INNER JOIN users s ON s.id = p.student_id
        WHERE p.status = 'active' AND m.status IN ('open','changes') AND m.due_date IS NOT NULL AND m.due_date >= CURDATE() AND m.due_date <= DATE_ADD(CURDATE(), INTERVAL " . (int) $days . " DAY) AND {$scope}
        ORDER BY m.due_date LIMIT " . (int) $limit, [(int) $user_id]);
}

function researchOverdueCount($user_id, $role) {
    global $db;
    $scope = $role === 'student' ? 'p.student_id = ?' : 'p.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = ?)';
    return (int) $db->fetchOne("SELECT COUNT(*) c FROM research_milestones m INNER JOIN research_projects p ON p.id = m.project_id
        WHERE p.status = 'active' AND m.status IN ('open','changes') AND m.due_date IS NOT NULL AND m.due_date < CURDATE() AND {$scope}", [(int) $user_id])['c'];
}

// ============================================================ templates (G3) =====
/** Built-in sets: key => [name, [[milestone title, days until due], …]]. Teachers' own sets are added with the key "t<id>". */
function researchTemplatesBuiltin() {
    return [
        'standard' => ['Standard research project', [['Literature review', 14], ['Method and plan', 28], ['First draft', 49], ['Final report', 70]]],
        'short'    => ['Short report',              [['Plan', 7], ['Findings', 21], ['Report', 35]]],
        'survey'   => ['Survey project',            [['Questionnaire', 10], ['Collect the answers', 28], ['Analysis', 42], ['Final report', 56]]],
    ];
}

function researchTemplates($teacher_id) {
    global $db;
    $all = researchTemplatesBuiltin();
    foreach ($db->fetchAll("SELECT id, name, items FROM research_templates WHERE teacher_id = ? ORDER BY name", [(int) $teacher_id]) as $t) {
        $items = json_decode((string) $t['items'], true);
        if (is_array($items)) $all['t' . $t['id']] = [$t['name'], array_map(fn($i) => [(string) ($i['title'] ?? ''), (int) ($i['days'] ?? 0)], $items)];
    }
    return $all;
}

/** Add every milestone of a template (due = today + days); titles the project already has are skipped. */
function researchApplyTemplate($p, $teacher_id, $key) {
    global $db;
    $all = researchTemplates($teacher_id);
    if (!isset($all[$key])) return researchFail('Choose a template.');
    if ($p['status'] !== 'active') return researchFail('Milestones can be set while the project is in progress.');
    $have = array_map('mb_strtolower', array_column(researchMilestones($p['id']), 'title')); $added = 0;
    foreach ($all[$key][1] as [$title, $days]) {
        if ($title === '' || in_array(mb_strtolower($title), $have, true)) continue;
        $r = researchAddMilestone($p, $teacher_id, $title, $days > 0 ? date('Y-m-d', strtotime("+{$days} day")) : '');
        if (!$r['ok']) return $added ? ['ok' => true, 'error' => null, 'errors' => [], 'added' => $added] : $r;
        $added++;
    }
    return ['ok' => true, 'error' => null, 'errors' => [], 'added' => $added];
}

/** Save this project's milestones as the teacher's own template (days = how far each due date is from today). */
function researchSaveTemplate($p, $teacher_id, $name) {
    global $db;
    $name = trim($name); $ms = researchMilestones($p['id']);
    if (mb_strlen($name) < 3 || mb_strlen($name) > 100) return researchFail(['template_name' => 'Give the template a name (3 to 100 characters).']);
    if (!$ms) return researchFail(['template_name' => 'This project has no milestones to save yet.']);
    if ((int) $db->fetchOne("SELECT COUNT(*) c FROM research_templates WHERE teacher_id = ?", [(int) $teacher_id])['c'] >= 20) return researchFail(['template_name' => 'You can keep at most 20 templates. Delete one first.']);
    if ($db->fetchOne("SELECT 1 FROM research_templates WHERE teacher_id = ? AND name = ?", [(int) $teacher_id, $name])) return researchFail(['template_name' => 'You already have a template with this name.']);
    $items = array_map(fn($m) => ['title' => $m['title'], 'days' => $m['due_date'] ? max(1, (int) round((strtotime($m['due_date']) - strtotime(date('Y-m-d'))) / 86400)) : 0], $ms);
    $db->insert('research_templates', ['teacher_id' => (int) $teacher_id, 'name' => $name, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
    return researchOk();
}

function researchDeleteTemplate($teacher_id, $template_id) {
    global $db;
    $t = $db->fetchOne("SELECT id FROM research_templates WHERE id = ? AND teacher_id = ?", [(int) $template_id, (int) $teacher_id]);
    if (!$t) return researchFail('Template not found.');
    $db->delete('research_templates', 'id = ?', [(int) $template_id]);
    return researchOk();
}

// ============================================================ sources (G4) =====
function researchSources($pid) {
    global $db;
    return $db->fetchAll("SELECT s.*, u.name AS adder FROM research_sources s LEFT JOIN users u ON u.id = s.added_by WHERE s.project_id = ? ORDER BY s.id", [(int) $pid]);
}

/** Add a source: a Library item of the project's class ($item_id) or one typed in. */
function researchAddSource($p, $user_id, $item_id, $title, $authors, $year, $url, $note) {
    global $db;
    if ($p['status'] === 'closed') return researchFail('This project was closed.');
    if ((int) $db->fetchOne("SELECT COUNT(*) c FROM research_sources WHERE project_id = ?", [(int) $p['id']])['c'] >= 40) return researchFail(['source_title' => 'A project can list at most 40 sources.']);
    $row = ['project_id' => (int) $p['id'], 'added_by' => (int) $user_id];
    if ((int) $item_id > 0) {
        $it = $db->fetchOne("SELECT * FROM research_items WHERE id = ? AND class_id = ?", [(int) $item_id, (int) $p['class_id']]);
        if (!$it) return researchFail(['source_item' => 'Choose an item from your class library.']);
        if ($db->fetchOne("SELECT 1 FROM research_sources WHERE project_id = ? AND item_id = ?", [(int) $p['id'], (int) $item_id])) return researchFail(['source_item' => 'This source is already in the list.']);
        $row += ['item_id' => (int) $it['id'], 'title' => $it['title'], 'authors' => $it['authors'], 'year' => $it['year'], 'url' => $it['url']];
    } else {
        $title = trim($title); $authors = trim($authors); $year = trim((string) $year); $url = trim($url); $e = [];
        if (mb_strlen($title) < 3) $e['source_title'] = 'Write the title of the source.'; elseif (mb_strlen($title) > 250) $e['source_title'] = 'The title must be under 250 characters.';
        if (mb_strlen($authors) > 250) $e['source_authors'] = 'Authors must be under 250 characters.';
        if ($year !== '' && (!preg_match('/^\d{4}$/', $year) || (int) $year < 1000 || (int) $year > (int) date('Y') + 1)) $e['source_year'] = 'Enter a 4-digit year (or leave it empty).';
        if ($url !== '') { $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME)); if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || mb_strlen($url) > 500) $e['source_url'] = 'Enter a full link starting with http:// or https://'; }
        if ($e) return researchFail($e);
        $row += ['title' => $title, 'authors' => $authors !== '' ? $authors : null, 'year' => $year !== '' ? (int) $year : null, 'url' => $url !== '' ? $url : null];
    }
    $note = trim((string) $note);
    if (mb_strlen($note) > 300) return researchFail(['source_note' => 'The note must be under 300 characters.']);
    $row['note'] = $note !== '' ? $note : null;
    $db->insert('research_sources', $row);
    return researchOk();
}

function researchDeleteSource($p, $user_id, $role, $sid) {
    global $db;
    $s = $db->fetchOne("SELECT * FROM research_sources WHERE id = ? AND project_id = ?", [(int) $sid, (int) $p['id']]);
    if (!$s) return researchFail('Source not found.');
    if ($role !== 'teacher' && (int) $s['added_by'] !== (int) $user_id) return researchFail('You can remove only the sources you added.');
    $db->delete('research_sources', 'id = ?', [(int) $sid]);
    return researchOk();
}
