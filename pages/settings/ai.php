<?php
/**
 * AI Settings (super admin) — sidebar "Settings › AI Settings".
 * Everything about AI is configured here, never in files (includes/ai.php does the calls):
 *   POST save_general     on/off, default + fallback provider, provider per feature,
 *                         monthly token limits (per person / whole school), timeout, students on/off
 *   POST save_provider    API key (stored encrypted, never shown again — leave empty to keep),
 *                         model, address, enabled — saving loads the provider's model list by itself
 *   POST load_models      refresh the model list
 *   POST test_provider    a tiny real request ("reply OK") — shows the exact error if it fails
 *   POST remove_provider  forget the provider (and stop using it anywhere)
 * Inline field errors; SweetAlert2 for task results; CSRF + Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireAdmin();   // school-wide setting: super admin only

$self = APP_URL . '/pages/settings/ai.php';
$registry = aiProviderRegistry();
$features = aiFeatures();
$rows = aiProviderRows();
$task_result = null;
$gen_errors = ['ai_default_provider' => '', 'ai_limit_user' => '', 'ai_limit_school' => '', 'ai_timeout' => ''];
$prov_errors = [];      // provider => [field => message]
$prov_form = [];        // provider => posted values (re-shown after an error)
$open_provider = (string) ($_GET['p'] ?? '');

/** A provider row exists and can be called (key readable, model set). */
function providerStatus($slug, $row) {
    if (!$row) return ['pending', 'Not set up'];
    if (!$row['is_enabled']) return ['pending', 'Switched off'];
    $reg = aiProviderRegistry()[$slug];
    if ($reg['key'] && decryptSecret($row['api_key']) === '') return ['missing', 'Key missing — enter it again'];
    if (trim((string) $row['model']) === '') return ['late', 'Choose a model'];
    if (aiBaseUrl($row) === '') return ['late', 'Address missing'];
    return ['graded', 'Ready'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $slug = (string) ($_POST['provider'] ?? '');
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];

    } elseif ($action === 'toggle_ai') {
        // the one big switch: AI for the whole school on / off
        $want = !empty($_POST['on']);
        if ($want && !aiProviderUsable($rows[getSetting('ai_default_provider', '')] ?? null)) {
            $task_result = ['error', 'Cannot switch on', 'Choose a default provider that is set up and ready first (General below).'];
        } else {
            setSetting('ai_enabled', $want ? '1' : '0', $current_user_id);
            logActivity('ai_settings', 'settings', 'AI switched ' . ($want ? 'on' : 'OFF') . ' for the whole school');
            Session::flash('ai_msg', ['success', $want ? 'AI is on' : 'AI is off for the whole school', $want ? 'Teachers and students can use the AI features again.' : 'Every AI feature is hidden or refused until you switch it on again. Everything else works as before.']);
            redirect(APP_URL . '/pages/settings/ai.php');
        }
    } elseif ($action === 'review_report') {
        $rid = (int) ($_POST['report_id'] ?? 0);
        $db->update('ai_reports', ['status' => 'reviewed', 'reviewed_by' => $current_user_id, 'reviewed_at' => date('Y-m-d H:i:s')], "id = ? AND status = 'open'", [$rid]);
        Session::flash('ai_msg', ['success', 'Marked as reviewed', 'Thank you.']);
        redirect(APP_URL . '/pages/settings/ai.php#reports');
    } elseif ($action === 'save_general') {
        $usable = array_keys(array_filter($rows, fn($r) => aiProviderUsable($r)));
        $default = (string) ($_POST['ai_default_provider'] ?? '');
        $fallback = (string) ($_POST['ai_fallback_provider'] ?? '');
        $enabled = !empty($_POST['ai_enabled']);
        $nums = [];
        foreach (['ai_limit_user', 'ai_limit_school', 'ai_timeout'] as $k) {
            $v = trim((string) ($_POST[$k] ?? ''));
            $nums[$k] = $v === '' ? ($k === 'ai_timeout' ? '60' : '0') : $v;
            if (!preg_match('/^\d{1,10}$/', $nums[$k])) $gen_errors[$k] = 'Enter a whole number (0 = no limit)';
        }
        if (!$gen_errors['ai_timeout'] && ((int) $nums['ai_timeout'] < 10 || (int) $nums['ai_timeout'] > 300)) {
            $gen_errors['ai_timeout'] = 'Between 10 and 300 seconds';
        }
        if ($enabled && !in_array($default, $usable, true)) {
            $gen_errors['ai_default_provider'] = 'Choose a provider that is set up and ready (Providers below)';
        } elseif ($default !== '' && !isset($registry[$default])) {
            $gen_errors['ai_default_provider'] = 'Unknown provider';
        }
        if (!array_filter($gen_errors)) {
            setSetting('ai_enabled', $enabled ? '1' : '0', $current_user_id);
            setSetting('ai_default_provider', $default, $current_user_id);
            setSetting('ai_fallback_provider', isset($registry[$fallback]) && $fallback !== $default ? $fallback : '', $current_user_id);
            foreach ($features as $fk => $f) {
                $v = (string) ($_POST['ai_feature_' . $fk] ?? '');
                setSetting('ai_feature_' . $fk, isset($registry[$v]) ? $v : '', $current_user_id);
            }
            foreach ($nums as $k => $v) setSetting($k, (string) (int) $v, $current_user_id);
            setSetting('ai_students', !empty($_POST['ai_students']) ? '1' : '0', $current_user_id);
            logActivity('ai_settings', 'settings', 'Updated AI settings (' . ($enabled ? 'on' : 'off') . ', default ' . ($default ?: 'none') . ')');
            Session::flash('ai_msg', ['success', 'AI settings saved', $enabled ? 'AI features are on.' : 'AI features are off — everything works manually.']);
            redirect($self);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];

    } elseif (!isset($registry[$slug])) {
        $task_result = ['error', 'Unknown provider', 'Pick one of the providers on this page.'];

    } elseif ($action === 'save_provider') {
        $reg = $registry[$slug];
        $row = $rows[$slug] ?? null;
        [$f, $e] = aiValidateProviderForm($slug, $_POST, $row);
        if ($e) {
            $prov_errors[$slug] = $e;
            $prov_form[$slug] = $f;
            $open_provider = $slug;
            $task_result = ['error', $reg['label'] . ' not saved', 'Fix the highlighted fields.'];
        } else {
            $saved = aiSaveProvider(null, $slug, $f, $row, $current_user_id);
            logActivity('ai_provider', 'settings', 'Saved AI provider ' . $reg['label'] . ($f['model'] !== '' ? ' (model ' . $f['model'] . ')' : '') . ($f['api_key'] !== '' ? ', new key' : ''));
            Session::flash('ai_msg', aiSavedMessage($reg['label'], $f['model'], $saved));
            redirect($self . '?p=' . rawurlencode($slug) . '#prov-' . $slug);
        }

    } elseif ($action === 'load_models' || $action === 'test_provider') {
        $row = $rows[$slug] ?? null;
        $label = $registry[$slug]['label'];
        if (!$row) {
            $task_result = ['error', $label . ' is not set up', 'Save the API key first.'];
        } elseif ($action === 'load_models') {
            try {
                $models = aiListModels($row);
                $db->update('ai_providers', ['models_cache' => json_encode($models)], 'id = ?', [$row['id']]);
                Session::flash('ai_msg', $models
                    ? ['success', count($models) . ' models loaded', 'Pick one in the Model list of ' . $label . '.']
                    : ['info', 'No models listed', $label . ' did not list any models — type the model name yourself.']);
                redirect($self . '?p=' . rawurlencode($slug) . '#prov-' . $slug);
            } catch (AiError $e) {
                $task_result = ['error', 'Could not load models', $e->getMessage()];
                $open_provider = $slug;
            }
        } else {
            $t0 = microtime(true);
            $r = aiChat('test', 'You are a connection test. Reply with exactly: OK', 'Reply with OK.',
                ['provider' => $slug, 'max_tokens' => 20, 'temperature' => 0]);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $task_result = $r['ok']
                ? ['success', 'Connected to ' . $label, 'Model ' . $r['model'] . ' answered "' . mb_strimwidth(trim($r['text']), 0, 40, '…') . '" in ' . number_format($ms) . ' ms.']
                : ['error', 'Test failed', $r['error']];
            $open_provider = $slug;
        }

    } elseif ($action === 'remove_provider') {
        $db->delete('ai_providers', 'provider = ? AND owner_id IS NULL', [$slug]);   // school row only
        foreach (array_merge(['ai_default_provider', 'ai_fallback_provider'], array_map(fn($k) => 'ai_feature_' . $k, array_keys($features))) as $k) {
            if (getSetting($k, '') === $slug) setSetting($k, '', $current_user_id);
        }
        if (getSetting('ai_default_provider', '') === '') setSetting('ai_enabled', '0', $current_user_id);
        logActivity('ai_provider_removed', 'settings', 'Removed AI provider ' . $registry[$slug]['label']);
        Session::flash('ai_msg', ['success', $registry[$slug]['label'] . ' removed', 'Its key was deleted.']);
        redirect($self);
    }
}

