<?php
/**
 * Accept a teacher invite — ?token=<64 hex> (link made on pages/teachers/index.php)
 * The invitee confirms their name and chooses a password; the account is a teacher,
 * approved, from the start. The link works once (the invite is claimed atomically).
 * Inline under-field validation (client + server); SweetAlert2 only for the task result.
 */
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

$token = (string) ($_GET['token'] ?? '');
$invite = findUsableTeacherInvite($token);
$signed_in = isset($_SESSION['user_id']);

$done = false;
$general_error = '';
$field_errors = ['name' => '', 'password' => '', 'confirm_password' => ''];
$name = $invite ? $invite['name'] : '';

if ($invite && !$signed_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $general_error = 'Your session expired. Please try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if ($name === '') {
            $field_errors['name'] = 'Name is required';
        } elseif (mb_strlen($name) > 100) {
            $field_errors['name'] = 'Name must be under 100 characters';
        }
        if ($password === '') {
            $field_errors['password'] = 'Password is required';
        } elseif ($why = passwordProblem($password, (string) ($invite['email'] ?? ''), (string) ($invite['name'] ?? ''))) {
            $field_errors['password'] = $why;
        }
        if ($confirm === '') {
            $field_errors['confirm_password'] = 'Please confirm your password';
        } elseif ($password !== $confirm) {
            $field_errors['confirm_password'] = 'Passwords do not match';
        }
        if (!array_filter($field_errors)) {
            if (acceptTeacherInvite($invite, $name, $password)) {
                $done = true;
            } else {
                $general_error = 'This invite was already used or an account with this email exists. Try logging in.';
                $invite = null;
            }
        }
    }
}

$page_title = 'Teacher invite';
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>
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
                <i class="fas fa-chalkboard-teacher fa-3x mb-3"></i>
                <h3>Join as a teacher</h3>
                <p class="mb-0"><?php echo $invite ? htmlspecialchars(($invite['inviter_name'] ?: 'A teacher') . ' invited you to ' . APP_NAME) : APP_NAME; ?></p>
            </div>
            <div class="register-body">
                <?php if ($done): ?>
                <div class="text-center py-3">
                    <i class="fas fa-check-circle fa-4x mb-3" style="color: var(--success);"></i>
                    <h5>Your teacher account is ready</h5>
                    <p class="text-muted">Log in with your email and the password you just chose.</p>
                    <a href="login.php" class="btn btn-primary btn-register w-100 mt-2"><i class="fas fa-sign-in-alt me-2"></i> Go to Login</a>
                </div>
                <?php elseif (!$invite): ?>
                <div class="text-center py-3">
                    <i class="fas fa-unlink fa-3x mb-3" style="color: var(--danger);"></i>
                    <h5>This invite link does not work</h5>
                    <p class="text-muted">It may have been used already, cancelled, or it expired. Ask the teacher who invited you for a new link.</p>
                    <a href="login.php" class="btn btn-outline-primary w-100 mt-2"><i class="fas fa-sign-in-alt me-2"></i> Go to Login</a>
                </div>
                <?php elseif ($signed_in): ?>
                <div class="text-center py-3">
                    <i class="fas fa-user-lock fa-3x mb-3" style="color: var(--primary);"></i>
                    <h5>You are signed in</h5>
                    <p class="text-muted">Log out first, then open the invite link again to create the new teacher account.</p>
                    <a href="logout.php" class="btn btn-outline-primary w-100 mt-2"><i class="fas fa-sign-out-alt me-2"></i> Log out</a>
                </div>
                <?php else: ?>
                <form method="POST" id="inviteAcceptForm" novalidate>
                    <?php echo csrfField(); ?>
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($invite['email']); ?>" readonly>
                        <label for="email">Email (from the invite)</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control <?php echo $field_errors['name'] ? 'is-invalid' : ''; ?>"
                            id="name" name="name" placeholder="Full Name" autocomplete="name" maxlength="100" value="<?php echo htmlspecialchars($name); ?>">
                        <label for="name">Full Name</label>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['name']); ?></div>
                    </div>
                    <div class="form-floating mb-3 password-field">
                        <input type="password" class="form-control <?php echo $field_errors['password'] ? 'is-invalid' : ''; ?>"
                            id="password" name="password" placeholder="Password" autocomplete="new-password">
                        <label for="password">Password (min 6 characters)</label>
                        <button type="button" class="password-toggle" data-target="password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['password']); ?></div>
                    </div>
                    <div class="form-floating mb-3 password-field">
                        <input type="password" class="form-control <?php echo $field_errors['confirm_password'] ? 'is-invalid' : ''; ?>"
                            id="confirm_password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password">
                        <label for="confirm_password">Confirm Password</label>
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['confirm_password']); ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-register w-100"><i class="fas fa-user-check me-2"></i> Create teacher account</button>
                    <p class="text-muted small mt-3 mb-0">This link works once and expires on <?php echo formatDate($invite['expires_at'], 'M d, Y'); ?>.</p>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($done): ?>
        Swal.fire({ icon: 'success', title: 'Account created', text: 'You can log in now as a teacher.', confirmButtonText: 'Go to Login', confirmButtonColor: '#4361ee' })
            .then(function () { window.location.href = 'login.php'; });
        <?php elseif ($general_error): ?>
        Swal.fire({ icon: 'error', title: 'Could not create the account', text: <?php echo json_encode($general_error); ?>, confirmButtonColor: '#4361ee' });
        <?php endif; ?>
        const form = document.getElementById('inviteAcceptForm');
        if (!form) return;
        bindLiveClear(form);
        form.addEventListener('submit', function (e) {
            let ok = true;
            const name = document.getElementById('name'), pw = document.getElementById('password'), cf = document.getElementById('confirm_password');
            if (!name.value.trim()) { setFieldError(name, 'Name is required'); ok = false; }
            if (!pw.value) { setFieldError(pw, 'Password is required'); ok = false; }
            else if (pw.value.length < <?php echo passwordMinLength(); ?>) { setFieldError(pw, 'Use at least <?php echo passwordMinLength(); ?> characters'); ok = false; }
            if (!cf.value) { setFieldError(cf, 'Please confirm your password'); ok = false; }
            else if (pw.value !== cf.value) { setFieldError(cf, 'Passwords do not match'); ok = false; }
            if (!ok) e.preventDefault();
        });
    });
    </script>
</body>
</html>
