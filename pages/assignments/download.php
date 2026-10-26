<?php
/**
 * Download a submitted file — ?id=<submission id>
 * Files live in uploads/submissions/ (web access denied). Only the student who
 * submitted it and the teachers of the assignment's class (owner + co-teachers) may download.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$submission = $db->fetchOne(
    "SELECT s.*, a.class_id
     FROM submissions s
     INNER JOIN assignments a ON s.assignment_id = a.id
     WHERE s.id = ?",
    [(int) ($_GET['id'] ?? 0)]
);

$allowed = $submission && (
    isTeacher() ||   // a teacher of THIS class — checked by getAccessibleClass() below
    (isStudent() && (int) $submission['student_id'] === (int) $current_user_id)
);
if (!$allowed || !getAccessibleClass($submission['class_id'], $current_user_id, $current_user_role)) {
    redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
}

$abs = uploadAbsolutePath($submission['file_path']);
if (!$abs) {
    redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$name = $submission['original_name'] ?: ('submission-' . $submission['id'] . '.' . $ext);
sendDownload($abs, $name, allowedUploadTypes()[$ext][0] ?? 'application/octet-stream', !empty($_GET['view']));
