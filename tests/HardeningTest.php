<?php
test('https redirect: only when switched on, only on a real domain, never on localhost / IPs / .local', function () {
    eq(null, httpsRedirectTarget('school.example.com', '/a?b=1', false, false), 'off');
    eq('https://school.example.com/a?b=1', httpsRedirectTarget('school.example.com', '/a?b=1', false, true));
    eq(null, httpsRedirectTarget('school.example.com', '/a', true, true), 'already https');
    foreach (['localhost', 'localhost:8080', '127.0.0.1', '192.168.1.5:81', 'school.smartclass.local', 'x.localhost'] as $h) eq(null, httpsRedirectTarget($h, '/', false, true), $h);
});
test('administrators without two-step are sent to set it up once the school requires it', function () {
    $a = mkUser('HdA', 'teacher', 1); $t = mkUser('HdT', 'teacher'); $h = client($a);
    $h->get('pages/dashboard.php'); ok($h->shown(), 'not required yet');
    setSetting('require_admin_2fa', '1');
    $h->get('pages/dashboard.php'); eq(302, $h->code); contains('settings/two_factor.php', $h->redirect);
    $h->get('pages/settings/two_factor.php'); ok($h->shown(), 'the setup page itself stays open'); $h->get('pages/settings/school.php'); eq(302, $h->code, 'other admin pages stay closed');
    $ht = client($t); $ht->get('pages/dashboard.php'); ok($ht->shown(), 'plain teachers are not affected');
    TW('db')->query('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$a]); $h->get('pages/dashboard.php'); ok($h->shown(), 'with two-step on they pass');
    setSetting('require_admin_2fa', '0');
});
test('Security rules page: admin only; saving validates and stores', function () {
    $a = mkUser('HdB', 'teacher', 1); $t = mkUser('HdC', 'teacher'); $h = client($a); $ht = client($t);
    $ht->get('pages/settings/security_rules.php'); ok(!$ht->shown(), 'teacher refused');
    $tok = $h->token('pages/settings/security_rules.php'); ok($h->shown()); contains('Force HTTPS', $h->body);
    $h->post('pages/settings/security_rules.php', ['csrf_token' => $tok, 'password_min_length' => '5']); contains('from 8 to 20', $h->body); eq('10', (string) passwordMinLength());
    $h->post('pages/settings/security_rules.php', ['csrf_token' => $tok, 'force_https' => '1', 'password_min_length' => '12']); contains('open this page over https', strtolower($h->body)); eq('0', getSetting('force_https', '0'), 'cannot switch https on from plain http');
    $h->post('pages/settings/security_rules.php', ['csrf_token' => $tok, 'password_min_length' => '12']); eq(302, $h->code); setSetting('x', '1'); allSettings(true); eq(12, passwordMinLength());
    setSetting('password_min_length', '10');
});
test('bin/security_switch.php works on the test database only when told so, and rejects bad input', function () {
    $env = 'SC_TEST_DB=' . TW('dbname') . ' ';
    exec($env . 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/security_switch.php') . ' require_admin_2fa on 2>&1', $o1, $c1); eq(0, $c1); contains(TW('dbname'), implode(' ', $o1));
    exec($env . 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/security_switch.php') . ' require_admin_2fa maybe 2>&1', $o2, $c2); eq(1, $c2);
    exec($env . 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/security_switch.php') . ' not_a_setting on 2>&1', $o3, $c3); eq(1, $c3);
    exec($env . 'php ' . escapeshellarg(dirname(__DIR__) . '/bin/security_switch.php') . ' require_admin_2fa off 2>&1', $o4, $c4); eq(0, $c4);
    allSettings(true); eq('0', getSetting('require_admin_2fa', '0'));
});
