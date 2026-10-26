<?php
/** Final marks of a class as an Excel file — ?class_id=<class> (class teacher only): each part, total, percent, GPA, grade. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$class = getAccessibleClass((int) ($_GET['class_id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) redirect(APP_URL . '/pages/marks/view.php');
$fm = classFinalMarks((int) $class['id']);
$comps = markingComponents();
$gpa_on = gradingShowGpa();
$header = ['Student'];
$widths = [28];
foreach ($fm['scheme'] as $k => $w) { $header[] = $comps[$k] . ' (out of ' . (0 + $w) . ')'; $widths[] = 16; }
array_push($header, 'Total (out of ' . (0 + $fm['weight_all']) . ')', 'Percent');
if ($gpa_on) $header[] = 'GPA';
$header[] = 'Grade';
array_push($widths, 18, 10, 8, 12);
$rows = [];
foreach ($fm['rows'] as $r) {
    $line = [$r['name']];
    foreach ($fm['scheme'] as $k => $w) $line[] = $r['parts'][$k]['earned'] === null ? '' : (float) $r['parts'][$k]['earned'];
    $b = gradeBand($r['percent']);
    array_push($line, $r['percent'] === null ? '' : (float) $r['total'], $r['percent'] === null ? '' : (float) $r['percent']);
    if ($gpa_on) $line[] = $b ? (float) gradeGpa($r['percent']) : '';
    $line[] = $b ? $b['label'] : '';
    $rows[] = $line;
}
$scale = [];
foreach (gradingRanges($fm['weight_all']) as $g) $scale[] = [$g['label'], $g['pct'], $g['marks'] ?? '', $g['gpa'] ?? ''];
logActivity('export_marks', 'marks', "Exported the final marks of {$class['class_name']} to Excel");
xlsxDownload($class['class_name'] . '_final_marks_' . date('Y-m-d'), [
    'Final marks' => ['title' => $class['class_name'] . ' — final marks (' . date('M j, Y') . ')', 'header' => $header, 'rows' => $rows, 'widths' => $widths,
                      'notes' => ['Each part = (marks earned ÷ marks possible on its items) × the part\'s worth. A blank part has no marks yet.']],
    'Grading scale' => ['header' => ['Grade', 'Percent', 'Marks', $gpa_on ? 'GPA' : ''], 'rows' => $scale, 'widths' => [14, 22, 20, 10]],
]);
