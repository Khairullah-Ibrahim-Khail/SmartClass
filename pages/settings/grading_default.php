<?php
/**
 * School default grading scale (super admin) — Settings › School default grading.
 * Classes that have no scale of their own use this one; each class teacher can set their own (Settings › Grading scale).
 * Each school decides how a percentage is described: ratings, letters (A+, A, B …) and an optional GPA.
 * Used on the Grades tab, the grade PDFs and the student report card. Marks are never changed — only labelled.
 *   POST save   bands[i][label|min|gpa|tone], show_gpa  (validated by gradingValidate(); CSRF; Post/Redirect/Get)
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('grading_msg');
$error = '';
$cur = gradingSchoolScale();
$rows = $cur['bands'];
$show_gpa = $cur['show_gpa'];
$basis = $cur['basis'];
$round = $cur['round'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $posted = is_array($_POST['bands'] ?? null) ? array_values($_POST['bands']) : [];
        $show_gpa = !empty($_POST['show_gpa']);
        $basis = ($_POST['basis'] ?? '') === 'all' ? 'all' : 'graded';
        $round = isset(gradingRoundModes()[$_POST['round'] ?? '']) ? $_POST['round'] : 'nearest';
        [$clean, $error] = gradingValidate($posted);
        $rows = $error ? array_map(fn($r) => ['label' => (string) ($r['label'] ?? ''), 'min' => (string) ($r['min'] ?? ''), 'gpa' => (string) ($r['gpa'] ?? ''), 'gpa_max' => (string) ($r['gpa_max'] ?? ''), 'max' => (string) ($r['max'] ?? ''), 'min_op' => (string) ($r['min_op'] ?? 'gte'), 'max_op' => (string) ($r['max_op'] ?? 'lte'), 'tone' => 'mid', 'color' => (string) ($r['color'] ?? '#e9a23b')], $posted) : $clean;
        if (!$error) {
            setSetting('grading_scale', json_encode(['show_gpa' => $show_gpa, 'basis' => $basis, 'round' => $round, 'bands' => $clean]), $current_user_id);
            logActivity('grading_scale', 'settings', 'Saved the grading scale (' . count($clean) . ' bands' . ($show_gpa ? ', with GPA' : '') . ')');
            Session::flash('grading_msg', ['success', 'Grading scale saved', 'Grades, reports and report cards now use it.']);
            redirect(APP_URL . '/pages/settings/grading_default.php');
        }
        $task_result = ['error', 'Not saved', $error];
    }
}

$page_title = 'School default grading';
$page_subtitle = 'Used by every class that has no grading scale of its own';
$active_menu = 'settings';
$settings_page = true;
$page_scripts[] = 'assets/js/grading-settings.js';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<form method="POST" id="gradingForm" class="form-card grading-form one-column" novalidate data-presets="<?php echo gradingPresetsAttr(); ?>">
    <?php echo csrfField(); ?>
    <p class="form-hint" style="margin-top:0;">This is the <strong>school's default</strong>. Each class teacher can set their own scale for their class (Settings › Grading scale); classes without one use this.</p>
    <p><a class="link-btn" href="<?php echo htmlspecialchars(backUrl(APP_URL . '/pages/settings/grading.php')); ?>"><i class="fas fa-arrow-left"></i> Back to grading scales</a></p>
    <?php echo gradingEditorHtml(['show_gpa' => $show_gpa, 'basis' => $basis, 'round' => $round, 'bands' => $rows], $error); ?>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save school default</button></div>
</form>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
