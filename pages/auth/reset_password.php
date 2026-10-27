<?php
/**
 * Choose a new password — opened from the emailed link: ?token=<64 hex>.
 * Opening the link changes nothing; the password changes only when the form is sent.
 * A used / expired / unknown link shows one message. The token never leaves this page (no
 * referrer is sent) and is only stored as a hash in the database.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = findPasswordReset($token);
$errors = ['password' => '', 'confirm_password' => ''];
$task_result = null;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please try again.'];
    } else {
        if ($password === '') $errors['password'] = 'Password is required';
        if ($confirm === '') $errors['confirm_password'] = 'Please confirm your password';
        elseif ($password !== '' && $password !== $confirm) $errors['confirm_password'] = 'Passwords do not match';
        if (!array_filter($errors)) {
            $err = completePasswordReset($token, $password);
            if ($err === null) {
                Session::flash('login_msg', ['success', 'Password changed', 'Sign in with your new password.']);
                header('Location: ' . APP_URL . '/pages/auth/login.php');
                exit;
            }
            if ($err === 'expired') $reset = null;
            else $errors['password'] = $err;
        }
    }
}
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Choose a new password - <?php echo APP_NAME; ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg">
    <link href="<?php echo asset('assets/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?><?php echo i18nLiveScripts(); ?>
</head>
<body>
    <div class="auth-wrap">
        <div class="register-card">
            <div class="register-header">
                <i class="fas fa-lock fa-3x mb-3"></i>
                <h3>Choose a new password</h3>
                <p class="mb-0"><?php echo $reset ? htmlspecialchars($reset['email']) : 'This link cannot be used'; ?></p>
            </div>
            <div class="register-body">
                <?php if (!$reset): ?>
                <div class="alert alert-warning small mb-3">This reset link has expired, was already used, or is not valid. Ask for a new one.</div>
                <a href="forgot_password.php" class="btn btn-primary btn-register w-100"><i class="fas fa-paper-plane me-2"></i> Get a new link</a>
                <?php else: ?>
                <form method="POST" id="resetForm" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <div class="form-floating mb-3 password-field">
                        <input type="password" class="form-control <?php echo $errors['password'] ? 'is-invalid' : ''; ?>" id="password" name="password"
                            placeholder="New password" autocomplete="new-password" autofocus>
                        <label for="password">New password</label>
                        <button type="button" class="password-toggle" data-target="password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div>
                    </div>
                    <div class="form-floating mb-3 password-field">
                        <input type="password" class="form-control <?php echo $errors['confirm_password'] ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password"
                            placeholder="Confirm password" autocomplete="new-password">
                        <label for="confirm_password">Confirm password</label>
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                    </div>
                    <small class="text-muted d-block mb-3">At least <?php echo passwordMinLength(); ?> characters.</small>
                    <button type="submit" class="btn btn-primary btn-register w-100"><i class="fas fa-check me-2"></i> Change password</button>
                </form>
                <?php endif; ?>
                <div class="login-link"><a href="login.php" class="btn btn-link"><i class="fas fa-arrow-left me-1"></i> Back to sign in</a></div>
            </div>
        </div>
    </div>
    <script src="<?php echo asset('assets/vendor/sweetalert2/sweetalert2.all.min.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($task_result): ?>
        Swal.fire({ icon: <?php echo json_encode($task_result[0]); ?>, titleText: <?php echo json_encode($task_result[1]); ?>, text: <?php echo json_encode($task_result[2]); ?>, confirmButtonColor: '#4361ee' });
        <?php endif; ?>
        const f = document.getElementById('resetForm');
        if (f) {
            bindLiveClear(f);
            f.addEventListener('submit', function (e) {
                const p = document.getElementById('password'), c = document.getElementById('confirm_password');
                let ok = true;
                if (!p.value) { setFieldError(p, 'Password is required'); ok = false; }
                else if (p.value.length < <?php echo passwordMinLength(); ?>) { setFieldError(p, 'Password must be at least <?php echo passwordMinLength(); ?> characters'); ok = false; }
                if (!c.value) { setFieldError(c, 'Please confirm your password'); ok = false; }
                else if (p.value && c.value !== p.value) { setFieldError(c, 'Passwords do not match'); ok = false; }
                if (!ok) e.preventDefault();
            });
        }
    });
    </script>
</body>
</html>
