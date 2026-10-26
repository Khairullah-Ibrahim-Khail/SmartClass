<?php
/**
 * Lecture study helper (AI) — POST lecture_id, mode (summary|terms|ask), question (ask only), CSRF via
 * X-CSRF-Token. JSON answer. Students enrolled in the class, for published lectures only.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$live = !empty($_POST['stream']) && ($_POST['mode'] ?? '') !== 'practice';   // text answers can be sent piece by piece while they are written
if (!$live) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }

$fail = function ($msg, $code = 400) use (&$live) {
    if ($live && !empty($GLOBALS['sc_ai_stream'])) { aiSseSend(['error' => $msg]); exit; }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isStudent()) $fail('Not allowed.', 403);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$lecture = $db->fetchOne("SELECT * FROM lectures WHERE id = ?", [(int) ($_POST['lecture_id'] ?? 0)]);
$class = $lecture ? getAccessibleClass($lecture['class_id'], $current_user_id, 'student') : null;
if (!$class || lectureState($lecture) !== 'published') $fail('Lecture not found.', 404);

if (($_POST['mode'] ?? '') === 'practice') {
    $r = lecturePracticeQuiz($lecture, $current_user_id);
    if (!$r['ok']) $fail($r['error'], 422);
    logActivity('ai_study_helper', 'lectures', "Practice quiz on lecture #{$lecture['id']}");
    echo json_encode(['ok' => true, 'questions' => $r['questions']]);
    exit;
}
if ($live) aiSseOpen();
$r = lectureStudyHelp($lecture, (string) ($_POST['mode'] ?? ''), (string) ($_POST['question'] ?? ''), $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
if (!$r['cached']) logActivity('ai_study_helper', 'lectures', "Study helper ({$_POST['mode']}) on lecture #{$lecture['id']}");
if ($live) { aiSseSend(['done' => true, 'text' => $r['text'], 'cached' => $r['cached']]); exit; }
echo json_encode(['ok' => true, 'text' => $r['text'], 'cached' => $r['cached']]);
