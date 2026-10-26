<?php
/**
 * Teacher writing helpers (AI) — POST task, CSRF via X-CSRF-Token, JSON answer {ok, text}.
 *   task = announcement | assignment | lecture | feedback | translate | reply | exam | event | resource | forum | class | quiz | research | improve
 *   class_id (required, a class the teacher teaches), text, title, summary, lang,
 *   reply: student_id + c + cid (the private-message thread to answer; read on the server)
 * The AI only drafts text for a form — nothing is saved or sent from here.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$live = !empty($_POST['stream']);   // the answer is sent piece by piece (server-sent events) so the text appears while it is written
if (!$live) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }

$fail = function ($msg, $code = 400) use (&$live) {
    if ($live && !empty($GLOBALS['sc_ai_stream'])) { aiSseSend(['error' => $msg]); exit; }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isTeacher()) $fail('Not allowed.', 403);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$task = (string) ($_POST['task'] ?? '');
$class_id = (int) ($_POST['class_id'] ?? 0);
$class = $class_id ? getAccessibleClass($class_id, $current_user_id, 'teacher') : null;
// Forms that are not tied to one class yet (new assignment / lecture) may leave it out
if ($class_id && !$class) $fail('Choose one of your classes.', 403);

$in = ['text' => (string) ($_POST['text'] ?? ''), 'title' => (string) ($_POST['title'] ?? ''), 'summary' => (string) ($_POST['summary'] ?? ''),
       'lang' => (string) ($_POST['lang'] ?? ''), 'class_name' => $class['class_name'] ?? ''];

if ($class) $in['context'] = aiClassContext($class['id']);   // topic-aware drafts: the class's own topics and lectures, read on the server
if (isset(aiWriteKinds()[$task])) {
    $in['facts'] = []; foreach (array_keys(aiWriteKinds()[$task]['facts']) as $k) $in['facts'][$k] = mb_substr(trim((string) ($_POST['ctx'][$k] ?? '')), 0, 160);
}
if ($task === 'exam') {
    $in['exam'] = []; foreach (['kind', 'date', 'duration', 'venue', 'marks'] as $k) $in['exam'][$k] = mb_substr(trim((string) ($_POST['ctx'][$k] ?? '')), 0, 120);
    $in['context'] = aiClassContext($class ? $class['id'] : 0);   // read here, from the class — not from the browser
}

if ($task === 'reply') {
    $student_id = (int) ($_POST['student_id'] ?? 0);
    $type = (string) ($_POST['c'] ?? '');
    if (!$class || !$db->count('class_members', 'class_id = ? AND student_id = ?', [$class['id'], $student_id])) $fail('Student not found in this class.', 404);
    $ctx = messageContext($class['id'], $type, (int) ($_POST['cid'] ?? 0));
    if (!$ctx) $fail('Conversation not found.', 404);
    $msgs = getThreadMessages($class['id'], $student_id, $ctx[0], $ctx[1]);
    $in['conversation'] = array_map(fn($m) => ['from' => (int) $m['user_id'] === $student_id ? 'student' : 'teacher', 'body' => $m['body']], $msgs);
}

if ($live) aiSseOpen();   // from here the person's session is released and the page answers with an event stream
$r = aiAssist($task, $in, $current_user_id);
if (!$r['ok']) $fail($r['error'], 422);
logActivity('ai_writing', 'ai', "AI writing helper ({$task})" . ($class ? " in {$class['class_name']}" : ''));
if ($live) { aiSseSend(['done' => true, 'text' => $r['text']]); exit; }
echo json_encode(['ok' => true, 'text' => $r['text']]);
