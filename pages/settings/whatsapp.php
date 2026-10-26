<?php
/**
 * Settings › WhatsApp — everything about it, in one place, for everybody, at any time (nothing is on until somebody switches it on):
 *   My WhatsApp     your own number and your agreement to receive messages (students and teachers)
 *   Sender          a teacher's own WhatsApp Business sender (the school's sender is set by the administrator) — token kept encrypted
 *   Messages        the wording of every message in every language, and the name of the approved Meta template; a teacher's own wording or the school's (admin)
 *   School rules    (administrator) the switches, quiet hours, limits, times, stop words, webhook details
 *   Recent messages what the automatic sender did, with the reason when something failed
 * POST action = save_person | save_sender | test_sender | save_texts | reset_texts | save_school | gen_webhook   CSRF; Post/Redirect/Get; errors under the field.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$teacher = isTeacher(); $admin = isAdmin(); $uid = (int) $current_user_id;
$kinds = waKinds(); $langs = waLangs();
$scope_owner = $admin ? null : $uid;    // whose wording the Messages section edits: the school's (admin) or the teacher's own
$task_result = Session::flash('wa_msg');
$form = Session::flash('wa_form') ?: ['errors' => [], 'old' => [], 'section' => ''];
$E = $form['errors']; $O = $form['old'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? ''; $errors = []; $ok = null;
    if (!verifyCsrf()) { Session::flash('wa_msg', ['error', 'Session expired', 'Please reload the page and try again.']); redirect(APP_URL . '/pages/settings/whatsapp.php'); }
    if ($a === 'save_person') {
        $raw = trim((string) ($_POST['number'] ?? '')); $student = !$teacher; $agree = $student || !empty($_POST['consent']);   // a student's number is part of registering (the school's rule); teachers tick
        if ($raw === '') {
            if ($student && waNumberRequired()) $errors['number'] = 'A WhatsApp number is required — yours, or your father\'s, uncle\'s or elder brother\'s.';
            else { $db->query("UPDATE users SET whatsapp_number = NULL, whatsapp_consent_at = NULL, whatsapp_relation = NULL WHERE id = ?", [$uid]); $ok = 'Your WhatsApp number was removed.'; }
        } else {
            $num = waNormalizeNumber($raw);
            if (!$num) $errors['number'] = 'Enter a full phone number, for example +93 700 123 456 (or 0700 123 456).';
            elseif (!$agree) $errors['consent'] = 'Tick the box to agree to receive messages on this number — or clear the number.';
            else { waSavePerson($uid, $raw, (string) ($_POST['relation'] ?? 'self')); if ($teacher) $db->query("UPDATE users SET whatsapp_todo = ? WHERE id = ?", [!empty($_POST['todo']) ? 1 : 0, $uid]); $ok = 'Your WhatsApp number was saved. You can stop the messages any time by replying STOP.'; }
        }
        if ($ok) logActivity('whatsapp_person', 'settings', 'WhatsApp number ' . ($raw === '' ? 'removed' : 'saved'));
    } elseif ($a === 'save_sender' && $teacher) {
        $owner = ($_POST['which'] ?? '') === 'school' && $admin ? null : $uid;
        if ($owner !== null && waSetting('wa_teachers_own') !== '1' && !$admin) { $errors['sender'] = 'The administrator did not allow teachers to use their own sender.'; }
        else { $r = waSaveSender($owner, ['label' => $_POST['label'] ?? '', 'phone_number_id' => $_POST['phone_number_id'] ?? '', 'business_id' => $_POST['business_id'] ?? '', 'token' => $_POST['token'] ?? '', 'is_enabled' => !empty($_POST['is_enabled']),
                'daily_limit' => $_POST['daily_limit'] ?? '0', 'kinds' => $_POST['kinds'] ?? []]);
            if ($r['ok']) { $ok = $owner === null ? 'The school\'s sender was saved.' : 'Your sender was saved.'; logActivity('whatsapp_sender', 'settings', ($owner === null ? 'School' : 'Own') . ' WhatsApp sender saved'); } else $errors = $r['errors']; }
    } elseif ($a === 'test_sender' && $teacher) {
        $owner = ($_POST['which'] ?? '') === 'school' && $admin ? null : $uid;
        $r = waSendTest(waSenderOf($owner), $uid);
        Session::flash('wa_msg', $r['ok'] ? ['success', 'Test sent', 'Check your WhatsApp.'] : ['error', 'Test failed', (string) $r['error']]); redirect(APP_URL . '/pages/settings/whatsapp.php#sender');
    } elseif ($a === 'save_texts' && $teacher) {
        $saved = 0;
        foreach ($kinds as $k => $def) foreach ($langs as $lc => $ln) {
            $body = trim((string) ($_POST['body'][$k][$lc] ?? $def['text'][$lc])); $tpl = trim((string) ($_POST['tpl'][$k][$lc] ?? ''));
            $err = waValidateText($k, $body);
            if ($err) { $errors["text_{$k}_{$lc}"] = $err; continue; }
            if ($tpl !== '' && !preg_match('/^[a-z0-9_]{1,100}$/', $tpl)) { $errors["tpl_{$k}_{$lc}"] = 'A template name has only small letters, digits and _ (as in Meta).'; continue; }
            $where = $scope_owner === null ? 'owner_id IS NULL' : 'owner_id = ' . (int) $scope_owner;
            if ($body === $def['text'][$lc] && $tpl === '') { $db->query("DELETE FROM whatsapp_texts WHERE {$where} AND kind = ? AND lang = ?", [$k, $lc]); continue; }
            $db->query("INSERT INTO whatsapp_texts (owner_id, kind, lang, body, template_name) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE body = VALUES(body), template_name = VALUES(template_name)", [$scope_owner, $k, $lc, $body, $tpl !== '' ? $tpl : null]);
            $saved++;
        }
        if (!$errors) { $ok = 'The messages were saved.'; logActivity('whatsapp_texts', 'settings', 'WhatsApp message texts saved'); }
    } elseif ($a === 'reset_texts' && $teacher) {
        $db->query("DELETE FROM whatsapp_texts WHERE " . ($scope_owner === null ? 'owner_id IS NULL' : 'owner_id = ' . (int) $scope_owner)); $ok = 'All messages are back to the built-in wording.';
    } elseif ($a === 'save_school' && $admin) {
        $v = fn($k) => trim((string) ($_POST[$k] ?? ''));
        $t = fn($x) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x);
        foreach (['wa_quiet_from', 'wa_quiet_to', 'wa_deadline_time', 'wa_todo_time'] as $k) if (!$t($v($k))) $errors[$k] = 'Enter a time like 21:00.';
        if (!preg_match('/^\d{1,4}$/', $v('wa_default_cc'))) $errors['wa_default_cc'] = 'The country code is 1 to 4 digits, for example 93.';
        if (!isset($langs[$v('wa_default_lang')])) $errors['wa_default_lang'] = 'Choose a language.';
        if (!preg_match('/^v\d{1,2}\.\d{1,2}$/', $v('wa_api_version'))) $errors['wa_api_version'] = 'Write the API version like v21.0.';
        if (!ctype_digit($v('wa_retry_max')) || (int) $v('wa_retry_max') < 1 || (int) $v('wa_retry_max') > 10) $errors['wa_retry_max'] = 'Between 1 and 10 tries.';
        if (!ctype_digit($v('wa_retry_gap_min')) || (int) $v('wa_retry_gap_min') < 1 || (int) $v('wa_retry_gap_min') > 720) $errors['wa_retry_gap_min'] = 'Between 1 and 720 minutes.';
        if (mb_strlen($v('wa_stop_words')) > 200) $errors['wa_stop_words'] = 'Keep the stop words under 200 characters.';
        if (mb_strlen($v('wa_school_name')) > 80) $errors['wa_school_name'] = 'Keep the name under 80 characters.';
        if ($v('wa_webhook_token') !== '' && !preg_match('/^[A-Za-z0-9_-]{8,100}$/', $v('wa_webhook_token'))) $errors['wa_webhook_token'] = 'The verify token is 8 to 100 letters, digits, - or _.';
        if ($v('wa_app_secret') !== '' && (strlen($v('wa_app_secret')) < 16 || strlen($v('wa_app_secret')) > 128 || preg_match('/\s/', $v('wa_app_secret')))) $errors['wa_app_secret'] = 'The app secret looks wrong (16 to 128 characters, no spaces).';
        if (!$errors) {
            foreach (['wa_enabled', 'wa_click_on', 'wa_auto_on', 'wa_teachers_own', 'wa_require_number'] as $k) setSetting($k, !empty($_POST[$k]) ? '1' : '0', $uid);
            foreach (['wa_quiet_from', 'wa_quiet_to', 'wa_deadline_time', 'wa_todo_time', 'wa_default_cc', 'wa_default_lang', 'wa_api_version', 'wa_retry_max', 'wa_retry_gap_min', 'wa_stop_words', 'wa_school_name', 'wa_webhook_token'] as $k) setSetting($k, $v($k), $uid);
            if ($v('wa_app_secret') !== '') setSetting('wa_app_secret', encryptSecret($v('wa_app_secret')), $uid);
            $ok = 'The school rules were saved.'; logActivity('whatsapp_school', 'settings', 'WhatsApp school rules saved: ' . (waOn() ? 'on' : 'off'));
        }
    } elseif ($a === 'gen_webhook' && $admin) {
        setSetting('wa_webhook_token', bin2hex(random_bytes(12)), $uid); $ok = 'A new verify token was made. Paste it into Meta when you set up the webhook.';
    } else { Session::flash('wa_msg', ['error', 'Not allowed', 'You cannot do that here.']); redirect(APP_URL . '/pages/settings/whatsapp.php'); }
    if ($errors) { Session::flash('wa_form', ['errors' => $errors, 'old' => $_POST, 'section' => $a]); Session::flash('wa_msg', ['error', 'Not saved', 'Fix the highlighted fields.']); }
    else Session::flash('wa_msg', ['success', 'Saved', $ok]);
    redirect(APP_URL . '/pages/settings/whatsapp.php' . ['save_person' => '#person', 'save_sender' => '#sender', 'save_texts' => '#messages', 'reset_texts' => '#messages', 'save_school' => '#school', 'gen_webhook' => '#school'][$a]);
}

$me = $db->fetchOne("SELECT name, whatsapp_number, whatsapp_consent_at, whatsapp_relation, whatsapp_todo FROM users WHERE id = ?", [$uid]);
$own = $teacher ? waSenderOf($uid) : null; $school = $admin ? waSenderOf(null) : null;
$s_all = ['school' => $school, 'own' => $own];
$val = fn($k, $def = '') => array_key_exists($k, $O) ? (string) $O[$k] : $def;
$err = fn($k) => !empty($E[$k]) ? '<div class="invalid-feedback show">' . htmlspecialchars(t($E[$k])) . '</div>' : '';
$mask = fn($s) => $s && decryptSecret((string) $s['token']) !== '' ? '••••' . substr(decryptSecret((string) $s['token']), -4) : '';
$log = $teacher ? $db->fetchAll("SELECT q.*, u.name AS who, s.owner_id AS sowner FROM whatsapp_queue q INNER JOIN users u ON u.id = q.recipient_id INNER JOIN whatsapp_senders s ON s.id = q.sender_id
    WHERE " . ($admin ? '1 = 1' : 's.owner_id = ' . $uid) . " ORDER BY q.id DESC LIMIT 25") : [];
$webhook = APP_URL . '/pages/whatsapp/webhook.php';
$page_title = 'WhatsApp'; $page_subtitle = 'Messages through WhatsApp — optional, and you decide'; $active_menu = 'settings'; $settings_page = true; $page_scripts = ['assets/js/class.js'];
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode(t($task_result[1])) . ", text: " . json_encode(t($task_result[2])) . ", confirmButtonColor: '#4361ee'" . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
$status = !waOn() ? 'off' : (waAutoOn() ? 'auto' : 'click');
?>
<section class="set-section" id="status">
    <header><h3>How it works</h3><p><span>WhatsApp is optional.</span> <strong>Level 1</strong> <span>is a button that opens WhatsApp with the message ready — you press Send yourself.</span> <strong>Level 2</strong> <span>sends by itself through the official WhatsApp Business service, from a sender somebody set up below.</span></p></header>
    <div class="body"><p class="wa-state wa-<?php echo $status; ?>"><i class="fas fa-circle"></i> <span><?php echo ['off' => 'WhatsApp is switched off for this school.', 'click' => 'Level 1 is on (buttons). Automatic sending is off.', 'auto' => 'Level 1 and Level 2 are on.'][$status]; ?></span><?php echo $admin && $status === 'off' ? ' <span>Switch it on in “School rules” below.</span>' : ''; ?></p></div>
</section>

<section class="set-section" id="person">
    <header><h3>My WhatsApp</h3><p>The number that receives your messages. Only you and your teachers see it.</p></header>
    <div class="body">
        <form method="POST" class="profile-form" novalidate><?php echo csrfField(); ?><input type="hidden" name="action" value="save_person">
            <div class="form-group"><label for="waNumber">WhatsApp number</label>
                <input type="text" id="waNumber" name="number" inputmode="tel" maxlength="30" placeholder="+93 700 123 456" value="<?php echo htmlspecialchars($val('number', (string) ($me['whatsapp_number'] ? '+' . $me['whatsapp_number'] : ''))); ?>" class="<?php echo !empty($E['number']) ? 'is-invalid' : ''; ?>"><?php echo $err('number'); ?></div>
            <?php if ($teacher): ?>
            <label class="switch-row"><input type="checkbox" name="consent" value="1" <?php echo ($form['section'] === 'save_person' ? !empty($O['consent']) : !empty($me['whatsapp_consent_at'])) ? 'checked' : ''; ?>>
                <span><strong>I agree to receive messages on this number</strong><small class="form-hint" style="display:block;margin:2px 0 0;">Reminders and alerts from <?php echo htmlspecialchars(waSchoolName()); ?>. You can stop any time here or by replying STOP.</small></span></label>
            <?php echo $err('consent'); ?>
            <label class="switch-row"><input type="checkbox" name="todo" value="1" <?php echo ($form['section'] === 'save_person' ? !empty($O['todo']) : !empty($me['whatsapp_todo'])) ? 'checked' : ''; ?>>
                <span><strong>Send me the “Today” list every morning</strong><small class="form-hint" style="display:block;margin:2px 0 0;">What needs you today: attendance, work to grade, research waiting. Untick it to keep your number but stop this message.</small></span></label>
            <?php else: ?>
            <div class="form-group"><label for="waRel">Whose number is it?</label><select id="waRel" name="relation"><?php foreach (waRelations() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo ($form['section'] === 'save_person' ? ($O['relation'] ?? 'self') : ($me['whatsapp_relation'] ?: 'self')) === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars(t($rl)); ?></option><?php endforeach; ?></select>
                <small class="form-hint">The school sends messages about attendance and results to this number: yours, or your father's, uncle's or elder brother's.<?php echo $me['whatsapp_number'] && !$me['whatsapp_consent_at'] ? ' Messages to this number were stopped (a STOP reply). Save the number again to receive them.' : ''; ?></small></div>
            <?php endif; ?>
            <div class="form-actions" style="margin-top:12px;"><button class="btn-app"><i class="fas fa-save"></i> Save my number</button></div>
        </form>
    </div>
</section>

<?php if ($teacher): foreach ([['own', 'My own sender', 'Send automatically from your own WhatsApp Business account. Only you see these details.', $own, $uid], ['school', 'The school\'s sender', 'The WhatsApp Business account of the whole school — used when a teacher has no sender of their own.', $school, null]] as [$which, $title, $desc, $s, $ownerId]):
    if ($which === 'school' && !$admin) continue;
    if ($which === 'own' && !$admin && waSetting('wa_teachers_own') !== '1') { echo '<section class="set-section" id="sender"><header><h3>My own sender</h3></header><div class="body"><p class="form-hint" style="margin:0;">The administrator has not allowed teachers to use their own sender.</p></div></section>'; continue; }
    $sel = waSenderKinds($s ?: []); $sid = 'wa_' . $which; $show = $form['section'] === 'save_sender' && ($O['which'] ?? '') === $which; ?>
<section class="set-section" <?php echo $which === 'own' ? 'id="sender"' : 'id="schoolsender"'; ?>>
    <header><h3><?php echo $title; ?></h3><p><?php echo $desc; ?></p></header>
    <div class="body">
        <?php if ($which === 'own' && !empty($E['sender'])): echo $err('sender'); endif; ?>
        <form method="POST" class="profile-form" novalidate autocomplete="off"><?php echo csrfField(); ?><input type="hidden" name="action" value="save_sender"><input type="hidden" name="which" value="<?php echo $which; ?>">
            <div class="form-row">
                <div class="form-group"><label for="<?php echo $sid; ?>_pid">Phone number ID</label><input type="text" id="<?php echo $sid; ?>_pid" name="phone_number_id" inputmode="numeric" value="<?php echo htmlspecialchars($show ? $val('phone_number_id') : (string) ($s['phone_number_id'] ?? '')); ?>" class="<?php echo $show && !empty($E['phone_number_id']) ? 'is-invalid' : ''; ?>"><?php echo $show ? $err('phone_number_id') : ''; ?></div>
                <div class="form-group"><label for="<?php echo $sid; ?>_bid">Business account ID <small class="form-hint" style="display:inline;margin:0;">optional</small></label><input type="text" id="<?php echo $sid; ?>_bid" name="business_id" inputmode="numeric" value="<?php echo htmlspecialchars($show ? $val('business_id') : (string) ($s['business_id'] ?? '')); ?>" class="<?php echo $show && !empty($E['business_id']) ? 'is-invalid' : ''; ?>"><?php echo $show ? $err('business_id') : ''; ?></div>
            </div>
            <div class="form-group"><label for="<?php echo $sid; ?>_tok">Access token <?php echo $mask($s) ? '<small class="form-hint" style="display:inline;margin:0;">saved: ' . $mask($s) . ' — leave empty to keep it</small>' : ''; ?></label>
                <input type="password" id="<?php echo $sid; ?>_tok" name="token" autocomplete="new-password" placeholder="<?php echo $mask($s) ? '' : 'Paste the permanent access token'; ?>" class="<?php echo $show && !empty($E['token']) ? 'is-invalid' : ''; ?>"><?php echo $show ? $err('token') : ''; ?>
                <small class="form-hint">Kept encrypted; shown again only as its last 4 characters.</small></div>
            <div class="form-row">
                <div class="form-group"><label for="<?php echo $sid; ?>_lab">Label <small class="form-hint" style="display:inline;margin:0;">optional</small></label><input type="text" id="<?php echo $sid; ?>_lab" name="label" maxlength="100" value="<?php echo htmlspecialchars($show ? $val('label') : (string) ($s['label'] ?? '')); ?>"></div>
                <div class="form-group"><label for="<?php echo $sid; ?>_lim">Messages per day <small class="form-hint" style="display:inline;margin:0;">0 = no limit</small></label><input type="number" id="<?php echo $sid; ?>_lim" name="daily_limit" min="0" max="100000" value="<?php echo htmlspecialchars($show ? $val('daily_limit', '0') : (string) ($s['daily_limit'] ?? 0)); ?>" class="<?php echo $show && !empty($E['daily_limit']) ? 'is-invalid' : ''; ?>"><?php echo $show ? $err('daily_limit') : ''; ?></div>
            </div>
            <div class="form-group"><label>Send these automatically</label>
                <div class="pref-grid"><?php foreach ($kinds as $k => $def): if (!empty($def['click_only'])) continue; ?>
                    <label class="switch-row"><input type="checkbox" name="kinds[]" value="<?php echo $k; ?>" <?php echo in_array($k, $show ? (array) ($O['kinds'] ?? []) : $sel, true) ? 'checked' : ''; ?>><span><strong><?php echo htmlspecialchars(t($def['label'])); ?></strong><small class="form-hint" style="display:block;margin:2px 0 0;"><?php echo htmlspecialchars(t($def['help'])); ?></small></span></label><?php endforeach; ?></div></div>
            <label class="switch-row"><input type="checkbox" name="is_enabled" value="1" <?php echo ($show ? !empty($O['is_enabled']) : !empty($s['is_enabled'])) ? 'checked' : ''; ?>><span><strong>This sender is on</strong><small class="form-hint" style="display:block;margin:2px 0 0;">Needs the phone number ID and token. Automatic sending must also be on in the school rules.</small></span></label>
            <?php echo $show ? $err('is_enabled') : ''; ?>
            <div class="form-actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;"><button class="btn-app"><i class="fas fa-save"></i> Save sender</button></div>
        </form>
        <?php if ($s): ?><form method="POST" style="margin-top:8px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="test_sender"><input type="hidden" name="which" value="<?php echo $which; ?>">
            <button class="btn-app btn-outline btn-sm"><i class="fas fa-paper-plane"></i> Send me a test</button>
            <?php if ($s['last_test_at']): ?><span class="form-hint" style="display:inline;margin-inline-start:8px;">Last test <?php echo timeAgo($s['last_test_at']); ?>: <b style="color:<?php echo $s['last_test_ok'] ? '#0b8a64' : '#c4282d'; ?>"><?php echo $s['last_test_ok'] ? 'worked' : 'failed'; ?></b></span><?php endif; ?></form><?php endif; ?>
    </div>
</section>
<?php endforeach; endif; ?>

<?php if ($teacher): ?>
<section class="set-section" id="messages">
    <header><h3>Messages</h3><p><span><?php echo $admin ? 'The wording of every message, in every language (the school\'s wording).' : 'Your own wording of every message. Where you change nothing, the school\'s wording is used.'; ?></span> <span>Each text uses every {placeholder} once. The template name is the name Meta approved for that text — needed for Level 2 only.</span></p></header>
    <div class="body">
        <form method="POST" novalidate><?php echo csrfField(); ?><input type="hidden" name="action" value="save_texts">
            <?php foreach ($kinds as $k => $def): ?>
            <details class="wa-kind"<?php echo $form['section'] === 'save_texts' && array_filter(array_keys($E), fn($x) => strpos($x, "_{$k}_") !== false) ? ' open' : ''; ?>>
                <summary><strong><?php echo htmlspecialchars(t($def['label'])); ?></strong> <span class="form-hint" style="display:inline;margin:0;"><?php echo htmlspecialchars(t($def['help'])); ?></span></summary>
                <p class="form-hint" style="margin:6px 0;"><span>Placeholders:</span> <?php foreach ($def['params'] as $p): ?><code>{<?php echo $p; ?>}</code> <?php endforeach; ?></p>
                <?php foreach ($langs as $lc => $ln): $cur = waText($k, $lc, $scope_owner); $b = $form['section'] === 'save_texts' ? $val("body")[$k][$lc] ?? $cur['body'] : $cur['body']; ?>
                <div class="form-group"><label for="wt_<?php echo $k . $lc; ?>"><?php echo $ln; ?></label>
                    <textarea id="wt_<?php echo $k . $lc; ?>" name="body[<?php echo $k; ?>][<?php echo $lc; ?>]" rows="2" maxlength="900" class="<?php echo !empty($E["text_{$k}_{$lc}"]) ? 'is-invalid' : ''; ?>" <?php echo $lc !== 'en' ? 'dir="rtl"' : ''; ?>><?php echo htmlspecialchars((string) (is_array($b) ? ($O['body'][$k][$lc] ?? '') : $b)); ?></textarea><?php echo $err("text_{$k}_{$lc}"); ?>
                    <?php if (empty($def['click_only'])): ?><div class="wa-tplrow"><input type="text" name="tpl[<?php echo $k; ?>][<?php echo $lc; ?>]" maxlength="100" placeholder="Meta template name (optional)" value="<?php echo htmlspecialchars((string) ($form['section'] === 'save_texts' ? ($O['tpl'][$k][$lc] ?? '') : ($cur['template'] ?? ''))); ?>" aria-label="Template name <?php echo $ln; ?>"><small class="form-hint" style="margin:0;">Text for Meta: <code><?php echo htmlspecialchars(waMetaText($k, $cur['body'])); ?></code></small></div><?php echo $err("tpl_{$k}_{$lc}"); endif; ?></div>
                <?php endforeach; ?>
            </details>
            <?php endforeach; ?>
            <div class="form-actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;"><button class="btn-app"><i class="fas fa-save"></i> Save messages</button></div>
        </form>
        <form method="POST" style="margin-top:8px;" data-confirm="Go back to the built-in wording?" data-confirm-text="Your changed texts and template names are removed." data-confirm-button="Reset"><?php echo csrfField(); ?><input type="hidden" name="action" value="reset_texts"><button class="btn-app btn-outline btn-sm"><i class="fas fa-rotate-left"></i> Reset all messages</button></form>
    </div>
</section>
<?php endif; ?>

<?php if ($admin): $sv = fn($k) => $form['section'] === 'save_school' ? $val($k) : waSetting($k); ?>
<section class="set-section" id="school">
    <header><h3>School rules</h3><p>The switches and limits for the whole school. Only the administrator sees this.</p></header>
    <div class="body">
        <form method="POST" novalidate autocomplete="off"><?php echo csrfField(); ?><input type="hidden" name="action" value="save_school">
            <?php foreach ([['wa_enabled', 'WhatsApp is on', 'The master switch. Off = no buttons and no automatic messages.'], ['wa_click_on', 'Level 1: buttons that open WhatsApp', 'The “WhatsApp” / “Share” buttons next to absences, reminders and announcements.'], ['wa_auto_on', 'Level 2: automatic sending', 'Senders that are set up and switched on may send by themselves.'], ['wa_teachers_own', 'Teachers may use their own sender', 'Each teacher can add their own WhatsApp Business account.'], ['wa_require_number', 'Students must give a WhatsApp number', 'Registration needs a valid number — the student\'s own, or a father\'s, uncle\'s or elder brother\'s — and students cannot clear it.']] as [$k, $l, $h]): ?>
            <label class="switch-row"><input type="checkbox" name="<?php echo $k; ?>" value="1" <?php echo ($form['section'] === 'save_school' ? !empty($O[$k]) : waSetting($k) === '1') ? 'checked' : ''; ?>><span><strong><?php echo $l; ?></strong><small class="form-hint" style="display:block;margin:2px 0 0;"><?php echo $h; ?></small></span></label>
            <?php endforeach; ?>
            <div class="wa-grid">
                <?php foreach ([['wa_quiet_from', 'Quiet hours: from', 'time'], ['wa_quiet_to', 'Quiet hours: until', 'time'], ['wa_deadline_time', 'Send “due tomorrow” after', 'time'], ['wa_todo_time', 'Send the teacher’s “today” after', 'time'], ['wa_default_cc', 'Country code for numbers starting with 0', 'text'], ['wa_api_version', 'WhatsApp API version', 'text'], ['wa_retry_max', 'Tries before a message fails', 'number'], ['wa_retry_gap_min', 'Minutes between tries', 'number'], ['wa_school_name', 'Name used in messages ({school})', 'text']] as [$k, $l, $ty]): ?>
                <div class="form-group"><label for="<?php echo $k; ?>"><?php echo $l; ?></label><input type="<?php echo $ty; ?>" id="<?php echo $k; ?>" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($sv($k)); ?>" class="<?php echo !empty($E[$k]) ? 'is-invalid' : ''; ?>"><?php echo $err($k); ?></div>
                <?php endforeach; ?>
                <div class="form-group"><label for="wa_default_lang">Language when a person has none</label><select id="wa_default_lang" name="wa_default_lang"><?php foreach ($langs as $lc => $ln): ?><option value="<?php echo $lc; ?>" <?php echo $sv('wa_default_lang') === $lc ? 'selected' : ''; ?>><?php echo $ln; ?></option><?php endforeach; ?></select><?php echo $err('wa_default_lang'); ?></div>
            </div>
            <div class="form-group"><label for="wa_stop_words">Words that stop the messages (comma separated)</label><input type="text" id="wa_stop_words" name="wa_stop_words" maxlength="200" value="<?php echo htmlspecialchars($sv('wa_stop_words')); ?>" class="<?php echo !empty($E['wa_stop_words']) ? 'is-invalid' : ''; ?>"><?php echo $err('wa_stop_words'); ?></div>
            <h4 style="margin:18px 0 6px;font-size:15px;">Webhook (so a STOP reply works)</h4>
            <p class="form-hint"><span>In your Meta app: Webhooks → WhatsApp → callback URL</span> <code><?php echo htmlspecialchars($webhook); ?></code> <span>(it must be https), the verify token below, and subscribe to</span> <em>messages</em>.</p>
            <div class="wa-grid">
                <div class="form-group"><label for="wa_webhook_token">Verify token</label><input type="text" id="wa_webhook_token" name="wa_webhook_token" maxlength="100" value="<?php echo htmlspecialchars($sv('wa_webhook_token')); ?>" class="<?php echo !empty($E['wa_webhook_token']) ? 'is-invalid' : ''; ?>"><?php echo $err('wa_webhook_token'); ?></div>
                <div class="form-group"><label for="wa_app_secret">App secret <?php echo waSetting('wa_app_secret') !== '' ? '<small class="form-hint" style="display:inline;margin:0;">saved — leave empty to keep it</small>' : ''; ?></label><input type="password" id="wa_app_secret" name="wa_app_secret" autocomplete="new-password" class="<?php echo !empty($E['wa_app_secret']) ? 'is-invalid' : ''; ?>"><?php echo $err('wa_app_secret'); ?></div>
            </div>
            <div class="form-actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;"><button class="btn-app"><i class="fas fa-save"></i> Save school rules</button></div>
        </form>
        <form method="POST" style="margin-top:8px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="gen_webhook"><button class="btn-app btn-outline btn-sm"><i class="fas fa-key"></i> Make a new verify token</button></form>
    </div>
</section>
<?php endif; ?>

<?php if ($teacher): ?>
<section class="set-section" id="log">
    <header><h3>Recent messages</h3><p><?php echo $admin ? 'What the automatic sender did lately (all senders).' : 'What the automatic sender did lately (your sender).'; ?></p></header>
    <div class="body">
        <?php if (!$log): ?><p class="form-hint" style="margin:0;">Nothing yet.</p><?php else: ?>
        <div class="table-wrap"><table class="app-table"><thead><tr><th>When</th><th>To</th><th>Message</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($log as $q): ?><tr><td><?php echo timeAgo($q['sent_at'] ?: $q['created_at']); ?></td><td><?php echo htmlspecialchars($q['who']); ?></td><td><strong><?php echo htmlspecialchars(t($kinds[$q['kind']]['label'] ?? $q['kind'])); ?></strong><div class="form-hint" style="margin:0;"><?php echo htmlspecialchars(mb_strimwidth((string) $q['body'], 0, 110, '…')); ?></div></td>
                <td><span class="status-badge <?php echo ['sent' => 'graded', 'failed' => 'missing', 'skipped' => 'pending', 'pending' => 'late'][$q['status']]; ?>"><?php echo ucfirst($q['status']); ?></span><?php echo $q['error'] ? '<div class="form-hint" style="margin:2px 0 0;">' . htmlspecialchars($q['error']) . '</div>' : ''; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
