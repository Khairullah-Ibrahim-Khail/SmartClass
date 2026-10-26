<?php
/** Attendance as an Excel file — ?id=<class>&from=&to= (class teacher only): summary per student and the day-by-day register. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
$class_id = (int) $class['id'];
[$from, $to] = attendancePeriod($class_id, $_GET['from'] ?? '', $_GET['to'] ?? '');
$statuses = attendanceStatuses();
$students = $db->fetchAll("SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id WHERE cm.class_id = ? ORDER BY u.name", [$class_id]);
$summary = $from ? getAttendanceSummary($class_id, null, $from, $to) : [];
$days = []; $marks = []; $day_labels = [];
if ($from) {
    $slots = attendanceSlots($class_id, $from, $to);   // one column per (date, session)
    $per_date = [];
    foreach ($slots as $sl) $per_date[$sl['date']] = ($per_date[$sl['date']] ?? 0) + 1;
    foreach ($slots as $sl) { $days[] = $sl['date'] . '#' . $sl['session']; $day_labels[] = date('M j', strtotime($sl['date'])) . ($per_date[$sl['date']] > 1 ? ' · s' . $sl['session'] : ''); }
    foreach ($db->fetchAll("SELECT student_id, date, session, status FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ?", [$class_id, $from, $to]) as $m) $marks[$m['student_id']][$m['date'] . '#' . $m['session']] = $m['status'];
}
$sum_rows = [];
foreach ($students as $st) {
    $s = $summary[$st['id']] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0, 'excused' => 0, 'late_minutes' => 0, 'total' => 0, 'rate' => null];
    $sum_rows[] = [$st['name'], $s['present'], $s['late'], $s['late_minutes'] ?: '', $s['absent'], $s['excused'], $s['official'], $s['total'], $s['rate'] === null ? '' : (float) $s['rate']];
}
$reg_head = array_merge(['Student'], $day_labels);
$reg_rows = [];
foreach ($students as $st) { $line = [$st['name']]; foreach ($days as $d) $line[] = isset($marks[$st['id']][$d]) ? $statuses[$marks[$st['id']][$d]][0] : ''; $reg_rows[] = $line; }
$period = $from ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)) : 'no attendance yet';
logActivity('export_attendance', 'attendance', "Exported attendance of {$class['class_name']} to Excel ({$period})");
xlsxDownload($class['class_name'] . '_attendance_' . date('Y-m-d'), [
    'Summary' => ['title' => $class['class_name'] . ' — attendance, ' . $period, 'header' => ['Student', 'Present', 'Late', 'Late minutes', 'Absent', 'of which excused', 'Official present', 'Days recorded', 'Attendance %'],
                  'rows' => $sum_rows, 'widths' => [28, 10, 8, 12, 8, 16, 16, 14, 14], 'notes' => ['Attendance % = (present + late + official present) ÷ days recorded.']],
    'Register' => ['title' => 'Day by day', 'header' => $reg_head, 'rows' => $reg_rows, 'widths' => array_merge([28], array_fill(0, count($days), 11))],
]);