$task_result = Session::flash('ai_msg') ?? $task_result;
$rows = aiProviderRows();
$usable = array_keys(array_filter($rows, fn($r) => aiProviderUsable($r)));
$month_start = date('Y-m-01 00:00:00');
$totals = $db->fetchOne(
    "SELECT COUNT(*) AS calls, SUM(status = 'error') AS errors, COALESCE(SUM(input_tokens), 0) AS tin,
            COALESCE(SUM(output_tokens), 0) AS tout, SUM(cost) AS cost
     FROM ai_usage WHERE created_at >= ?", [$month_start]);
$by_feature = $db->fetchAll(
    "SELECT provider, feature, COUNT(*) AS calls, SUM(status = 'error') AS errors,
            SUM(input_tokens + output_tokens) AS tokens, SUM(cost) AS cost
     FROM ai_usage WHERE created_at >= ? GROUP BY provider, feature ORDER BY tokens DESC", [$month_start]);
$recent = $db->fetchAll(
    "SELECT a.*, u.name AS user_name FROM ai_usage a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 15");
$limit_school = (int) getSetting('ai_limit_school', '0');
$used_school = aiTokensThisMonth(null);   // school keys only (personal keys are not limited by the school)
$feature_labels = $features + ['test' => ['Connection test', 'admin']];

$page_title = 'AI Settings';
$page_subtitle = 'Choose AI providers for quiz generation, grading help and study helpers — the teacher always decides';
$active_menu = 'settings';
$settings_page = true;
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (f.dataset.confirmed) return;
            e.preventDefault();
            Swal.fire({ icon: 'warning', titleText: f.dataset.confirm, text: f.dataset.confirmText || '', showCancelButton: true,
                        confirmButtonText: f.dataset.confirmButton || 'Yes', confirmButtonColor: '#ef476f' })
                .then(function (r) { if (r.isConfirmed) { f.dataset.confirmed = '1'; f.submit(); } });
        });
    });
    document.querySelectorAll('form.ai-prov-form, #aiGeneral').forEach(function (f) { bindLiveClear(f); });
    // Test / Load models can take a few seconds: show it
    document.querySelectorAll('form.ai-busy').forEach(function (f) {
        f.addEventListener('submit', function () {
            const b = f.querySelector('button');
            b.disabled = true;
            b.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> ' + b.dataset.busy;
        });
    });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php providerServiceNotice('ai'); ?>

