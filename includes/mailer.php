<?php
/**
 * SmartClass - Email module (loaded by includes/functions.php)
 *
 * Every in-app notification can also be emailed:
 *   notify() -> queueNotificationEmail()   respects the email settings + the user's preferences
 *   processEmailQueue($limit)               sends pending emails (cron/send_emails.php, the
 *                                           settings page, or right after the request when
 *                                           delivery = immediate)
 *   buildDailyDigests()                     one summary email per "daily" user (cron)
 *   sendEmailNow()                          SMTP / PHP mail(), used by the queue + "Send test email"
 *
 * Settings (app_settings, edited on pages/settings/email.php):
 *   email_enabled, email_transport (smtp|mail), smtp_host, smtp_port, smtp_encryption (none|ssl|tls),
 *   smtp_username, smtp_password (encrypted), from_email, from_name, site_url, email_delivery (cron|immediate)
 */

define('EMAIL_MAX_ATTEMPTS', 3);

// ==================== CONFIG ====================

/** Email configuration from the settings (password decrypted). */
function emailConfig() {
    return [
        'enabled'    => getSetting('email_enabled', '0') === '1' && serviceEnabled('email') && !SC_STAGING,   // a staging copy never sends e-mail
        'transport'  => getSetting('email_transport', 'smtp'),
        'host'       => (string) getSetting('smtp_host', ''),
        'port'       => (int) getSetting('smtp_port', '587'),
        'encryption' => getSetting('smtp_encryption', 'tls'),
        'username'   => (string) getSetting('smtp_username', ''),
        'password'   => decryptSecret(getSetting('smtp_password', '')),
        'from_email' => (string) getSetting('from_email', ''),
        'from_name'  => (string) getSetting('from_name', APP_NAME),
        'site_url'   => rtrim((string) getSetting('site_url', APP_URL), '/'),
        'delivery'   => getSetting('email_delivery', 'cron'),
    ];
}

/** Email category of a notification type (matches the columns of email_preferences). */
function emailCategory($type) {
    $map = [
        'lecture_added' => 'classwork', 'resource_added' => 'classwork', 'assignment_created' => 'classwork',
        'quiz_created' => 'classwork', 'quiz_reopened' => 'classwork', 'announcement_posted' => 'classwork',
        'submission_graded' => 'grades', 'submission_returned' => 'grades', 'result_released' => 'grades', 'work_reminder' => 'classwork', 'event_created' => 'classwork', 'event_updated' => 'classwork', 'schedule_updated' => 'classwork', 'exam_scheduled' => 'classwork', 'forum_post' => 'comments', 'quiz_submitted' => 'grades', 'submission_received' => 'grades',
        'attendance_absent' => 'attendance', 'attendance_warning' => 'attendance', 'attendance_excuse' => 'attendance',
        'comment_posted' => 'comments', 'private_message' => 'comments', 'research_update' => 'comments', 'research_reminder' => 'classwork',
        'student_registered' => 'approvals', 'student_approved' => 'approvals', 'join_requested' => 'approvals',
        'join_approved' => 'approvals', 'join_declined' => 'approvals', 'student_joined' => 'approvals',
        'removed_from_class' => 'approvals', 'teacher_joined' => 'approvals', 'co_teacher_added' => 'approvals',
    ];
    return $map[$type] ?? 'general';
}

/** Labels for the preference categories (profile page). */
function emailCategoryLabels() {
    return [
        'classwork'  => ['New classwork', 'Lectures, resources, assignments, quizzes and announcements'],
        'grades'     => ['Grades & submissions', 'Graded work, quiz results, work handed in to you'],
        'attendance' => ['Attendance', 'When you are marked absent'],
        'comments'   => ['Comments & posts', 'Replies, class comments and private comments'],
        'approvals'  => ['Account & class approvals', 'Registrations, join requests, approvals'],
    ];
}

/** A user's email preferences (defaults when never saved). */
function getEmailPreferences($user_id) {
    global $db;
    $row = $db->fetchOne("SELECT * FROM email_preferences WHERE user_id = ?", [(int) $user_id]);
    return $row ?: ['user_id' => (int) $user_id, 'mode' => 'instant', 'classwork' => 1, 'grades' => 1,
                    'attendance' => 1, 'comments' => 1, 'approvals' => 1];
}

