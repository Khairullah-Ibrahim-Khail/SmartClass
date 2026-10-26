<?php
/**
 * Student progress in one class — ?class_id=N&student_id=M
 * The class teacher can open any enrolled student; a student only themselves.
 * Overall grade, turned-in / missing / late counts, quiz average, and every
 * assignment and quiz with its status and marks (same data as the gradebook).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['class_id'] ?? 0), $current_user_id, $current_user_role);
$student_id = isTeacher() ? (int) ($_GET['student_id'] ?? 0) : (int) $current_user_id;
$student = $class ? $db->fetchOne(
    "SELECT u.id, u.name, u.email, cm.joined_at FROM class_members cm
     INNER JOIN users u ON cm.student_id = u.id
     WHERE cm.class_id = ? AND cm.student_id = ?", [$class['id'], $student_id]) : null;
if (!$class || !$student) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}

// The class teacher can record the number the school writes to (the student's own, or a father / uncle / elder brother)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isTeacher() && ($_POST['action'] ?? '') === 'set_whatsapp' && verifyCsrf()) {
    $raw = trim((string) ($_POST['whatsapp'] ?? ''));
    if ($raw === '') $db->query("UPDATE users SET whatsapp_number = NULL, whatsapp_consent_at = NULL, whatsapp_relation = NULL WHERE id = ?", [$student_id]);
    elseif (waSavePerson($student_id, $raw, (string) ($_POST['relation'] ?? 'self'))) logActivity('whatsapp_person', 'students', "WhatsApp number set for {$student['name']} by the teacher");
    else { Session::flash('wa_stu_err', 'Enter a valid WhatsApp number, for example +93 700 123 456 (or 0700 123 456).'); }
    redirect(APP_URL . '/pages/classes/student.php?class_id=' . (int) $class['id'] . '&student_id=' . $student_id);
}
$book = getClassGradebook($class['id'], $student_id, !isTeacher());   // a student viewing their own progress: unreleased results stay hidden
$cols = $book['columns'];
$row = $book['rows'][0];

$counts = ['assignments' => 0, 'turned_in' => 0, 'late' => 0, 'missing' => 0, 'quizzes' => 0, 'quiz_done' => 0];
$quiz_pct = [];
foreach ($cols as $i => $c) {
    $cell = $row['cells'][$i];
    if ($c['type'] === 'assignment') {
        $counts['assignments']++;
        if (in_array($cell['state'], ['submitted', 'late', 'graded', 'held'], true)) $counts['turned_in']++;
        if ($cell['state'] === 'late') $counts['late']++;
        if ($cell['state'] === 'missing') $counts['missing']++;
    } else {
        $counts['quizzes']++;
        if ($cell['state'] === 'held') {
            $counts['quiz_done']++;   // completed, result not released yet
        } elseif ($cell['score'] !== null) {
            $counts['quiz_done']++;
            if ($c['max'] > 0) $quiz_pct[] = $cell['score'] / $c['max'] * 100;
        } elseif ($cell['state'] === 'missing') {
            $counts['missing']++;
        }
    }
}
$last_activity = $db->fetchOne(
    "SELECT MAX(t) AS t FROM (
        SELECT MAX(s.submitted_at) AS t FROM submissions s INNER JOIN assignments a ON s.assignment_id = a.id WHERE a.class_id = ? AND s.student_id = ?
        UNION ALL SELECT MAX(q.submitted_at) FROM quiz_attempts q INNER JOIN quizzes z ON q.quiz_id = z.id WHERE z.class_id = ? AND q.student_id = ?
        UNION ALL SELECT MAX(c.created_at) FROM announcement_comments c INNER JOIN announcements an ON c.announcement_id = an.id WHERE an.class_id = ? AND c.user_id = ?
     ) x", [$class['id'], $student_id, $class['id'], $student_id, $class['id'], $student_id])['t'] ?? null;

$att = getAttendanceSummary($class['id'], $student_id)[$student_id] ?? null;

// The files this student handed in for this class's assignments (by assignment id), so they can be opened from here
$sub_files = [];
foreach ($db->fetchAll("SELECT s.id, s.assignment_id, s.original_name, s.file_path, s.submitted_at FROM submissions s INNER JOIN assignments a ON a.id = s.assignment_id
                        WHERE a.class_id = ? AND s.student_id = ? AND s.file_path IS NOT NULL", [$class['id'], $student_id]) as $f) $sub_files[(int) $f['assignment_id']] = $f;

$labels = ['graded' => 'Graded', 'done' => 'Completed', 'timeout' => 'Over time', 'submitted' => 'Turned in',
           'late' => 'Turned in late', 'missing' => 'Missing', 'pending' => 'Assigned', 'open' => 'In progress', 'held' => 'Result pending', 'returned' => 'Returned for changes'];
$badges = ['graded' => 'graded', 'done' => 'graded', 'timeout' => 'missing', 'submitted' => 'submitted',
           'late' => 'late', 'missing' => 'missing', 'pending' => 'pending', 'open' => 'late', 'held' => 'submitted', 'returned' => 'late'];

/** "17.5" / "20". */
function progMarks($m) {
    return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
}

