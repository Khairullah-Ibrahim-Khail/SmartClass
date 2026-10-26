<?php
/**
 * Settings › Notifications — which e-mails you get and how often.
 *   POST save_email_prefs   mode (each update / one daily summary / none) and the kinds of e-mail
 * SweetAlert2 for task results; CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_email_prefs') {
        $mode = in_array($_POST['email_mode'] ?? '', ['instant', 'daily', 'off'], true) ? $_POST['email_mode'] : 'instant';
        $row = ['user_id' => $current_user_id, 'mode' => $mode];
        foreach (array_keys(emailCategoryLabels()) as $cat) {
            $row[$cat] = !empty($_POST['cat'][$cat]) ? 1 : 0;
        }
        $cols = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $updates = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", array_keys(array_slice($row, 1))));
        $db->query("INSERT INTO email_preferences ({$cols}) VALUES ({$marks}) ON DUPLICATE KEY UPDATE {$updates}", array_values($row));
        logActivity('email_preferences', 'profile', 'Email notifications: ' . $mode);
        Session::flash('profile_msg', ['success', 'Email preferences saved',
            $mode === 'off' ? 'You will not get notification emails.' : ($mode === 'daily' ? 'You will get one summary email a day.' : 'You will get an email for each update you chose.')]);
        redirect(APP_URL . '/pages/settings/notifications.php');
    }

    if ($action === 'save_push_switch' && isAdmin()) {
        setSetting('push_enabled', !empty($_POST['push_enabled']) ? '1' : '0', $current_user_id);
        logActivity('push_switch', 'settings', 'Phone notifications for the school: ' . (!empty($_POST['push_enabled']) ? 'on' : 'off'));
        Session::flash('profile_msg', ['success', 'Saved', !empty($_POST['push_enabled']) ? 'Phone notifications are allowed.' : 'Phone notifications are switched off for everyone.']);
        redirect(APP_URL . '/pages/settings/notifications.php');
    }
}
$task_result = Session::flash('profile_msg') ?? $task_result;
$email_prefs = getEmailPreferences($current_user_id);
$email_on = emailConfig()['enabled'];
$push_on = pushEnabled();
try { $push_subs = (int) $db->fetchOne("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id = ?", [$current_user_id])['c']; }
catch (Throwable $e) { $push_subs = 0; $push_on = false; }   // migration 054 not applied yet: the section says it is off

$page_title = 'Notifications';
$page_subtitle = 'Choose how SmartClass reaches you: phone notifications and e-mail';
$active_menu = 'settings';
$settings_page = true;
$page_script = ($push_on ? "var s = document.createElement('script'); s.src = " . json_encode(APP_URL . '/assets/js/push.js') . "; document.body.appendChild(s);" : '') . ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });" : "");

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
        <!-- Phone notifications (web push) -->
        <section class="set-section">
    <header><h3>Phone notifications</h3><p>Get SmartClass alerts on this phone or computer even when the site is closed. E-mail and the bell keep working as they are.</p></header>
    <div class="body">
        <?php if (!$push_on): ?>
        <p class="form-hint" style="margin:0;"><i class="fas fa-info-circle"></i> <span>Phone notifications are switched off for this school.</span><?php echo isAdmin() ? ' <span>Turn them on below.</span>' : ''; ?></p>
        <?php else: ?>
        <div id="pushBox" data-key="<?php echo htmlspecialchars(pushPublicKey()); ?>" data-url="<?php echo htmlspecialchars(APP_URL . '/pages/notifications/push.php'); ?>" data-csrf="<?php echo htmlspecialchars(csrfToken()); ?>"
             data-is-on="<?php echo htmlspecialchars(t('Phone notifications are on for this device.')); ?>" data-is-off="<?php echo htmlspecialchars(t('Phone notifications are off on this device.')); ?>"
             data-denied="<?php echo htmlspecialchars(t("Notifications are blocked in this browser. Allow them in the browser's site settings, then come back.")); ?>" data-unsupported="<?php echo htmlspecialchars(t('This browser cannot show phone notifications. On an iPhone, add SmartClass to the Home Screen first.')); ?>"
             data-failed="<?php echo htmlspecialchars(t('Something went wrong. Please try again.')); ?>">
            <p class="push-status" data-push-status role="status" aria-live="polite">Checking…</p>
            <div class="form-actions" style="margin-top:10px;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn-app" data-push-on hidden><i class="fas fa-bell"></i> Turn on for this device</button>
                <button type="button" class="btn-app btn-outline" data-push-off hidden><i class="fas fa-bell-slash"></i> Turn off for this device</button>
                <button type="button" class="btn-app btn-outline" data-push-test hidden><i class="fas fa-paper-plane"></i> Send me a test</button>
            </div>
            <p class="form-hint" style="margin:10px 0 0;"><span>The alert text shows on your lock screen, so turn this on only on a device you keep to yourself.</span> <span>You are signed in on <?php echo $push_subs; ?> device<?php echo $push_subs == 1 ? '' : 's'; ?> with it on.</span></p>
        </div>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <form method="POST" class="profile-form" style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px;">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save_push_switch">
            <label class="switch-row"><input type="checkbox" name="push_enabled" value="1" <?php echo getSetting('push_enabled', '1') === '1' ? 'checked' : ''; ?>>
                <span><strong>Allow phone notifications for everyone</strong><small class="form-hint" style="display:block;margin:2px 0 0;">Turn off to stop all phone notifications at once. A staging copy never sends them.</small></span></label>
            <div class="form-actions" style="margin-top:10px;"><button type="submit" class="btn-app btn-sm"><i class="fas fa-save"></i> Save</button></div>
        </form>
        <?php endif; ?>
    </div>
        </section>

        <!-- Email notifications -->
        <section class="set-section">
    <header><h3>E-mail notifications</h3><p>Choose how and when SmartClass e-mails you.</p></header>
    <div class="body">
            <?php if (!$email_on): ?>
            <p class="form-hint" style="margin:-6px 0 14px;"><i class="fas fa-info-circle"></i> Email is not switched on for <?php echo APP_NAME; ?> yet<?php echo isTeacher() ? ' — set it up in <a href="' . APP_URL . '/pages/settings/email.php">Settings › E-mail</a>' : ''; ?>. Your choices are saved for when it is.</p>
            <?php endif; ?>
            <form method="POST" class="profile-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_email_prefs">
                <div class="radio-row" style="margin-bottom:14px;">
                    <?php foreach (['instant' => 'Email me for each update', 'daily' => 'One daily summary', 'off' => 'No emails'] as $k => $l): ?>
                    <label><input type="radio" name="email_mode" value="<?php echo $k; ?>" <?php echo $email_prefs['mode'] === $k ? 'checked' : ''; ?>> <?php echo $l; ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="pref-grid">
                    <?php foreach (emailCategoryLabels() as $cat => [$label, $hint]): ?>
                    <label class="switch-row">
                        <input type="checkbox" name="cat[<?php echo $cat; ?>]" value="1" <?php echo (int) $email_prefs[$cat] ? 'checked' : ''; ?>>
                        <span><strong><?php echo $label; ?></strong><small class="form-hint" style="display:block;margin:2px 0 0;"><?php echo $hint; ?></small></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions" style="margin-top:14px;"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save email preferences</button></div>
            </form>
        </section>


<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
