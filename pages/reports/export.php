<?php
/** CSV of a class report (teachers of the class): ?class_id=N. Cells that start with = + - @ are prefixed so spreadsheets never run them as formulas. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/reports/index.php');
$class = getAccessibleClass((int) ($_GET['class_id'] ?? 0), $current_user_id, 'teacher');
if (!$class) redirect(APP_URL . '/pages/reports/index.php');
$rep = classReport($class['id']);
$safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="report-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $class['class_name']) . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
csvRow($out, ['Student', 'Email', 'Attendance %', 'Assignments handed in', 'of', 'Quizzes finished', 'of', 'Average grade %', 'Flags']);
foreach ($rep['students'] as $s) {
    csvRow($out, [$safe($s['name']), $safe($s['email']), $s['attendance'] ?? '', $s['submitted'], $rep['assignments'], $s['quiz_done'], $rep['quizzes'], $s['average'] ?? '', implode('; ', $s['risk'])]);
}
logActivity('export_report', 'reports', "Exported the report of {$class['class_name']}");
