<?php
/**
 * Grading screen — ?id=<assignment>[&s=<student>][&f=all|todo|missing|graded|returned]
 * One student at a time: the class list on the left (with tabs and search), the student's work in the middle (a typed answer and the file shown
 * right there — pictures, PDFs and text open without downloading), and the marks, rubric, AI suggestion, feedback, "Return for changes" and private
 * comments on the right. Save & next walks through the class. Teachers of the assignment's class only.
 *   POST save_grade (submission_id, marks, feedback, [criteria[]], then=next|stay)   POST return_work (submission_id, note)   POST private_comment (body)
 * CSRF on every POST; the submission must belong to this assignment.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$assignment_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$assignment = $assignment_id ? $db->fetchOne("SELECT * FROM assignments WHERE id = ?", [$assignment_id]) : null;
$class = $assignment ? getAccessibleClass($assignment['class_id'], $current_user_id, $current_user_role) : null;
if (!$class || !isTeacher()) redirect(APP_URL . '/pages/assignments/view.php' . ($assignment_id && $class ? '?id=' . $assignment_id : '?error=not_found'));

if (!function_exists('formatMarks')) { function formatMarks($m) { return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.'); } }
$due = $assignment['due_date'];
/** pending | missing | submitted | late | graded | returned — the state of one student's work. */
function gradeWorkState($r, $due) {
    if (!$r['submission_id']) return (!empty($due) && time() > strtotime($due)) ? 'missing' : 'pending';
    return in_array($r['status'], ['graded', 'returned', 'late'], true) ? $r['status'] : 'submitted';
}
$state_labels = ['pending' => 'Not handed in', 'missing' => 'Missing', 'submitted' => 'Turned in', 'late' => 'Turned in late', 'graded' => 'Graded', 'returned' => 'Returned for changes'];
$state_dot = ['pending' => 'idle', 'missing' => 'bad', 'submitted' => 'todo', 'late' => 'todo', 'graded' => 'ok', 'returned' => 'wait'];

