<?php
/**
 * AI quiz generator — POST (teacher of the class), CSRF via X-CSRF-Token, JSON answer.
 *   class_id, lectures[] (ids of lectures of that class), topic (free text), count (1–20), difficulty
 * Returns {ok, questions:[{text, options, correct}], dropped} for the quiz builder to show and edit.
 * Nothing is stored here: the teacher reviews the draft and publishes the quiz as usual.
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
$class = getAccessibleClass((int) ($_POST['class_id'] ?? 0), $current_user_id, 'teacher');
if (!$class) $fail('Choose one of your classes first.', 403);

$r = generateQuizQuestions($class, (array) ($_POST['lectures'] ?? []), (string) ($_POST['topic'] ?? ''),
    (int) ($_POST['count'] ?? 5), (string) ($_POST['difficulty'] ?? 'medium'), $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
logActivity('ai_quiz_draft', 'quizzes', 'Generated ' . count($r['questions']) . " AI quiz questions for {$class['class_name']} ({$r['provider']})");
echo json_encode(['ok' => true, 'questions' => $r['questions'], 'dropped' => $r['dropped']]);
