<?php
/**
 * SmartClass - Shared helper functions (loaded by config/db.php)
 *
 * EVERY module must use these:
 *   - notify() / notifyClassStudents() / notifyTeachers() when something happens
 *   - logActivity() for the audit trail
 *   - csrfField() in every POST form + verifyCsrf() before handling it
 *   - getAccessibleClass() / getUserClasses() for class-scoped access checks (includes/classes.php)
 *   - storeUpload() for every file upload (never the user's filename)
 */

// ==================== CSRF ====================

/** Per-session CSRF token (created on first use). */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop into every POST form. */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** True when the request carries the session's token (form field or X-CSRF-Token header). */
function verifyCsrf() {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrfToken(), $sent);
}

/**
 * A POST whose body was larger than post_max_size arrives with $_POST and
 * $_FILES empty — detect it so the user gets "file too large", not a CSRF error.
 */
function postBodyTooLarge() {
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

// ==================== CLASSES MODULE ====================

// Class access, settings, topics and gradebook live in their own module file
require_once __DIR__ . '/classes.php';

// App settings (stored in the database) and email notifications
require_once __DIR__ . '/settings.php';

// The school's name: editable in Settings › School (falls back to SmartClass); used in titles and emails
if (!defined('APP_NAME')) {
    $sc_name = trim((string) getSetting('school_name', ''));
    define('APP_NAME', $sc_name !== '' ? mb_substr($sc_name, 0, 60) : 'SmartClass');
}
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/research.php';
require_once __DIR__ . '/pager.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/suggestions.php';

// Teachers: invite new teachers from the app
require_once __DIR__ . '/teachers.php';

// Grading scale (ratings / letters / GPA), set by the school in Settings
require_once __DIR__ . '/grading.php';

// Final marks: mid / final exam, activities, quizzes, assignments with weights per class
require_once __DIR__ . '/final_marks.php';

// School-wide class figures for the super admin
require_once __DIR__ . '/school_report.php';

// The Settings area: one list of every setting, shared frame
require_once __DIR__ . '/settings_nav.php';

// Academic years: close a year, start the next, keep the old as a record
require_once __DIR__ . '/years.php';

// Excel (.xlsx) export writer
require_once __DIR__ . '/xlsx.php';

// Two-step sign-in (authenticator app + backup codes)
require_once __DIR__ . '/totp.php';

// Files attached to forum topics and replies
require_once __DIR__ . '/forum_files.php';

// Parent / guardian contact and absence alerts
require_once __DIR__ . '/guardian.php';

// Students sign in with a one-time email code
require_once __DIR__ . '/login_codes.php';

// AI providers (OpenAI, Claude, Gemini, Groq, ...) — Settings › AI Settings
require_once __DIR__ . '/ai.php';

// Lectures: safe formatting, publishing, materials, "Explain this code"
require_once __DIR__ . '/lectures.php';

// AI quiz generator (drafts questions for the quiz builder)
require_once __DIR__ . '/ai_quiz.php';

// Rubrics + AI grading assistant (suggests, the teacher decides)
require_once __DIR__ . '/ai_grading.php';
require_once __DIR__ . '/ai_insights.php';
require_once __DIR__ . '/quiz_short.php';
require_once __DIR__ . '/ai_study.php';
require_once __DIR__ . '/ai_writing.php';
require_once __DIR__ . '/ai_student.php';
require_once __DIR__ . '/ai_tutor.php';
require_once __DIR__ . '/print_lang.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/passwords.php';
require_once __DIR__ . '/hardening.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/support_access.php';
require_once __DIR__ . '/question_bank.php';
require_once __DIR__ . '/code_runner.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/bulk.php';
require_once __DIR__ . '/class_copy.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/license.php';
require_once __DIR__ . '/terms.php';

// Releasing results (hold grades back until the teacher releases them)
require_once __DIR__ . '/results.php';

// ==================== FILE UPLOADS ====================

/** App-side upload cap; the effective cap is also bounded by php.ini (see maxUploadBytes()). */
define('APP_MAX_UPLOAD_BYTES', 14 * 1024 * 1024);

/**
 * Allowed upload types: extension => accepted MIME types (detected from file content).
 * Office files are zip containers, so some servers report them as application/zip.
 */
function allowedUploadTypes() {
    $office_zip = ['application/zip', 'application/octet-stream'];
    return [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream', 'application/CDFV2'],
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $office_zip),
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream', 'application/CDFV2'],
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $office_zip),
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'],
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $office_zip),
        'txt'  => ['text/plain'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'mp4'  => ['video/mp4'],
    ];
}

