<?php
/**
 * View Marks —
 *   student: the exams whose results were published, with their mark, percentage, class average and remarks, plus an overall figure
 *   teacher: one card per exam of their classes (average, highest, lowest, pass rate, how many are marked) with a table of the marks
 *   ?class_id=N one class.
 * Students never see other students' marks (only the class average).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$use_ids = $filter ? [$filter] : $class_ids;
$task_result = Session::flash('marks_msg');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_gpa') {
    $cid = (int) ($_POST['class_id'] ?? 0);
    if (verifyCsrf() && isTeacher() && in_array($cid, $class_ids, true)) {
        $cur = gradingClassScale($cid) ?? gradingSchoolScale();
        $cur['show_gpa'] = !empty($_POST['show']);
        $db->query("INSERT INTO class_grading_scales (class_id, scale, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE scale = VALUES(scale), updated_by = VALUES(updated_by)",
            [$cid, json_encode(['show_gpa' => $cur['show_gpa'], 'basis' => $cur['basis'], 'round' => $cur['round'], 'bands' => $cur['bands']]), $current_user_id]);
        logActivity('class_grading_scale', 'marks', 'GPA ' . ($cur['show_gpa'] ? 'shown' : 'hidden') . " for class #{$cid}");
        Session::flash('marks_msg', ['success', $cur['show_gpa'] ? 'GPA is shown' : 'GPA is hidden', $cur['show_gpa'] ? 'The GPA column is on the marks sheet, grades and reports of this class.' : 'Only marks and grades are shown now. Your GPA numbers are kept.']);
    } else {
        Session::flash('marks_msg', ['error', 'Not allowed', 'Only a teacher of the class can change this.']);
    }
    redirect(APP_URL . '/pages/marks/view.php?class_id=' . $cid);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scheme') {
    $cid = (int) ($_POST['class_id'] ?? 0);
    $w_in = is_array($_POST['weights'] ?? null) ? $_POST['weights'] : [];
    $new = [];
    $err = '';
    if (!verifyCsrf()) $err = 'Please reload the page and try again.';
    elseif (!isTeacher() || !in_array($cid, $class_ids, true)) $err = 'Only a teacher of the class can change its marking scheme.';
    else {
        foreach (markingComponents() as $k => $label) {
            $v = trim((string) ($w_in[$k] ?? '0'));
            if ($v === '') $v = '0';
            if (!is_numeric($v) || $v < 0 || $v > 1000) { $err = "\"{$label}\": enter marks between 0 and 1000 (0 = not used)."; break; }
            $new[$k] = round((float) $v, 2);
        }
        if (!$err && array_sum($new) <= 0) $err = 'At least one part must be worth some marks.';
    }
    if ($err) {
        $task_result = ['error', 'Not saved', $err];
    } else {
        foreach ($new as $k => $v) {
            $db->query("INSERT INTO class_marking_scheme (class_id, component, weight) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE weight = VALUES(weight)", [$cid, $k, $v]);
        }
        logActivity('marking_scheme', 'marks', "Saved the marking scheme of class #{$cid}: " . json_encode($new));
        Session::flash('marks_msg', ['success', 'Marking scheme saved', 'The final marks of the class now use these weights (total ' . rtrim(rtrim(number_format(array_sum($new), 2, '.', ''), '0'), '.') . ').']);
        redirect(APP_URL . '/pages/marks/view.php?class_id=' . $cid);
    }
}
$fmt = fn($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

$rows = [];
$details = [];   // teacher: exam id => per-student rows
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    if (isStudent()) {
        $rows = $db->fetchAll(
            "SELECT e.id, e.title, e.exam_date, e.max_marks, c.class_name, m.marks, m.absent, m.remarks,
                    (SELECT AVG(x.marks) FROM exam_marks x WHERE x.exam_id = e.id AND x.marks IS NOT NULL AND x.absent = 0) AS class_avg
             FROM exams e INNER JOIN classes c ON e.class_id = c.id LEFT JOIN exam_marks m ON m.exam_id = e.id AND m.student_id = ?
             WHERE e.class_id IN ({$in}) AND e.results_published_at IS NOT NULL ORDER BY e.exam_date DESC", array_merge([$current_user_id], $use_ids));
    } else {
        $rows = $db->fetchAll(
            "SELECT e.id, e.title, e.exam_date, e.max_marks, e.results_published_at, c.class_name,
                    (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = e.class_id) AS students,
                    (SELECT COUNT(*) FROM exam_marks x WHERE x.exam_id = e.id AND x.marks IS NOT NULL AND x.absent = 0) AS marked,
                    (SELECT COUNT(*) FROM exam_marks x WHERE x.exam_id = e.id AND x.absent = 1) AS absent_n,
                    (SELECT AVG(x.marks) FROM exam_marks x WHERE x.exam_id = e.id AND x.marks IS NOT NULL AND x.absent = 0) AS avg_m,
                    (SELECT MAX(x.marks) FROM exam_marks x WHERE x.exam_id = e.id AND x.absent = 0) AS max_m,
                    (SELECT MIN(x.marks) FROM exam_marks x WHERE x.exam_id = e.id AND x.marks IS NOT NULL AND x.absent = 0) AS min_m,
                    (SELECT COUNT(*) FROM exam_marks x WHERE x.exam_id = e.id AND x.marks IS NOT NULL AND x.absent = 0 AND x.marks >= e.max_marks * 0.5) AS passed
             FROM exams e INNER JOIN classes c ON e.class_id = c.id WHERE e.class_id IN ({$in}) ORDER BY e.exam_date DESC LIMIT 60", $use_ids);
        foreach ($db->fetchAll(
            "SELECT m.exam_id, u.name, m.marks, m.absent, m.remarks FROM exam_marks m INNER JOIN users u ON m.student_id = u.id
             INNER JOIN exams e ON m.exam_id = e.id WHERE e.class_id IN ({$in}) ORDER BY u.name", $use_ids) as $d) {
            $details[$d['exam_id']][] = $d;
        }
    }
}
$overall = null;
if (isStudent()) {
    $got = $max = 0;
    foreach ($rows as $r) if ($r['marks'] !== null && !$r['absent']) { $got += (float) $r['marks']; $max += (int) $r['max_marks']; }
    $overall = $max ? round($got / $max * 100, 1) : null;
}

$page_title = 'Marks';
$page_subtitle = isTeacher() ? 'Final marks and exam results of your classes' : 'Your final marks and exam results';
$active_menu = 'marks';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function () { f.form.submit(); });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
if (isStudent()) { require __DIR__ . '/_student.php'; include dirname(__DIR__, 2) . '/includes/app_footer.php'; exit; }   // the student's page lives in _student.php; the teacher's page below is unchanged
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
    <?php if (isTeacher()): ?><a href="add.php" class="btn-app"><i class="fas fa-plus"></i> Add Marks</a><?php endif; ?>
</div>

<?php if ($classes): foreach ($classes as $fc):
    if (!in_array((int) $fc['id'], $use_ids, true)) continue;
    $fm = classFinalMarks((int) $fc['id'], isStudent(), isStudent() ? $current_user_id : null);
    $comps = markingComponents();
    ?>
<style><?php echo gradingCss(); ?></style>
<section class="content-card fm-card">
    <div class="card-head">
        <h2><i class="fas fa-award"></i> Final marks <span class="form-hint" style="display:inline;margin:0;">· <?php echo htmlspecialchars($fc['class_name']); ?></span></h2>
        <div class="action-buttons">
            <?php if (isTeacher()): ?>
            <form method="POST" style="display:inline;margin:0;"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_gpa"><input type="hidden" name="class_id" value="<?php echo (int) $fc['id']; ?>">
                <?php if (gradingShowGpa()): ?><button type="submit" class="btn-app btn-sm btn-outline" title="Show only marks and grades"><i class="fas fa-eye-slash"></i> Hide GPA</button>
                <?php else: ?><input type="hidden" name="show" value="1"><button type="submit" class="btn-app btn-sm" title="Add the GPA column — your GPA numbers are kept"><i class="fas fa-eye"></i> Show GPA</button><?php endif; ?></form>
            <a class="btn-app btn-sm btn-outline" href="<?php echo APP_URL; ?>/pages/settings/grading_class.php?class_id=<?php echo (int) $fc['id']; ?>"><i class="fas fa-award"></i> Grading scale</a><?php endif; ?>
            <?php if (isTeacher()): ?><a class="btn-app btn-sm btn-outline" data-no-ajax href="<?php echo APP_URL; ?>/pages/marks/export_xlsx.php?class_id=<?php echo (int) $fc['id']; ?>"><i class="fas fa-file-excel"></i> Excel</a><?php endif; ?>
            <a class="btn-app btn-sm" target="_blank" href="<?php echo APP_URL; ?>/pages/marks/print.php?class_id=<?php echo (int) $fc['id']; ?>"><i class="fas fa-file-pdf"></i> <?php echo isTeacher() ? 'PDF' : 'Statement (PDF)'; ?></a>
        </div>
    </div>
    <?php if (isStudent()): $me = $fm['rows'][0] ?? null; ?>
    <?php if (!$me || $me['percent'] === null): ?>
    <p class="form-hint" style="padding:16px 20px;margin:0;">Your final marks appear here once marks are in for the mid exam, final exam, activities, quizzes or assignments of this class.</p>
    <?php else: $gb = gradeBand($me['percent']); ?>
    <div class="fm-me">
        <div class="fm-total"><span class="fm-big"><?php echo $fmt($me['total']); ?></span><span class="fm-of">/ <?php echo $fmt($fm['weight_all']); ?></span>
            <span class="fm-grade gc-bg <?php echo $gb['cls']; ?>"><?php echo htmlspecialchars($gb['label']); ?><?php echo gradingShowGpa() ? ' · GPA ' . number_format(gradeGpa($me['percent']), 2) : ''; ?></span></div>
        <div class="fm-parts">
            <?php foreach ($fm['scheme'] as $k => $w): $pt = $me['parts'][$k]; ?>
            <div class="fm-part"><span class="fm-name"><?php echo $comps[$k]; ?></span>
                <span class="fm-bar"><i style="width:<?php echo $pt['pct'] === null ? 0 : min(100, $pt['pct']); ?>%"></i></span>
                <b><?php echo $pt['earned'] === null ? '—' : $fmt($pt['earned']); ?> <small>/ <?php echo $fmt($w); ?></small></b></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="fm-ranges" aria-label="Grade ranges"><span class="fm-ranges-t">Grades:</span>
        <?php foreach (gradingRanges($fm['weight_all']) as $ri => $rg): ?><span class="fm-grade gc-bg <?php echo $rg['cls']; ?>"><?php echo htmlspecialchars($rg['label']); ?> <small><?php echo htmlspecialchars($rg['marks'] . ' marks · ' . $rg['pct'] . ($rg['gpa'] !== null ? ' · GPA ' . $rg['gpa'] : '')); ?></small></span><?php endforeach; ?>
    </div>
    <?php if ($me['weight_done'] < $fm['weight_all']): ?>
    <p class="form-hint" style="padding:0 20px 14px;margin:0;"><i class="fas fa-info-circle"></i> Some parts have no marks yet, so your total so far is <?php echo $fmt($me['total']); ?> out of <?php echo $fmt($fm['weight_all']); ?>
        (<?php echo $me['percent_so_far']; ?>% of the parts marked so far). The grade follows this class's rule: <?php echo gradingBasis() === 'all' ? 'the total out of everything' : 'the parts marked so far'; ?>.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php else: ?>
    <div class="table-wrap"><table class="app-table fm-table">
        <thead><tr><th>Student</th>
            <?php foreach ($fm['scheme'] as $k => $w): ?><th><?php echo $comps[$k]; ?><small>out of <?php echo $fmt($w); ?></small></th><?php endforeach; ?>
            <th>Total<small>out of <?php echo $fmt($fm['weight_all']); ?></small></th><th>Percent</th><?php if (gradingShowGpa()): ?><th>GPA</th><?php endif; ?><th>Grade</th></tr></thead>
        <tbody>
        <?php foreach ($fm['rows'] as $r): $gb = gradeBand($r['percent']); ?>
            <tr><td><strong><?php echo htmlspecialchars($r['name']); ?></strong>
                    <a class="icon-btn" style="margin-left:6px;" target="_blank" href="<?php echo APP_URL; ?>/pages/marks/print.php?class_id=<?php echo (int) $fc['id']; ?>&amp;student=<?php echo (int) $r['id']; ?>" title="Statement of <?php echo htmlspecialchars($r['name']); ?> (PDF)" aria-label="Statement of <?php echo htmlspecialchars($r['name']); ?>"><i class="fas fa-file-pdf"></i></a></td>
                <?php foreach ($fm['scheme'] as $k => $w): $pt = $r['parts'][$k]; ?>
                <td class="<?php echo $pt['pct'] === null ? 'fm-na' : ''; ?>" title="<?php echo $pt['pct'] === null ? 'No marks yet' : $pt['pct'] . '% of ' . $pt['items'] . ' item' . ($pt['items'] == 1 ? '' : 's'); ?>"><?php echo $pt['earned'] === null ? '—' : $fmt($pt['earned']); ?></td>
                <?php endforeach; ?>
                <td><strong><?php echo $r['percent'] === null ? '—' : $fmt($r['total']); ?></strong></td>
                <td><?php echo $r['percent'] === null ? '—' : $r['percent'] . '%'; ?></td>
                <?php if (gradingShowGpa()): ?><td><strong><?php echo $gb ? number_format(gradeGpa($r['percent']), 2) : '—'; ?></strong></td><?php endif; ?>
                <td><?php echo $gb ? '<span class="fm-grade gc-bg ' . $gb['cls'] . '">' . htmlspecialchars($gb['label']) . '</span>' : '—'; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$fm['rows']): ?><tr><td colspan="<?php echo count($fm['scheme']) + 4 + (gradingShowGpa() ? 1 : 0); ?>" class="form-hint" style="text-align:center;">No students in this class yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <p class="form-hint" style="padding:10px 18px;margin:0;">Percent = total ÷ <?php echo gradingBasis() === 'all' ? 'all ' . $fmt($fm['weight_all']) . ' marks (parts without marks count as 0)' : 'the marks of the parts that have marks so far'; ?>; the grade comes from this class's grading scale, which you set under "Grading scale".
        Exams count when their kind is Mid, Final or Activity.</p>
    <div class="fm-ranges" aria-label="Grade ranges"><span class="fm-ranges-t">Grades:</span>
        <?php foreach (gradingRanges($fm['weight_all']) as $ri => $rg): ?><span class="fm-grade gc-bg <?php echo $rg['cls']; ?>"><?php echo htmlspecialchars($rg['label']); ?> <small><?php echo htmlspecialchars($rg['marks'] . ' marks · ' . $rg['pct'] . ($rg['gpa'] !== null ? ' · GPA ' . $rg['gpa'] : '')); ?></small></span><?php endforeach; ?>
    </div>
    <p class="form-hint" style="padding:10px 18px 14px;margin:0;border-top:1px solid var(--line);"><i class="fas fa-sliders-h"></i> How many marks each part is worth is set in <a href="<?php echo APP_URL; ?>/pages/settings/marking.php?class_id=<?php echo (int) $fc['id']; ?>">Settings › Marking scheme</a>.</p>
    <?php endif; ?>
</section>
<?php endforeach; endif; ?>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chart-line"></i><h3>No classes yet</h3></div>
<?php elseif (!$rows): ?>
<p class="form-hint" style="text-align:center;margin:10px 0 24px;"><?php echo isTeacher() ? 'No exams yet — create one from the Exams page.' : 'No exam results have been published yet.'; ?></p>
<?php elseif (isStudent()): ?>
<?php if ($overall !== null): ?>
<div class="content-card"><div class="card-inner" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
    <div><div class="form-hint" style="margin:0;">Overall across published exams</div><div style="font-size:2rem;font-weight:700;"><?php echo $overall; ?>%</div></div>
    <div class="qstat-bar" style="flex:1;min-width:200px;"><span style="width:<?php echo (int) min(100, $overall); ?>%"></span></div>
</div></div>
<?php endif; ?>
<div class="content-card"><div class="table-wrap"><table class="app-table">
    <thead><tr><th>Exam</th><th>Class</th><th>Date</th><th>Your mark</th><th>%</th><th>Class average</th><th>Remarks</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($r['title']); ?></strong></td>
        <td><?php echo htmlspecialchars($r['class_name']); ?></td>
        <td><?php echo formatDate($r['exam_date'], 'M d, Y'); ?></td>
        <?php if ($r['absent']): ?><td colspan="2"><span class="status-badge missing">Absent</span></td>
        <?php elseif ($r['marks'] === null): ?><td colspan="2"><span class="status-badge pending">No mark recorded</span></td>
        <?php else: $pct = round($r['marks'] / $r['max_marks'] * 100, 1); ?>
        <td class="marks"><?php echo $fmt($r['marks']); ?> / <?php echo (int) $r['max_marks']; ?></td>
        <td><span class="status-badge <?php echo $pct >= 50 ? 'graded' : 'late'; ?>"><?php echo $pct; ?>%</span></td>
        <?php endif; ?>
        <td><?php echo $r['class_avg'] !== null ? $fmt($r['class_avg']) . ' / ' . (int) $r['max_marks'] : '—'; ?></td>
        <td><?php echo htmlspecialchars((string) $r['remarks']); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php else: foreach ($rows as $r): ?>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($r['title']); ?> <span class="form-hint" style="display:inline;margin:0;">· <?php echo htmlspecialchars($r['class_name']); ?> · <?php echo formatDate($r['exam_date'], 'M d, Y'); ?></span></h2>
        <div class="action-buttons">
            <span class="status-badge <?php echo $r['results_published_at'] ? 'graded' : 'pending'; ?>"><?php echo $r['results_published_at'] ? 'Published' : 'Hidden from students'; ?></span>
            <a class="btn-app btn-sm btn-outline" href="export.php?exam_id=<?php echo (int) $r['id']; ?>" data-no-ajax><i class="fas fa-file-csv"></i> CSV</a>
            <a class="btn-app btn-sm" href="add.php?exam_id=<?php echo (int) $r['id']; ?>"><i class="fas fa-pen"></i> Marks</a>
        </div>
    </div>
    <div class="card-inner">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Marked</div><div class="value"><?php echo (int) $r['marked']; ?> / <?php echo (int) $r['students']; ?><?php echo $r['absent_n'] ? ' · ' . (int) $r['absent_n'] . ' absent' : ''; ?></div></div>
            <div class="detail-item"><div class="label">Average</div><div class="value"><?php echo $r['avg_m'] !== null ? $fmt($r['avg_m']) . ' / ' . (int) $r['max_marks'] : '—'; ?></div></div>
            <div class="detail-item"><div class="label">Highest</div><div class="value"><?php echo $r['max_m'] !== null ? $fmt($r['max_m']) : '—'; ?></div></div>
            <div class="detail-item"><div class="label">Lowest</div><div class="value"><?php echo $r['min_m'] !== null ? $fmt($r['min_m']) : '—'; ?></div></div>
            <div class="detail-item"><div class="label">Pass rate (50%)</div><div class="value"><?php echo $r['marked'] ? round($r['passed'] / $r['marked'] * 100) . '%' : '—'; ?></div></div>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
