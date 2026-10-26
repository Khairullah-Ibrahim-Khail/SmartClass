<?php
/**
 * Create / edit an exam (teachers of the class): ?id=N edits.
 * Class (fixed once created), title, date and time, duration, venue, max marks, instructions.
 * New exams notify the class's students. Inline field errors (client + server); CSRF; PRG.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/exams/view.php');

$editing = null;
if (!empty($_GET['id'])) {
    $editing = $db->fetchOne("SELECT e.* FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'), [(int) $_GET['id'], $current_user_id]);
    if (!$editing) redirect(APP_URL . '/pages/exams/view.php');
}
$classes = getUserClasses($current_user_id, 'teacher');
$by_id = array_column($classes, 'class_name', 'id');
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = fn($k, $d = '') => $posted ? trim((string) ($_POST[$k] ?? '')) : (string) ($editing[$k] ?? $d);
$form = ['class_id' => $editing ? (int) $editing['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0), 'title' => $val('title'), 'kind' => $val('kind', 'other'),
         'exam_date' => $posted ? $val('exam_date') : ($editing ? date('Y-m-d\TH:i', strtotime($editing['exam_date'])) : ''),
         'duration_min' => $val('duration_min'), 'venue' => $val('venue'), 'max_marks' => $val('max_marks', '100'), 'instructions' => $val('instructions')];
$errors = array_fill_keys(['class_id', 'title', 'kind', 'exam_date', 'duration_min', 'venue', 'max_marks', 'instructions'], '');
$task_result = null;

if ($posted) {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if (!isset($by_id[$form['class_id']])) $errors['class_id'] = 'Choose one of your classes';
        if ($form['title'] === '') $errors['title'] = 'Exam title is required'; elseif (mb_strlen($form['title']) > 200) $errors['title'] = 'Title must be under 200 characters';
        if (!isset(examKinds()[$form['kind']])) $errors['kind'] = 'Choose what kind of exam this is';
        $when = DateTime::createFromFormat('Y-m-d\TH:i', $form['exam_date']);
        if (!$when || $when->format('Y-m-d\TH:i') !== $form['exam_date']) $errors['exam_date'] = 'Choose a valid date and time';
        if ($form['duration_min'] !== '' && (!preg_match('/^\d+$/', $form['duration_min']) || (int) $form['duration_min'] < 5 || (int) $form['duration_min'] > 600)) $errors['duration_min'] = 'Duration must be 5 to 600 minutes (or leave it empty)';
        if (mb_strlen($form['venue']) > 200) $errors['venue'] = 'Venue must be under 200 characters';
        if (!preg_match('/^\d+$/', $form['max_marks']) || (int) $form['max_marks'] < 1 || (int) $form['max_marks'] > 1000) $errors['max_marks'] = 'Max marks must be 1 to 1000';
        elseif ($editing && (int) $form['max_marks'] < (float) ($db->fetchOne("SELECT COALESCE(MAX(marks), 0) AS m FROM exam_marks WHERE exam_id = ?", [$editing['id']])['m'])) $errors['max_marks'] = 'Some students already have more marks than that';
        if (mb_strlen($form['instructions']) > 3000) $errors['instructions'] = 'Instructions must be under 3,000 characters';
        if (!array_filter($errors)) {
            $row = ['title' => $form['title'], 'kind' => $form['kind'], 'exam_date' => $when->format('Y-m-d H:i:00'), 'duration_min' => $form['duration_min'] !== '' ? (int) $form['duration_min'] : null,
                    'venue' => $form['venue'] !== '' ? $form['venue'] : null, 'max_marks' => (int) $form['max_marks'], 'instructions' => $form['instructions'] !== '' ? $form['instructions'] : null];
            if ($editing) {
                $db->update('exams', $row, 'id = ?', [$editing['id']]);
                logActivity('edit_exam', 'exams', "Edited exam #{$editing['id']} \"{$form['title']}\"");
                Session::flash('exam_msg', ['success', 'Saved', 'The exam was updated.']);
            } else {
                $id = $db->insert('exams', $row + ['class_id' => $form['class_id'], 'created_by' => $current_user_id]);
                logActivity('create_exam', 'exams', "Created exam #{$id} \"{$form['title']}\" in {$by_id[$form['class_id']]}");
                notifyClassStudents($form['class_id'], 'exam_scheduled', 'Exam: ' . $form['title'], $when->format('D, M j · h:i A') . ($form['venue'] !== '' ? ' · ' . $form['venue'] : '') . ' — ' . $by_id[$form['class_id']],
                    'pages/exams/view.php?class_id=' . $form['class_id'], $current_user_id);
                Session::flash('exam_msg', ['success', 'Exam created', 'The class has been notified.']);
            }
            redirect(APP_URL . '/pages/exams/view.php?class_id=' . $form['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}

$page_title = $editing ? 'Edit Exam' : 'Create Exam';
$page_subtitle = $editing ? $editing['title'] : 'Schedule an exam for a class';
$active_menu = 'exams';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const xf = document.getElementById('examForm');
    if (xf) { bindLiveClear(xf); xf.addEventListener('submit', function (e) {
        let ok = true;
        const need = function (id, msg) { const el = document.getElementById(id); if (!el.disabled && !el.value.trim()) { setFieldError(el, msg); ok = false; } };
        need('class_id', 'Choose one of your classes'); need('title', 'Exam title is required'); need('exam_date', 'Choose a valid date and time');
        const mm = document.getElementById('max_marks'); if (!/^\\d+\$/.test(mm.value) || +mm.value < 1 || +mm.value > 1000) { setFieldError(mm, 'Max marks must be 1 to 1000'); ok = false; }
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> All exams</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<form method="POST" id="examForm" class="form-card" novalidate>
    <?php echo csrfField(); ?>
    <div class="form-group">
        <label for="class_id">Class <span class="required-mark">*</span></label>
        <select id="class_id" name="class_id" <?php echo $editing ? 'disabled' : ''; ?> class="<?php echo $errors['class_id'] ? 'is-invalid' : ''; ?>">
            <option value="">Choose a class</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php if ($editing): ?><input type="hidden" name="class_id" value="<?php echo (int) $form['class_id']; ?>"><?php endif; ?>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['class_id']); ?></div>
    </div>
    <div class="form-group">
        <label for="title">Title <span class="required-mark">*</span></label>
        <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Mid-term exam" value="<?php echo htmlspecialchars($form['title']); ?>" class="<?php echo $errors['title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['title']); ?></div>
    </div>
    <div class="form-group">
        <label for="kind">Counts in the final marks as</label>
        <select id="kind" name="kind" class="<?php echo $errors['kind'] ? 'is-invalid' : ''; ?>">
            <?php foreach (examKinds() as $kk => $kl): ?><option value="<?php echo $kk; ?>" <?php echo $form['kind'] === $kk ? 'selected' : ''; ?>><?php echo htmlspecialchars($kl); ?></option><?php endforeach; ?>
        </select>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['kind']); ?></div>
        <span class="form-hint">Mid exam, final exam and activities each have their own share of the total (set on the Marks page).</span>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="exam_date">Date and time <span class="required-mark">*</span></label>
            <input type="datetime-local" id="exam_date" name="exam_date" value="<?php echo htmlspecialchars($form['exam_date']); ?>" class="<?php echo $errors['exam_date'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['exam_date']); ?></div>
        </div>
        <div class="form-group">
            <label for="duration_min">Duration (minutes) <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
            <input type="number" id="duration_min" name="duration_min" min="5" max="600" value="<?php echo htmlspecialchars($form['duration_min']); ?>" class="<?php echo $errors['duration_min'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['duration_min']); ?></div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="venue">Venue <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
            <input type="text" id="venue" name="venue" maxlength="200" value="<?php echo htmlspecialchars($form['venue']); ?>" class="<?php echo $errors['venue'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['venue']); ?></div>
        </div>
        <div class="form-group">
            <label for="max_marks">Maximum marks <span class="required-mark">*</span></label>
            <input type="number" id="max_marks" name="max_marks" min="1" max="1000" value="<?php echo htmlspecialchars($form['max_marks']); ?>" class="<?php echo $errors['max_marks'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['max_marks']); ?></div>
        </div>
    </div>
    <div class="form-group">
        <label for="instructions">Instructions <span class="form-hint" style="display:inline;margin:0;">optional — what to bring, topics covered</span></label>
        <?php echo aiWriteButton('exam', '#instructions', ['class' => '#class_id', 'title' => '#title', 'use' => true, 'label' => 'Draft the instructions with AI', 'ctx' => ['kind' => '#kind', 'date' => '#exam_date', 'duration' => '#duration_min', 'venue' => '#venue', 'marks' => '#max_marks'], 'prompt' => 'Anything the AI should include? (optional)']); ?>
        <textarea id="instructions" name="instructions" rows="4" maxlength="3000" class="<?php echo $errors['instructions'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['instructions']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['instructions']); ?></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> <?php echo $editing ? 'Save changes' : 'Create exam'; ?></button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
