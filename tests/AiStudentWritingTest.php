<?php
require_once __DIR__ . '/AiStreamTest.php';   // aiMockStart(): a fake AI provider
/** The student's AI writing help: off until the class teacher switches it on; only a language check and hints (never the answer); never in quizzes; the existing student AI switch is untouched. */
function saiSetup() {
    aiMockStart(); $t = mkUser('SaiT', 'teacher'); $s = mkUser('SaiS'); $o = mkUser('SaiO'); $c = mkClass($t, 'SaiClass', [$s]);
    $a = (int) TW('db')->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'River essay', 'description' => 'Explain how a river floods.', 'due_date' => date('Y-m-d H:i:s', strtotime('+3 days')), 'max_marks' => 20]);
    return [$t, $s, $o, $c, $a];
}
function saiPost($h, $task, $class, array $more = []) {
    $h->post('pages/ai/student_assist.php', ['task' => $task, 'class_id' => $class] + $more, [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]);
    return json_decode($h->body, true);
}

test('student writing help: off by default; the teacher switches it on in the class settings and can switch it off again', function () {
    [$t, $s, $o, $c, $a] = saiSetup(); $hs = client($s); $db = TW('db');
    eq(0, (int) $db->fetchOne("SELECT ai_student_writing v FROM classes WHERE id = ?", [$c])['v'], 'off by default');
    $hs->get("pages/assignments/submit.php?id=$a"); notContains('data-sai="check"', $hs->body, 'no buttons while it is off');
    $r = saiPost($hs, 'check', $c, ['text' => 'my anser']); ok(!$r['ok'] && $hs->code === 403, 'and the endpoint refuses');
    $h = client($t); $h->get("pages/classes/edit.php?id=$c"); contains('AI writing help for students', $h->body); contains('name="ai_student_writing"', $h->body);
    $save = fn($on) => $h->post("pages/classes/edit.php?id=$c", ['csrf_token' => $h->token("pages/classes/edit.php?id=$c"), 'action' => 'save', 'class_name' => 'SaiClass', 'description' => 'd', 'ai_students' => '1', 'ai_student_writing' => $on ? '1' : '0', 'ai_language' => 'screen', 'theme' => 'auto', 'stream_permission' => 'comment']);
    $save(true); eq(1, (int) $db->fetchOne("SELECT ai_student_writing v FROM classes WHERE id = ?", [$c])['v'], 'switched on'); eq(1, (int) $db->fetchOne("SELECT ai_students v FROM classes WHERE id = ?", [$c])['v'], 'the old switch is untouched');
    $hs->get("pages/assignments/submit.php?id=$a"); contains('data-sai="check"', $hs->body); contains('data-sai="hint"', $hs->body); contains('never writes your answer', $hs->body); eq(1, substr_count($hs->body, 'assets/js/ai-student.js'));
    $save(false); $hs->get("pages/assignments/submit.php?id=$a"); notContains('data-sai="check"', $hs->body, 'hidden again by the teacher');
    aiMockReset();
});

