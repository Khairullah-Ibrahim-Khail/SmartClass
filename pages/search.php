<?php
/** Top-bar search — GET q (2+ letters) → JSON {ok, q, groups}. Needs sign-in; 60 searches a minute per person; see includes/search.php for what is searched. */
require_once dirname(__DIR__) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'q' => $q, 'groups' => []]); exit; }
$g = rateGuard('search_user', (string) $current_user_id, 60, 60);
if (!$g['ok']) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'Too many searches. Please wait a moment.']); exit; }
$lang = i18nLang();
$groups = searchEverything($q, $current_user_id, $current_user_role);
if ($lang !== 'en') foreach ($groups as &$gr) { $t = i18nTranslateText($gr['label'], $lang); if ($t !== null) $gr['label'] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'); } unset($gr);
echo json_encode(['ok' => true, 'q' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
