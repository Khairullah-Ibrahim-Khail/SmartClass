<?php
/**
 * Forgot password — type the account email, get a one-time link by email.
 * The page answers the same way whether or not the email belongs to an account, so nobody
 * can test which emails exist. Needs email switched on (Settings › Email Settings).
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}
$available = passwordResetAvailable();
$email_error = '';
$email_value = '';
$sent = false;
$task_result = null;

if ($available && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please try again.'];
    } else {
        $email_value = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email_value === '') {
            $email_error = 'Email is required';
        } elseif (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
            $email_error = 'Please enter a valid email address';
        } else {
            // 5 requests per hour for one e-mail, 20 per hour from one address; over the limit the page looks the same but nothing is sent
            $g1 = rateGuard('reset_account', $email_value, 5, 3600); $g2 = rateGuard('reset_ip', clientIp(), 20, 3600);
            if ($g1['ok'] && $g2['ok']) requestPasswordReset($email_value);   // result deliberately not shown
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot password - <?php echo APP_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;600;700&display=swap" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?><?php echo i18nLiveScripts(); ?>
</head>
<body>
    <div class="auth-wrap">
        <div class="register-card">
            <div class="register-header">
                <i class="fas fa-key fa-3x mb-3"></i>
                <h3>Forgot your password?</h3>
                <p class="mb-0">We will email you a link to choose a new one</p>
            </div>
            <div class="register-body">
                <?php if (!$available): ?>
                <div class="alert alert-warning small mb-3">Email is not switched on for this school, so a reset link cannot be sent.
                    Ask your school administrator to reset your password.</div>
                <?php elseif ($sent): ?>
                <div class="alert alert-success small mb-3">If <strong><?php echo htmlspecialchars($email_value); ?></strong> belongs to an account, we sent a link to it.
                    It works once and expires in <?php echo PASSWORD_RESET_MINUTES; ?> minutes — check the spam folder too.</div>
                <?php else: ?>
                <form method="POST" id="forgotForm" novalidate>
                    <?php echo csrfField(); ?>
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control <?php echo $email_error ? 'is-invalid' : ''; ?>" id="email" name="email"
                            placeholder="Email address" autocomplete="email" value="<?php echo htmlspecialchars($email_value); ?>" autofocus>
                        <label for="email">Your email address</label>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($email_error); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-register w-100"><i class="fas fa-paper-plane me-2"></i> Email me a reset link</button>
                </form>
                <?php endif; ?>
                <div class="login-link"><a href="login.php" class="btn btn-link"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a></div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($task_result): ?>
        Swal.fire({ icon: <?php echo json_encode($task_result[0]); ?>, titleText: <?php echo json_encode($task_result[1]); ?>, text: <?php echo json_encode($task_result[2]); ?>, confirmButtonColor: '#4361ee' });
        <?php endif; ?>
        const f = document.getElementById('forgotForm');
        if (f) {
            bindLiveClear(f);
            f.addEventListener('submit', function (e) {
                const m = document.getElementById('email');
                if (!m.value.trim()) { setFieldError(m, 'Email is required'); e.preventDefault(); }
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m.value.trim())) { setFieldError(m, 'Please enter a valid email address'); e.preventDefault(); }
            });
        }
    });
    </script>
</body>
</html>
