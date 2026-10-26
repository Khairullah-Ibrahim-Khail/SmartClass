<?php
/**
 * Settings › Security rules (super admin): the safety rules of the whole school.
 *   force_https          send everyone to https (and tell browsers to remember it) — can only be switched ON from a page opened over https
 *   require_admin_2fa    administrators must use two-step sign-in (an authenticator app) — an admin without it is sent to set it up
 *   password_min_length  8-20 characters for new passwords (also refused: common passwords, your own name or e-mail)
 * Shown read-only: the sign-in and AI limits that always apply.   POST save   CSRF; Post/Redirect/Get
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('secrules_msg');
$https_now = requestIsHttps();
$vals = ['force_https' => getSetting('force_https', '0') === '1', 'require_admin_2fa' => getSetting('require_admin_2fa', '0') === '1', 'password_min_length' => (string) passwordMinLength()];
$errors = ['force_https' => '', 'password_min_length' => ''];
$admins_without = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1 AND totp_enabled = 0")['c'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $vals['force_https'] = !empty($_POST['force_https']);
        $vals['require_admin_2fa'] = !empty($_POST['require_admin_2fa']);
        $vals['password_min_length'] = trim((string) ($_POST['password_min_length'] ?? ''));
        if ($vals['force_https'] && !$https_now && getSetting('force_https', '0') !== '1') $errors['force_https'] = 'Open this page over https first — switching it on from plain http could lock everyone out if https does not work.';
        if (!ctype_digit($vals['password_min_length']) || (int) $vals['password_min_length'] < 8 || (int) $vals['password_min_length'] > 20) $errors['password_min_length'] = 'Enter a whole number from 8 to 20';
        if (!array_filter($errors)) {
            setSetting('force_https', $vals['force_https'] ? '1' : '0', $current_user_id);
            setSetting('require_admin_2fa', $vals['require_admin_2fa'] ? '1' : '0', $current_user_id);
            setSetting('password_min_length', (int) $vals['password_min_length'], $current_user_id);
            logActivity('security_rules', 'settings', 'Security rules: https ' . ($vals['force_https'] ? 'on' : 'off') . ', admin two-step ' . ($vals['require_admin_2fa'] ? 'required' : 'optional') . ', minimum password ' . (int) $vals['password_min_length']);
            Session::flash('secrules_msg', ['success', 'Rules saved', $vals['require_admin_2fa'] && empty($user['totp_enabled']) ? 'You will now be asked to set up two-step sign-in for your own account.' : 'They apply to everyone from now on.']);
            redirect(APP_URL . '/pages/settings/security_rules.php');
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = 'Security rules';
$page_subtitle = 'The safety rules of the whole school';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Rules you can switch on</h3><p>One setting for the whole school.</p></header>
    <div class="body">
        <form method="POST" novalidate>
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label class="switch-row"><input type="checkbox" name="force_https" value="1" <?php echo $vals['force_https'] ? 'checked' : ''; ?> class="<?php echo $errors['force_https'] ? 'is-invalid' : ''; ?>">
                    <span><strong>Force HTTPS</strong>
                        <small class="form-hint" style="display:block;margin:2px 0 0;">Everyone is sent to the secure (https) address and browsers remember it for a year. Needs a working https certificate.
                            <?php echo $https_now ? '<i class="fas fa-lock"></i> You are on https now.' : '<i class="fas fa-unlock"></i> You opened this page over plain http — to switch it on, open it over https first.'; ?></small></span></label>
                <?php if ($errors['force_https']): ?><div class="invalid-feedback show"><?php echo htmlspecialchars($errors['force_https']); ?></div><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="switch-row"><input type="checkbox" name="require_admin_2fa" value="1" <?php echo $vals['require_admin_2fa'] ? 'checked' : ''; ?>>
                    <span><strong>Administrators must use two-step sign-in</strong>
                        <small class="form-hint" style="display:block;margin:2px 0 0;">An administrator without an authenticator app is sent to set one up before anything else.
                            Right now <strong><?php echo $admins_without; ?></strong> administrator<?php echo $admins_without == 1 ? ' has' : 's have'; ?> not set it up.</small></span></label>
            </div>
            <div class="form-group" style="max-width:260px;">
                <label for="password_min_length">Shortest new password <small class="form-hint" style="display:inline;margin:0;">characters (8 – 20)</small></label>
                <input type="number" id="password_min_length" name="password_min_length" min="8" max="20" step="1" value="<?php echo htmlspecialchars((string) $vals['password_min_length']); ?>" class="<?php echo $errors['password_min_length'] ? 'is-invalid' : ''; ?>">
                <?php if ($errors['password_min_length']): ?><div class="invalid-feedback show"><?php echo htmlspecialchars($errors['password_min_length']); ?></div><?php endif; ?>
                <small class="form-hint">Also refused: common passwords, a password that contains the person's own name or e-mail, plain runs like 12345678. Passwords that already exist keep working until they are changed.</small>
            </div>
            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save rules</button></div>
        </form>
    </div>
</section>

<section class="set-section">
    <header><h3>Limits that always apply</h3><p>They protect accounts and the AI budget; you do not need to switch them on.</p></header>
    <div class="body">
        <table class="app-table"><tbody>
            <tr><td>Wrong passwords</td><td>8 per e-mail or 40 per address in 15 minutes, then a short wait</td></tr>
            <tr><td>E-mail sign-in codes and two-step codes</td><td>a few tries per code, then a new code is needed</td></tr>
            <tr><td>Password reset requests</td><td>5 per e-mail and 20 per address per hour</td></tr>
            <tr><td>New sign-ups</td><td>10 per address per hour</td></tr>
            <tr><td>AI questions</td><td>30 per person per minute; the AI Tutor also stops at 60 questions per student per day</td></tr>
            <tr><td>Uploads</td><td>40 per person per minute</td></tr>
        </tbody></table>
        <p class="form-hint" style="margin-top:10px;">Locked out of your own administrator account? On the server run <code>php bin/security_switch.php require_admin_2fa off</code> (or <code>force_https off</code>).</p>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
