<?php
/**
 * Lecture editor preview — POST content (teacher only, CSRF via X-CSRF-Token).
 * Returns JSON {html, minutes, sections} rendered by the same renderLecture() students see,
 * with images of class_id (a class this teacher teaches) and source-line markers for the editor.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isTeacher()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}
if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page.']);
    exit;
}
$text = mb_substr((string) ($_POST['content'] ?? ''), 0, 60000);
$class = getAccessibleClass((int) ($_POST['class_id'] ?? 0), $current_user_id, 'teacher');
$doc = renderLecture($text, ['explain' => false, 'class_id' => $class ? (int) $class['id'] : null, 'source_map' => true]);
echo json_encode(['ok' => true, 'html' => $doc['html'], 'minutes' => $doc['minutes'], 'sections' => count($doc['toc'])]);
