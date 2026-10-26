<?php
/**
 * Students sign in with a one-time email code (no password).
 *   step 1  POST send    email → a 6-digit code is emailed (if a student account exists — the page
 *                        says the same thing either way, so nobody can test which emails exist)
 *   step 2  POST verify  the code → signed in (pending accounts land on the "waiting" page)
 *           POST resend  a new code (after RESEND_SECONDS)   POST reset  use another email
 * The email being signed in is kept in the session, never in the URL.
 * Inline under-field errors; SweetAlert2 only for task results. Needs email switched on.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

const RESEND_SECONDS = 60;

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}
if (!codeLoginAvailable()) {
    header('Location: ' . APP_URL . '/pages/auth/login.php');
    exit;
}

// Invite link opened while logged out (see login.php): go back to the join page afterwards
$invite_code = '';
if (preg_match('#/pages/classes/join\.php\?code=([A-Za-z0-9]{1,20})$#', (string) ($_SESSION['redirect_after_login'] ?? ''), $m)) {
    $invite_code = strtoupper($m[1]);
}

$state = $_SESSION['code_login'] ?? null;   // ['email' => ..., 'sent_at' => ts]
$email_error = '';
$code_error = '';
$task_result = null;
$email_value = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please try again.'];
    } elseif ($action === 'send') {
        $email_value = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email_value === '') {
            $email_error = 'Email is required';
        } elseif (!filter_var($email_value, FILTER_VALIDATE_EMAIL)) {
            $email_error = 'Please enter a valid email address';
        } else {
            requestLoginCode($email_value);   // result deliberately not shown
            $_SESSION['code_login'] = ['email' => $email_value, 'sent_at' => time()];
            header('Location: ' . APP_URL . '/pages/auth/code_login.php');
            exit;
        }
    } elseif ($action === 'resend' && $state) {
        if (time() - (int) $state['sent_at'] < RESEND_SECONDS) {
            $task_result = ['info', 'Please wait a moment', 'You can ask for a new code ' . (RESEND_SECONDS - (time() - (int) $state['sent_at'])) . ' seconds from now.'];
        } else {
            requestLoginCode($state['email']);
            $_SESSION['code_login']['sent_at'] = time();
            Session::flash('code_msg', ['success', 'New code sent', 'Use the newest code — older ones stop working.']);
            header('Location: ' . APP_URL . '/pages/auth/code_login.php');
            exit;
        }
    } elseif ($action === 'reset') {
        unset($_SESSION['code_login']);
        header('Location: ' . APP_URL . '/pages/auth/code_login.php');
        exit;
    } elseif ($action === 'verify' && $state) {
        $result = verifyLoginCode($state['email'], $_POST['code'] ?? '');
        if (is_string($result)) {
            $code_error = $result;
        } else {
            unset($_SESSION['code_login'], $_SESSION['redirect_after_login']);
            startUserSession($result, 'code');
            header('Location: ' . APP_URL . ($invite_code !== '' ? '/pages/classes/join.php?code=' . rawurlencode($invite_code) : '/pages/dashboard.php'));
            exit;
        }
    }
}
$task_result = Session::flash('code_msg') ?? $task_result;
$state = $_SESSION['code_login'] ?? null;
$wait = $state ? max(0, RESEND_SECONDS - (time() - (int) $state['sent_at'])) : 0;
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in with a code - <?php echo APP_NAME; ?></title>
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
                <i class="fas fa-envelope-open-text fa-3x mb-3"></i>
                <h3>Sign in with a code</h3>
                <p class="mb-0"><?php echo $state ? 'Check your email' : 'For students — no password needed'; ?></p>
            </div>
            <div class="register-body">
                <?php if (!$state): ?>
                <form method="POST" id="emailForm" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="send">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control <?php echo $email_error ? 'is-invalid' : ''; ?>" id="email" name="email"
                            placeholder="Email address" autocomplete="email" value="<?php echo htmlspecialchars($email_value); ?>" autofocus>
                        <label for="email">Your email address</label>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($email_error); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-register w-100"><i class="fas fa-paper-plane me-2"></i> Email me a code</button>
                </form>
                <?php else: ?>
                <p class="text-muted small">If <strong><?php echo htmlspecialchars($state['email']); ?></strong> belongs to a student account,
                    we sent a 6-digit code to it. It expires in <?php echo LOGIN_CODE_MINUTES; ?> minutes — check the spam folder too.</p>
                <form method="POST" id="codeForm" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="verify">
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control code-input <?php echo $code_error ? 'is-invalid' : ''; ?>" id="code" name="code"
                            placeholder="123456" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" autofocus>
                        <label for="code">6-digit code</label>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($code_error); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-register w-100"><i class="fas fa-sign-in-alt me-2"></i> Sign in</button>
                </form>
                <div class="d-flex justify-content-between mt-3 small">
                    <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-link btn-sm p-0" id="resendBtn" data-wait="<?php echo $wait; ?>">Send a new code</button></form>
                    <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="reset">
                        <button type="submit" class="btn btn-link btn-sm p-0">Use another email</button></form>
                </div>
                <?php endif; ?>
                <div class="login-link">
                    <p class="text-muted mb-2">New student?</p>
                    <a href="register.php<?php echo $invite_code !== '' ? '?code=' . rawurlencode($invite_code) : ''; ?>" class="btn btn-outline-primary"><i class="fas fa-user-plus me-2"></i> Register</a>
                    <a href="login.php" class="btn btn-link">Teacher sign in</a>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($task_result): ?>
        Swal.fire({ icon: <?php echo json_encode($task_result[0]); ?>, titleText: <?php echo json_encode($task_result[1]); ?>,
                    text: <?php echo json_encode($task_result[2]); ?>, confirmButtonColor: '#4361ee' });
        <?php endif; ?>
        const ef = document.getElementById('emailForm');
        if (ef) {
            bindLiveClear(ef);
            ef.addEventListener('submit', function (e) {
                const m = document.getElementById('email');
                if (!m.value.trim()) { setFieldError(m, 'Email is required'); e.preventDefault(); }
                else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m.value.trim())) { setFieldError(m, 'Please enter a valid email address'); e.preventDefault(); }
            });
        }
        const cf = document.getElementById('codeForm');
        if (cf) {
            bindLiveClear(cf);
            cf.addEventListener('submit', function (e) {
                const c = document.getElementById('code');
                if (c.value.replace(/\D/g, '').length !== 6) { setFieldError(c, 'Enter the 6-digit code from the email'); e.preventDefault(); }
            });
        }
        // "Send a new code" counts down instead of being clickable too early
        const rb = document.getElementById('resendBtn');
        if (rb) {
            let left = Number(rb.dataset.wait);
            const label = rb.textContent;
            const tick = function () {
                if (left > 0) { rb.disabled = true; rb.textContent = label + ' (' + left + 's)'; left--; setTimeout(tick, 1000); }
                else { rb.disabled = false; rb.textContent = label; }
            };
            tick();
        }
    });
    </script>
</body>
</html>
