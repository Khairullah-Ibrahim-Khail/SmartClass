<?php
test('installable app: the manifest, the service worker and the offline page are served', function () {
    $h = anon(); $h->get('manifest.php'); eq(200, $h->code); contains('manifest+json', $h->headers); $m = json_decode($h->body, true);
    eq('standalone', $m['display']); ok(count($m['icons']) >= 3); ok(strpos($m['start_url'], '/pages/dashboard.php') !== false); ok(strpos($m['scope'], '/') === 0);
    foreach ($m['icons'] as $ic) { $i = anon(); $i->get(preg_replace('#^.*?/assets/#', 'assets/', $ic['src'])); eq(200, $i->code, $ic['src']); contains('image/png', $i->headers); }
    $h->get('sw.js'); eq(200, $h->code); contains('fetch', $h->body); notContains('dashboard', $h->body, 'the worker never lists signed-in pages to cache');
    $h->get('offline.php'); eq(200, $h->code); contains('You are offline', $h->body);
});
test('every app page links the manifest and the theme colour', function () {
    $u = mkUser('PwaU'); $h = client($u); $h->get('pages/dashboard.php'); contains('rel="manifest"', $h->body); contains('name="theme-color"', $h->body); contains("serviceWorker.register", $h->body);
});