$page_title = $student['name'];
$page_subtitle = $class['class_name'] . ' · Student progress';
$active_menu = 'classes';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<a class="back-link" href="<?php ob_start(); ?><?php echo isTeacher() ? 'people' : 'grades'; ?>.php?id=<?php echo (int) $class['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>">
    <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($class['class_name']); ?>
</a>

<div class="content-card">
    <div class="person-row" style="border:none;">
        <?php echo avatarHtml($student['id'], $student['name'], '', 'width:52px;height:52px;font-size:1.3rem;'); ?>
        <div style="flex:1;min-width:0;">
            <strong style="font-size:1.1rem;"><?php echo htmlspecialchars($student['name']); ?></strong>
            <div class="notif-meta"><?php echo htmlspecialchars($student['email']); ?> · joined <?php echo formatDate($student['joined_at'], 'M d, Y'); ?>
                · last active <?php echo $last_activity ? timeAgo($last_activity) : 'never'; ?></div>
            <?php if (isTeacher()): $gc = $db->fetchOne("SELECT guardian_name, guardian_email, guardian_phone FROM users WHERE id = ?", [$student_id]); ?>
            <div class="notif-meta"><i class="fas fa-user-shield" aria-hidden="true"></i> Guardian:
                <?php if ($gc && ($gc['guardian_name'] || $gc['guardian_email'] || $gc['guardian_phone'])): ?>
                    <?php echo htmlspecialchars((string) $gc['guardian_name'] ?: '—'); ?>
                    <?php if ($gc['guardian_phone']): ?> · <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $gc['guardian_phone'])); ?>"><?php echo htmlspecialchars($gc['guardian_phone']); ?></a><?php endif; ?>
                    <?php if ($gc['guardian_email']): ?> · <a href="mailto:<?php echo htmlspecialchars($gc['guardian_email']); ?>"><?php echo htmlspecialchars($gc['guardian_email']); ?></a><?php endif; ?>
                <?php else: ?><span class="form-hint" style="display:inline;margin:0;">not recorded — the student can add it in Settings › Profile</span><?php endif; ?></div>
            <?php $wn = $db->fetchOne("SELECT whatsapp_number, whatsapp_relation FROM users WHERE id = ?", [$student_id]); $wer = Session::flash('wa_stu_err'); ?>
            <div class="notif-meta"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp:
                <?php if ($wn && $wn['whatsapp_number']): ?><strong dir="ltr">+<?php echo htmlspecialchars($wn['whatsapp_number']); ?></strong> · <?php echo htmlspecialchars(t(waRelations()[$wn['whatsapp_relation'] ?: 'self'] ?? 'My own number')); ?>
                <?php else: ?><span class="form-hint" style="display:inline;margin:0;">not recorded</span><?php endif; ?>
                <details style="display:inline-block;margin-inline-start:8px;"><summary style="cursor:pointer;display:inline;color:var(--primary,#4361ee);"><?php echo $wn && $wn['whatsapp_number'] ? 'Change' : 'Add'; ?></summary>
                        <form method="POST" class="notif-meta" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:6px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_whatsapp">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> <span>WhatsApp number</span>
                <input type="text" name="whatsapp" inputmode="tel" maxlength="30" placeholder="+93 700 123 456" value="<?php echo htmlspecialchars($wn && $wn['whatsapp_number'] ? '+' . $wn['whatsapp_number'] : ''); ?>" style="max-width:170px;" aria-label="WhatsApp number">
                <select name="relation" aria-label="Whose number"><?php foreach (waRelations() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo ($wn['whatsapp_relation'] ?? 'self') === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars(t($rl)); ?></option><?php endforeach; ?></select>
                <button class="btn-app btn-sm btn-outline">Save</button><?php if ($wer): ?><span class="invalid-feedback show" style="width:100%;"><?php echo htmlspecialchars(t($wer)); ?></span><?php endif; ?></form></details></div>
            <?php endif; ?>
        </div>
        <?php if ($row['percent'] !== null): ?>
        <div class="overall-grade"><span><?php echo $row['percent']; ?>%</span><small>overall</small></div>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid" style="margin-bottom:22px;">
    <div class="detail-item"><div class="label">Assignments turned in</div><div class="value"><?php echo $counts['turned_in']; ?> / <?php echo $counts['assignments']; ?></div></div>
    <div class="detail-item"><div class="label">Missing work</div><div class="value" style="<?php echo $counts['missing'] ? 'color:var(--danger)' : ''; ?>"><?php echo $counts['missing']; ?></div></div>
    <div class="detail-item"><div class="label">Turned in late</div><div class="value"><?php echo $counts['late']; ?></div></div>
    <div class="detail-item"><div class="label">Quizzes completed</div><div class="value"><?php echo $counts['quiz_done']; ?> / <?php echo $counts['quizzes']; ?></div></div>
    <div class="detail-item"><div class="label">Quiz average</div><div class="value"><?php echo $quiz_pct ? round(array_sum($quiz_pct) / count($quiz_pct), 1) . '%' : '—'; ?></div></div>
    <a class="detail-item" style="text-decoration:none;color:inherit;" href="<?php echo attendanceUrl($class['id']); ?><?php echo isTeacher() ? '&amp;view=report' : ''; ?>">
        <div class="label">Attendance</div>
        <div class="value" style="<?php echo ($att && $att['rate'] !== null && $att['rate'] < attLow()) ? 'color:var(--danger)' : ''; ?>">
            <?php echo ($att && $att['rate'] !== null) ? $att['rate'] . '% · ' . $att['absent'] . ' absent' : '—'; ?></div>
    </a>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-list-check"></i> All work</h2></div>
    <?php if (!$cols): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-book-open"></i><p>No assignments or quizzes in this class yet.</p></div>
    <?php endif; ?>
    <?php foreach (array_reverse(array_keys($cols)) as $i): $c = $cols[$i]; $cell = $row['cells'][$i]; ?>
    <a class="classwork-item" href="<?php echo APP_URL . '/' . $c['url']; ?>">
        <span class="work-icon type-<?php echo $c['type']; ?>"><i class="fas <?php echo $c['type'] === 'quiz' ? 'fa-question-circle' : 'fa-tasks'; ?>"></i></span>
        <span class="classwork-main">
            <span class="classwork-title"><?php echo htmlspecialchars($c['title']); ?></span>
            <span class="notif-meta"><?php echo $c['type'] === 'quiz' ? 'Quiz' : 'Assignment'; ?> · out of <?php echo (int) $c['max']; ?><?php echo $c['due'] ? ' · due ' . formatDate($c['due'], 'M d, h:i A') : ''; ?></span>
        </span>
        <?php if ($cell['score'] !== null): ?>
        <span class="marks"><?php echo progMarks($cell['score']); ?> / <?php echo (int) $c['max']; ?></span>
        <?php endif; ?>
        <span class="status-badge <?php echo $badges[$cell['state']]; ?>"><?php echo $labels[$cell['state']]; ?></span>
    </a>
    <?php if ($c['type'] === 'assignment' && isset($sub_files[(int) $c['id']])): $sf = $sub_files[(int) $c['id']]; $sx = pathinfo((string) $sf['file_path'], PATHINFO_EXTENSION); $dl = APP_URL . '/pages/assignments/download.php?id=' . (int) $sf['id']; ?>
    <div class="work-files"><i class="fas fa-paperclip" aria-hidden="true"></i> <span>Submitted file:</span>
        <?php echo submissionFileHtml($sf['id'], $sf['original_name'], $sf['file_path'], APP_URL . '/pages/assignments/'); ?></div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
