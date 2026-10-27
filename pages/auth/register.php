<?php
/**
 * Student Registration Page
 * Self-registration for students.
 * With email switched on (codeLoginAvailable) students have NO password: after registering
 * they get a 6-digit code by email and sign in with it (pages/auth/code_login.php), which
 * also proves the email. With email off, the old password form is used.
 * - Inline under-field validation (client + server)
 * - SweetAlert2 only for task-level results (registration success / server failure)
 */

// Include required files (defines APP_URL and starts the session)
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

if (!serviceEnabled('signups')) {
    pausedPage('Registration is closed', 'New accounts cannot be created on this site right now. If you already have an account, please sign in.',
        '<p><a href="' . htmlspecialchars(APP_URL) . '/pages/auth/login.php">Sign in</a></p>');
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/pages/dashboard.php');
    exit;
}

$success = false;
$general_error = '';
$passwordless = codeLoginAvailable();
$field_errors = ['name' => '', 'email' => '', 'password' => '', 'confirm_password' => '', 'class_code' => ''];
// Optional class code (typed, or from an invite link ?code=...): the request goes to that class's teacher
$class_code = strtoupper(trim($_POST['class_code'] ?? $_GET['code'] ?? ''));
$join_class = null;
$success_teacher = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    // Task-level failure -> SweetAlert2
    $general_error = 'Your session expired. Please try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Stored raw; every page escapes on output
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // At most 10 sign-ups per hour from one address
    $reg_guard = rateGuard('register_ip', clientIp(), 10, 3600);
    if (!$reg_guard['ok']) $field_errors['email'] = 'Too many sign-ups from this address. Please wait ' . rateWaitText($reg_guard['wait']) . '.';

    // Inline field validation (server side)
    if (empty($name)) {
        $field_errors['name'] = 'Full name is required';
    }

    if (empty($email)) {
        $field_errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Please enter a valid email address';
    } elseif ($db->exists('users', 'email', $email)) {
        $field_errors['email'] = 'This email is already registered';
    }

    if (!$passwordless) {
        if (empty($password)) {
            $field_errors['password'] = 'Password is required';
        } elseif ($why = passwordProblem($password, $email, $name)) {
            $field_errors['password'] = $why;
        }

        if (empty($confirm_password)) {
            $field_errors['confirm_password'] = 'Please confirm your password';
        } elseif ($password !== $confirm_password) {
            $field_errors['confirm_password'] = 'Passwords do not match';
        }
    } else {
        $password = bin2hex(random_bytes(32));   // never shown or used: students sign in with emailed codes
    }

    $wa_raw = trim((string) ($_POST['whatsapp'] ?? '')); $wa_rel = (string) ($_POST['whatsapp_relation'] ?? 'self');
    if (waNumberRequired() || $wa_raw !== '') {
        if ($wa_raw === '') $field_errors['whatsapp'] = 'A WhatsApp number is required — yours, or your father\'s, uncle\'s or elder brother\'s.';
        elseif (!waNormalizeNumber($wa_raw)) $field_errors['whatsapp'] = 'Enter a valid WhatsApp number, for example +93 700 123 456 (or 0700 123 456).';
    }

    if ($class_code !== '') {
        $join_class = $db->fetchOne(
            "SELECT c.*, u.name AS teacher_name FROM classes c INNER JOIN users u ON c.teacher_id = u.id
             WHERE c.class_code = ? AND c.is_active = 1", [$class_code]);
        if (!$join_class) {
            $field_errors['class_code'] = 'No class found with this code (or leave it empty)';
        }
    }

    if (!array_filter($field_errors) && ($limit_msg = limitReached('students'))) {
        $field_errors['email'] = $limit_msg;
    }

    if (!array_filter($field_errors)) {
        try {
            // Create student account (pending approval)
            $new_user_id = $db->insert('users', [
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'student',
                'is_approved' => 0, // Pending approval
                'is_active' => 1
            ]);

            if ($wa_raw !== '') waSavePerson($new_user_id, $wa_raw, $wa_rel);
            logActivity('register', 'auth', "New student registered: {$name} ({$email})", $new_user_id);
            if ($join_class) {
                // The class teacher approves account + class in one step (People page / Approve Students)
                requestToJoinClass(array_merge($join_class, ['require_approval' => 1]), $new_user_id, $name);
                $success_teacher = $join_class['class_name'];   // class name only — no teacher details before approval
            } else {
                // No class yet: only the super admin sees the account in Approve Students
                notifyAdmins(
                    'student_registered',
                    'New student awaiting approval',
                    "{$name} ({$email}) registered and is waiting for approval.",
                    'pages/students/approve.php',
                    $new_user_id
                );
            }

            $success = true;
            if ($passwordless) {
                // Straight to the code step: the first code proves the email and signs them in
                requestLoginCode(strtolower($email));
                $_SESSION['code_login'] = ['email' => strtolower($email), 'sent_at' => time()];
                Session::flash('code_msg', ['success', 'Account created', 'Enter the 6-digit code we emailed you to sign in.'
                    . ($success_teacher ? ' Your request to join ' . $success_teacher . ' was sent to its teacher.' : '')]);
                header('Location: ' . APP_URL . '/pages/auth/code_login.php');
                exit;
            }
        } catch (Exception $e) {
            // Task-level failure -> SweetAlert2
            $general_error = 'Registration failed. Please try again.';
        }
    }
}

