<?php
function tutorWorld() { static $w = null; if ($w) return $w; $t = mkUser('TuT', 'teacher'); $s = mkUser('TuS'); $s2 = mkUser('TuS2'); $c = mkClass($t, 'TutorClass', [$s]); return $w = compact('t', 's', 's2', 'c'); }
test('AI tutor: a teacher is sent away; an outsider student and a missing token are refused', function () {
    $w = tutorWorld();
    $ht = client($w['t']); $ht->get('pages/ai/tutor.php'); ok(!$ht->shown(), 'teachers have no tutor page');
    $o = client($w['s2']); $tok = $o->token('pages/classes/my-classes.php');
    $o->post('pages/ai/tutor.php', ['csrf_token' => $tok, 'ajax' => 'ask', 'class_id' => $w['c'], 'text' => 'hi']); eq(403, $o->code, 'outsider');
    $m = client($w['s']); $m->post('pages/ai/tutor.php', ['ajax' => 'ask', 'class_id' => $w['c'], 'text' => 'hi']); eq(403, $m->code, 'no token');
});
test('AI tutor: without an AI key the student gets a clear message and nothing is saved', function () {
    $w = tutorWorld(); $m = client($w['s']); $tok = $m->token('pages/classes/my-classes.php');
    $m->post('pages/ai/tutor.php', ['csrf_token' => $tok, 'ajax' => 'ask', 'class_id' => $w['c'], 'text' => 'Explain loops']); $r = json_decode($m->body, true);
    ok(isset($r['ok']) && $r['ok'] === false, 'refused'); ok(!empty($r['error']), 'with a reason');
    eq(0, (int) TW('db')->fetchOne('SELECT COUNT(*) c FROM ai_tutor_chats WHERE student_id = ?', [$w['s']])['c']);
});
test('AI tutor: a student can only read and delete their own conversations', function () {
    $w = tutorWorld(); $db = TW('db');
    $chat = (int) $db->insert('ai_tutor_chats', ['student_id' => $w['s'], 'class_id' => $w['c'], 'title' => 'mine']); $db->insert('ai_tutor_messages', ['chat_id' => $chat, 'role' => 'student', 'body' => 'private question']);
    $o = client($w['s2']); $tok = $o->token('pages/classes/my-classes.php');
    $o->get("pages/ai/tutor.php?ajax=chat&id=$chat"); eq(404, $o->code); notContains('private question', $o->body);
    $o->post('pages/ai/tutor.php', ['csrf_token' => $tok, 'ajax' => 'delete', 'class_id' => $w['c'], 'chat_id' => $chat]); ok(in_array($o->code, [403, 404], true));
    eq(1, (int) $db->fetchOne('SELECT COUNT(*) c FROM ai_tutor_chats WHERE id = ?', [$chat])['c'], 'still there');
    $m = client($w['s']); $m->get("pages/ai/tutor.php?ajax=chat&id=$chat"); eq(200, $m->code); contains('private question', $m->body);
});
test('study helper and code explainer refuse people outside the class', function () {
    $w = tutorWorld(); $db = TW('db'); $l = (int) $db->insert('lectures', ['class_id' => $w['c'], 'teacher_id' => $w['t'], 'title' => 'TL', 'content' => "```python\nprint(1)\n```", 'status' => 'published']);
    $o = client($w['s2']); $tok = $o->token('pages/classes/my-classes.php');
    $o->post('pages/lectures/study.php', ['lecture_id' => $l, 'mode' => 'summary'], [], ['X-CSRF-Token' => $tok]); eq(404, $o->code);
    $o->post('pages/lectures/explain.php', ['lecture_id' => $l, 'hash' => str_repeat('a', 64)], [], ['X-CSRF-Token' => $tok]); eq(404, $o->code);
});
