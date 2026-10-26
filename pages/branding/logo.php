<?php
/** The school's logo (public: the sign-in pages show it too). Re-encoded PNG written by Settings › School; 404 when none is set. */
require_once dirname(__DIR__, 2) . '/config/db.php';
$rel = (string) getSetting('school_logo', '');
$abs = $rel !== '' ? realpath(STORAGE_ROOT . '/' . $rel) : false;
$root = realpath(STORAGE_ROOT . '/uploads/branding');
if (!$abs || !$root || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
readfile($abs);
