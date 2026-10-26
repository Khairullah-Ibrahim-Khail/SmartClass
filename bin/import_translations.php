<?php
// Load corrections from the csv made by export_translations.php:   php bin/import_translations.php file.csv [--dry]
// Only rows whose Pashto / Dari text changed are kept, in includes/lang/overrides.php (the built-in files are never edited; the old overrides
// file is saved next to it as overrides.php.bak). A pattern row must keep every {…} marker of the original. Run php tests/run.php i18n afterwards.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/i18n.php';
$file = $argv[1] ?? ''; $dry = in_array('--dry', $argv, true);
if (!is_file($file)) { fwrite(STDERR, "Give the csv file: php bin/import_translations.php file.csv [--dry]\n"); exit(1); }
$base = array_merge(require dirname(__DIR__) . '/includes/lang/strings.php', require dirname(__DIR__) . '/includes/lang/strings_more.php');
$frag = require dirname(__DIR__) . '/includes/lang/fragments.php';
$rules = []; foreach (require dirname(__DIR__) . '/includes/lang/rules.php' as $r) $rules[$r[0]] = [$r[1], $r[2]];
$cur = i18nOverrides(); $new = ['strings' => $cur['strings'], 'fragments' => $cur['fragments'], 'rules' => $cur['rules']];
$f = fopen($file, 'r'); $head = fgetcsv($f, 0, ',', '"', ''); if ($head) $head[0] = ltrim($head[0], "\xEF\xBB\xBF");
if ($head !== ['kind', 'english', 'pashto', 'dari', 'note']) { fwrite(STDERR, "The first row must be: kind,english,pashto,dari,note\n"); exit(1); }
$changed = 0; $skipped = []; $line = 1;
while (($r = fgetcsv($f, 0, ',', '"', '')) !== false) {
    $line++; if (count($r) < 4) continue;
    [$kind, $en, $ps, $fa] = [trim($r[0]), $r[1], trim($r[2]), trim($r[3])];
    if ($en === '' || ($ps === '' && $fa === '')) continue;
    $src = $kind === 'phrase' ? ($base[$en] ?? null) : ($kind === 'piece' ? ($frag[$en] ?? null) : ($rules[$en] ?? null));
    $was = $kind === 'phrase' ? ($cur['strings'][$en] ?? $src) : ($kind === 'piece' ? ($cur['fragments'][$en] ?? $src) : ($cur['rules'][$en] ?? $src));
    if ($src === null) { $skipped[] = "line $line: unknown $kind (the English text was changed?)"; continue; }
    if ($ps === '' || $fa === '') { $skipped[] = "line $line: Pashto and Dari must both be filled"; continue; }
    if ($kind === 'pattern') { preg_match_all('/\{~?\d+\}/', $src[0] . $src[1], $a); foreach ([$ps, $fa] as $t) { preg_match_all('/\{~?\d+\}/', $t, $b); if (array_diff(array_unique(array_map(fn($x) => str_replace('~', '', $x), $a[0])), array_map(fn($x) => str_replace('~', '', $x), $b[0]))) { $skipped[] = "line $line: a {…} marker is missing"; continue 2; } } }
    if ([$ps, $fa] === [$was[0] ?? '', $was[1] ?? '']) continue;
    if ([$ps, $fa] === [$src[0] ?? '', $src[1] ?? '']) { unset($new[$kind === 'phrase' ? 'strings' : ($kind === 'piece' ? 'fragments' : 'rules')][$en]); $changed++; continue; }
    $new[$kind === 'phrase' ? 'strings' : ($kind === 'piece' ? 'fragments' : 'rules')][$en] = [$ps, $fa]; $changed++;
}
fclose($f);
foreach ($skipped as $s) echo "skipped — $s\n";
echo "$changed correction(s) " . ($dry ? 'found (dry run, nothing written)' : 'saved') . "\n";
if ($dry || !$changed) exit(0);
$path = getenv('SC_LANG_OVERRIDES') ?: dirname(__DIR__) . '/includes/lang/overrides.php';
if (is_file($path)) copy($path, $path . '.bak');
$head = "<?php\n/**\n * Corrections made by a native speaker (bin/import_translations.php writes this file). They win over strings.php / strings_more.php,\n * fragments.php and rules.php, so the source files never have to be edited by hand.\n */\nreturn ";
file_put_contents($path . '.tmp', $head . var_export($new, true) . ";\n"); rename($path . '.tmp', $path);
echo "Written: $path\n";