/** Resource categories (key stored in resources.category => label), per designs/pages/resources/. */
function resourceCategories() {
    return [
        'research-paper' => 'Research Paper',
        'presentation'   => 'Presentation/Slides',
        'dataset'        => 'Dataset',
        'code'           => 'Code',
        'video'          => 'Video/Lecture',
        'book'           => 'Book/Textbook',
        'article'        => 'Article',
        'template'       => 'Template',
        'other'          => 'Other',
    ];
}

/** Bytes from a php.ini size like "2M". */
function iniBytes($value) {
    $value = trim((string) $value);
    $num = (float) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return (int) $num;
}

/** Effective per-file upload limit: the smallest of the app cap and the php.ini limits. */
function maxUploadBytes() {
    $limits = [APP_MAX_UPLOAD_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $b = iniBytes(ini_get($key));
        if ($b > 0) $limits[] = $b;
    }
    return min($limits);
}

/** "2.4 MB" style sizes. */
function formatBytes($bytes) {
    $bytes = (int) $bytes;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Create an uploads/ subfolder that the web server never serves directly
 * (files are streamed by access-checked PHP download pages instead).
 */
function ensureProtectedUploadDir($subdir) {
    $root = STORAGE_ROOT . '/uploads';
    $dir  = $root . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    // Never execute anything under uploads/; deny all direct access to this subfolder.
    if (!is_file($root . '/.htaccess')) {
        @file_put_contents($root . '/.htaccess',
            "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|php\\d?|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return is_writable($dir) ? $dir : false;
}

/**
 * Validate and store one uploaded file under uploads/<subdir>/ with a random name.
 * The user-supplied filename is never used on disk (only kept for display/download).
 *
 * @return array ['ok' => true, 'path', 'original_name', 'ext', 'mime', 'size']
 *            or ['ok' => false, 'error' => message]
 */
function storeUpload($file, $subdir) {
    if (!serviceEnabled('uploads')) return ['ok' => false, 'error' => 'File uploads are switched off for this installation by the service provider.'];
    $g = rateGuard('upload_user', (string) ($_SESSION['user_id'] ?? clientIp()), 40, 60);   // 40 uploads a minute per person
    if (!$g['ok']) return ['ok' => false, 'error' => 'Too many uploads at once. Please wait ' . rateWaitText($g['wait']) . '.'];
    $max = maxUploadBytes();

    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'Please choose a file'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => 'Please choose a file'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'File is too large (max ' . formatBytes($max) . ')'];
        default:
            return ['ok' => false, 'error' => 'Upload failed, please try again'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Upload failed, please try again'];
    }

    $size = (int) $file['size'];
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The file is empty'];
    }
    if ($size > $max) {
        return ['ok' => false, 'error' => 'File is too large (max ' . formatBytes($max) . ')'];
    }

    $types = allowedUploadTypes();
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        return ['ok' => false, 'error' => 'File type not allowed. Allowed: ' . strtoupper(implode(', ', array_keys($types)))];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: '';
    if (!in_array($mime, $types[$ext], true)) {
        return ['ok' => false, 'error' => 'The file content does not match its .' . $ext . ' extension'];
    }

    $dir = ensureProtectedUploadDir($subdir);
    if ($dir === false) {
        error_log("SmartClass: upload folder uploads/{$subdir} is missing or not writable");
        return ['ok' => false, 'error' => 'Server upload folder is not writable'];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        return ['ok' => false, 'error' => 'Upload failed, please try again'];
    }

    // Display name only: strip any path, control chars and quotes; cap the length.
    $original = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', basename((string) $file['name']));
    $original = mb_substr($original !== '' ? $original : ('file.' . $ext), 0, 200);

    return [
        'ok'            => true,
        'path'          => 'uploads/' . $subdir . '/' . $stored,
        'original_name' => $original,
        'ext'           => $ext,
        'mime'          => $mime,
        'size'          => $size,
    ];
}

/** Absolute path of a stored upload, or null if the stored path is not a real file inside uploads/. */
function uploadAbsolutePath($relative) {
    if (!is_string($relative) || strpos($relative, 'uploads/') !== 0) return null;
    $root = realpath(STORAGE_ROOT . '/uploads');
    $abs  = realpath(STORAGE_ROOT . '/' . $relative);
    if ($root === false || $abs === false || strpos($abs, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) {
        return null;
    }
    return $abs;
}

/** Stream a stored upload as a download (never rendered inline by the browser). */
/**
 * What the in-page viewer can show: extension => kind ('pdf', 'image', 'text'). Never HTML, SVG or anything a browser would run.
 */
function viewableKind($ext) {
    return ['pdf' => 'pdf', 'png' => 'image', 'jpg' => 'image', 'jpeg' => 'image', 'gif' => 'image', 'webp' => 'image', 'txt' => 'text'][strtolower((string) $ext)] ?? null;
}

/** Send a file. $inline = show it in the browser (only for viewableKind() types) instead of saving it. */
function sendDownload($abs_path, $download_name, $mime, $inline = false) {
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $download_name);
    $kind = viewableKind(pathinfo($abs_path, PATHINFO_EXTENSION));
    $inline = $inline && $kind !== null;
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . ($kind === 'text' && $inline ? 'text/plain; charset=utf-8' : ($mime ?: 'application/octet-stream')));
    header('X-Content-Type-Options: nosniff');
    // Shown in the page: pictures and text may load nothing and run nothing (sandbox). A PDF gets no CSP — a sandbox or a strict policy stops the browser's own PDF viewer from starting —
    // but it is still sent with its fixed type and nosniff, so it can never be treated as a web page.
    if (!($inline && $kind === 'pdf')) header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
    header('Content-Length: ' . filesize($abs_path));
    header('Cache-Control: private, no-store');
    readfile($abs_path);
    exit;
}

