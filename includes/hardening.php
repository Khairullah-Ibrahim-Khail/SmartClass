<?php
/**
 * SmartClass - security rules the school switches on (loaded by includes/functions.php)
 *   httpsRedirectTarget($host, $uri, $isHttps, $enabled)   the https address to send a visitor to, or null (pure function — tested)
 *   enforceHttps()                                          runs on every web request: redirect to https / send HSTS when "Force HTTPS" is on
 *   adminNeedsTwoStep($user)                                true when the school requires two-step sign-in for administrators and this admin has none yet
 * Settings (Settings › Security rules): force_https, require_admin_2fa, password_min_length.
 * Safety: "Force HTTPS" can only be switched ON from a page that was itself opened over https, and never redirects localhost / .local /
 * .localhost / IP addresses, so nobody can lock themselves out of a test machine.
 */
function httpsRedirectTarget($host, $uri, $isHttps, $enabled) {
    if (!$enabled || $isHttps) return null;
    $h = strtolower(preg_replace('/:\d+$/', '', (string) $host));
    if ($h === '' || $h === 'localhost' || preg_match('/\.(localhost|local|test|invalid)$/', $h) || filter_var($h, FILTER_VALIDATE_IP)) return null;
    return 'https://' . $host . $uri;
}

function requestIsHttps() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function enforceHttps() {
    if (PHP_SAPI === 'cli' || (defined('SC_STAGING') && SC_STAGING)) return;
    $on = getSetting('force_https', '0') === '1';
    $to = httpsRedirectTarget($_SERVER['HTTP_HOST'] ?? '', $_SERVER['REQUEST_URI'] ?? '/', requestIsHttps(), $on);
    if ($to !== null) { header('Location: ' . $to, true, 301); exit; }
    if ($on && requestIsHttps() && !headers_sent()) header('Strict-Transport-Security: max-age=31536000');
}

/** A machine used for development / testing (localhost, an IP address, *.local / *.localhost / *.test, a staging copy) — never a real school domain. */
function isDevHost() {
    if (defined('SC_STAGING') && SC_STAGING) return true;
    $h = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    return $h === 'localhost' || (bool) preg_match('/\.(localhost|local|test|invalid)$/', $h) || (bool) filter_var($h, FILTER_VALIDATE_IP);
}

/** Active accounts whose password is a well-known demo password ("password", the e-mail name …) — [id, email, role]. Used by System check. */
function weakDemoAccounts() {
    global $db;
    $out = [];
    foreach ($db->fetchAll("SELECT id, email, role, password FROM users WHERE is_active = 1 AND (email LIKE '%@test.com' OR email LIKE '%@smartclass.demo' OR email LIKE 'admin@%' OR email LIKE 'teacher@%' OR email LIKE 'student@%') LIMIT 200") as $u) {
        foreach (['password', '123456', 'admin123', 'password123', 'test1234', 'changeme'] as $guess) {
            if (password_verify($guess, $u['password'])) { $out[] = ['id' => (int) $u['id'], 'email' => $u['email'], 'role' => $u['role']]; break; }
        }
    }
    return $out;
}

function adminNeedsTwoStep($u) {
    return getSetting('require_admin_2fa', '0') === '1' && !empty($u['is_admin']) && ($u['role'] ?? '') === 'teacher' && empty($u['totp_enabled']) && empty($_SESSION['support_until']);
}
