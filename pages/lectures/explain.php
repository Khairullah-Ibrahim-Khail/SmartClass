<?php
/**
 * "Explain this code" (AI) — POST lecture_id + hash, CSRF (X-CSRF-Token header). JSON answer.
 * Only code blocks that are really in the lecture are explained (explainLectureCode); the
 * viewer must be a teacher of the class or an enrolled student who can see the lecture, and
 * AI must be available to them (class switch / own / shared / school key — aiSources).
 * Explanations are cached per code block and reused for the whole class.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$live = !empty($_POST['stream']);
if (!$live) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }

$fail = function ($msg, $code = 400) use (&$live) {
    if ($live && !empty($GLOBALS['sc_ai_stream'])) { aiSseSend(['error' => $msg]); exit; }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Use POST.', 405);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$lecture = $db->fetchOne("SELECT * FROM lectures WHERE id = ?", [(int) ($_POST['lecture_id'] ?? 0)]);
$class = $lecture ? getAccessibleClass($lecture['class_id'], $current_user_id, $current_user_role) : null;
if (!$class || (!$class['is_owner'] && lectureState($lecture) !== 'published')) $fail('Lecture not found.', 404);

if ($live) aiSseOpen();
$r = explainLectureCode($lecture, (string) ($_POST['hash'] ?? ''), $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
if ($live) { aiSseSend(['done' => true, 'html' => $r['html'], 'cached' => $r['cached']]); exit; }
echo json_encode(['ok' => true, 'html' => $r['html'], 'cached' => $r['cached']]);
