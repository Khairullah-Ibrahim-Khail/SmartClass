<?php
/** The profile moved into Settings (Profile, Password & security, Notifications). */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
redirect(APP_URL . '/pages/settings/' . (($_GET['tab'] ?? '') === 'email' ? 'notifications' : 'profile') . '.php');
