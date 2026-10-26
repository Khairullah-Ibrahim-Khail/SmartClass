<?php
/**
 * SmartClass - sign in with a one-time email code (loaded by includes/functions.php)
 *
 * Students do not need a password: they type their email, get a 6-digit code and sign in.
 * Several teachers share the same students, so no teacher manages student passwords.
 *   codeLoginAvailable()           email is switched on (codes can be delivered)
 *   requestLoginCode($email)       sends a code if a student account exists — the caller always
 *                                  shows the same message, so nobody can test which emails exist
 *   verifyLoginCode($email, $code) → user row, or an error message (string)
 *   startUserSession($user)        the one place that signs a user in (code and password login)
 * Codes: random 6 digits, only an HMAC is stored, valid LOGIN_CODE_MINUTES, LOGIN_CODE_TRIES
 * tries, used once; at most LOGIN_CODE_PER_15MIN codes per account per 15 minutes.
 */

const LOGIN_CODE_MINUTES = 10;
const LOGIN_CODE_TRIES = 5;
const LOGIN_CODE_PER_15MIN = 3;

/** Codes can only be used when email notifications are switched on. */
function codeLoginAvailable() {
    return emailConfig()['enabled'];
}

/** HMAC of a code, tied to the account (a code for one account never works for another). */
function loginCodeHash($user_id, $code) {
    return hash_hmac('sha256', (int) $user_id . '|' . $code, secretKey());
}

/**
 * Email a sign-in code to a student account. Returns 'sent', 'none' (no such active
 * student), 'limited' (too many codes) or 'failed' (email error, logged).
 * Callers must not tell the visitor which of these happened, except 'limited' / 'failed'
 * for an address that exists — they show one neutral message instead.
 */
function requestLoginCode($email) {
    global $db;
    $user = $db->fetchOne("SELECT id, name, email FROM users WHERE email = ? AND role = 'student' AND is_active = 1", [$email]);
    if (!$user) return 'none';
    $recent = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM login_codes WHERE user_id = ? AND created_at >= ?",
        [$user['id'], date('Y-m-d H:i:s', strtotime('-15 minutes'))])['c'];
    if ($recent >= LOGIN_CODE_PER_15MIN) return 'limited';

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // A new code replaces older unused ones
    $db->query("UPDATE login_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$user['id']]);
    $db->insert('login_codes', [
        'user_id' => $user['id'], 'code_hash' => loginCodeHash($user['id'], $code),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+' . LOGIN_CODE_MINUTES . ' minutes')),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $cfg = emailConfig();
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif;">'
        . '<div style="max-width:480px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;">'
        . '<h2 style="margin:0 0 12px;color:#4361ee;">' . $h(APP_NAME) . '</h2>'
        . '<p style="font-size:15px;color:#343a40;">Hi ' . $h($user['name']) . ', your sign-in code is:</p>'
        . '<p style="font-size:34px;font-weight:bold;letter-spacing:8px;color:#1a1a2e;margin:18px 0;">' . $code . '</p>'
        . '<p style="font-size:13px;color:#6c757d;">It works once and expires in ' . LOGIN_CODE_MINUTES . ' minutes. '
        . 'If you did not try to sign in, you can ignore this email — nobody can sign in without the code.</p>'
        . '</div></body></html>';
    $text = "Hi {$user['name']},\n\nYour " . APP_NAME . " sign-in code is: {$code}\n\n"
        . "It works once and expires in " . LOGIN_CODE_MINUTES . " minutes.\n"
        . "If you did not try to sign in, you can ignore this email.\n";
    try {
        sendEmailNow($cfg, $user['email'], $user['name'], 'Your ' . APP_NAME . ' sign-in code', $html, $text);
        return 'sent';
    } catch (Throwable $e) {
        error_log('SmartClass: login code email failed: ' . $e->getMessage());
        return 'failed';
    }
}

/** The student for a correct code, else an error message for the code field. */
function verifyLoginCode($email, $code) {
    global $db;
    $code = preg_replace('/\D/', '', (string) $code);
    $generic = 'That code is not right or has expired. Ask for a new one.';
    if (strlen($code) !== 6) return 'Enter the 6-digit code from the email';
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND role = 'student' AND is_active = 1", [$email]);
    if (!$user) return $generic;
    $row = $db->fetchOne(
        "SELECT * FROM login_codes WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$user['id']]);
    if (!$row || $row['attempts'] >= LOGIN_CODE_TRIES) return $generic;
    if (!hash_equals($row['code_hash'], loginCodeHash($user['id'], $code))) {
        $db->query("UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?", [$row['id']]);
        $left = LOGIN_CODE_TRIES - $row['attempts'] - 1;
        return $left > 0 ? 'Wrong code — ' . $left . ' tr' . ($left === 1 ? 'y' : 'ies') . ' left' : $generic;
    }
    // Use it once (claimed atomically — a code can never sign in twice)
    if (!$db->query("UPDATE login_codes SET used_at = NOW() WHERE id = ? AND used_at IS NULL", [$row['id']])->rowCount()) return $generic;
    if (!$user['email_verified_at']) {
        $db->update('users', ['email_verified_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    }
    return $user;
}

/** Sign a user in: fresh session id, session fields, last login, audit log. */
function startUserSession(array $user, $method = 'password', $second_step_done = false) {
    global $db;
    // Two-step sign-in: the password / e-mail code was right, but nobody is signed in until the authenticator code is too
    if (!$second_step_done && $method !== 'support' && !empty($user['totp_enabled'])) {
        $_SESSION['two_factor'] = ['uid' => (int) $user['id'], 'method' => $method, 'at' => time(), 'tries' => 0];
        redirect(APP_URL . '/pages/auth/two_factor.php');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    i18nOnLogin($user);   // the person's saved language
    $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
    logActivity('login', 'auth', $method === 'code' ? 'Signed in with an email code' : null, $user['id']);
}
