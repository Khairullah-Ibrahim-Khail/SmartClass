<?php
/**
 * Open a notification — ?id=N
 * Marks it read (only if it belongs to the current user) and goes to its page.
 * Links are validated as in-app paths, so this is never an open redirect.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$notification = $db->fetchOne(
    "SELECT id, link FROM notifications WHERE id = ? AND user_id = ?",
    [(int) ($_GET['id'] ?? 0), $current_user_id]
);

if (!$notification) {
    redirect(APP_URL . '/pages/notifications/index.php');
}

markNotificationRead($notification['id'], $current_user_id);

$link = safeNotificationLink($notification['link']);
redirect(APP_URL . '/' . ($link ?? 'pages/notifications/index.php'));
