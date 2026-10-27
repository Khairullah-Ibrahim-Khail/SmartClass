<?php
/**
 * One-click unsubscribe from email notifications — ?u=<user id>&t=<email token>
 * Works without logging in (the token in the email is the secret). GET shows a
 * confirmation; POST turns email off (in-app notifications stay on). Mail scanners
 * that open links therefore never unsubscribe anyone by accident.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$uid = (int) ($_GET['u'] ?? $_POST['u'] ?? 0);
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$account = $uid ? $db->fetchOne("SELECT id, name, email, email_token FROM users WHERE id = ?", [$uid]) : null;
$valid = $account && !empty($account['email_token']) && preg_match('/^[0-9a-f]{48}$/', $token) && hash_equals($account['email_token'], $token);
$done = false;

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db->query(
        "INSERT INTO email_preferences (user_id, mode) VALUES (?, 'off') ON DUPLICATE KEY UPDATE mode = 'off'",
        [$account['id']]
    );
    logActivity('email_unsubscribe', 'settings', 'Unsubscribed from email notifications', $account['id']);
    $done = true;
}
?>
<?php i18nStart(); ?><!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email notifications - <?php echo APP_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo asset('assets/img/favicon.svg'); ?>">
    <link href="<?php echo asset('assets/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?>
</head>
<body>
    <div class="auth-wrap">
        <div class="pending-card">
            <div class="pending-header">
                <i class="fas <?php echo $done ? 'fa-bell-slash' : 'fa-envelope'; ?> icon-clock"></i>
                <h3><?php echo $done ? 'Unsubscribed' : 'Email notifications'; ?></h3>
            </div>
            <div class="pending-body">
                <?php if (!$valid): ?>
                <p class="text-muted mb-4">This unsubscribe link is not valid. Log in and change your email preferences on your profile page instead.</p>
                <a href="<?php echo APP_URL; ?>/pages/settings/notifications.php" class="btn btn-primary">Open my profile</a>
                <?php elseif ($done): ?>
                <p class="text-muted mb-4">You will no longer get email notifications at <strong><?php echo htmlspecialchars($account['email']); ?></strong>.
                    You still see everything in <?php echo APP_NAME; ?>. You can turn emails back on in your profile.</p>
                <a href="<?php echo APP_URL; ?>/pages/settings/notifications.php" class="btn btn-outline-primary">Email preferences</a>
                <?php else: ?>
                <p class="text-muted mb-4">Stop all email notifications to <strong><?php echo htmlspecialchars($account['email']); ?></strong>?
                    In-app notifications stay on.</p>
                <form method="POST">
                    <input type="hidden" name="u" value="<?php echo (int) $account['id']; ?>">
                    <input type="hidden" name="t" value="<?php echo htmlspecialchars($token); ?>">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-bell-slash me-2"></i> Unsubscribe</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
