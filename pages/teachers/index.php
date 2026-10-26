<?php
/**
 * Teachers (super admin only) — directory of every teacher + invite new teachers.
 *   POST invite   name + email → one-time link (shown once here, emailed when email is set up)
 *   POST renew    new link + 7 more days for a waiting / expired invite (the inviter only)
 *   POST revoke   the link stops working (the inviter only)
 * Inline field errors; SweetAlert2 for the task result; CSRF + Post/Redirect/Get.
 * The link is passed to the next page load through the session (never in the URL).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireAdmin();   // only the school's super admin invites teachers and sees every teacher

$self = APP_URL . '/pages/teachers/index.php';
$task_result = null;
$form = ['name' => '', 'email' => ''];
$errors = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'invite') {
        $form = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => strtolower(trim((string) ($_POST['email'] ?? '')))];
        $errors = validateTeacherInvite($form['name'], $form['email']);
        if (!array_filter($errors)) {
            $open_invites = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM teacher_invites WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()")['c'];
            if ($limit_msg = limitReached('teachers', $open_invites)) $errors['email'] = $limit_msg;   // the provider's teacher limit (open invites count)
        }
        if (!array_filter($errors)) {
            [$invite_id, $token] = createTeacherInvite($form['name'], $form['email'], $current_user_id);
            logActivity('invite_teacher', 'teachers', "Invited {$form['name']} ({$form['email']}) as a teacher (invite #{$invite_id})");
            $mail_error = sendTeacherInviteEmail(['name' => $form['name'], 'email' => $form['email']], $token, $current_user_name);
            Session::flash('invite_link', ['link' => teacherInviteLink($token), 'name' => $form['name'], 'emailed' => $mail_error === null, 'mail_error' => $mail_error]);
            Session::flash('teachers_msg', ['success', 'Invite created', $mail_error === null
                ? 'We emailed the link to ' . $form['email'] . '. You can also copy it below.'
                : 'Copy the link below and send it to ' . $form['name'] . ' yourself.']);
            redirect($self . '#invite-link');
        }
    } elseif ($action === 'renew' || $action === 'revoke') {
        $invite = $db->fetchOne("SELECT * FROM teacher_invites WHERE id = ? AND invited_by = ? AND accepted_at IS NULL",
            [(int) ($_POST['invite_id'] ?? 0), $current_user_id]);
        if (!$invite) {
            $task_result = ['error', 'Not allowed', 'Only the teacher who sent an invite can change it, and only before it is used.'];
        } elseif ($action === 'revoke') {
            $db->update('teacher_invites', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$invite['id']]);
            logActivity('revoke_teacher_invite', 'teachers', "Cancelled the teacher invite for {$invite['email']}");
            Session::flash('teachers_msg', ['success', 'Invite cancelled', 'The link for ' . $invite['email'] . ' no longer works.']);
            redirect($self);
        } else {
            $token = renewTeacherInvite($invite['id']);
            $mail_error = sendTeacherInviteEmail($invite, $token, $current_user_name);
            logActivity('renew_teacher_invite', 'teachers', "Sent a new teacher invite link to {$invite['email']}");
            Session::flash('invite_link', ['link' => teacherInviteLink($token), 'name' => $invite['name'], 'emailed' => $mail_error === null, 'mail_error' => $mail_error]);
            Session::flash('teachers_msg', ['success', 'New link ready', 'The old link no longer works. This one is valid for ' . TEACHER_INVITE_DAYS . ' days.']);
            redirect($self . '#invite-link');
        }
    } else {
        $task_result = ['error', 'Not allowed', 'Unknown action.'];
    }
}

$task_result = Session::flash('teachers_msg') ?? $task_result;
$new_link = Session::flash('invite_link');
$teachers = getTeacherDirectory();
$invites = $db->fetchAll(
    "SELECT i.*, u.name AS inviter_name, a.name AS accepted_name FROM teacher_invites i
     LEFT JOIN users u ON i.invited_by = u.id LEFT JOIN users a ON i.accepted_user_id = a.id
     ORDER BY i.accepted_at IS NOT NULL, i.created_at DESC LIMIT 50");
$state_badge = ['pending' => ['submitted', 'Waiting'], 'accepted' => ['graded', 'Joined'], 'revoked' => ['pending', 'Cancelled'], 'expired' => ['missing', 'Expired']];

$page_title = 'Teachers';
$page_subtitle = 'Everyone who teaches on ' . APP_NAME . ' — invite a colleague to join';
$active_menu = 'settings';
$settings_page = true;
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const inv = document.getElementById('inviteForm');
    if (inv) {
        bindLiveClear(inv);
        inv.addEventListener('submit', function (e) {
            let ok = true;
            const n = document.getElementById('inv_name'), m = document.getElementById('inv_email');
            if (!n.value.trim()) { setFieldError(n, 'Name is required'); ok = false; }
            if (!m.value.trim()) { setFieldError(m, 'Email is required'); ok = false; }
            else if (!/^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(m.value.trim())) { setFieldError(m, 'Enter a valid email address'); ok = false; }
            if (!ok) e.preventDefault();
        });
    }
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            navigator.clipboard.writeText(b.dataset.copy).then(function () {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Link copied', showConfirmButton: false, timer: 1500 });
            });
        });
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (f.dataset.confirmed) return;
            e.preventDefault();
            Swal.fire({ icon: 'warning', titleText: f.dataset.confirm, text: f.dataset.confirmText || '', showCancelButton: true,
                        confirmButtonText: f.dataset.confirmButton || 'Yes', confirmButtonColor: '#ef476f' })
                .then(function (r) { if (r.isConfirmed) { f.dataset.confirmed = '1'; f.submit(); } });
        });
    });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="teachers-layout">
    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-user-plus"></i> Invite a teacher</h2></div>
        <form method="POST" id="inviteForm" class="invite-form" novalidate>
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="invite">
            <div class="form-group">
                <label for="inv_name">Name <span class="required-mark">*</span></label>
                <input type="text" id="inv_name" name="name" maxlength="100" autocomplete="off" placeholder="e.g. Ms. Sara Ahmadi"
                    class="<?php echo $errors['name'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['name']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
            </div>
            <div class="form-group">
                <label for="inv_email">Email <span class="required-mark">*</span></label>
                <input type="email" id="inv_email" name="email" maxlength="100" autocomplete="off" placeholder="name@school.edu"
                    class="<?php echo $errors['email'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['email']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
            </div>
            <button type="submit" class="btn-app"><i class="fas fa-paper-plane"></i> Create invite</button>
            <p class="form-hint" style="margin:10px 0 0;">They get a one-time link (valid <?php echo TEACHER_INVITE_DAYS; ?> days) and choose their own password. <?php echo emailConfig()['enabled'] ? 'The link is also emailed to them.' : 'Email is not set up, so copy the link and send it yourself.'; ?></p>
        </form>

        <?php if ($new_link): ?>
        <div class="invite-link-box" id="invite-link">
            <strong><i class="fas fa-link"></i> Invite link for <?php echo htmlspecialchars($new_link['name']); ?></strong>
            <div class="invite-link-row">
                <input type="text" readonly value="<?php echo htmlspecialchars($new_link['link']); ?>" aria-label="Invite link" onclick="this.select()">
                <button type="button" class="btn-app btn-sm" data-copy="<?php echo htmlspecialchars($new_link['link']); ?>"><i class="fas fa-copy"></i> Copy</button>
            </div>
            <span class="form-hint" style="margin:0;"><?php echo $new_link['emailed'] ? 'Also emailed.' : 'Not emailed' . ($new_link['mail_error'] ? ' (' . htmlspecialchars($new_link['mail_error']) . ')' : '') . '.'; ?>
                For safety this link is shown only now — use "New link" later if it gets lost.</span>
        </div>
        <?php endif; ?>
    </section>

    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-envelope-open-text"></i> Invites</h2></div>
        <?php if (!$invites): ?>
        <p class="form-hint" style="padding:0 20px 18px;">No invites yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="app-table stack-table">
                <thead><tr><th>Invited</th><th>Status</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invites as $i): $st = teacherInviteState($i); $mine = (int) $i['invited_by'] === (int) $current_user_id; ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($i['accepted_name'] ?: $i['name']); ?></strong><div class="notif-meta"><?php echo htmlspecialchars($i['email']); ?></div>
                        <div class="notif-meta">by <?php echo htmlspecialchars($i['inviter_name'] ?? 'a former teacher'); ?> · <?php echo formatDate($i['created_at'], 'M d'); ?></div></td>
                    <td data-label="<?php echo htmlspecialchars(t('Status')); ?>"><span class="status-badge <?php echo $state_badge[$st][0]; ?>"><?php echo $state_badge[$st][1]; ?></span></td>
                    <td class="nowrap" data-label="<?php echo htmlspecialchars(t('Expires')); ?>"><?php echo $st === 'accepted' ? 'Joined ' . formatDate($i['accepted_at'], 'M d') : formatDate($i['expires_at'], 'M d, h:i A'); ?></td>
                    <td><div class="invite-actions">
                        <?php if ($mine && $st !== 'accepted'): ?>
                        <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="renew"><input type="hidden" name="invite_id" value="<?php echo (int) $i['id']; ?>">
                            <button type="submit" class="btn-app btn-sm btn-outline" title="Make a new link (the old one stops working)"><i class="fas fa-redo"></i> New link</button></form>
                        <?php if ($st === 'pending'): ?>
                        <form method="POST" data-confirm="Cancel this invite?" data-confirm-text="The link stops working. You can send a new one later." data-confirm-button="Cancel invite">
                            <?php echo csrfField(); ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="invite_id" value="<?php echo (int) $i['id']; ?>">
                            <button type="submit" class="icon-btn danger" title="Cancel invite" aria-label="Cancel invite for <?php echo htmlspecialchars($i['email']); ?>"><i class="fas fa-ban"></i></button></form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-chalkboard-teacher"></i> Teachers (<?php echo count($teachers); ?>)</h2></div>
    <div class="table-wrap">
        <table class="app-table stack-table">
            <thead><tr><th>Teacher</th><th>Classes</th><th>Students</th><th>Joined</th><th>Last login</th></tr></thead>
            <tbody>
            <?php foreach ($teachers as $t): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($t['name']); ?></strong><?php echo (int) $t['id'] === (int) $current_user_id ? ' <span class="role-tag">You</span>' : ''; ?>
                    <?php if (!$t['is_active']): ?> <span class="status-badge pending">Inactive</span><?php endif; ?>
                    <div class="notif-meta"><?php echo htmlspecialchars($t['email']); ?></div></td>
                <td data-label="<?php echo htmlspecialchars(t('Classes')); ?>"><?php echo (int) $t['classes']; ?></td>
                <td data-label="<?php echo htmlspecialchars(t('Students')); ?>"><?php echo (int) $t['students']; ?></td>
                <td data-label="<?php echo htmlspecialchars(t('Joined')); ?>"><?php echo formatDate($t['created_at'], 'M d, Y'); ?></td>
                <td data-label="<?php echo htmlspecialchars(t('Last login')); ?>"><?php echo $t['last_login'] ? timeAgo($t['last_login']) : 'Never'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
