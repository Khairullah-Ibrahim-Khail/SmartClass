<?php
/**
 * Phone notifications (web push) — a second way to reach people, next to the in-app bell and e-mail (both stay as they are).
 *
 * How it works: a person turns it on in Settings › Notifications; their browser gives us an "endpoint" address at its push
 * service (Google, Mozilla, Apple, Microsoft). When notify() creates a notification we ring that address with a signed, EMPTY
 * push (VAPID). The phone wakes the service worker (sw.js), which asks pages/notifications/latest.php for the newest unread
 * notification — with the person's own sign-in — and shows it. So no text ever travels through the push service, and a person who
 * is signed out only sees "You have a new notification".
 *
 *   pushEnabled()            the school's switch (Settings › Notifications, super admin) — default on; off on a staging copy
 *   pushPublicKey()          the VAPID public key the browser needs to subscribe (created on first use, private part encrypted)
 *   pushAllowedEndpoint($u)  only the real push services (an address typed by a visitor must never make the server call anything else)
 *   pushQueue($user_id)      called by notify(): rings the person's phones after the page has been sent
 *   pushSendNow($user_ids)   rings now (used by the "Send a test" button and the tests); returns [sent, failed]
 */

function pushEnabled() {
    return getSetting('push_enabled', '1') === '1' && !(defined('SC_STAGING') && SC_STAGING);
}

function pushB64u($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

/** [public (base64url, 65-byte point), pem] — made once, kept in settings (private key encrypted). */
function pushKeys() {
    static $keys = null;
    if ($keys) return $keys;
    $pub = (string) getSetting('push_vapid_public', ''); $pem = decryptSecret((string) getSetting('push_vapid_private', ''));
    if ($pub === '' || $pem === '') {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$k) return null;
        openssl_pkey_export($k, $pem);
        $ec = openssl_pkey_get_details($k)['ec'];
        $pub = pushB64u("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        setSetting('push_vapid_public', $pub);
        setSetting('push_vapid_private', encryptSecret($pem));
    }
    return $keys = [$pub, $pem];
}

function pushPublicKey() { $k = pushKeys(); return $k ? $k[0] : ''; }

/** Only https addresses of the browsers' own push services (tests may allow one local host: SC_PUSH_TEST_HOST=127.0.0.1:8099). */
function pushAllowedEndpoint($url) {
    $p = parse_url((string) $url);
    if (!$p || empty($p['host']) || strlen($url) > 1000 || isset($p['user'])) return false;
    $test = getenv('SC_PUSH_TEST_HOST');
    $hostport = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($test && $hostport === strtolower($test) && in_array($p['scheme'] ?? '', ['http', 'https'], true)) return true;
    if (($p['scheme'] ?? '') !== 'https' || isset($p['port'])) return false;
    $host = strtolower($p['host']);
    if (substr($host, -11) === '.google.com' && strpos((string) ($p['path'] ?? ''), '/fcm/') === 0) return true;   // Chrome sometimes hands out e.g. jmt17.google.com/fcm/send/...
    foreach (['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com', 'push.microsoft.com'] as $ok) {
        if ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok) return true;
    }
    return false;
}

/** DER ECDSA signature → the raw 64 bytes (r || s) that JWT ES256 wants. */
function pushDerToRaw($der) {
    $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}

function pushJwt($endpoint) {
    $keys = pushKeys(); if (!$keys) return null;
    $p = parse_url($endpoint);
    $aud = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $host = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $in = pushB64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . pushB64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:admin@' . $host]));
    if (!openssl_sign($in, $der, $keys[1], OPENSSL_ALGO_SHA256)) return null;
    return $in . '.' . pushB64u(pushDerToRaw($der));
}

function pushQueue($user_id) {
    static $hooked = false;
    if (!pushEnabled()) return;
    $GLOBALS['sc_push_pending'][(int) $user_id] = true;
    if ($hooked) return;
    $hooked = true;
    register_shutdown_function(function () {   // after the page has gone out, so nobody waits for the push services
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        pushFlush();
    });
}

/** Ring everyone queued by notify() so far (also called by the tests). */
function pushFlush() {
    $ids = array_keys($GLOBALS['sc_push_pending'] ?? []);
    $GLOBALS['sc_push_pending'] = [];
    if (!$ids) return [0, 0];
    try { return pushSendNow($ids); } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); return [0, 0]; }
}

function pushSendNow(array $user_ids) {
    global $db;
    $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
    if (!$user_ids || !pushEnabled() || !function_exists('curl_multi_init')) return [0, 0];
    $rows = $db->fetchAll("SELECT * FROM push_subscriptions WHERE user_id IN (" . implode(',', array_fill(0, count($user_ids), '?')) . ") LIMIT 500", $user_ids);
    $keys = pushKeys();
    if (!$rows || !$keys) return [0, 0];
    $multi = curl_multi_init(); $handles = [];
    foreach ($rows as $r) {
        if (!pushAllowedEndpoint($r['endpoint'])) { $db->query("DELETE FROM push_subscriptions WHERE id = ?", [$r['id']]); continue; }
        $jwt = pushJwt($r['endpoint']); if (!$jwt) continue;
        $ch = curl_init($r['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('SC_PUSH_TEST_HOST') ? CURLPROTO_HTTP : 0),
            CURLOPT_HTTPHEADER => ['Authorization: vapid t=' . $jwt . ', k=' . $keys[0], 'TTL: 3600', 'Urgency: normal', 'Content-Length: 0']]);
        curl_multi_add_handle($multi, $ch); $handles[(int) $r['id']] = $ch;
    }
    do { $st = curl_multi_exec($multi, $active); if ($active) curl_multi_select($multi, 1.0); } while ($active && $st === CURLM_OK);
    $sent = 0; $failed = 0;
    foreach ($handles as $id => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code >= 200 && $code < 300) { $sent++; $db->query("UPDATE push_subscriptions SET last_ok_at = NOW(), fail_count = 0 WHERE id = ?", [$id]); }
        elseif ($code === 404 || $code === 410) { $failed++; $db->query("DELETE FROM push_subscriptions WHERE id = ?", [$id]); }   // the phone turned it off or uninstalled
        else { $failed++; $db->query("UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?", [$id]); $db->query("DELETE FROM push_subscriptions WHERE id = ? AND fail_count >= 5", [$id]); }
        curl_multi_remove_handle($multi, $ch); curl_close($ch);
    }
    curl_multi_close($multi);
    return [$sent, $failed];
}
