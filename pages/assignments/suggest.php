<?php
/**
 * AI grade suggestion — POST submission_id [refresh=1] (teacher of the assignment's class),
 * CSRF via X-CSRF-Token, JSON answer {ok, marks, feedback, breakdown, flagged, flag_reason, cached}.
 * The AI only suggests: the grade form is filled in and the teacher still presses Grade.
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

$submission = $db->fetchOne("SELECT s.* FROM submissions s WHERE s.id = ?", [(int) ($_POST['submission_id'] ?? 0)]);
$assignment = $submission ? $db->fetchOne("SELECT * FROM assignments WHERE id = ?", [$submission['assignment_id']]) : null;
$class = $assignment ? getAccessibleClass($assignment['class_id'], $current_user_id, 'teacher') : null;
if (!$class) $fail('Submission not found.', 404);

$r = aiSuggestGrade($assignment, $submission, $current_user_id, !empty($_POST['refresh']));
if (!$r['ok']) $fail($r['error'], 422);
if (empty($r['cached'])) logActivity('ai_grade_suggestion', 'assignments', "AI grade suggestion for submission #{$submission['id']} of assignment #{$assignment['id']}");
echo json_encode($r);