/** Secret token for a user's unsubscribe link (created on first use). */
function emailToken($user_id) {
    global $db;
    $row = $db->fetchOne("SELECT email_token FROM users WHERE id = ?", [(int) $user_id]);
    if (!empty($row['email_token'])) return $row['email_token'];
    $token = bin2hex(random_bytes(24));
    $db->update('users', ['email_token' => $token], 'id = ?', [(int) $user_id]);
    return $token;
}

// ==================== TEMPLATE ====================

/** [html, text] for one notification email. All dynamic text is escaped. */
function renderNotificationEmail($cfg, $user, $title, $message, $link, $unsubscribe_url) {
    $site = $cfg['site_url'];
    $safe_link = ($link && function_exists('safeNotificationLink') && safeNotificationLink($link)) ? $site . '/' . $link : $site . '/pages/dashboard.php';
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $prefs_url = $site . '/pages/settings/notifications.php';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $h($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#4361ee;background:linear-gradient(135deg,#4361ee,#7209b7);padding:18px 24px;color:#ffffff;font-size:20px;font-weight:bold;">'
        . $h(APP_NAME) . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 6px;font-size:14px;color:#6b7280;">Hi ' . $h($user['name']) . ',</p>'
        . '<h1 style="margin:0 0 12px;font-size:20px;line-height:1.35;color:#111827;">' . $h($title) . '</h1>'
        . ($message !== '' ? '<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#374151;">' . nl2br($h($message)) . '</p>' : '')
        . '<a href="' . $h($safe_link) . '" style="display:inline-block;background:#4361ee;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;font-size:15px;">Open in ' . $h(APP_NAME) . '</a>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;line-height:1.6;">'
        . 'You get this email because of your ' . $h(APP_NAME) . ' notification settings. '
        . '<a href="' . $h($prefs_url) . '" style="color:#4361ee;">Email preferences</a> · '
        . '<a href="' . $h($unsubscribe_url) . '" style="color:#4361ee;">Unsubscribe</a>'
        . '</td></tr></table></td></tr></table></body></html>';

    $text = "Hi {$user['name']},\n\n{$title}\n" . ($message !== '' ? "\n{$message}\n" : '')
        . "\nOpen in " . APP_NAME . ": {$safe_link}\n\n--\nEmail preferences: {$prefs_url}\nUnsubscribe: {$unsubscribe_url}\n";
    return [$html, $text];
}

// ==================== QUEUE ====================

/**
 * Called by notify(): queue an email for this notification if email is on, the
 * user is active and their preferences allow this category. Never throws.
 */
function queueNotificationEmail($user_id, $type, $title, $message, $link) {
    global $db;
    try {
        $cfg = emailConfig();
        if (!$cfg['enabled']) return;

        $user = $db->fetchOne("SELECT id, name, email, is_active FROM users WHERE id = ?", [(int) $user_id]);
        if (!$user || !(int) $user['is_active'] || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) return;

        $prefs = getEmailPreferences($user['id']);
        $category = emailCategory($type);
        if ($prefs['mode'] === 'off' || (isset($prefs[$category]) && !(int) $prefs[$category])) return;

        $unsubscribe = $cfg['site_url'] . '/pages/email/unsubscribe.php?u=' . (int) $user['id'] . '&t=' . emailToken($user['id']);
        [$html, $text] = renderNotificationEmail($cfg, $user, $title, (string) $message, $link, $unsubscribe);

        $db->insert('email_queue', [
            'user_id'   => $user['id'],
            'to_email'  => $user['email'],
            'to_name'   => $user['name'],
            'category'  => $category,
            'subject'   => mb_substr($title, 0, 200) . ' — ' . APP_NAME,
            'body_html' => $html,
            'body_text' => $text,
            'status'    => $prefs['mode'] === 'daily' ? 'held' : 'pending',
        ]);

        // "Immediate" delivery: send a small batch once the page has been sent to the browser
        if ($cfg['delivery'] === 'immediate' && $prefs['mode'] !== 'daily') {
            static $registered = false;
            if (!$registered) {
                $registered = true;
                register_shutdown_function(function () {
                    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                    processEmailQueue(20);
                });
            }
        }
    } catch (Throwable $e) {
        error_log('SmartClass: could not queue email: ' . $e->getMessage());
    }
}

/**
 * Send up to $limit pending emails. Failed sends are retried (EMAIL_MAX_ATTEMPTS),
 * then marked failed with the error. Returns ['sent' => n, 'failed' => n].
 */
function processEmailQueue($limit = 50) {
    global $db;
    $cfg = emailConfig();
    $result = ['sent' => 0, 'failed' => 0];
    if (!$cfg['enabled']) return $result;

    $limit = max(1, (int) $limit);
    foreach ($db->fetchAll(
        "SELECT * FROM email_queue WHERE status = 'pending' AND attempts < " . EMAIL_MAX_ATTEMPTS . " ORDER BY id LIMIT {$limit}") as $mail) {
        // Claim the row first so two senders (cron + immediate) never send it twice
        if ($db->update('email_queue', ['attempts' => (int) $mail['attempts'] + 1], 'id = ? AND attempts = ? AND status = ?',
                [$mail['id'], $mail['attempts'], 'pending']) === 0) {
            continue;
        }
        try {
            $unsub = null;
            if (preg_match('#(https?://[^\s"<>]+/pages/email/unsubscribe\.php\?u=\d+&t=[0-9a-f]+)#', $mail['body_text'], $m)) {
                $unsub = $m[1];
            }
            sendEmailNow($cfg, $mail['to_email'], $mail['to_name'], $mail['subject'], $mail['body_html'], $mail['body_text'], $unsub);
            $db->update('email_queue', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'last_error' => null], 'id = ?', [$mail['id']]);
            $result['sent']++;
        } catch (Throwable $e) {
            $final = (int) $mail['attempts'] + 1 >= EMAIL_MAX_ATTEMPTS;
            $db->update('email_queue', ['status' => $final ? 'failed' : 'pending', 'last_error' => mb_substr($e->getMessage(), 0, 500)],
                'id = ?', [$mail['id']]);
            $result['failed']++;
            error_log('SmartClass: email #' . $mail['id'] . ' failed: ' . $e->getMessage());
        }
    }
    return $result;
}

