<?php
/**
 * Reports —
 *   teacher: pick one of YOUR classes: class summary + one row per student (attendance, work handed in, average grade,
 *            risk flags: low grades / low attendance / missing work) + CSV export. Teachers never see other teachers' classes.
 *   student: "My report" — the same figures for yourself in each of your classes.
 * Numbers come from the same gradebook and attendance data as the class pages.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$sel = (int) ($_GET['class_id'] ?? 0);
if (isTeacher() && (!$sel || !in_array($sel, $class_ids, true))) $sel = $class_ids[0] ?? 0;
$fmt = fn($v, $suffix = '%') => $v === null ? '—' : $v . $suffix;

$report = null;
$mine = [];
if (isTeacher() && $sel) {
    $report = classReport($sel);
    $fm_rows = [];
    foreach (classFinalMarks($sel)['rows'] as $fr) $fm_rows[$fr['id']] = $fr;
    $fm_total = array_sum(markingScheme($sel));
    $fm_css = gradingCss();
} elseif (isStudent()) {
    foreach ($classes as $c) {
        $r = classReport($c['id'], $current_user_id, true);
        if ($r['students']) {
            $fm = classFinalMarks((int) $c['id'], true, $current_user_id)['rows'][0] ?? null;
            $fmw = array_sum(markingScheme((int) $c['id']));
            $gb = $fm && $fm['percent'] !== null ? gradeBand($fm['percent']) : null;
            $mine[] = ['class' => $c['class_name'], 'id' => $c['id'], 'r' => $r['students'][0], 'assignments' => $r['assignments'], 'quizzes' => $r['quizzes'], 'summary' => $r['summary'],
                       'final' => $fm && $gb ? ['total' => $fm['total'], 'of' => $fmw, 'grade' => $gb['label'], 'cls' => $gb['cls'], 'gpa' => gradingShowGpa() ? gradeGpa($fm['percent']) : null, 'css' => gradingCss()] : null];
        }
    }
}

$page_title = 'Reports';
$page_subtitle = isTeacher() ? 'How your classes are doing' : 'How you are doing in your classes';
$active_menu = 'reports';
$page_script = "
    const f = document.getElementById('classFilter');
    if (f) f.addEventListener('change', function () { f.form.submit(); });";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if (isTeacher()): ?>
<div class="page-toolbar">
    <?php if (isAdmin()): ?><div class="tab-pills"><a href="index.php" class="active"><i class="fas fa-chalkboard"></i> My classes</a><a href="school.php"><i class="fas fa-school"></i> Whole school</a></div><?php endif; ?>
    <form method="GET" class="filter-form">
        <?php if ($classes): ?>
        <select id="classFilter" name="class_id" aria-label="Class">
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $sel === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Show</button></noscript>
    </form>
    <?php if ($report): ?><a class="btn-app btn-outline" href="export.php?class_id=<?php echo $sel; ?>" data-no-ajax><i class="fas fa-file-csv"></i> Export CSV</a><?php endif; ?>
</div>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chart-pie"></i><h3>You have no classes yet</h3></div>
<?php else: $s = $report['summary']; ?>
<div class="stats-grid five-tiles" style="margin-bottom:18px;">
    <div class="stat-card"><div class="stat-info"><h3>Students</h3><p class="stat-number"><?php echo (int) $s['students']; ?></p><span class="stat-change"><?php echo (int) $report['assignments']; ?> assignments · <?php echo (int) $report['quizzes']; ?> quizzes</span></div></div>
    <div class="stat-card"><div class="stat-info"><h3>Average grade</h3><p class="stat-number"><?php echo $fmt($s['average']); ?></p><span class="stat-change">of graded work</span></div></div>
    <div class="stat-card"><div class="stat-info"><h3>Attendance</h3><p class="stat-number"><?php echo $fmt($s['attendance']); ?></p><span class="stat-change">average per student</span></div></div>
    <div class="stat-card"><div class="stat-info"><h3>Work handed in</h3><p class="stat-number"><?php echo $fmt($s['submission_rate']); ?></p><span class="stat-change">of all assignments</span></div></div>
    <div class="stat-card"><div class="stat-info"><h3>Need attention</h3><p class="stat-number"><?php echo (int) $s['at_risk']; ?></p><span class="stat-change<?php echo $s['at_risk'] ? ' warn' : ''; ?>"><?php echo $s['at_risk'] ? 'see flags below' : 'everyone on track'; ?></span></div></div>
</div>
<div class="content-card"><div class="table-wrap"><table class="app-table">
    <style><?php echo $fm_css; ?></style>
    <thead><tr><th>Student</th><th>Attendance</th><th>Assignments</th><th>Quizzes</th><th>Average</th><th>Final marks<small style="display:block;font-weight:400;">out of <?php echo rtrim(rtrim(number_format($fm_total, 2, '.', ''), '0'), '.'); ?></small></th><?php if (gradingShowGpa()): ?><th>GPA</th><?php endif; ?><th>Grade</th><th>Flags</th></tr></thead>
    <tbody>
    <?php if (!$report['students']): ?><tr><td colspan="9" class="form-hint">No students in this class yet.</td></tr><?php endif; ?>
    <?php foreach ($report['students'] as $st): ?>
    <tr>
        <td><a href="<?php echo APP_URL; ?>/pages/classes/student.php?id=<?php echo $sel; ?>&amp;s=<?php echo (int) $st['id']; ?>"><strong><?php echo htmlspecialchars($st['name']); ?></strong></a></td>
        <td><?php echo $st['attendance'] === null ? '—' : '<span class="status-badge ' . ($st['attendance'] < attLow() ? 'late' : 'graded') . '">' . $st['attendance'] . '%</span>'; ?></td>
        <td><?php echo (int) $st['submitted']; ?> / <?php echo (int) $report['assignments']; ?></td>
        <td><?php echo (int) $st['quiz_done']; ?> / <?php echo (int) $report['quizzes']; ?></td>
        <td><?php echo $st['average'] === null ? '—' : '<span class="status-badge ' . ($st['average'] < lowGradePct() ? 'missing' : 'graded') . '">' . $st['average'] . '%</span>'; ?></td>
        <?php $fr = $fm_rows[$st['id']] ?? null; $gb = $fr && $fr['percent'] !== null ? gradeBand($fr['percent']) : null; ?>
        <td><?php echo $fr && $gb ? rtrim(rtrim(number_format($fr['total'], 2, '.', ''), '0'), '.') : '—'; ?></td>
        <?php if (gradingShowGpa()): ?><td><?php echo $gb ? number_format(gradeGpa($fr['percent']), 2) : '—'; ?></td><?php endif; ?>
        <td><?php echo $gb ? '<span class="fm-grade gc-bg ' . $gb['cls'] . '">' . htmlspecialchars($gb['label']) . '</span>' : '—'; ?></td>
        <td><?php foreach ($st['risk'] as $rk): ?><span class="status-badge late"><?php echo htmlspecialchars($rk); ?></span> <?php endforeach; ?><?php echo $st['risk'] ? '' : '—'; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php else: ?>
<?php if (!$mine): ?>
<div class="empty-state"><i class="fas fa-chart-pie"></i><h3>Nothing to report yet</h3><p style="margin-top:6px;">Join a class to see your progress here.</p></div>
<?php else: foreach ($mine as $m): $r = $m['r']; ?>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($m['class']); ?></h2>
        <?php foreach ($r['risk'] as $rk): ?><span class="status-badge late"><?php echo htmlspecialchars($rk); ?></span><?php endforeach; ?></div>
    <div class="card-inner"><div class="detail-grid">
        <?php if ($m['final']): ?>
        <style><?php echo $m['final']['css']; ?></style>
        <div class="detail-item"><div class="label">Final marks</div><div class="value"><?php echo rtrim(rtrim(number_format($m['final']['total'], 2, '.', ''), '0'), '.'); ?> / <?php echo rtrim(rtrim(number_format($m['final']['of'], 2, '.', ''), '0'), '.'); ?>
            <span class="fm-grade gc-bg <?php echo $m['final']['cls']; ?>"><?php echo htmlspecialchars($m['final']['grade']); ?></span><?php echo $m['final']['gpa'] !== null ? ' <small>GPA ' . number_format($m['final']['gpa'], 2) . '</small>' : ''; ?></div></div>
        <?php endif; ?>
        <div class="detail-item"><div class="label">My average</div><div class="value"><?php echo $fmt($r['average']); ?></div></div>
        <div class="detail-item"><div class="label">Attendance</div><div class="value"><?php echo $fmt($r['attendance']); ?></div></div>
        <div class="detail-item"><div class="label">Assignments handed in</div><div class="value"><?php echo (int) $r['submitted']; ?> / <?php echo (int) $m['assignments']; ?></div></div>
        <div class="detail-item"><div class="label">Quizzes finished</div><div class="value"><?php echo (int) $r['quiz_done']; ?> / <?php echo (int) $m['quizzes']; ?></div></div>
        <div class="detail-item"><div class="label">Class average</div><div class="value"><?php echo $fmt($m['summary']['average']); ?></div></div>
    </div></div>
</div>
<?php endforeach; endif; ?>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
