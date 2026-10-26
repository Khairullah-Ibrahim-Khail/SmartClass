<?php
/**
 * SmartClass - App settings module (loaded by includes/functions.php)
 *
 * Settings edited in the app (never hard-coded in files), stored in app_settings.
 *   getSetting($key, $default)          read (cached per request)
 *   setSetting($key, $value, $user_id)  write
 *   encryptSecret() / decryptSecret()   for secrets such as the SMTP password —
 *                                       AES-256-GCM, key derived from the DB credentials
 *                                       in config/db.php (changing those means re-entering it)
 */

/** All settings, loaded once per request. */
function allSettings($refresh = false) {
    global $db;
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach ($db->fetchAll("SELECT setting_key, setting_value FROM app_settings") as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (PDOException $e) {
            // app_settings missing (migration 008 not run yet): behave as "no settings"
            error_log('SmartClass: app_settings unavailable: ' . $e->getMessage());
        }
    }
    return $cache;
}

function getSetting($key, $default = null) {
    $all = allSettings();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function setSetting($key, $value, $user_id = null) {
    global $db;
    $db->query(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
        [$key, $value === null ? null : (string) $value, $user_id]
    );
    allSettings(true);
}

/** Key for secrets at rest — never stored anywhere. */
/** v1 key: derived from the database login (older installations; changing the DB login makes such secrets unreadable). */
function secretKeyLegacy() {
    return hash('sha256', 'smartclass|' . DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS, true);
}

/** v2 key: its own random key from config/local.php ('secret_key') — independent of the database login. null when none is configured. */
function secretKeyV2() {
    return defined('SC_SECRET_KEY') && SC_SECRET_KEY !== '' ? hash('sha256', 'smartclass-secret-v2|' . SC_SECRET_KEY, true) : null;
}

function secretKey() {
    return secretKeyV2() ?? secretKeyLegacy();
}

function encryptSecret($plain) {
    if ($plain === '' || $plain === null) return '';
    $iv = random_bytes(12);
    $tag = '';
    $v2 = secretKeyV2();
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $v2 ?? secretKeyLegacy(), OPENSSL_RAW_DATA, $iv, $tag);
    return ($v2 ? 'v2:' : 'v1:') . base64_encode($iv . $tag . $cipher);
}

/** Plain text, or '' when empty / not decryptable (e.g. DB credentials changed). */
function decryptSecret($stored) {
    if (!is_string($stored) || !in_array(substr($stored, 0, 3), ['v1:', 'v2:'], true)) return '';
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $key = substr($stored, 0, 3) === 'v2:' ? secretKeyV2() : secretKeyLegacy();
    if ($key === null) return '';   // a v2 secret but no secret_key configured here
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}
