<?php
// Export every Pashto / Dari phrase to ONE csv file a native speaker can correct in a spreadsheet:
//   php bin/export_translations.php [file]        (default: docs/translations_review.csv)
// Columns: kind (phrase | piece | pattern), english (the key — do not change it), pashto, dari, note.
//   phrase   a whole text on the screen        piece   part of a sentence that has names / numbers in it
//   pattern  a sentence pattern: the English column is a regular expression, {1} {2} … stand for the parts that are kept as written,
//            {~1} means "that part, translated too if it is a known phrase". Keep the {…} markers in the Pashto / Dari text.
// Edit only the pashto / dari columns, save as CSV (UTF-8), then:  php bin/import_translations.php file.csv
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/i18n.php';
$out = $argv[1] ?? dirname(__DIR__) . '/docs/translations_review.csv';
$o = i18nOverrides();
$rows = [];
foreach (i18nStrings() as $en => $tr) $rows[] = ['phrase', $en, $tr[0] ?? '', $tr[1] ?? '', ''];
foreach (array_merge(require dirname(__DIR__) . '/includes/lang/fragments.php', $o['fragments']) as $en => $tr) $rows[] = ['piece', $en, $tr[0] ?? '', $tr[1] ?? '', 'a piece of a longer sentence'];
foreach (require dirname(__DIR__) . '/includes/lang/rules.php' as $r) { $ov = $o['rules'][$r[0]] ?? null; $rows[] = ['pattern', $r[0], $ov[0] ?? $r[1], $ov[1] ?? $r[2], 'regular expression — keep the {…} markers']; }
$f = fopen($out, 'w'); fwrite($f, "\xEF\xBB\xBF");   // the BOM makes Excel / LibreOffice read the file as UTF-8
fputcsv($f, ['kind', 'english', 'pashto', 'dari', 'note'], ',', '"', '');
foreach ($rows as $r) fputcsv($f, $r, ',', '"', '');
fclose($f);
echo count($rows) . " rows written to $out\n";
