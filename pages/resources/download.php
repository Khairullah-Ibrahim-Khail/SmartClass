<?php
/**
 * Download a resource file.
 * Files live in uploads/resources/ (web access denied); this page streams them
 * only to the class's teacher or its enrolled students, counts downloads and
 * records the student's open (content_progress) for the teacher's activity view.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$resource = $db->fetchOne("SELECT * FROM resources WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);

// Membership / ownership check on the resource's class
$class = $resource ? getAccessibleClass($resource['class_id'], $current_user_id, $current_user_role) : null;
if (!$class) {
    redirect(APP_URL . '/pages/resources/view.php?error=not_found');
}

$abs = uploadAbsolutePath($resource['file_path']);
if (!$abs) {
    error_log("SmartClass: resource #{$resource['id']} file missing: {$resource['file_path']}");
    redirect(APP_URL . '/pages/resources/view.php?error=not_found');
}

$db->query("UPDATE resources SET download_count = download_count + 1 WHERE id = ?", [$resource['id']]);
recordContentOpen($class, $current_user_id, 'resource', $resource['id']);   // "who opened it" for the teacher

$name = $resource['original_name'] ?: ('resource-' . $resource['id'] . '.' . $resource['file_type']);
$mime = allowedUploadTypes()[$resource['file_type']][0] ?? 'application/octet-stream';
sendDownload($abs, $name, $mime);