$roster = $db->fetchAll(
    "SELECT u.id AS student_id, u.name, u.email, s.id AS submission_id, s.content, s.file_path, s.original_name, s.submitted_at, s.marks_obtained, s.feedback,
            s.status, s.grade_source, s.returned_at, s.return_note, s.revision, s.graded_at
     FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
     LEFT JOIN submissions s ON s.assignment_id = ? AND s.student_id = u.id
     WHERE cm.class_id = ? ORDER BY u.name", [$assignment['id'], $class['id']]);
foreach ($roster as &$r) $r['state'] = gradeWorkState($r, $due);
unset($r);
$counts = ['all' => count($roster), 'todo' => 0, 'missing' => 0, 'graded' => 0, 'returned' => 0];
foreach ($roster as $r) {
    if (in_array($r['state'], ['submitted', 'late'], true)) $counts['todo']++;
    elseif (isset($counts[$r['state']])) $counts[$r['state']]++;
}
$filter = in_array($_GET['f'] ?? '', ['todo', 'missing', 'graded', 'returned'], true) ? $_GET['f'] : 'all';
$in_filter = fn($r) => $filter === 'all' || ($filter === 'todo' ? in_array($r['state'], ['submitted', 'late'], true) : $r['state'] === $filter);
$listed = array_values(array_filter($roster, $in_filter));

// Which student is open
$sid = (int) ($_GET['s'] ?? $_POST['student_id'] ?? 0);
$cur = null;
foreach ($roster as $r) if ((int) $r['student_id'] === $sid) { $cur = $r; break; }
if (!$cur) $cur = $listed[0] ?? ($roster[0] ?? null);
$self = fn($student_id = null, $f = null) => APP_URL . '/pages/assignments/grade.php?id=' . $assignment['id'] . ($student_id ? '&s=' . (int) $student_id : '') . '&f=' . ($f ?? $filter);
$selfH = fn(...$a) => htmlspecialchars($self(...$a));   // for href="…" (the & must be &amp;)
/** The student after $student_id in the listed order (the one after the last wraps to none). */
$next_after = function ($student_id) use ($listed) {
    foreach ($listed as $i => $r) if ((int) $r['student_id'] === (int) $student_id) return $listed[$i + 1]['student_id'] ?? null;
    return null;
};

$errors = []; $task_result = Session::flash('grade_msg');
$rubric = assignmentRubric($assignment);
$max = (int) $assignment['max_marks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $sub = $cur && $cur['submission_id'] ? $db->fetchOne("SELECT s.*, u.name AS student_name FROM submissions s INNER JOIN users u ON s.student_id = u.id WHERE s.id = ? AND s.assignment_id = ?", [(int) ($_POST['submission_id'] ?? 0), $assignment['id']]) : null;
    if (!verifyCsrf()) { Session::flash('grade_msg', ['error', 'Session expired', 'Please reload the page and try again.']); redirect($self($cur['student_id'] ?? null)); }
    $go_next = ($_POST['then'] ?? '') === 'next';
    $after = $cur ? $next_after($cur['student_id']) : null;
    if ($act === 'save_grade' && $sub && (int) $sub['student_id'] === (int) $cur['student_id']) {
        $marks_raw = trim((string) ($_POST['marks'] ?? ''));
        $e = gradeSubmission($assignment, $sub, $marks_raw, $_POST['feedback'] ?? '', $current_user_id, $class['id']);
        if ($e) { $errors = $e; $form_old = ['marks' => $marks_raw, 'feedback' => (string) ($_POST['feedback'] ?? '')]; }
        else {
            Session::flash('grade_msg', ['success', 'Grade saved', $sub['student_name'] . ' has been notified' . (resultsReleased($assignment) ? '' : ' when you release the results') . '.']);
            redirect($self($go_next && $after ? $after : $cur['student_id']));
        }
    } elseif ($act === 'return_work' && $sub && (int) $sub['student_id'] === (int) $cur['student_id']) {
        $e = returnSubmission($assignment, $sub, $_POST['note'] ?? '', $current_user_id, $class['id']);
        if ($e) { $errors = $e; $form_old = ['note' => (string) ($_POST['note'] ?? '')]; }
        else { Session::flash('grade_msg', ['success', 'Sent back', $sub['student_name'] . ' can fix it and hand it in again.']); redirect($self($after ?: $cur['student_id'])); }
    } elseif ($act === 'private_comment' && $cur) {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 2000) $errors['comment'] = 'Write a comment (up to 2,000 characters)';
        else { postPrivateMessage($class, (int) $cur['student_id'], $current_user_id, $current_user_name, messageContext($class['id'], 'assignment', $assignment['id']), $body); redirect($self($cur['student_id']) . '#comments'); }
    } else { Session::flash('grade_msg', ['error', 'Not done', 'That submission does not belong to this assignment.']); redirect($self($cur['student_id'] ?? null)); }
}
$form_old = $form_old ?? [];

