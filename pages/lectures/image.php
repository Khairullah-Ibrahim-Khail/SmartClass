<?php
/**
 * Lecture image — ?id=N. Served only to the teachers and students of the image's class
 * (getAccessibleClass); files live in uploads/lectures/ (web access denied). 404 otherwise.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$img = $db->fetchOne("SELECT * FROM lecture_images WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);
$abs = ($img && getAccessibleClass($img['class_id'], $current_user_id, $current_user_role)) ? uploadAbsolutePath($img['file_path']) : null;
if (!$abs || !in_array($img['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $img['mime']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=604800');   // the file never changes (a new image gets a new id)
readfile($abs);
exit;
