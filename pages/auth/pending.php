<?php
/**
 * Pending Approval Page
 * Shown to students waiting for teacher approval
 */

// Include required files (defines APP_NAME and starts the session)
require_once dirname(__DIR__, 2) . '/config/db.php';
i18nStart();   // Pashto / Dari

$page_title = 'Pending Approval';
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
                <div class="pending-card">
                    <div class="pending-header">
                        <i class="fas fa-clock icon-clock"></i>
                        <h3>Pending Approval</h3>
                    </div>

                    <div class="pending-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Your account is pending approval.</strong>
                        </div>

                        <p class="text-muted mb-4">
                            Your student account has been created but requires approval from a teacher before you can
                            join classes.
                        </p>

                        <?php
                        // Which class / teacher this student is waiting for (if they used a class code)
                        $waiting_for = isset($_SESSION['user_id']) ? $db->fetchAll(
                            "SELECT c.class_name FROM class_join_requests r INNER JOIN classes c ON r.class_id = c.id
                             WHERE r.student_id = ? AND r.status = 'pending'", [$_SESSION['user_id']]) : [];   // class name only until approved
                        ?>
                        <?php if ($waiting_for): ?>
                        <p class="text-muted mb-2">Waiting for approval to join:</p>
                        <ul class="text-start mb-4">
                            <?php foreach ($waiting_for as $w): ?>
                            <li><strong><?php echo htmlspecialchars($w['class_name']); ?></strong></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-muted mb-4">
                            Please contact your teacher to approve your account, or check back later.
                        </p>
                        <?php endif; ?>

                        <hr class="my-4">

                        <a href="<?php echo APP_URL; ?>/pages/dashboard.php" class="btn btn-primary me-2">
                            <i class="fas fa-sync-alt me-2"></i> Check again
                        </a>
                        <form method="POST" action="logout.php" class="d-inline">
                            <?php echo csrfField(); ?>
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </button>
                        </form>
                    </div>
        </div>
    </div>
</body>

</html>