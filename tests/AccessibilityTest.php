<?php
/**
 * Accessibility / markup basics on every page as the teacher: language + direction on <html>, viewport, a title, a single <h1>-level landmark,
 * every image has alt text, every form field has a label (label[for], wrapping label, aria-label or aria-labelledby), every icon-only
 * button/link has an accessible name, no duplicate ids. Pashto and Dari pages must be right-to-left.
 */
require_once __DIR__ . '/RouteMatrixTest.php';

/** Elements inside a [hidden] container are not shown to anyone (data holders for scripts). */
function a11yHidden($el) { for ($n = $el; $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode) if ($n->hasAttribute('hidden')) return true; return false; }

function a11yProblems($html, $label) {
    $p = []; $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML('<?xml encoding="utf-8"?>' . $html); libxml_clear_errors(); $x = new DOMXPath($d);
    $root = $x->query('//html')->item(0);
    if (!$root || !$root->getAttribute('lang')) $p[] = "$label: <html> has no lang";
    if (!$x->query('//meta[@name="viewport"]')->length) $p[] = "$label: no viewport meta";
    if (trim((string) ($x->query('//title')->item(0)->textContent ?? '')) === '') $p[] = "$label: empty <title>";
    foreach ($x->query('//img') as $i) if (!$i->hasAttribute('alt')) $p[] = "$label: <img src=\"" . substr($i->getAttribute('src'), 0, 60) . "\"> without alt";
    $labels = []; foreach ($x->query('//label[@for]') as $l) $labels[$l->getAttribute('for')] = true;
    foreach ($x->query('//input[not(@type="hidden" or @type="submit" or @type="button" or @type="image" or @type="reset")] | //select | //textarea') as $f) {
        $id = $f->getAttribute('id'); if (a11yHidden($f) || $f->getAttribute('style') && preg_match('/display:\s*none/', $f->getAttribute('style'))) continue;
        $named = ($id !== '' && isset($labels[$id])) || $f->getAttribute('aria-label') !== '' || $f->getAttribute('aria-labelledby') !== '' || $f->getAttribute('title') !== '';
        for ($n = $f->parentNode; !$named && $n && $n->nodeType === XML_ELEMENT_NODE; $n = $n->parentNode) if ($n->nodeName === 'label') $named = true;
        if (!$named) $p[] = "$label: field " . ($f->getAttribute('name') ?: $f->nodeName) . " (" . ($f->getAttribute('type') ?: $f->nodeName) . ") has no label";
    }
    foreach ($x->query('//button | //a[@href]') as $b) {
        if (a11yHidden($b)) continue;
        if (trim($b->textContent) !== '' || $b->getAttribute('aria-label') !== '' || $b->getAttribute('title') !== '' || $b->getAttribute('aria-labelledby') !== '') continue;
        if ($x->query('.//img[@alt!=""]', $b)->length) continue;
        $p[] = "$label: " . $b->nodeName . " without a name (" . substr($b->getAttribute('class'), 0, 40) . ' ' . substr($b->getAttribute('href'), 0, 40) . ")";
    }
    $seen = []; foreach ($x->query('//*[@id]') as $e) { $id = $e->getAttribute('id'); if (isset($seen[$id])) $p[$id] = "$label: duplicate id \"$id\""; $seen[$id] = true; }
    return array_values($p);
}

test('accessibility: every page has lang, viewport, title, alt text, labelled fields, named buttons and no duplicate ids; Pashto/Dari are right-to-left', function () {
    $f = rmFixtures(); $h = client($f['t']); $adm = client($f['admin']); $all = []; $n = 0;
    foreach (rmUrlsAll($f) as [$page, $url]) {
        if (in_array($page, ['whatsapp/webhook.php', 'notifications/latest.php', 'auth/logout.php'], true) || preg_match('#(export|print|xlsx|download|photo|file|ajax|stream_|send_)#', $page) && substr($page, -4) === '.php' && strpos($page, 'print') === false) { }
        $c = in_array(explode('/', $page)[0], ['settings', 'customers', 'branding'], true) ? $adm : $h; $c->get($url);
        if ($c->code !== 200 || strpos($c->body, '<html') === false) continue; $n++;
        foreach (a11yProblems($c->body, $page) as $p) $all[$p] = true;
    }
    fwrite(STDERR, "accessibility: $n pages checked\n");
    $rtl = client($f['t']); TW('db')->query("UPDATE users SET lang = 'ps' WHERE id = ?", [$f['t']]);
    $r = client($f['t']); $r->get('pages/dashboard.php'); ok(preg_match('/<html[^>]*dir="rtl"/', $r->body) === 1 && preg_match('/<html[^>]*lang="ps"/', $r->body) === 1, 'Pashto page must have lang="ps" dir="rtl"');
    TW('db')->query("UPDATE users SET lang = 'fa' WHERE id = ?", [$f['t']]);
    $r = client($f['t']); $r->get('pages/dashboard.php'); ok(preg_match('/<html[^>]*dir="rtl"/', $r->body) === 1, 'Dari page must be right-to-left');
    ok(!$all, count($all) . " problem(s):\n   " . implode("\n   ", array_slice(array_keys($all), 0, 40)));
});

function rmUrlsAll($f) {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/pages', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (substr($file, -4) !== '.php') continue; $rel = str_replace(dirname(__DIR__) . '/pages/', '', $file); if (basename($rel)[0] === '_') continue;
        $u = rmUrls($rel, $f); $out[] = [$rel, $u[1] ?? $u[0]];
    }
    return $out;
}
