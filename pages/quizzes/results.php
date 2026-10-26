<?php
/**
 * Quiz Results — per designs/pages/quize.html
 * One row per student of the selected class: completed quizzes, individual
 * marks, total marks and percentage (total scored / total possible of the
 * quizzes they completed). Teachers see every student in their class;
 * students see only their own row.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$classes = getUserClasses($current_user_id, $current_user_role);

$class = null;
$filter_id = (int) ($_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
if ($filter_id > 0) {
    $class = getAccessibleClass($filter_id, $current_user_id, $current_user_role);
    if (!$class) {
        redirect(APP_URL . '/pages/quizzes/view.php?error=not_found');
    }
}

$rows = [];
$quiz_count = 0;
if ($class) {
    $quiz_count = $db->count('quizzes', 'class_id = ?', [$class['id']]);

    // Students to show: the whole class for the teacher, only yourself for a student
    if (isTeacher()) {
        $students = $db->fetchAll(
            "SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
             WHERE cm.class_id = ? ORDER BY u.name", [$class['id']]);
    } else {
        $students = [['id' => $current_user_id, 'name' => $current_user_name]];
    }
    foreach ($students as $s) {
        $rows[$s['id']] = ['name' => $s['name'], 'quizzes' => []];
    }

    if ($rows) {
        $in = implode(',', array_fill(0, count($rows), '?'));
        foreach ($db->fetchAll(
            "SELECT a.student_id, q.id AS quiz_id, q.title, a.score, a.max_score
             FROM quiz_attempts a
             INNER JOIN quizzes q ON a.quiz_id = q.id
             WHERE q.class_id = ? AND a.submitted_at IS NOT NULL AND a.student_id IN ({$in})
               " . (isTeacher() ? '' : ' AND ' . sqlResultsVisible('q')) . "
             ORDER BY q.created_at, q.id",
            array_merge([$class['id']], array_keys($rows))) as $a) {
            $rows[$a['student_id']]['quizzes'][] = $a;
        }
    }
}

/** 7.50 -> "7.5", 10.00 -> "10". */
function resultMarks($m) {
    return rtrim(rtrim(number_format((float) $m, 2, '.', ''), '0'), '.');
}

$page_title = 'Quiz Results';
$page_subtitle = isTeacher()
    ? 'Complete quiz performance summary for all students'
    : 'Your quiz performance summary';
$active_menu = 'quizzes';
$page_script = "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function() { f.form.submit(); });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Quizzes', 'view.php'); ?>

<div class="page-toolbar">
    <?php if (!empty($classes)): ?>
    <form method="GET" class="filter-form">
        <select id="classFilter" name="class_id" aria-label="Class">
            <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo ($class && (int) $class['id'] === (int) $c['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['class_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-app btn-sm">Show</button></noscript>
    </form>
    <?php else: ?><span></span><?php endif; ?>
    <a href="view.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-question-circle"></i> Quizzes</a>
</div>

<?php if (!$class): ?>
<div class="empty-state">
    <i class="fas fa-chart-bar"></i>
    <h3>No classes yet</h3>
    <p style="margin-top:6px;"><?php echo isTeacher() ? 'Create a class first.' : 'Join a class to see quiz results.'; ?></p>
</div>
<?php elseif (empty($rows)): ?>
<div class="empty-state">
    <i class="fas fa-user-graduate"></i>
    <h3>No students in this class yet</h3>
</div>
<?php else: ?>
<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-chart-bar"></i> <?php echo htmlspecialchars($class['class_name']); ?></h2>
        <span class="form-hint" style="margin:0;"><?php echo (int) $quiz_count; ?> quiz<?php echo $quiz_count == 1 ? '' : 'zes'; ?> in this class</span>
    </div>
    <div class="table-wrap">
        <table class="app-table results-table">
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Completed Quizzes</th>
                    <th>Individual Marks</th>
                    <th>Total Marks</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $scored = array_sum(array_column($r['quizzes'], 'score'));
                    $possible = array_sum(array_column($r['quizzes'], 'max_score'));
                ?>
                <tr>
                    <td class="student-name"><?php echo htmlspecialchars($r['name']); ?></td>
                    <td>
                        <?php if (!$r['quizzes']): ?><span class="form-hint" style="margin:0;">None yet</span><?php endif; ?>
                        <?php foreach ($r['quizzes'] as $q): ?>
                        <a class="quiz-item" href="view.php?id=<?php echo (int) $q['quiz_id']; ?>"><?php echo htmlspecialchars($q['title']); ?></a>
                        <?php endforeach; ?>
                    </td>
                    <td class="marks">
                        <?php echo $r['quizzes'] ? htmlspecialchars(implode(', ', array_map(fn($q) => resultMarks($q['score']) . '/' . (int) $q['max_score'], $r['quizzes']))) : '—'; ?>
                    </td>
                    <td class="total-marks"><?php echo $r['quizzes'] ? resultMarks($scored) . ' / ' . (int) $possible : '—'; ?></td>
                    <td><?php if ($possible > 0): ?><span class="percentage"><?php echo round($scored / $possible * 100, 1); ?>%</span><?php else: ?>—<?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
