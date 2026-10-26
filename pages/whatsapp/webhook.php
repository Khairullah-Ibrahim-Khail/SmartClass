<?php
/**
 * WhatsApp webhook (Meta calls this; no sign-in). GET = Meta's check: answer hub.challenge when hub.verify_token is ours.
 * POST = incoming events, signed with the app secret (X-Hub-Signature-256): a message that is a stop word withdraws that number's agreement.
 * Always answers fast and says nothing about people.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tok = waSetting('wa_webhook_token');
    if ($tok !== '' && ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '') === 'subscribe' && hash_equals($tok, (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''))) {
        header('Content-Type: text/plain'); echo (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? ''); exit;
    }
    http_response_code(403); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !waOn()) { http_response_code(204); exit; }
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 200000 || !waVerifySignature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')) { http_response_code(403); exit; }
$j = json_decode($raw, true);
foreach ((array) ($j['entry'] ?? []) as $en) foreach ((array) ($en['changes'] ?? []) as $ch) foreach ((array) ($ch['value']['messages'] ?? []) as $m) {
    if (($m['type'] ?? '') === 'text' && !empty($m['from'])) waHandleInbound((string) $m['from'], (string) ($m['text']['body'] ?? ''));
}
http_response_code(200); echo 'ok';
