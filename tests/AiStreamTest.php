<?php
/** Live (streamed) AI answers against a fake provider: pieces arrive in order, errors before any text are clean, the web endpoints answer as event streams. */
/** Put the shared test database back to "no AI, no runner" so other suites (the tutor's "no AI key" message …) see what they expect. */
function aiMockReset() {
    TW('db')->query("DELETE FROM ai_providers WHERE owner_id IS NULL");
    foreach (['ai_enabled' => '0', 'ai_students' => '0', 'ai_default_provider' => '', 'code_runner_url' => '', 'code_runner_on' => '1'] as $k => $v) setSetting($k, $v);
    aiProviderRows(true);
}
function aiMockStart() {
    static $port = null;
    if ($port === null) {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock_ai_server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
        register_shutdown_function(function () use ($proc) { @proc_terminate($proc); });
    }
    $db = TW('db');   // every call (re)configures the school's AI to use the fake provider
    $db->query("DELETE FROM ai_providers WHERE owner_id IS NULL");
    $db->insert('ai_providers', ['owner_id' => null, 'provider' => 'custom', 'base_url' => "http://127.0.0.1:$port/v1", 'model' => 'mock', 'is_enabled' => 1]);
    foreach (['ai_enabled' => '1', 'ai_students' => '1', 'ai_default_provider' => 'custom'] as $k => $v) setSetting($k, $v);
    aiProviderRows(true);
    return ['port' => $port];
}

test('live AI: the text arrives in pieces, in order, with usage logged; a refused key is a clean error before any text', function () {
    aiMockStart(); $t = mkUser('LsT', 'teacher'); $pieces = [];
    $r = aiChat('writing', 'sys', 'say hello', ['user_id' => $t, 'stream' => function ($p) use (&$pieces) { $pieces[] = $p; }]);
    ok($r['ok'], $r['error'] ?? ''); ok(count($pieces) >= 4, 'several pieces, not one block: ' . count($pieces));
    eq($r['text'], implode('', $pieces), 'the pieces add up to the answer'); contains('**world**', $r['text']); eq(11, $r['input_tokens']); eq(7, $r['output_tokens']);
    $row = TW('db')->fetchOne("SELECT status, input_tokens, output_tokens FROM ai_usage WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$t]); eq('ok', $row['status']);
    $none = []; $e = aiChat('writing', 'sys', 'FAIL401 please', ['user_id' => $t, 'stream' => function ($p) use (&$none) { $none[] = $p; }]);
    ok(!$e['ok'] && $none === [], 'no text was sent'); contains('API key was rejected', $e['error']);
    $plain = aiChat('writing', 'sys', 'say hello', ['user_id' => $t]); ok($plain['ok']); eq($r['text'], $plain['text'], 'without streaming the same answer comes back whole');
    aiMockReset();
});

test('live AI: the writing helper answers as an event stream (pieces, then done) and as plain JSON when not asked to stream; sign-in and CSRF still apply', function () {
    aiMockStart(); $t = mkUser('LsT2', 'teacher'); $c = mkClass($t, 'LsClass'); $h = client($t); $tok = $h->token('pages/lectures/add.php');
    $post = ['task' => 'announcement', 'class_id' => $c, 'text' => 'Quiz on Monday'];
    $h->post('pages/ai/assist.php', $post + ['stream' => '1'], [], ['X-CSRF-Token' => $tok]);
    contains('text/event-stream', $h->headers); preg_match_all('/^data: (\{.*\})$/m', $h->body, $m); $ev = array_map(fn($x) => json_decode($x, true), $m[1]);
    $texts = array_values(array_filter(array_column($ev, 't'), fn($x) => $x !== null)); ok(count($texts) >= 4, 'pieces arrived'); $last = end($ev); ok(!empty($last['done']), 'ends with done'); contains('Hello', $last['text']);
    $h->post('pages/ai/assist.php', $post, [], ['X-CSRF-Token' => $tok]); contains('application/json', $h->headers); $j = json_decode($h->body, true); ok($j['ok']); contains('Hello', $j['text']);
    $h->post('pages/ai/assist.php', $post + ['stream' => '1'], [], ['X-CSRF-Token' => 'wrong']); notContains('text/event-stream', $h->headers); eq(403, $h->code, 'a wrong token is refused before anything streams');
    $a = anon(); $a->post('pages/ai/assist.php', $post + ['stream' => '1']); ok($a->code !== 200 || strpos($a->body, 'Hello') === false, 'a visitor gets nothing');
    $st = mkUser('LsS'); $hs = client($st); $hs->post('pages/ai/assist.php', $post + ['stream' => '1'], [], ['X-CSRF-Token' => $hs->token()]); eq(403, $hs->code, 'a student cannot use the teacher helper');
    aiMockReset();
});

test('live AI: the AI Tutor and the study helper stream too; the saved conversation holds the finished answer', function () {
    aiMockStart(); $t = mkUser('LsT3', 'teacher'); $s = mkUser('LsS3'); $c = mkClass($t, 'LsClass3', [$s]); $h = client($s); $tok = $h->token('pages/dashboard.php');
    $h->post('pages/ai/tutor.php', ['ajax' => 'ask', 'class_id' => $c, 'chat_id' => 0, 'lecture_id' => 0, 'text' => 'Explain gravity', 'csrf_token' => $tok, 'stream' => '1']);
    contains('text/event-stream', $h->headers); preg_match_all('/^data: (\{.*\})$/m', $h->body, $m); $ev = array_map(fn($x) => json_decode($x, true), $m[1]); $last = end($ev);
    ok(!empty($last['done']) && !empty($last['chat_id']), 'done with the conversation id'); contains('Hello', $last['reply']);
    eq($last['reply'], TW('db')->fetchOne("SELECT body FROM ai_tutor_messages WHERE chat_id = ? AND role = 'tutor'", [$last['chat_id']])['body'], 'what is saved is what was shown');
    $h->post('pages/ai/tutor.php', ['ajax' => 'ask', 'class_id' => $c, 'chat_id' => 0, 'text' => 'I want to hurt myself', 'csrf_token' => $tok, 'stream' => '1']);
    preg_match_all('/^data: (\{.*\})$/m', $h->body, $m2); $e2 = array_map(fn($x) => json_decode($x, true), $m2[1]); $l2 = end($e2); ok(!empty($l2['safeguarded']), 'a harm message still never reaches the AI (fixed kind answer)'); notContains('Hello', $h->body);
    $lec = (int) TW('db')->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'Gravity', 'status' => 'published', 'content' => "## Gravity\nThings fall."]);
    $h->post('pages/lectures/study.php', ['lecture_id' => $lec, 'mode' => 'summary', 'stream' => '1'], [], ['X-CSRF-Token' => $tok]);
    preg_match_all('/^data: (\{.*\})$/m', $h->body, $m3); $e3 = array_map(fn($x) => json_decode($x, true), $m3[1]); $l3 = end($e3); ok(!empty($l3['done']), 'summary streams'); contains('Hello', $l3['text']);
    aiMockReset();
});
