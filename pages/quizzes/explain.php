<?php
/**
 * "Explain my mistakes" (AI) — POST quiz_id, CSRF via X-CSRF-Token, JSON answer.
 * Only the student who took the quiz, after submitting, and only once the teacher released the
 * results (the answers are not shown before that). AI rules: class switch / own / shared / school key.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = function ($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isStudent()) $fail('Not allowed.', 403);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$quiz = $db->fetchOne("SELECT * FROM quizzes WHERE id = ?", [(int) ($_POST['quiz_id'] ?? 0)]);
$class = $quiz ? getAccessibleClass($quiz['class_id'], $current_user_id, 'student') : null;
if (!$class) $fail('Quiz not found.', 404);
$attempt = $db->fetchOne("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND submitted_at IS NOT NULL AND timed_out = 0", [$quiz['id'], $current_user_id]);
if (!$attempt) $fail('Finish the quiz first.', 403);
if (!resultsReleased($quiz)) $fail('Your teacher has not released the results yet.', 403);

$r = explainQuizMistakes($quiz, $attempt, $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
if (!$r['cached'] && $r['items']) logActivity('ai_explain_mistakes', 'quizzes', "Explained mistakes of quiz #{$quiz['id']} for student");
echo json_encode(['ok' => true, 'items' => (object) $r['items'], 'cached' => $r['cached']]);