$thread = $cur ? $db->fetchAll("SELECT m.*, u.name AS author, u.role FROM private_messages m INNER JOIN users u ON m.user_id = u.id
    WHERE m.class_id = ? AND m.context_type = 'assignment' AND m.context_id = ? AND m.student_id = ? ORDER BY m.created_at, m.id", [$class['id'], $assignment['id'], $cur['student_id']]) : [];
$prev = null; $nxt = null;
foreach ($listed as $i => $r) if ($cur && (int) $r['student_id'] === (int) $cur['student_id']) { $prev = $listed[$i - 1]['student_id'] ?? null; $nxt = $listed[$i + 1]['student_id'] ?? null; }
$pos = 0; foreach ($listed as $i => $r) if ($cur && (int) $r['student_id'] === (int) $cur['student_id']) $pos = $i + 1;
$can_ai = aiAvailableFor('grading', $current_user_id);
$sub_ext = $cur && $cur['file_path'] ? strtolower(pathinfo((string) $cur['file_path'], PATHINFO_EXTENSION)) : '';
$kind = viewableKind($sub_ext);

$page_title = 'Grade: ' . $assignment['title']; $page_subtitle = $class['class_name'] . ' · ' . $max . ' marks' . ($due ? ' · due ' . formatDate($due, 'M d, h:i A') : '');
$active_menu = 'assignments'; $hide_page_head = true;
$page_scripts = ['assets/js/class.js', 'assets/js/grade-screen.js'] + ($can_ai ? [2 => 'assets/js/grading-ai.js', 3 => 'assets/js/grade-review-ai.js'] : []);
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode(t($task_result[1])) . ", text: " . json_encode(t($task_result[2])) . ", confirmButtonColor: '#4361ee'" . ($task_result[0] === 'success' ? ", timer: 1600, showConfirmButton: false" : "") . " });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
$tabs = ['all' => 'All', 'todo' => 'To grade', 'missing' => 'Missing', 'graded' => 'Graded', 'returned' => 'Returned'];
?>
<div class="grade-top">
    <a class="back-link" href="<?php echo APP_URL; ?>/pages/assignments/view.php?id=<?php echo (int) $assignment['id']; ?>" title="<?php echo htmlspecialchars(t('Back to the assignment')); ?>"><i class="fas fa-arrow-left"></i> <strong><?php echo htmlspecialchars($assignment['title']); ?></strong></a>
    <span class="grade-top-meta"><?php echo htmlspecialchars($class['class_name']); ?> · <?php echo $max; ?> <?php echo t('marks'); ?><?php echo $due ? ' · ' . t('due') . ' ' . formatDate($due, 'M d, h:i A') : ''; ?></span>
    <span class="grade-top-prog"><span class="grade-progress" title="<?php echo $counts['graded']; ?> / <?php echo $counts['all']; ?>"><span style="width:<?php echo $counts['all'] ? round($counts['graded'] / $counts['all'] * 100) : 0; ?>%"></span></span><b><?php echo $counts['graded']; ?>/<?php echo $counts['all']; ?></b> <?php echo t('graded'); ?></span>
</div>

<?php if (!$cur): ?>
<div class="empty-state"><i class="fas fa-user-graduate"></i><p>No students are enrolled in this class yet.</p></div>
<?php else: ?>
<div class="grade-shell" data-assignment="<?php echo (int) $assignment['id']; ?>">

    <!-- ===== Students ===== -->
    <aside class="grade-list" aria-label="<?php echo htmlspecialchars(t('Students')); ?>">
        <div class="grade-tabs" role="tablist">
            <?php foreach ($tabs as $k => $label): ?>
            <a class="grade-tab<?php echo $filter === $k ? ' on' : ''; ?>" href="<?php echo $selfH(null, $k); ?>"><?php echo t($label); ?> <b><?php echo $counts[$k]; ?></b></a>
            <?php endforeach; ?>
        </div>
        <input type="search" id="gradeFind" class="grade-find" placeholder="<?php echo htmlspecialchars(t('Find a student')); ?>" aria-label="<?php echo htmlspecialchars(t('Find a student')); ?>" autocomplete="off">
        <div class="grade-students" id="gradeStudents">
            <?php foreach ($listed as $r): $on = (int) $r['student_id'] === (int) $cur['student_id']; ?>
            <a class="grade-student<?php echo $on ? ' on' : ''; ?>" href="<?php echo $selfH($r['student_id']); ?>" data-name="<?php echo htmlspecialchars(mb_strtolower($r['name'])); ?>"<?php echo $on ? ' aria-current="true"' : ''; ?>>
                <?php echo avatarHtml($r['student_id'], $r['name']); ?>
                <span class="gs-name"><strong><?php echo htmlspecialchars($r['name']); ?></strong><small><span class="gdot <?php echo $state_dot[$r['state']]; ?>"></span><?php echo t($state_labels[$r['state']]); ?></small></span>
                <?php if ($r['state'] === 'graded'): ?><span class="gs-mark"><?php echo formatMarks($r['marks_obtained']); ?><small>/<?php echo $max; ?></small></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
            <?php if (!$listed): ?><p class="form-hint" style="padding:10px 12px;margin:0;"><?php echo t('Nobody here.'); ?></p><?php endif; ?>
        </div>
    </aside>

    <!-- ===== The work ===== -->
    <main class="grade-work" id="gradeWork">
        <div class="grade-work-head">
            <?php echo avatarHtml($cur['student_id'], $cur['name'], '', 'width:48px;height:48px;font-size:1.2rem;'); ?>
            <div class="gw-who"><h2><?php echo htmlspecialchars($cur['name']); ?></h2>
                <div class="gw-sub"><span class="status-badge <?php echo $cur['state'] === 'graded' ? 'graded' : ($cur['state'] === 'missing' ? 'missing' : ($cur['state'] === 'returned' ? 'returned' : ($cur['state'] === 'late' ? 'late' : ($cur['state'] === 'pending' ? 'pending' : 'submitted')))); ?>"><?php echo t($state_labels[$cur['state']]); ?></span>
                <?php if ($cur['submitted_at']): ?><span class="form-hint" style="margin:0;">· <?php echo formatDate($cur['submitted_at'], 'M d, h:i A'); ?><?php echo (int) $cur['revision'] > 1 ? ' · ' . t('version') . ' ' . (int) $cur['revision'] : ''; ?></span><?php endif; ?></div></div>
            <div class="gw-nav">
                <a class="icon-btn<?php echo $prev ? '' : ' is-off'; ?>" <?php echo $prev ? 'href="' . $selfH($prev) . '"' : 'aria-disabled="true"'; ?> title="<?php echo htmlspecialchars(t('Previous student')); ?> (K)" aria-label="<?php echo htmlspecialchars(t('Previous student')); ?>" id="gradePrev"><i class="fas fa-chevron-left"></i></a>
                <span class="gw-count"><?php echo $pos ?: '–'; ?>/<?php echo count($listed); ?></span>
                <a class="icon-btn<?php echo $nxt ? '' : ' is-off'; ?>" <?php echo $nxt ? 'href="' . $selfH($nxt) . '"' : 'aria-disabled="true"'; ?> title="<?php echo htmlspecialchars(t('Next student')); ?> (J)" aria-label="<?php echo htmlspecialchars(t('Next student')); ?>" id="gradeNext"><i class="fas fa-chevron-right"></i></a>
                <a class="icon-btn" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo (int) $class['id']; ?>&amp;student_id=<?php echo (int) $cur['student_id']; ?>" title="<?php echo htmlspecialchars(t('Student progress')); ?>" aria-label="<?php echo htmlspecialchars(t('Student progress')); ?>"><i class="fas fa-chart-line"></i></a>
            </div>
        </div>

        <?php if ($cur['state'] === 'returned'): ?>
        <div class="grade-banner wait"><i class="fas fa-rotate-left"></i> <div><strong><?php echo t('Returned for changes'); ?></strong> <span class="form-hint" style="margin:0;">· <?php echo formatDate($cur['returned_at'], 'M d, h:i A'); ?></span>
            <div class="prewrap"><?php echo htmlspecialchars((string) $cur['return_note']); ?></div><div class="form-hint" style="margin:4px 0 0;"><?php echo t('Waiting for the student to hand it in again.'); ?></div></div></div>
        <?php endif; ?>

        <?php if (!$cur['submission_id']): ?>
        <div class="empty-state grade-empty"><i class="fas fa-inbox"></i><h3><?php echo t($cur['state'] === 'missing' ? 'Nothing was handed in' : 'Not handed in yet'); ?></h3>
            <p><?php echo t($cur['state'] === 'missing' ? 'The due date has passed. You can remind the whole class from the assignment page.' : 'There is nothing to grade yet.'); ?></p></div>
        <?php else: ?>
            <?php if (!empty($cur['content'])): ?>
            <section class="grade-answer"><h3><i class="fas fa-align-left"></i> <?php echo t('Written answer'); ?></h3><div class="prewrap"><?php echo htmlspecialchars($cur['content']); ?></div></section>
            <?php endif; ?>
            <?php if (!empty($cur['file_path'])):
                $dl = APP_URL . '/pages/assignments/download.php?id=' . (int) $cur['submission_id']; $name = $cur['original_name'] ?: 'file'; ?>
            <section class="grade-file"><div class="grade-file-bar"><i class="fas fa-paperclip"></i> <strong><?php echo htmlspecialchars($name); ?></strong>
                <?php if ($kind): ?><a class="btn-app btn-sm btn-outline" href="<?php echo $dl; ?>&amp;view=1" target="_blank" rel="noopener" data-file-view="<?php echo $kind; ?>" data-name="<?php echo htmlspecialchars($name); ?>" data-download="<?php echo $dl; ?>"><i class="fas fa-expand"></i><span class="lbl"> <?php echo t('Full screen'); ?></span></a><?php endif; ?>
                <a class="btn-app btn-sm btn-outline" href="<?php echo $dl; ?>" title="<?php echo htmlspecialchars(t('Download')); ?>"><i class="fas fa-download"></i><span class="lbl"> <?php echo t('Download'); ?></span></a></div>
                <?php if ($kind === 'image'): ?><div class="grade-file-body img"><img src="<?php echo $dl; ?>&amp;view=1" alt="<?php echo htmlspecialchars($name); ?>"></div>
                <?php elseif ($kind): ?><div class="grade-file-body"><iframe src="<?php echo $dl; ?>&amp;view=1" title="<?php echo htmlspecialchars($name); ?>" loading="lazy"></iframe></div>
                <?php else: ?><div class="grade-file-body none"><i class="fas fa-file"></i><p><?php echo t('This type of file cannot be shown here. Download it to open it.'); ?></p></div><?php endif; ?>
            </section>
            <?php endif; ?>
            <?php if (empty($cur['content']) && empty($cur['file_path'])): ?><div class="empty-state grade-empty"><i class="fas fa-inbox"></i><p><?php echo t('The student handed in an empty answer.'); ?></p></div><?php endif; ?>
        <?php endif; ?>

        <section class="grade-comments" id="comments"><h3><i class="fas fa-user-lock"></i> <?php echo t('Private comments'); ?> <small class="form-hint" style="display:inline;margin:0;">· <?php echo t('only you and this student'); ?></small></h3>
            <?php if (!$thread): ?><p class="form-hint" style="margin:0 0 8px;"><?php echo t('No private comments yet.'); ?></p><?php endif; ?>
            <?php foreach ($thread as $c): ?>
            <div class="comment"><?php echo avatarHtml($c['user_id'], $c['author'], $c['role'] === 'teacher' ? 'teacher' : ''); ?><div class="comment-body"><strong><?php echo htmlspecialchars($c['author']); ?></strong> <span class="notif-meta"><?php echo timeAgo($c['created_at']); ?></span>
                <div class="prewrap"><?php echo htmlspecialchars($c['body']); ?></div></div></div>
            <?php endforeach; ?>
            <?php if (array_filter($thread, fn($m) => (int) $m['user_id'] === (int) $cur['student_id'])) echo aiWriteButton('reply', '#commentBody', ['class_id' => (int) $class['id'], 'label' => 'Draft a reply', 'extra' => 'data-student="' . (int) $cur['student_id'] . '" data-ctx="assignment" data-cid="' . (int) $assignment['id'] . '"']); ?>
            <form method="POST" class="comment-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="private_comment"><input type="hidden" name="id" value="<?php echo (int) $assignment['id']; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $cur['student_id']; ?>">
                <div class="form-group" style="flex:1;margin:0;"><textarea name="body" id="commentBody" rows="2" maxlength="2000" placeholder="<?php echo htmlspecialchars(t('Add a private comment…')); ?>" aria-label="<?php echo htmlspecialchars(t('Add a private comment…')); ?>" class="<?php echo !empty($errors['comment']) ? 'is-invalid' : ''; ?>"></textarea>
                    <div class="invalid-feedback <?php echo !empty($errors['comment']) ? 'show' : ''; ?>"><?php echo htmlspecialchars(t($errors['comment'] ?? '')); ?></div></div>
                <button type="submit" class="icon-btn" title="<?php echo htmlspecialchars(t('Send')); ?>" aria-label="<?php echo htmlspecialchars(t('Send')); ?>"><i class="fas fa-paper-plane"></i></button></form>
        </section>
    </main>

    <!-- ===== Marks ===== -->
    <aside class="grade-panel" aria-label="<?php echo htmlspecialchars(t('Grade')); ?>">
        <?php if (!$cur['submission_id']): ?>
        <div class="grade-card"><h3><?php echo t('Grade'); ?></h3><p class="form-hint" style="margin:0;"><?php echo t('Nothing to grade yet.'); ?></p></div>
        <?php else:
            $mv = $form_old['marks'] ?? ($cur['marks_obtained'] !== null ? formatMarks($cur['marks_obtained']) : '');
            $fv = $form_old['feedback'] ?? (string) ($cur['feedback'] ?? ''); ?>
        <div class="grade-wrap">
        <form method="POST" class="grade-form grade-card" id="gradeForm" novalidate data-max="<?php echo $max; ?>"><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_grade"><input type="hidden" name="id" value="<?php echo (int) $assignment['id']; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $cur['student_id']; ?>"><input type="hidden" name="submission_id" value="<?php echo (int) $cur['submission_id']; ?>"><input type="hidden" name="then" value="next" id="gradeThen">
            <h3><?php echo t('Grade'); ?> <?php if ($cur['state'] === 'graded'): ?><span class="status-badge graded"><?php echo t('Graded'); ?></span><?php endif; ?></h3>
            <div class="grade-marks"><input type="number" name="marks" id="gradeMarks" min="0" max="<?php echo $max; ?>" step="0.5" inputmode="decimal" value="<?php echo htmlspecialchars((string) $mv); ?>" placeholder="–" aria-label="<?php echo htmlspecialchars(t('Marks')); ?>" class="<?php echo !empty($errors['marks']) ? 'is-invalid' : ''; ?>"><span>/ <?php echo $max; ?></span></div>
            <div class="invalid-feedback <?php echo !empty($errors['marks']) ? 'show' : ''; ?>"><?php echo htmlspecialchars(t($errors['marks'] ?? '')); ?></div>
            <?php if ($rubric): ?>
            <div class="grade-rubric" id="gradeRubric"><h4><i class="fas fa-clipboard-list"></i> <?php echo t('Rubric'); ?> <small class="form-hint" style="display:inline;margin:0;"><?php echo t('fills the marks'); ?></small></h4>
                <?php foreach ($rubric as $i => $c): ?>
                <label class="rub-row"><span><strong><?php echo htmlspecialchars($c['title']); ?></strong><?php echo $c['description'] !== '' ? '<small>' . htmlspecialchars($c['description']) . '</small>' : ''; ?></span>
                    <span class="rub-in"><input type="number" class="rub-pt" min="0" max="<?php echo (int) $c['points']; ?>" step="0.5" inputmode="decimal" data-max="<?php echo (int) $c['points']; ?>" aria-label="<?php echo htmlspecialchars($c['title']); ?>"><em>/ <?php echo (int) $c['points']; ?></em></span></label>
                <?php endforeach; ?></div>
            <?php endif; ?>
            <label for="gradeFeedback" class="grade-label"><?php echo t('Feedback to the student'); ?></label>
            <?php echo aiWriteButton('feedback', '#gradeFeedback', ['class_id' => (int) $class['id'], 'use' => true, 'label' => 'Improve my wording']); ?>
            <textarea name="feedback" id="gradeFeedback" rows="5" maxlength="2000" placeholder="<?php echo htmlspecialchars(t('What went well, what to improve…')); ?>" class="<?php echo !empty($errors['feedback']) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($fv); ?></textarea>
            <div class="invalid-feedback <?php echo !empty($errors['feedback']) ? 'show' : ''; ?>"><?php echo htmlspecialchars(t($errors['feedback'] ?? '')); ?></div>
            <?php if ($can_ai && (!empty($cur['content']))): ?>
            <button type="button" class="btn-app btn-sm btn-outline ai-check-btn" id="aiCheckBtn" data-sub="<?php echo (int) $cur['submission_id']; ?>" title="<?php echo htmlspecialchars(t('The AI says whether this looks ready to grade or needs changes. You decide.')); ?>"><i class="fas fa-list-check"></i> <?php echo t('Check the work'); ?></button>
            <button type="button" class="btn-app btn-sm btn-outline ai-suggest-btn" data-sub="<?php echo (int) $cur['submission_id']; ?>" data-ungraded="<?php echo $cur['state'] === 'graded' ? '0' : '1'; ?>"><i class="fas fa-robot"></i> <?php echo t('Suggest with AI'); ?></button>
            <div class="ai-check" id="aiCheckOut" hidden aria-live="polite"></div>
            <div class="ai-suggest" hidden aria-live="polite"></div>
            <?php endif; ?>
            <div class="grade-actions">
                <button type="submit" class="btn-app" id="saveNext"><i class="fas fa-check"></i> <?php echo htmlspecialchars(t($nxt ? 'Save & next' : 'Save')); ?></button>
                <button type="submit" class="btn-app btn-outline" id="saveStay" formnovalidate<?php echo $nxt ? '' : ' hidden'; ?>><?php echo t('Save'); ?></button>
            </div>
            <p class="form-hint" style="margin:6px 0 0;"><?php echo t('Shortcuts: Ctrl+Enter saves and goes to the next student · J / K next / previous'); ?></p>
        </form>
        <form method="POST" id="returnForm" class="grade-card return-card" novalidate><?php echo csrfField(); ?>
            <input type="hidden" name="action" value="return_work"><input type="hidden" name="id" value="<?php echo (int) $assignment['id']; ?>"><input type="hidden" name="student_id" value="<?php echo (int) $cur['student_id']; ?>"><input type="hidden" name="submission_id" value="<?php echo (int) $cur['submission_id']; ?>">
            <h4><i class="fas fa-rotate-left"></i> <?php echo t('Return for changes'); ?></h4>
            <p class="form-hint" style="margin:0 0 8px;"><?php echo t('Not good enough yet? Send it back with a note. The student fixes it and hands it in again; any mark is cleared.'); ?></p>
            <?php if ($can_ai && !empty($cur['content'])): ?><div class="ai-draft-row"><button type="button" class="btn-app btn-sm btn-outline" id="aiDraftBtn" data-sub="<?php echo (int) $cur['submission_id']; ?>"><i class="fas fa-wand-magic-sparkles"></i> <?php echo t('Draft the note with AI'); ?></button><span class="form-hint" id="aiDraftHint" style="margin:0;"></span></div><?php endif; ?>
            <textarea name="note" id="returnNote" aria-label="<?php echo htmlspecialchars(t('What should the student change?')); ?>" rows="3" maxlength="2000" placeholder="<?php echo htmlspecialchars(t('What should the student change?')); ?>" class="<?php echo !empty($errors['note']) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form_old['note'] ?? ''); ?></textarea>
            <div class="invalid-feedback <?php echo !empty($errors['note']) ? 'show' : ''; ?>"><?php echo htmlspecialchars(t($errors['note'] ?? '')); ?></div>
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-paper-plane"></i> <?php echo t('Return to the student'); ?></button>
        </form>
        </div>
        <?php endif; ?>
    </aside>
</div>
<?php endif; ?>
<?php if ($can_ai): ?><script>window.__scT = <?php echo json_encode(['ready' => t('Looks ready to grade'), 'changes' => t('May need changes')], JSON_UNESCAPED_UNICODE); ?>;</script><?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
