<?php
/**
 * New event (teachers): class, title, details, place, start (and end). The class's students are notified.
 * Inline field errors (client + server); CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/events/view.php');

$classes = getUserClasses($current_user_id, 'teacher');
$by_id = array_column($classes, 'class_name', 'id');
// ?id=N → edit one of your events (the class stays; the students are told what changed)
$edit = ((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) ? $db->fetchOne("SELECT e.* FROM class_events e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'), [(int) ($_GET['id'] ?? $_POST['id']), $current_user_id]) : null;
if (((int) ($_GET['id'] ?? $_POST['id'] ?? 0)) && !$edit) { Session::flash('event_msg', ['error', 'Not found', 'That event is not in one of your classes.']); redirect(APP_URL . '/pages/events/view.php'); }
$fmt = fn($d) => $d ? date('Y-m-d\TH:i', strtotime($d)) : '';
$form = ['class_id' => $edit ? (int) $edit['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0), 'title' => trim((string) ($_POST['title'] ?? '')), 'description' => trim((string) ($_POST['description'] ?? '')),
         'location' => trim((string) ($_POST['location'] ?? '')), 'starts_at' => trim((string) ($_POST['starts_at'] ?? '')), 'ends_at' => trim((string) ($_POST['ends_at'] ?? ''))];
if ($edit && $_SERVER['REQUEST_METHOD'] !== 'POST') $form = ['class_id' => (int) $edit['class_id'], 'title' => $edit['title'], 'description' => (string) $edit['description'], 'location' => (string) $edit['location'], 'starts_at' => $fmt($edit['starts_at']), 'ends_at' => $fmt($edit['ends_at'])];
$errors = ['class_id' => '', 'title' => '', 'description' => '', 'location' => '', 'starts_at' => '', 'ends_at' => ''];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if (!isset($by_id[$form['class_id']])) $errors['class_id'] = 'Choose one of your classes';
        if ($form['title'] === '') $errors['title'] = 'Event title is required'; elseif (mb_strlen($form['title']) > 200) $errors['title'] = 'Title must be under 200 characters';
        if (mb_strlen($form['description']) > 3000) $errors['description'] = 'Details must be under 3,000 characters';
        if (mb_strlen($form['location']) > 200) $errors['location'] = 'Place must be under 200 characters';
        $start = DateTime::createFromFormat('Y-m-d\TH:i', $form['starts_at']);
        if (!$start || $start->format('Y-m-d\TH:i') !== $form['starts_at']) $errors['starts_at'] = 'Choose a valid start date and time';
        $end = null;
        if ($form['ends_at'] !== '') {
            $end = DateTime::createFromFormat('Y-m-d\TH:i', $form['ends_at']);
            if (!$end || $end->format('Y-m-d\TH:i') !== $form['ends_at']) $errors['ends_at'] = 'Choose a valid end date and time';
            elseif ($start && !$errors['starts_at'] && $end <= $start) $errors['ends_at'] = 'The end must be after the start';
        }
        if (!array_filter($errors)) {
            $row = ['title' => $form['title'], 'description' => $form['description'] !== '' ? $form['description'] : null, 'location' => $form['location'] !== '' ? $form['location'] : null,
                    'starts_at' => $start->format('Y-m-d H:i:00'), 'ends_at' => $end ? $end->format('Y-m-d H:i:00') : null];
            if ($edit) {
                $changes = [];
                if ($edit['title'] !== $row['title']) $changes[] = 'title';
                if ($edit['starts_at'] !== $row['starts_at'] || (string) $edit['ends_at'] !== (string) $row['ends_at']) $changes[] = 'time';
                if ((string) $edit['location'] !== (string) $row['location']) $changes[] = 'place';
                if ((string) $edit['description'] !== (string) $row['description']) $changes[] = 'details';
                $db->update('class_events', $row, 'id = ?', [$edit['id']]);
                logActivity('edit_event', 'events', "Edited event #{$edit['id']} \"{$form['title']}\" (" . ($changes ? implode(', ', $changes) : 'no change') . ')');
                if ($changes) notifyClassStudents($form['class_id'], 'event_updated', 'Event updated: ' . $form['title'], 'Changed: ' . implode(', ', $changes) . ' — ' . $start->format('D, M j · h:i A') . ($form['location'] !== '' ? ' · ' . $form['location'] : ''), 'pages/events/view.php?class_id=' . $form['class_id'], $current_user_id);
                Session::flash('event_msg', ['success', 'Event updated', $changes ? 'The class was told what changed.' : 'Nothing was different, so nobody was notified.']);
                redirect(APP_URL . '/pages/events/view.php?class_id=' . $form['class_id']);
            }
            $id = $db->insert('class_events', $row + ['class_id' => $form['class_id'], 'created_by' => $current_user_id]);
            logActivity('create_event', 'events', "Created event #{$id} \"{$form['title']}\" in {$by_id[$form['class_id']]}");
            notifyClassStudents($form['class_id'], 'event_created', 'New event: ' . $form['title'],
                $start->format('D, M j · h:i A') . ($form['location'] !== '' ? ' · ' . $form['location'] : '') . ' — ' . $by_id[$form['class_id']], 'pages/events/view.php?class_id=' . $form['class_id'], $current_user_id);
            Session::flash('event_msg', ['success', 'Event created', 'The class has been notified.']);
            redirect(APP_URL . '/pages/events/view.php?class_id=' . $form['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = $edit ? 'Edit event' : 'New Event';
$page_subtitle = $edit ? 'Change the details — your class is told what changed' : 'Add something to your class calendar';
$active_menu = 'events';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const ef = document.getElementById('eventForm');
    if (ef) { bindLiveClear(ef); ef.addEventListener('submit', function (e) {
        let ok = true;
        const need = function (id, msg) { const el = document.getElementById(id); if (!el.value.trim()) { setFieldError(el, msg); ok = false; } };
        need('class_id', 'Choose one of your classes'); need('title', 'Event title is required'); need('starts_at', 'Choose a valid start date and time');
        const s = document.getElementById('starts_at').value, en = document.getElementById('ends_at');
        if (s && en.value && en.value <= s) { setFieldError(en, 'The end must be after the start'); ok = false; }
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> All events</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<form method="POST" id="eventForm" class="form-card" novalidate>
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
        <label for="title">Title <span class="required-mark">*</span></label>
        <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Science fair" value="<?php echo htmlspecialchars($form['title']); ?>" class="<?php echo $errors['title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['title']); ?></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="starts_at">Starts <span class="required-mark">*</span></label>
            <input type="datetime-local" id="starts_at" name="starts_at" value="<?php echo htmlspecialchars($form['starts_at']); ?>" class="<?php echo $errors['starts_at'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['starts_at']); ?></div>
        </div>
        <div class="form-group">
            <label for="ends_at">Ends <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
            <input type="datetime-local" id="ends_at" name="ends_at" value="<?php echo htmlspecialchars($form['ends_at']); ?>" class="<?php echo $errors['ends_at'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['ends_at']); ?></div>
        </div>
    </div>
    <div class="form-group">
        <label for="location">Place <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <input type="text" id="location" name="location" maxlength="200" placeholder="Room, hall or link" value="<?php echo htmlspecialchars($form['location']); ?>" class="<?php echo $errors['location'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['location']); ?></div>
    </div>
    <div class="form-group">
        <label for="description">Details <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <?php echo aiWriteButton('event', '#description', ['class' => '#class_id', 'title' => '#title', 'use' => true, 'ctx' => ['starts' => '#starts_at', 'ends' => '#ends_at', 'location' => '#location']]); ?>
        <textarea id="description" name="description" rows="4" maxlength="3000" class="<?php echo $errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['description']); ?></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-calendar-plus"></i> <?php echo $edit ? 'Save changes' : 'Create event'; ?></button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
