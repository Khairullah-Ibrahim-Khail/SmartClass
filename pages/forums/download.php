<?php
/** Download a forum attachment — only members and teachers of the topic's class (the file is never served directly). */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$f = $db->fetchOne("SELECT a.*, t.class_id FROM forum_attachments a INNER JOIN forum_threads t ON a.thread_id = t.id WHERE a.id = ?", [(int) ($_GET['id'] ?? 0)]);
$class = $f ? getAccessibleClass($f['class_id'], $current_user_id, $current_user_role) : null;
$abs = $class ? uploadAbsolutePath($f['file_path']) : null;
if (!$abs) redirect(APP_URL . '/pages/forums/view.php');
sendDownload($abs, $f['original_name'], allowedUploadTypes()[$f['file_type']][0] ?? 'application/octet-stream');
