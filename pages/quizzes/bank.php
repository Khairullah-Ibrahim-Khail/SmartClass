<?php
/**
 * Question bank as JSON for the quiz builder (teachers only; each teacher sees only their own questions).
 *   GET  ?q=text&tag=name        → {ok, questions:[…], tags:{name:count}}
 *   POST action=delete&id=N      → {ok}   (X-CSRF-Token header)
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fail = function ($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
if (!isTeacher()) $fail('Not allowed.', 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);
    if (($_POST['action'] ?? '') !== 'delete') $fail('Unknown action.');
    if (!qbDelete($current_user_id, (int) ($_POST['id'] ?? 0))) $fail('Not found.', 404);
    logActivity('question_bank_delete', 'quizzes', 'Removed a question from the question bank');
    echo json_encode(['ok' => true]); exit;
}
echo json_encode(['ok' => true, 'questions' => qbList($current_user_id, trim((string) ($_GET['q'] ?? '')), trim((string) ($_GET['tag'] ?? ''))), 'tags' => (object) qbTags($current_user_id)]);
