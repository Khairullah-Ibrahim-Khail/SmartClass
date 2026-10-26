<?php
test('rate limit counters: count, block, reset, new window', function () {
    $k = 'k' . bin2hex(random_bytes(3));
    eq([false, 0], rateBlocked('t_bucket', $k, 3, 60)); rateHit('t_bucket', $k, 60); rateHit('t_bucket', $k, 60); eq(false, rateBlocked('t_bucket', $k, 3, 60)[0], 'two hits, limit 3');
    rateHit('t_bucket', $k, 60); [$b, $w] = rateBlocked('t_bucket', $k, 3, 60); ok($b && $w >= 1 && $w <= 60, 'blocked with a wait time'); rateReset('t_bucket', $k); eq(false, rateBlocked('t_bucket', $k, 3, 60)[0]);
    rateHit('t_bucket', $k, 60); TW('db')->query("UPDATE rate_limits SET window_start = NOW() - INTERVAL 2 HOUR WHERE bucket = 't_bucket'"); eq(0, rateCount('t_bucket', $k, 60), 'an old window no longer counts'); rateHit('t_bucket', $k, 60); eq(1, rateCount('t_bucket', $k, 60));
});
test('rateGuard counts the attempt and refuses after the limit', function () {
    $k = 'g' . bin2hex(random_bytes(3)); foreach ([1, 2, 3] as $i) ok(rateGuard('t_guard', $k, 3, 60)['ok'], "attempt $i"); $r = rateGuard('t_guard', $k, 3, 60); ok(!$r['ok'] && $r['wait'] > 0, 'fourth refused');
});
test('sign-in: 8 wrong passwords lock the account for a while, even with the right password; other accounts are not affected', function () {
    $u = mkUser('RlU'); $v = mkUser('RlV'); $a = anon(); $a->get('pages/auth/login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $a->body, $m);
    $try = function ($email, $pw) use ($a, $m) { $a->post('pages/auth/login.php', ['csrf_token' => $m[1], 'email' => $email, 'password' => $pw]); return $a; };
    for ($i = 0; $i < 8; $i++) { $r = $try(userEmail($u), 'wrong-pass-' . $i); contains('Invalid email or password', $r->body, "try $i"); }
    $r = $try(userEmail($u), TEST_PW); contains('Too many sign-in attempts', $r->body, 'locked although the password is right'); notContains('Location: ' . TW('base') . '/pages/dashboard.php', $r->headers);
    $r = $try(userEmail($v), TEST_PW); eq(302, $r->code, 'another account can still sign in');
    rateReset('login_account', userEmail($u)); rateReset('login_ip', clientIp()); foreach (['login_ip'] as $b) TW('db')->query("DELETE FROM rate_limits WHERE bucket = ?", [$b]);
});
test('a successful sign-in clears the failures of that account', function () {
    $u = mkUser('RlW'); $a = anon(); $a->get('pages/auth/login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $a->body, $m);
    for ($i = 0; $i < 3; $i++) $a->post('pages/auth/login.php', ['csrf_token' => $m[1], 'email' => userEmail($u), 'password' => 'nope' . $i]);
    ok(rateCount('login_account', userEmail($u), 900) >= 3); $a->post('pages/auth/login.php', ['csrf_token' => $m[1], 'email' => userEmail($u), 'password' => TEST_PW]); eq(0, rateCount('login_account', userEmail($u), 900));
    TW('db')->query("DELETE FROM rate_limits WHERE bucket = 'login_ip'");
});