// ==================== ASSETS ====================

/**
 * URL of a file under assets/ with its modification time appended, so browsers
 * always load the current CSS/JS after an update (no stale cached styles).
 */
function asset($path) {
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return APP_URL . '/' . ltrim($path, '/') . '?v=' . $v;
}

// ==================== NOTIFICATIONS ====================

/**
 * Send a notification to one user.
 *
 * @param int         $user_id   Recipient user id
 * @param string      $type      Machine type, e.g. 'lecture_added', 'student_registered'
 * @param string      $title     Short title shown in the bell/inbox
 * @param string      $message   Longer text (optional)
 * @param string|null $link      Relative URL (from APP_URL) to open on click
 * @param int|null    $sender_id User who caused the notification
 * @param int|null    $class_id  Related class
 * @return int inserted notification id
 */
function notify($user_id, $type, $title, $message = '', $link = null, $sender_id = null, $class_id = null) {
    global $db;
    $id = $db->insert('notifications', [
        'user_id'   => $user_id,
        'sender_id' => $sender_id,
        'class_id'  => $class_id,
        'type'      => $type,
        'title'     => $title,
        'message'   => $message,
        'link'      => $link,
    ]);
    // Also email it when email is on and the user's preferences allow it (never throws)
    queueNotificationEmail($user_id, $type, $title, (string) $message, $link);
    // And ring the person's phones when they turned phone notifications on (never throws)
    try { pushQueue($user_id); } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
    return $id;
}

/**
 * Notify every student enrolled in a class (e.g. new lecture/resource/assignment).
 * Returns the number of students notified.
 */
