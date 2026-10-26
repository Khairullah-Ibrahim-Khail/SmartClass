<?php
/**
 * SmartClass - forgot password (loaded by includes/functions.php)
 *   passwordResetAvailable()        email is switched on (the link can be delivered)
 *   requestPasswordReset($email)    emails a one-time link to an active account — the caller always
 *                                   shows the same message, so nobody can test which emails exist
 *   findPasswordReset($token)       the valid, unused, unexpired reset row for a link token, or null
 *   completePasswordReset($token, $password)   sets the new password; returns null or an error text
 * The link carries a random 256-bit token; only its SHA-256 is stored. A link works once and
 * expires after PASSWORD_RESET_MINUTES; asking again cancels older links; at most
 * PASSWORD_RESET_PER_HOUR links per account per hour. A new password ends every other open link.
 */

const PASSWORD_RESET_MINUTES = 60;
const PASSWORD_RESET_PER_HOUR = 3;
// the length rule is the school's setting: passwordMinLength() / passwordProblem() in includes/passwords.php

function passwordResetAvailable() {
    return emailConfig()['enabled'];
}

function passwordResetHash($token) {
    return hash('sha256', (string) $token);
}

/** 'sent', 'none' (no such active account), 'limited' or 'failed'. Never shown to the visitor as such. */
function requestPasswordReset($email) {
    global $db;
    $user = $db->fetchOne("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1", [$email]);
    if (!$user) return 'none';
    $recent = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM password_resets WHERE user_id = ? AND created_at >= ?",
        [$user['id'], date('Y-m-d H:i:s', strtotime('-1 hour'))])['c'];
    if ($recent >= PASSWORD_RESET_PER_HOUR) return 'limited';

    $token = bin2hex(random_bytes(32));
    $db->query("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$user['id']]);   // older links stop working
    $db->insert('password_resets', [
        'user_id' => $user['id'], 'token_hash' => passwordResetHash($token),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+' . PASSWORD_RESET_MINUTES . ' minutes')),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $link = APP_URL . '/pages/auth/reset_password.php?token=' . $token;
    $cfg = emailConfig();
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif;">'
        . '<div style="max-width:480px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;">'
        . '<h2 style="margin:0 0 12px;color:#4361ee;">' . $h(APP_NAME) . '</h2>'
        . '<p style="font-size:15px;color:#343a40;">Hi ' . $h($user['name']) . ', someone asked to reset the password of this account.</p>'
        . '<p style="margin:22px 0;"><a href="' . $h($link) . '" style="background:#4361ee;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;">Choose a new password</a></p>'
        . '<p style="font-size:13px;color:#6c757d;">The link works once and expires in ' . PASSWORD_RESET_MINUTES . ' minutes. '
        . 'If you did not ask for this, ignore this email — your password stays as it is.</p>'
        . '<p style="font-size:12px;color:#adb5bd;word-break:break-all;">' . $h($link) . '</p>'
        . '</div></body></html>';
    $text = "Hi {$user['name']},\n\nSomeone asked to reset the password of your " . APP_NAME . " account. Choose a new one here:\n{$link}\n\n"
        . "The link works once and expires in " . PASSWORD_RESET_MINUTES . " minutes.\nIf you did not ask for this, ignore this email.\n";
    try {
        sendEmailNow($cfg, $user['email'], $user['name'], 'Reset your ' . APP_NAME . ' password', $html, $text);
        logActivity('password_reset_requested', 'auth', null, $user['id']);
        return 'sent';
    } catch (Throwable $e) {
        error_log('SmartClass: password reset email failed: ' . $e->getMessage());
        return 'failed';
    }
}

/** The usable reset row (with the account's name / email) for a token, or null. */
function findPasswordReset($token) {
    global $db;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return $db->fetchOne(
        "SELECT r.*, u.name, u.email FROM password_resets r INNER JOIN users u ON r.user_id = u.id
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND u.is_active = 1", [passwordResetHash($token)]) ?: null;
}

/** Set the new password for a link. Returns null on success, else a message for the password field. */
function completePasswordReset($token, $password) {
    global $db;
    $row = findPasswordReset($token);
    if (!$row) return 'expired';
    if ($why = passwordProblem($password, (string) ($row['email'] ?? ''), (string) ($row['name'] ?? ''))) return $why;
    // Claim the link first: only one request can ever use it
    if (!$db->query("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL", [$row['id']])->rowCount()) return 'expired';
    $db->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$row['user_id']]);
    $db->query("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$row['user_id']]);
    logActivity('password_reset', 'auth', 'Password changed with an emailed link', $row['user_id']);
    // Tell the owner (best effort — a failure must not undo the change)
    try {
        sendEmailNow(emailConfig(), $row['email'], $row['name'], 'Your ' . APP_NAME . ' password was changed',
            '<p>Hi ' . htmlspecialchars($row['name']) . ', the password of your ' . htmlspecialchars(APP_NAME) . ' account was just changed. If this was not you, ask your school administrator for help.</p>',
            "Hi {$row['name']},\n\nThe password of your " . APP_NAME . " account was just changed. If this was not you, ask your school administrator for help.\n");
    } catch (Throwable $e) {
        error_log('SmartClass: password changed email failed: ' . $e->getMessage());
    }
    return null;
}
