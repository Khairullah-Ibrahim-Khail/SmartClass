<?php
/**
 * Add Marks (teachers) — ?exam_id=N: one row per student of the class: marks (0..max), absent, remarks.
 *   POST save      saves every row at once (a blank mark = not marked yet); students see nothing until published
 *   POST publish   makes the results visible to students and notifies each student once (later edits are visible at once)
 *   POST unpublish hides them again
 * Without exam_id: choose an exam. Only teachers of the exam's class; CSRF; Post/Redirect/Get; inline row errors.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/marks/view.php');

$exam = null;
if (!empty($_GET['exam_id'])) {
    $exam = $db->fetchOne("SELECT e.*, c.class_name FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'), [(int) $_GET['exam_id'], $current_user_id]);
    if (!$exam) redirect(APP_URL . '/pages/marks/add.php');
}
$self = APP_URL . '/pages/marks/add.php' . ($exam ? '?exam_id=' . $exam['id'] : '');
$row_errors = [];
$task_result = null;
$students = [];
if ($exam) {
    $students = $db->fetchAll(
        "SELECT u.id, u.name, u.email, m.marks, m.absent, m.remarks FROM class_members cm INNER JOIN users u ON cm.student_id = u.id
         LEFT JOIN exam_marks m ON m.exam_id = ? AND m.student_id = u.id WHERE cm.class_id = ? ORDER BY u.name", [$exam['id'], $exam['class_id']]);
}

if ($exam && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save') {
        $in_marks = (array) ($_POST['marks'] ?? []);
        $in_abs = (array) ($_POST['absent'] ?? []);
        $in_rem = (array) ($_POST['remarks'] ?? []);
        $clean = [];
        foreach ($students as $s) {
            $sid = (int) $s['id'];
            $absent = !empty($in_abs[$sid]);
            $raw = trim((string) ($in_marks[$sid] ?? ''));
            $rem = mb_substr(trim((string) ($in_rem[$sid] ?? '')), 0, 300);
            if (!$absent && $raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $exam['max_marks'])) {
                $row_errors[$sid] = 'Marks must be 0 to ' . (int) $exam['max_marks'];
            }
            $clean[$sid] = ['marks' => $absent || $raw === '' ? null : round((float) $raw, 2), 'absent' => $absent ? 1 : 0, 'remarks' => $rem !== '' ? $rem : null, 'raw' => $raw, 'rem' => $rem];
        }
        if ($row_errors) {
            $task_result = ['error', 'Not saved', count($row_errors) . ' row(s) have invalid marks. Nothing was saved.'];
            foreach ($students as &$s) { $c = $clean[(int) $s['id']]; $s['marks'] = $c['raw']; $s['absent'] = $c['absent']; $s['remarks'] = $c['rem']; }
            unset($s);
        } else {
            foreach ($clean as $sid => $c) {
                if ($c['marks'] === null && !$c['absent'] && $c['remarks'] === null) { $db->delete('exam_marks', 'exam_id = ? AND student_id = ?', [$exam['id'], $sid]); continue; }
                $db->query("INSERT INTO exam_marks (exam_id, student_id, marks, absent, remarks) VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE marks = VALUES(marks), absent = VALUES(absent), remarks = VALUES(remarks)", [$exam['id'], $sid, $c['marks'], $c['absent'], $c['remarks']]);
            }
            logActivity('save_exam_marks', 'exams', "Saved marks of exam #{$exam['id']} \"{$exam['title']}\"");
            Session::flash('marks_msg', ['success', 'Marks saved', $exam['results_published_at'] ? 'Students already see their marks, so changes are visible at once.' : 'Students will see them when you publish the results.']);
            redirect($self);
        }
    } elseif ($action === 'publish' && empty($exam['results_published_at'])) {
        // claimed once, so a double click never notifies twice
        if ($db->query("UPDATE exams SET results_published_at = NOW() WHERE id = ? AND results_published_at IS NULL", [$exam['id']])->rowCount()) {
            $n = 0;
            foreach ($students as $s) {
                $mk = $db->fetchOne("SELECT marks, absent FROM exam_marks WHERE exam_id = ? AND student_id = ?", [$exam['id'], $s['id']]);
                $text = $mk && $mk['absent'] ? 'You were marked absent.' : ($mk && $mk['marks'] !== null ? 'You scored ' . rtrim(rtrim(number_format((float) $mk['marks'], 2, '.', ''), '0'), '.') . ' / ' . (int) $exam['max_marks'] . '.' : 'Your result is available.');
                notify($s['id'], 'result_released', 'Exam results: ' . $exam['title'], $text . ' — ' . $exam['class_name'], 'pages/marks/view.php', $current_user_id, $exam['class_id']);
                $n++;
            }
            logActivity('publish_exam_results', 'exams', "Published results of exam #{$exam['id']} \"{$exam['title']}\"");
            Session::flash('marks_msg', ['success', 'Results published', $n . ' student' . ($n == 1 ? ' was' : 's were') . ' notified.']);
        }
        redirect($self);
    } elseif ($action === 'unpublish' && !empty($exam['results_published_at'])) {
        $db->update('exams', ['results_published_at' => null], 'id = ?', [$exam['id']]);
        logActivity('unpublish_exam_results', 'exams', "Hid the results of exam #{$exam['id']}");
        Session::flash('marks_msg', ['info', 'Results hidden', 'Students no longer see these marks. Publishing again notifies them again.']);
        redirect($self);
    }
}
$task_result = Session::flash('marks_msg') ?? $task_result;
$exam_list = [];
if (!$exam) {
    $exam_list = $db->fetchAll("SELECT e.id, e.title, e.exam_date, c.class_name FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE " . teachesClassSql('c') . " ORDER BY e.exam_date DESC LIMIT 100", [$current_user_id]);
}

$page_title = 'Add Marks';
$page_subtitle = $exam ? $exam['title'] . ' · ' . $exam['class_name'] : 'Choose an exam';
$active_menu = 'marks';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_script .= "
    const mf = document.getElementById('marksForm');
    if (mf) {
        const max = Number(mf.dataset.max);
        // absent disables the mark box of its row
        mf.querySelectorAll('.abs-box').forEach(function (b) {
            const sync = function () { const m = b.closest('tr').querySelector('.mark-in'); m.disabled = b.checked; if (b.checked) m.value = ''; };
            b.addEventListener('change', sync); sync();
        });
        mf.addEventListener('submit', function (e) {
            let ok = true;
            mf.querySelectorAll('.mark-in').forEach(function (m) {
                const fb = m.parentElement.querySelector('.invalid-feedback');
                if (!m.disabled && m.value !== '' && (isNaN(m.value) || +m.value < 0 || +m.value > max)) { m.classList.add('is-invalid'); fb.textContent = 'Marks must be 0 to ' + max; ok = false; }
            });
            if (!ok) e.preventDefault();
        });
        mf.addEventListener('input', function (e) { if (e.target.classList.contains('mark-in')) e.target.classList.remove('is-invalid'); });
    }";
$page_scripts = ['assets/js/class.js'];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if (!$exam): ?>
<?php if (!$exam_list): ?>
<div class="empty-state"><i class="fas fa-file-alt"></i><h3>No exams yet</h3><p style="margin-top:6px;">Create an exam first, then enter its marks here.</p>
    <a class="btn-app" href="<?php echo APP_URL; ?>/pages/exams/create.php" style="margin-top:12px;"><i class="fas fa-plus"></i> Create Exam</a></div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($exam_list as $x): ?>
    <a class="lecture-card" href="add.php?exam_id=<?php echo (int) $x['id']; ?>" style="text-decoration:none;color:inherit;">
        <div class="lecture-info"><div class="lecture-title"><?php echo htmlspecialchars($x['title']); ?></div>
            <div class="lecture-meta"><span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($x['class_name']); ?></span><span><i class="fas fa-clock"></i> <?php echo formatDate($x['exam_date'], 'M d, Y h:i A'); ?></span></div></div>
        <div class="lecture-actions"><span class="btn-app btn-sm">Enter marks</span></div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php else: $published = !empty($exam['results_published_at']); ?>
<a class="back-link" href="<?php ob_start(); ?><?php echo APP_URL; ?>/pages/exams/view.php?class_id=<?php echo (int) $exam['class_id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Exams</a>
<div class="content-card release-card<?php echo $published ? '' : ' is-held'; ?>">
    <div class="release-row">
        <div><strong><i class="fas fa-bullhorn"></i> Results</strong>
            <div class="notif-meta"><?php echo $published ? 'Published on ' . formatDate($exam['results_published_at'], 'M d, Y h:i A') . ' — students see their marks.' : 'Hidden from students until you publish them.'; ?></div></div>
        <div class="release-actions">
            <form method="POST" data-confirm="<?php echo $published ? 'Hide the results?' : 'Publish the results?'; ?>"
                  data-confirm-text="<?php echo $published ? 'Students will no longer see these marks.' : 'Every student is notified now (in the app and by email) and can see their mark.'; ?>"
                  data-confirm-button="<?php echo $published ? 'Hide' : 'Publish results'; ?>">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="<?php echo $published ? 'unpublish' : 'publish'; ?>">
                <button type="submit" class="btn-app btn-sm<?php echo $published ? ' btn-outline' : ''; ?>"><i class="fas fa-<?php echo $published ? 'eye-slash' : 'paper-plane'; ?>"></i> <?php echo $published ? 'Hide results' : 'Publish results'; ?></button>
            </form>
        </div>
    </div>
</div>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-pen"></i> Marks out of <?php echo (int) $exam['max_marks']; ?></h2></div>
    <?php if (!$students): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-user-graduate"></i><p>No students are enrolled in this class yet.</p></div>
    <?php else: ?>
    <form method="POST" id="marksForm" data-max="<?php echo (int) $exam['max_marks']; ?>" novalidate>
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save">
        <div class="table-wrap">
            <table class="app-table">
                <thead><tr><th>Student</th><th style="width:150px;">Marks</th><th style="width:90px;">Absent</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($students as $s): $sid = (int) $s['id']; $err = $row_errors[$sid] ?? ''; ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['name']); ?></strong><br><span class="form-hint" style="margin:0;"><?php echo htmlspecialchars($s['email']); ?></span></td>
                    <td><div class="form-group" style="margin:0;"><input type="number" class="mark-in <?php echo $err ? 'is-invalid' : ''; ?>" name="marks[<?php echo $sid; ?>]" min="0" max="<?php echo (int) $exam['max_marks']; ?>" step="0.25" inputmode="decimal"
                        value="<?php echo $s['marks'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $s['marks'], 2, '.', ''), '0'), '.')) : ''; ?>" aria-label="Marks for <?php echo htmlspecialchars($s['name']); ?>">
                        <div class="invalid-feedback"><?php echo htmlspecialchars($err); ?></div></div></td>
                    <td><label class="material-check"><input type="checkbox" class="abs-box" name="absent[<?php echo $sid; ?>]" value="1" <?php echo $s['absent'] ? 'checked' : ''; ?>> <span>Absent</span></label></td>
                    <td><input type="text" name="remarks[<?php echo $sid; ?>]" maxlength="300" placeholder="Optional" value="<?php echo htmlspecialchars((string) $s['remarks']); ?>" aria-label="Remarks for <?php echo htmlspecialchars($s['name']); ?>"></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-inner"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save marks</button>
            <span class="form-hint" style="margin-left:10px;">A blank mark means "not marked yet".</span></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
