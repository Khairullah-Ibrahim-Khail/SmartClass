<?php
function privWorld() {
    static $w = null; if ($w) return $w; $db = TW('db');
    $t = mkUser('PvT', 'teacher'); $s = mkUser('PvS'); $adm = mkUser('PvAdmin', 'teacher', 1); $c = mkClass($t, 'PrivClass', [$s]);
    $db->update('users', ['guardian_name' => 'Parent', 'guardian_email' => 'parent@example.invalid', 'guardian_phone' => '+93123456'], 'id = ?', [$s]);
    $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-02-01', 'status' => 'present']);
    $chat = (int) $db->insert('ai_tutor_chats', ['student_id' => $s, 'class_id' => $c, 'title' => 'q']); $db->insert('ai_tutor_messages', ['chat_id' => $chat, 'role' => 'student', 'body' => 'my question']);
    $db->insert('notifications', ['user_id' => $s, 'type' => 'x', 'title' => 'hello', 'message' => 'm']);
    return $w = compact('t', 's', 'adm', 'c', 'chat');
}
test('export: everything about one person, never passwords or secret keys', function () {
    $w = privWorld(); $x = privacyExport($w['s']); $json = json_encode($x);
    eq($w['s'], (int) $x['account']['id']); ok(!isset($x['account']['password']), 'no password'); notContains('password_hash', $json);
    ok(isset($x['records']['attendance.student_id']), 'attendance'); ok(isset($x['records']['notifications.user_id']), 'notifications'); contains('my question', $json, 'AI tutor messages are included'); contains('parent@example.invalid', $json, 'guardian contact');
    eq(null, privacyExport(99999999));
});
test('people tools are protected: administrators and the last admin cannot be removed', function () {
    $w = privWorld(); [$a, $d, $why] = privacyEligibility($w['adm']); ok(!$a && !$d && $why !== '', 'admin protected'); [$a2, $d2] = privacyEligibility($w['s']); ok($a2 && $d2, 'a student can be anonymised or deleted');
    [$a3, $d3, $why3] = privacyEligibility($w['t']); ok($a3 && !$d3 && $why3 !== '', 'a teacher with a class can be anonymised but not deleted'); eq(false, privacyAnonymise($w['adm']), 'refused');
});
test('anonymise: the person is gone, the school records stay', function () {
    $db = TW('db'); $t = mkUser('PvT2', 'teacher'); $s = mkUser('PvS2'); $c = mkClass($t, 'PrivClass2', [$s]);
    $db->update('users', ['guardian_email' => 'p2@example.invalid', 'totp_enabled' => 1, 'totp_secret' => 'x'], 'id = ?', [$s]);
    $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-02-01', 'status' => 'absent']); $chat = (int) $db->insert('ai_tutor_chats', ['student_id' => $s, 'class_id' => $c, 'title' => 't']);
    $db->insert('notifications', ['user_id' => $s, 'type' => 'x', 'title' => 'n']); $db->insert('login_codes', ['user_id' => $s, 'code_hash' => str_repeat('a', 64), 'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))]);
    ok(privacyAnonymise($s));
    $u = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$s]);
    eq('Former user #' . $s, $u['name']); contains('@removed.invalid', $u['email']); eq(0, (int) $u['is_active']); eq(null, $u['guardian_email']); eq(0, (int) $u['totp_enabled']); eq(null, $u['totp_secret']);
    eq(1, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE student_id = ?', [$s])['c'], 'the attendance record stays');
    foreach (['notifications' => 'user_id', 'login_codes' => 'user_id', 'ai_tutor_chats' => 'student_id'] as $tb => $col) eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM {$tb} WHERE {$col} = ?", [$s])['c'], "$tb removed");
    $h = anon(); $h->get('pages/auth/login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $h->body, $m);
    $h->post('pages/auth/login.php', ['csrf_token' => $m[1], 'email' => 'removed-' . $s . '@removed.invalid', 'password' => TEST_PW]); contains('Invalid email or password', $h->body, 'the closed account cannot sign in');
});
test('delete: a student and what depends on them are removed; a teacher with classes is refused', function () {
    $db = TW('db'); $t = mkUser('PvT3', 'teacher'); $s = mkUser('PvS3'); $c = mkClass($t, 'PrivClass3', [$s]); $db->insert('attendance', ['class_id' => $c, 'student_id' => $s, 'date' => '2026-02-02', 'status' => 'present']);
    ok(privacyDelete($s)); eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM users WHERE id = ?', [$s])['c']); eq(0, (int) $db->fetchOne('SELECT COUNT(*) c FROM attendance WHERE student_id = ?', [$s])['c']);
    eq(false, privacyDelete($t), 'teacher with a class'); eq(1, (int) $db->fetchOne('SELECT COUNT(*) c FROM users WHERE id = ?', [$t])['c']);
});
test('retention: old rows go, recent rows and unlimited rules stay; preview changes nothing', function () {
    $db = TW('db'); $u = mkUser('PvR');
    $db->insert('activity_log', ['user_id' => $u, 'action' => 'old', 'module' => 'x', 'created_at' => date('Y-m-d H:i:s', strtotime('-30 months'))]); $db->insert('activity_log', ['user_id' => $u, 'action' => 'new', 'module' => 'x']);
    $db->insert('notifications', ['user_id' => $u, 'type' => 'x', 'title' => 'read old', 'is_read' => 1, 'created_at' => date('Y-m-d H:i:s', strtotime('-12 months'))]);
    $db->insert('notifications', ['user_id' => $u, 'type' => 'x', 'title' => 'unread old', 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s', strtotime('-12 months'))]);
    $p = retentionRun(true); ok($p['Activity log (who did what)'] >= 1); eq(2, (int) $db->fetchOne("SELECT COUNT(*) c FROM activity_log WHERE user_id = ?", [$u])['c'], 'preview deleted nothing');
    retentionRun(false);
    eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM activity_log WHERE user_id = ?", [$u])['c'], 'old activity removed'); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ?", [$u])['c'], 'only the READ old notification removed');
    setSetting('retention_activity_months', '0'); $db->insert('activity_log', ['user_id' => $u, 'action' => 'ancient', 'module' => 'x', 'created_at' => date('Y-m-d H:i:s', strtotime('-60 months'))]); retentionRun(false);
    eq(2, (int) $db->fetchOne("SELECT COUNT(*) c FROM activity_log WHERE user_id = ?", [$u])['c'], '0 = keep forever'); setSetting('retention_activity_months', '12');
});
test('safeguarding: harm words are caught, ordinary study questions are not', function () {
    foreach (['I want to kill myself', 'sometimes I want to die', 'my uncle touches me where I do not want', 'he beats me every night', 'they bully me at school', 'I will bring a knife to school', 'زه غواړم ځان ووژنم', 'می‌خواهم خودکشی کنم',
        'i am hurting', 'some one hurting me', 'someone hurting me please', "I'm really hurting", 'I feel unsafe at home', 'my uncle hits me', 'he hurts me', 'I hate my life', 'I want to end my life', 'نن زه ځوریږم', 'مرا می‌زنند'] as $t) ok(safeguardScan($t) !== null, "should flag: $t");
    foreach (['How do I kill a process in Linux?', 'Explain the abuse of power in Animal Farm', 'What is the time complexity of bubble sort?', 'Why did the Romans conquer Gaul?', 'solve x^2 = 4', 'The cell wall kills bacteria?',
        'he hit the ball to me', 'It hurts when I touch the stove?', 'what is a hit in baseball', 'Explain how a loop hits the condition', 'They follow me on Instagram', 'Who beat me in the chess game', 'info my teacher'] as $t) eq(null, safeguardScan($t), "should NOT flag: $t");
    eq('distress', safeguardScan('i am hurting')); eq('abuse', safeguardScan('some one hurting me'));
    // the second net: the tutor's own answer sounds like a crisis answer
    ok(safeguardReplyCue('I am so sorry to hear that you are hurting. If you are in immediate danger or experiencing a medical emergency, call emergency services.'), 'a crisis-style answer is noticed');
    ok(safeguardReplyCue('I am very sorry you are being hurt. I am an AI and cannot call the police for you. If you can, tell a trusted adult.'), 'another one');
    ok(!safeguardReplyCue('A for loop repeats a block of code. Here is an example with a counter.'), 'an ordinary answer is not');
});
test('safeguarding: the AI is not asked, the student gets the fixed answer, teachers and admins are told, the flag can be handled', function () {
    $db = TW('db'); $t = mkUser('SgT', 'teacher'); $adm = mkUser('SgAdm', 'teacher', 1); $s = mkUser('SgS'); $c = mkClass($t, 'SgClass', [$s]);
    $hs = client($s); $tok = $hs->token('pages/classes/my-classes.php');
    $hs->post('pages/ai/tutor.php', ['csrf_token' => $tok, 'ajax' => 'ask', 'class_id' => $c, 'text' => 'I want to hurt myself, I see no reason to live']); $r = json_decode($hs->body, true);
    ok(!empty($r['ok']) && !empty($r['safeguarded']), $hs->body); contains('grown-up', $r['reply']); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM ai_usage WHERE user_id = ?", [$s])['c'], 'no AI call was made');
    $flag = $db->fetchOne('SELECT * FROM safeguarding_flags WHERE student_id = ?', [$s]); eq('self_harm', $flag['category']); eq('open', $flag['status']);
    ok((int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'safeguarding'", [$t])['c'] >= 1, 'class teacher told'); ok((int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'safeguarding'", [$adm])['c'] >= 1, 'admin told');
    $ht = client($t); $ht->get('pages/ai/safeguarding.php'); ok($ht->shown()); contains('SgS', $ht->body); contains('hurt myself', $ht->body);
    $other = client(mkUser('SgT2', 'teacher')); $other->get('pages/ai/safeguarding.php'); notContains('SgS', $other->body, 'another teacher must not see it');
    $hs->get('pages/ai/safeguarding.php'); ok(!$hs->shown(), 'students cannot open it');
    $tt = $ht->token('pages/ai/safeguarding.php'); $ht->post('pages/ai/safeguarding.php', ['csrf_token' => $tt, 'flag_id' => $flag['id'], 'note' => 'spoke to her']); eq('handled', $db->fetchOne('SELECT status FROM safeguarding_flags WHERE id = ?', [$flag['id']])['status']);
});
test('safeguarding switch: when off the message goes to the normal path', function () {
    $db = TW('db'); setSetting('ai_safeguarding', '0'); ok(!safeguardingOn()); $t = mkUser('SgT3', 'teacher'); $s = mkUser('SgS3'); $c = mkClass($t, 'SgClass3', [$s]);
    $r = tutorAsk($s, $c, 0, 'I want to die'); ok(empty($r['safeguarded']), 'not intercepted'); setSetting('ai_safeguarding', '1');
});
test('Privacy page: admin only; a person can be found and downloaded; removal needs the typed e-mail', function () {
    $adm = mkUser('PvAdm2', 'teacher', 1); $s = mkUser('PvFound'); $h = client($adm); $tt = mkUser('PvTeach', 'teacher'); $ht = client($tt);
    $ht->get('pages/settings/privacy.php'); ok(!$ht->shown(), 'teachers cannot open it');
    $tok = $h->token('pages/settings/privacy.php'); ok($h->shown()); $h->get('pages/settings/privacy.php?q=PvFound'); contains(userEmail($s), $h->body);
    $h->post('pages/settings/privacy.php', ['csrf_token' => $tok, 'action' => 'export', 'user_id' => $s]); contains('attachment', $h->headers); $j = json_decode($h->body, true); eq($s, (int) $j['account']['id']);
    $h->post('pages/settings/privacy.php', ['csrf_token' => $tok, 'action' => 'anonymise', 'user_id' => $s, 'confirm_email' => 'wrong@example.invalid']); eq(1, (int) TW('db')->fetchOne('SELECT is_active FROM users WHERE id = ?', [$s])['is_active'], 'wrong confirmation: nothing happens');
    $h->post('pages/settings/privacy.php', ['csrf_token' => $tok, 'action' => 'anonymise', 'user_id' => $s, 'confirm_email' => userEmail($s)]); eq(0, (int) TW('db')->fetchOne('SELECT is_active FROM users WHERE id = ?', [$s])['is_active']);
    $h->post('pages/settings/privacy.php', ['csrf_token' => $tok, 'action' => 'anonymise', 'user_id' => $adm, 'confirm_email' => userEmail($adm)]); eq(1, (int) TW('db')->fetchOne('SELECT is_active FROM users WHERE id = ?', [$adm])['is_active'], 'an admin cannot remove themself');
});
test('own data: a student downloads their data; asking for removal tells the admins once a day', function () {
    $db = TW('db'); $adm = mkUser('PvAdm3', 'teacher', 1); $s = mkUser('PvSelf'); $h = client($s); $tok = $h->token('pages/settings/profile.php');
    $h->post('pages/settings/profile.php', ['csrf_token' => $tok, 'action' => 'export_me']); contains('attachment', $h->headers); eq($s, (int) json_decode($h->body, true)['account']['id']); notContains('"password"', $h->body);
    $h->post('pages/settings/profile.php', ['csrf_token' => $tok, 'action' => 'ask_delete']); $n1 = (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'data_request'", [$adm])['c']; eq(1, $n1);
    $h->post('pages/settings/profile.php', ['csrf_token' => $tok, 'action' => 'ask_delete']); eq($n1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'data_request'", [$adm])['c'], 'a second request the same day does not tell them again');
    eq(1, (int) $db->fetchOne('SELECT is_active FROM users WHERE id = ?', [$s])['is_active'], 'nothing is removed automatically');
});

test('safeguarding: "i am hurting" and "some one hurting me" (the words that were once missed) are flagged and the teachers told; the AI is not asked', function () {
    $db = TW('db'); $t = mkUser('SgT2', 'teacher'); $s = mkUser('SgS2'); $c = mkClass($t, 'SgClass2', [$s]);
    foreach (['i am hurting' => 'distress', 'some one hurting me' => 'abuse'] as $msg => $cat) {
        $r = tutorAsk($s, $c, 0, $msg); ok($r['ok'] && !empty($r['safeguarded']), "answered with the fixed kind reply: $msg");
        $f = $db->fetchOne("SELECT category, snippet FROM safeguarding_flags WHERE student_id = ? AND snippet = ?", [$s, $msg]); eq($cat, $f['category'] ?? null, "flag recorded: $msg");
    }
    ok((int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'safeguarding'", [$t])['c'] >= 2, 'the class teacher was told');
});
