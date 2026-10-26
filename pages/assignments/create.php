<?php
/**
 * Create / Edit Assignment (teacher only)
 *   create.php?class_id=N  new assignment (students are notified)
 *   create.php?id=N        edit an assignment in one of the teacher's classes (class stays fixed;
 *                          an unchanged past due date is kept; max marks never below a mark already given)
 * Title, instructions, due date and max marks for one of the teacher's classes.
 * Inline field errors; SweetAlert2 for the task result.
 * Rubric (optional): criteria whose points add up to Max Marks; "Suggest with AI" drafts it from the
 *   title + instructions (pages/assignments/rubric_ai.php) for the teacher to edit. Students see it.
 * Materials: up to MAX_ASSIGNMENT_LINKS http(s) links + any resources of the same class
 *   (checkbox list follows the selected class; validated server-side by validateAssignmentMaterials()).
 * Notifies every student in the class + writes the audit log.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/assignments/view.php');
}

$classes = getUserClasses($current_user_id, 'teacher');

// Edit mode: the assignment must be in one of this teacher's classes
$editing = null;
$highest_mark = 0.0;
if (!empty($_GET['id'])) {
    $editing = $db->fetchOne(
        "SELECT a.* FROM assignments a INNER JOIN classes c ON a.class_id = c.id
         WHERE a.id = ? AND " . teachesClassSql('c') . "", [(int) $_GET['id'], $current_user_id]);
    if (!$editing) {
        redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
    }
    $highest_mark = (float) ($db->fetchOne("SELECT MAX(marks_obtained) AS m FROM submissions WHERE assignment_id = ?", [$editing['id']])['m'] ?? 0);
}

$field_errors = ['class_id' => '', 'title' => '', 'description' => '', 'due_date' => '', 'max_marks' => ''];
$csrf_error = false;
$created = null;

$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$old_due = $editing && $editing['due_date'] ? date('Y-m-d\TH:i', strtotime($editing['due_date'])) : '';
$form = [
    'class_id'    => $editing ? (int) $editing['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0),
    'title'       => trim($is_post || !$editing ? ($_POST['title'] ?? '') : $editing['title']),
    'description' => trim($is_post || !$editing ? ($_POST['description'] ?? '') : (string) $editing['description']),
    'due_date'    => trim($is_post || !$editing ? ($_POST['due_date'] ?? '') : $old_due),
    'max_marks'   => trim($is_post || !$editing ? ($_POST['max_marks'] ?? '100') : (string) $editing['max_marks']),
];
$form_topic = $is_post ? (int) ($_POST['topic_id'] ?? 0) : (int) ($editing['topic_id'] ?? 0);

// Rubric rows the form shows (posted values, or the saved rubric when editing)
$can_ai = aiAvailableFor('grading', $current_user_id);
$rubric_errors = [];
if ($is_post) {
    $form_rubric = [];
    foreach (array_slice((array) ($_POST['rubric_title'] ?? []), 0, RUBRIC_MAX_CRITERIA + 2, true) as $i => $t) {
        $form_rubric[] = ['title' => trim((string) $t), 'description' => trim((string) (((array) ($_POST['rubric_desc'] ?? []))[$i] ?? '')),
                          'points' => trim((string) (((array) ($_POST['rubric_points'] ?? []))[$i] ?? ''))];
    }
} else {
    $form_rubric = array_map(fn($c) => ['title' => $c['title'], 'description' => $c['description'], 'points' => (string) $c['points']], assignmentRubric($editing));
}
while (count($form_rubric) < 3) $form_rubric[] = ['title' => '', 'description' => '', 'points' => ''];

// Materials: what the form shows (posted values, or the saved ones when editing)
$link_errors = [];
if ($is_post) {
    $form_links = [];
    foreach (array_slice((array) ($_POST['link_url'] ?? []), 0, MAX_ASSIGNMENT_LINKS) as $i => $u) {
        $form_links[] = ['url' => trim((string) $u), 'title' => trim((string) (((array) ($_POST['link_title'] ?? []))[$i] ?? ''))];
    }
    $form_resources = array_map('intval', (array) ($_POST['resource_ids'] ?? []));
} else {
    $saved = $editing ? getAssignmentMaterials($editing['id']) : [];
    $form_links = array_map(fn($m) => ['url' => $m['url'], 'title' => (string) $m['title']], array_values(array_filter($saved, fn($m) => $m['kind'] === 'link')));
    $form_resources = array_map('intval', array_column(array_filter($saved, fn($m) => $m['kind'] === 'resource'), 'resource_id'));
}
while (count($form_links) < 3 && count($form_links) < MAX_ASSIGNMENT_LINKS) $form_links[] = ['url' => '', 'title' => ''];
$class_ids = array_map('intval', array_column($classes, 'id'));
$all_resources = [];
if ($class_ids) {
    $in = implode(',', array_fill(0, count($class_ids), '?'));
    $all_resources = $db->fetchAll("SELECT id, class_id, title, file_type FROM resources WHERE class_id IN ({$in}) ORDER BY title", $class_ids);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $csrf_error = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Server-side ownership check: the class must belong to this teacher
    $class = getAccessibleClass($form['class_id'], $current_user_id, 'teacher');
    if (!$class) {
        $field_errors['class_id'] = 'Please select one of your classes';
    }

    if ($form['title'] === '') {
        $field_errors['title'] = 'Title is required';
    } elseif (mb_strlen($form['title']) > 200) {
        $field_errors['title'] = 'Title must be under 200 characters';
    }

    if ($form['description'] === '') {
        $field_errors['description'] = 'Instructions are required';
    } elseif (mb_strlen($form['description']) > 20000) {
        $field_errors['description'] = 'Instructions are too long (max 20,000 characters)';
    }

    // <input type="datetime-local"> sends "2026-10-25T23:59"
    $due = DateTime::createFromFormat('Y-m-d\TH:i', $form['due_date']);
    if ($form['due_date'] === '') {
        $field_errors['due_date'] = 'Due date is required';
    } elseif (!$due || $due->format('Y-m-d\TH:i') !== $form['due_date']) {
        $field_errors['due_date'] = 'Please enter a valid date and time';
    } elseif ($due->getTimestamp() <= time() && !($editing && $form['due_date'] === $old_due)) {
        // a new due date must be in the future; an unchanged one is kept as it is
        $field_errors['due_date'] = 'Due date must be in the future';
    }

    if (!preg_match('/^\d+$/', $form['max_marks']) || (int) $form['max_marks'] < 1 || (int) $form['max_marks'] > 1000) {
        $field_errors['max_marks'] = 'Max marks must be a whole number from 1 to 1000';
    } elseif ($editing && (int) $form['max_marks'] < $highest_mark) {
        $field_errors['max_marks'] = 'Max marks cannot be lower than a mark already given (' . (0 + $highest_mark) . ')';
    }

    [$rubric, $rubric_errors] = parseRubricInput($_POST['rubric_title'] ?? [], $_POST['rubric_desc'] ?? [], $_POST['rubric_points'] ?? [],
        preg_match('/^\d+$/', $form['max_marks']) ? (int) $form['max_marks'] : 0);
    [$mat_links, $mat_resources, $link_errors] = validateAssignmentMaterials($class['id'] ?? 0, $_POST['link_url'] ?? [], $_POST['link_title'] ?? [], $_POST['resource_ids'] ?? []);

    if (!array_filter($field_errors) && !$link_errors && !$rubric_errors && $editing) {
        $db->update('assignments', [
            'topic_id'    => validTopicId($_POST['topic_id'] ?? 0, $class['id']),
            'title'       => $form['title'],
            'description' => $form['description'],
            'due_date'    => $due->format('Y-m-d H:i:00'),
            'max_marks'   => (int) $form['max_marks'],
            'rubric'      => $rubric ? json_encode($rubric, JSON_UNESCAPED_UNICODE) : null,
            'release_mode' => postedReleaseMode(),
        ], 'id = ?', [$editing['id']]);
        saveAssignmentMaterials($editing['id'], $mat_links, $mat_resources);
        logActivity('edit_assignment', 'assignments', "Edited assignment #{$editing['id']} \"{$form['title']}\" in {$class['class_name']}");
        if ($form['due_date'] !== $old_due) {
            notifyClassStudents($class['id'], 'assignment_created', 'Due date changed: ' . $form['title'],
                'Now due ' . $due->format('M d, Y h:i A') . " in {$class['class_name']}.",
                'pages/assignments/view.php?id=' . $editing['id'], $current_user_id);
        }
        Session::flash('assignment_msg', ['success', 'Assignment updated', $form['due_date'] !== $old_due ? 'Students were told about the new due date.' : 'Your changes are live.']);
        redirect(APP_URL . '/pages/assignments/view.php?id=' . $editing['id']);
    } elseif (!array_filter($field_errors) && !$link_errors && !$rubric_errors) {
        $assignment_id = $db->insert('assignments', [
            'topic_id'    => validTopicId($_POST['topic_id'] ?? 0, $class['id']),
            'class_id'    => $class['id'],
            'teacher_id'  => $current_user_id,
            'title'       => $form['title'],
            'description' => $form['description'],
            'due_date'    => $due->format('Y-m-d H:i:00'),
            'max_marks'   => (int) $form['max_marks'],
            'rubric'      => $rubric ? json_encode($rubric, JSON_UNESCAPED_UNICODE) : null,
            'release_mode' => postedReleaseMode(),
        ]);

        saveAssignmentMaterials($assignment_id, $mat_links, $mat_resources);
        logActivity('create_assignment', 'assignments', "Created assignment #{$assignment_id} \"{$form['title']}\" in {$class['class_name']}");
        notifyClassStudents(
            $class['id'],
            'assignment_created',
            'New assignment: ' . $form['title'],
            "Due " . $due->format('M d, Y h:i A') . " in {$class['class_name']}.",
            'pages/assignments/view.php?id=' . $assignment_id,
            $current_user_id
        );

        $created = ['id' => $assignment_id, 'class_id' => $class['id']];
    }
}

/** One rubric row (also the JS template with empty values). */
function rubricRowHtml($i, $c, $error) {
    $h = fn($v) => htmlspecialchars((string) $v);
    return '<div class="rubric-row"><div class="rubric-main">'
        . '<input type="text" name="rubric_title[]" maxlength="100" placeholder="Criterion, e.g. Understanding" aria-label="Criterion name" class="' . ($error ? 'is-invalid' : '') . '" value="' . $h($c['title']) . '">'
        . '<input type="text" name="rubric_desc[]" maxlength="300" placeholder="What earns the points (optional)" aria-label="Criterion description" value="' . $h($c['description']) . '">'
        . '</div><div class="rubric-pts"><input type="number" name="rubric_points[]" min="1" max="1000" placeholder="pts" aria-label="Points" value="' . $h($c['points']) . '">'
        . '<button type="button" class="icon-btn danger rubric-remove" title="Remove criterion" aria-label="Remove criterion"><i class="fas fa-times"></i></button></div>'
        . '<div class="invalid-feedback" style="grid-column:1/-1;' . ($error ? 'display:block;' : '') . '">' . $h($error) . '</div></div>';
}