<?php $bud = aiBudgetStatus(); ?>
<?php if (!aiEnabled()): ?>
<div class="alert-box" style="margin-bottom:16px;border-left:4px solid #ef476f;"><i class="fas fa-power-off" style="color:#ef476f;"></i>
    <span><strong>AI is off for the whole school.</strong> Teachers and students see no AI buttons. <form method="POST" style="display:inline;margin-left:8px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_ai"><input type="hidden" name="on" value="1"><button type="submit" class="btn-app btn-sm"><i class="fas fa-power-off"></i> Switch AI on</button></form></span></div>
<?php else: ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:10px;"><form method="POST" data-confirm="Switch AI off for the whole school?" data-confirm-text="Every AI feature is hidden or refused for everyone until you switch it on again. Nothing else changes." data-confirm-button="Switch AI off">
    <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_ai"><input type="hidden" name="on" value="0"><button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-power-off"></i> Switch AI off for everyone</button></form></div>
<?php endif; ?>
<?php if ($bud['limit'] > 0): ?>
<div class="content-card" style="margin-bottom:16px;"><div class="card-inner">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;"><strong>Monthly AI budget on the school account</strong><span><?php echo number_format($bud['used']); ?> of <?php echo number_format($bud['limit']); ?> tokens · <?php echo min(100, $bud['pct']); ?>%</span></div>
    <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo min(100, $bud['pct']); ?>" aria-label="AI budget used this month" style="height:12px;border-radius:99px;background:rgba(127,140,170,.25);margin-top:8px;overflow:hidden;">
        <div style="height:100%;width:<?php echo min(100, $bud['pct']); ?>%;background:<?php echo $bud['pct'] >= 100 ? '#c9284f' : ($bud['pct'] >= 80 ? '#e9a23b' : '#06a77d'); ?>;"></div></div>
    <p class="form-hint" style="margin:8px 0 0;">You are told at 80% and at 100%. At 100% the school account stops until the 1st of next month (people with their own provider are not affected).</p>
