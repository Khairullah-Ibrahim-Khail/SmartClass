<?php
/**
 * Service terms (a customer's super admin) — shown on first sign-in until accepted; afterwards a read-only record.
 * When the account was created with a temporary password, a new password is chosen here at the same time.
 * Inline field errors; CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (SC_IS_MASTER || !isAdmin()) redirect(APP_URL . '/pages/dashboard.php');

$accepted = termsAccepted();
$must_change = (string) getSetting('must_change_password_user', '') === (string) $current_user_id;
$errors = ['accept' => '', 'password' => '', 'confirm_password' => ''];
$task_result = null;

if (!$accepted && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        if (empty($_POST['accept'])) $errors['accept'] = 'Tick the box to accept the terms';
        $pw = (string) ($_POST['password'] ?? '');
        if ($must_change) {
            if ($why = passwordProblem($pw, (string) $user['email'], (string) $user['name'])) $errors['password'] = $why;
            elseif ($pw !== (string) ($_POST['confirm_password'] ?? '')) $errors['confirm_password'] = 'The passwords do not match';
        }
        if (!array_filter($errors)) {
            if ($must_change) {
                $db->update('users', ['password' => password_hash($pw, PASSWORD_DEFAULT)], 'id = ?', [$current_user_id]);
                $db->query("DELETE FROM app_settings WHERE setting_key = 'must_change_password_user'");
            }
            setSetting('terms_version', TERMS_VERSION, $current_user_id);
            setSetting('terms_accepted_at', date('Y-m-d H:i:s'), $current_user_id);
            setSetting('terms_accepted_by', (string) $current_user_id, $current_user_id);
            logActivity('accept_terms', 'settings', 'Accepted the service terms (version ' . TERMS_VERSION . ')' . ($must_change ? ' and chose a new password' : ''));
            redirect(APP_URL . '/pages/dashboard.php');
        }
        $task_result = ['error', 'Not yet', 'Please fix the highlighted fields.'];
    }
}

$page_title = 'Service terms';
$page_subtitle = $accepted ? 'Accepted on ' . formatDate(getSetting('terms_accepted_at'), 'M d, Y h:i A') : 'Please read and accept to continue';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_script .= "
    const tf = document.getElementById('termsForm');
    if (tf) { bindLiveClear(tf); tf.addEventListener('submit', function (e) {
        let ok = true;
        const a = tf.querySelector('[name=accept]'); const ae = document.getElementById('acceptErr');
        if (!a.checked) { ae.textContent = 'Tick the box to accept the terms'; ae.classList.add('show'); ok = false; } else ae.classList.remove('show');
        const p = tf.querySelector('[name=password]'), c = tf.querySelector('[name=confirm_password]');
        if (p) {
            if (p.value.length < " . passwordMinLength() . ") { setFieldError(p, 'Use at least " . passwordMinLength() . " characters'); ok = false; }
            else if (c.value !== p.value) { setFieldError(c, 'The passwords do not match'); ok = false; }
        }
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="content-card">
    <div class="card-inner">
        <?php foreach (termsParagraphs() as [$h, $t]): ?>
        <h3 style="font-size:1rem;margin:0 0 4px;"><?php echo htmlspecialchars($h); ?></h3>
        <p style="margin:0 0 16px;color:var(--ink-2);"><?php echo htmlspecialchars($t); ?></p>
        <?php endforeach; ?>
        <?php if ($accepted): ?>
        <span class="status-badge graded"><i class="fas fa-check"></i> Accepted <?php echo formatDate(getSetting('terms_accepted_at'), 'M d, Y'); ?> (version <?php echo htmlspecialchars((string) getSetting('terms_version')); ?>)</span>
        <?php else: ?>
        <form method="POST" id="termsForm" novalidate style="margin-top:8px;">
            <?php echo csrfField(); ?>
            <?php if ($must_change): ?>
            <div class="form-row">
                <div class="form-group"><label for="password">Choose your own password <span class="required-mark">*</span></label>
                    <input type="password" id="password" name="password" autocomplete="new-password" class="<?php echo $errors['password'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div></div>
                <div class="form-group"><label for="confirm_password">Repeat it <span class="required-mark">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" class="<?php echo $errors['confirm_password'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm_password']); ?></div></div>
            </div>
            <?php endif; ?>
            <label class="switch-row"><input type="checkbox" name="accept" value="1"> <span><strong>I have read and accept these terms for this installation</strong></span></label>
            <div class="invalid-feedback<?php echo $errors['accept'] ? ' show' : ''; ?>" id="acceptErr"><?php echo htmlspecialchars($errors['accept']); ?></div>
            <div class="form-actions" style="margin-top:14px;"><button type="submit" class="btn-app"><i class="fas fa-check"></i> Accept and continue</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
