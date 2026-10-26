<?php
/**
 * Second step of sign-in: the 6-digit code of the authenticator app (or a one-time backup code).
 * Reached only from startUserSession() after the password / e-mail code was right; nobody is signed in until this succeeds.
 * 5 wrong tries or 10 minutes end the attempt (back to the sign-in page). CSRF; the code works once.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari
require_once dirname(__DIR__, 2) . '/includes/login_codes.php';

$p = $_SESSION['two_factor'] ?? null;
if (isset($_SESSION['user_id'])) { header('Location: ' . APP_URL . '/pages/dashboard.php'); exit; }
if (!$p || time() - (int) $p['at'] > 600) { unset($_SESSION['two_factor']); header('Location: ' . APP_URL . '/pages/auth/login.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Your session expired. Please sign in again.';
        unset($_SESSION['two_factor']);
    } elseif (twoFactorVerify($p['uid'], $_POST['code'] ?? '')) {
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1", [$p['uid']]);
        unset($_SESSION['two_factor']);
        if ($user) {
            startUserSession($user, $p['method'], true);
            $to = (string) ($_SESSION['redirect_after_login'] ?? '');
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . (preg_match('#^/[A-Za-z0-9_./?=&%-]*$#', $to) && strpos($to, '//') === false ? $to : APP_URL . '/pages/dashboard.php'));
            exit;
        }
        header('Location: ' . APP_URL . '/pages/auth/login.php'); exit;
    } else {
        $_SESSION['two_factor']['tries']++;
        logActivity('login_failed', 'auth', 'Wrong two-step code', $p['uid']);
        if ($_SESSION['two_factor']['tries'] >= 5) {
            unset($_SESSION['two_factor']);
            Session::flash('login_msg', ['error', 'Too many wrong codes', 'Please sign in again.']);
            header('Location: ' . APP_URL . '/pages/auth/login.php'); exit;
        }
        $error = 'That code is not right. Try the current code from your app, or a backup code.';
    }
    if (!isset($_SESSION['two_factor'])) { header('Location: ' . APP_URL . '/pages/auth/login.php'); exit; }
}
$page_title = 'Two-step sign-in';
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;600;700&display=swap" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?><?php echo i18nLiveScripts(); ?>
</head>
<body>
<div class="auth-wrap"><div class="login-card">
    <div class="login-header"><i class="fas fa-shield-halved"></i><h2>Two-step sign-in</h2><p class="mb-0 mt-2 opacity-75">Enter the code from your authenticator app</p></div>
    <div class="login-body">
        <form method="POST" novalidate>
            <?php echo csrfField(); ?>
            <div class="form-group mb-3">
                <label for="code" class="form-label">6-digit code <span class="text-muted" style="font-weight:400;">or a backup code</span></label>
                <input type="text" id="code" name="code" class="form-control form-control-lg<?php echo $error ? ' is-invalid' : ''; ?>" inputmode="numeric" autocomplete="one-time-code" maxlength="9" autofocus required style="letter-spacing:.2em;text-align:center;">
                <div class="invalid-feedback"><?php echo htmlspecialchars($error); ?></div>
            </div>
            <button type="submit" class="btn btn-primary btn-login w-100"><i class="fas fa-check me-2"></i> Verify and sign in</button>
        </form>
        <p class="mt-3 mb-0 text-center"><a href="<?php echo APP_URL; ?>/pages/auth/login.php">← Back to sign in</a></p>
    </div>
</div></div>
</body>
</html>
