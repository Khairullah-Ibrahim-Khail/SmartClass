<?php
/**
 * Login Page
 * User authentication
 * - Inline under-field validation (client + server)
 * - SweetAlert2 only for task-level results (login failed / logged out)
 */
// Include required files (defines APP_URL, starts the session, and configures
// error handling: display off, log on, error_log = logs/php-error.log)
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

// Invite link (pages/classes/join.php?code=...) opened while logged out: keep the code
// so "Register" pre-fills it and logging in goes back to the join page.
$invite_code = '';
if (preg_match('#/pages/classes/join\.php\?code=([A-Za-z0-9]{1,20})$#', (string) ($_SESSION['redirect_after_login'] ?? ''), $m)) {
    $invite_code = strtoupper($m[1]);
}

// Process login form
$error = '';
$field_errors = ['email' => '', 'password' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    // Task-level failure -> SweetAlert2
    $error = 'Your session expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Inline field validation (server side)
    if (empty($email)) {
        $field_errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Please enter a valid email address';
    }
    if (empty($password)) {
        $field_errors['password'] = 'Password is required';
    }

    // Too many wrong passwords for this e-mail (8 in 15 minutes) or from this address (40) — the same message whether or not the account exists
    [$lock_acc, $wait_acc] = rateBlocked('login_account', $email, 8, 900);
    [$lock_ip, $wait_ip] = rateBlocked('login_ip', clientIp(), 40, 900);
    if (!array_filter($field_errors) && ($lock_acc || $lock_ip)) {
        $error = 'Too many sign-in attempts. Please wait ' . rateWaitText(max($wait_acc, $wait_ip)) . ' and try again.';
        logActivity('login_blocked', 'auth', 'Sign-in blocked by the rate limit for ' . mb_substr($email, 0, 60), null);
    } elseif (!array_filter($field_errors)) {
        $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);

        if ($user && password_verify($password, $user['password'])) {
            rateReset('login_account', $email);
            startUserSession($user);   // fresh session id, session fields, last login, audit log

            // Back to the class invite if that is where they came from, else the dashboard
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . APP_URL . ($invite_code !== '' && $user['role'] === 'student'
                ? '/pages/classes/join.php?code=' . rawurlencode($invite_code)
                : '/pages/dashboard.php'));
            exit;
        } else {
            // Task-level failure -> SweetAlert2 (not an inline field error)
            rateHit('login_account', $email, 900); rateHit('login_ip', clientIp(), 900);
            $error = 'Invalid email or password';
        }
    }
}

$logged_out = ($_GET['message'] ?? '') === 'logged_out';
$flash_msg = Session::flash('login_msg');   // e.g. "Password changed"; [icon, title, text]
$page_title = 'Login';
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Poppins font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Shared auth styles (dashboard palette) -->
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;600;700&display=swap" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?><?php echo i18nLiveScripts(); ?>
</head>