/** Combine each "daily" user's held emails into one digest email (run once a day by cron). */
function buildDailyDigests() {
    global $db;
    $cfg = emailConfig();
    if (!$cfg['enabled']) return 0;
    $made = 0;
    foreach ($db->fetchAll("SELECT DISTINCT user_id FROM email_queue WHERE status = 'held' AND user_id IS NOT NULL") as $u) {
        $user = $db->fetchOne("SELECT id, name, email, is_active FROM users WHERE id = ?", [$u['user_id']]);
        $rows = $db->fetchAll("SELECT id, subject, created_at FROM email_queue WHERE user_id = ? AND status = 'held' ORDER BY id", [$u['user_id']]);
        $ids = array_column($rows, 'id');
        if ($user && (int) $user['is_active'] && $rows) {
            $lines = array_map(fn($r) => '• ' . preg_replace('/ — ' . preg_quote(APP_NAME, '/') . '$/', '', $r['subject'])
                . ' (' . date('M d, h:i A', strtotime($r['created_at'])) . ')', $rows);
            $unsubscribe = $cfg['site_url'] . '/pages/email/unsubscribe.php?u=' . (int) $user['id'] . '&t=' . emailToken($user['id']);
            $title = count($rows) . ' update' . (count($rows) == 1 ? '' : 's') . ' in ' . APP_NAME . ' today';
            [$html, $text] = renderNotificationEmail($cfg, $user, $title, implode("\n", $lines), 'pages/notifications/index.php', $unsubscribe);
            $db->insert('email_queue', [
                'user_id' => $user['id'], 'to_email' => $user['email'], 'to_name' => $user['name'], 'category' => 'digest',
                'subject' => 'Your daily summary — ' . APP_NAME, 'body_html' => $html, 'body_text' => $text, 'status' => 'pending',
            ]);
            $made++;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->query("UPDATE email_queue SET status = 'digested' WHERE id IN ({$in})", $ids);
    }
    return $made;
}

// ==================== SENDING ====================

/** Header-safe text: no CR/LF (prevents header injection). */
function emailHeaderText($s) {
    return trim(preg_replace('/[\r\n]+/', ' ', (string) $s));
}

/** "Name" <email>: plain ASCII names are quoted (RFC 5322), others RFC 2047-encoded. */
function emailAddressHeader($email, $name) {
    $name = emailHeaderText($name);
    if ($name === '') return '<' . $email . '>';
    if (preg_match('/^[\x20-\x7E]*$/', $name)) {
        return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
    }
    return mb_encode_mimeheader($name, 'UTF-8', 'Q') . ' <' . $email . '>';
}

/** Full MIME message (headers + multipart/alternative body) with CRLF line endings. */
function buildMimeMessage($cfg, $to, $to_name, $subject, $html, $text, $unsubscribe_url) {
    $boundary = 'sc-' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($cfg['from_email'], '@'), 1) ?: 'smartclass.local';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . emailAddressHeader($cfg['from_email'], $cfg['from_name']),
        'To: ' . emailAddressHeader($to, $to_name),
        'Subject: ' . mb_encode_mimeheader(emailHeaderText($subject), 'UTF-8', 'Q'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($unsubscribe_url) {
        $headers[] = 'List-Unsubscribe: <' . emailHeaderText($unsubscribe_url) . '>';
    }
    $part = fn($type, $body) => "--{$boundary}\r\nContent-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $body)) . "\r\n";
    $body = $part('text/plain', $text) . $part('text/html', $html) . "--{$boundary}--\r\n";
    return [$headers, str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body)];
}

