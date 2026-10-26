<?php
/**
 * SmartClass - teachers: invite new teachers from the app (loaded by includes/functions.php)
 *
 * An invite = name + email + a one-time link (random 32-byte token, valid TEACHER_INVITE_DAYS).
 * Only sha256(token) is stored; the link is shown once to the inviting teacher (and emailed
 * when email is set up). The invitee opens pages/auth/teacher_invite.php?token=… and sets
 * their own password; the account is a teacher, approved, from the start.
 */

const TEACHER_INVITE_DAYS = 7;

/** Absolute link for an invite token. */
function teacherInviteLink($token) {
    return APP_URL . '/pages/auth/teacher_invite.php?token=' . rawurlencode($token);
}

/** State of an invite row: pending | accepted | revoked | expired. */
function teacherInviteState(array $invite) {
    if ($invite['accepted_at']) return 'accepted';
    if ($invite['revoked_at']) return 'revoked';
    return strtotime($invite['expires_at']) < time() ? 'expired' : 'pending';
}

/**
 * Validate the invite form. Returns ['name' => msg, 'email' => msg] (empty strings = fine).
 * An email that already has an account, or a pending invite, is refused (renew that invite instead).
 */
function validateTeacherInvite($name, $email) {
    global $db;
    $errors = ['name' => '', 'email' => ''];
    if ($name === '') {
        $errors['name'] = 'Name is required';
    } elseif (mb_strlen($name) > 100) {
        $errors['name'] = 'Name must be under 100 characters';
    }
    if ($email === '') {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        $errors['email'] = 'Enter a valid email address';
    } elseif ($db->fetchOne("SELECT id FROM users WHERE email = ?", [$email])) {
        $errors['email'] = 'Someone already has an account with this email';
    } elseif ($db->fetchOne("SELECT id FROM teacher_invites WHERE email = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()", [$email])) {
        $errors['email'] = 'This email already has a waiting invite — use "New link" on it below';
    }
    return $errors;
}

/** Create an invite. Returns [invite id, token] — the token is only known now. */
function createTeacherInvite($name, $email, $inviter_id) {
    global $db;
    $token = bin2hex(random_bytes(32));
    $id = $db->insert('teacher_invites', [
        'name' => $name, 'email' => $email, 'token_hash' => hash('sha256', $token),
        'invited_by' => (int) $inviter_id,
        'expires_at' => date('Y-m-d H:i:s', strtotime('+' . TEACHER_INVITE_DAYS . ' days')),
    ]);
    return [$id, $token];
}

/** New token + new expiry for a not-yet-accepted invite (the old link stops working). Returns the token or null. */
function renewTeacherInvite($invite_id) {
    global $db;
    $token = bin2hex(random_bytes(32));
    $ok = $db->query(
        "UPDATE teacher_invites SET token_hash = ?, expires_at = ?, revoked_at = NULL WHERE id = ? AND accepted_at IS NULL",
        [hash('sha256', $token), date('Y-m-d H:i:s', strtotime('+' . TEACHER_INVITE_DAYS . ' days')), (int) $invite_id])->rowCount();
    return $ok ? $token : null;
}

