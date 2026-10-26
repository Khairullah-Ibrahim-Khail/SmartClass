<?php
/**
 * Settings › Password & security — change your password (the current one is required).
 *   POST change_password   current, new (6+ characters, different) and confirmation
 * Inline field errors; SweetAlert2 for task results; CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$errors = ['current_password' => '', 'new_password' => '', 'confirm_password' => ''];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($current === '') {
            $errors['current_password'] = 'Enter your current password';
        } elseif (!password_verify($current, $user['password'])) {
            $errors['current_password'] = 'Current password is incorrect';
        }
        if ($new === '') {
            $errors['new_password'] = 'Enter a new password';
        } elseif ($why = passwordProblem($new, (string) $user['email'], (string) $user['name'])) {
            $errors['new_password'] = $why;
        } elseif ($new === $current) {
            $errors['new_password'] = 'New password must be different from the current one';
        }
        if ($confirm !== $new) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        if (!$errors['current_password'] && !$errors['new_password'] && !$errors['confirm_password']) {
            $db->update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$current_user_id]);
            session_regenerate_id(true);
            logActivity('change_password', 'profile', 'Changed password');
            Session::flash('profile_msg', ['success', 'Password changed', 'Use your new password next time you log in.']);
            redirect(APP_URL . '/pages/settings/security.php');
        }
    }
}
$task_result = Session::flash('profile_msg') ?? $task_result;
// Your recent sign-ins (from the activity log) and when the password last changed — so an unfamiliar sign-in stands out
$logins = $db->fetchAll("SELECT action, details, ip_address, created_at FROM activity_log WHERE user_id = ? AND action IN ('login', 'logout') ORDER BY id DESC LIMIT 10", [$current_user_id]);
$pw_changed = $db->fetchOne("SELECT created_at FROM activity_log WHERE user_id = ? AND action = 'change_password' ORDER BY id DESC LIMIT 1", [$current_user_id])['created_at'] ?? null;

$page_title = 'Password & security';
$page_subtitle = 'Change the password you sign in with';
$active_menu = 'settings';
$settings_page = true;
$page_script = "
    document.querySelectorAll('.profile-form').forEach(function(f) { bindLiveClear(f); });
    const pw = document.getElementById('passwordForm');
    pw.addEventListener('submit', function(e) {
        let ok = true;
        const cur = document.getElementById('current_password'), nw = document.getElementById('new_password'), cf = document.getElementById('confirm_password');
        if (!cur.value) { setFieldError(cur, 'Enter your current password'); ok = false; }
        if (nw.value.length < " . passwordMinLength() . ") { setFieldError(nw, 'Use at least " . passwordMinLength() . " characters'); ok = false; }
        if (cf.value !== nw.value) { setFieldError(cf, 'Passwords do not match'); ok = false; }
        if (!ok) e.preventDefault();
    });" . ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });" : "");

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
        <!-- Password -->
        <section class="set-section">
    <header><h3>Change password</h3><p>Use at least <?php echo passwordMinLength(); ?> characters, nothing common or built from your name. You stay signed in on this device.</p></header>
    <div class="body">
            <form method="POST" class="profile-form" id="passwordForm" novalidate>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="change_password">
                <div class="form-group password-field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" class="<?php echo $errors['current_password'] ? 'is-invalid' : ''; ?>">
                    <button type="button" class="password-toggle" data-target="current_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['current_password']); ?></div>
                </div>
                <div class="form-row">
                    <div class="form-group password-field">
                        <label for="new_password">New password</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" class="<?php echo $errors['new_password'] ? 'is-invalid' : ''; ?>">
                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['new_password']); ?></div>
                    </div>
                    <div class="form-group password-field">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" class="<?php echo $errors['confirm_password'] ? 'is-invalid' : ''; ?>">
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password']); ?></div>
                    </div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-key"></i> Change password</button></div>
            </form>
        </section>

<section class="set-section">
    <header><h3>Recent sign-ins</h3><p>If you see a sign-in that was not you, change your password now<?php echo $pw_changed ? ' — it was last changed ' . htmlspecialchars(timeAgo($pw_changed)) : ''; ?>.</p></header>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>When</th><th>What</th><th>From</th></tr></thead>
        <tbody>
        <?php foreach ($logins as $l): ?>
            <tr><td><?php echo formatDate($l['created_at'], 'M d, Y h:i A'); ?> <span class="form-hint" style="margin:0;">· <?php echo htmlspecialchars(timeAgo($l['created_at'])); ?></span></td>
                <td><?php echo $l['action'] === 'login' ? 'Signed in' . ($l['details'] ? ' — ' . htmlspecialchars($l['details']) : '') : 'Signed out'; ?></td>
                <td><code><?php echo htmlspecialchars((string) $l['ip_address']); ?></code></td></tr>
        <?php endforeach; ?>
        <?php if (!$logins): ?><tr><td colspan="3" class="form-hint">No sign-ins recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
