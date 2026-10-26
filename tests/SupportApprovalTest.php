<?php
test('support approval: a request is pending until the administrator decides; it is used once; old or invented tokens do nothing', function () {
    $db = TW('db'); $adm = mkUser('SupA0', 'teacher', 1); $db->query("DELETE FROM app_settings WHERE setting_key LIKE 'support\\_req\\_%'");
    $tok = supportRequestCreate('fix the timetable'); $r = supportRequestGet($tok); eq('pending', $r['status']); eq('fix the timetable', $r['reason']);
    eq(null, supportRequestUse($tok), 'not approved yet — nobody gets in');
    ok(supportRequestDecide($tok, true, $adm)); eq('approved', supportRequestGet($tok)['status']);
    ok(!supportRequestDecide($tok, false, $adm), 'a decision cannot be changed afterwards');
    ok(supportRequestUse($tok) !== null, 'the approved request is used once'); eq(null, supportRequestUse($tok), 'and never twice'); eq('used', supportRequestGet($tok)['status']);
    $t2 = supportRequestCreate('x'); supportRequestDecide($t2, false, $adm); eq(null, supportRequestUse($t2), 'denied = no entry');
    eq(null, supportRequestGet('nothex'), 'invented tokens'); eq(null, supportRequestGet(str_repeat('a', 32)));
    $t3 = supportRequestCreate('late'); $raw = json_decode(getSetting('support_req_' . $t3), true); $raw['exp'] = time() - 5; setSetting('support_req_' . $t3, json_encode($raw));
    eq('expired', supportRequestGet($t3)['status']); ok(!supportRequestDecide($t3, true, $adm), 'an expired request cannot be approved'); eq(null, supportRequestUse($t3));
    $t4 = supportRequestCreate('pending one'); ok(in_array($t4, array_column(supportPending(), 'token'), true), 'listed for the administrator'); 
    $db->query("DELETE FROM app_settings WHERE setting_key LIKE 'support\\_req\\_%'");
});
test('support approval: only the administrator can switch it on and decide, from Settings › Privacy & data', function () {
    $row = fn($k) => (TW('db')->fetchOne('SELECT setting_value v FROM app_settings WHERE setting_key = ?', [$k])['v'] ?? null);   // read the database itself (this process caches settings)
    $a = mkUser('SupAdm', 'teacher', 1); $t = mkUser('SupTch', 'teacher'); $h = client($a); $ht = client($t);
    $ht->get('pages/settings/privacy.php'); ok(!$ht->shown(), 'a teacher cannot open it');
    $h->get('pages/settings/privacy.php'); contains('Support needs my approval', $h->body); $tok = $h->token('pages/settings/privacy.php');
    $h->post('pages/settings/privacy.php', ['csrf_token' => $tok, 'action' => 'support_save', 'support_approval' => '1']); eq('1', $row('support_approval'), 'switched on');
    $q = supportRequestCreate('help'); $h->get('pages/settings/privacy.php'); contains('The provider asks for support access', $h->body); contains($q, $h->body);
    $tt = $ht->token('pages/dashboard.php'); $ht->post('pages/settings/privacy.php', ['csrf_token' => $tt, 'action' => 'support_decide', 'token' => $q, 'decision' => 'approve']); eq('pending', json_decode($row('support_req_' . $q), true)['status'], 'a teacher cannot approve');
    $h->post('pages/settings/privacy.php', ['csrf_token' => $h->token('pages/settings/privacy.php'), 'action' => 'support_decide', 'token' => $q, 'decision' => 'approve']); eq('approved', json_decode($row('support_req_' . $q), true)['status'], 'the administrator can');
    $h->post('pages/settings/privacy.php', ['csrf_token' => $h->token('pages/settings/privacy.php'), 'action' => 'support_save']); eq('0', $row('support_approval'), 'and switch it off again');
    TW('db')->query("DELETE FROM app_settings WHERE setting_key LIKE 'support\\_req\\_%'");
});
