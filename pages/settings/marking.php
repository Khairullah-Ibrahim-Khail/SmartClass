<?php
/**
 * Settings › Marking scheme — how many marks each part of the final total is worth, per class (teachers of the class).
 * Mid exam, Final exam, Activities, Quizzes, Assignments (0 = the class does not have that part). Used by the Marks page,
 * the final-marks PDFs and the reports. Overview of all the teacher's classes + an editor for the chosen one.
 *   POST save   class_id, weights[mid|final|activity|quiz|assignment], apply_all (also my other classes)   CSRF; PRG
 *   POST reset  class_id — back to the default 20 / 40 / 10 / 15 / 15
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/settings/index.php');

$classes = getUserClasses($current_user_id, 'teacher');
$class_ids = array_map('intval', array_column($classes, 'id'));
$sel = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
if (!in_array($sel, $class_ids, true)) $sel = $class_ids[0] ?? 0;
$comps = markingComponents();
$fmt = fn($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
$task_result = Session::flash('marking_msg');
$error = '';
$weights = $sel ? markingScheme($sel) : markingDefaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sel) {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'reset') {
        $db->delete('class_marking_scheme', 'class_id = ?', [$sel]);
        logActivity('marking_scheme', 'settings', "Marking scheme of class #{$sel} back to the default");
        Session::flash('marking_msg', ['success', 'Default scheme', 'This class uses 20 / 40 / 10 / 15 / 15 again.']);
        redirect(APP_URL . '/pages/settings/marking.php?class_id=' . $sel);
    } elseif ($action === 'save') {
        $in = is_array($_POST['weights'] ?? null) ? $_POST['weights'] : [];
        $new = [];
        foreach ($comps as $k => $label) {
            $v = trim((string) ($in[$k] ?? '0'));
            if ($v === '') $v = '0';
            if (!is_numeric($v) || $v < 0 || $v > 1000) { $error = "\"{$label}\": enter marks between 0 and 1000 (0 = not used)."; break; }
            $new[$k] = round((float) $v, 2);
        }
        if (!$error && array_sum($new) <= 0) $error = 'At least one part must be worth some marks.';
        $weights = $error ? array_map('floatval', $in) + $weights : $new;
        if (!$error) {
            $targets = !empty($_POST['apply_all']) ? $class_ids : [$sel];
            foreach ($targets as $tid) foreach ($new as $k => $v) {
                $db->query("INSERT INTO class_marking_scheme (class_id, component, weight) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE weight = VALUES(weight)", [$tid, $k, $v]);
            }
            logActivity('marking_scheme', 'settings', 'Saved the marking scheme of ' . count($targets) . ' class(es): ' . json_encode($new));
            Session::flash('marking_msg', ['success', 'Marking scheme saved', 'Total ' . $fmt(array_sum($new)) . ($targets === [$sel] ? '' : ' — applied to ' . count($targets) . ' of your classes') . '.']);
            redirect(APP_URL . '/pages/settings/marking.php?class_id=' . $sel);
        }
        $task_result = ['error', 'Not saved', $error];
    }
}

$page_title = 'Marking scheme';
$page_subtitle = 'How many marks each part of the final total is worth';
$active_menu = 'settings';
$settings_page = true;
$page_scripts[] = 'assets/js/marking-settings.js';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-sliders-h"></i> Edit the scheme</h2>
        <form method="GET" class="filter-form"><select name="class_id" id="markClass" data-autosubmit aria-label="Class">
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $sel === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select><noscript><button type="submit" class="btn-app btn-sm">Open</button></noscript></form></div>
    <form method="POST" class="mk-form" id="markingForm" novalidate>
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="class_id" value="<?php echo $sel; ?>">
        <div class="mk-grid">
            <?php foreach ($comps as $k => $label): ?>
            <label class="mk-part"><span><?php echo $label; ?></span>
                <input type="number" name="weights[<?php echo $k; ?>]" min="0" max="1000" step="0.5" value="<?php echo $fmt($weights[$k] ?? 0); ?>" data-weight>
                <small><?php echo ['mid' => 'Exams marked "Mid exam"', 'final' => 'Exams marked "Final exam"', 'activity' => 'Practicals, oral work, participation', 'quiz' => 'All quizzes of the class', 'assignment' => 'All assignments of the class'][$k]; ?></small></label>
            <?php endforeach; ?>
        </div>
        <div class="mk-total">Total: <strong id="mkTotal"><?php echo $fmt(array_sum($weights)); ?></strong> <span class="form-hint" style="margin:0;">marks — students' final marks are out of this</span></div>
        <?php if (count($classes) > 1): ?><label class="switch-row"><input type="checkbox" name="apply_all" value="1"> <span>Use this scheme for all my other classes too</span></label><?php endif; ?>
        <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn-app"><i class="fas fa-save"></i> Save marking scheme</button>
            <a class="btn-app btn-outline" href="<?php echo APP_URL; ?>/pages/marks/view.php?class_id=<?php echo $sel; ?>">See the marks</a>
        </div>
    </form>
    <form method="POST" data-confirm="Use the default scheme?" data-confirm-text="20 / 40 / 10 / 15 / 15 for this class." data-confirm-button="Use default" style="padding:0 20px 16px;">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="reset"><input type="hidden" name="class_id" value="<?php echo $sel; ?>">
        <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-undo"></i> Use the default instead</button>
    </form>
</section>

<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-table"></i> All your classes</h2></div>
    <div class="table-wrap"><table class="app-table mk-table">
        <thead><tr><th>Class</th><?php foreach ($comps as $label): ?><th><?php echo $label; ?></th><?php endforeach; ?><th>Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): $w = markingScheme((int) $c['id']); ?>
            <tr<?php echo (int) $c['id'] === $sel ? ' class="is-sel"' : ''; ?>>
                <td><strong><?php echo htmlspecialchars($c['class_name']); ?></strong></td>
                <?php foreach ($comps as $k => $_): ?><td><?php echo $w[$k] > 0 ? $fmt($w[$k]) : '—'; ?></td><?php endforeach; ?>
                <td><strong><?php echo $fmt(array_sum($w)); ?></strong></td>
                <td style="text-align:right;"><a class="btn-app btn-sm btn-outline" href="?class_id=<?php echo (int) $c['id']; ?>">Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
