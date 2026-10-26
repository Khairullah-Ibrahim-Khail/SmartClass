<?php
/**
 * AI rubric draft — POST (teacher of the class), CSRF via X-CSRF-Token, JSON answer.
 *   class_id, title, description, max_marks → {ok, rubric: [{title, description, points}]}
 * Nothing is stored: the form shows the draft, the teacher edits it and saves the assignment.
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
if (!getAccessibleClass((int) ($_POST['class_id'] ?? 0), $current_user_id, 'teacher')) $fail('Choose one of your classes first.', 403);

$r = aiSuggestRubric(trim((string) ($_POST['title'] ?? '')), (string) ($_POST['description'] ?? ''), (int) ($_POST['max_marks'] ?? 0), $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
echo json_encode(['ok' => true, 'rubric' => $r['rubric']]);
