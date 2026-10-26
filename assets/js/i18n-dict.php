<?php
/**
 * The phrase list of one interface language as JavaScript (window.SC_DICT), used by i18n-live.js to translate texts the browser builds itself
 * (pop-up messages, inline errors, toasts). Public: it holds only interface wording. Cached by the browser; the address carries a version.
 */
require_once dirname(__DIR__, 2) . '/includes/i18n.php';
$lang = $_GET['lang'] ?? '';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=86400');
if (!in_array($lang, ['ps', 'fa'], true)) { echo 'window.SC_DICT={};'; exit; }
$i = $lang === 'ps' ? 0 : 1;
$d = [];
foreach (i18nStrings() as $en => $tr) if (($tr[$i] ?? '') !== '') $d[$en] = $tr[$i];
echo 'window.SC_DICT=', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ';';
