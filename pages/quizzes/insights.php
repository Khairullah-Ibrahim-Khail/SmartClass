<?php
/**
 * Class summary of a quiz (AI) — POST quiz_id [refresh=1], CSRF via X-CSRF-Token, JSON answer.
 * Teacher of the class only. The AI sees question text and counts, never student names.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = function ($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isTeacher()) $fail('Not allowed.', 403);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$quiz = $db->fetchOne("SELECT * FROM quizzes WHERE id = ?", [(int) ($_POST['quiz_id'] ?? 0)]);
$class = $quiz ? getAccessibleClass($quiz['class_id'], $current_user_id, 'teacher') : null;
if (!$class) $fail('Quiz not found.', 404);

$r = aiQuizInsights($quiz, $current_user_id, !empty($_POST['refresh']));
if (!$r['ok']) $fail($r['error'], 422);
if (!$r['cached']) logActivity('ai_quiz_insights', 'quizzes', "AI class summary for quiz #{$quiz['id']}");
echo json_encode(['ok' => true, 'insights' => $r['insights'], 'cached' => $r['cached']]);
