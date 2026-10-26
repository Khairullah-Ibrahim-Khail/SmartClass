<?php
// SmartClass - set a new password from the command line (the way back in when email is off,
// or when the only super admin forgot the password).
//
//   php cron/reset_password.php someone@example.com
//
// It asks for the new password (typed without echo) and writes it for that account.
// Command line only; it cannot be started from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/db.php';

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php cron/reset_password.php email@example.com\n");
    exit(1);
}
$user = $db->fetchOne("SELECT id, name, email, role, is_admin FROM users WHERE email = ?", [$email]);
if (!$user) {
    fwrite(STDERR, "No account with that email.\n");
    exit(1);
}
echo "Account: {$user['name']} <{$user['email']}> (" . ($user['is_admin'] ? 'super admin' : $user['role']) . ")\n";

function readHidden($prompt) {
    echo $prompt;
    $tty = DIRECTORY_SEPARATOR === '/' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $line = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) { shell_exec('stty echo'); echo "\n"; }
    return $line;
}
$pw = readHidden('New password: ');
if (strlen($pw) < PASSWORD_MIN_LENGTH) {
    fwrite(STDERR, 'Password must be at least ' . PASSWORD_MIN_LENGTH . " characters.\n");
    exit(1);
}
if ($pw !== readHidden('Repeat it:   ')) {
    fwrite(STDERR, "The two passwords are not the same. Nothing was changed.\n");
    exit(1);
}
$db->update('users', ['password' => password_hash($pw, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
$db->query("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$user['id']]);
logActivity('password_reset', 'auth', 'Password set from the command line', $user['id']);
echo "Done. {$user['email']} can sign in with the new password.\n";
