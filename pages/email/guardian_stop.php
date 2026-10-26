<?php
/**
 * A parent / guardian stops the school's e-mails about their child (link at the bottom of every guardian e-mail).
 * Public page (no sign-in): the link carries a signed token (HMAC of the student id and the address). GET shows the
 * question; POST (CSRF-free by design — the signed token is the proof) switches the alerts off for that address.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$sid = (int) ($_GET['s'] ?? $_POST['s'] ?? 0);
$tok = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$st = $sid ? $db->fetchOne("SELECT id, name, guardian_email FROM users WHERE id = ? AND role = 'student'", [$sid]) : null;
$valid = $st && $st['guardian_email'] && hash_equals(guardianStopToken($st['id'], $st['guardian_email']), $tok);
$done = false;
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->query("UPDATE users SET guardian_alerts = 0 WHERE id = ?", [$st['id']]);
    $db->query("UPDATE email_queue SET status = 'failed', last_error = 'guardian stopped' WHERE to_email = ? AND category = 'guardian' AND status IN ('pending', 'held')", [$st['guardian_email']]);
    logActivity('guardian_stop', 'email', 'A guardian stopped the e-mails', $st['id']);
    $done = true;
}
?>
<?php i18nStart(); ?><!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Stop e-mails - <?php echo htmlspecialchars(APP_NAME); ?></title>
<style>body{font-family:Arial,Helvetica,sans-serif;background:#f4f6fb;margin:0;padding:40px 16px;color:#172033}.box{max-width:460px;margin:0 auto;background:#fff;border-radius:14px;padding:30px;box-shadow:0 4px 24px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 12px}button{background:#4361ee;color:#fff;border:0;border-radius:8px;padding:11px 20px;font-size:15px;cursor:pointer}</style></head>
<body><div class="box">
<?php if ($done): ?><h1>Done</h1><p>You will no longer get e-mails about <?php echo htmlspecialchars($st['name']); ?> from <?php echo htmlspecialchars(APP_NAME); ?>.</p>
<?php elseif ($valid): ?><h1>Stop these e-mails?</h1><p>You get e-mails when <?php echo htmlspecialchars($st['name']); ?> is marked absent. Stop them?</p>
<form method="POST"><input type="hidden" name="s" value="<?php echo (int) $st['id']; ?>"><input type="hidden" name="t" value="<?php echo htmlspecialchars($tok); ?>"><button type="submit">Yes, stop the e-mails</button></form>
<?php else: ?><h1>This link is not valid</h1><p>It may be old, or the contact was changed. Please ask the school.</p><?php endif; ?>
</div></body></html>