/** The invite for a link token if it can still be used, else null. */
function findUsableTeacherInvite($token) {
    global $db;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $invite = $db->fetchOne(
        "SELECT i.*, u.name AS inviter_name FROM teacher_invites i LEFT JOIN users u ON i.invited_by = u.id
         WHERE i.token_hash = ?", [hash('sha256', $token)]);
    return ($invite && teacherInviteState($invite) === 'pending') ? $invite : null;
}

/**
 * Turn an invite into a teacher account. Returns the new user id, or null when the
 * invite was used meanwhile or the email got an account (checked again here).
 */
function acceptTeacherInvite(array $invite, $name, $password) {
    global $db;
    if ($db->fetchOne("SELECT id FROM users WHERE email = ?", [$invite['email']])) return null;
    if (limitReached('teachers')) return null;   // the provider's teacher limit
    // Claim the invite first, so the same link can never create two accounts
    $claimed = $db->query(
        "UPDATE teacher_invites SET accepted_at = NOW() WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()",
        [$invite['id']])->rowCount();
    if (!$claimed) return null;
    $user_id = $db->insert('users', [
        'name' => $name, 'email' => $invite['email'], 'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'teacher', 'is_approved' => 1, 'is_active' => 1,
    ]);
    $db->update('teacher_invites', ['accepted_user_id' => $user_id], 'id = ?', [$invite['id']]);
    logActivity('accept_teacher_invite', 'auth', "Teacher account created from invite #{$invite['id']}: {$name} ({$invite['email']})", $user_id);
    if ($invite['invited_by']) {
        notify($invite['invited_by'], 'teacher_joined', $name . ' accepted your invite',
            $name . ' (' . $invite['email'] . ') now has a teacher account.', 'pages/teachers/index.php', $user_id);
    }
    return $user_id;
}

/**
 * Email the invite link (when email is set up). Sent right away — the invitee has no
 * account, so it does not go through the per-user queue. Returns null on success or the error.
 */
function sendTeacherInviteEmail(array $invite, $token, $inviter_name) {
    $cfg = emailConfig();
    if (!$cfg['enabled']) return 'Email is not set up (Settings › Email Settings)';
    $link = $cfg['site_url'] . '/pages/auth/teacher_invite.php?token=' . rawurlencode($token);
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $subject = $inviter_name . ' invited you to teach on ' . APP_NAME;
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f4f6fb;font-family:Arial,sans-serif;">'
        . '<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;">'
        . '<h2 style="margin:0 0 12px;color:#4361ee;">' . $h(APP_NAME) . '</h2>'
        . '<p style="font-size:15px;color:#343a40;">Hi ' . $h($invite['name']) . ',</p>'
        . '<p style="font-size:15px;color:#343a40;">' . $h($inviter_name) . ' invited you to join ' . $h(APP_NAME) . ' as a teacher. '
        . 'Open the link below and choose your password. The link works once and expires in ' . TEACHER_INVITE_DAYS . ' days.</p>'
        . '<p style="margin:24px 0;"><a href="' . $h($link) . '" style="background:#4361ee;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;">Create my teacher account</a></p>'
        . '<p style="font-size:12px;color:#6c757d;word-break:break-all;">' . $h($link) . '</p>'
        . '<p style="font-size:12px;color:#6c757d;">If you were not expecting this, you can ignore this email.</p>'
        . '</div></body></html>';
    $text = "Hi {$invite['name']},\n\n{$inviter_name} invited you to join " . APP_NAME . " as a teacher.\n"
        . "Open this link and choose your password (works once, expires in " . TEACHER_INVITE_DAYS . " days):\n\n{$link}\n\n"
        . "If you were not expecting this, you can ignore this email.\n";
    try {
        sendEmailNow($cfg, $invite['email'], $invite['name'], $subject, $html, $text);
        return null;
    } catch (Throwable $e) {
        error_log('SmartClass: teacher invite email failed: ' . $e->getMessage());
        return $e->getMessage();
    }
}

/** Every teacher with their numbers, for the Teachers page. */
function getTeacherDirectory() {
    global $db;
    return $db->fetchAll(
        "SELECT u.id, u.name, u.email, u.created_at, u.last_login, u.is_active,
                (SELECT COUNT(*) FROM classes c WHERE c.teacher_id = u.id AND c.is_active = 1) AS classes,
                (SELECT COUNT(DISTINCT cm.student_id) FROM class_members cm INNER JOIN classes c ON cm.class_id = c.id
                  WHERE c.teacher_id = u.id AND c.is_active = 1) AS students
         FROM users u WHERE u.role = 'teacher' ORDER BY u.name");
}
