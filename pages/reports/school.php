<?php
/**
 * Reports › School (super admin): every active class side by side — teacher, students, average grade, attendance,
 * work handed in, students needing attention — with a printable report. Totals only, no student names.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$rows = schoolClassReport();
$sort = in_array($_GET['sort'] ?? '', ['name', 'average', 'attendance', 'at_risk', 'students'], true) ? $_GET['sort'] : 'name';
usort($rows, function ($a, $b) use ($sort) {
    if ($sort === 'name') return strcasecmp($a['name'], $b['name']);
    $x = $a[$sort] ?? -1; $y = $b[$sort] ?? -1;
    return $sort === 'at_risk' || $sort === 'students' ? $y <=> $x : $x <=> $y;   // lowest average / attendance first; most students / at risk first
});
$tot_students = array_sum(array_column($rows, 'students'));
$avgs = array_filter(array_column($rows, 'average'), fn($v) => $v !== null);
$atts = array_filter(array_column($rows, 'attendance'), fn($v) => $v !== null);
$fmt = fn($v) => $v === null ? '—' : $v . '%';

$page_title = 'School report';
$page_subtitle = 'Every class of the school side by side';
$active_menu = 'reports';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Reports', 'index.php'); ?>
<div class="page-toolbar">
    <div class="tab-pills">
        <a href="index.php"><i class="fas fa-chalkboard"></i> My classes</a>
        <a href="school.php" class="active"><i class="fas fa-school"></i> Whole school</a>
    </div>
    <a class="btn-app btn-sm" target="_blank" href="school_print.php"><i class="fas fa-file-pdf"></i> PDF report</a>
</div>
<?php if (!$rows): ?>
<div class="empty-state"><i class="fas fa-school"></i><h3>No classes yet</h3></div>
<?php else: ?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-school"></i> All classes <span class="form-hint" style="display:inline;margin:0;">· <?php echo count($rows); ?> classes · <?php echo $tot_students; ?> students · average <?php echo $fmt($avgs ? round(array_sum($avgs) / count($avgs), 1) : null); ?> · attendance <?php echo $fmt($atts ? round(array_sum($atts) / count($atts), 1) : null); ?></span></h2>
        <form method="GET" class="people-sort"><select name="sort" data-autosubmit aria-label="Sort classes">
            <?php foreach (['name' => 'Class name', 'average' => 'Lowest average first', 'attendance' => 'Lowest attendance first', 'at_risk' => 'Most students needing attention', 'students' => 'Most students'] as $k => $l): ?>
            <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
            <noscript><button type="submit" class="btn-app btn-sm">Sort</button></noscript></form>
    </div>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Class</th><th>Teacher</th><th>Students</th><th>Average</th><th>Attendance</th><th>Work handed in</th><th>Need attention</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($r['name']); ?></strong><?php echo $r['subject'] !== '' ? '<br><span class="form-hint" style="margin:0;">' . htmlspecialchars($r['subject']) . '</span>' : ''; ?></td>
                <td><?php echo htmlspecialchars($r['teacher']); ?></td>
                <td><?php echo (int) $r['students']; ?></td>
                <td><?php echo $r['average'] === null ? '—' : '<span class="status-badge ' . ($r['average'] < lowGradePct() ? 'missing' : 'graded') . '">' . $r['average'] . '%</span>'; ?></td>
                <td><?php echo $r['attendance'] === null ? '—' : '<span class="status-badge ' . ($r['attendance'] < attLow() ? 'late' : 'graded') . '">' . $r['attendance'] . '%</span>'; ?></td>
                <td><?php echo $fmt($r['submission']); ?></td>
                <td><?php echo $r['at_risk'] ? '<span class="status-badge late">' . (int) $r['at_risk'] . '</span>' : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
