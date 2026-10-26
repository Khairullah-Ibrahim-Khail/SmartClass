<?php
/**
 * My AI (every teacher and student) — sidebar "Settings › My AI".
 * A person can use their OWN AI provider + key (one per person); their AI requests use it
 * first and it is not limited by the school. Teachers can also share it with the students of
 * their classes, with a monthly token limit per student; switching it off (or sharing off)
 * stops students using it right away. Students only get AI in classes whose teacher switched
 * "AI for students" on (class settings) — even with their own key.
 *   POST save | test | load_models | remove      (always the signed-in user's own row)
 * Saving the key loads the provider's model list by itself; the person picks a model from it.
 * Key stored encrypted, never shown again (last 4 characters only). CSRF + PRG; inline errors.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$self = APP_URL . '/pages/settings/my_ai.php';
$registry = aiProviderRegistry();
$is_teacher = isTeacher();
$row = aiPersonalProvider($current_user_id);
$task_result = null;
$errors = [];
$form = null;
$slug = (string) ($_POST['provider'] ?? ($row['provider'] ?? 'openai'));
if (!isset($registry[$slug])) $slug = 'openai';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save') {
        [$form, $errors] = aiValidateProviderForm($slug, $_POST, $row);
        $extra = [];
        if ($is_teacher) {
            $limit = trim((string) ($_POST['share_limit'] ?? '20000'));
            if (!preg_match('/^\d{1,9}$/', $limit)) $errors['share_limit'] = 'Enter a whole number of tokens (0 = no limit)';
            $extra = ['share_students' => !empty($_POST['share_students']) ? 1 : 0, 'share_limit' => (int) $limit];
            $form += ['share_students' => !empty($_POST['share_students']), 'share_limit' => $limit];
        }
        if ($errors) {
            $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
        } else {
            $saved = aiSaveProvider((int) $current_user_id, $slug, $form, $row, $current_user_id, $extra);
            logActivity('my_ai', 'settings', 'Saved own AI provider ' . $registry[$slug]['label'] . ($is_teacher && $extra['share_students'] ? ' (shared with students)' : ''));
            Session::flash('my_ai_msg', aiSavedMessage($registry[$slug]['label'], $form['model'], $saved));
            redirect($self);
        }
    } elseif (!$row) {
        $task_result = ['error', 'Nothing saved yet', 'Save your provider first.'];
    } elseif ($action === 'test') {
        $t0 = microtime(true);
        $r = aiChat('test', 'You are a connection test. Reply with exactly: OK', 'Reply with OK.', ['row' => $row, 'max_tokens' => 20, 'temperature' => 0]);
        $task_result = $r['ok']
            ? ['success', 'Connected', 'Model ' . $r['model'] . ' answered "' . mb_strimwidth(trim($r['text']), 0, 40, '…') . '" in ' . number_format((int) round((microtime(true) - $t0) * 1000)) . ' ms.']
            : ['error', 'Test failed', $r['error']];
    } elseif ($action === 'load_models') {
        try {
            $models = aiListModels($row);
            $db->update('ai_providers', ['models_cache' => json_encode($models)], 'id = ? AND owner_id = ?', [$row['id'], $current_user_id]);
            Session::flash('my_ai_msg', $models ? ['success', count($models) . ' models loaded', 'Pick one in the Model field.']
                                                : ['info', 'No models listed', 'Type the model name yourself.']);
            redirect($self);
        } catch (AiError $e) {
            $task_result = ['error', 'Could not load models', $e->getMessage()];
        }
    } elseif ($action === 'remove') {
        $db->delete('ai_providers', 'owner_id = ?', [$current_user_id]);
        logActivity('my_ai_removed', 'settings', 'Removed own AI provider');
        Session::flash('my_ai_msg', ['success', 'Removed', 'Your key was deleted.' . ($is_teacher ? ' Students no longer use it.' : '')]);
        redirect($self);
    }
}

$task_result = Session::flash('my_ai_msg') ?? $task_result;
$row = aiPersonalProvider($current_user_id);
$models = $row && $row['models_cache'] ? (json_decode($row['models_cache'], true) ?: []) : [];
$v = fn($k, $default = '') => htmlspecialchars((string) ($form[$k] ?? ($row[$k] ?? $default)));
$month = date('Y-m-01 00:00:00');
$my_usage = $db->fetchAll(
    "SELECT key_owner, COUNT(*) AS calls, SUM(input_tokens + output_tokens) AS tokens FROM ai_usage
     WHERE user_id = ? AND created_at >= ? GROUP BY key_owner", [$current_user_id, $month]);
$usage_label = function ($key_owner) use ($current_user_id, $db) {
    if ($key_owner === null) return 'School account';
    if ((int) $key_owner === (int) $current_user_id) return 'Your own key';
    return 'Shared by ' . ($db->fetchOne("SELECT name FROM users WHERE id = ?", [$key_owner])['name'] ?? 'a teacher');
};
// Teacher: who uses my shared key this month (to spot misuse)
$shared_use = $is_teacher ? $db->fetchAll(
    "SELECT u.name, COUNT(*) AS calls, SUM(a.input_tokens + a.output_tokens) AS tokens FROM ai_usage a
     INNER JOIN users u ON a.user_id = u.id
     WHERE a.key_owner = ? AND a.user_id <> ? AND a.created_at >= ? GROUP BY a.user_id ORDER BY tokens DESC LIMIT 20",
    [$current_user_id, $current_user_id, $month]) : [];
// Student: AI per class (teacher's switch + which key would be used)
$class_ai = [];
if (!$is_teacher) {
    foreach (getUserClasses($current_user_id, 'student') as $c) {
        [$src, $why] = aiSources('study', $current_user_id, $c['id']);
        $class_ai[] = ['name' => $c['class_name'], 'source' => $src ? $usage_label($src[0]['key_owner']) : null, 'why' => $why];
    }
}
$st = $row ? (aiProviderUsable($row) ? ['graded', 'Ready'] : (!$row['is_enabled'] ? ['pending', 'Switched off'] : ['late', 'Not complete'])) : ['pending', 'Not set up'];

$page_title = 'My AI';
$page_subtitle = $is_teacher ? 'Use your own AI key — and decide whether your students may use it'
                             : 'Use your own AI key for study helpers in classes where your teacher allows AI';
$active_menu = 'settings';
$settings_page = true;
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const f = document.getElementById('myAiForm');
    bindLiveClear(f);
    const sel = document.getElementById('provider'), url = document.getElementById('base_url'), keyHint = document.getElementById('keyNeeded');
    function sync() {
        const o = sel.options[sel.selectedIndex];
        url.placeholder = o.dataset.base || 'https://api.example.com/v1';
        document.getElementById('baseUrlGroup').hidden = o.dataset.local !== '1';
        if (o.dataset.local !== '1') url.value = '';
        keyHint.textContent = o.dataset.key === '1' ? '*' : '(optional)';
    }
    sel.addEventListener('change', sync); sync();
    document.querySelectorAll('form[data-confirm]').forEach(function (fm) {
        fm.addEventListener('submit', function (e) {
            if (fm.dataset.confirmed) return;
            e.preventDefault();
            Swal.fire({ icon: 'warning', titleText: fm.dataset.confirm, text: fm.dataset.confirmText || '', showCancelButton: true,
                        confirmButtonText: fm.dataset.confirmButton || 'Yes', confirmButtonColor: '#ef476f' })
                .then(function (r) { if (r.isConfirmed) { fm.dataset.confirmed = '1'; fm.submit(); } });
        });
    });
    document.querySelectorAll('form.ai-busy').forEach(function (fm) {
        fm.addEventListener('submit', function () { const b = fm.querySelector('button'); b.disabled = true; b.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> ' + b.dataset.busy; });
    });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php providerServiceNotice('ai'); ?>

<div class="settings-layout ai-layout">
    <section>
        <form method="POST" class="form-card" id="myAiForm" novalidate style="max-width:none;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <h2 class="ai-h2"><i class="fas fa-key"></i> My AI provider <span class="status-badge <?php echo $st[0]; ?>"><?php echo $st[1]; ?></span></h2>
            <p class="form-hint" style="margin:-6px 0 14px;">Your key is stored encrypted and never shown again<?php echo $row && $row['key_hint'] ? ' (saved key ends in …' . htmlspecialchars($row['key_hint']) . ')' : ''; ?>.
                Requests made with it are paid by you and are not limited by the school.</p>
            <div class="form-group">
                <label for="provider">Provider</label>
                <select id="provider" name="provider">
                    <?php foreach ($registry as $k => $r): ?>
                    <option value="<?php echo $k; ?>" data-base="<?php echo htmlspecialchars($r['base']); ?>"  data-key="<?php echo $r['key'] ? '1' : '0'; ?>" data-local="<?php echo aiIsLocalProvider($k) ? '1' : '0'; ?>" <?php echo $slug === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($registry[$slug]['site']): ?><small class="form-hint">Get a key: <a href="<?php echo htmlspecialchars($registry[$slug]['site']); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($registry[$slug]['site']); ?></a></small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="api_key">API key <span class="required-mark" id="keyNeeded">*</span></label>
                <input type="password" id="api_key" name="api_key" autocomplete="new-password" spellcheck="false"
                    placeholder="<?php echo $row && $row['key_hint'] ? 'Saved (…' . htmlspecialchars($row['key_hint']) . ') — leave empty to keep' : 'Paste your key'; ?>"
                    class="<?php echo isset($errors['api_key']) ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['api_key'] ?? ''); ?></div>
            </div>
            <?php echo aiModelField('model', $row && $row['provider'] === $slug ? $models : [], (string) ($form['model'] ?? ($row['model'] ?? '')), $errors['model'] ?? '', $registry[$slug]['label'], (bool) $row); ?>
            <div class="form-group" id="baseUrlGroup"<?php echo aiIsLocalProvider($slug) ? '' : ' hidden'; ?>>
                <label for="base_url">API address <span class="form-hint" style="display:inline;margin:0;"><?php echo $registry[$slug]['base'] ? 'leave empty for ' . htmlspecialchars($registry[$slug]['base']) : 'required'; ?></span></label>
                <input type="url" id="base_url" name="base_url" maxlength="255" spellcheck="false" value="<?php echo $v('base_url'); ?>" class="<?php echo isset($errors['base_url']) ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['base_url'] ?? ''); ?></div>
            </div>
            <label class="switch-row" style="margin-bottom:14px;"><input type="checkbox" name="is_enabled" value="1" <?php echo (!$row || $row['is_enabled']) ? 'checked' : ''; ?>>
                <span><strong>Use my provider</strong><small class="form-hint" style="display:block;margin:2px 0 0;">Switch off to stop using it<?php echo $is_teacher ? ' — your students stop too' : ''; ?>.</small></span></label>

            <?php if ($is_teacher): ?>
            <fieldset class="ai-features">
                <legend>Share with my students</legend>
                <label class="switch-row"><input type="checkbox" name="share_students" value="1" <?php echo !empty($form['share_students'] ?? ($row['share_students'] ?? 0)) ? 'checked' : ''; ?>>
                    <span><strong>Students in my classes may use my provider</strong>
                        <small class="form-hint" style="display:block;margin:2px 0 0;">Only in classes where you switched "AI for students" on (class settings). They never see your key.</small></span></label>
                <div class="form-group" style="margin-top:12px;">
                    <label for="share_limit">Monthly limit per student <span class="form-hint" style="display:inline;margin:0;">tokens, 0 = no limit</span></label>
                    <input type="number" min="0" id="share_limit" name="share_limit" value="<?php echo htmlspecialchars((string) ($form['share_limit'] ?? ($row['share_limit'] ?? 20000))); ?>"
                        class="<?php echo isset($errors['share_limit']) ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['share_limit'] ?? ''); ?></div>
                    <small class="form-hint">Stops one student from using up your credit. About 20,000 tokens ≈ 10–20 study-helper answers.</small>
                </div>
            </fieldset>
            <?php endif; ?>

            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save</button></div>
        </form>
        <?php if ($row): ?>
        <div class="ai-prov-actions" style="margin-top:12px;">
            <form method="POST" class="ai-busy"><?php echo csrfField(); ?><input type="hidden" name="action" value="test">
                <button type="submit" class="btn-app btn-sm btn-outline" data-busy="Testing…"><i class="fas fa-vial"></i> Test connection</button></form>
            <form method="POST" class="ai-busy"><?php echo csrfField(); ?><input type="hidden" name="action" value="load_models">
                <button type="submit" class="btn-app btn-sm btn-outline" data-busy="Loading…"><i class="fas fa-sync-alt"></i> Refresh models</button></form>
            <form method="POST" data-confirm="Remove your AI provider?" data-confirm-text="Your key is deleted.<?php echo $is_teacher ? ' Students who used it stop.' : ''; ?>" data-confirm-button="Remove">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="remove">
                <button type="submit" class="btn-app btn-sm btn-outline btn-danger-outline"><i class="fas fa-trash"></i> Remove</button></form>
        </div>
        <?php endif; ?>
    </section>

    <section>
        <div class="content-card">
            <div class="card-head"><h2><i class="fas fa-route"></i> Where my AI comes from</h2></div>
            <div class="ai-explain">
                <?php if ($is_teacher): ?>
                <ol>
                    <li>Your own provider <?php echo aiProviderUsable($row) ? '<span class="status-badge graded">ready</span>' : '<span class="status-badge pending">not set</span>'; ?></li>
                    <li>The school's provider <?php echo aiEnabled() ? '<span class="status-badge graded">on</span>' : '<span class="status-badge pending">off</span>'; ?>
                        <span class="form-hint" style="display:inline;margin:0;">(set by the school admin, with monthly limits)</span></li>
                </ol>
                <?php else: ?>
                <p class="form-hint" style="margin:0 0 10px;">Your teacher decides whether AI helpers can be used in each class. Then: your own key → your teacher's shared key → the school's.</p>
                <?php if (!$class_ai): ?><p class="form-hint">You are not in any class yet.</p><?php endif; ?>
                <?php foreach ($class_ai as $c): ?>
                <div class="ai-class-row">
                    <strong><?php echo htmlspecialchars($c['name']); ?></strong>
                    <?php if ($c['source']): ?>
                    <span class="status-badge graded"><i class="fas fa-check"></i> AI on · <?php echo htmlspecialchars($c['source']); ?></span>
                    <?php else: ?>
                    <span class="status-badge pending" title="<?php echo htmlspecialchars((string) $c['why']); ?>"><?php echo htmlspecialchars(mb_strimwidth((string) $c['why'], 0, 60, '…')); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card">
            <div class="card-head"><h2><i class="fas fa-chart-bar"></i> My usage this month</h2></div>
            <?php if (!$my_usage): ?><p class="form-hint" style="padding:0 20px 18px;">No AI used this month.</p><?php else: ?>
            <div class="table-wrap"><table class="app-table"><thead><tr><th>Paid by</th><th>Calls</th><th>Tokens</th></tr></thead><tbody>
            <?php foreach ($my_usage as $u): ?>
            <tr><td><?php echo htmlspecialchars($usage_label($u['key_owner'] !== null ? (int) $u['key_owner'] : null)); ?></td><td><?php echo (int) $u['calls']; ?></td><td><?php echo number_format((int) $u['tokens']); ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>

        <?php if ($is_teacher): ?>
        <div class="content-card">
            <div class="card-head"><h2><i class="fas fa-users"></i> Students using my provider this month</h2></div>
            <?php if (!$shared_use): ?><p class="form-hint" style="padding:0 20px 18px;">None yet.</p><?php else: ?>
            <div class="table-wrap"><table class="app-table"><thead><tr><th>Student</th><th>Calls</th><th>Tokens</th></tr></thead><tbody>
            <?php foreach ($shared_use as $u): ?>
            <tr><td><?php echo htmlspecialchars($u['name']); ?></td><td><?php echo (int) $u['calls']; ?></td>
                <td><?php echo number_format((int) $u['tokens']); ?><?php if ($row && (int) $row['share_limit'] > 0 && (int) $u['tokens'] >= (int) $row['share_limit']): ?> <span class="status-badge missing">limit reached</span><?php endif; ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
