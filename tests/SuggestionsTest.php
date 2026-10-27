<?php
/** Teacher dashboard suggestions: each rule fires on the right data, is private to the teacher, can be dismissed, and a proposed message is only sent when the teacher approves it. */
require_once __DIR__ . '/AiStreamTest.php';   // aiMockStart(): a fake AI provider
function sgFixture() {
    static $f = null; if ($f) return $f; $db = TW('db');
    $t = mkUser('SgTeacher', 'teacher'); $o = mkUser('SgOther', 'teacher'); $a = mkUser('SgAbsent'); $m = mkUser('SgMissing'); $i = mkUser('SgIdle'); $g = mkUser('SgGood'); $out = mkUser('SgOutsider');
    $c = mkClass($t, 'SgClass', [$a, $m, $i, $g]); $oc = mkClass($o, 'SgOtherClass', [$out]);
    $db->query("UPDATE users SET last_login = NOW() WHERE id IN (?, ?, ?, ?)", [$a, $m, $g, $out]); $db->query("UPDATE users SET last_login = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE id = ?", [$i]);
    for ($d = 1; $d <= 8; $d++) { foreach ([$a => $d <= 2 ? 'present' : 'absent', $g => 'present'] as $sid => $st) $db->insert('attendance', ['class_id' => $c, 'student_id' => $sid, 'date' => date('Y-m-d', strtotime("-$d days")), 'session' => 1, 'status' => $st]); }
    foreach ([1, 2, 3] as $n) { $as = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => "Past $n", 'description' => 'x', 'due_date' => date('Y-m-d H:i:s', strtotime('-' . (3 + $n) . ' days')), 'max_marks' => 10]);
        foreach ([$a, $i, $g] as $sid) $db->insert('submissions', ['assignment_id' => $as, 'student_id' => $sid, 'content' => 'x', 'status' => 'graded', 'marks_obtained' => 5]); }   // $m hands in nothing
    $old = (int) $db->insert('assignments', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Waiting', 'description' => 'x', 'due_date' => date('Y-m-d H:i:s', strtotime('-20 days')), 'max_marks' => 10]);
    foreach ([$a, $m, $i] as $sid) $db->insert('submissions', ['assignment_id' => $old, 'student_id' => $sid, 'content' => 'x', 'status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s', strtotime('-12 days'))]);
    $qz = (int) $db->insert('quizzes', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Hard quiz', 'due_date' => date('Y-m-d H:i:s', strtotime('+5 days'))]);
    foreach ([$a, $m, $i] as $sid) $db->insert('quiz_attempts', ['quiz_id' => $qz, 'student_id' => $sid, 'started_at' => date('Y-m-d H:i:s'), 'submitted_at' => date('Y-m-d H:i:s'), 'score' => 1, 'max_score' => 10, 'timed_out' => 0]);
    return $f = compact('t', 'o', 'a', 'm', 'i', 'g', 'out', 'c', 'oc', 'old', 'qz');
}
function sgKeys($uid) { return array_column(suggestionsFor($uid, 50), 'key'); }

test('suggestions: every rule fires on the right data and nothing fires for a good student', function () {
    $f = sgFixture(); $k = sgKeys($f['t']);
    ok(in_array("att:{$f['c']}:{$f['a']}", $k, true), 'low attendance'); ok(!in_array("att:{$f['c']}:{$f['g']}", $k, true), 'a student who is always present');
    ok(in_array("miss:{$f['c']}:{$f['m']}", $k, true), 'missing work'); ok(!in_array("miss:{$f['c']}:{$f['g']}", $k, true));
    ok(in_array("grade:{$f['old']}", $k, true), 'grading backlog'); ok(in_array("quiz:{$f['qz']}", $k, true), 'hard quiz'); ok(in_array("idle:{$f['c']}", $k, true), 'inactive students');
    ok(in_array("noatt:{$f['c']}", $k, true) === false, 'attendance was taken yesterday: no reminder');
    $s = suggestionsFor($f['t'], 50); eq('high', $s[0]['level'], 'most urgent first');
});

