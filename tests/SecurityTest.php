<?php
test('every page sends the browser-protection headers', function () {
    $h = anon(); $h->get('pages/auth/login.php');
    foreach (['X-Content-Type-Options: nosniff', 'X-Frame-Options: SAMEORIGIN', 'Referrer-Policy: same-origin'] as $line) contains($line, $h->headers);
});
test('the session cookie is HttpOnly with SameSite', function () {
    $h = anon(); $h->get('pages/auth/login.php'); contains('HttpOnly', $h->headers); contains('SameSite=Lax', $h->headers);
});
test('signed-in pages are never cached', function () {
    $u = mkUser('SecT', 'teacher'); $h = client($u); $h->get('pages/dashboard.php'); contains('no-store', $h->headers);
});
test('wrong password and unknown user get the same answer', function () {
    $u = mkUser('SecU'); $a = anon(); $a->get('pages/auth/login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $a->body, $m);
    $a->post('pages/auth/login.php', ['csrf_token' => $m[1], 'email' => userEmail($u), 'password' => 'wrong-password']); $r1 = [$a->code, $a->redirect, strlen($a->body) > 0];
    $b = anon(); $b->get('pages/auth/login.php'); preg_match('/name="csrf_token" value="([^"]+)"/', $b->body, $m2);
    $b->post('pages/auth/login.php', ['csrf_token' => $m2[1], 'email' => 'nobody@example.invalid', 'password' => 'wrong-password']); $r2 = [$b->code, $b->redirect, strlen($b->body) > 0];
    eq($r1, $r2, 'the reply must not reveal whether the e-mail exists');
});
test('login without the CSRF token does not sign anyone in', function () {
    $u = mkUser('SecV', 'teacher'); $a = anon(); $a->post('pages/auth/login.php', ['email' => userEmail($u), 'password' => TEST_PW]);
    $a->get('pages/dashboard.php'); ok(!$a->shown(), 'dashboard must stay closed');
});
test('the language parameter of print pages is a whitelist (no injection)', function () {
    $t = mkUser('SecL', 'teacher'); $s = mkUser('SecLs'); $c = mkClass($t, 'LangClass', [$s]); $h = client($t);
    $h->get("pages/classes/roster_print.php?id=$c&lang=" . urlencode('"><script>alert(1)</script>')); ok($h->shown()); notContains('<script>alert(1)', $h->body); contains('lang="en"', $h->body);
    $h->get("pages/classes/roster_print.php?id=$c&lang=ps"); contains('dir="rtl"', $h->body); contains('lang="ps"', $h->body);
    $h->get("pages/classes/roster_print.php?id=$c&lang=fa"); contains('dir="rtl"', $h->body);
});
test('lecture text cannot inject HTML or scripts', function () {
    $t = mkUser('SecX', 'teacher'); $c = mkClass($t, 'XssClass'); $db = TW('db');
    $l = (int) $db->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'x', 'status' => 'published', 'content' => "<script>alert('x')</script> <img src=x onerror=alert(1)> [click](javascript:alert(1))\n\n```output\n<b>raw</b>\n```"]);
    $h = client($t); $h->get("pages/lectures/view.php?id=$l"); ok($h->shown());
    notContains("<script>alert('x')", $h->body); notContains('<img src=x', $h->body); notContains('href="javascript:', $h->body); notContains('<b>raw</b>', $h->body);
});
test('demo logins ("Password: password") are shown on a development machine only, never on a school domain', function () {
    $db = TW('db'); $db->query("DELETE FROM users WHERE email = 'admin@test.com'");
    $db->insert('users', ['name' => 'Demo Admin', 'email' => 'admin@test.com', 'password' => password_hash('password', PASSWORD_DEFAULT), 'role' => 'teacher', 'is_admin' => 1, 'is_approved' => 1]);
    $dev = anon(); $dev->get('pages/auth/login.php'); contains('Demo Accounts', $dev->body, 'localhost / IP: shown');
    $real = anon(); $real->req('GET', 'pages/auth/login.php', [], [], ['Host' => 'school.example.com']); eq(200, $real->code); notContains('Demo Accounts', $real->body, 'a real domain: hidden'); notContains('Password: password', $real->body);
    $weak = weakDemoAccounts(); ok(in_array('admin@test.com', array_column($weak, 'email'), true), 'System check flags the weak account');
    $a = mkUser('SecAdm', 'teacher', 1); $h = client($a); $h->get('pages/settings/system.php'); contains('well-known password', $h->body);
    $db->query("DELETE FROM users WHERE email = 'admin@test.com'");
});