$page_title = 'Student Registration';
?>

<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - <?php echo APP_NAME; ?></title>

    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg">

    <!-- Bootstrap CSS -->
    <link href="<?php echo asset('assets/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/bootstrap-icons/bootstrap-icons.css'); ?>" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">

    <!-- Poppins font -->
    <link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet">

    <!-- Shared auth styles (dashboard palette) -->
    <link href="<?php echo asset('assets/css/auth.css'); ?>" rel="stylesheet">
    <?php if (i18nRtl()): ?><link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet"><link href="<?php echo asset('assets/css/rtl.css'); ?>" rel="stylesheet"><?php endif; ?><?php echo i18nLiveScripts(); ?>
</head>

<body>
    <div class="auth-wrap">
                <div class="register-card">
                    <div class="register-header">
                        <i class="fas fa-user-plus fa-3x mb-3"></i>
                        <h3>Student Registration</h3>
                        <p class="mb-0">Create your account to join classes</p>
                    </div>

                    <div class="register-body">
                        <?php if ($success): ?>
                        <div class="text-center py-3">
                            <i class="fas fa-check-circle fa-4x mb-3" style="color: var(--success);"></i>
                            <h5>Registration Successful</h5>
                            <p class="text-muted"><?php echo $success_teacher
                                ? 'Your request to join ' . htmlspecialchars($success_teacher) . ' was sent to its teacher. You can log in once you are approved.'
                                : 'Your account is pending teacher approval.'; ?></p>
                            <a href="login.php" class="btn btn-primary btn-register w-100 mt-2">
                                <i class="fas fa-sign-in-alt me-2"></i> Go to Login
                            </a>
                        </div>
                        <?php else: ?>

                        <form method="POST" id="registerForm" novalidate>
                            <?php echo csrfField(); ?>
                            <div class="form-floating mb-3">
                                <input type="text"
                                    class="form-control <?php echo $field_errors['name'] ? 'is-invalid' : ''; ?>"
                                    id="name" name="name" placeholder="Full Name" autocomplete="name"
                                    value="<?php echo sanitize($_POST['name'] ?? ''); ?>">
                                <label for="name">Full Name</label>
                                <div class="invalid-feedback"><?php echo $field_errors['name']; ?></div>
                            </div>

                            <div class="form-floating mb-3">
                                <input type="email"
                                    class="form-control <?php echo $field_errors['email'] ? 'is-invalid' : ''; ?>"
                                    id="email" name="email" placeholder="Email address" autocomplete="email"
                                    value="<?php echo sanitize($_POST['email'] ?? ''); ?>">
                                <label for="email">Email address</label>
                                <div class="invalid-feedback"><?php echo $field_errors['email']; ?></div>
                            </div>

                            <?php if (!$passwordless): ?>
                            <div class="form-floating mb-3 password-field">
                                <input type="password"
                                    class="form-control <?php echo $field_errors['password'] ? 'is-invalid' : ''; ?>"
                                    id="password" name="password" placeholder="Password" autocomplete="new-password">
                                <label for="password">Password (min 6 characters)</label>
                                <button type="button" class="password-toggle" data-target="password"
                                    aria-label="Show password" tabindex="-1">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                                <div class="invalid-feedback"><?php echo $field_errors['password']; ?></div>
                            </div>

                            <div class="form-floating mb-3 password-field">
                                <input type="password"
                                    class="form-control <?php echo $field_errors['confirm_password'] ? 'is-invalid' : ''; ?>"
                                    id="confirm_password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password">
                                <label for="confirm_password">Confirm Password</label>
                                <button type="button" class="password-toggle" data-target="confirm_password"
                                    aria-label="Show password" tabindex="-1">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                                <div class="invalid-feedback"><?php echo $field_errors['confirm_password']; ?></div>
                            </div>

                            <?php else: ?>
                            <p class="form-text mb-3"><i class="fas fa-envelope-open-text me-1"></i> No password needed — we email you a 6-digit code to sign in.</p>
                            <?php endif; ?>

                            <?php if (waOn()): ?>
                            <div class="form-floating mb-3">
                                <input type="text" inputmode="tel" maxlength="30"
                                    class="form-control <?php echo !empty($field_errors['whatsapp']) ? 'is-invalid' : ''; ?>"
                                    id="whatsapp" name="whatsapp" placeholder="WhatsApp number" value="<?php echo sanitize($_POST['whatsapp'] ?? ''); ?>">
                                <label for="whatsapp">WhatsApp number<?php echo waNumberRequired() ? '' : ' (optional)'; ?></label>
                                <div class="invalid-feedback"><?php echo !empty($field_errors['whatsapp']) ? htmlspecialchars(t($field_errors['whatsapp'])) : ''; ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small" for="whatsapp_relation">Whose number is it?</label>
                                <select class="form-select" id="whatsapp_relation" name="whatsapp_relation"><?php foreach (waRelations() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo ($_POST['whatsapp_relation'] ?? 'self') === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars(t($rl)); ?></option><?php endforeach; ?></select>
                                <div class="form-text">The school sends messages about attendance and results to this number. Use your own, or your father's, uncle's or elder brother's.</div>
                            </div>
                            <?php endif; ?>

                            <div class="form-floating mb-3">
                                <input type="text"
                                    class="form-control <?php echo $field_errors['class_code'] ? 'is-invalid' : ''; ?>"
                                    id="class_code" name="class_code" placeholder="Class code (optional)" maxlength="20"
                                    style="text-transform: uppercase; letter-spacing: 2px;"
                                    value="<?php echo htmlspecialchars($class_code); ?>">
                                <label for="class_code">Class code (optional)</label>
                                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_code']); ?></div>
                                <div class="form-text">Got a code or invite link from your teacher? Your teacher approves you and adds you to the class in one step.</div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-register w-100">
                                <i class="fas fa-user-plus me-2"></i> Register
                            </button>
                        </form>
<div class="text-center mt-3 small" lang="en"><?php foreach (i18nLanguages() as $lc => [$ln]): ?><a href="?lang=<?php echo $lc; ?>" class="mx-2 <?php echo i18nLang() === $lc ? 'fw-bold' : ''; ?>" lang="<?php echo $lc; ?>"><?php echo htmlspecialchars($ln); ?></a><?php endforeach; ?></div>


                        <div class="login-link">
                            <p class="text-muted mb-2">Already have an account?</p>
                            <a href="login.php" class="btn btn-outline-primary">
                                <i class="fas fa-sign-in-alt me-2"></i> Login here
                            </a>
                        </div>

                        <?php endif; ?>
                    </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="<?php echo asset('assets/vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>

    <!-- SweetAlert2 (task-level messages only) -->
    <script src="<?php echo asset('assets/vendor/sweetalert2/sweetalert2.all.min.js'); ?>"></script>

    <!-- Shared auth JS (password toggle + inline validation helpers) -->
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($success): ?>
        // Task result: registration succeeded
        Swal.fire({
            icon: 'success',
            title: 'Registration Successful!',
            text: <?php echo json_encode($success_teacher
                ? 'Your request to join ' . $success_teacher . ' was sent to its teacher. You can log in as soon as you are approved.'
                : 'Your account has been created and is pending teacher approval.'); ?>,
            confirmButtonText: 'Go to Login',
            confirmButtonColor: '#4361ee'
        }).then(function() {
            window.location.href = 'login.php';
        });
        <?php else: ?>

        const form = document.getElementById('registerForm');
        bindLiveClear(form);

        // Client-side inline validation (under-field messages, no popups)
        form.addEventListener('submit', function(e) {
            const name = document.getElementById('name');
            const email = document.getElementById('email');
            const password = document.getElementById('password');
            const confirm = document.getElementById('confirm_password');
            let valid = true;
            const passwordless = !password;   // email-code sign-in: no password fields

            if (!name.value.trim()) {
                setFieldError(name, 'Full name is required');
                valid = false;
            }

            if (!email.value.trim()) {
                setFieldError(email, 'Email is required');
                valid = false;
            } else if (!isValidEmail(email.value.trim())) {
                setFieldError(email, 'Please enter a valid email address');
                valid = false;
            }

            if (!passwordless && !password.value) {
                setFieldError(password, 'Password is required');
                valid = false;
            } else if (!passwordless && password.value.length < <?php echo passwordMinLength(); ?>) {
                setFieldError(password, 'Password must be at least 6 characters');
                valid = false;
            }

            if (!passwordless && !confirm.value) {
                setFieldError(confirm, 'Please confirm your password');
                valid = false;
            } else if (!passwordless && confirm.value !== password.value) {
                setFieldError(confirm, 'Passwords do not match');
                valid = false;
            }

            if (!valid) e.preventDefault();
        });

        <?php if ($general_error): ?>
        // Task result: server failure
        Swal.fire({
            icon: 'error',
            title: 'Registration Failed',
            text: '<?php echo $general_error; ?>',
            confirmButtonColor: '#4361ee'
        });
        <?php endif; ?>
        <?php endif; ?>
    });
    </script>
</body>

</html>
