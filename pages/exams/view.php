<?php
/**
 * Exams — scheduled exams of the user's classes (?class_id=N, ?past=1).
 * Students: when and where, instructions, and their mark once the teacher published the results.
 * Teachers: edit, enter marks (Marks module), delete (POST + CSRF).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can delete an exam.'];
    } else {
        $ex = $db->fetchOne("SELECT e.id, e.title FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'), [(int) ($_POST['exam_id'] ?? 0), $current_user_id]);
        if (!$ex) {
            $task_result = ['error', 'Not found', 'That exam is not in one of your classes.'];
        } else {
            $db->delete('exams', 'id = ?', [$ex['id']]);
            logActivity('delete_exam', 'exams', "Deleted exam #{$ex['id']} \"{$ex['title']}\" and its marks");
            Session::flash('exam_msg', ['success', 'Exam deleted', '"' . $ex['title'] . '" and its marks were removed.']);
            redirect(APP_URL . '/pages/exams/view.php');
        }
    }
}
$task_result = Session::flash('exam_msg') ?? $task_result;

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$past = !empty($_GET['past']);
$use_ids = $filter ? [$filter] : $class_ids;

$exams = [];
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    $exams = $db->fetchAll(
        "SELECT e.*, c.class_name,
                (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = e.id AND (m.marks IS NOT NULL OR m.absent = 1)) AS entered,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = e.class_id) AS students,
                my.marks AS my_marks, my.absent AS my_absent, my.remarks AS my_remarks
         FROM exams e INNER JOIN classes c ON e.class_id = c.id
         LEFT JOIN exam_marks my ON my.exam_id = e.id AND my.student_id = ?
         WHERE e.class_id IN ({$in}) AND " . ($past ? "e.exam_date < NOW()" : "e.exam_date >= NOW()") . "
         ORDER BY e.exam_date " . ($past ? 'DESC' : 'ASC') . " LIMIT 100", array_merge([$current_user_id], $use_ids));
}

$page_title = 'Exams';
$page_subtitle = isTeacher() ? 'Schedule exams and enter the marks' : 'Your exam timetable and results';
$active_menu = 'exams';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function () { f.form.submit(); });";
$page_scripts = isTeacher() ? ['assets/js/class.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form">
        <?php if ($classes): ?>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($past): ?><input type="hidden" name="past" value="1"><?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
    </form>
    <div class="action-buttons">
        <a class="btn-app btn-outline" href="view.php?<?php echo http_build_query(array_filter(['class_id' => $filter, 'past' => $past ? null : 1])); ?>"><i class="fas fa-history"></i> <?php echo $past ? 'Upcoming exams' : 'Past exams'; ?></a>
        <?php if (isTeacher() && $classes): ?><a href="create.php<?php echo $filter ? '?class_id=' . $filter : ''; ?>" class="btn-app"><i class="fas fa-plus"></i> New Exam</a><?php endif; ?>
    </div>
</div>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-file-alt"></i><h3>No classes yet</h3></div>
<?php elseif (!$exams): ?>
<div class="empty-state"><i class="fas fa-file-alt"></i><h3><?php echo $past ? 'No past exams' : 'No upcoming exams'; ?></h3></div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($exams as $e): $s = strtotime($e['exam_date']); $published = !empty($e['results_published_at']); ?>
    <div class="lecture-card event-card">
        <div class="event-date" aria-hidden="true"><span><?php echo date('M', $s); ?></span><strong><?php echo date('j', $s); ?></strong></div>
        <div class="lecture-info">
            <div class="lecture-title"><?php echo htmlspecialchars($e['title']); ?>
                <?php if (in_array($e['kind'] ?? 'other', ['mid', 'final', 'activity'], true)): ?><span class="status-badge submitted" title="Counts in the final marks"><i class="fas fa-award"></i> <?php echo htmlspecialchars(examKinds()[$e['kind']]); ?></span><?php endif; ?></div>
            <div class="lecture-meta">
                <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($e['class_name']); ?></span>
                <span><i class="fas fa-clock"></i> <?php echo date('D, M j · h:i A', $s); ?><?php echo $e['duration_min'] ? ' · ' . (int) $e['duration_min'] . ' min' : ''; ?></span>
                <?php if ($e['venue']): ?><span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($e['venue']); ?></span><?php endif; ?>
                <span><i class="fas fa-star"></i> <?php echo (int) $e['max_marks']; ?> marks</span>
                <?php if (isTeacher()): ?>
                <span><i class="fas fa-check"></i> <?php echo (int) $e['entered']; ?>/<?php echo (int) $e['students']; ?> marked</span>
                <span class="status-badge <?php echo $published ? 'graded' : 'pending'; ?>"><?php echo $published ? 'Results published' : 'Results hidden'; ?></span>
                <?php endif; ?>
            </div>
            <?php if ($e['instructions']): ?><div class="prewrap" style="margin-top:6px;"><?php echo linkify($e['instructions']); ?></div><?php endif; ?>
            <?php if (isStudent() && $published): ?>
            <div style="margin-top:8px;">
                <?php if ($e['my_absent']): ?><span class="status-badge missing">Absent</span>
                <?php elseif ($e['my_marks'] !== null): ?><span class="status-badge graded">Your mark: <?php echo rtrim(rtrim(number_format((float) $e['my_marks'], 2, '.', ''), '0'), '.'); ?> / <?php echo (int) $e['max_marks']; ?> (<?php echo round($e['my_marks'] / $e['max_marks'] * 100, 1); ?>%)</span>
                <?php else: ?><span class="status-badge pending">No mark recorded</span><?php endif; ?>
                <?php if ($e['my_remarks']): ?><span class="form-hint" style="display:inline;margin:0 0 0 8px;"><?php echo htmlspecialchars($e['my_remarks']); ?></span><?php endif; ?>
            </div>
            <?php elseif (isStudent() && $s < time()): ?><div style="margin-top:8px;"><span class="status-badge pending">Results not published yet</span></div><?php endif; ?>
        </div>
        <?php if (isTeacher()): ?>
        <div class="lecture-actions">
            <a class="btn-app btn-sm" href="<?php echo APP_URL; ?>/pages/marks/add.php?exam_id=<?php echo (int) $e['id']; ?>"><i class="fas fa-pen"></i> Marks</a>
            <a class="icon-btn" href="create.php?id=<?php echo (int) $e['id']; ?>" title="Edit exam" aria-label="Edit exam"><i class="fas fa-edit"></i></a>
            <form method="POST" data-confirm="Delete this exam?" data-confirm-text="Its marks are deleted too." data-confirm-button="Delete">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="exam_id" value="<?php echo (int) $e['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Delete exam" aria-label="Delete exam <?php echo htmlspecialchars($e['title']); ?>"><i class="fas fa-trash"></i></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
