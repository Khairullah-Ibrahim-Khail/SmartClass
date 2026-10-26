<?php
/**
 * Add to the timetable (teachers): a class, one or more weekdays, start / end time, room, note.
 * A slot may not overlap another slot of the same class, or any slot of another class taught by the same
 * teacher (a teacher cannot be in two lessons at once). Inline field errors (client + server); CSRF; PRG.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/schedules/view.php');

$classes = getUserClasses($current_user_id, 'teacher');
$by_id = array_column($classes, 'class_name', 'id');
// ?id=N → edit one slot (one day); the students are told the new time
$edit = ((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) ? $db->fetchOne("SELECT s.* FROM class_schedule s INNER JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND " . teachesClassSql('c'), [(int) ($_GET['id'] ?? $_POST['id']), $current_user_id]) : null;
if (((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) && !$edit) { Session::flash('sched_msg', ['error', 'Not found', 'That slot is not in one of your classes.']); redirect(APP_URL . '/pages/schedules/view.php'); }
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$form = ['class_id' => $edit ? (int) $edit['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0), 'start' => trim((string) ($_POST['start_time'] ?? '')), 'end' => trim((string) ($_POST['end_time'] ?? '')),
         'room' => trim((string) ($_POST['room'] ?? '')), 'note' => trim((string) ($_POST['note'] ?? '')), 'days' => array_map('intval', (array) ($_POST['days'] ?? []))];
if ($edit && $_SERVER['REQUEST_METHOD'] !== 'POST') $form = ['class_id' => (int) $edit['class_id'], 'start' => substr($edit['start_time'], 0, 5), 'end' => substr($edit['end_time'], 0, 5), 'room' => (string) $edit['room'], 'note' => (string) $edit['note'], 'days' => [(int) $edit['day_of_week']]];
$errors = ['class_id' => '', 'days' => '', 'start_time' => '', 'end_time' => '', 'room' => '', 'note' => ''];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if (!isset($by_id[$form['class_id']])) $errors['class_id'] = 'Choose one of your classes';
        $picked = array_values(array_unique(array_filter($form['days'], fn($d) => isset($days[$d]))));
        if ($edit) $picked = array_slice($picked, 0, 1);   // one slot = one day
        if (!$picked) $errors['days'] = 'Choose at least one day';
        $ok_t = fn($t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok_t($form['start'])) $errors['start_time'] = 'Choose the start time';
        if (!$ok_t($form['end'])) $errors['end_time'] = 'Choose the end time';
        elseif ($ok_t($form['start']) && $form['end'] <= $form['start']) $errors['end_time'] = 'The end must be after the start';
        if (mb_strlen($form['room']) > 100) $errors['room'] = 'Room must be under 100 characters';
        if (mb_strlen($form['note']) > 200) $errors['note'] = 'Note must be under 200 characters';

        $conflict = '';
        if (!array_filter($errors)) {
            foreach ($picked as $d) {
                // any slot of a class this teacher teaches (this class included) that overlaps in time
                $hit = $db->fetchOne(
                    "SELECT s.start_time, s.end_time, c.class_name FROM class_schedule s INNER JOIN classes c ON s.class_id = c.id
                     WHERE s.day_of_week = ? AND s.start_time < ? AND s.end_time > ? AND s.id <> ? AND (s.class_id = ? OR " . teachesClassSql('c') . ") LIMIT 1",
                    [$d, $form['end'] . ':00', $form['start'] . ':00', $edit ? (int) $edit['id'] : 0, $form['class_id'], $current_user_id]);
                if ($hit) { $conflict = $days[$d] . ' overlaps ' . $hit['class_name'] . ' (' . date('g:i A', strtotime($hit['start_time'])) . ' – ' . date('g:i A', strtotime($hit['end_time'])) . ')'; break; }
            }
            if ($conflict) $errors['start_time'] = $conflict;
        }
        if (!array_filter($errors) && $edit) {
            $row = ['day_of_week' => $picked[0], 'start_time' => $form['start'] . ':00', 'end_time' => $form['end'] . ':00', 'room' => $form['room'] !== '' ? $form['room'] : null, 'note' => $form['note'] !== '' ? $form['note'] : null];
            $same = (int) $edit['day_of_week'] === $row['day_of_week'] && $edit['start_time'] === $row['start_time'] && $edit['end_time'] === $row['end_time'] && (string) $edit['room'] === (string) $row['room'] && (string) $edit['note'] === (string) $row['note'];
            $db->update('class_schedule', $row, 'id = ?', [$edit['id']]);
            logActivity('edit_schedule', 'schedules', "Edited timetable slot #{$edit['id']} of {$by_id[$form['class_id']]}");
            if (!$same) notifyClassStudents($form['class_id'], 'schedule_updated', 'Timetable changed: ' . $by_id[$form['class_id']], $days[$row['day_of_week']] . ' ' . date('g:i A', strtotime($form['start'])) . ' – ' . date('g:i A', strtotime($form['end'])) . ($form['room'] !== '' ? ' · ' . $form['room'] : ''), 'pages/schedules/view.php?class_id=' . $form['class_id'], $current_user_id);
            Session::flash('sched_msg', ['success', 'Saved', $same ? 'Nothing was different, so nobody was notified.' : 'The slot was updated and the class was told.']);
            redirect(APP_URL . '/pages/schedules/view.php?class_id=' . $form['class_id']);
        }
        if (!array_filter($errors)) {
            foreach ($picked as $d) {
                $db->insert('class_schedule', ['class_id' => $form['class_id'], 'created_by' => $current_user_id, 'day_of_week' => $d, 'start_time' => $form['start'] . ':00',
                    'end_time' => $form['end'] . ':00', 'room' => $form['room'] !== '' ? $form['room'] : null, 'note' => $form['note'] !== '' ? $form['note'] : null]);
            }
            logActivity('add_schedule', 'schedules', 'Added ' . count($picked) . " timetable slot(s) to {$by_id[$form['class_id']]}");
            Session::flash('sched_msg', ['success', 'Added', count($picked) . ' slot' . (count($picked) == 1 ? '' : 's') . ' added to the timetable.']);
            redirect(APP_URL . '/pages/schedules/view.php?class_id=' . $form['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = $edit ? 'Edit timetable slot' : 'Add to Timetable';
$page_subtitle = $edit ? 'Change the day, time or room — your class is told' : 'When does the class meet?';
$active_menu = 'schedules';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const sf = document.getElementById('schedForm');
    if (sf) { bindLiveClear(sf); sf.addEventListener('submit', function (e) {
        let ok = true;
        const need = function (id, msg) { const el = document.getElementById(id); if (!el.value) { setFieldError(el, msg); ok = false; } };
        need('class_id', 'Choose one of your classes'); need('start_time', 'Choose the start time'); need('end_time', 'Choose the end time');
        const s = document.getElementById('start_time').value, en = document.getElementById('end_time');
        if (s && en.value && en.value <= s) { setFieldError(en, 'The end must be after the start'); ok = false; }
        const dayErr = document.getElementById('daysErr');
        if (!sf.querySelector('[name=\"days[]\"]:checked')) { dayErr.textContent = 'Choose at least one day'; dayErr.classList.add('show'); ok = false; }
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Timetable</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<form method="POST" id="schedForm" class="form-card" novalidate>
    <?php echo csrfField(); ?><?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>"><input type="hidden" name="class_id" value="<?php echo (int) $edit['class_id']; ?>"><?php endif; ?>
    <div class="form-group">
        <label for="class_id">Class <span class="required-mark">*</span></label>
        <select id="class_id" name="class_id" <?php echo $edit ? 'disabled' : ''; ?> class="<?php echo $errors['class_id'] ? 'is-invalid' : ''; ?>">
            <option value="">Choose a class</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['class_id']); ?></div>
    </div>
    <div class="form-group">
        <label>Days <span class="required-mark">*</span></label>
        <div class="ann-classes">
            <?php foreach ($days as $n => $name): ?>
            <label class="material-check"><input type="<?php echo $edit ? 'radio' : 'checkbox'; ?>" name="days[]" value="<?php echo $n; ?>" <?php echo in_array($n, $form['days'], true) ? 'checked' : ''; ?>><span><?php echo $name; ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="invalid-feedback<?php echo $errors['days'] ? ' show' : ''; ?>" id="daysErr"><?php echo htmlspecialchars($errors['days']); ?></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="start_time">From <span class="required-mark">*</span></label>
            <input type="time" id="start_time" name="start_time" value="<?php echo htmlspecialchars($form['start']); ?>" class="<?php echo $errors['start_time'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['start_time']); ?></div>
        </div>
        <div class="form-group">
            <label for="end_time">To <span class="required-mark">*</span></label>
            <input type="time" id="end_time" name="end_time" value="<?php echo htmlspecialchars($form['end']); ?>" class="<?php echo $errors['end_time'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['end_time']); ?></div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="room">Room <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
            <input type="text" id="room" name="room" maxlength="100" value="<?php echo htmlspecialchars($form['room']); ?>" class="<?php echo $errors['room'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['room']); ?></div>
        </div>
        <div class="form-group">
            <label for="note">Note <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
            <input type="text" id="note" name="note" maxlength="200" placeholder="e.g. Lab session" value="<?php echo htmlspecialchars($form['note']); ?>" class="<?php echo $errors['note'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['note']); ?></div>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-<?php echo $edit ? 'save' : 'plus'; ?>"></i> <?php echo $edit ? 'Save changes' : 'Add to timetable'; ?></button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
