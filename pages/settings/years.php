<?php
/**
 * Settings › Academic years (super admin): the school's years and "Close this year and start the next".
 *   Rollover: the new year's name and dates, then pick the classes to carry forward — each is copied (topics, lectures as
 *   drafts, assignments / quizzes without dates, resources; its grading scale and marking scheme; its teachers; optionally
 *   its students). The old classes are archived: readable as the record (grades, attendance, reports, PDFs), never editable.
 *   POST rollover   new_name, starts_on, ends_on, classes[id] => (parts[], students, name), archive   CSRF; Post/Redirect/Get
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$cur = currentAcademicYear();
$years = academicYears();
$task_result = Session::flash('years_msg');
$errors = ['name' => '', 'starts_on' => '', 'ends_on' => '', 'general' => ''];

$next_start = $cur['ends_on'] ? date('Y-m-d', strtotime($cur['ends_on'] . ' +2 months')) : date('Y-09-01');   // July 1st-ish → September
$ys = (int) substr($next_start, 0, 4); if ((int) substr($next_start, 5, 2) < 8) $ys--;
$form = ['name' => $ys . '–' . ($ys + 1), 'starts_on' => $ys . '-09-01', 'ends_on' => ($ys + 1) . '-06-30'];
$part_labels = ['lectures' => 'Lectures (drafts)', 'assignments' => 'Assignments', 'quizzes' => 'Quizzes', 'resources' => 'Resources'];

$classes = $db->fetchAll(
    "SELECT c.id, c.class_name, u.name AS teacher,
            (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS students,
            (SELECT COUNT(*) FROM lectures l WHERE l.class_id = c.id) AS lectures, (SELECT COUNT(*) FROM assignments a WHERE a.class_id = c.id) AS assignments,
            (SELECT COUNT(*) FROM quizzes q WHERE q.class_id = c.id) AS quizzes, (SELECT COUNT(*) FROM resources r WHERE r.class_id = c.id) AS resources
     FROM classes c INNER JOIN users u ON c.teacher_id = u.id
     WHERE c.is_active = 1 AND c.year_id <=> ? ORDER BY c.class_name", [$cur['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $form['name'] = trim((string) ($_POST['new_name'] ?? ''));
        $form['starts_on'] = (string) ($_POST['starts_on'] ?? '');
        $form['ends_on'] = (string) ($_POST['ends_on'] ?? '');
        if ($form['name'] === '' || mb_strlen($form['name']) > 40) $errors['name'] = 'Give the new year a name (up to 40 characters), e.g. 2027–2028';
        elseif ($db->exists('academic_years', 'name', $form['name'])) $errors['name'] = 'A year with this name already exists';
        if (!attValidDate($form['starts_on'])) $errors['starts_on'] = 'Choose the first day of the year';
        if (!attValidDate($form['ends_on'])) $errors['ends_on'] = 'Choose the last day of the year';
        elseif (!$errors['starts_on'] && $form['ends_on'] <= $form['starts_on']) $errors['ends_on'] = 'The last day must be after the first day';
        $pick = [];
        $valid_ids = array_column($classes, 'id');
        foreach ((array) ($_POST['carry'] ?? []) as $cid => $on) {
            if (!in_array((int) $cid, array_map('intval', $valid_ids), true)) continue;
            $opt = $_POST['opt'][$cid] ?? [];
            $pick[(int) $cid] = ['name' => trim((string) ($opt['name'] ?? '')), 'students' => !empty($opt['students']),
                                 'parts' => array_values(array_intersect(array_keys($part_labels), (array) ($opt['parts'] ?? [])))];
            if (mb_strlen($pick[(int) $cid]['name']) > 100) $errors['general'] = 'A class name must be under 100 characters';
        }
        if (!array_filter($errors)) {
            $r = rolloverAcademicYear(['name' => $form['name'], 'starts_on' => $form['starts_on'], 'ends_on' => $form['ends_on']], $pick, !empty($_POST['archive']));
            logActivity('academic_year', 'settings', "Closed {$cur['name']} and started {$form['name']}: {$r['copied']} classes carried, {$r['students']} students, {$r['archived']} archived" . ($r['failed'] ? ', failed: ' . implode(', ', array_keys($r['failed'])) : ''));
            Session::flash('years_msg', [$r['failed'] ? 'warning' : 'success', 'New year started: ' . $form['name'],
                $r['copied'] . ' class' . ($r['copied'] == 1 ? '' : 'es') . ' carried forward' . ($r['students'] ? ' with ' . $r['students'] . ' student places' : '') . ', ' . $r['archived'] . ' archived as the record of ' . $cur['name'] . '.'
                . ($r['failed'] ? ' Could not copy: ' . implode(', ', array_keys($r['failed'])) . ' (left active).' : '')]);
            redirect(APP_URL . '/pages/settings/years.php');
        }
        $task_result = ['error', 'Not started', $errors['general'] ?: 'Fix the highlighted fields.'];
    }
}

$page_title = 'Academic years';
$page_subtitle = 'Close a year, start the next — the old one stays as the record';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Years</h3><p>Every class belongs to a year. Closed years stay readable for reports and PDFs.</p></header>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Year</th><th>Dates</th><th>Status</th><th>Classes</th><th>Students</th></tr></thead>
        <tbody>
        <?php foreach ($years as $y): ?>
            <tr><td><strong><?php echo htmlspecialchars($y['name']); ?></strong></td>
                <td><?php echo date('M j, Y', strtotime($y['starts_on'])) . ' – ' . date('M j, Y', strtotime($y['ends_on'])); ?></td>
                <td><?php echo (int) $y['id'] === (int) $cur['id'] ? '<span class="status-badge graded">Current</span>' : '<span class="status-badge pending">Closed</span>'; ?></td>
                <td><?php echo (int) $y['active_classes']; ?> active<?php echo $y['archived_classes'] ? ' · ' . (int) $y['archived_classes'] . ' archived' : ''; ?></td>
                <td><?php echo (int) $y['students']; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="set-section">
    <header><h3>Close <?php echo htmlspecialchars($cur['name']); ?> and start the next year</h3><p>Choose what moves forward. Nothing is deleted — the classes of <?php echo htmlspecialchars($cur['name']); ?> stay as a record.</p></header>
    <div class="body">
    <form method="POST" class="yr-form" novalidate data-confirm="Start the new year?" data-confirm-text="The chosen classes are copied forward and the old classes are archived (read only). This cannot be undone." data-confirm-button="Start new year">
        <?php echo csrfField(); ?>
        <div class="form-row">
            <div class="form-group"><label for="new_name">New year name</label>
                <input type="text" id="new_name" name="new_name" maxlength="40" value="<?php echo htmlspecialchars($form['name']); ?>" class="<?php echo $errors['name'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div></div>
            <div class="form-group"><label for="starts_on">First day</label>
                <input type="date" id="starts_on" name="starts_on" value="<?php echo htmlspecialchars($form['starts_on']); ?>" class="<?php echo $errors['starts_on'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['starts_on']); ?></div></div>
            <div class="form-group"><label for="ends_on">Last day</label>
                <input type="date" id="ends_on" name="ends_on" value="<?php echo htmlspecialchars($form['ends_on']); ?>" class="<?php echo $errors['ends_on'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['ends_on']); ?></div></div>
        </div>

        <h4 class="yr-sub">Classes to carry forward</h4>
        <?php if (!$classes): ?>
        <p class="form-hint">There are no active classes in <?php echo htmlspecialchars($cur['name']); ?>. You can still start the new year empty.</p>
        <?php else: ?>
        <div class="yr-tools"><button type="button" class="chip" id="yrAll">Select all</button><button type="button" class="chip" id="yrNone">Clear</button></div>
        <div class="yr-classes">
            <?php foreach ($classes as $c): $cid = (int) $c['id']; ?>
            <div class="yr-class">
                <label class="yr-pick"><input type="checkbox" name="carry[<?php echo $cid; ?>]" value="1" class="yr-box" checked>
                    <span><strong><?php echo htmlspecialchars($c['class_name']); ?></strong><small><?php echo htmlspecialchars($c['teacher']); ?> · <?php echo (int) $c['students']; ?> students</small></span></label>
                <div class="yr-opts">
                    <input type="text" name="opt[<?php echo $cid; ?>][name]" maxlength="100" value="<?php echo htmlspecialchars($c['class_name']); ?>" aria-label="Name in the new year">
                    <span class="yr-parts"><?php foreach ($part_labels as $pk => $pl): if (!$c[$pk]) continue; ?>
                        <label><input type="checkbox" name="opt[<?php echo $cid; ?>][parts][]" value="<?php echo $pk; ?>" checked> <?php echo $pl; ?> <small>(<?php echo (int) $c[$pk]; ?>)</small></label><?php endforeach; ?></span>
                    <label class="yr-stu"><input type="checkbox" name="opt[<?php echo $cid; ?>][students]" value="1"> Move the <?php echo (int) $c['students']; ?> students too</label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <label class="switch-row" style="margin-top:14px;"><input type="checkbox" name="archive" value="1" checked> <span>Archive the old classes (recommended — they stay readable as the record)</span></label>
        <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-forward"></i> Close year and start the next</button></div>
    </form>
    </div>
</section>
<script data-page>
(function(){ var f=document.querySelector('.yr-form'); if(!f) return;
  function all(v){ f.querySelectorAll('.yr-box').forEach(function(b){ b.checked=v; }); }
  var a=document.getElementById('yrAll'), n=document.getElementById('yrNone'); if(a) a.addEventListener('click',function(){all(true);}); if(n) n.addEventListener('click',function(){all(false);});
})();
</script>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
