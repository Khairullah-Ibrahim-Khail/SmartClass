<?php
/**
 * Settings › Grading scale — the hub: every class of this teacher with the scale it uses.
 * The class teacher decides (A+, A, B … ranges, colours, GPA): "Edit" opens that class's own editor (pages/classes/grading.php).
 * The school's default scale (classes without their own) has its own page for the super admin: grading_default.php.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/settings/index.php');

$classes = getUserClasses($current_user_id, 'teacher');
$school = gradingSchoolScale();
$rows = [];
foreach ($classes as $c) {
    $own = gradingClassScale((int) $c['id']);
    $sc = $own ?? $school;
    $rows[] = ['id' => (int) $c['id'], 'name' => $c['class_name'], 'own' => (bool) $own, 'bands' => $sc['bands'], 'gpa' => $sc['show_gpa'], 'round' => $sc['round']];
}

$page_title = 'Grading scale';
$page_subtitle = 'Grades, ranges, colours and GPA — decided per class by its teacher';
$active_menu = 'settings';
$settings_page = true;
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-award"></i> Your classes</h2>
        <?php if (isAdmin()): ?><a class="btn-app btn-sm btn-outline" href="grading_default.php"><i class="fas fa-school"></i> School default</a><?php endif; ?></div>
    <?php if (!$rows): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-chalkboard"></i><p>You have no classes yet.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Class</th><th>Grading scale</th><th>Grades</th><th>GPA</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                <td><span class="status-badge <?php echo $r['own'] ? 'graded' : 'pending'; ?>"><?php echo $r['own'] ? 'Set by you' : 'School default'; ?></span></td>
                <td class="gs-mini"><?php foreach (gradingRanges() as $_) break; foreach ($r['bands'] as $b): ?><span class="gs-dot" style="background:<?php echo htmlspecialchars($b['color']); ?>" title="<?php echo htmlspecialchars($b['label']); ?>"></span><?php endforeach; ?>
                    <span class="form-hint" style="margin:0 0 0 6px;"><?php echo htmlspecialchars(implode(' · ', array_slice(array_column($r['bands'], 'label'), 0, 6))); ?><?php echo count($r['bands']) > 6 ? ' …' : ''; ?></span></td>
                <td><?php echo $r['gpa'] ? 'Shown' : 'Hidden'; ?></td>
                <td style="text-align:right;"><a class="btn-app btn-sm" href="<?php echo APP_URL; ?>/pages/settings/grading_class.php?class_id=<?php echo $r['id']; ?>"><i class="fas fa-pen"></i> Edit</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="form-hint" style="padding:12px 18px;margin:0;">A class without its own scale uses the school default. Open a class to write its grades, ranges (≥ > ≤ <), colours and GPA numbers.</p>
    <?php endif; ?>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
