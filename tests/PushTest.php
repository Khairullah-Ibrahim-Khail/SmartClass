<?php
function pushMockStart() {
    static $st = null;
    if ($st === null) {
        $log = sys_get_temp_dir() . '/sc_push_' . bin2hex(random_bytes(4)) . '.log'; putenv("SC_PUSH_LOG=$log"); file_put_contents($log, '');
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock_push_server.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
        register_shutdown_function(function () use ($proc, $log) { @proc_terminate($proc); @unlink($log); });
        putenv("SC_PUSH_TEST_HOST=127.0.0.1:$port"); $st = ['port' => $port, 'log' => $log];
    }
    file_put_contents($st['log'], '');
    return $st;
}
function pushLog($st) { return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($st['log'])))); }

test('phone notifications: subscribe / unsubscribe over the web (CSRF, sign-in, only real push services), at most 5 devices, school switch', function () {
    $db = TW('db'); $u = mkUser('PsU'); $o = mkUser('PsO'); $h = client($u); $ep = 'https://fcm.googleapis.com/fcm/send/abc123';
    $h->get('pages/settings/notifications.php'); ok($h->shown()); contains('id="pushBox"', $h->body); contains('data-key="', $h->body); contains('Turn on for this device', $h->body);
    $tok = $h->token('pages/settings/notifications.php');
    $call = function ($c, $a, $e, $t = null) use ($tok) { $c->post('pages/notifications/push.php', ['csrf_token' => $t ?? $tok, 'action' => $a, 'endpoint' => $e]); return json_decode($c->body, true); };
    $r = $call($h, 'subscribe', $ep); ok($r['ok'] ?? false, json_encode($r)); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?", [$u])['c']);
    $r = $call($h, 'subscribe', 'https://jmt17.google.com/fcm/send/dpy-R2w2Pbg:APA91b'); ok($r['ok'] ?? false, 'the Google address Chrome gives out is accepted'); $db->query("DELETE FROM push_subscriptions WHERE endpoint LIKE '%jmt17%'");
    $call($h, 'subscribe', $ep); eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?", [$u])['c'], 'the same device twice is one row');
    foreach (['http://fcm.googleapis.com/x', 'https://evil.example/x', 'https://fcm.googleapis.com.evil.example/x', 'https://sites.google.com/x', 'https://evilgoogle.com/fcm/send/x', 'https://x.google.com.evil.example/fcm/send/x', 'https://127.0.0.1/x', 'https://user@fcm.googleapis.com/x', 'file:///etc/passwd'] as $bad) { $r = $call($h, 'subscribe', $bad); ok(!($r['ok'] ?? true), "refused: $bad"); }
    $call($h, 'subscribe', '', 'wrong'); ok(!json_decode($h->body, true)['ok'], 'no CSRF token, no subscription');
    for ($i = 0; $i < 7; $i++) $call($h, 'subscribe', "https://fcm.googleapis.com/fcm/send/dev$i");
    ok((int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?", [$u])['c'] <= 5, 'at most 5 devices per person');
    // another person cannot remove my device
    $ho = client($o); $ho->get('pages/settings/notifications.php'); $to = $ho->token('pages/settings/notifications.php'); $call($ho, 'unsubscribe', "https://fcm.googleapis.com/fcm/send/dev6", $to);
    eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE endpoint_hash = ?", [hash('sha256', 'https://fcm.googleapis.com/fcm/send/dev6')])['c'], 'someone else cannot unsubscribe my device');
    $call($h, 'unsubscribe', 'https://fcm.googleapis.com/fcm/send/dev6'); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE endpoint_hash = ?", [hash('sha256', 'https://fcm.googleapis.com/fcm/send/dev6')])['c']);
    ok(!anon()->get('pages/notifications/push.php')->shown(), 'sign-in needed');
    // the school's switch
    setSetting('push_enabled', '0'); $r = $call($h, 'subscribe', $ep); ok(!($r['ok'] ?? true), 'switched off: no new subscriptions'); [$s] = pushSendNow([$u]); eq(0, $s, 'switched off: nothing is sent'); setSetting('push_enabled', '1');
});

test('phone notifications: the push is an empty, VAPID-signed request; the signature verifies with the published key; gone phones are forgotten; failures are counted', function () {
    $db = TW('db'); $st = pushMockStart(); $u = mkUser('PwU'); $base = "http://127.0.0.1:{$st['port']}";
    foreach (['/ok/1', '/gone/1', '/fail/1'] as $p) $db->insert('push_subscriptions', ['user_id' => $u, 'endpoint_hash' => hash('sha256', $base . $p), 'endpoint' => $base . $p]);
    [$sent, $failed] = pushSendNow([$u]); eq(1, $sent); eq(2, $failed);
    $log = pushLog($st); eq(3, count($log), 'one request per device');
    $req = null; foreach ($log as $l) if (strpos($l['path'], '/ok') === 0) $req = $l;
    eq('POST', $req['method']); eq('', $req['body'], 'the push carries no text'); eq('3600', $req['ttl']);
    ok(preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $req['auth'], $m) === 1, 'Authorization: vapid t=<jwt>, k=<key>: ' . $req['auth']);
    $b = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    $hd = json_decode($b($m[1]), true); $cl = json_decode($b($m[2]), true); eq('ES256', $hd['alg']); eq($base, $cl['aud'], 'audience = the push service'); ok($cl['exp'] > time() && $cl['exp'] <= time() + 13 * 3600, 'expires within 12 hours');
    eq(pushPublicKey(), $m[4], 'the key in the header is the one the browser subscribed with');
    // verify the signature with only the public key
    $pt = $b($m[4]); eq(65, strlen($pt)); $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $pt;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $raw = $b($m[3]); eq(64, strlen($raw)); $enc = fn($x) => (ord($x[0]) > 127 ? "\0" : '') . ltrim($x, "\0") ;
    $r = $enc(substr($raw, 0, 32)); $s = $enc(substr($raw, 32)); $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s; $der = "\x30" . chr(strlen($seq)) . $seq;
    eq(1, openssl_verify($m[1] . '.' . $m[2], $der, $pem, OPENSSL_ALGO_SHA256), 'the signature is valid for the published key');
    eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE endpoint = ?", [$base . '/gone/1'])['c'], 'a 410 answer removes the device');
    eq(1, (int) $db->fetchOne("SELECT fail_count FROM push_subscriptions WHERE endpoint = ?", [$base . '/fail/1'])['fail_count'], 'a failure is counted');
    ok((string) $db->fetchOne("SELECT last_ok_at FROM push_subscriptions WHERE endpoint = ?", [$base . '/ok/1'])['last_ok_at'] !== '', 'success is noted');
    foreach ([2, 3, 4, 5] as $i) pushSendNow([$u]); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE endpoint = ?", [$base . '/fail/1'])['c'], 'five failures in a row remove it');
    // an address that is not a real push service is never called, and is deleted
    $db->insert('push_subscriptions', ['user_id' => $u, 'endpoint_hash' => hash('sha256', 'https://evil.example/p'), 'endpoint' => 'https://evil.example/p']); file_put_contents($st['log'], '');
    pushSendNow([$u]); eq(0, (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE endpoint = 'https://evil.example/p'")['c']);
});

test('phone notifications: notify() rings the person\'s phone next to the e-mail and the bell; latest.php gives the service worker the newest unread notification, translated, and nothing to a visitor', function () {
    $db = TW('db'); $st = pushMockStart(); $u = mkUser('PnU'); $other = mkUser('PnO'); $base = "http://127.0.0.1:{$st['port']}";
    $db->insert('push_subscriptions', ['user_id' => $u, 'endpoint_hash' => hash('sha256', $base . '/ok/n'), 'endpoint' => $base . '/ok/n']);
    $id = notify($u, 'lecture_added', 'New lecture: Cells', 'Marks & grades: open it', 'pages/lectures/view.php?id=1'); notify($other, 'lecture_added', 'Not yours', '');
    eq(1, (int) $db->fetchOne("SELECT COUNT(*) c FROM notifications WHERE id = ?", [$id])['c'], 'the bell notification is still created');
    ok(isset($GLOBALS['sc_push_pending'][$u]), 'the person was queued'); [$sent] = pushFlush(); eq(1, $sent, 'the phone was rung once'); eq(1, count(pushLog($st)));
    eq([], $GLOBALS['sc_push_pending'], 'the queue is empty again'); eq([0, 0], pushFlush());
    $h = client($u); $h->get('pages/notifications/latest.php'); $j = json_decode($h->body, true);
    ok(in_array($j['title'], ['New lecture: Cells', html_entity_decode((string) i18nTranslateText('New lecture: Cells', 'ps')), html_entity_decode((string) i18nTranslateText('New lecture: Cells', 'fa'))], true), 'the title, in the screen language: ' . $j['title']); ok(in_array($j['message'] ?? '', ['Marks & grades: open it'], true) || ($j['message'] ?? '') !== '', 'the message text is there');
    ok(strpos($j['message'] ?? '', '&amp;') === false, 'no HTML escapes in the text'); contains("pages/notifications/open.php?id=$id", $j['url']); eq(1, $j['unread']);
    ok(i18nTranslateText('New lecture: Cells', 'ps') !== 'New lecture: Cells', 'the same title has a Pashto form');
    $db->query("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$u]); $h->get('pages/notifications/latest.php'); $j = json_decode($h->body, true); ok(!isset($j['title']), 'nothing unread: no text'); eq(0, $j['unread']);
    $v = anon(); $v->get('pages/notifications/latest.php'); ok(!$v->shown() && strpos($v->body, 'New lecture') === false, 'a visitor gets no notification text');
    contains("addEventListener('push'", file_get_contents(dirname(__DIR__) . '/sw.js'), 'the service worker shows the push'); contains('notificationclick', file_get_contents(dirname(__DIR__) . '/sw.js'));
});
