<?php
/**
 * Settings › At-risk limits (super admin): when a student is flagged.
 *   att_low     attendance below this percentage (default 75) — flags in Insights, People, Reports, dashboards, reminders
 *   att_streak  this many absences in a row also flag a student (default 3)
 *   low_grade   a grade below this percentage is "low" (default 50)
 *   POST save   whole numbers within range   CSRF; Post/Redirect/Get
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('limits_msg');
$rules = ['att_low' => ['Low attendance', 'Attendance below this percentage flags a student.', 1, 100, 75, '%'],
          'att_streak' => ['Absences in a row', 'This many absent class days in a row also flag a student.', 2, 30, 3, 'days'],
          'low_grade' => ['Low grade', 'An overall grade below this percentage is "low".', 1, 100, 50, '%']];
$vals = ['att_low' => attLow(), 'att_streak' => attStreak(), 'low_grade' => lowGradePct()];
$errors = array_fill_keys(array_keys($rules), '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        foreach ($rules as $k => [$label, , $min, $max]) {
            $raw = trim((string) ($_POST[$k] ?? ''));
            $vals[$k] = $raw;
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) $errors[$k] = "Enter a whole number from {$min} to {$max}";
        }
        if (!array_filter($errors)) {
            foreach ($rules as $k => $_) setSetting($k, (int) $vals[$k], $current_user_id);
            logActivity('risk_limits', 'settings', 'At-risk limits: attendance ' . (int) $vals['att_low'] . '%, ' . (int) $vals['att_streak'] . ' absences in a row, low grade ' . (int) $vals['low_grade'] . '%');
            Session::flash('limits_msg', ['success', 'Limits saved', 'Insights, People, Reports, reminders and dashboards use them now.']);
            redirect(APP_URL . '/pages/settings/limits.php');
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = 'At-risk limits';
$page_subtitle = 'When a student is flagged for attention';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Flags</h3><p>One setting for the whole school — teachers see the same limits everywhere.</p></header>
    <div class="body">
        <form method="POST" novalidate>
            <?php echo csrfField(); ?>
            <div class="mk-grid">
                <?php foreach ($rules as $k => [$label, $hint, $min, $max, $def, $unit]): ?>
                <label class="mk-part"><span><?php echo $label; ?></span>
                    <input type="number" name="<?php echo $k; ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>" step="1" value="<?php echo htmlspecialchars((string) $vals[$k]); ?>" class="<?php echo $errors[$k] ? 'is-invalid' : ''; ?>">
                    <small><?php echo $hint; ?> (default <?php echo $def; ?> <?php echo $unit; ?>)</small>
                    <?php if ($errors[$k]): ?><span class="invalid-feedback show"><?php echo htmlspecialchars($errors[$k]); ?></span><?php endif; ?></label>
                <?php endforeach; ?>
            </div>
            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save limits</button></div>
        </form>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
