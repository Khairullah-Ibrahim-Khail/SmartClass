<?php
/** Regression tests for defects found in the QA audit (qa/defect-report.md). Each test names its defect id; each one failed before the fix. */

test('D-003: the password-reset page opens with a valid link (it used an undefined constant and crashed)', function () {
    $u = mkUser('QaReset'); $db = TW('db'); $token = bin2hex(random_bytes(32));
    $db->insert('password_resets', ['user_id' => $u, 'token_hash' => passwordResetHash($token), 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);
    $h = anon(); $h->get('pages/auth/reset_password.php?token=' . $token);
    eq(200, $h->code, 'the page answers'); notContains('Fatal error', $h->body); notContains('Undefined constant', $h->body); contains('name="password"', $h->body, 'the new-password form is shown');
    contains('at least ' . passwordMinLength() . ' characters', $h->body, 'with the real minimum length from the settings');
});

test('D-003: the command-line password reset tool uses the configured minimum length, not an undefined constant', function () {
    $src = file_get_contents(dirname(__DIR__) . '/cron/reset_password.php');
    notContains('PASSWORD_MIN_LENGTH', $src); contains('passwordMinLength()', $src);
});

test('S-001: no database password is written in the code — config/db.php defaults to an empty password and the installer uses the current schema (D-001)', function () {
    $root = dirname(__DIR__);
    $db = file_get_contents($root . '/config/db.php');
    ok(preg_match("/define\\('DB_PASS',[^;]*\\?\\? ''\\);/", $db) === 1, 'DB_PASS must fall back to an empty string, never to a real password');
    // No tracked source file may contain a literal password assignment for the database
    foreach (['config/db.php', 'setup.php', 'includes/instances.php'] as $f) notContains('sahil', strtolower(file_get_contents($root . '/' . $f)), "$f contains the old password");
    $setup = file_get_contents($root . '/setup.php');
    contains('config/install_schema.sql', $setup); notContains("config/smartclass_db.sql';", $setup);
    contains('migrateDatabase', $setup);
});

test('S-003: a deployed copy never serves its repository or its tests, and setup.php locks itself once an administrator exists', function () {
    $root = dirname(__DIR__);
    $ht = file_get_contents($root . '/.htaccess');
    contains('RedirectMatch 404', $ht); contains('git', $ht);
    // every folder that holds code or private data refuses web access
    foreach (['config', 'includes', 'bin', 'cron', 'tests', 'qa', 'docs', 'logs', 'backups', 'instances'] as $d) {
        ok(is_file("$root/$d/.htaccess") && stripos(file_get_contents("$root/$d/.htaccess"), 'Require all denied') !== false, "$d/.htaccess must deny web access");
    }
    $setup = file_get_contents($root . '/setup.php');
    contains('$locked', $setup); contains('config/allow_reinstall', $setup);
    ok(preg_match('/REQUEST_METHOD\'\] === \'POST\' && \$status\[\'server_ok\'\] && !\$locked/', $setup) === 1, 'POST actions of setup.php must be refused while it is locked');
    contains('admin_count', $setup);
});

test('Q-002: bin/check.sh runs the test suite once', function () {
    eq(1, preg_match_all('/php tests\/run\.php/', file_get_contents(dirname(__DIR__) . '/bin/check.sh')));
});

test('S-004: the old AJAX sign-in and sign-up are gone — they skipped the lockout, two-step sign-in, password rules and registration settings', function () {
    $u = mkUser('AjaxLogin'); $h = anon(); $tok = $h->token('pages/auth/login.php');
    $h->post('ajax/handler.php', ['csrf_token' => $tok, 'action' => 'login', 'email' => userEmail($u), 'password' => TEST_PW], [], ['X-Requested-With' => 'XMLHttpRequest']);
    $j = json_decode($h->body, true); ok(is_array($j) && empty($j['success']), 'ajax login must not sign anyone in: ' . substr($h->body, 0, 120));
    $h->get('pages/dashboard.php'); ok($h->code === 302 || $h->code === 303, 'no session may exist after the ajax login attempt (HTTP ' . $h->code . ')');
    $h2 = anon(); $tok = $h2->token('pages/auth/login.php');
    $h2->post('ajax/handler.php', ['csrf_token' => $tok, 'action' => 'register', 'name' => 'Ajax Reg', 'email' => 'ajaxreg@example.invalid', 'password' => 'abcdef', 'confirm_password' => 'abcdef'], [], ['X-Requested-With' => 'XMLHttpRequest']);
    ok(!TW('db')->fetchOne("SELECT id FROM users WHERE email = 'ajaxreg@example.invalid'"), 'ajax register must not create accounts');
});

test('H-001: behind an HTTPS-terminating proxy the app builds https links and Secure cookies — only when told to trust the proxy', function () {
    $run = function ($trust) {
        $code = '$_SERVER = ["HTTP_HOST" => "school.example.com", "HTTP_X_FORWARDED_PROTO" => "https", "REQUEST_METHOD" => "GET", "REQUEST_URI" => "/", "SCRIPT_NAME" => "/x.php"]; require ' . var_export(dirname(__DIR__) . '/config/db.php', true) . '; echo APP_URL, "|", $_SERVER["HTTPS"] ?? "unset";';
        $env = 'SC_TEST_DB=' . escapeshellarg(TW('dbname')) . ($trust ? ' SC_TRUST_PROXY=1' : '');
        return trim((string) shell_exec("$env php -r " . escapeshellarg($code) . ' 2>/dev/null'));
    };
    $off = $run(false); $on = $run(true);
    contains('http://school.example.com', $off); contains('unset', $off);   // header ignored by default: it could be forged
    contains('https://school.example.com', $on); contains('|on', $on);
});

test('the administrator can rename the "School admin" title shown under their name (Settings > School)', function () {
    $a = mkUser('TitleAdmin', 'teacher', 1); $h = client($a);
    $h->get('pages/dashboard.php'); contains('School admin', $h->body);
    $tok = $h->token('pages/settings/school.php');
    $h->post('pages/settings/school.php', ['csrf_token' => $tok, 'school_name' => 'Title School', 'brand_color' => '#4361ee', 'admin_title' => 'Principal <b>']);
    $h->get('pages/dashboard.php'); contains('Principal &lt;b&gt;', $h->body, 'new title shown, escaped'); notContains('>School admin<', $h->body);
    $t = mkUser('TitleTeacher'); $tt = client(mkUser('TitleTeacher2', 'teacher')); $tt->get('pages/dashboard.php'); contains('Teacher', $tt->body); notContains('Principal', $tt->body, 'ordinary teachers keep their label');
    TW('db')->query("DELETE FROM app_settings WHERE setting_key = 'admin_title'");
});

test('the header has a refresh button on every kind of page', function () {
    $h = client(mkUser('RefreshT', 'teacher')); $h->get('pages/dashboard.php'); contains('id="refresh-btn"', $h->body);
    $s = client(mkUser('RefreshS')); $s->get('pages/dashboard.php'); contains('id="refresh-btn"', $s->body);
});
