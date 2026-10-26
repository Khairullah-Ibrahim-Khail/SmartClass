<?php
/** "Plan my week" (AI, students) — POST, CSRF via X-CSRF-Token, JSON {ok, text}. Built from the student's own open work. */
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

$r = studentStudyPlan($current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
if (empty($r['empty'])) logActivity('ai_study_plan', 'ai', 'Study plan');
echo json_encode(['ok' => true, 'text' => $r['text']]);
