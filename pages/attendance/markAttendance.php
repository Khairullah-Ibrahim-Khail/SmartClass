<?php
/**
 * Mark Attendance (teacher) — sidebar "Attendance › Mark Attendance".
 * Lists the teacher's classes with today's status and opens the class
 * attendance sheet (pages/attendance/class.php), where marking happens.
 * (Rebuilt: the old version queried tables that do not exist.)
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/attendance/view.php');
}

$today = date('Y-m-d');
$classes = $db->fetchAll(
    "SELECT c.*,
            (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS students,
            (SELECT COUNT(*) FROM attendance a WHERE a.class_id = c.id AND a.date = ?) AS marked_today,
            (SELECT MAX(a.date) FROM attendance a WHERE a.class_id = c.id) AS last_marked
     FROM classes c WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY c.class_name",
    [$today, $current_user_id]
);

$page_title = 'Mark Attendance';
$page_subtitle = 'Choose a class to take attendance for ' . date('l, F j');
$active_menu = 'attendance';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Attendance', 'view.php'); ?>

<?php if (!$classes): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Create a class first; attendance is taken per class.</p>
    <div style="margin-top:16px;"><a href="<?php echo APP_URL; ?>/pages/classes/create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a></div>
</div>
<?php else: ?>
<div class="content-card centered-list">
    <?php foreach ($classes as $c):
        $done = (int) $c['marked_today'];
        $all = (int) $c['students']; ?>
    <a class="classwork-item" href="<?php echo attendanceUrl((int) $c['id'], '&amp;date=' . $today); ?>">
        <span class="work-icon" style="background: <?php echo classTheme($c); ?>;"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
        <span class="classwork-main">
            <span class="classwork-title"><?php echo htmlspecialchars($c['class_name']); ?></span>
            <span class="notif-meta"><?php echo $all; ?> student<?php echo $all == 1 ? '' : 's'; ?>
                · last taken <?php echo $c['last_marked'] ? date('M d', strtotime($c['last_marked'])) : 'never'; ?></span>
        </span>
        <?php if (!$all): ?>
        <span class="status-badge pending">No students</span>
        <?php elseif ($done >= $all): ?>
        <span class="status-badge graded"><i class="fas fa-check" aria-hidden="true"></i> Taken today</span>
        <?php elseif ($done): ?>
        <span class="status-badge late"><?php echo $done; ?> / <?php echo $all; ?> marked today</span>
        <?php else: ?>
        <span class="status-badge missing">Not taken today</span>
        <?php endif; ?>
        <i class="fas fa-chevron-right" style="color:var(--gray-300);" aria-hidden="true"></i>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