test('student writing help: only a language check and hints, only for a student of the class, CSRF-guarded, logged; a forum reply gets the language check only; quizzes never', function () {
    [$t, $s, $o, $c, $a] = saiSetup(); $db = TW('db'); $db->query("UPDATE classes SET ai_student_writing = 1 WHERE id = ?", [$c]); $hs = client($s);
    $r = saiPost($hs, 'check', $c, ['text' => 'rivers floods when it rain a lot']); ok($r['ok'] ?? false, $hs->body); ok($r['text'] !== '');
    $r = saiPost($hs, 'check', $c, ['text' => '']); ok(!$r['ok'] && $hs->code === 422, 'nothing to check');
    $r = saiPost($hs, 'hint', $c, ['text' => 'my draft', 'assignment_id' => $a]); ok($r['ok'] ?? false, $hs->body);
    $r = saiPost($hs, 'write', $c, ['text' => 'write my essay']); ok(!$r['ok'] && $hs->code === 422, 'there is no "write it for me" helper');
    $r = saiPost($hs, 'rewrite', $c, ['text' => 'x']); ok(!$r['ok'] && $hs->code === 422);
    $hs->post('pages/ai/student_assist.php', ['task' => 'check', 'class_id' => $c, 'text' => 'x']); eq(403, $hs->code, 'no CSRF token');
    $ho = client($o); $r = saiPost($ho, 'check', $c, ['text' => 'x']); eq(403, $ho->code, 'a student outside the class');
    $ht = client($t); $r = saiPost($ht, 'check', $c, ['text' => 'x']); eq(403, $ht->code, 'a teacher uses the teacher helpers instead');
    ok((int) $db->fetchOne("SELECT COUNT(*) c FROM ai_usage WHERE user_id = ? AND feature = 'student_writing' AND class_id = ?", [$s, $c])['c'] >= 2, 'every use is counted for that student and class');
    ok((int) $db->fetchOne("SELECT COUNT(*) c FROM activity_log WHERE user_id = ? AND action = 'ai_student_writing'", [$s])['c'] >= 2, 'and written to the activity log');
    $th = (int) $db->insert('forum_threads', ['class_id' => $c, 'user_id' => $t, 'title' => 'Floods', 'body' => 'Why?']);
    $hs->get("pages/forums/thread.php?id=$th"); contains('data-sai="check"', $hs->body); notContains('data-sai="hint"', $hs->body, 'a forum reply has no hints');
    notContains('aiStudentButtons', file_get_contents(dirname(__DIR__) . '/pages/quizzes/take.php'), 'never on a quiz answer'); notContains('aiStudentButtons', file_get_contents(dirname(__DIR__) . '/pages/exams/view.php'));
    aiMockReset();
});

test('the existing student AI keeps its own switch: the writing help does not turn it on or off, and it does not need it', function () {
    [$t, $s, $o, $c, $a] = saiSetup(); $db = TW('db');
    $db->query("UPDATE classes SET ai_students = 1, ai_student_writing = 0 WHERE id = ?", [$c]); ok(aiAvailableFor('study', $s, $c), 'the tutor / study helpers work as before'); ok(!aiAvailableFor('student_writing', $s, $c));
    $db->query("UPDATE classes SET ai_students = 0, ai_student_writing = 1 WHERE id = ?", [$c]); ok(!aiAvailableFor('study', $s, $c), 'the old switch still turns the old helpers off'); ok(aiAvailableFor('student_writing', $s, $c), 'the writing help has its own switch');
    $db->query("UPDATE classes SET ai_students = 1, ai_student_writing = 1 WHERE id = ?", [$c]); ok(aiAvailableFor('study', $s, $c) && aiAvailableFor('student_writing', $s, $c));
    aiMockReset();
});

test('quiz editor: the teacher gets "write / improve the question" and "write the model answer" on every question', function () {
    [$t, $s, $o, $c, $a] = saiSetup(); $h = client($t);
    $h->get('pages/quizzes/create.php'); contains('data-ai-task="question"', $h->body); contains('data-ai-task="model"', $h->body); contains('data-scope=".question-block"', $h->body);
    $db = TW('db'); $db->insert('class_topics', ['class_id' => $c, 'name' => 'Rivers and lakes', 'position' => 1]);
    $post = fn($task, array $more) => ($h->post('pages/ai/assist.php', ['task' => $task, 'class_id' => $c] + $more, [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]) ?: null) ?: json_decode($h->body, true);
    $h->post('pages/ai/assist.php', ['task' => 'question', 'class_id' => $c, 'title' => 'Rivers quiz', 'ctx[type]' => 'short'], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); $j = json_decode($h->body, true); ok($j['ok'], $h->body); contains('CTXSEEN', $j['text'], 'the question is written for the class\'s topics');
    $h->post('pages/ai/assist.php', ['task' => 'model', 'class_id' => $c, 'ctx[question]' => 'Why do rivers flood?'], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); $j = json_decode($h->body, true); ok($j['ok'], $h->body); contains('FACTSEEN', $j['text'], 'the model answer is written for that question');
    $h->post('pages/ai/assist.php', ['task' => 'model', 'class_id' => $c], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); eq(422, $h->code, 'no question, no model answer');
    aiMockReset();
});
