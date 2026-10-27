<?php
// Production pre-flight: is this installation ready for real users?   Run it on the SERVER, after installing, and again after every upgrade.
//   php bin/preflight.php                 the original system
//   php bin/preflight.php customer.com    one hosted school (config/instances/customer.com.php)
// Prints PASS / WARN / FAIL per check. Exit code 1 when anything FAILs (WARN does not fail). Changes nothing.
// PHP limits are read from the COMMAND-LINE php.ini; the web server (PHP-FPM / Apache) can differ — the Hosting Guide shows how to check those over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
require_once dirname(__DIR__) . '/includes/migrator.php';
$root = dirname(__DIR__); $target = null;
foreach (array_slice($argv, 1) as $a) if ($a[0] !== '-') $target = $a;
$res = []; $fails = 0; $warns = 0;
$add = function ($level, $what, $hint = '') use (&$res, &$fails, &$warns) { $res[] = [$level, $what, $hint]; if ($level === 'FAIL') $fails++; if ($level === 'WARN') $warns++; };
$bytes = function ($v) { $v = trim((string) $v); $n = (float) $v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return (int) $n; };

// --- PHP
version_compare(PHP_VERSION, '8.0.0', '>=') ? $add('PASS', 'PHP ' . PHP_VERSION) : $add('FAIL', 'PHP ' . PHP_VERSION . ' is too old', 'PHP 8.0 or newer is required');
foreach (['pdo_mysql', 'mbstring', 'curl', 'zip', 'openssl', 'fileinfo'] as $e) extension_loaded($e) ? $add('PASS', "extension $e") : $add('FAIL', "extension $e is missing", "install php-$e");
(extension_loaded('gd') || extension_loaded('imagick')) ? $add('PASS', 'image extension (gd or imagick)') : $add('FAIL', 'no image extension', 'install php-gd');
$bytes(ini_get('upload_max_filesize')) >= 14 * 1048576 ? $add('PASS', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ' (CLI)') : $add('WARN', 'upload_max_filesize is ' . ini_get('upload_max_filesize') . ' in the command-line php.ini', 'the web PHP must allow 14M: see deploy/php-smartclass.ini; verify with Settings > System check');
($bytes(ini_get('post_max_size')) >= 20 * 1048576 || ini_get('post_max_size') === '0') ? $add('PASS', 'post_max_size ' . ini_get('post_max_size') . ' (CLI)') : $add('WARN', 'post_max_size is ' . ini_get('post_max_size') . ' in the command-line php.ini', 'set 20M for the web PHP');

// --- configuration
$cfg = $target ? instanceConfig($target) : instanceMasterConfig();
if (!$cfg) { echo "No such customer: $target\n"; exit(1); }
$local = $root . '/config/local.php'; $l = (!$target && is_file($local)) ? (@include $local) : [];
if (!$target) {
    is_array($l) && $l ? $add('PASS', 'config/local.php exists') : $add('FAIL', 'config/local.php is missing', 'copy config/local.example.php to config/local.php and fill it in');
    $key = is_array($l) ? (string) ($l['secret_key'] ?? '') : '';
    strlen($key) >= 32 && $key !== 'CHANGE_ME' ? $add('PASS', 'secret_key is set') : $add('FAIL', 'secret_key is missing or too short', 'php -r \'echo bin2hex(random_bytes(32));\' and put it in config/local.php');
    if (is_file($local)) { $perm = fileperms($local) & 0007; $perm ? $add('WARN', 'config/local.php is readable by every user on this server', 'chmod 640 config/local.php') : $add('PASS', 'config/local.php is not world-readable'); }
}
($cfg['db_pass'] ?? '') !== '' ? $add('PASS', 'the database password is not empty') : $add('FAIL', 'the database password is empty', 'set db_pass in config/local.php and create the database user with a strong password');
if (($cfg['db_user'] ?? '') === 'root') $add('WARN', 'the application connects as the database user root', 'create a user that only has rights on this one database');

