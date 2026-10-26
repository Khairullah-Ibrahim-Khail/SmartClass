<?php
/**
 * Profile photo — ?u=<user id>
 * Photos live in uploads/avatars/ (web access denied); served inline as an image only to
 * people who may see that user (canSeeUser: yourself, the super admin, someone you share
 * a class with, or a student asking to join a class you teach). Anyone else gets 404.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$target = (int) ($_GET['u'] ?? 0);
$row = canSeeUser($current_user_id, $target, isAdmin()) ? $db->fetchOne("SELECT photo FROM users WHERE id = ?", [$target]) : null;
$abs = $row ? uploadAbsolutePath((string) $row['photo']) : null;
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = $abs ? strtolower(pathinfo($abs, PATHINFO_EXTENSION)) : '';

if (!$abs || !isset($types[$ext]) || strpos((string) $row['photo'], 'uploads/avatars/') !== 0) {
    http_response_code(404);
    exit;
}

// ?s=128|256 → a sharp square thumbnail (the browser shrinking a 1.4 MB original to 40 px looks jagged), made once and kept in uploads/avatars/thumb/
$want = (int) ($_GET['s'] ?? 0);
if (in_array($want, [128, 256], true) && function_exists('imagecreatetruecolor')) {
    $tdir = ensureProtectedUploadDir('avatars/thumb');
    $thumb = $tdir ? $tdir . '/' . $want . '_' . basename($abs) : null;
    if ($thumb && !is_file($thumb)) {
        $load = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$ext];
        $src = function_exists($load) ? @$load($abs) : false;
        if ($src) {
            $w = imagesx($src); $h = imagesy($src); $side = min($w, $h);
            $dst = imagecreatetruecolor($want, $want); imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) * 0.2), $want, $want, $side, $side);
            ['jpg' => fn() => imagejpeg($dst, $thumb, 90), 'png' => fn() => imagepng($dst, $thumb, 6), 'webp' => fn() => imagewebp($dst, $thumb, 90)][$ext]();
            imagedestroy($src); imagedestroy($dst);
        }
    }
    if ($thumb && is_file($thumb)) $abs = $thumb;
}

header('Content-Type: ' . $types[$ext]);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=86400');   // URL carries the filename, so a new photo gets a new URL
readfile($abs);
exit;
