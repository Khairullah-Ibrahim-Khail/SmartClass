<?php
/**
 * Notifications inbox — everything the system told this user, newest first.
 *   ?filter=unread  only unread
 * POST action=read_all (CSRF) marks everything read.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (($_POST['action'] ?? '') === 'read_all') {
        markAllNotificationsRead($current_user_id);
        Session::flash('notif_done', true);
        redirect(APP_URL . '/pages/notifications/index.php');
    }
}
if (Session::flash('notif_done')) {
    $task_result = ['success', 'All caught up', 'Every notification is marked as read.'];
}

$unread_only = ($_GET['filter'] ?? '') === 'unread';
// Category filter: the user's own notification types grouped like the e-mail categories
$cat_labels = ['classwork' => 'Classwork', 'grades' => 'Grades', 'comments' => 'Messages & comments', 'attendance' => 'Attendance', 'approvals' => 'People & approvals', 'general' => 'Other'];
$cats = [];
foreach ($db->fetchAll("SELECT DISTINCT type FROM notifications WHERE user_id = ?", [$current_user_id]) as $r) $cats[emailCategory($r['type'])][] = $r['type'];
$cat = isset($cats[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['filter' => $unread_only ? 'unread' : '', 'cat' => $cat], $over), fn($v) => $v !== ''));
$per_page = 30;
$page_no = max(1, (int) ($_GET['p'] ?? 1));
$where = 'n.user_id = ?' . ($unread_only ? ' AND n.is_read = 0' : '');
$cat_params = [];
if ($cat) {
    $where .= ' AND n.type IN (' . implode(',', array_fill(0, count($cats[$cat]), '?')) . ')';
    $cat_params = $cats[$cat];
}
$total = (int) $db->fetchOne("SELECT COUNT(*) AS c FROM notifications n WHERE {$where}", array_merge([$current_user_id], $cat_params))['c'];
$pages = max(1, (int) ceil($total / $per_page));
$page_no = min($page_no, $pages);
$offset = ($page_no - 1) * $per_page;

$notifications = $db->fetchAll(
    "SELECT n.*, u.name AS sender_name, c.class_name
     FROM notifications n
     LEFT JOIN users u ON n.sender_id = u.id
     LEFT JOIN classes c ON n.class_id = c.id
     WHERE {$where}
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT {$per_page} OFFSET {$offset}",
    array_merge([$current_user_id], $cat_params)
);
$unread = countUnreadNotifications($current_user_id);

$page_title = 'Notifications';
$page_subtitle = $unread ? $unread . ' unread' : 'You are all caught up';
$active_menu = 'notifications';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", title: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1600, showConfirmButton: false" : "") . " });" : '';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="page-toolbar">
    <div class="tab-pills">
        <a href="index.php<?php echo $qs(['filter' => '']); ?>" class="<?php echo $unread_only ? '' : 'active'; ?>">All</a>
        <a href="index.php<?php echo $qs(['filter' => 'unread']); ?>" class="<?php echo $unread_only ? 'active' : ''; ?>">Unread<?php echo $unread ? ' (' . $unread . ')' : ''; ?></a>
    </div>
    <?php if (count($cats) > 1): ?>
    <div class="pf-chips" role="group" aria-label="Filter by kind">
        <a class="chip<?php echo $cat === '' ? ' active' : ''; ?>" href="index.php<?php echo $qs(['cat' => '']); ?>">Everything</a>
        <?php foreach ($cat_labels as $ck => $cl): if (!isset($cats[$ck])) continue; ?>
        <a class="chip<?php echo $cat === $ck ? ' active' : ''; ?>" href="index.php<?php echo $qs(['cat' => $ck]); ?>"><?php echo $cl; ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($unread): ?>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="read_all">
        <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-check-double"></i> Mark all as read</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div class="empty-state">
    <i class="fas fa-bell-slash"></i>
    <h3><?php echo $unread_only ? 'No unread notifications' : 'No notifications yet'; ?></h3>
    <p style="margin-top:6px;">New lectures, assignments, grades and class activity will show up here.</p>
</div>
<?php else: ?>
<div class="content-card">
    <div class="notif-list">
        <?php foreach ($notifications as $n): [$icon, $tone] = notificationIcon($n['type']); ?>
        <a class="notif-item<?php echo $n['is_read'] ? '' : ' unread'; ?>" href="open.php?id=<?php echo (int) $n['id']; ?>">
            <div class="activity-icon <?php echo $tone; ?>"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i></div>
            <div class="notif-body">
                <p class="notif-title"><?php echo htmlspecialchars($n['title']); ?></p>
                <?php if (!empty($n['message'])): ?><p class="notif-text"><?php echo htmlspecialchars($n['message']); ?></p><?php endif; ?>
                <span class="notif-meta">
                    <?php echo timeAgo($n['created_at']); ?>
                    <?php if (!empty($n['class_name'])): ?> · <?php echo htmlspecialchars($n['class_name']); ?><?php endif; ?>
                    <?php if (!empty($n['sender_name'])): ?> · <?php echo htmlspecialchars($n['sender_name']); ?><?php endif; ?>
                </span>
            </div>
            <?php if (!$n['is_read']): ?><span class="unread-dot" aria-label="Unread"></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php if ($pages > 1): ?>
<div class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="index.php<?php echo htmlspecialchars($qs(['p' => (string) $i])); ?>" class="<?php echo $i === $page_no ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
