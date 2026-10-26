<?php
/**
 * Announcements — everything announced in the user's classes, newest first (pinned first).
 *   ?class_id=N   one class.   Students see only posts that are already published.
 * Teachers can delete their own posts here (POST + CSRF); posting is on create.php (several classes at once)
 * or in a class stream.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can delete announcements.'];
    } else {
        $ann = $db->fetchOne(
            "SELECT a.id, a.class_id FROM announcements a INNER JOIN classes c ON a.class_id = c.id
             WHERE a.id = ? AND " . teachesClassSql('c'), [(int) ($_POST['announcement_id'] ?? 0), $current_user_id]);
        if (!$ann) {
            $task_result = ['error', 'Not found', 'That announcement is not in one of your classes.'];
        } else {
            $db->delete('announcements', 'id = ?', [$ann['id']]);
            logActivity('delete_announcement', 'announcements', "Deleted announcement #{$ann['id']}");
            Session::flash('ann_msg', ['success', 'Deleted', 'The announcement and its comments were removed.']);
            redirect(APP_URL . '/pages/announcements/view.php' . (!empty($_GET['class_id']) ? '?class_id=' . (int) $_GET['class_id'] : ''));
        }
    }
}
$task_result = Session::flash('ann_msg') ?? $task_result;

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$use_ids = $filter ? [$filter] : $class_ids;

$q = trim((string) ($_GET['q'] ?? ''));
$posts = []; $total = 0; $per = pagerPerPage();
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    $params = $use_ids; $search = '';
    if ($q !== '') { $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%'; $search = ' AND a.body LIKE ?'; $params[] = $like; }
    $vis = isTeacher() ? '' : ' AND (a.publish_at IS NULL OR a.publish_at <= NOW())';
    $total = (int) $db->fetchOne("SELECT COUNT(*) c FROM announcements a WHERE a.class_id IN ({$in}){$vis}{$search}", $params)['c'];
    [$page_no, $pages, $offset] = pagerWindow($total, $per);
    $posts = $db->fetchAll(
        "SELECT a.*, c.class_name, u.name AS author, u.role AS author_role,
                (SELECT COUNT(*) FROM announcement_comments x WHERE x.announcement_id = a.id) AS comment_count,
                (a.publish_at IS NOT NULL AND a.publish_at > NOW()) AS scheduled
         FROM announcements a INNER JOIN classes c ON a.class_id = c.id INNER JOIN users u ON a.user_id = u.id
         WHERE a.class_id IN ({$in}){$vis}{$search}
         ORDER BY a.pinned DESC, COALESCE(a.publish_at, a.created_at) DESC, a.id DESC LIMIT {$per} OFFSET {$offset}", $params);
}

$page_title = 'Announcements';
$page_subtitle = isTeacher() ? 'What you announced in your classes' : 'What your teachers announced';
$active_menu = 'announcements';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_script .= "";
$page_scripts = isTeacher() ? ['assets/js/class.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="page-toolbar">
    <?php if ($classes): ?>
    <form method="GET" class="filter-form" data-live data-no-ajax>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search announcements…" aria-label="Search announcements" style="min-width:200px;">
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <?php else: ?><span></span><?php endif; ?>
    <?php if (isTeacher() && $classes): ?>
    <a href="create.php" class="btn-app"><i class="fas fa-plus"></i> New Announcement</a>
    <?php endif; ?>
</div>
<div data-live-results>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-bullhorn"></i><h3>No classes yet</h3>
    <p style="margin-top:6px;"><?php echo isTeacher() ? 'Create a class to announce things to its students.' : 'Join a class to see its announcements.'; ?></p></div>
<?php elseif (!$posts): ?>
<div class="empty-state"><i class="fas fa-bullhorn"></i><h3><?php echo $q !== '' ? 'No announcements match your search' : 'Nothing announced yet'; ?></h3></div>
<?php else: foreach ($posts as $a): ?>
<article class="content-card stream-post<?php echo $a['pinned'] ? ' pinned' : ''; ?><?php echo $a['scheduled'] ? ' scheduled' : ''; ?>">
    <header class="post-head">
        <span class="avatar<?php echo $a['author_role'] === 'teacher' ? ' teacher' : ''; ?>" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($a['author'], 0, 1))); ?></span>
        <div>
            <strong><?php echo htmlspecialchars($a['author']); ?></strong>
            <span class="role-tag"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($a['class_name']); ?></span>
            <?php if ($a['scheduled']): ?><div class="scheduled-tag"><i class="fas fa-clock"></i> Scheduled for <?php echo formatDate($a['publish_at'], 'M d, Y · h:i A'); ?></div><?php endif; ?>
            <div class="notif-meta"><?php echo formatDate($a['publish_at'] && !$a['scheduled'] ? $a['publish_at'] : $a['created_at'], 'M d, Y · h:i A'); ?>
                <?php if ($a['pinned']): ?> · <i class="fas fa-thumbtack"></i> Pinned<?php endif; ?><?php if ($a['edited_at']): ?> · <span title="Edited <?php echo formatDate($a['edited_at'], 'M d, Y · h:i A'); ?>">Edited</span><?php endif; ?></div>
        </div>
        <div class="post-tools">
            <a class="icon-btn" href="<?php echo APP_URL; ?>/pages/classes/stream.php?id=<?php echo (int) $a['class_id']; ?>#post-<?php echo (int) $a['id']; ?>" title="Open in the class<?php echo $a['comment_count'] ? ' (' . (int) $a['comment_count'] . ' comments)' : ''; ?>" aria-label="Open in the class"><i class="fas fa-comments"></i></a>
            <?php echo isTeacher() ? waButton(waShareUrl('announcement', ['class' => $a['class_name'], 'text' => mb_strimwidth(trim(strip_tags((string) $a['body'])), 0, 600, '…')], null, $current_user_id), 'Share on WhatsApp') : ''; ?>
            <?php if (isTeacher() && (int) $a['user_id'] === (int) $current_user_id): ?>
            <a class="icon-btn" href="edit.php?id=<?php echo (int) $a['id']; ?>" title="Edit" aria-label="Edit announcement"><i class="fas fa-pen"></i></a>
            <?php endif; ?>
            <?php if (isTeacher()): ?>
            <form method="POST" data-confirm="Delete this announcement?" data-confirm-text="Its comments will be deleted too." data-confirm-button="Delete">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Delete" aria-label="Delete announcement"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </header>
    <div class="post-body prewrap"><?php echo linkify($a['body']); ?></div>
</article>
<?php endforeach; echo pagerHtml($total, $page_no, $per); endif; ?>
</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
