<?php
/**
 * Schedules — the weekly timetable of the user's classes (Monday to Sunday), today highlighted.
 *   ?class_id=N  one class. Teachers remove slots of their own classes here (POST + CSRF); slots are added on create.php.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can change the timetable.'];
    } else {
        $slot = $db->fetchOne("SELECT s.id FROM class_schedule s INNER JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND " . teachesClassSql('c'), [(int) ($_POST['slot_id'] ?? 0), $current_user_id]);
        if (!$slot) {
            $task_result = ['error', 'Not found', 'That slot is not in one of your classes.'];
        } else {
            $db->delete('class_schedule', 'id = ?', [$slot['id']]);
            logActivity('delete_schedule', 'schedules', "Removed timetable slot #{$slot['id']}");
            Session::flash('sched_msg', ['success', 'Removed', 'The slot was removed from the timetable.']);
            redirect(APP_URL . '/pages/schedules/view.php' . (!empty($_GET['class_id']) ? '?class_id=' . (int) $_GET['class_id'] : ''));
        }
    }
}
$task_result = Session::flash('sched_msg') ?? $task_result;

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$use_ids = $filter ? [$filter] : $class_ids;

$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$by_day = array_fill_keys(array_keys($days), []);
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    foreach ($db->fetchAll("SELECT s.*, c.class_name FROM class_schedule s INNER JOIN classes c ON s.class_id = c.id WHERE s.class_id IN ({$in}) ORDER BY s.day_of_week, s.start_time", $use_ids) as $s) {
        $by_day[(int) $s['day_of_week']][] = $s;
    }
}
$total = array_sum(array_map('count', $by_day));
$today = (int) date('N');
$now = date('H:i:s');

$page_title = 'Schedules';
$page_subtitle = isTeacher() ? 'The weekly timetable of your classes' : 'When your classes meet';
$active_menu = 'schedules';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function () { f.form.submit(); });";
$page_scripts = isTeacher() ? ['assets/js/class.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form">
        <?php if ($classes): ?>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
    </form>
    <?php if (isTeacher() && $classes): ?><a href="create.php<?php echo $filter ? '?class_id=' . $filter : ''; ?>" class="btn-app"><i class="fas fa-plus"></i> Add to timetable</a><?php endif; ?>
</div>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-calendar-alt"></i><h3>No classes yet</h3></div>
<?php elseif (!$total): ?>
<div class="empty-state"><i class="fas fa-calendar-alt"></i><h3>The timetable is empty</h3><p style="margin-top:6px;"><?php echo isTeacher() ? 'Add when each class meets.' : 'Your teachers have not added lesson times yet.'; ?></p></div>
<?php else: ?>
<div class="timetable">
    <?php foreach ($days as $n => $name): ?>
    <section class="tt-day<?php echo $n === $today ? ' is-today' : ''; ?>" aria-label="<?php echo $name; ?>">
        <h3><?php echo $name; ?><?php echo $n === $today ? ' <span class="status-badge graded">Today</span>' : ''; ?></h3>
        <?php if (!$by_day[$n]): ?><p class="form-hint">Nothing scheduled</p><?php endif; ?>
        <?php foreach ($by_day[$n] as $s):
            $live = $n === $today && $now >= $s['start_time'] && $now < $s['end_time']; ?>
        <div class="tt-slot<?php echo $live ? ' is-live' : ''; ?>">
            <div class="tt-time"><?php echo date('g:i A', strtotime($s['start_time'])); ?> – <?php echo date('g:i A', strtotime($s['end_time'])); ?><?php echo $live ? ' <span class="status-badge late">Now</span>' : ''; ?></div>
            <div class="tt-class"><a href="<?php echo APP_URL; ?>/pages/classes/stream.php?id=<?php echo (int) $s['class_id']; ?>"><?php echo htmlspecialchars($s['class_name']); ?></a></div>
            <?php if ($s['room'] || $s['note']): ?><div class="form-hint" style="margin:2px 0 0;"><?php echo $s['room'] ? '<i class="fas fa-door-open"></i> ' . htmlspecialchars($s['room']) : ''; ?><?php echo $s['room'] && $s['note'] ? ' · ' : ''; ?><?php echo htmlspecialchars((string) $s['note']); ?></div><?php endif; ?>
            <?php if (isTeacher()): ?>
            <a class="icon-btn tt-edit" href="create.php?id=<?php echo (int) $s['id']; ?>" title="Edit slot" aria-label="Edit slot"><i class="fas fa-pen"></i></a>
            <form method="POST" class="tt-del" data-confirm="Remove this slot?" data-confirm-text="<?php echo htmlspecialchars($s['class_name'] . ', ' . $name . ' ' . date('g:i A', strtotime($s['start_time']))); ?>" data-confirm-button="Remove">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="slot_id" value="<?php echo (int) $s['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Remove slot" aria-label="Remove slot"><i class="fas fa-times"></i></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
