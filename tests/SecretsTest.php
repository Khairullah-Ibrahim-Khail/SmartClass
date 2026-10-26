<?php
test('stored secrets: v1 (database-login key) still opens; with a secret_key new ones are v2 and independent of the login', function () {
    $enc = encryptSecret('smtp-password-123'); ok(strpos($enc, 'v1:') === 0 || strpos($enc, 'v2:') === 0); eq('smtp-password-123', decryptSecret($enc));
    eq('', decryptSecret('v1:not-base64!!'), 'garbage gives empty'); eq('', decryptSecret(null)); eq('', encryptSecret(''));
    // a v1 value made the old way opens with the legacy key
    $iv = random_bytes(12); $tag = ''; $c = openssl_encrypt('old-secret', 'aes-256-gcm', secretKeyLegacy(), OPENSSL_RAW_DATA, $iv, $tag); eq('old-secret', decryptSecret('v1:' . base64_encode($iv . $tag . $c)));
});
test('rotate_secrets: v1 secrets become v2, plain text is kept, and they no longer depend on the database login', function () {
    $db = TW('db'); $key = 'k' . bin2hex(random_bytes(8)); $php = escapeshellarg(PHP_BINARY); $root = dirname(__DIR__);
    $t = mkUser('RoT', 'teacher');
    $iv = random_bytes(12); $tag = ''; $c = openssl_encrypt('smtp-secret-xyz', 'aes-256-gcm', secretKeyLegacy(), OPENSSL_RAW_DATA, $iv, $tag);
    $db->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('smtp_password', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['v1:' . base64_encode($iv . $tag . $c)]);
    $db->query("INSERT INTO ai_providers (owner_id, provider, api_key, model) VALUES (?, 'openai', ?, 'm')", [$t, 'v1:' . base64_encode($iv . $tag . $c)]);
    $env = 'SC_TEST_DB=' . TW('dbname') . ' SC_SECRET_KEY=' . $key . ' HOME=' . sys_get_temp_dir() . ' ';
    exec($env . "$php $root/bin/rotate_secrets.php --dry 2>&1", $o1, $c1); eq(0, $c1); contains('2 secret(s) to re-encrypt', implode(' ', $o1));
    eq('v1:', substr($db->fetchOne("SELECT setting_value v FROM app_settings WHERE setting_key = 'smtp_password'")['v'], 0, 3), 'a dry run changes nothing');
    exec($env . "$php $root/bin/rotate_secrets.php 2>&1", $o2, $c2); eq(0, $c2, implode(' ', $o2)); contains('2 re-encrypted', implode(' ', $o2));
    $v = $db->fetchOne("SELECT setting_value v FROM app_settings WHERE setting_key = 'smtp_password'")['v']; eq('v2:', substr($v, 0, 3));
    // read it back in a fresh process that knows only the secret key (and a DIFFERENT database login would not matter)
    $code = '$_SERVER["HTTP_HOST"]="localhost"; require "' . $root . '/config/db.php"; echo decryptSecret(getSetting("smtp_password"));';
    exec($env . "$php -r " . escapeshellarg($code) . ' 2>&1', $o3); eq('smtp-secret-xyz', implode('', $o3));
    exec($env . "$php $root/bin/rotate_secrets.php 2>&1", $o4); contains('0 secret(s)', implode(' ', $o4));
    $db->query("DELETE FROM ai_providers WHERE owner_id = ?", [$t]);
});
