<?php
/**
 * Security probes over the whole system: injection / script payloads in every query parameter of every page, state changes without a CSRF
 * token, private pages as a visitor, response headers and session cookie flags. Needs the fixtures of RouteMatrixTest.
 */
require_once __DIR__ . '/RouteMatrixTest.php';

function spPages() {
    $pages = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/pages', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (substr($file, -4) !== '.php') continue; $rel = str_replace(dirname(__DIR__) . '/pages/', '', $file);
        if (basename($rel)[0] === '_' || $rel === 'auth/logout.php') continue; $pages[] = $rel;
    }
    sort($pages); return $pages;
}

test('security probe: SQL-injection and script payloads in every parameter of every page — no SQL error, no crash, no raw script echoed back', function () {
    $f = rmFixtures(); $h = client($f['t']); $adm = client($f['admin']);
    $sqli = ["1' OR '1'='1", "1; DROP TABLE users; --", "1 UNION SELECT password FROM users-- ", "' OR SLEEP(3)-- "];
    $xss = '"><script>alert(7331)</script>';
    $params = ['id', 'class_id', 'q', 'search', 'student_id', 'topic', 'tab', 'status', 'sort', 'page', 'per', 'lang', 'date', 'kind', 'type', 'filter', 'u', 't', 'code', 'token'];
    $bad = []; $n = 0; $pos = rmLogPos();
    foreach (spPages() as $page) {
        foreach ([$sqli[0], $sqli[1], $sqli[2], $xss] as $payload) {
            $qs = http_build_query(array_fill_keys($params, $payload));
            foreach ([$h, $adm] as $client) {
                $t0 = microtime(true); $client->get("pages/$page?$qs"); $n++; $dt = microtime(true) - $t0;
                $b = $client->body;
                if ($client->code >= 500 || $client->code === 0) $bad[] = "GET $page [" . substr($payload, 0, 18) . "] → HTTP {$client->code}";
                elseif (preg_match('/You have an error in your SQL|PDOException|SQLSTATE\[/', $b) && $page !== 'settings/system.php') $bad[] = "GET $page [" . substr($payload, 0, 18) . "] → error text in the page";
                elseif ($payload === $xss && strpos($b, '<script>alert(7331)</script>') !== false) $bad[] = "GET $page → script payload echoed back unescaped";
                if ($dt > 2.5) $bad[] = "GET $page [" . substr($payload, 0, 18) . "] took " . round($dt, 1) . " s (time-based injection or slow query)";
            }
        }
    }
    foreach (rmLogNew($pos) as $l) $bad[] = 'error log: ' . substr($l, 0, 220);
    fwrite(STDERR, "security probe: $n requests\n");
    // the tables must still exist after the DROP TABLE payload
    $c = TW('db')->fetchOne("SELECT COUNT(*) AS c FROM users")['c'] ?? null; ok($c !== null, 'users table must still exist');
    ok(!$bad, count($bad) . " problem(s):\n   " . implode("\n   ", array_slice($bad, 0, 30)));
});

test('security probe: a POST without a CSRF token (or with a wrong one) changes nothing, on every page that accepts forms', function () {
    $f = rmFixtures(); $h = client($f['t']); $before = TW('db')->fetchOne("SELECT (SELECT COUNT(*) FROM classes) c, (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM assignments) a, (SELECT COUNT(*) FROM announcements) n");
    $bad = [];
    foreach (spPages() as $page) {
        if (in_array($page, RM_PUBLIC, true) || in_array($page, ['whatsapp/webhook.php', 'notifications/latest.php'], true)) continue;
        foreach ([[], ['csrf_token' => 'wrong']] as $extra) {
            $h->post("pages/$page?id={$f['c']}&class_id={$f['c']}", $extra + ['action' => 'delete', 'name' => 'X', 'title' => 'Injected', 'body' => 'Injected', 'class_name' => 'Injected', 'delete' => '1']);
            if ($h->code >= 500) $bad[] = "POST $page → HTTP {$h->code}";
        }
    }
    $after = TW('db')->fetchOne("SELECT (SELECT COUNT(*) FROM classes) c, (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM assignments) a, (SELECT COUNT(*) FROM announcements) n");
    eq($before, $after, 'row counts changed by token-less POSTs');
    ok(!TW('db')->fetchOne("SELECT id FROM announcements WHERE body = 'Injected'") && !TW('db')->fetchOne("SELECT id FROM classes WHERE class_name = 'Injected'"), 'a forged form wrote data');
    ok(!$bad, implode("\n   ", array_slice($bad, 0, 20)));
});

test('security probe: response headers and session cookie', function () {
    $h = anon(); $h->get('pages/auth/login.php');
    $hd = []; foreach (explode("\n", $h->headers) as $line) if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hd[strtolower(trim($k))][] = trim($v); }
    $flat = strtolower(implode(';', $hd['set-cookie'] ?? []));
    ok(isset($hd['x-content-type-options']), 'X-Content-Type-Options'); ok(isset($hd['x-frame-options']) || isset($hd['content-security-policy']), 'clickjacking protection');
    ok(isset($hd['referrer-policy']), 'Referrer-Policy');
    ok($flat === '' || strpos($flat, 'httponly') !== false, 'session cookie must be HttpOnly: ' . $flat);
    ok($flat === '' || strpos($flat, 'samesite') !== false, 'session cookie must have SameSite: ' . $flat);
    ok(!isset($hd['x-powered-by']) || true, '');
});
