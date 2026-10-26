<?php
/**
 * Settings › Attendance rules (super admin): when a day locks, and the weekly days off.
 *   lock days   attendance older than N days can only be changed by the school admin (0 = never lock)
 *   days off    weekdays shown as "no class" in the calendar (e.g. Friday); they are not counted against anyone
 *   POST save   lock_days (0–3650), off_days[] (1 = Monday … 7 = Sunday)   CSRF; Post/Redirect/Get
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('att_msg');
$error = '';
$lock = attendanceLockDays();
$off = attendanceOffWeekdays();
$names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $raw = trim((string) ($_POST['lock_days'] ?? '0'));
        if ($raw === '') $raw = '0';
        $off = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['off_days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
        if (!ctype_digit($raw) || (int) $raw > 3650) {
            $error = 'Enter a whole number of days between 0 and 3650 (0 = never lock).';
            $lock = (int) preg_replace('/\D/', '', $raw);
        } else {
            $lock = (int) $raw;
            setSetting('attendance_lock_days', $lock, $current_user_id);
            setSetting('attendance_off_weekdays', implode(',', $off), $current_user_id);
            setSetting('guardian_alerts', !empty($_POST['guardian_alerts']) ? '1' : '0', $current_user_id);
            logActivity('attendance_policy', 'settings', "Attendance lock set to {$lock} days; weekly off days: " . ($off ? implode(',', $off) : 'none'));
            Session::flash('att_msg', ['success', 'Attendance rules saved', $lock ? "Days older than {$lock} can only be edited by you." : 'Days are never locked.']);
            redirect(APP_URL . '/pages/settings/attendance.php');
        }
        $task_result = ['error', 'Not saved', $error];
    }
}

$page_title = 'Attendance rules';
$page_subtitle = 'When a day locks and which weekdays are normally off';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<form method="POST" class="form-card" novalidate>
    <?php echo csrfField(); ?>
    <div class="form-group">
        <label for="lock_days">Lock attendance after <span class="form-hint" style="display:inline;margin:0;">days</span></label>
        <input type="number" id="lock_days" name="lock_days" min="0" max="3650" step="1" value="<?php echo $lock; ?>" style="max-width:140px;" class="<?php echo $error ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($error); ?></div>
        <span class="form-hint">Teachers can no longer change a day older than this; only you can. Every change is kept in the edit history. <strong>0 = never lock.</strong></span>
    </div>
    <div class="form-group">
        <label>Weekly days off</label>
        <div class="day-picks">
            <?php foreach ($names as $n => $name): ?>
            <label class="day-pick"><input type="checkbox" name="off_days[]" value="<?php echo $n; ?>" <?php echo in_array($n, $off, true) ? 'checked' : ''; ?>><span><?php echo $name; ?></span></label>
            <?php endforeach; ?>
        </div>
        <span class="form-hint">These weekdays are greyed in the attendance calendar and the "attendance not taken" reminder skips them. A single holiday can be marked on the attendance page ("Not a class day?").</span>
    </div>
    <div class="form-group">
        <label class="switch-row"><input type="checkbox" name="guardian_alerts" value="1" <?php echo guardianAlertsOn() ? 'checked' : ''; ?>> <span><strong>E-mail the parent / guardian when a student is marked absent</strong> (and when a low-attendance reminder is sent)</span></label>
        <span class="form-hint">Uses the guardian e-mail the student recorded in their profile. Needs e-mail to be set up (Settings › E-mail). Every e-mail has a "stop these e-mails" link for the guardian.<?php echo emailConfig()['enabled'] ? '' : ' <strong>E-mail is not switched on yet.</strong>'; ?></span>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save</button></div>
</form>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
