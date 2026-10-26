<?php
/**
 * SmartClass - files in the forum (loaded by includes/functions.php)
 *   forumAttach($thread_id, $post_id|null, $user_id, $file)   store one optional uploaded file (same rules as every upload:
 *                                                              type + content checked, size cap, random name, never served directly)
 *   forumAttachments($thread_id)                              thread's files grouped by post (0 = the topic itself)
 *   forumDeleteFiles($thread_id, $post_id = null)             remove the files of a topic (all) or of one reply from disk
 * Downloads go through pages/forums/download.php (the topic's class members only).
 */
/** Check an uploaded file with the same rules as storeUpload() WITHOUT storing it; returns an error text or null. */
function storeUploadCheck($file) {
    if (!serviceEnabled('uploads')) return 'File uploads are switched off for this installation by the service provider.';
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) return 'Please choose a file';
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return 'File is too large (max ' . formatBytes(maxUploadBytes()) . ')';
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return 'Upload failed, please try again';
    if ((int) $file['size'] <= 0) return 'The file is empty';
    if ((int) $file['size'] > maxUploadBytes()) return 'File is too large (max ' . formatBytes(maxUploadBytes()) . ')';
    $types = allowedUploadTypes();
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($types[$ext])) return 'File type not allowed. Allowed: ' . strtoupper(implode(', ', array_keys($types)));
    if (!in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '', $types[$ext], true)) return 'The file content does not match its .' . $ext . ' extension';
    return null;
}

function forumAttach($thread_id, $post_id, $user_id, $file) {
    global $db;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'none' => true];
    $r = storeUpload($file, 'forum');
    if (!$r['ok']) return $r;
    $db->insert('forum_attachments', ['thread_id' => (int) $thread_id, 'post_id' => $post_id ? (int) $post_id : null, 'user_id' => (int) $user_id,
        'file_path' => $r['path'], 'original_name' => $r['original_name'], 'file_type' => $r['ext'], 'file_size' => $r['size']]);
    return $r;
}

function forumAttachments($thread_id) {
    global $db;
    $out = [];
    foreach ($db->fetchAll("SELECT * FROM forum_attachments WHERE thread_id = ? ORDER BY id", [(int) $thread_id]) as $a) $out[(int) $a['post_id']][] = $a;
    return $out;
}

function forumDeleteFiles($thread_id, $post_id = null) {
    global $db;
    $rows = $post_id === null
        ? $db->fetchAll("SELECT file_path FROM forum_attachments WHERE thread_id = ?", [(int) $thread_id])
        : $db->fetchAll("SELECT file_path FROM forum_attachments WHERE thread_id = ? AND post_id = ?", [(int) $thread_id, (int) $post_id]);
    foreach ($rows as $r) if ($abs = uploadAbsolutePath($r['file_path'])) @unlink($abs);
}

/** The file chips under a topic / reply. */
function forumFilesHtml(array $files) {
    if (!$files) return '';
    $h = '<div class="forum-files">';
    foreach ($files as $f) {
        $h .= '<a class="forum-file" href="' . APP_URL . '/pages/forums/download.php?id=' . (int) $f['id'] . '" data-no-ajax><i class="fas ' . (in_array($f['file_type'], ['jpg', 'jpeg', 'png']) ? 'fa-file-image' : ($f['file_type'] === 'pdf' ? 'fa-file-pdf' : 'fa-paperclip')) . '"></i> '
            . '<span>' . htmlspecialchars($f['original_name']) . '</span><small>' . htmlspecialchars(formatBytes((int) $f['file_size'])) . '</small></a>';
    }
    return $h . '</div>';
}
