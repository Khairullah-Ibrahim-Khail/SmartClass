<?php
/**
 * Approve a proposed action of a dashboard suggestion: the teacher has read and (maybe) edited the message, and this sends it to the student(s) as a notification.
 * POST key, message — teacher only, CSRF, the class must be the teacher's, recipients are members. Logged. The suggestion is hidden afterwards. JSON.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json');
$fail = function ($code, $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
if (!isTeacher() || $_SERVER['REQUEST_METHOD'] !== 'POST') $fail(403, 'Not allowed');
if (!verifyCsrf()) $fail(403, 'Your session expired. Please reload the page.');
$key = (string) ($_POST['key'] ?? ''); $message = trim((string) ($_POST['message'] ?? ''));
if ($message === '') $fail(422, 'Write the message first.');
if (mb_strlen($message) > 500) $fail(422, 'The message must be under 500 characters.');
$to = suggestionRecipients($current_user_id, $key);
if (!$to || !suggestionAction($key)) $fail(404, 'This suggestion is no longer available.');
$cid = (int) explode(':', $key)[1];
foreach ($to as $sid) notify($sid, 'teacher_message', 'A message from your teacher', $message, 'pages/classes/stream.php?id=' . $cid, $current_user_id, $cid);
logActivity('suggestion_message', 'classes', 'Sent a suggested message (' . explode(':', $key)[0] . ') to ' . count($to) . ' student(s)', $current_user_id);
suggestionDismiss($current_user_id, $key);
echo json_encode(['ok' => true, 'sent' => count($to)]);
