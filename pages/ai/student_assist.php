<?php
/**
 * A student's writing help — POST task (check | hint), class_id, text [, assignment_id], CSRF via X-CSRF-Token, JSON answer {ok, text}.
 * Only for a student of that class, only where the class teacher switched "AI writing help for students" on, and only these two helpers: neither writes the answer.
 * The task for "hint" is read here from the assignment of the same class — not taken from the browser.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = function ($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isStudent()) $fail('Not allowed.', 403);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);

$task = (string) ($_POST['task'] ?? '');
if (!in_array($task, ['check', 'hint'], true)) $fail('Unknown helper.', 422);
$class_id = (int) ($_POST['class_id'] ?? 0);
if (!$db->count('class_members', 'class_id = ? AND student_id = ?', [$class_id, (int) $current_user_id])) $fail('Not allowed.', 403);
[$sources, $reason] = aiSources('student_writing', $current_user_id, $class_id);
if (!$sources) $fail($reason ?: 'AI help is switched off for this class.', 403);

$in = ['text' => (string) ($_POST['text'] ?? ''), 'context' => ''];
if ($task === 'hint') {
    $a = $db->fetchOne("SELECT title, description, rubric, max_marks FROM assignments WHERE id = ? AND class_id = ?", [(int) ($_POST['assignment_id'] ?? 0), $class_id]);
    if ($a) {
        $in['context'] = "Assignment: " . $a['title'] . "\nInstructions:\n" . mb_substr((string) $a['description'], 0, 3000);
        foreach (assignmentRubric($a) as $c) $in['context'] .= "\n- " . $c['title'] . ($c['description'] !== '' ? ': ' . $c['description'] : '');
    }
}
$r = aiStudentHelp($task, $in, $current_user_id, $class_id);
if (!$r['ok']) $fail($r['error'], 422);
logActivity('ai_student_writing', 'ai', "Student writing help ({$task}) in class #{$class_id}");
echo json_encode(['ok' => true, 'text' => $r['text']]);
