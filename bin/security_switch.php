<?php
// Switch a school-wide security rule off (or on) from the server — for the day an administrator is locked out.
//   php bin/security_switch.php require_admin_2fa off     administrators no longer have to use two-step sign-in
//   php bin/security_switch.php force_https off           stop redirecting to https
//   php bin/security_switch.php password_min_length 10    (any of the three)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/db.php';
$allowed = ['force_https' => 'bool', 'require_admin_2fa' => 'bool', 'password_min_length' => 'int'];
[, $key, $val] = array_pad($argv, 3, null);
if (!isset($allowed[$key ?? '']) || $val === null) { fwrite(STDERR, "Usage: php bin/security_switch.php <" . implode('|', array_keys($allowed)) . "> <on|off|number>\n"); exit(1); }
if ($allowed[$key] === 'bool') { if (!in_array($val, ['on', 'off'], true)) { fwrite(STDERR, "Use on or off.\n"); exit(1); } $val = $val === 'on' ? '1' : '0'; }
else { if (!ctype_digit($val) || (int) $val < 8 || (int) $val > 20) { fwrite(STDERR, "Use a number from 8 to 20.\n"); exit(1); } }
setSetting($key, $val, null);
logActivity('security_switch', 'settings', "Command line: {$key} = {$val}", null);
echo "{$key} = {$val}  (database " . DB_NAME . ")\n";