</div></div>
<?php endif; ?>

<div class="ai-status content-card">
    <div><span class="status-badge <?php echo aiEnabled() ? 'graded' : 'pending'; ?>"><?php echo aiEnabled() ? 'AI is on' : 'AI is off'; ?></span>
        <span class="form-hint" style="margin:0 0 0 8px;"><?php echo aiEnabled()
            ? 'Default: ' . htmlspecialchars($registry[getSetting('ai_default_provider')]['label'] ?? '') . (getSetting('ai_fallback_provider') ? ' · fallback: ' . htmlspecialchars($registry[getSetting('ai_fallback_provider')]['label'] ?? '') : '')
            : 'Without AI every feature still works by hand.'; ?></span></div>
    <div class="form-hint" style="margin:0;">This month on school keys: <strong><?php echo number_format($used_school); ?></strong> tokens<?php echo $limit_school ? ' of ' . number_format($limit_school) : ''; ?>
        · <?php echo (int) $totals['calls']; ?> calls</div>
</div>

<div class="settings-layout ai-layout">
    <form method="POST" class="form-card" id="aiGeneral" novalidate style="max-width:none;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_general">
        <h2 class="ai-h2"><i class="fas fa-sliders-h"></i> General</h2>

        <div class="form-group">
            <label class="switch-row">
                <input type="checkbox" name="ai_enabled" value="1" <?php echo getSetting('ai_enabled', '0') === '1' ? 'checked' : ''; ?>>
                <span><strong>Use AI features</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">AI only drafts and suggests — nothing is published, graded or emailed without a teacher.</small></span>
            </label>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="ai_default_provider">Default provider</label>
                <select id="ai_default_provider" name="ai_default_provider" class="<?php echo $gen_errors['ai_default_provider'] ? 'is-invalid' : ''; ?>">
                    <option value="">— none —</option>
                    <?php foreach ($usable as $u): ?>
                    <option value="<?php echo $u; ?>" <?php echo getSetting('ai_default_provider') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($registry[$u]['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?php echo htmlspecialchars($gen_errors['ai_default_provider']); ?></div>
                <?php if (!$usable): ?><small class="form-hint">Set up a provider below first.</small><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="ai_fallback_provider">Fallback provider <span class="form-hint" style="display:inline;margin:0;">if the main one fails</span></label>
                <select id="ai_fallback_provider" name="ai_fallback_provider">
                    <option value="">— none —</option>
                    <?php foreach ($usable as $u): ?>
                    <option value="<?php echo $u; ?>" <?php echo getSetting('ai_fallback_provider') === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($registry[$u]['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <fieldset class="ai-features">
            <legend>Provider per feature <span class="form-hint" style="display:inline;margin:0;">optional — e.g. a fast one for quiz drafts, a strong one for grading</span></legend>
            <?php foreach ($features as $fk => [$flabel, $who]): ?>
            <div class="ai-feature-row">
                <label for="feat_<?php echo $fk; ?>"><?php echo htmlspecialchars($flabel); ?> <span class="role-tag"><?php echo $who; ?></span></label>
                <select id="feat_<?php echo $fk; ?>" name="ai_feature_<?php echo $fk; ?>">
                    <option value="">Use the default</option>
                    <?php foreach ($usable as $u): ?>
                    <option value="<?php echo $u; ?>" <?php echo getSetting('ai_feature_' . $fk) === $u ? 'selected' : ''; ?>><?php echo htmlspecialchars($registry[$u]['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
        </fieldset>

        <div class="form-row">
            <div class="form-group">
                <label for="ai_limit_user">Monthly limit per person <span class="form-hint" style="display:inline;margin:0;">tokens</span></label>
                <input type="number" min="0" id="ai_limit_user" name="ai_limit_user" value="<?php echo htmlspecialchars((string) ($_POST['ai_limit_user'] ?? getSetting('ai_limit_user', '0'))); ?>" class="<?php echo $gen_errors['ai_limit_user'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($gen_errors['ai_limit_user']); ?></div>
                <small class="form-hint">0 = no limit. AI stops for that person at the limit until the 1st.</small>
            </div>
            <div class="form-group">
                <label for="ai_limit_school">Monthly limit for the school <span class="form-hint" style="display:inline;margin:0;">tokens</span></label>
                <input type="number" min="0" id="ai_limit_school" name="ai_limit_school" value="<?php echo htmlspecialchars((string) ($_POST['ai_limit_school'] ?? getSetting('ai_limit_school', '0'))); ?>" class="<?php echo $gen_errors['ai_limit_school'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($gen_errors['ai_limit_school']); ?></div>
            </div>
            <div class="form-group">
                <label for="ai_timeout">Timeout <span class="form-hint" style="display:inline;margin:0;">seconds</span></label>
                <input type="number" min="10" max="300" id="ai_timeout" name="ai_timeout" value="<?php echo htmlspecialchars((string) ($_POST['ai_timeout'] ?? getSetting('ai_timeout', '60'))); ?>" class="<?php echo $gen_errors['ai_timeout'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($gen_errors['ai_timeout']); ?></div>
            </div>
        </div>

        <div class="form-group">
            <label class="switch-row">
                <input type="checkbox" name="ai_students" value="1" <?php echo getSetting('ai_students', '0') === '1' ? 'checked' : ''; ?>>
                <span><strong>Students may use the AI helpers</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">Explain my mistakes, lecture study helper, practice quizzes. Never help with graded work.</small></span>
            </label>
        </div>
        <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save</button></div>
    </form>

    <section class="ai-providers">
        <h2 class="ai-h2"><i class="fas fa-plug"></i> Providers</h2>
        <p class="form-hint" style="margin:-4px 0 12px;">API keys are stored encrypted and never shown again. Only the last 4 characters are displayed so you can tell keys apart.</p>
        <?php foreach ($registry as $slug => $reg):
            $row = $rows[$slug] ?? null;
            [$st_class, $st_label] = providerStatus($slug, $row);
            $e = $prov_errors[$slug] ?? [];
            $pf = $prov_form[$slug] ?? null;
            $models = $row && $row['models_cache'] ? (json_decode($row['models_cache'], true) ?: []) : [];
            $val = fn($k, $default = '') => htmlspecialchars((string) ($pf[$k] ?? ($row[$k] ?? $default)));
        ?>
        <article class="content-card ai-prov" id="prov-<?php echo $slug; ?>">
            <header class="ai-prov-head">
                <div>
                    <strong><?php echo htmlspecialchars($reg['label']); ?></strong>
                    <span class="status-badge <?php echo $st_class; ?>"><?php echo $st_label; ?></span>
                    <div class="notif-meta">
                        <?php if ($row): ?>
                        <?php echo $row['model'] ? 'Model ' . htmlspecialchars($row['model']) : 'No model'; ?>
                        <?php echo $row['key_hint'] ? ' · key …' . htmlspecialchars($row['key_hint']) : ''; ?>
                        <?php echo $row['base_url'] ? ' · ' . htmlspecialchars($row['base_url']) : ''; ?>
                        <?php else: ?>
                        <?php echo $reg['key'] ? 'Needs an API key' : 'No key needed'; ?><?php if ($reg['site']): ?> · <a href="<?php echo htmlspecialchars($reg['site']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $reg['key'] ? 'Get a key' : 'Website'; ?></a><?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($row): ?>
                <div class="ai-prov-actions">
                    <form method="POST" class="ai-busy"><?php echo csrfField(); ?><input type="hidden" name="action" value="test_provider"><input type="hidden" name="provider" value="<?php echo $slug; ?>">
                        <button type="submit" class="btn-app btn-sm btn-outline" data-busy="Testing…"><i class="fas fa-vial"></i> Test connection</button></form>
                    <form method="POST" class="ai-busy"><?php echo csrfField(); ?><input type="hidden" name="action" value="load_models"><input type="hidden" name="provider" value="<?php echo $slug; ?>">
                        <button type="submit" class="btn-app btn-sm btn-outline" data-busy="Loading…"><i class="fas fa-sync-alt"></i> Refresh models</button></form>
                    <form method="POST" data-confirm="Remove <?php echo htmlspecialchars($reg['label']); ?>?" data-confirm-text="Its API key is deleted and features using it fall back to the default." data-confirm-button="Remove">
                        <?php echo csrfField(); ?><input type="hidden" name="action" value="remove_provider"><input type="hidden" name="provider" value="<?php echo $slug; ?>">
                        <button type="submit" class="icon-btn danger" title="Remove" aria-label="Remove <?php echo htmlspecialchars($reg['label']); ?>"><i class="fas fa-trash"></i></button></form>
                </div>
                <?php endif; ?>
            </header>
            <details <?php echo ($open_provider === $slug || $e) ? 'open' : ''; ?>>
                <summary><?php echo $row ? 'Edit settings' : 'Set up ' . htmlspecialchars($reg['label']); ?></summary>
                <form method="POST" class="ai-prov-form" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_provider">
                    <input type="hidden" name="provider" value="<?php echo $slug; ?>">
                    <div class="form-group">
                        <label for="key_<?php echo $slug; ?>">API key<?php echo $reg['key'] ? ' <span class="required-mark">*</span>' : ' <span class="form-hint" style="display:inline;margin:0;">(optional)</span>'; ?></label>
                        <input type="password" id="key_<?php echo $slug; ?>" name="api_key" autocomplete="new-password" spellcheck="false"
                            placeholder="<?php echo $row && $row['key_hint'] ? 'Saved (…' . htmlspecialchars($row['key_hint']) . ') — leave empty to keep' : 'Paste the key'; ?>"
                            class="<?php echo isset($e['api_key']) ? 'is-invalid' : ''; ?>">
                        <div class="invalid-feedback"><?php echo htmlspecialchars($e['api_key'] ?? ''); ?></div>
                    </div>
                    <?php echo aiModelField('model_' . $slug, $models, (string) ($pf['model'] ?? ($row['model'] ?? '')), $e['model'] ?? '', $reg['label'], (bool) $row); ?>
                    <?php if (!aiIsLocalProvider($slug)): ?>
                    <input type="hidden" name="base_url" value="<?php echo $val('base_url'); ?>">
                    <?php else: ?>
                    <div class="form-group">
                        <label for="url_<?php echo $slug; ?>">API address <span class="form-hint" style="display:inline;margin:0;"><?php echo $reg['base'] ? 'leave empty for ' . htmlspecialchars($reg['base']) : 'required'; ?></span></label>
                        <input type="url" id="url_<?php echo $slug; ?>" name="base_url" maxlength="255" spellcheck="false"
                            value="<?php echo $val('base_url'); ?>" placeholder="<?php echo htmlspecialchars($reg['base'] ?: 'https://api.example.com/v1'); ?>"
                            class="<?php echo isset($e['base_url']) ? 'is-invalid' : ''; ?>">
                        <div class="invalid-feedback"><?php echo htmlspecialchars($e['base_url'] ?? ''); ?></div>
                    </div>
                    <?php endif; ?>
                    <label class="switch-row"><input type="checkbox" name="is_enabled" value="1" <?php echo (!$row || $row['is_enabled']) ? 'checked' : ''; ?>> <span>Enabled</span></label>
                    <div class="form-actions"><button type="submit" class="btn-app btn-sm"><i class="fas fa-save"></i> Save <?php echo htmlspecialchars($reg['label']); ?></button></div>
                </form>
            </details>
        </article>
        <?php endforeach; ?>
    </section>
</div>

<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-chart-bar"></i> Usage this month</h2></div>
    <?php if (!$by_feature): ?>
    <p class="form-hint" style="padding:0 20px 18px;">No AI calls yet this month.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th>Provider</th><th>Feature</th><th>Calls</th><th>Failed</th><th>Tokens</th></tr></thead>
            <tbody>
            <?php foreach ($by_feature as $b): ?>
            <tr><td><?php echo htmlspecialchars($registry[$b['provider']]['label'] ?? $b['provider']); ?></td>
                <td><?php echo htmlspecialchars($feature_labels[$b['feature']][0] ?? $b['feature']); ?></td>
                <td><?php echo (int) $b['calls']; ?></td><td><?php echo (int) $b['errors']; ?></td>
                <td><?php echo number_format((int) $b['tokens']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php if ($recent): ?>
    <h3 class="ai-h3">Latest calls</h3>
    <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th>When</th><th>Who</th><th>Feature</th><th>Provider · model</th><th>Tokens in / out</th><th>Time</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $c): ?>
            <tr><td class="nowrap"><?php echo timeAgo($c['created_at']); ?></td>
                <td><?php echo htmlspecialchars($c['user_name'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($feature_labels[$c['feature']][0] ?? $c['feature']); ?></td>
                <td><?php echo htmlspecialchars(($registry[$c['provider']]['label'] ?? $c['provider']) . ' · ' . $c['model']); ?></td>
                <td><?php echo number_format((int) $c['input_tokens']) . ' / ' . number_format((int) $c['output_tokens']); ?></td>
                <td class="nowrap"><?php echo $c['duration_ms'] !== null ? number_format((int) $c['duration_ms']) . ' ms' : '—'; ?></td>
                <td><?php echo $c['status'] === 'ok' ? '<span class="status-badge graded">OK</span>'
                    : '<span class="status-badge missing" title="' . htmlspecialchars((string) $c['error']) . '">Failed</span> <span class="notif-meta">' . htmlspecialchars(mb_strimwidth((string) $c['error'], 0, 80, '…')) . '</span>'; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php $reports = $db->fetchAll("SELECT r.*, u.name AS who, c.class_name FROM ai_reports r INNER JOIN users u ON r.user_id = u.id LEFT JOIN classes c ON r.class_id = c.id ORDER BY r.status = 'open' DESC, r.created_at DESC LIMIT 30"); ?>
<div class="content-card" id="reports">
    <div class="card-head"><h2><i class="fas fa-flag"></i> Reported answers <span class="form-hint" style="display:inline;margin:0 0 0 8px;"><?php echo count(array_filter($reports, fn($r) => $r['status'] === 'open')); ?> open</span></h2></div>
    <?php if (!$reports): ?><div class="card-inner"><p class="form-hint" style="margin:0;">Nobody has reported a wrong AI answer. Students and teachers see a "Report" link under AI answers.</p></div>
    <?php else: ?><div class="table-wrap"><table class="app-table"><thead><tr><th>Who</th><th>Where</th><th>The answer (start)</th><th>Their note</th><th></th></tr></thead><tbody>
        <?php foreach ($reports as $r): ?><tr>
            <td><?php echo htmlspecialchars($r['who']); ?><br><span class="form-hint" style="margin:0;"><?php echo htmlspecialchars(date('M j, g:i A', strtotime($r['created_at']))); ?></span></td>
            <td><?php echo htmlspecialchars($r['feature']); ?><?php echo $r['class_name'] ? '<br><span class="form-hint" style="margin:0;">' . htmlspecialchars($r['class_name']) . '</span>' : ''; ?></td>
            <td style="max-width:360px;overflow-wrap:anywhere;"><?php echo htmlspecialchars($r['answer']); ?></td><td style="max-width:200px;overflow-wrap:anywhere;"><?php echo htmlspecialchars((string) $r['note']); ?></td>
            <td><?php if ($r['status'] === 'open'): ?><form method="POST" style="margin:0;"><?php echo csrfField(); ?><input type="hidden" name="action" value="review_report"><input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>"><button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-check"></i> Reviewed</button></form><?php else: ?><span class="status-badge graded">Reviewed</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
