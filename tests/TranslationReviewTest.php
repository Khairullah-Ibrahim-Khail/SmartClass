<?php
test('translation review: the csv export holds every phrase; a corrected row is loaded back into the overrides file and wins; broken rows are refused', function () {
    $root = dirname(__DIR__); $tmp = sys_get_temp_dir() . '/trv_' . bin2hex(random_bytes(3)); mkdir($tmp);
    $csv = "$tmp/review.csv"; $ov = "$tmp/overrides.php"; $env = 'SC_LANG_OVERRIDES=' . escapeshellarg($ov) . ' ';
    exec("php $root/bin/export_translations.php " . escapeshellarg($csv) . ' 2>&1', $o, $rc); eq(0, $rc, implode("\n", $o));
    $rows = array_map(fn($l) => str_getcsv($l, ',', '"', ''), file($csv, FILE_IGNORE_NEW_LINES)); $rows[0][0] = ltrim($rows[0][0], "\xEF\xBB\xBF");
    eq(['kind', 'english', 'pashto', 'dari', 'note'], $rows[0]); ok(count($rows) > 2500, 'every phrase, piece and pattern: ' . count($rows));
    $i = array_search('Dashboard', array_column($rows, 1)); eq('phrase', $rows[$i][0]); eq('ډشبورډ', $rows[$i][2]);
    $p = null; foreach ($rows as $k => $r) if ($r[0] === 'pattern' && strpos($r[1], 'Remove') !== false) { $p = $k; break; } ok($p !== null);
    $rows[$i][2] = 'ډېشبورډ'; $rows[$i][3] = 'پیشخوان';                                  // a correction
    $badPattern = $rows[$p]; $rows[$p][2] = 'لرې کړئ';                                      // the {…} marker was lost → refused
    $f = fopen($csv, 'w'); foreach ($rows as $r) fputcsv($f, $r, ',', '"', ''); fclose($f);
    exec($env . "php $root/bin/import_translations.php " . escapeshellarg($csv) . ' --dry 2>&1', $o2); contains('1 correction(s) found (dry run', implode("\n", $o2)); ok(!is_file($ov), 'a dry run writes nothing'); contains('a {…} marker is missing', implode("\n", $o2));
    exec($env . "php $root/bin/import_translations.php " . escapeshellarg($csv) . ' 2>&1', $o3, $rc3); eq(0, $rc3); ok(is_file($ov));
    $now = trim((string) shell_exec($env . "php -r " . escapeshellarg('require "' . $root . '/includes/i18n.php"; echo i18nTranslateString("Dashboard", "ps"), "|", i18nTranslateString("Dashboard", "fa"), "|", i18nTranslateString("Home", "ps");')));
    eq('ډېشبورډ|پیشخوان|کور', $now, 'the correction wins; everything else is unchanged');
    $again = shell_exec($env . "php $root/bin/import_translations.php " . escapeshellarg($csv) . ' 2>&1'); contains('0 correction(s)', $again, 'importing the same file twice changes nothing');
    $rows[$i][2] = 'ډشبورډ'; $rows[$i][3] = 'داشبورد'; $f = fopen($csv, 'w'); foreach ($rows as $r) fputcsv($f, $r, ',', '"', ''); fclose($f);
    shell_exec($env . "php $root/bin/import_translations.php " . escapeshellarg($csv) . ' 2>&1'); $back = trim((string) shell_exec($env . "php -r " . escapeshellarg('require "' . $root . '/includes/i18n.php"; echo i18nTranslateString("Dashboard", "ps");')));
    eq('ډشبورډ', $back, 'putting the original wording back removes the override');
    foreach (glob("$tmp/*") as $x) @unlink($x); @rmdir($tmp);
});
