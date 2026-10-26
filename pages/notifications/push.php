<?php
/**
 * Phone notifications — POST action = subscribe | unsubscribe | test, endpoint (the browser's push address). CSRF via X-CSRF-Token.
 * JSON answer. Only the signed-in person's own subscriptions are touched. See includes/push.php.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$out = function ($ok, $msg = '', $code = 200, $extra = []) { http_response_code($code); echo json_encode(['ok' => $ok, 'message' => t($msg)] + $extra, JSON_UNESCAPED_UNICODE); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(false, 'Not allowed.', 405);
if (!verifyCsrf()) $out(false, 'Your session expired. Reload the page.', 403);
if (!pushEnabled()) $out(false, 'Phone notifications are switched off for this school.', 409);

$action = $_POST['action'] ?? '';
$endpoint = (string) ($_POST['endpoint'] ?? '');
if ($action === 'subscribe') {
    if (!pushAllowedEndpoint($endpoint)) $out(false, 'This browser\'s push service is not supported.', 422);
    $count = (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?", [$current_user_id])['c'];
    $hash = hash('sha256', $endpoint);
    $mine = $db->fetchOne("SELECT id FROM push_subscriptions WHERE endpoint_hash = ?", [$hash]);
    if (!$mine && $count >= 5) $db->query("DELETE FROM push_subscriptions WHERE user_id = ? ORDER BY COALESCE(last_ok_at, created_at) ASC LIMIT 1", [$current_user_id]);   // at most 5 phones/browsers each
    // one browser belongs to one person: signing in as someone else on it moves it
    $db->query("INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), fail_count = 0", [$current_user_id, $hash, $endpoint]);
    logActivity('push_on', 'profile', 'Phone notifications turned on');
    $out(true, 'Phone notifications are on.');
}
if ($action === 'unsubscribe') {
    $db->query("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?", [$current_user_id, hash('sha256', $endpoint)]);
    logActivity('push_off', 'profile', 'Phone notifications turned off');
    $out(true, 'Phone notifications are off.');
}
if ($action === 'test') {
    [$sent, $failed] = pushSendNow([$current_user_id]);
    $out($sent > 0, $sent > 0 ? 'Sent. It should appear on your phone in a moment.' : 'Nothing could be sent. Turn phone notifications off and on again on this device.', $sent > 0 ? 200 : 409, ['sent' => $sent, 'failed' => $failed]);
}
$out(false, 'Unknown action.', 400);
