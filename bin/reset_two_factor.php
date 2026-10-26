<?php
// Turn off two-step sign-in for a user who lost their phone AND their backup codes (run on the server by whoever hosts the system).
//   php bin/reset_two_factor.php person@example.com            the original system
//   php bin/reset_two_factor.php person@example.com customer.com   one customer's installation
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/migrator.php';
require_once dirname(__DIR__) . '/includes/instances.php';
$email = $argv[1] ?? '';
$cfg = isset($argv[2]) ? instanceConfig($argv[2]) : instanceMasterConfig();
if ($email === '' || !$cfg) { fwrite(STDERR, "Usage: php bin/reset_two_factor.php EMAIL [customer-domain]\n"); exit(1); }
$pdo = instancePdo($cfg);
$st = $pdo->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = 0 WHERE email = ?");
$st->execute([$email]);
if (!$st->rowCount()) { fwrite(STDERR, "No such user (or two-step sign-in was already off).\n"); exit(1); }
$pdo->prepare("DELETE FROM user_backup_codes WHERE user_id = (SELECT id FROM users WHERE email = ?)")->execute([$email]);
echo "Two-step sign-in is off for {$email}. They can sign in with the password and set it up again.\n";
