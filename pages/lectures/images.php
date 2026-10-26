<?php
/**
 * Lecture image library (teachers of the class) — JSON, CSRF via X-CSRF-Token.
 *   POST action=upload  class_id + file "image"   → {ok, id, width, height, url}
 *   POST action=list    class_id                  → {ok, images: [{id, url, width, height, name}]}
 *   POST action=delete  class_id + id             → {ok} (lectures using it show "Image not available")
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = function ($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isTeacher()) $fail('Not allowed.', 403);
if (postBodyTooLarge()) $fail('The image is too large (max 8 MB).', 413);
if (!verifyCsrf()) $fail('Your session expired. Reload the page.', 403);
$class = getAccessibleClass((int) ($_POST['class_id'] ?? 0), $current_user_id, 'teacher');
if (!$class) $fail('Choose one of your classes first.', 403);
$url = fn($id) => APP_URL . '/pages/lectures/image.php?id=' . (int) $id;

$action = $_POST['action'] ?? '';
if ($action === 'upload') {
    $r = storeLectureImage($_FILES['image'] ?? null, $class['id'], $current_user_id);
    if (!$r['ok']) $fail($r['error'], 422);
    logActivity('lecture_image', 'lectures', "Uploaded lecture image #{$r['id']} to {$class['class_name']}");
    echo json_encode(['ok' => true, 'id' => $r['id'], 'width' => $r['width'], 'height' => $r['height'], 'url' => $url($r['id'])]);
} elseif ($action === 'list') {
    echo json_encode(['ok' => true, 'images' => array_map(fn($i) => ['id' => (int) $i['id'], 'url' => $url($i['id']), 'width' => (int) $i['width'],
        'height' => (int) $i['height'], 'name' => (string) $i['original_name']], getClassLectureImages($class['id']))]);
} elseif ($action === 'delete') {
    $img = $db->fetchOne("SELECT * FROM lecture_images WHERE id = ? AND class_id = ?", [(int) ($_POST['id'] ?? 0), $class['id']]);
    if (!$img) $fail('Image not found.', 404);
    $abs = uploadAbsolutePath($img['file_path']);
    $db->delete('lecture_images', 'id = ?', [$img['id']]);
    if ($abs) @unlink($abs);
    logActivity('lecture_image_deleted', 'lectures', "Deleted lecture image #{$img['id']} from {$class['class_name']}");
    echo json_encode(['ok' => true]);
} else {
    $fail('Unknown action.');
}
