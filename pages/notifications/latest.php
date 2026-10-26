<?php
/**
 * The newest unread notification of the signed-in person, as JSON — asked by the service worker (sw.js) when a phone
 * notification arrives (the push itself carries no text). Signed out → the sign-in page (the worker then shows a generic line).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$n = $db->fetchOne("SELECT id, title, message FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 1", [$current_user_id]);
$unread = countUnreadNotifications($current_user_id);
if (!$n) { echo json_encode(['unread' => $unread]); exit; }
$lang = i18nLang();
$tr = function ($s) use ($lang) {   // the screen language; text with no translation stays as written (i18nTranslateText answers HTML-escaped text or null)
    if ($lang === 'en') return $s;
    $t = i18nTranslateText($s, $lang);
    return $t === null ? $s : html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};
$cut = fn($s, $n) => mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
echo json_encode([
    'id' => (int) $n['id'], 'unread' => $unread,
    'title' => $cut($tr((string) $n['title']), 80),
    'message' => $cut($tr((string) $n['message']), 160),
    'url' => APP_URL . '/pages/notifications/open.php?id=' . (int) $n['id'],
], JSON_UNESCAPED_UNICODE);
