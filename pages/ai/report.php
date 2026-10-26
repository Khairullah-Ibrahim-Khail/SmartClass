<?php
/**
 * "This AI answer is wrong" — POST feature, answer, note?, class_id?  (CSRF header X-CSRF-Token or csrf_token) → JSON {ok}
 * Anyone signed in can report an answer they were shown; the report goes to the school administrators' list (Settings › AI providers).
 * class_id (when given) must be a class the person belongs to. 10 reports per person per hour.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$fail = function ($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Use POST.', 405);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);
$feature = in_array($_POST['feature'] ?? '', ['tutor', 'study', 'explain_code', 'other'], true) ? $_POST['feature'] : 'other';
$answer = mb_substr(trim((string) ($_POST['answer'] ?? '')), 0, 600);
$note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
if ($answer === '') $fail('There is no answer to report.');
$class_id = (int) ($_POST['class_id'] ?? 0);
if ($class_id && !getAccessibleClass($class_id, $current_user_id, $current_user_role)) $fail('Not one of your classes.', 403);
$g = rateGuard('ai_report', (string) $current_user_id, 10, 3600);
if (!$g['ok']) $fail('Too many reports for now. Please wait ' . rateWaitText($g['wait']) . '.', 429);
$db->insert('ai_reports', ['user_id' => $current_user_id, 'class_id' => $class_id ?: null, 'feature' => $feature, 'answer' => $answer, 'note' => $note !== '' ? $note : null]);
foreach ($db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1") as $a) {
    if ((int) $a['id'] !== (int) $current_user_id && !$db->fetchOne("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'ai_report' AND is_read = 0", [(int) $a['id']]))
        notify((int) $a['id'], 'ai_report', 'An AI answer was reported as wrong', 'Settings › AI providers → Reported answers.', 'pages/settings/ai.php#reports');
}
echo json_encode(['ok' => true]);
