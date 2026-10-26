<?php
/** Download a file from a research project's timeline — only the project's student and the teachers of its class (never served directly). */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$u = $db->fetchOne("SELECT * FROM research_updates WHERE id = ? AND file_path IS NOT NULL", [(int) ($_GET['id'] ?? 0)]);
$p = $u ? researchProject($u['project_id'], $current_user_id, $current_user_role) : null;
$abs = $p ? uploadAbsolutePath($u['file_path']) : null;
if (!$abs) redirect(APP_URL . '/pages/research/projects.php');
sendDownload($abs, $u['file_name'], allowedUploadTypes()[$u['file_type']][0] ?? 'application/octet-stream');