function notifyClassStudents($class_id, $type, $title, $message = '', $link = null, $sender_id = null) {
    global $db;
    $students = $db->fetchAll(
        "SELECT student_id FROM class_members WHERE class_id = ?",
        [$class_id]
    );
    foreach ($students as $s) {
        notify($s['student_id'], $type, $title, $message, $link, $sender_id, $class_id);
    }
    return count($students);
}

/**
 * Notify all (active, approved) teachers — e.g. a new student registered.
 * Returns the number of teachers notified.
 */
function notifyTeachers($type, $title, $message = '', $link = null, $sender_id = null, $class_id = null) {
    global $db;
    $teachers = $db->fetchAll(
        "SELECT id FROM users WHERE role = 'teacher' AND is_active = 1"
    );
    foreach ($teachers as $t) {
        notify($t['id'], $type, $title, $message, $link, $sender_id, $class_id);
    }
    return count($teachers);
}

/**
 * Notify the school's super admin(s) — e.g. a student registered without a class code.
 * (Teachers only hear about students who ask to join one of their own classes.)
 */
function notifyAdmins($type, $title, $message = '', $link = null, $sender_id = null) {
    global $db;
    $admins = $db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1");
    foreach ($admins as $a) {
        notify($a['id'], $type, $title, $message, $link, $sender_id);
    }
    return count($admins);
}

/** Unread notification count for a user. */
function countUnreadNotifications($user_id) {
    global $db;
    return (int) $db->count('notifications', 'user_id = ? AND is_read = 0', [$user_id]);
}

/** Latest notifications for a user (unread first, then newest). */
function getNotifications($user_id, $limit = 10) {
    global $db;
    $limit = max(1, (int) $limit);
    return $db->fetchAll(
        "SELECT n.*, u.name AS sender_name
         FROM notifications n
         LEFT JOIN users u ON n.sender_id = u.id
         WHERE n.user_id = ?
         ORDER BY n.is_read ASC, n.created_at DESC
         LIMIT {$limit}",
        [$user_id]
    );
}

/** Mark one notification as read (only if it belongs to the user). */
function markNotificationRead($notification_id, $user_id) {
    global $db;
    return $db->update(
        'notifications',
        ['is_read' => 1],
        'id = ? AND user_id = ?',
        [$notification_id, $user_id]
    );
}

/** Font Awesome icon + colour class for a notification type. */
function notificationIcon($type) {
    $map = [
        'student_registered'  => ['fa-user-plus', 'warning'],
        'student_approved'    => ['fa-user-check', 'success'],
        'student_joined'      => ['fa-door-open', 'primary'],
        'teacher_joined'      => ['fa-chalkboard-teacher', 'success'],
        'co_teacher_added'    => ['fa-chalkboard-teacher', 'primary'],
        'removed_from_class'  => ['fa-user-minus', 'warning'],
        'lecture_added'       => ['fa-video', 'primary'],
        'resource_added'      => ['fa-folder-open', 'info'],
        'research_update'     => ['fa-flask', 'info'],
        'research_reminder'   => ['fa-hourglass-half', 'warning'],
        'assignment_created'  => ['fa-tasks', 'warning'],
        'submission_received' => ['fa-file-upload', 'info'],
        'submission_graded'   => ['fa-star', 'success'],
        'submission_returned' => ['fa-rotate-left', 'warning'],
        'result_released'     => ['fa-bullhorn', 'success'],
        'work_reminder'       => ['fa-bell', 'warning'],
        'event_created'       => ['fa-calendar-check', 'info'],
        'event_updated'       => ['fa-calendar-day', 'warning'],
        'schedule_updated'    => ['fa-table-cells', 'warning'],
        'exam_scheduled'      => ['fa-file-alt', 'warning'],
        'forum_post'          => ['fa-comments', 'info'],
        'support_access'      => ['fa-life-ring', 'warning'],
        'quiz_created'        => ['fa-question-circle', 'warning'],
        'quiz_submitted'      => ['fa-clipboard-check', 'info'],
        'announcement_posted' => ['fa-bullhorn', 'primary'],
        'comment_posted'      => ['fa-comment', 'info'],
        'attendance_absent'   => ['fa-user-times', 'warning'],
        'attendance_warning'  => ['fa-exclamation-triangle', 'warning'],
        'quiz_reopened'       => ['fa-redo', 'info'],
        'attendance_excuse'   => ['fa-comment-medical', 'info'],
        'join_requested'      => ['fa-user-clock', 'warning'],
        'private_message'     => ['fa-user-lock', 'primary'],
        'join_approved'       => ['fa-door-open', 'success'],
        'join_declined'       => ['fa-door-closed', 'warning'],
    ];
    return $map[$type] ?? ['fa-bell', 'primary'];
}

