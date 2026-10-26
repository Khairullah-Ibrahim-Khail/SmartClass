<?php
/**
 * Settings › Grading scale › one class: ?class_id=N (teachers of the class).
 * The class teacher decides how marks become grades in THIS class: names (A+, A, B …), where each starts, colours,
 * GPA on / off, and whether the grade follows marked work or all work. Used by the Grades tab, grade PDFs, Final marks,
 * Insights. "Use the school default" removes the class's own scale.
 *   POST save    bands[i][label|min|gpa|tone], show_gpa, basis, apply_all (also my other classes)
 *   POST reset   back to the school default
 * CSRF; Post/Redirect/Get; the scale is validated by gradingValidate().
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/settings/profile.php');

$class_id = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$class = getAccessibleClass($class_id, $current_user_id, $current_user_role);
if (!$class || empty($class['is_owner'])) redirect(APP_URL . '/pages/settings/grading.php');
gradingUseClass($class_id);
$self = APP_URL . '/pages/settings/grading_class.php?class_id=' . $class_id;

$task_result = Session::flash('class_msg');
$error = '';
$own = gradingClassScale($class_id);
$cur = $own ?? gradingSchoolScale();
$rows = $cur['bands'];
$show_gpa = $cur['show_gpa'];
$basis = $cur['basis'];
$round = $cur['round'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'reset') {
        $db->delete('class_grading_scales', 'class_id = ?', [$class_id]);
        logActivity('class_grading_scale', 'classes', "Class {$class['class_name']} now uses the school's grading scale");
        Session::flash('class_msg', ['success', 'Back to the school scale', 'This class follows the school default again.']);
        redirect($self);
    } elseif ($action === 'save') {
        $posted = is_array($_POST['bands'] ?? null) ? array_values($_POST['bands']) : [];
        $show_gpa = !empty($_POST['show_gpa']);
        $basis = ($_POST['basis'] ?? '') === 'all' ? 'all' : 'graded';
        $round = isset(gradingRoundModes()[$_POST['round'] ?? '']) ? $_POST['round'] : 'nearest';
        [$clean, $error] = gradingValidate($posted);
        $rows = $error ? array_map(fn($r) => ['label' => (string) ($r['label'] ?? ''), 'min' => (string) ($r['min'] ?? ''), 'gpa' => (string) ($r['gpa'] ?? ''), 'gpa_max' => (string) ($r['gpa_max'] ?? ''), 'max' => (string) ($r['max'] ?? ''), 'min_op' => (string) ($r['min_op'] ?? 'gte'), 'max_op' => (string) ($r['max_op'] ?? 'lte'), 'tone' => 'mid', 'color' => (string) ($r['color'] ?? '#e9a23b')], $posted) : $clean;
        if (!$error) {
            $json = json_encode(['show_gpa' => $show_gpa, 'basis' => $basis, 'round' => $round, 'bands' => $clean]);
            $targets = [$class_id];
            if (!empty($_POST['apply_all'])) $targets = array_map('intval', array_column(getUserClasses($current_user_id, 'teacher'), 'id'));
            foreach ($targets as $tid) {
                $db->query("INSERT INTO class_grading_scales (class_id, scale, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE scale = VALUES(scale), updated_by = VALUES(updated_by)", [$tid, $json, $current_user_id]);
            }
            logActivity('class_grading_scale', 'classes', "Saved the grading scale of {$class['class_name']} (" . count($clean) . ' bands' . ($show_gpa ? ', with GPA' : '') . ')' . (count($targets) > 1 ? ' and ' . (count($targets) - 1) . ' other class(es)' : ''));
            Session::flash('class_msg', ['success', 'Grading scale saved', count($targets) > 1 ? 'Applied to ' . count($targets) . ' of your classes.' : 'Grades and reports of this class now use it.']);
            redirect($self);
        }
        $task_result = ['error', 'Not saved', $error];
    }
}

$page_title = 'Grading scale — ' . $class['class_name'];
$page_subtitle = 'Grades, ranges, colours and GPA for this class';
$active_menu = 'settings';
$settings_page = true;
$settings_active = 'pages/settings/grading.php';   // the navigation highlights the grading hub
$page_scripts[] = 'assets/js/grading-settings.js';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-award"></i> Grading scale of <?php echo htmlspecialchars($class['class_name']); ?></h2>
        <span class="status-badge <?php echo $own ? 'graded' : 'pending'; ?>"><?php echo $own ? 'Set by you' : 'Using the school default'; ?></span></div>
    <form method="POST" id="gradingForm" class="grading-form one-column" novalidate data-presets="<?php echo gradingPresetsAttr(); ?>" data-total="<?php echo (float) array_sum(markingScheme($class_id)); ?>" style="padding:18px 20px;">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
        <p class="form-hint" style="margin-top:0;">You decide how marks become grades in this class — for example A+, A, B, C, D, F with the percentage each starts at.</p>
        <?php echo gradingEditorHtml(['show_gpa' => $show_gpa, 'basis' => $basis, 'round' => $round, 'bands' => $rows], $error, array_sum(markingScheme($class_id))); ?>
        <?php if (count(getUserClasses($current_user_id, 'teacher')) > 1): ?>
        <label class="switch-row"><input type="checkbox" name="apply_all" value="1"> <span>Also use this scale for all my other classes</span></label>
        <?php endif; ?>
        <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
            <button type="submit" class="btn-app"><i class="fas fa-save"></i> Save grading scale</button>
            <a class="btn-app btn-outline" href="<?php echo APP_URL; ?>/pages/settings/grading.php">All grading scales</a>
        </div>
    </form>
    <?php if ($own): ?>
    <form method="POST" data-confirm="Use the school's grading scale?" data-confirm-text="This class's own scale is removed; grades are described by the school default again." data-confirm-button="Use school default" style="padding:0 20px 18px;">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="reset"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
        <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-undo"></i> Use the school default instead</button>
    </form>
    <?php endif; ?>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
