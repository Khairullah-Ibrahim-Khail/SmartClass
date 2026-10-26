<?php
/**
 * Settings › Privacy & data (super admin).
 *   Retention     how long the activity log, AI usage, AI Tutor chats, read notifications, sent e-mail copies and attendance edit history are kept
 *                 (months, 0 = keep) — cleaned every night by cron/housekeeping.php; "Preview" shows what the next clean-up would remove
 *   Safeguarding  switch for the AI Tutor's harm detection (on by default; see pages/ai/safeguarding.php)
 *   People        find a person → download everything the system holds about them (JSON) · anonymise (keeps marks, attendance and work, removes the person)
 *                 · delete (students, and teachers without classes). Administrators cannot be removed here. Each needs the person's e-mail typed as confirmation.
 *   POST save | export | anonymise | delete   CSRF; Post/Redirect/Get
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('priv_msg');
$rules = retentionRules();
$vals = []; foreach ($rules as $k => $r) $vals[$k] = (string) retentionMonths($k);
$errors = array_fill_keys(array_keys($rules), '');
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save' || $action === 'preview') {
        foreach ($rules as $k => $_) {
            $raw = trim((string) ($_POST[$k] ?? ''));
            $vals[$k] = $raw;
            if (!ctype_digit($raw) || (int) $raw > 120) $errors[$k] = 'Whole months, 0 to 120 (0 = keep)';
        }
        if (!array_filter($errors)) {
            foreach ($rules as $k => $_) setSetting($k, (int) $vals[$k], $current_user_id);
            setSetting('ai_safeguarding', !empty($_POST['ai_safeguarding']) ? '1' : '0', $current_user_id);
            if ($action === 'preview') { $preview = retentionRun(true); }
            else {
                logActivity('privacy_rules', 'settings', 'Retention rules saved');
                Session::flash('priv_msg', ['success', 'Saved', 'The nightly clean-up uses these rules from now on.']);
                redirect(APP_URL . '/pages/settings/privacy.php');
            }
        } else $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    } elseif ($action === 'support_save') {
        setSetting('support_approval', !empty($_POST['support_approval']) ? '1' : '0', $current_user_id);
        logActivity('support_setting', 'settings', 'Support access ' . (!empty($_POST['support_approval']) ? 'needs the administrator\'s approval' : 'no longer needs approval'));
        Session::flash('priv_msg', ['success', 'Saved', !empty($_POST['support_approval']) ? 'The provider can only get in after you approve each request.' : 'The provider\'s support link signs in directly again (still logged and announced).']);
        redirect(APP_URL . '/pages/settings/privacy.php');
    } elseif ($action === 'support_decide') {
        $ok = supportRequestDecide((string) ($_POST['token'] ?? ''), ($_POST['decision'] ?? '') === 'approve', $current_user_id);
        logActivity('support_decision', 'settings', ($_POST['decision'] ?? '') === 'approve' ? 'Approved a provider support request' : 'Denied a provider support request');
        Session::flash('priv_msg', $ok ? ['success', ($_POST['decision'] ?? '') === 'approve' ? 'Approved' : 'Denied', ($_POST['decision'] ?? '') === 'approve' ? 'The provider is being signed in now.' : 'Nobody was let in.'] : ['info', 'Already over', 'That request was already decided or expired.']);
        redirect(APP_URL . '/pages/settings/privacy.php');
    } elseif (in_array($action, ['export', 'anonymise', 'delete'], true)) {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $who = $db->fetchOne("SELECT id, name, email FROM users WHERE id = ?", [$uid]);
        if (!$who) {
            $task_result = ['error', 'Not found', 'That person does not exist any more.'];
        } elseif ($action === 'export') {
            $data = privacyExport($uid);
            logActivity('privacy_export', 'settings', "Exported the data of user #{$uid}");
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="smartclass-data-user-' . $uid . '-' . date('Y-m-d') . '.json"');
            header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        } elseif (trim(mb_strtolower((string) ($_POST['confirm_email'] ?? ''))) !== mb_strtolower($who['email'])) {
            $task_result = ['error', 'Confirmation does not match', 'Type the person\'s e-mail exactly as shown to confirm.'];
        } else {
            [$can_anon, $can_del, $why] = privacyEligibility($uid);
            if ($action === 'anonymise' && $can_anon) {
                privacyAnonymise($uid); logActivity('privacy_anonymise', 'settings', "Anonymised user #{$uid}");
                Session::flash('priv_msg', ['success', 'Person anonymised', 'Marks, attendance and work stay in the records; the name, contacts, photo and sign-in are gone.']);
                redirect(APP_URL . '/pages/settings/privacy.php');
            } elseif ($action === 'delete' && $can_del) {
                privacyDelete($uid); logActivity('privacy_delete', 'settings', "Deleted user #{$uid}");
                Session::flash('priv_msg', ['success', 'Person deleted', 'The account and everything that depended on it were removed.']);
                redirect(APP_URL . '/pages/settings/privacy.php');
            } else $task_result = ['error', 'Not allowed', $why ?: 'This person cannot be removed here.'];
        }
    }
}
$q = trim((string) ($_GET['q'] ?? ''));
$found = [];
if ($q !== '' && mb_strlen($q) >= 2) {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $found = $db->fetchAll("SELECT id, name, email, role, is_active, is_admin FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY name LIMIT 20", [$like, $like]);
}
$support_pending = supportApprovalOn() ? supportPending() : [];
$open_flags = (int) $db->fetchOne("SELECT COUNT(*) c FROM safeguarding_flags WHERE status = 'open'")['c'];

$page_title = 'Privacy & data';
$page_subtitle = 'How long data is kept, and what a person can ask for';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section" id="support">
    <header><h3>Provider support access</h3><p>When you host this system with a provider, they can ask for a one-time link into your admin side to help you. You can require your approval for every request.</p></header>
    <div class="body">
        <form method="POST">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="support_save">
            <label class="switch-row"><input type="checkbox" name="support_approval" value="1" <?php echo supportApprovalOn() ? 'checked' : ''; ?>>
                <span><strong>Support needs my approval</strong><small class="form-hint" style="display:block;margin:2px 0 0;">The provider waits on a page until you press Approve here (the request is kept 10 minutes). Without it, the signed link signs them in directly — it is always logged and you are always told.</small></span></label>
            <div class="form-actions"><button type="submit" class="btn-app btn-sm"><i class="fas fa-save"></i> Save</button></div>
        </form>
        <?php foreach ($support_pending as $rq): ?>
        <div class="alert-card" style="margin-top:14px;border-left:4px solid #e5484d;padding:12px 14px;border-radius:10px;background:rgba(229,72,77,.07);">
            <strong><i class="fas fa-life-ring"></i> The provider asks for support access</strong>
            <p class="form-hint" style="margin:4px 0 8px;">Reason: <?php echo htmlspecialchars($rq['reason'] ?: '—'); ?> · asked <?php echo timeAgo(date('Y-m-d H:i:s', $rq['asked'])); ?></p>
            <form method="POST" style="display:flex;gap:8px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="support_decide"><input type="hidden" name="token" value="<?php echo htmlspecialchars($rq['token']); ?>">
                <button type="submit" name="decision" value="approve" class="btn-app btn-sm"><i class="fas fa-check"></i> Approve</button>
                <button type="submit" name="decision" value="deny" class="btn-app btn-sm btn-outline"><i class="fas fa-xmark"></i> Deny</button></form>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<section class="set-section">
    <header><h3>How long data is kept</h3><p>Old records are removed every night. Marks, attendance, submissions, lectures and messages are school records and are never removed by these rules.</p></header>
    <div class="body">
        <form method="POST" novalidate>
            <?php echo csrfField(); ?>
            <div class="mk-grid">
                <?php foreach ($rules as $k => [$label]): ?>
                <label class="mk-part"><span><?php echo htmlspecialchars($label); ?></span>
                    <input type="number" name="<?php echo $k; ?>" min="0" max="120" step="1" value="<?php echo htmlspecialchars($vals[$k]); ?>" class="<?php echo $errors[$k] ? 'is-invalid' : ''; ?>">
                    <small>months, 0 = keep forever</small>
                    <?php if ($errors[$k]): ?><span class="invalid-feedback show"><?php echo htmlspecialchars($errors[$k]); ?></span><?php endif; ?></label>
                <?php endforeach; ?>
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label class="switch-row"><input type="checkbox" name="ai_safeguarding" value="1" <?php echo safeguardingOn() ? 'checked' : ''; ?>>
                    <span><strong>AI Tutor safeguarding</strong>
                        <small class="form-hint" style="display:block;margin:2px 0 0;">When a student's message suggests they are in danger or hurting (self-harm, abuse, violence, bullying), the AI is not asked: the student gets a fixed, kind answer to talk to a trusted adult, and the class teachers and administrators are told (<a href="<?php echo APP_URL; ?>/pages/ai/safeguarding.php">Safeguarding</a><?php echo $open_flags ? ' — <strong>' . $open_flags . ' open</strong>' : ''; ?>).</small></span></label>
            </div>
            <div class="form-actions"><button type="submit" name="action" value="save" class="btn-app"><i class="fas fa-save"></i> Save</button>
                <button type="submit" name="action" value="preview" class="btn-app btn-outline"><i class="fas fa-magnifying-glass"></i> Save and show what the next clean-up removes</button></div>
        </form>
        <?php if ($preview !== null): ?>
        <table class="app-table" style="margin-top:12px;"><thead><tr><th>Record</th><th>Would be removed tonight</th></tr></thead><tbody>
            <?php foreach ($preview as $label => $n): ?><tr><td><?php echo htmlspecialchars($label); ?></td><td><?php echo $n === null ? 'kept forever' : number_format((int) $n); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </div>
</section>

<section class="set-section">
    <header><h3>A person's data</h3><p>Find someone to download what the system holds about them, or to remove them. Anonymising keeps the school's records (marks, attendance, work) but removes the person.</p></header>
    <div class="body">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;"><input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or e-mail" aria-label="Name or e-mail" style="flex:1;min-width:220px;"><button type="submit" class="btn-app"><i class="fas fa-search"></i> Find</button></form>
        <?php if ($q !== '' && !$found): ?><p class="form-hint">Nobody matches “<?php echo htmlspecialchars($q); ?>”.</p><?php endif; ?>
        <?php foreach ($found as $p): [$can_a, $can_d, $why] = privacyEligibility($p['id']); ?>
        <div class="content-card" style="margin-bottom:12px;"><div class="card-inner">
            <div style="display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;align-items:center;">
                <div><strong><?php echo htmlspecialchars($p['name']); ?></strong> <span class="status-badge <?php echo $p['is_active'] ? 'graded' : 'pending'; ?>"><?php echo htmlspecialchars($p['role'] . ($p['is_admin'] ? ' · admin' : '') . ($p['is_active'] ? '' : ' · closed')); ?></span><br><span class="form-hint" style="margin:0;"><?php echo htmlspecialchars($p['email']); ?></span></div>
                <form method="POST" style="margin:0;"><?php echo csrfField(); ?><input type="hidden" name="action" value="export"><input type="hidden" name="user_id" value="<?php echo (int) $p['id']; ?>">
                    <button type="submit" class="btn-app btn-sm btn-outline" data-no-ajax><i class="fas fa-download"></i> Download their data</button></form>
            </div>
            <?php if ($can_a): ?>
            <form method="POST" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;"><?php echo csrfField(); ?><input type="hidden" name="user_id" value="<?php echo (int) $p['id']; ?>">
                <input type="text" name="confirm_email" placeholder="Type <?php echo htmlspecialchars($p['email']); ?> to confirm" aria-label="Type the e-mail to confirm" autocomplete="off" style="flex:1;min-width:240px;">
                <button type="submit" name="action" value="anonymise" class="btn-app btn-sm"><i class="fas fa-user-slash"></i> Anonymise</button>
                <?php if ($can_d): ?><button type="submit" name="action" value="delete" class="btn-app btn-sm btn-danger"><i class="fas fa-trash"></i> Delete everything</button><?php endif; ?>
            </form>
            <?php if (!$can_d && $why): ?><p class="form-hint" style="margin:6px 0 0;"><?php echo htmlspecialchars($why); ?></p><?php endif; ?>
            <?php else: ?><p class="form-hint" style="margin:8px 0 0;"><?php echo htmlspecialchars($why); ?></p><?php endif; ?>
        </div></div>
        <?php endforeach; ?>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