/**
 * A stored notification link if it is a safe in-app path ("pages/...php?..."),
 * else null — so opening a notification can never redirect off-site.
 */
function safeNotificationLink($link) {
    $link = (string) $link;
    return preg_match('#^pages/[A-Za-z0-9_/-]+\.php(\?[A-Za-z0-9_=&%.-]*)?(\#[A-Za-z0-9_-]+)?$#', $link) && strpos($link, '..') === false
        ? $link : null;
}

/** Mark all of a user's notifications as read. */
function markAllNotificationsRead($user_id) {
    global $db;
    return $db->update('notifications', ['is_read' => 1], 'user_id = ?', [$user_id]);
}

// ==================== TIME ====================

/** "5 minutes ago" style timestamps. */
function timeAgo($datetime) {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    $ago = fn($n, $unit) => $n . ' ' . $unit . ($n == 1 ? '' : 's') . ' ago';
    if ($diff < 3600) return $ago(floor($diff / 60), 'minute');
    if ($diff < 86400) return $ago(floor($diff / 3600), 'hour');
    if ($diff < 604800) return $ago(floor($diff / 86400), 'day');
    return date('M d, Y', $ts);
}

/**
 * Plain text -> safe HTML with http(s) links made clickable (new tab, no referrer / opener).
 * The text is split on URLs and every piece is escaped, so nothing else becomes HTML.
 * Trailing punctuation (.,;:!?) and an unmatched ")" stay outside the link.
 */
/**
 * A teacher's text shown properly: paragraphs, bold headings (a short line ending in ":" or "## …"), real bullet and numbered lists, **bold**, *italic*, `code` and links —
 * made from plain text (what the AI writes and what people type). Everything is escaped first, so nothing typed can become HTML. Use it where the text is for reading
 * (announcements, instructions, descriptions); NOT for what a student typed as an answer.
 */
function richText($text) {
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    if ($text === '') return '';
    $inline = function ($line) {
        $h = linkify($line);   // escapes the text and links bare addresses
        $h = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/su', '<strong>$2</strong>', $h);
        $h = preg_replace('/(?<![*\w])\*(?![\s*])(.+?)(?<![\s*])\*(?![*\w])/u', '<em>$1</em>', $h);
        return preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $h);
    };
    $isBullet = fn($l) => (bool) preg_match('/^\s*[-*•]\s+\S/u', $l);
    $isNumber = fn($l) => (bool) preg_match('/^\s*\d{1,3}[.)]\s+\S/', $l);
    $isHeading = fn($l) => (bool) preg_match('/^\s{0,3}#{1,6}\s*\S/', $l) || (mb_strlen(trim($l)) <= 80 && (bool) preg_match('/\S:\s*$/u', $l) && !preg_match('~https?://~', $l));
    $out = '';
    foreach (preg_split('/\n{2,}/', $text) as $block) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn($l) => trim($l) !== ''));
        for ($i = 0, $n = count($lines); $i < $n;) {
            $l = $lines[$i];
            if ($isBullet($l) || $isNumber($l)) {
                $ordered = $isNumber($l); $items = '';
                while ($i < $n && ($ordered ? $isNumber($lines[$i]) : $isBullet($lines[$i]))) { $items .= '<li>' . $inline(preg_replace('/^\s*(?:[-*•]|\d{1,3}[.)])\s+/u', '', $lines[$i])) . '</li>'; $i++; }
                $out .= $ordered ? "<ol>{$items}</ol>" : "<ul>{$items}</ul>";
            } elseif ($isHeading($l)) {
                $out .= '<p class="rt-h">' . $inline(preg_replace('/^\s{0,3}#{1,6}\s*/', '', $l)) . '</p>'; $i++;
            } else {
                $para = [];
                while ($i < $n && !$isBullet($lines[$i]) && !$isNumber($lines[$i]) && !($para && $isHeading($lines[$i]))) { $para[] = $inline($lines[$i]); $i++; }
                $out .= '<p>' . implode('<br>', $para) . '</p>';
            }
        }
    }
    return '<div class="rich-text" dir="auto">' . $out . '</div>';
}

