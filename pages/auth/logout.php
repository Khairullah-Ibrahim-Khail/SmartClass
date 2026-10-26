<?php
/**
 * Logout Page
 * POST only (CSRF-checked) so other sites cannot log users out with a link.
 */

// Include required files (defines APP_URL and starts the session)
require_once dirname(__DIR__, 2) . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf()) {
    // A plain GET (old bookmark, prefetch, foreign link) does nothing harmful
    redirect(APP_URL . (isset($_SESSION['user_id']) ? '/pages/dashboard.php' : '/pages/auth/login.php'));
}

// Audit trail before destroying session
if (isset($_SESSION['user_id'])) {
    logActivity('logout', 'auth');
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: ' . APP_URL . '/pages/auth/login.php?message=logged_out');
exit;