$page_title = $editing ? 'Edit Assignment' : 'Create Assignment';
$page_subtitle = $editing ? 'Changes are visible to the class right away' : 'Give your class work with a due date';
$active_menu = 'assignments';

if ($created) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        title: 'Assignment Created!',
        text: 'Students in this class have been notified.',
        confirmButtonText: 'Open Assignment',
        confirmButtonColor: '#4361ee',
        showCancelButton: true,
        cancelButtonText: 'Create Another'
    }).then(function(r) {
        window.location.href = r.isConfirmed
            ? '" . APP_URL . "/pages/assignments/view.php?id={$created['id']}'
            : '" . APP_URL . "/pages/assignments/create.php?class_id={$created['class_id']}';
    });";
} else {
    $page_script = ($csrf_error ? "
    Swal.fire({ icon: 'error', title: 'Session expired',
                text: 'Please submit the form again.', confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('assignmentForm');
    if (form) {
        bindLiveClear(form);

        // ---------- rubric ----------
        const rows = document.getElementById('rubricRows');
        const rTotal = document.getElementById('rubricTotal');
        const rErr = document.getElementById('rubricError');
        const maxMarks = document.getElementById('max_marks');
        const RMAX = " . RUBRIC_MAX_CRITERIA . ";
        function rubricSum() { let t = 0; rows.querySelectorAll('input[name=\"rubric_points[]\"]').forEach(function (i) { t += parseInt(i.value, 10) || 0; }); return t; }
        function rubricUsed() { return Array.from(rows.children).some(function (r) { return Array.from(r.querySelectorAll('input')).some(function (i) { return i.value.trim() !== ''; }); }); }
        function rubricTotals() {
            if (!rubricUsed()) { rTotal.textContent = ''; return; }
            const t = rubricSum(), m = parseInt(maxMarks.value, 10) || 0;
            rTotal.textContent = 'Total ' + t + ' / ' + m + (t === m ? ' ✓' : '');
            rTotal.className = 'rubric-total ' + (t === m ? 'ok' : 'off');
        }
        function addRubricRow() {
            const holder = document.createElement('div');
            holder.innerHTML = document.getElementById('rubricRowTpl').innerHTML;
            const row = holder.firstElementChild;
            rows.appendChild(row);
            bindLiveClear(row);
            return row;
        }
        rows.addEventListener('click', function (e) {
            const b = e.target.closest('.rubric-remove');
            if (!b) return;
            const row = b.closest('.rubric-row');
            if (rows.children.length > 1) row.remove(); else row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            rubricTotals();
        });
        rows.addEventListener('input', function () { rErr.style.display = 'none'; rubricTotals(); });
        maxMarks.addEventListener('input', rubricTotals);
        document.getElementById('addRubricRow').addEventListener('click', function () {
            if (rows.children.length >= RMAX) return;
            addRubricRow().querySelector('input').focus();
        });
        rubricTotals();
        const aiBtn = document.getElementById('rubricAi');
        if (aiBtn) aiBtn.addEventListener('click', function () {
            const cls = document.getElementById('class_id');
            if (!cls.value) { rErr.textContent = 'Choose the class first.'; rErr.style.display = 'block'; return; }
            const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
            const body = new URLSearchParams({ class_id: cls.value, title: document.getElementById('title').value, description: document.getElementById('description').value, max_marks: maxMarks.value });
            const label = aiBtn.innerHTML;
            aiBtn.disabled = true; aiBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> Writing the rubric…';
            rErr.style.display = 'none';
            fetch('rubric_ai.php', { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) throw new Error(d.error);
                    rows.innerHTML = '';
                    d.rubric.forEach(function (c) {
                        const row = addRubricRow(), ins = row.querySelectorAll('input');
                        ins[0].value = c.title; ins[1].value = c.description; ins[2].value = c.points;
                    });
                    rubricTotals();
                })
                .catch(function (e) { rErr.textContent = e.message || 'The AI could not write a rubric.'; rErr.style.display = 'block'; })
                .finally(function () { aiBtn.disabled = false; aiBtn.innerHTML = label; });
        });

        form.addEventListener('submit', function(e) {
            let ok = true;
            if (rubricUsed()) {
                const t = rubricSum(), m = parseInt(maxMarks.value, 10) || 0;
                if (t !== m) { rErr.textContent = 'The criteria add up to ' + t + ' points but Max Marks is ' + m + ' — they must match'; rErr.style.display = 'block'; ok = false; }
                rows.querySelectorAll('.rubric-row').forEach(function (row) {
                    const ins = row.querySelectorAll('input');
                    if ((ins[0].value.trim() || ins[1].value.trim() || ins[2].value.trim()) && !ins[0].value.trim()) { ins[0].classList.add('is-invalid'); ok = false; }
                });
            }
            const cls = document.getElementById('class_id');
            const title = document.getElementById('title');
            const desc = document.getElementById('description');
            const due = document.getElementById('due_date');
            const marks = document.getElementById('max_marks');
            if (!cls.value) { setFieldError(cls, 'Please select one of your classes'); ok = false; }
            if (!title.value.trim()) { setFieldError(title, 'Title is required'); ok = false; }
            if (!desc.value.trim()) { setFieldError(desc, 'Instructions are required'); ok = false; }
            if (!due.value) { setFieldError(due, 'Due date is required'); ok = false; }
            else if (new Date(due.value) <= new Date() && due.value !== " . json_encode($old_due) . ") { setFieldError(due, 'Due date must be in the future'); ok = false; }
            const m = Number(marks.value);
            if (!/^\\d+$/.test(marks.value) || m < 1 || m > 1000) {
                setFieldError(marks, 'Max marks must be a whole number from 1 to 1000'); ok = false;
            }
            form.querySelectorAll('.material-link').forEach(function (row) {
                const u = row.querySelector('input[type=url]'), t = row.querySelector('input[type=text]');
                if (!u.value.trim() && t.value.trim()) { setFieldError(u, 'Add the link address, or clear the title'); ok = false; }
                else if (u.value.trim() && !/^https?:\/\/\S+$/i.test(u.value.trim())) { setFieldError(u, 'Enter a full web address starting with http:// or https://'); ok = false; }
            });
            if (!ok) e.preventDefault();
        });
        // Resource checkboxes: only the selected class's resources
        const clsSel = document.getElementById('class_id');
        function syncResources() {
            let any = false;
            form.querySelectorAll('.material-check').forEach(function (lab) {
                const show = lab.dataset.class === clsSel.value;
                lab.hidden = !show;
                const box = lab.querySelector('input');
                box.disabled = !show;
                if (!show) box.checked = false;
                if (show) any = true;
            });
            document.getElementById('noClassResources').hidden = any || !clsSel.value;
        }
        clsSel.addEventListener('change', syncResources);
        syncResources();
        const addRow = document.getElementById('addLinkRow');
        if (addRow) addRow.addEventListener('click', function () {
            const rows = form.querySelectorAll('.material-link');
            const copy = rows[rows.length - 1].cloneNode(true);
            copy.querySelectorAll('input').forEach(function (i) { i.value = ''; i.classList.remove('is-invalid'); });
            copy.querySelector('.invalid-feedback').textContent = '';
            rows[rows.length - 1].after(copy);
            bindLiveClear(form);
            if (rows.length + 1 >= Number(addRow.dataset.max)) addRow.remove();
        });
    }";
}

$can_ai_write = true && aiAvailableFor('writing', $current_user_id);
if ($can_ai_write) $page_scripts[] = 'assets/js/ai-assist.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink($editing ? 'Back to assignment' : 'Assignments', $editing ? 'view.php?id=' . (int) $editing['id'] : 'view.php'); ?>

<?php if (empty($classes)): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Create a class first, then give it assignments.</p>
    <div style="margin-top:16px;">
        <a href="<?php echo APP_URL; ?>/pages/classes/create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a>
    </div>
</div>
<?php else: ?>
<div class="form-card">
    <form method="POST" id="assignmentForm" novalidate>
        <?php echo csrfField(); ?>

        <div class="form-group">
            <label for="class_id">Class <span class="required-mark">*</span></label>
            <select id="class_id" name="class_id" <?php echo $editing ? 'disabled' : ''; ?>
                class="<?php echo $field_errors['class_id'] ? 'is-invalid' : ''; ?>">
                <option value="">Select a class</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['class_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_id']); ?></div>
        </div>

        <?php if ($editing): ?><input type="hidden" name="class_id" value="<?php echo (int) $editing['class_id']; ?>"><?php endif; ?>
        <?php echo topicSelectField($classes, $form_topic); ?>

        <div class="form-group">
            <label for="title">Title <span class="required-mark">*</span></label>
            <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Exercise 1: Loops"
                class="<?php echo $field_errors['title'] ? 'is-invalid' : ''; ?>"
                value="<?php echo htmlspecialchars($form['title']); ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['title']); ?></div>
        </div>

        <div class="form-group">
            <label for="description">Instructions <span class="required-mark">*</span></label>
            <textarea id="description" name="description" rows="6"
                placeholder="What should students do and hand in?"
                class="<?php echo $field_errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description']); ?></textarea>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['description']); ?></div>
            <?php if ($can_ai_write): ?>
            <div class="ai-assist-row"><button type="button" class="btn-app btn-sm btn-outline" data-ai-task="assignment" data-target="#description" data-class-source="#class_id" data-title-source="#title" data-prompt="Anything the AI should include? (optional)"><i class="fas fa-robot"></i> Draft instructions with AI</button></div>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="due_date">Due Date <span class="required-mark">*</span></label>
                <input type="datetime-local" id="due_date" name="due_date"
                    class="<?php echo $field_errors['due_date'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['due_date']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['due_date']); ?></div>
                <small class="form-hint">Late submissions are still accepted and marked late.</small>
            </div>

            <div class="form-group">
                <label for="max_marks">Max Marks <span class="required-mark">*</span></label>
                <input type="number" id="max_marks" name="max_marks" min="1" max="1000" step="1"
                    class="<?php echo $field_errors['max_marks'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['max_marks']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['max_marks']); ?></div>
            </div>
        </div>

        <fieldset class="rubric-field" id="rubricField">
            <legend>Rubric <span class="form-hint" style="font-weight:400;">optional — what earns the marks (students see it)</span></legend>
            <div id="rubricRows">
                <?php foreach ($form_rubric as $ri => $rc): echo rubricRowHtml($ri, $rc, $rubric_errors[$ri] ?? ''); endforeach; ?>
            </div>
            <div class="rubric-foot">
                <button type="button" class="link-btn" id="addRubricRow"><i class="fas fa-plus"></i> Add criterion</button>
                <span class="rubric-total" id="rubricTotal" aria-live="polite"></span>
            </div>
            <?php if ($can_ai): ?>
            <button type="button" class="btn-app btn-sm btn-outline" id="rubricAi"><i class="fas fa-magic"></i> Suggest a rubric with AI</button>
            <small class="form-hint" style="display:block;margin-top:6px;">The AI drafts criteria from your title and instructions; points are scaled to Max Marks. Edit anything before saving.</small>
            <?php endif; ?>
            <div class="invalid-feedback<?php echo isset($rubric_errors['total']) ? ' show' : ''; ?>" id="rubricError" style="display:<?php echo isset($rubric_errors['total']) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars($rubric_errors['total'] ?? ''); ?></div>
        </fieldset>

        <?php echo releaseModeField($is_post ? postedReleaseMode() : ($editing['release_mode'] ?? 'instant')); ?>

        <fieldset class="materials-field">
            <legend>Materials <span class="form-hint" style="font-weight:400;">optional — files and links students need</span></legend>

            <div class="form-group" id="materialResources">
                <label>Class resources</label>
                <div class="material-checks">
                    <?php foreach ($all_resources as $r): ?>
                    <label class="material-check" data-class="<?php echo (int) $r['class_id']; ?>">
                        <input type="checkbox" name="resource_ids[]" value="<?php echo (int) $r['id']; ?>" <?php echo in_array((int) $r['id'], $form_resources, true) ? 'checked' : ''; ?>>
                        <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($r['title']); ?>
                        <span class="notif-meta"><?php echo htmlspecialchars(strtoupper((string) $r['file_type'])); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <small class="form-hint" id="noClassResources">This class has no resources yet. Upload one under Resources to attach it here.</small>
            </div>

            <label>Links</label>
            <?php foreach ($form_links as $i => $l): ?>
            <div class="form-group material-link">
                <div class="material-link-row">
                    <input type="url" name="link_url[]" maxlength="500" placeholder="https://…" aria-label="Link <?php echo $i + 1; ?> address"
                        class="<?php echo isset($link_errors[$i]) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($l['url']); ?>">
                    <input type="text" name="link_title[]" maxlength="200" placeholder="Title (optional)" aria-label="Link <?php echo $i + 1; ?> title"
                        value="<?php echo htmlspecialchars($l['title']); ?>">
                </div>
                <div class="invalid-feedback"><?php echo htmlspecialchars($link_errors[$i] ?? ''); ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (count($form_links) < MAX_ASSIGNMENT_LINKS): ?>
            <button type="button" class="link-btn" id="addLinkRow" data-max="<?php echo MAX_ASSIGNMENT_LINKS; ?>"><i class="fas fa-plus"></i> Add another link</button>
            <?php endif; ?>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn-app">
                <i class="fas <?php echo $editing ? 'fa-save' : 'fa-plus-circle'; ?>"></i> <?php echo $editing ? 'Save Changes' : 'Create Assignment'; ?>
            </button>
            <a href="<?php echo APP_URL; ?>/pages/assignments/view.php<?php echo $editing ? '?id=' . (int) $editing['id'] : ''; ?>" class="btn-app btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<template id="rubricRowTpl"><?php echo rubricRowHtml(0, ['title' => '', 'description' => '', 'points' => ''], ''); ?></template>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