function linkify($text) {
    $text = (string) $text;
    $parts = preg_split('~(https?://[^\s<>"\']+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $html .= htmlspecialchars($part);
            continue;
        }
        $tail = '';
        while ($part !== '' && (strpbrk(substr($part, -1), '.,;:!?') !== false
               || (substr($part, -1) === ')' && substr_count($part, '(') < substr_count($part, ')')))) {
            $tail = substr($part, -1) . $tail;
            $part = substr($part, 0, -1);
        }
        $html .= '<a href="' . htmlspecialchars($part) . '" target="_blank" rel="noopener noreferrer nofollow">' . htmlspecialchars($part) . '</a>'
               . htmlspecialchars($tail);
    }
    return $html;
}

// ==================== ACTIVITY LOG ====================

/**
 * Write one row to the audit trail.
 *
 * @param string      $action  e.g. 'login', 'create_class', 'submit_assignment'
 * @param string      $module  e.g. 'auth', 'classes', 'assignments'
 * @param string|null $details Human-readable details
 * @param int|null    $user_id Defaults to the logged-in user (null allowed, e.g. failed logins)
 */
/** One CSV line (PHP 8.4 wants the escape character stated; \\ is the long-standing default). */
function csvRow($out, array $row) {
    return fputcsv($out, $row, ',', '"', '\\');
}

