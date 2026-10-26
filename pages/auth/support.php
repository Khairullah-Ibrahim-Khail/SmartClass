<?php
/**
 * Provider support access (customer installations only): ?t=<payload>.<signature> made on the provider's Customers page.
 * The link is signed with this customer's support secret, opens within SUPPORT_LINK_MINUTES, works once, and signs in as the
 * installation's super admin for SUPPORT_SESSION_MINUTES. Every use is written to this installation's activity log, the admin
 * is notified, and a banner is shown for the whole session. Anything else is refused without saying why.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

function supportRefuse() {
    http_response_code(404);
    echo 'Not found';
    exit;
}
if (SC_IS_MASTER) supportRefuse();

// the provider's waiting page asks here: ?poll=<token> → JSON status; ?go=<token> → signs in once, after the administrator approved
if (isset($_GET['poll'])) {
    $r = supportRequestGet((string) $_GET['poll']); if (!$r) supportRefuse();
    header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode(['status' => $r['status']]); exit;
}
if (isset($_GET['go'])) {
    $r = supportRequestUse((string) $_GET['go']); if (!$r) supportRefuse();
    $admin = $db->fetchOne("SELECT * FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1 ORDER BY id LIMIT 1"); if (!$admin) supportRefuse();
    $until = time() + SUPPORT_SESSION_MINUTES * 60;
    startUserSession($admin, 'support'); $_SESSION['support_until'] = $until; $_SESSION['support_reason'] = (string) $r['reason'];
    logActivity('support_access', 'settings', 'Provider support access (approved by the administrator) until ' . date('H:i', $until) . ' — ' . $r['reason'], $admin['id']);
    notify($admin['id'], 'support_access', 'Provider support access', 'You approved it. The provider is in your admin side until ' . date('H:i', $until) . ': ' . $r['reason'], 'pages/dashboard.php');
    redirect(APP_URL . '/pages/dashboard.php');
}
$secret = (string) ($GLOBALS['sc_inst']['support_secret'] ?? '');
$raw = (string) ($_GET['t'] ?? '');
if ($secret === '' || substr_count($raw, '.') !== 1) supportRefuse();
[$payload, $sig] = explode('.', $raw);
if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) supportRefuse();
$p = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
if (!is_array($p) || ($p['s'] ?? '') !== SC_INSTANCE || (int) ($p['e'] ?? 0) < time() || !preg_match('/^[a-f0-9]{16}$/', (string) ($p['n'] ?? ''))) supportRefuse();

// one use only: the nonce is recorded; a second attempt finds it and fails
$db->query("DELETE FROM app_settings WHERE setting_key LIKE 'support_nonce_%' AND updated_at < NOW() - INTERVAL 1 DAY");
try {
    $db->query("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)", ['support_nonce_' . $p['n'], date('c')]);
} catch (PDOException $e) {
    supportRefuse();
}
$admin = $db->fetchOne("SELECT * FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1 ORDER BY id LIMIT 1");
if (!$admin) supportRefuse();

$reason = mb_substr((string) ($p['r'] ?? ''), 0, 200);
if (supportApprovalOn()) {   // the administrator decides first
    $tok = supportRequestCreate($reason);
    notify($admin['id'], 'support_request', 'Provider asks for support access', 'Reason: ' . $reason . ' — approve or deny it in Settings › Privacy & data (valid ' . SUPPORT_REQUEST_MINUTES . ' minutes).', 'pages/settings/privacy.php#support');
    logActivity('support_request', 'settings', 'Provider asked for support access — ' . $reason, $admin['id']);
    $self = htmlspecialchars(APP_URL . '/pages/auth/support.php', ENT_QUOTES);
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Waiting for approval</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f4f6fb;color:#172033;display:grid;place-items:center;min-height:100vh;margin:0}.b{background:#fff;border-radius:14px;padding:28px;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,.08);text-align:center}</style></head><body><div class="b">'
        . '<h1 style="font-size:20px;margin:0 0 8px">Waiting for the administrator</h1><p id="m">The administrator of this school must approve your support access first. They were told just now. This page signs you in as soon as they approve (valid ' . SUPPORT_REQUEST_MINUTES . ' minutes).</p></div>'
        . '<script>var t=' . json_encode($tok) . ',u="' . $self . '";function ask(){fetch(u+"?poll="+t,{cache:"no-store"}).then(function(r){return r.json()}).then(function(j){'
        . 'if(j.status==="approved"){location.href=u+"?go="+t;return}'
        . 'if(j.status==="denied"){document.getElementById("m").textContent="The administrator denied the request."}'
        . 'else if(j.status==="expired"||j.status==="used"){document.getElementById("m").textContent="This request is over. Ask for a new support link."}'
        . 'else setTimeout(ask,3000)}).catch(function(){setTimeout(ask,5000)})}ask();</script></body></html>';
    exit;
}
$until = time() + SUPPORT_SESSION_MINUTES * 60;
startUserSession($admin, 'support');   // the provider's logged support link signs in directly (no second step)
$_SESSION['support_until'] = $until;
$_SESSION['support_reason'] = $reason;
logActivity('support_access', 'settings', 'Provider support access until ' . date('H:i', $until) . ' — ' . $reason, $admin['id']);
notify($admin['id'], 'support_access', 'Provider support access', 'The provider opened your admin side for support until ' . date('H:i', $until) . ': ' . $reason, 'pages/dashboard.php');
redirect(APP_URL . '/pages/dashboard.php');
