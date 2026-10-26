<?php
/**
 * SmartClass - two-step sign-in (loaded by includes/functions.php): TOTP (RFC 6238 — the codes of Google Authenticator,
 * Microsoft Authenticator, Authy, 2FAS …: HMAC-SHA1, 30 seconds, 6 digits) plus one-time backup codes.
 *   totpNewSecret()                      a random base32 secret
 *   totpUri($secret, $account)           otpauth:// link for authenticator apps
 *   totpVerifySecret($secret, $code)     is this code right now (±1 step)? returns the accepted step or false
 *   twoFactorVerify($user_id, $code)     user's code OR backup code; a code works once (TOTP step is remembered)
 *   twoFactorEnable / Disable / backupCodesNew
 * The secret is stored encrypted (encryptSecret, key from the DB credentials).
 */
const TOTP_STEP = 30;

function base32Encode($bin) {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $out = ''; $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32Decode($s) {
    $a = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')); $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin($a[$c]), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totpNewSecret() { return base32Encode(random_bytes(20)); }

function totpCodeAt($secret, $counter) {
    $h = hash_hmac('sha1', pack('J', $counter), base32Decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = (unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000;
    return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
}

function totpUri($secret, $account) {
    return 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode(APP_NAME) . '&algorithm=SHA1&digits=6&period=30';
}

/** The 30-second step that matches $code (within one step either way), or false. */
function totpVerifySecret($secret, $code) {
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6) return false;
    $now = intdiv(time(), TOTP_STEP);
    for ($d = -1; $d <= 1; $d++) if (hash_equals(totpCodeAt($secret, $now + $d), $code)) return $now + $d;
    return false;
}

function backupCodesNew($user_id) {
    global $db;
    $db->query("DELETE FROM user_backup_codes WHERE user_id = ?", [(int) $user_id]);
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
        $codes[] = $c;
        $db->insert('user_backup_codes', ['user_id' => (int) $user_id, 'code_hash' => hash('sha256', $c)]);
    }
    return $codes;
}

/** A code typed at sign-in: the authenticator's current code, or an unused backup code. */
function twoFactorVerify($user_id, $input) {
    global $db;
    $u = $db->fetchOne("SELECT totp_secret, totp_enabled, totp_last_step FROM users WHERE id = ?", [(int) $user_id]);
    if (!$u || !$u['totp_enabled']) return false;
    $input = strtolower(trim((string) $input));
    if (preg_match('/^[0-9a-f]{4}-?[0-9a-f]{4}$/', $input)) {   // backup code
        $h = hash('sha256', strlen($input) === 8 ? substr($input, 0, 4) . '-' . substr($input, 4) : $input);
        return (bool) $db->query("UPDATE user_backup_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL", [(int) $user_id, $h])->rowCount();
    }
    $step = totpVerifySecret(decryptSecret($u['totp_secret']), $input);
    if ($step === false || $step <= (int) $u['totp_last_step']) return false;   // wrong, or this code was already used
    return (bool) $db->query("UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?", [$step, (int) $user_id, $step])->rowCount();
}

function twoFactorEnabled($user_id) {
    global $db;
    return (bool) ($db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [(int) $user_id])['totp_enabled'] ?? 0);
}
