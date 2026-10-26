<?php
/**
 * Gradebook CSV export — ?id=<class id> (class teacher only).
 * Same numbers as the Grades tab; opens in Excel / Google Sheets.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}

$book = getClassGradebook($class['id']);

/** Cells that start with = + - @ are treated as formulas by spreadsheets — neutralise them. */
function csvSafe($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$labels = ['submitted' => 'Turned in', 'late' => 'Turned in late', 'missing' => 'Missing',
           'pending' => 'Assigned', 'open' => 'In progress', 'timeout' => 'Over time', 'returned' => 'Returned for changes'];

$filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $class['class_name']) . '_grades_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows non-English names correctly

$head = ['Student', 'Email', 'Overall %'];
foreach ($book['columns'] as $c) {
    $head[] = csvSafe(($c['type'] === 'quiz' ? 'Quiz: ' : 'Assignment: ') . $c['title'] . ' (/' . $c['max'] . ')');
}
csvRow($out, $head);

foreach ($book['rows'] as $r) {
    $line = [csvSafe($r['name']), csvSafe($r['email']), $r['percent'] === null ? '' : $r['percent']];
    foreach ($r['cells'] as $cell) {
        $line[] = $cell['score'] !== null ? (0 + $cell['score']) : ($labels[$cell['state']] ?? '');
    }
    csvRow($out, $line);
}
fclose($out);

logActivity('export_grades', 'classes', "Exported gradebook of {$class['class_name']}");
exit;
