<?php
/**
 * Authentication Include
 * Protect pages and handle user authentication
 * Roles: teacher, student. A teacher can also be the school's super admin (users.is_admin):
 * isAdmin() — school-wide pages (teachers, Email / AI settings, backups).
 */

// Include database connection (defines APP_URL and starts the session)
require_once dirname(__DIR__) . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Store current page for redirect after login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';

    // Redirect to login
    header('Location: ' . APP_URL . '/pages/auth/login.php');
    exit;
}

// Get current user data
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'] ?? 'student';
$current_user_name = $_SESSION['user_name'] ?? 'User';

// Get full user data
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$current_user_id]);

if (!$user) {
    // User not found, destroy session and redirect
    session_destroy();
    header('Location: ' . APP_URL . '/pages/auth/login.php?error=user_not_found');
    exit;
}

// Update session variables
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_photo'] = $user['photo'] ?? null;

// Check if user is active (checked first: a rejected student is inactive,
// and must be signed out rather than parked on the pending page)
if ($user['is_active'] == 0) {
    session_destroy();
    header('Location: ' . APP_URL . '/pages/auth/login.php?error=account_inactive');
    exit;
}

// Check if user is approved (only for students)
if ($user['role'] === 'student' && $user['is_approved'] == 0) {
    // Student not approved yet
    header('Location: ' . APP_URL . '/pages/auth/pending.php');
    exit;
}

/** The signed-in user is the school's super admin (always a teacher account). */
function isAdmin() {
    global $user;
    return !empty($user['is_admin']) && ($user['role'] ?? '') === 'teacher';
}

// A provider support session ends by itself
if (!empty($_SESSION['support_until']) && time() > (int) $_SESSION['support_until']) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . APP_URL . '/pages/auth/login.php');
    exit;
}

// A customer's admin accepts the service terms (and replaces the temporary password) before anything else
if (!SC_IS_MASTER && isAdmin() && !termsAccepted() && empty($_SESSION['support_until'])
    && !preg_match('#/pages/(settings/terms|auth/logout|branding/logo)\.php$#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) {
    header('Location: ' . APP_URL . '/pages/settings/terms.php');
    exit;
}

// The school requires two-step sign-in for administrators: an admin without it can only set it up (or sign out)
if (adminNeedsTwoStep($user) && !preg_match('#/pages/(settings/two_factor|auth/logout|branding/logo)\.php$#', (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH))) {
    Session::flash('tf_msg', ['info', 'Two-step sign-in is required', 'Your school requires administrators to use an authenticator app. Set it up now to continue.']);
    header('Location: ' . APP_URL . '/pages/settings/two_factor.php');
    exit;
}

/** Stop here unless the user is the super admin (school-wide pages). */
function requireAdmin() {
    if (!isAdmin()) {
        redirect(APP_URL . '/pages/dashboard.php');
    }
}

// Permission check - check if user has access to specific module
function checkPermission($required_roles = []) {
    global $current_user_role;
    
    if (empty($required_roles)) {
        return true; // No restriction
    }
    
    if (in_array($current_user_role, $required_roles)) {
        return true;
    }
    
    // Access denied
    header('HTTP/1.1 403 Forbidden');
    echo '<div class="alert alert-danger">Access Denied: You do not have permission to access this page.</div>';
    echo '<a href="' . APP_URL . '/pages/dashboard.php" class="btn btn-primary">Return to Dashboard</a>';
    exit;
}

// Role helpers
function isTeacher() {
    return ($_SESSION['user_role'] ?? '') === 'teacher';
}

function isStudent() {
    return ($_SESSION['user_role'] ?? '') === 'student';
}

// Get unread notifications count (real, from the notifications table)
function getUnreadNotificationsCount() {
    return countUnreadNotifications($_SESSION['user_id'] ?? 0);
}

// Generate unique class code
function generateClassCode($length = 8) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $code;
}