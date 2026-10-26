<?php
/**
 * Roster CSV export — ?id=<class id> (class teacher only).
 * One row per student: name, email, joined, last activity, overall grade, attendance, missing work.
 * Same numbers as the People tab; opens in Excel / Google Sheets.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$class = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, $current_user_role);
if (!$class || !$class['is_owner']) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}

/** Cells that start with = + - @ are treated as formulas by spreadsheets — neutralise them. */
function csvSafe($value) {
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

$roster = getClassRoster($class['id']);
usort($roster, fn($a, $b) => strcmp($a['name'], $b['name']));

$filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $class['class_name']) . '_roster_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows non-English names correctly
csvRow($out, ['Student', 'Email', 'Joined', 'Last activity', 'Overall %', 'Attendance %', 'Missing work']);
foreach ($roster as $r) {
    csvRow($out, [
        csvSafe($r['name']), csvSafe($r['email']),
        $r['joined_at'] ? date('Y-m-d', strtotime($r['joined_at'])) : '',
        $r['last_active'] ? date('Y-m-d H:i', strtotime($r['last_active'])) : '',
        $r['percent'] === null ? '' : $r['percent'],
        $r['attendance'] === null ? '' : 0 + $r['attendance'],
        $r['missing'],
    ]);
}
fclose($out);
logActivity('export_roster', 'classes', "Exported the roster of {$class['class_name']}");