test('suggestions: private to the teacher of the class; the dashboard shows the card to teachers only; dismissing hides one for 14 days', function () {
    $f = sgFixture(); eq([], sgKeys($f['o']), 'another teacher sees nothing of this class');
    $h = client($f['t']); $h->get('pages/dashboard.php'); contains('id="suggestions"', $h->body); contains('SgMissing', $h->body);
    $s = client($f['a']); $s->get('pages/dashboard.php'); notContains('id="suggestions"', $s->body, 'students never see suggestions');
    $o = client($f['o']); $o->get('pages/dashboard.php'); notContains('SgMissing', $o->body);
    $tok = $h->token(); $key = "idle:{$f['c']}";
    $h->post('pages/suggestions/dismiss.php', ['csrf_token' => $tok, 'key' => $key]); eq(200, $h->code); ok(!in_array($key, sgKeys($f['t']), true), 'dismissed');
    $h->post('pages/suggestions/dismiss.php', ['key' => $key]); eq(403, $h->code, 'no CSRF token');
    $st = client($f['a']); $st->post('pages/suggestions/dismiss.php', ['csrf_token' => $st->token(), 'key' => $key]); eq(403, $st->code, 'students cannot');
    TW('db')->query("DELETE FROM suggestion_dismissals");
});

test('suggestions: a proposed message is sent only on approval, only to members of the teacher\'s own class, and is logged', function () {
    $f = sgFixture(); $db = TW('db'); $h = client($f['t']); $tok = $h->token(); $key = "miss:{$f['c']}:{$f['m']}";
    $count = fn($u) => (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'teacher_message'", [$u])['c'];
    eq(0, $count($f['m']), 'nothing sent just by having the suggestion');
    $h->post('pages/suggestions/act.php', ['key' => $key, 'message' => 'hi']); eq(403, $h->code, 'CSRF token required'); eq(0, $count($f['m']));
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => $key, 'message' => '   ']); eq(422, $h->code, 'empty message');
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => $key, 'message' => str_repeat('x', 501)]); eq(422, $h->code, 'too long');
    $o = client($f['o']); $o->post('pages/suggestions/act.php', ['csrf_token' => $o->token(), 'key' => $key, 'message' => 'Not yours']); eq(404, $o->code, 'another teacher cannot use it'); eq(0, $count($f['m']));
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => "miss:{$f['c']}:{$f['out']}", 'message' => 'x']); eq(404, $h->code, 'a student who is not in the class'); eq(0, $count($f['out']));
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => "grade:{$f['old']}", 'message' => 'x']); eq(404, $h->code, 'a suggestion without an action');
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => $key, 'message' => 'Please hand in your work.']); eq(200, $h->code); eq(1, $count($f['m']), 'sent after approval');
    ok(!in_array($key, sgKeys($f['t']), true), 'the suggestion is hidden after sending');
    ok((bool) $db->fetchOne("SELECT id FROM activity_log WHERE action = 'suggestion_message'"), 'logged');
    $h->post('pages/suggestions/act.php', ['csrf_token' => $tok, 'key' => "idle:{$f['c']}", 'message' => 'Come back']); eq(200, $h->code); eq(1, $count($f['i']), 'the idle student is notified'); eq(0, $count($f['g']), 'an active student is not');
});

test('suggestions: the "improve with AI" button of the message appears only when the school has AI switched on, and the AI is only asked to polish the teacher\'s own text', function () {
    $f = sgFixture(); aiMockStart(); $h = client($f['t']); $h->get('pages/dashboard.php');
    contains('data-ai-task="improve"', $h->body, 'AI on: the teacher can polish the message');
    $r = aiWPostSg($h, 'Please hand in your work soon.'); ok($r['ok'] ?? false, $h->body); ok(trim((string) ($r['text'] ?? '')) !== '', 'a polished text comes back');
    setSetting('ai_enabled', '0'); allSettings(true); $h2 = client($f['t']); $h2->get('pages/dashboard.php'); notContains('data-ai-task="improve"', $h2->body, 'AI off: no AI button, the rest still works'); contains('Approve &amp; send', $h2->body);
    aiMockReset(); allSettings(true);
});
function aiWPostSg($h, $text) { $h->post('pages/ai/assist.php', ['task' => 'improve', 'class_id' => 0, 'text' => $text], [], ['X-CSRF-Token' => $h->token('pages/dashboard.php')]); return json_decode($h->body, true); }