function logActivity($action, $module, $details = null, $user_id = null) {
    global $db;
    return $db->insert('activity_log', [
        'user_id'    => $user_id ?? ($_SESSION['user_id'] ?? null),
        'action'     => $action,
        'module'     => $module,
        'details'    => $details,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

// ==================== BACK LINKS ====================

/**
 * Where the "Back" link goes: the page the person CAME from when that was a page of this app (so a list keeps its filters and page number),
 * otherwise $default. Never back to the same page, to a sign-in page, or to another site. $default may be a relative address like "view.php".
 */
function backUrl($default) {
    $default = html_entity_decode((string) $default, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $p = $ref !== '' ? parse_url($ref) : false;
    if (!$p || empty($p['host'])) return $default;
    $host = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    $mine = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== $mine && strtolower($p['host']) !== preg_replace('/:\d+$/', '', $mine)) return $default;            // another site
    $path = (string) ($p['path'] ?? '');
    $base = (string) (parse_url(APP_URL, PHP_URL_PATH) ?: '');
    if (strpos($path, $base . '/pages/') !== 0) return $default;                                                       // only the app's own pages
    if (strpos($path, $base . '/pages/auth/') === 0) return $default;                                                   // never back to sign-in
    if (preg_match('#/pages/(search|notifications/(push|latest|open)|ai/|profile/photo)#', $path) || substr($path, -4) !== '.php') return $default;   // endpoints, not screens
    if ($path === parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) return $default;                    // the same page (e.g. after a form error)
    return $path . (isset($p['query']) ? '?' . $p['query'] : '');
}

/** The usual "← Label" link at the top of a page. */
function backLink($label, $default) {
    return '<a class="back-link" href="' . htmlspecialchars(backUrl($default)) . '"><i class="fas fa-arrow-left" aria-hidden="true"></i> ' . htmlspecialchars($label) . '</a>';
}

/** A person's round picture: their photo when they have one (served by pages/profile/photo.php to people allowed to see them), else the first letter. */
function avatarHtml($user_id, $name, $extra_class = '', $style = '') {
    static $photos = null;
    global $db;
    if ($photos === null) { $photos = []; foreach ($db->fetchAll("SELECT id, photo FROM users WHERE photo IS NOT NULL AND photo <> ''") as $r) $photos[(int) $r['id']] = basename((string) $r['photo']); }
    $cls = 'avatar' . ($extra_class !== '' ? ' ' . $extra_class : '');
    $st = $style !== '' ? ' style="' . htmlspecialchars($style) . '"' : '';
    if (!empty($photos[(int) $user_id])) return '<img class="' . $cls . ' avatar-img" src="' . APP_URL . '/pages/profile/photo.php?u=' . (int) $user_id . '&amp;s=' . (preg_match('/width:\s*(\d+)px/', $style, $m) && (int) $m[1] > 44 ? 256 : 128) . '&amp;v=' . urlencode($photos[(int) $user_id]) . '" alt="' . htmlspecialchars((string) $name) . '" loading="lazy"' . $st . '>';
    return '<span class="' . $cls . '" aria-hidden="true"' . $st . '>' . htmlspecialchars(mb_strtoupper(mb_substr((string) $name, 0, 1))) . '</span>';
}

/** The "View" button next to a file's Download button (PDF, pictures and text open in a viewer inside the page; no button for other types). */
function fileViewButton($view_href, $download_href, $name, $ext, $class = 'icon-btn') {
    $kind = viewableKind($ext);
    if ($kind === null) return '';
    return '<a class="' . htmlspecialchars($class) . '" style="width:auto;padding:0 10px;" href="' . htmlspecialchars($view_href) . '" target="_blank" rel="noopener" data-file-view="' . $kind . '" data-name="' . htmlspecialchars((string) $name) . '" data-download="' . htmlspecialchars($download_href) . '"><i class="fas fa-eye" aria-hidden="true"></i>&nbsp;' . htmlspecialchars(t('View')) . '</a>';
}

/**
 * A submitted file as the teacher / student sees it: the NAME opens it right away in the viewer (PDF, pictures, text) and Download is its own button;
 * a picture also shows a small thumbnail. Types the viewer cannot show (Word, zip …) just download. $base = where download.php lives (default: next to the page).
 */
function submissionFileHtml($submission_id, $name, $file_path, $base = '', $small = true) {
    $ext = pathinfo((string) $file_path, PATHINFO_EXTENSION); $kind = viewableKind($ext); $name = $name !== null && $name !== '' ? $name : 'file';
    $dl = $base . 'download.php?id=' . (int) $submission_id; $view = $dl . '&view=1';
    $cls = $small ? 'icon-btn' : 'btn-app btn-outline btn-sm'; $st = $small ? ' style="width:auto;padding:0 10px;margin-top:6px;"' : '';
    $n = htmlspecialchars($name);
    if ($kind === null) return '<a class="' . $cls . '"' . $st . ' href="' . htmlspecialchars($dl) . '"><i class="fas fa-download" aria-hidden="true"></i>&nbsp;' . $n . '</a>';
    $h = '<div class="sub-file">';
    if ($kind === 'image') $h .= '<a class="sub-thumb" href="' . htmlspecialchars($view) . '" target="_blank" rel="noopener" data-file-view="image" data-name="' . $n . '" data-download="' . htmlspecialchars($dl) . '" aria-label="' . htmlspecialchars(t('View')) . ' ' . $n . '"><img src="' . htmlspecialchars($view) . '" alt="" loading="lazy"></a>';
    $h .= '<a class="' . $cls . '"' . $st . ' href="' . htmlspecialchars($view) . '" target="_blank" rel="noopener" data-file-view="' . $kind . '" data-name="' . $n . '" data-download="' . htmlspecialchars($dl) . '" title="' . htmlspecialchars(t('View')) . '"><i class="fas fa-eye" aria-hidden="true"></i>&nbsp;' . $n . '</a>';
    $h .= '<a class="' . $cls . '"' . $st . ' href="' . htmlspecialchars($dl) . '"><i class="fas fa-download" aria-hidden="true"></i>&nbsp;' . htmlspecialchars(t('Download')) . '</a>';
    return $h . '</div>';
}
