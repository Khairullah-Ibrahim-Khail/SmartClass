<?php
/**
 * Provider support access that the customer's administrator can approve first.
 * With Settings › Privacy & data › "Support needs my approval" on, a valid support link (pages/auth/support.php) does NOT sign the provider in:
 * it files a request (kept 10 minutes, one use), tells the administrator, and the provider waits on a page that asks every few seconds.
 * Only when the administrator presses Approve does the next call sign the provider in — once. Deny or silence = nobody gets in.
 * Requests live in app_settings under support_req_<token> (JSON), so no table is needed.
 */
const SUPPORT_REQUEST_MINUTES = 10;

function supportApprovalOn() { return getSetting('support_approval', '0') === '1'; }

function supportRequestCreate($reason) {
    $tok = bin2hex(random_bytes(16));
    setSetting('support_req_' . $tok, json_encode(['status' => 'pending', 'reason' => mb_substr((string) $reason, 0, 200), 'asked' => time(), 'exp' => time() + SUPPORT_REQUEST_MINUTES * 60]));
    return $tok;
}

/** ['status' => pending|approved|denied|used|expired, 'reason' => …, 'asked' => ts] or null when there is no such request. */
function supportRequestGet($tok) {
    if (!preg_match('/^[a-f0-9]{32}$/', (string) $tok)) return null;
    $raw = getSetting('support_req_' . $tok, null);
    $r = $raw ? json_decode($raw, true) : null;
    if (!is_array($r)) return null;
    if (in_array($r['status'], ['pending', 'approved'], true) && (int) $r['exp'] < time()) $r['status'] = 'expired';
    return $r;
}

/** The administrator's decision. Only a pending, not-expired request can be decided. */
function supportRequestDecide($tok, $approve, $adminId) {
    $r = supportRequestGet($tok);
    if (!$r || $r['status'] !== 'pending') return false;
    $r['status'] = $approve ? 'approved' : 'denied'; $r['by'] = (int) $adminId; $r['at'] = time();
    setSetting('support_req_' . $tok, json_encode($r), $adminId);
    return true;
}

/** The provider's side: an approved request can be turned into a session exactly once. */
function supportRequestUse($tok) {
    $r = supportRequestGet($tok);
    if (!$r || $r['status'] !== 'approved') return null;
    $r['status'] = 'used'; setSetting('support_req_' . $tok, json_encode($r));
    return $r;
}

/** Requests waiting for the administrator (newest first). */
function supportPending() {
    global $db; $out = [];
    foreach ($db->fetchAll("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'support\\_req\\_%'") as $row) {
        $tok = substr($row['setting_key'], 12); $r = supportRequestGet($tok);
        if ($r && $r['status'] === 'pending') $out[] = ['token' => $tok] + $r;
    }
    usort($out, fn($a, $b) => $b['asked'] <=> $a['asked']);
    return $out;
}

/** Old requests are removed (called from the nightly job). */
function supportRequestsClean() {
    global $db;
    $db->query("DELETE FROM app_settings WHERE setting_key LIKE 'support\\_req\\_%' AND updated_at < NOW() - INTERVAL 1 DAY");
}