<body>
    <div class="auth-wrap">
        <div class="login-card">
                    <div class="login-header">
                        <i class="fas fa-graduation-cap"></i>
                        <h2><?php echo APP_NAME; ?></h2>
                        <p class="mb-0 mt-2 opacity-75">Learning Management System</p>
                    </div>

                    <div class="login-body">
                        <?php if (codeLoginAvailable()): ?>
                        <a href="code_login.php" class="btn btn-primary btn-login w-100 mb-2">
                            <i class="fas fa-envelope-open-text me-2"></i> Student? Sign in with an email code
                        </a>
                        <div class="login-divider"><span>Teachers sign in with a password</span></div>
                        <?php endif; ?>
                        <form method="POST" id="loginForm" novalidate>
                            <?php echo csrfField(); ?>
                            <div class="form-floating mb-3">
                                <input type="email"
                                    class="form-control <?php echo $field_errors['email'] ? 'is-invalid' : ''; ?>"
                                    id="email" name="email" placeholder="Email address" autocomplete="username"
                                    value="<?php echo sanitize($_POST['email'] ?? ''); ?>">
                                <label for="email">Email address</label>
                                <div class="invalid-feedback"><?php echo $field_errors['email']; ?></div>
                            </div>

                            <div class="form-floating mb-3 password-field">
                                <input type="password"
                                    class="form-control <?php echo $field_errors['password'] ? 'is-invalid' : ''; ?>"
                                    id="password" name="password" placeholder="Password" autocomplete="current-password">
                                <label for="password">Password</label>
                                <button type="button" class="password-toggle" data-target="password"
                                    aria-label="Show password" tabindex="-1">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                                <div class="invalid-feedback"><?php echo $field_errors['password']; ?></div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember">
                                    <label class="form-check-label" for="remember">Remember me</label>
                                </div>
                                <?php if (passwordResetAvailable()): ?>
                                <a href="forgot_password.php" class="small">Forgot password?</a>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-primary btn-login w-100">
                                <i class="fas fa-sign-in-alt me-2"></i> Login
                            </button>
                        </form>

                        <?php
                        // Demo logins are listed only on installations that really have those demo accounts (never on a clean customer install)
                        $demo = [];
                        if (isDevHost()) foreach (['admin@test.com' => 'Super Admin', 'teacher@test.com' => 'Teacher', 'student@test.com' => 'Student'] as $de => $dl) {
                            if ($db->fetchOne("SELECT id FROM users WHERE email = ? AND is_active = 1" . ($de === 'admin@test.com' ? ' AND is_admin = 1' : ''), [$de])) $demo[$de] = $dl;
                        } ?>
                        <div class="demo-accounts mt-4">
                            <?php if ($demo): ?>   <?php /* only on a development machine — never on a real school domain */ ?>
                            <h6><i class="fas fa-info-circle me-2"></i>Demo Accounts</h6>
                            <?php foreach ($demo as $de => $dl): ?>
                            <div class="account">
                                <strong><?php echo $dl; ?>:</strong> <?php echo $de; ?><br>
                                <small>Password: password</small>
                            </div>
                            <?php endforeach; endif; ?>
                            <div class="account text-center mt-3">
                                <a href="register.php<?php echo $invite_code !== '' ? '?code=' . rawurlencode($invite_code) : ''; ?>" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="fas fa-user-plus me-2"></i>Register as Student
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-3 small" lang="en"><?php foreach (i18nLanguages() as $lc => [$ln]): ?><a href="?lang=<?php echo $lc; ?>" class="mx-2 <?php echo i18nLang() === $lc ? 'fw-bold' : ''; ?>" lang="<?php echo $lc; ?>"><?php echo htmlspecialchars($ln); ?></a><?php endforeach; ?></div>
                    <div class="login-footer">
                        <p class="mb-0 text-muted">
                            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.
                        </p>
                    </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- SweetAlert2 (task-level messages only) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Shared auth JS (password toggle + inline validation helpers) -->
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('loginForm');
        bindLiveClear(form);

        // Client-side inline validation (under-field messages, no popups)
        form.addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            let valid = true;

            if (!email.value.trim()) {
                setFieldError(email, 'Email is required');
                valid = false;
            } else if (!isValidEmail(email.value.trim())) {
                setFieldError(email, 'Please enter a valid email address');
                valid = false;
            }

            if (!password.value) {
                setFieldError(password, 'Password is required');
                valid = false;
            }

            if (!valid) e.preventDefault();
        });

        <?php if ($error): ?>
        // Task result: login failed
        Swal.fire({
            icon: 'error',
            title: 'Login Failed',
            text: '<?php echo $error; ?>',
            confirmButtonColor: '#4361ee'
        });
        <?php endif; ?>

        <?php if ($flash_msg): ?>
        Swal.fire({ icon: <?php echo json_encode($flash_msg[0]); ?>, titleText: <?php echo json_encode($flash_msg[1]); ?>, text: <?php echo json_encode($flash_msg[2]); ?>, confirmButtonColor: '#4361ee' });
        <?php endif; ?>

        <?php if (($_GET['message'] ?? '') === 'restored'): ?>
        Swal.fire({ icon: 'success', title: 'Backup restored', text: 'Sign in again — the accounts are the ones from the backup.', confirmButtonColor: '#4361ee' });
        <?php endif; ?>

        <?php if ($logged_out): ?>
        // Task result: logged out
        Swal.fire({
            icon: 'success',
            title: 'Logged Out',
            text: 'You have been logged out successfully.',
            timer: 2000,
            showConfirmButton: false
        });
        <?php endif; ?>
    });
    </script>
</body>

</html>
