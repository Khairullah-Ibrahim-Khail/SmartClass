<?php
/** No page may load a script, stylesheet or font from another site: the school must work offline-from-CDN and no outside party can change what runs in a user's browser. */
test('self-hosted assets: no page loads code, styles or fonts from a CDN, and every local vendor file referenced exists', function () {
    $root = dirname(__DIR__); $bad = []; $missing = [];
    $files = glob($root . '/*.php'); foreach (['includes', 'pages', 'assets/js', 'assets/css'] as $d) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) $files[] = (string) $f;
    foreach ($files as $f) {
        $p = (string) $f; if (!preg_match('/\.(php|js|css)$/', $p)) continue;
        $s = file_get_contents($p);
        if (preg_match('#(src|href)\s*=\s*["\']https?://(cdn\.|cdnjs\.|code\.jquery|fonts\.|unpkg)#i', $s) || preg_match('#@import\s+url\(["\']?https?://#i', $s)) $bad[] = str_replace($root . '/', '', $p);
        if (preg_match_all("#asset\('(assets/vendor/[^']+)'\)#", $s, $m)) foreach ($m[1] as $v) if (!is_file("$root/$v")) $missing[] = "$v (in " . str_replace($root . '/', '', $p) . ')';
    }
    ok(!$bad, 'external CDN references in: ' . implode(', ', $bad)); ok(!$missing, 'missing vendor files: ' . implode(', ', array_unique($missing)));
    ok(is_file("$root/assets/vendor/bootstrap/bootstrap.rtl.min.css"), 'the right-to-left Bootstrap build (Pashto/Dari) must be local too');
    // the font CSS and icon CSS point to files that exist
    foreach (['fonts/fonts.css', 'fontawesome/css/all.min.css', 'bootstrap-icons/bootstrap-icons.css'] as $css) {
        $dir = dirname("$root/assets/vendor/$css"); preg_match_all('#url\(["\']?([^)"\'?\#]+)#', file_get_contents("$root/assets/vendor/$css"), $m);
        foreach ($m[1] as $u) if (!preg_match('#^(data:|https?:)#', $u)) ok(is_file("$dir/$u"), "$css refers to missing $u");
    }
});
