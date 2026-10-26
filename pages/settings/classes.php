<?php
/**
 * Settings › My classes — every class the teacher teaches, with the things that are set per class:
 * details & join approval & class code & archive (the class settings page), grading scale, marking scheme.
 * Archived classes are listed separately so they can be restored.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/settings/profile.php');

$rows = $db->fetchAll(
    "SELECT c.id, c.class_name, c.class_code, c.is_active, c.require_approval, ct.role AS teacher_role,
            (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS students
     FROM classes c INNER JOIN class_teachers ct ON ct.class_id = c.id AND ct.teacher_id = ?
     ORDER BY c.is_active DESC, c.class_name", [$current_user_id]);
$active = array_filter($rows, fn($r) => $r['is_active']);
$archived = array_filter($rows, fn($r) => !$r['is_active']);

$page_title = 'My classes';
$page_subtitle = 'Settings of each class you teach';
$active_menu = 'settings';
$settings_page = true;
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-chalkboard"></i> Active classes</h2>
        <a class="btn-app btn-sm" href="<?php echo APP_URL; ?>/pages/classes/create.php"><i class="fas fa-plus"></i> New class</a></div>
    <?php if (!$active): ?>
    <div class="empty-state" style="border:none;"><i class="fas fa-chalkboard"></i><p>You have no active classes.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Class</th><th>Code</th><th>Students</th><th>Joining</th><th>Settings</th></tr></thead>
        <tbody>
        <?php foreach ($active as $r): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($r['class_name']); ?></strong><?php echo $r['teacher_role'] === 'co_teacher' ? ' <span class="status-badge pending">Co-teacher</span>' : ''; ?></td>
                <td><code><?php echo htmlspecialchars($r['class_code']); ?></code></td>
                <td><?php echo (int) $r['students']; ?></td>
                <td><?php echo $r['require_approval'] ? 'You approve each student' : 'Anyone with the code'; ?></td>
                <td class="cls-actions">
                    <a class="btn-app btn-sm btn-outline" href="<?php echo APP_URL; ?>/pages/classes/edit.php?id=<?php echo (int) $r['id']; ?>"><i class="fas fa-cog"></i> Class details</a>
                    <?php if ($r['teacher_role'] === 'owner'): ?><a class="btn-app btn-sm btn-outline" href="grading_class.php?class_id=<?php echo (int) $r['id']; ?>"><i class="fas fa-award"></i> Grading</a><?php endif; ?>
                    <a class="btn-app btn-sm btn-outline" href="marking.php?class_id=<?php echo (int) $r['id']; ?>"><i class="fas fa-sliders-h"></i> Marking</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php if ($archived): ?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-box-archive"></i> Archived classes</h2></div>
    <div class="table-wrap"><table class="app-table">
        <tbody>
        <?php foreach ($archived as $r): ?>
            <tr><td><strong><?php echo htmlspecialchars($r['class_name']); ?></strong><br><span class="form-hint" style="margin:0;">Hidden from students</span></td>
                <td style="text-align:right;"><a class="btn-app btn-sm btn-outline" href="<?php echo APP_URL; ?>/pages/classes/edit.php?id=<?php echo (int) $r['id']; ?>"><i class="fas fa-undo"></i> Open to restore</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
