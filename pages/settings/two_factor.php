<?php
/**
 * Settings › Two-step sign-in — an authenticator app's 6-digit code on top of the password (or e-mail code).
 *   off  → "Turn on": a secret key (type it into the app, or open the otpauth link on the phone), then confirm with a code;
 *          8 one-time backup codes are shown ONCE
 *   on   → new backup codes · turn off (needs the password AND a current code)
 *   POST start | enable | new_codes | disable    CSRF; codes work once; the secret is stored encrypted
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$on = (bool) $user['totp_enabled'];
$task_result = Session::flash('tf_msg');
$codes = Session::flash('tf_codes');   // shown once
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'start' && !$on) {
        $_SESSION['tf_setup'] = totpNewSecret();
        redirect(APP_URL . '/pages/settings/two_factor.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['tf_setup']);
        redirect(APP_URL . '/pages/settings/two_factor.php');
    } elseif ($action === 'enable' && !$on && !empty($_SESSION['tf_setup'])) {
        $step = totpVerifySecret($_SESSION['tf_setup'], $_POST['code'] ?? '');
        if ($step === false) {
            $error = 'That code is not right. Check the 6 digits in your app (they change every 30 seconds).';
        } else {
            $db->query("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?", [encryptSecret($_SESSION['tf_setup']), $step, $current_user_id]);
            unset($_SESSION['tf_setup']);
            logActivity('two_factor_on', 'profile', 'Turned on two-step sign-in');
            Session::flash('tf_codes', backupCodesNew($current_user_id));
            Session::flash('tf_msg', ['success', 'Two-step sign-in is on', 'Save the backup codes now — they are shown only once.']);
            redirect(APP_URL . '/pages/settings/two_factor.php');
        }
    } elseif ($action === 'new_codes' && $on) {
        if (!password_verify((string) ($_POST['password'] ?? ''), $user['password'])) {
            $error = 'Your password is not right.';
        } else {
            Session::flash('tf_codes', backupCodesNew($current_user_id));
            Session::flash('tf_msg', ['success', 'New backup codes', 'The old ones no longer work. Save these now.']);
            logActivity('two_factor_codes', 'profile', 'New backup codes');
            redirect(APP_URL . '/pages/settings/two_factor.php');
        }
    } elseif ($action === 'disable' && $on) {
        if (!password_verify((string) ($_POST['password'] ?? ''), $user['password'])) {
            $error = 'Your password is not right.';
        } elseif (!twoFactorVerify($current_user_id, $_POST['code'] ?? '')) {
            $error = 'That code is not right.';
        } else {
            $db->query("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_step = 0 WHERE id = ?", [$current_user_id]);
            $db->query("DELETE FROM user_backup_codes WHERE user_id = ?", [$current_user_id]);
            logActivity('two_factor_off', 'profile', 'Turned off two-step sign-in');
            Session::flash('tf_msg', ['success', 'Two-step sign-in is off', 'You sign in with your password again.']);
            redirect(APP_URL . '/pages/settings/two_factor.php');
        }
    }
    if ($error) $task_result = ['error', 'Not done', $error];
}
$setup = !$on ? ($_SESSION['tf_setup'] ?? null) : null;
$left = $on ? (int) $db->fetchOne("SELECT COUNT(*) AS c FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL", [$current_user_id])['c'] : 0;

$page_title = 'Two-step sign-in';
$page_subtitle = 'A code from your phone on top of your password';
$active_menu = 'settings';
$settings_page = true;
if ($setup) $page_scripts = ['assets/vendor/qrcode/qrcode.js', 'assets/js/two-factor-qr.js'];
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if ($codes): ?>
<section class="set-section">
    <header><h3>Your backup codes</h3><p>Each works once if you lose your phone. Keep them somewhere safe — <strong>they are shown only now</strong>.</p></header>
    <div class="body"><div class="bk-codes"><?php foreach ($codes as $c): ?><code><?php echo htmlspecialchars($c); ?></code><?php endforeach; ?></div>
        <p class="form-hint" style="margin-bottom:0;">Print or copy them now. Using one signs you in without the app.</p></div>
</section>
<?php endif; ?>

<?php if ($on): ?>
<section class="set-section">
    <header><h3>Two-step sign-in is on <span class="status-badge graded" style="margin-left:8px;">Active</span></h3><p><?php echo $left; ?> unused backup code<?php echo $left == 1 ? '' : 's'; ?>.</p></header>
    <div class="body">
        <form method="POST" class="tf-form" novalidate><?php echo csrfField(); ?><input type="hidden" name="action" value="new_codes">
            <h4 class="yr-sub" style="margin-top:0;">Get new backup codes</h4>
            <div class="form-group"><label for="pw1">Your password</label><input type="password" id="pw1" name="password" autocomplete="current-password" style="max-width:320px;"></div>
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-rotate"></i> New backup codes</button>
        </form>
        <hr style="border:0;border-top:1px solid var(--line);margin:22px 0;">
        <form method="POST" class="tf-form" novalidate data-confirm="Turn off two-step sign-in?" data-confirm-text="Your account will be protected by the password alone." data-confirm-button="Turn off"><?php echo csrfField(); ?><input type="hidden" name="action" value="disable">
            <h4 class="yr-sub" style="margin-top:0;">Turn off</h4>
            <div class="form-row">
                <div class="form-group"><label for="pw2">Your password</label><input type="password" id="pw2" name="password" autocomplete="current-password"></div>
                <div class="form-group"><label for="cd2">Code from the app (or a backup code)</label><input type="text" id="cd2" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9"></div>
            </div>
            <button type="submit" class="btn-app btn-sm" style="background:var(--danger);"><i class="fas fa-lock-open"></i> Turn off two-step sign-in</button>
        </form>
    </div>
</section>
<?php elseif ($setup): ?>
<section class="set-section">
    <header><h3>Set it up</h3><p>Three steps, about a minute.</p></header>
    <div class="body">
        <ol class="tf-steps">
            <li><strong>Get an authenticator app</strong> on your phone — Google Authenticator, Microsoft Authenticator, Authy or 2FAS.</li>
            <li><strong>Scan this code</strong> with the app (tap "+" or "Scan a QR code") — or, if you cannot scan, add the account with "Enter a setup key" using the key below, or open the link on the phone.
                <div class="tf-qr-wrap"><div id="tfQr" class="tf-qr" data-uri="<?php echo htmlspecialchars(totpUri($setup, $user['email'])); ?>" data-label="<?php echo htmlspecialchars(t('QR code for the authenticator app')); ?>"></div></div>
                <div class="tf-key" aria-label="Setup key"><?php echo htmlspecialchars(implode(' ', str_split($setup, 4))); ?></div>
                <a class="link-btn" href="<?php echo htmlspecialchars(totpUri($setup, $user['email'])); ?>"><i class="fas fa-mobile-screen"></i> Open in the authenticator app</a>
                <span class="form-hint">Time-based, 6 digits. Keep this key private.</span></li>
            <li><strong>Type the 6-digit code</strong> the app shows to finish.</li>
        </ol>
        <form method="POST" class="tf-form" novalidate><?php echo csrfField(); ?><input type="hidden" name="action" value="enable">
            <div class="form-group"><label for="cd">Code from the app</label>
                <input type="text" id="cd" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="<?php echo $error ? 'is-invalid' : ''; ?>" style="max-width:200px;letter-spacing:.2em;text-align:center;font-size:20px;">
                <div class="invalid-feedback"><?php echo htmlspecialchars($error); ?></div></div>
            <div class="form-actions" style="display:flex;gap:10px;"><button type="submit" class="btn-app"><i class="fas fa-check"></i> Turn on</button></div>
        </form>
        <form method="POST" style="margin-top:8px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="cancel"><button type="submit" class="link-btn">Cancel</button></form>
    </div>
</section>
<?php else: ?>
<section class="set-section">
    <header><h3>Two-step sign-in is off</h3><p>Add a second lock: after your password you also type a 6-digit code from an app on your phone.</p></header>
    <div class="body">
        <p style="margin-top:0;">Recommended for teachers and the school administrator. If you lose your phone, one-time backup codes let you in.</p>
        <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="start"><button type="submit" class="btn-app"><i class="fas fa-shield-halved"></i> Set up two-step sign-in</button></form>
    </div>
</section>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
