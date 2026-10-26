<?php
/**
 * Attendance CSV export — ?id=<class id> (class teacher only).
 * One row per student, one column per marked day (P / L / A / O), then totals and rate.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}

[$from, $to] = attendancePeriod($class['id'], $_GET['from'] ?? '', $_GET['to'] ?? '');
$from = $from ?? '0000-00-00';
$to = $to ?? '9999-12-31';
$slots = attendanceSlots($class['id'], $from, $to);   // one column per (date, session)
$per_date = [];
foreach ($slots as $sl) $per_date[$sl['date']] = ($per_date[$sl['date']] ?? 0) + 1;
$days = array_map(fn($sl) => $sl['date'] . '#' . $sl['session'], $slots);
$day_labels = array_map(fn($sl) => $sl['date'] . ($per_date[$sl['date']] > 1 ? ' (session ' . $sl['session'] . ')' : ''), $slots);
$marks = [];
foreach ($db->fetchAll("SELECT student_id, date, session, status FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ?", [$class['id'], $from, $to]) as $m) {
    $marks[$m['student_id']][$m['date'] . '#' . $m['session']] = $m['status'];
}
$students = $db->fetchAll(
    "SELECT u.id, u.name, u.email FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
     WHERE cm.class_id = ? ORDER BY u.name", [$class['id']]);
$summary = getAttendanceSummary($class['id'], null, $from, $to);

/** Cells that start with = + - @ are treated as formulas by spreadsheets — neutralise them. */
function attCsvSafe($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$letters = ['present' => 'P', 'late' => 'L', 'absent' => 'A', 'official' => 'O'];
$filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $class['class_name']) . '_attendance_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
csvRow($out, array_merge(['Student', 'Email'], $day_labels, ['Present', 'Late', 'Absent', 'Official', 'Attendance %']));
foreach ($students as $s) {
    $line = [attCsvSafe($s['name']), attCsvSafe($s['email'])];
    foreach ($days as $d) {
        $line[] = $letters[$marks[$s['id']][$d] ?? ''] ?? '';
    }
    $sum = $summary[(int) $s['id']] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0, 'rate' => null];
    array_push($line, $sum['present'], $sum['late'], $sum['absent'], $sum['official'], $sum['rate'] ?? '');
    csvRow($out, $line);
}
csvRow($out, []);
csvRow($out, ['Key: P = Present, L = Late, A = Absent, O = Official Present']);
fclose($out);

logActivity('export_attendance', 'attendance', "Exported attendance of {$class['class_name']}");
exit;
