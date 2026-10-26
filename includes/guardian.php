<?php
/**
 * SmartClass - parent / guardian alerts (loaded by includes/functions.php)
 * A student (or their teacher) can record a guardian's name, e-mail and phone. When the school switches "guardian alerts" on
 * (Settings › Attendance rules) and e-mail is set up, the guardian gets an e-mail when the student is marked absent or is
 * reminded about low attendance. Every e-mail has a "stop these e-mails" link (signed, one click); nothing is sent to an
 * address nobody confirmed they own beyond this — the student / teacher who entered it is responsible for it.
 *   guardianAlertsOn()                    school switch (default off)
 *   guardianStopToken($student_id, $email) HMAC for the stop link
 *   queueGuardianEmail($student_id, $subject, $message)   queue one e-mail (never throws, never duplicates within 12 hours per subject)
 */
function guardianAlertsOn() {
    return (string) getSetting('guardian_alerts', '0') === '1';
}

function guardianStopToken($student_id, $email) {
    return hash_hmac('sha256', 'guardian|' . (int) $student_id . '|' . strtolower($email), secretKey());
}

function queueGuardianEmail($student_id, $subject, $message) {
    global $db;
    try {
        if (!guardianAlertsOn()) return false;
        $cfg = emailConfig();
        if (!$cfg['enabled']) return false;
        $s = $db->fetchOne("SELECT id, name, guardian_name, guardian_email, guardian_alerts FROM users WHERE id = ? AND role = 'student' AND is_active = 1", [(int) $student_id]);
        if (!$s || !(int) $s['guardian_alerts'] || !filter_var($s['guardian_email'], FILTER_VALIDATE_EMAIL)) return false;
        if ($db->fetchOne("SELECT id FROM email_queue WHERE to_email = ? AND category = 'guardian' AND subject = ? AND created_at > NOW() - INTERVAL 12 HOUR LIMIT 1", [$s['guardian_email'], mb_substr($subject, 0, 200) . ' — ' . APP_NAME])) return false;
        $stop = $cfg['site_url'] . '/pages/email/guardian_stop.php?s=' . (int) $s['id'] . '&t=' . guardianStopToken($s['id'], $s['guardian_email']);
        $h = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $hello = $s['guardian_name'] ? 'Dear ' . $s['guardian_name'] : 'Dear parent / guardian';
        $html = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">'
            . '<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;"><tr><td style="padding:28px 28px 20px;">'
            . '<p style="margin:0 0 6px;font-size:14px;color:#6b7280;">' . $h($hello) . ',</p>'
            . '<h1 style="margin:0 0 12px;font-size:19px;color:#111827;">' . $h($subject) . '</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;color:#374151;">' . nl2br($h($message)) . '</p></td></tr>'
            . '<tr><td style="padding:14px 28px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;line-height:1.6;">You get this because ' . $h($s['name']) . ' (or the school) listed this address as a parent / guardian contact at ' . $h(APP_NAME) . '. '
            . '<a href="' . $h($stop) . '" style="color:#4361ee;">Stop these e-mails</a></td></tr></table></td></tr></table></body></html>';
        $text = "{$hello},\n\n{$subject}\n\n{$message}\n\n--\nYou get this because {$s['name']} (or the school) listed this address as a guardian contact at " . APP_NAME . ".\nStop these e-mails: {$stop}\n";
        $db->insert('email_queue', ['user_id' => null, 'to_email' => $s['guardian_email'], 'to_name' => $s['guardian_name'], 'category' => 'guardian',
                                    'subject' => mb_substr($subject, 0, 200) . ' — ' . APP_NAME, 'body_html' => $html, 'body_text' => $text, 'status' => 'pending']);
        if ($cfg['delivery'] === 'immediate') {
            static $registered = false;
            if (!$registered) {
                $registered = true;
                register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); processEmailQueue(20); });
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('SmartClass: could not queue guardian email: ' . $e->getMessage());
        return false;
    }
}
