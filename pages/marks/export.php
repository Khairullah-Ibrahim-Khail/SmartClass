<?php
/** CSV of one exam's marks (teachers of the class): ?exam_id=N. Cells starting with = + - @ are prefixed so a spreadsheet never runs them as formulas. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/marks/view.php');
$exam = $db->fetchOne("SELECT e.*, c.class_name FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'), [(int) ($_GET['exam_id'] ?? 0), $current_user_id]);
if (!$exam) redirect(APP_URL . '/pages/marks/view.php');
$rows = $db->fetchAll("SELECT u.name, u.email, m.marks, m.absent, m.remarks FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
                       LEFT JOIN exam_marks m ON m.exam_id = ? AND m.student_id = u.id WHERE cm.class_id = ? ORDER BY u.name", [$exam['id'], $exam['class_id']]);
$safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="marks-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $exam['title']) . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
csvRow($out, ['Student', 'Email', 'Marks (out of ' . (int) $exam['max_marks'] . ')', 'Percentage', 'Absent', 'Remarks']);
foreach ($rows as $r) {
    $pct = $r['marks'] !== null && !$r['absent'] ? round($r['marks'] / $exam['max_marks'] * 100, 1) : '';
    csvRow($out, [$safe($r['name']), $safe($r['email']), $r['absent'] ? '' : ($r['marks'] ?? ''), $pct, $r['absent'] ? 'yes' : '', $safe($r['remarks'] ?? '')]);
}
logActivity('export_marks', 'exams', "Exported marks of exam #{$exam['id']}");