// --- database
$pdo = null;
try { $pdo = instancePdo($cfg); $add('PASS', 'database connection (' . $cfg['db_name'] . ')'); } catch (Throwable $e) { $add('FAIL', 'cannot connect to the database', $e->getMessage()); }
if ($pdo) {
    $have = $pdo->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn() ? $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) : null;
    $files = array_map('basename', migrationFiles());
    if ($have === null) $add('FAIL', 'the database has no schema (run setup.php once)');
    else { $missing = array_diff($files, $have); $missing ? $add('FAIL', count($missing) . ' migration(s) not applied: ' . implode(', ', array_slice($missing, 0, 3)), 'php bin/migrate.php') : $add('PASS', 'database is up to date (' . count($files) . ' migrations)'); }
    if ($have !== null) {
        $set = function ($k, $d = '') use ($pdo) { $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? $d : $v; };
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1")->fetchColumn();
        $admins > 0 ? $add('PASS', "$admins active administrator(s)") : $add('FAIL', 'no administrator account', 'open setup.php and create the first teacher');
        $weak = 0; foreach ($pdo->query("SELECT password FROM users WHERE is_active = 1 AND (email LIKE '%@test.com' OR email LIKE '%@smartclass.demo' OR email LIKE 'admin@%' OR email LIKE 'teacher@%' OR email LIKE 'student@%')")->fetchAll(PDO::FETCH_COLUMN) as $h)
            foreach (['password', '123456', 'admin123', 'password123', 'test1234', 'changeme'] as $g) if (password_verify($g, $h)) { $weak++; break; }
        $weak === 0 ? $add('PASS', 'no demo account with a well-known password') : $add('FAIL', "$weak account(s) with a well-known demo password", 'change the passwords or remove the demo accounts (Settings > System check lists them)');
        $set('force_https', '0') === '1' ? $add('PASS', 'Force HTTPS is on') : $add('WARN', 'Force HTTPS is off', 'after the certificate works: Settings > Rules & policies > Security rules (from a page opened over https)');
        $set('email_enabled', '0') === '1' ? $add('PASS', 'e-mail is switched on') : $add('WARN', 'e-mail is off: no password-reset mails, no notifications by e-mail', 'Settings > Communication > E-mail');
        foreach (['send_emails' => 20 * 60, 'housekeeping' => 36 * 3600] as $job => $late) {
            $last = (string) $set("cron_{$job}_last", ''); $age = $last !== '' ? time() - strtotime($last) : null;
            $age === null ? $add('WARN', "cron job $job has never run", 'install the cron lines (deploy/smartclass.cron)') : ($age > $late ? $add('WARN', "cron job $job last ran " . round($age / 3600, 1) . ' h ago', 'check the crontab') : $add('PASS', "cron job $job ran " . round($age / 60) . ' min ago'));
        }
    }
}

// --- files and exposure
$storage = $target ? $root . '/instances/' . ($cfg['slug'] ?? '') : $root;
foreach (['uploads', 'sessions', 'backups', 'logs'] as $d) { $p = $storage . '/' . $d; (is_dir($p) && is_writable($p)) ? $add('PASS', "$d/ is writable by the user running this command") : $add('WARN', "$d/ is missing or not writable by this user", "the WEB user must be able to write it: chown -R www-data:www-data $p"); }
is_file($root . '/setup.php') ? $add('WARN', 'setup.php is still on the server', 'delete it: rm setup.php') : $add('PASS', 'setup.php is removed');
is_file($root . '/config/allow_reinstall') ? $add('FAIL', 'config/allow_reinstall exists (setup.php can wipe the database)', 'delete it') : $add('PASS', 'config/allow_reinstall does not exist');
is_dir($root . '/.git') ? $add('WARN', 'a .git folder is inside the web folder', 'deploy with bin/package.sh instead of git clone, or make sure /.git/config answers 404 (see the Hosting Guide checks)') : $add('PASS', 'no .git folder in the web folder');
is_dir($root . '/tests') ? $add('WARN', 'tests/ is on the server', 'not needed in production: remove it (it is denied by .htaccess / the nginx rules)') : $add('PASS', 'tests/ is not on the server');

// --- output
$w = max(array_map(fn($r) => strlen($r[1]), $res)); $c = ['PASS' => "\033[32mPASS\033[0m", 'WARN' => "\033[33mWARN\033[0m", 'FAIL' => "\033[31mFAIL\033[0m"]; $tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
foreach ($res as [$lv, $what, $hint]) echo ($tty ? $c[$lv] : $lv) . '  ' . $what . ($hint !== '' && $lv !== 'PASS' ? "\n      -> $hint" : '') . "\n";
echo "\n" . count($res) . " checks: " . (count($res) - $fails - $warns) . " passed, $warns warning(s), $fails failed.\n";
echo $fails ? "NOT READY for real users.\n" : ($warns ? "Ready, with warnings: read them before going live.\n" : "READY.\n");
exit($fails ? 1 : 0);
