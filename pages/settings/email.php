<?php
/**
 * Email Settings (super admin) — sidebar "Settings › Email Settings".
 * Everything about outgoing email is configured here, never in files:
 *   POST save        on/off, SMTP or PHP mail(), host/port/encryption/login, sender, site URL, delivery
 *   POST test        send a test email to yourself right now (shows the exact error)
 *   POST send_now    send pending emails from the queue now
 *   POST retry       put failed emails back in the queue
 * The SMTP password is stored encrypted and never shown again (leave blank to keep it).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireAdmin();   // school-wide setting: super admin only

$cfg = emailConfig();
$errors = ['smtp_host' => '', 'smtp_port' => '', 'from_email' => '', 'site_url' => ''];
$task_result = null;
$form = $cfg;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save') {
        $form = [
            'enabled'    => !empty($_POST['enabled']),
            'transport'  => ($_POST['transport'] ?? '') === 'mail' ? 'mail' : 'smtp',
            'host'       => trim($_POST['smtp_host'] ?? ''),
            'port'       => trim($_POST['smtp_port'] ?? ''),
            'encryption' => in_array($_POST['smtp_encryption'] ?? '', ['none', 'ssl', 'tls'], true) ? $_POST['smtp_encryption'] : 'tls',
            'username'   => trim($_POST['smtp_username'] ?? ''),
            'from_email' => trim($_POST['from_email'] ?? ''),
            'from_name'  => trim($_POST['from_name'] ?? ''),
            'site_url'   => rtrim(trim($_POST['site_url'] ?? ''), '/'),
            'delivery'   => ($_POST['delivery'] ?? '') === 'immediate' ? 'immediate' : 'cron',
        ];
        if ($form['transport'] === 'smtp') {
            if ($form['host'] === '' || !preg_match('/^[A-Za-z0-9.-]{1,253}$/', $form['host'])) $errors['smtp_host'] = 'Enter the SMTP server name, e.g. mail.yourdomain.com';
            if (!preg_match('/^\d{1,5}$/', $form['port']) || (int) $form['port'] < 1 || (int) $form['port'] > 65535) $errors['smtp_port'] = 'Port is a number, usually 587 (TLS) or 465 (SSL)';
        }
        if (!filter_var($form['from_email'], FILTER_VALIDATE_EMAIL)) $errors['from_email'] = 'Enter the address emails are sent from';
        $scheme = strtolower((string) parse_url($form['site_url'], PHP_URL_SCHEME));
        if (!filter_var($form['site_url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) $errors['site_url'] = 'Enter the address of this site, e.g. https://school.example.com';

        if (!array_filter($errors)) {
            foreach ([
                'email_enabled' => $form['enabled'] ? '1' : '0', 'email_transport' => $form['transport'],
                'smtp_host' => $form['host'], 'smtp_port' => $form['port'], 'smtp_encryption' => $form['encryption'],
                'smtp_username' => $form['username'], 'from_email' => $form['from_email'],
                'from_name' => mb_substr($form['from_name'] !== '' ? $form['from_name'] : APP_NAME, 0, 100),
                'site_url' => $form['site_url'], 'email_delivery' => $form['delivery'],
            ] as $k => $v) {
                setSetting($k, $v, $current_user_id);
            }
            $new_password = (string) ($_POST['smtp_password'] ?? '');
            if ($new_password !== '') {
                setSetting('smtp_password', encryptSecret($new_password), $current_user_id);
            } elseif (!empty($_POST['clear_password'])) {
                setSetting('smtp_password', '', $current_user_id);
            }
            logActivity('email_settings', 'settings', 'Updated email settings (' . ($form['enabled'] ? 'on' : 'off') . ', ' . $form['transport'] . ')');
            Session::flash('email_msg', ['success', 'Email settings saved', $form['enabled'] ? 'Send a test email to check them.' : 'Email notifications are off.']);
            redirect(APP_URL . '/pages/settings/email.php');
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    } elseif ($action === 'test') {
        try {
            $me = ['name' => $current_user_name];
            [$html, $text] = renderNotificationEmail($cfg, $me, 'Test email from ' . APP_NAME,
                'If you can read this, email notifications are set up correctly.', 'pages/settings/email.php', $cfg['site_url'] . '/pages/profile/index.php');
            sendEmailNow($cfg, $user['email'], $current_user_name, 'Test email — ' . APP_NAME, $html, $text);
            logActivity('email_test', 'settings', 'Sent a test email to ' . $user['email']);
            $task_result = ['success', 'Test email sent', 'Check the inbox of ' . $user['email'] . ' (and the spam folder).'];
        } catch (Throwable $e) {
            $task_result = ['error', 'Test email failed', $e->getMessage()];
        }
    } elseif ($action === 'send_now') {
        $r = processEmailQueue(100);
        $task_result = ['success', 'Queue processed', $r['sent'] . ' sent, ' . $r['failed'] . ' failed.'];
    } elseif ($action === 'retry') {
        $n = $db->update('email_queue', ['status' => 'pending', 'attempts' => 0], "status = 'failed'", []);
        $task_result = ['success', 'Queued again', $n . ' failed email' . ($n == 1 ? '' : 's') . ' will be retried.'];
    }
}

$task_result = Session::flash('email_msg') ?? $task_result;
$stats = ['pending' => 0, 'held' => 0, 'sent' => 0, 'failed' => 0];
foreach ($db->fetchAll("SELECT status, COUNT(*) AS n FROM email_queue WHERE created_at >= ? GROUP BY status", [date('Y-m-d H:i:s', strtotime('-7 days'))]) as $r) {
    $stats[$r['status']] = (int) $r['n'];
}
$failures = $db->fetchAll("SELECT to_email, subject, last_error, created_at FROM email_queue WHERE status = 'failed' ORDER BY id DESC LIMIT 5");
$has_password = getSetting('smtp_password', '') !== '';
$cron_path = dirname(__DIR__, 2) . '/cron/send_emails.php';

$page_title = 'Email Settings';
$page_subtitle = 'Send notifications by email — new classwork, grades, attendance, approvals';
$active_menu = 'settings';
$settings_page = true;
$page_script = "
    const form = document.getElementById('emailForm');
    bindLiveClear(form);
    function sync() {
        const smtp = form.querySelector('[name=transport]:checked').value === 'smtp';
        document.getElementById('smtpFields').hidden = !smtp;
    }
    form.querySelectorAll('[name=transport]').forEach(function(r) { r.addEventListener('change', sync); });
    sync();" . ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : "");

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php providerServiceNotice('email'); ?>

<div class="settings-layout">
    <form method="POST" class="form-card" id="emailForm" novalidate style="max-width:none;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">

        <div class="form-group">
            <label class="switch-row">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" <?php echo $form['enabled'] ? 'checked' : ''; ?>>
                <span><strong>Send email notifications</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">Everyone still gets in-app notifications. Each person can choose instant, daily summary or off in their profile.</small>
                    <small class="form-hint" style="display:block;margin:4px 0 0;"><i class="fas fa-key"></i> <strong>Student sign-in:</strong> while email is on, students sign in with a 6-digit code sent to their email — no password.
                        While it is off, they use passwords. Send a test email before switching it on.</small></span>
            </label>
        </div>

        <div class="form-group">
            <label>Send with</label>
            <div class="radio-row">
                <label><input type="radio" name="transport" value="smtp" <?php echo $form['transport'] === 'smtp' ? 'checked' : ''; ?>> SMTP account <small class="form-hint" style="margin:0;">(recommended — cPanel email, Gmail, Outlook…)</small></label>
                <label><input type="radio" name="transport" value="mail" <?php echo $form['transport'] === 'mail' ? 'checked' : ''; ?>> Server mail() <small class="form-hint" style="margin:0;">(only if your host supports it)</small></label>
            </div>
        </div>

        <div id="smtpFields">
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_host">SMTP server</label>
                    <input type="text" id="smtp_host" name="smtp_host" placeholder="mail.yourdomain.com" class="<?php echo $errors['smtp_host'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['host']); ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['smtp_host']); ?></div>
                </div>
                <div class="form-row" style="gap:12px;">
                    <div class="form-group">
                        <label for="smtp_port">Port</label>
                        <input type="number" id="smtp_port" name="smtp_port" placeholder="587" class="<?php echo $errors['smtp_port'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars((string) $form['port']); ?>">
                        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['smtp_port']); ?></div>
                    </div>
                    <div class="form-group">
                        <label for="smtp_encryption">Security</label>
                        <select id="smtp_encryption" name="smtp_encryption">
                            <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l): ?>
                            <option value="<?php echo $k; ?>" <?php echo $form['encryption'] === $k ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="smtp_username">Username</label>
                    <input type="text" id="smtp_username" name="smtp_username" autocomplete="off" placeholder="usually the full email address" value="<?php echo htmlspecialchars($form['username']); ?>">
                </div>
                <div class="form-group password-field">
                    <label for="smtp_password">Password</label>
                    <input type="password" id="smtp_password" name="smtp_password" autocomplete="new-password" placeholder="<?php echo $has_password ? '•••••••• saved — leave blank to keep' : 'Enter the email account password'; ?>">
                    <button type="button" class="password-toggle" data-target="smtp_password" aria-label="Show password" tabindex="-1"><i class="fas fa-eye" aria-hidden="true"></i></button>
                    <?php if ($has_password): ?>
                    <label class="form-hint" style="display:flex;gap:6px;align-items:center;margin-top:4px;font-weight:400;"><input type="checkbox" name="clear_password" value="1"> Remove the saved password</label>
                    <?php endif; ?>
                    <small class="form-hint">Stored encrypted. It is never shown again.</small>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="from_email">From address</label>
                <input type="text" id="from_email" name="from_email" placeholder="no-reply@yourdomain.com" class="<?php echo $errors['from_email'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['from_email']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['from_email']); ?></div>
            </div>
            <div class="form-group">
                <label for="from_name">From name</label>
                <input type="text" id="from_name" name="from_name" maxlength="100" value="<?php echo htmlspecialchars($form['from_name']); ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="site_url">Site address (used in email links)</label>
                <input type="text" id="site_url" name="site_url" class="<?php echo $errors['site_url'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['site_url']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['site_url']); ?></div>
            </div>
            <div class="form-group">
                <label for="delivery">Delivery</label>
                <select id="delivery" name="delivery">
                    <option value="cron" <?php echo $form['delivery'] === 'cron' ? 'selected' : ''; ?>>In the background (cron job — recommended)</option>
                    <option value="immediate" <?php echo $form['delivery'] === 'immediate' ? 'selected' : ''; ?>>Right after each action (no cron needed)</option>
                </select>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-app"><i class="fas fa-save"></i> Save settings</button>
        </div>
    </form>

    <aside>
        <div class="form-card side-panel">
            <h2 class="profile-section-title"><i class="fas fa-paper-plane"></i> Test</h2>
            <p class="form-hint" style="margin:0 0 12px;">Sends an email to <strong><?php echo htmlspecialchars($user['email']); ?></strong> with the saved settings.</p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="test">
                <button type="submit" class="btn-app btn-sm" style="width:100%;" <?php echo $cfg['from_email'] === '' ? 'disabled' : ''; ?>><i class="fas fa-envelope"></i> Send test email</button>
            </form>
        </div>

        <div class="form-card side-panel">
            <h2 class="profile-section-title"><i class="fas fa-inbox"></i> Last 7 days</h2>
            <div class="queue-stats">
                <span><b><?php echo $stats['sent']; ?></b> sent</span>
                <span><b><?php echo $stats['pending']; ?></b> waiting</span>
                <span><b><?php echo $stats['held']; ?></b> for daily summary</span>
                <span class="<?php echo $stats['failed'] ? 'danger' : ''; ?>"><b><?php echo $stats['failed']; ?></b> failed</span>
            </div>
            <div class="form-actions" style="margin-top:12px;">
                <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="send_now">
                    <button type="submit" class="btn-app btn-sm btn-outline" <?php echo !$cfg['enabled'] ? 'disabled' : ''; ?>><i class="fas fa-bolt"></i> Send waiting now</button></form>
                <?php if ($stats['failed']): ?>
                <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="retry">
                    <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-redo"></i> Retry failed</button></form>
                <?php endif; ?>
            </div>
            <?php foreach ($failures as $f): ?>
            <div class="queue-failure">
                <strong><?php echo htmlspecialchars($f['to_email']); ?></strong> · <?php echo timeAgo($f['created_at']); ?>
                <div><?php echo htmlspecialchars((string) $f['last_error']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="form-card side-panel">
            <h2 class="profile-section-title"><i class="fas fa-clock"></i> Cron job</h2>
            <p class="form-hint" style="margin:0 0 8px;">For background delivery, add these in cPanel → Cron Jobs:</p>
            <code class="cron-line">*/5 * * * * php <?php echo htmlspecialchars($cron_path); ?></code>
            <code class="cron-line">0 18 * * * php <?php echo htmlspecialchars($cron_path); ?> --digest</code>
            <p class="form-hint" style="margin:8px 0 0;">The first sends emails every 5 minutes; the second sends the daily summaries at 6 PM.</p>
        </div>
    </aside>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
