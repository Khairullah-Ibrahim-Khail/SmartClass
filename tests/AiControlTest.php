<?php
test('AI budget: alerts the admins once at 80 % and once at 100 %, never twice', function () {
    $db = TW('db'); $adm = mkUser('AiAdm', 'teacher', 1); $u = mkUser('AiU');
    setSetting('ai_limit_school', '1000'); $db->query('DELETE FROM ai_usage');
    $log = fn($tok) => aiLogUsage($u, 'study', ['provider' => 'ollama'], 'm', $tok, 0, 'ok', null, microtime(true), null, null);
    $n = fn() => (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'ai_budget'", [$adm])['c'];
    $log(500); eq(0, $n(), 'at 50 % nobody is told'); $log(350); eq(1, $n(), 'at 85 % the admin is told'); $log(10); eq(1, $n(), 'not again at 86 %');
    $log(200); eq(2, $n(), 'at 100 % told again'); $log(100); eq(2, $n(), 'and only once');
    $b = aiBudgetStatus(); eq(1000, $b['limit']); ok($b['pct'] >= 100);
    [$blocked] = [aiLimitReason($u)]; ok($blocked !== null, 'the school account is stopped at 100 %');
    setSetting('ai_limit_school', '0'); $db->query('DELETE FROM ai_usage');
});
test('AI switch: only the admin can switch AI off for everyone, and it really stops the features', function () {
    $db = TW('db'); $adm = mkUser('AiAdm2', 'teacher', 1); $t = mkUser('AiT', 'teacher'); $s = mkUser('AiS'); $c = mkClass($t, 'AiClass', [$s]);
    $db->query("INSERT INTO ai_providers (owner_id, provider, base_url, model, is_enabled) VALUES (NULL, 'custom', 'http://127.0.0.1:9/v1', 'm', 1)");
    setSetting('ai_enabled', '1'); setSetting('ai_students', '1'); setSetting('ai_default_provider', 'custom'); allSettings(true);
    $h = client($adm); $tok = $h->token('pages/settings/ai.php'); ok($h->shown()); contains('Switch AI off for everyone', $h->body);
    $ht = client($t); $ttok = $ht->token('pages/settings/ai.php'); ok(!$ht->shown(), 'a teacher cannot open the AI settings');
    $ht->post('pages/settings/ai.php', ['csrf_token' => $ttok, 'action' => 'toggle_ai', 'on' => '0']); eq('1', getSetting('ai_enabled', '1'), 'a teacher cannot switch it'); allSettings(true);
    $h->post('pages/settings/ai.php', ['csrf_token' => $tok, 'action' => 'toggle_ai', 'on' => '0']); allSettings(true); eq('0', getSetting('ai_enabled', '1'));
    $h->get('pages/settings/ai.php'); contains('AI is off for the whole school', $h->body);
    ok(!aiAvailableFor('study', $s, $c), 'students have no AI while it is off');
    $db->query("DELETE FROM ai_providers WHERE owner_id IS NULL AND provider = 'custom'");
});
test('reporting a wrong AI answer: stored, checked, limited, shown to the admin only', function () {
    $db = TW('db'); $adm = mkUser('AiAdm3', 'teacher', 1); $t = mkUser('AiT3', 'teacher'); $s = mkUser('AiS3'); $o = mkUser('AiO3'); $c = mkClass($t, 'RepClass', [$s]);
    $hs = client($s); $tok = $hs->token('pages/classes/my-classes.php');
    $hs->post('pages/ai/report.php', ['feature' => 'tutor', 'class_id' => $c, 'answer' => '2 + 2 = 5', 'note' => 'maths is wrong'], [], ['X-CSRF-Token' => $tok]); eq(200, $hs->code); ok(json_decode($hs->body, true)['ok']);
    $r = $db->fetchOne('SELECT * FROM ai_reports WHERE user_id = ?', [$s]); eq('2 + 2 = 5', $r['answer']); eq('open', $r['status']); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND type = 'ai_report'", [$adm])['c']);
    $hs->post('pages/ai/report.php', ['feature' => 'tutor', 'answer' => 'x']); eq(403, $hs->code, 'no token');
    $ho = client($o); $otok = $ho->token('pages/classes/my-classes.php'); $ho->post('pages/ai/report.php', ['feature' => 'tutor', 'class_id' => $c, 'answer' => 'x'], [], ['X-CSRF-Token' => $otok]); eq(403, $ho->code, 'not a member of that class');
    $hs->post('pages/ai/report.php', ['feature' => 'tutor', 'answer' => ''], [], ['X-CSRF-Token' => $tok]); eq(400, $hs->code, 'empty answer');
    for ($i = 0; $i < 12; $i++) $hs->post('pages/ai/report.php', ['feature' => 'other', 'answer' => "a$i"], [], ['X-CSRF-Token' => $tok]); eq(429, $hs->code, 'more than 10 per hour is refused');
    $ha = client($adm); $ha->get('pages/settings/ai.php'); contains('Reported answers', $ha->body); contains('2 + 2 = 5', $ha->body);
    $atok = $ha->token('pages/settings/ai.php'); $ha->post('pages/settings/ai.php', ['csrf_token' => $atok, 'action' => 'review_report', 'report_id' => $r['id']]); eq('reviewed', $db->fetchOne('SELECT status FROM ai_reports WHERE id = ?', [$r['id']])['status']);
});
