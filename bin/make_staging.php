<?php
// Staging copy (command line): a separate database called smartclass_staging with a copy of the LIVE data, cleaned of anything
// that could reach real people or cost money, so new code can be tried on realistic data without touching the live system.
//   php bin/make_staging.php              (re)build smartclass_staging from the live database (the old staging copy is replaced)
//   php bin/make_staging.php --keep-emails keep student / guardian e-mail addresses (default: they are replaced by @staging.invalid)
// Cleaned: e-mail settings and queue, AI provider keys (providers disabled), sign-in codes, password-reset links, backup codes,
// two-step secrets, support / customer rows. The live database is only read.
// Run the staging site with  sh bin/staging.sh   →  http://127.0.0.1:8081/myProject/SmartClass/  (orange STAGING strip on top).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
define('STORAGE_ROOT', dirname(__DIR__)); define('SC_IS_MASTER', true);
require_once dirname(__DIR__) . '/includes/backup.php';
$keep_emails = in_array('--keep-emails', $argv, true);
$cfg = instanceMasterConfig();
$live = instancePdo($cfg);
$name = 'smartclass_staging';
$file = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(3)) . '.sql.gz';
try {
    $rows = backupWrite($file, $live);
    $live->exec("DROP DATABASE IF EXISTS `{$name}`");
    $live->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $st = new PDO("mysql:host={$cfg['db_host']};dbname={$name};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($st->query('SELECT DATABASE()')->fetchColumn() !== $name) throw new RuntimeException('Not connected to the staging database — stopping');
    $parsed = backupParse((string) backupRead($file), true);
    if (!is_array($parsed)) throw new RuntimeException($parsed);
    foreach ($parsed[0] as $s) $st->exec($s);
    echo "copied " . number_format($rows) . " rows into {$name}\n";
    $clean = [];
    $run = function ($label, $sql) use ($st, &$clean) { try { $n = $st->exec($sql); $clean[] = "{$label}" . ($n ? " ({$n})" : ''); } catch (Throwable $e) { $clean[] = "{$label} — skipped: " . $e->getMessage(); } };
    $run('e-mail settings', "DELETE FROM app_settings WHERE setting_key LIKE 'email\\_%' OR setting_key LIKE 'smtp\\_%'");
    $run('e-mail queue', 'DELETE FROM email_queue');
    $run('AI provider keys', "UPDATE ai_providers SET api_key = NULL, key_hint = NULL, is_enabled = 0");
    $run('phone-notification devices (a staging copy must never ring real phones)', 'DELETE FROM push_subscriptions');
    $run('phone-notification keys', "DELETE FROM app_settings WHERE setting_key LIKE 'push\\_vapid\\_%'");
    $run('sign-in codes', 'DELETE FROM login_codes');
    $run('password-reset links', 'DELETE FROM password_resets');
    $run('two-step backup codes', 'DELETE FROM user_backup_codes');
    $run('two-step secrets', 'UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0');
    $run('customers / support rows', 'DELETE FROM customer_events');
    $run('customers', 'DELETE FROM customers');
    $run('invite links', 'DELETE FROM teacher_invites');
    if (!$keep_emails) {
        $run('student e-mail addresses', "UPDATE users SET email = CONCAT('student', id, '@staging.invalid') WHERE role = 'student'");
        $run('guardian contacts', 'UPDATE users SET guardian_name = NULL, guardian_email = NULL, guardian_phone = NULL');
    }
    echo "cleaned: " . implode('; ', $clean) . "\n";
    echo "done. Start it with:  sh bin/staging.sh\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
} finally { @unlink($file); }
