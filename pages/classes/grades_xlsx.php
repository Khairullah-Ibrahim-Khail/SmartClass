<?php
/** Gradebook as an Excel file — ?id=<class> (class teacher only): the Grades tab numbers plus the grading scale. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
$class_id = (int) $class['id'];
$book = getClassGradebook($class_id);
$gpa_on = gradingShowGpa();
$labels = ['submitted' => 'Turned in', 'late' => 'Turned in late', 'missing' => 'Missing', 'pending' => 'Assigned', 'open' => 'In progress', 'timeout' => 'Over time', 'returned' => 'Returned for changes'];

$header = ['Student', 'E-mail'];
$widths = [26, 30];
foreach ($book['columns'] as $c) { $header[] = ($c['type'] === 'quiz' ? 'Quiz: ' : 'Assignment: ') . $c['title'] . ' (out of ' . (0 + $c['max']) . ')'; $widths[] = 18; }
array_push($header, 'Marks earned', 'Out of (marked work)', 'Overall %', 'Final % (all work)', 'Grade');
if ($gpa_on) $header[] = 'GPA';
array_push($widths, 14, 16, 12, 14, 12, 8);
$rows = [];
foreach ($book['rows'] as $r) {
    $line = [$r['name'], $r['email']];
    foreach ($r['cells'] as $cell) $line[] = $cell['score'] !== null ? (float) $cell['score'] : ($labels[$cell['state']] ?? '');
    $b = gradeBand($r['percent']);
    array_push($line, round((float) $r['scored'], 2), (float) $r['possible'], $r['graded_percent'] === null ? '' : (float) $r['graded_percent'], $r['final_percent'] === null ? '' : (float) $r['final_percent'], $b ? $b['label'] : '');
    if ($gpa_on) $line[] = $b ? (float) gradeGpa($r['percent']) : '';
    $rows[] = $line;
}
$scale = [];
foreach (gradingRanges() as $g) $scale[] = [$g['label'], $g['pct'], $g['gpa'] ?? ''];
logActivity('export_grades', 'classes', "Exported the gradebook of {$class['class_name']} to Excel");
xlsxDownload($class['class_name'] . '_gradebook_' . date('Y-m-d'), [
    'Gradebook' => ['title' => $class['class_name'] . ' — gradebook (' . date('M j, Y') . ')', 'header' => $header, 'rows' => $rows, 'widths' => $widths,
                    'notes' => ['Overall % = marks earned ÷ marks of the work marked so far. Final % = marks earned ÷ marks of all work given (not marked / missing = 0). The grade follows the class grading scale.']],
    'Grading scale' => ['header' => ['Grade', 'Range', $gpa_on ? 'GPA' : ''], 'rows' => $scale, 'widths' => [14, 24, 10]],
]);