/**
 * Send one email now. Throws RuntimeException with a readable reason on failure.
 */
function sendEmailNow($cfg, $to, $to_name, $subject, $html, $text, $unsubscribe_url = null) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient address');
    if (!filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Set a valid "From" address in the email settings');

    [$headers, $body] = buildMimeMessage($cfg, $to, $to_name, $subject, $html, $text, $unsubscribe_url);

    if ($cfg['transport'] === 'mail') {
        // PHP mail(): To and Subject are passed separately
        $extra = array_values(array_filter($headers, fn($h) => stripos($h, 'To:') !== 0 && stripos($h, 'Subject:') !== 0));
        $ok = @mail($to, mb_encode_mimeheader(emailHeaderText($subject), 'UTF-8', 'Q'), $body, implode("\r\n", $extra), '-f' . $cfg['from_email']);
        if (!$ok) throw new RuntimeException('PHP mail() could not send (no local mail server?)');
        return;
    }
    smtpSend($cfg, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
}

/** Minimal SMTP client: SSL or STARTTLS, AUTH LOGIN, one recipient. */
function smtpSend($cfg, $to, $message) {
    if ($cfg['host'] === '') throw new RuntimeException('Set the SMTP host in the email settings');
    $remote = ($cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . ($cfg['port'] ?: 587);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Could not connect to {$cfg['host']}:{$cfg['port']} ({$errstr})");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($line, $expect) use ($fp, $read) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $resp = $read();
        if (!in_array((int) substr($resp, 0, 3), (array) $expect, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp ?: 'no response'));
        }
        return $resp;
    };
    $helo = 'smartclass.local';

    try {
        $cmd(null, 220);
        $ehlo = $cmd('EHLO ' . $helo, 250);
        if ($cfg['encryption'] === 'tls') {
            $cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Could not start TLS encryption');
            }
            $ehlo = $cmd('EHLO ' . $helo, 250);
        }
        if ($cfg['username'] !== '') {
            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode($cfg['username']), 334);
            $cmd(base64_encode($cfg['password']), 235);
        }
        $cmd('MAIL FROM:<' . $cfg['from_email'] . '>', 250);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', 354);
        // Dot-stuffing: a line starting with "." gets another "."
        fwrite($fp, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
        $cmd(null, 250);
        $cmd('QUIT', [221, 250]);
    } finally {
        fclose($fp);
    }
}
