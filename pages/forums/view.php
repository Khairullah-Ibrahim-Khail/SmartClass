<?php
/**
 * Forums — discussion topics of the user's classes (?class_id=N, ?q=search). Pinned topics first, then latest activity.
 * Students and teachers both start topics and reply; teachers pin / lock / delete (on the topic page).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = Session::flash('forum_msg');
$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$use_ids = $filter ? [$filter] : $class_ids;
$q = trim((string) ($_GET['q'] ?? ''));

$threads = []; $total = 0; $per = pagerPerPage();
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    $params = $use_ids;
    $search = '';
    if ($q !== '') { $search = " AND (t.title LIKE ? OR t.body LIKE ?)"; $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%'; $params[] = $like; $params[] = $like; }
    $total = (int) $db->fetchOne("SELECT COUNT(*) c FROM forum_threads t WHERE t.class_id IN ({$in}){$search}", $params)['c'];
    [$page_no, $pages, $offset] = pagerWindow($total, $per);
    $threads = $db->fetchAll(
        "SELECT t.*, c.class_name, u.name AS author, u.role AS author_role,
                (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id) AS replies
         FROM forum_threads t INNER JOIN classes c ON t.class_id = c.id INNER JOIN users u ON t.user_id = u.id
         WHERE t.class_id IN ({$in}){$search} ORDER BY t.pinned DESC, t.last_post_at DESC LIMIT {$per} OFFSET {$offset}", $params);
}

$page_title = 'Forums';
$page_subtitle = 'Ask questions and discuss with your class';
$active_menu = 'forums';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '');

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form" data-live data-no-ajax>
        <?php if ($classes): ?>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search topics…" aria-label="Search topics" style="min-width:200px;">
        <?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <?php if ($classes): ?><a href="create.php<?php echo $filter ? '?class_id=' . $filter : ''; ?>" class="btn-app"><i class="fas fa-plus"></i> New Topic</a><?php endif; ?>
</div>
<div data-live-results>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-comments"></i><h3>No classes yet</h3></div>
<?php elseif (!$threads): ?>
<div class="empty-state"><i class="fas fa-comments"></i><h3><?php echo $q !== '' ? 'No topics match your search' : 'No topics yet'; ?></h3><p style="margin-top:6px;">Start the first discussion.</p></div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($threads as $t): ?>
    <a class="lecture-card" href="thread.php?id=<?php echo (int) $t['id']; ?>" style="text-decoration:none;color:inherit;">
        <div class="lecture-info">
            <div class="lecture-title"><?php if ($t['pinned']): ?><i class="fas fa-thumbtack" title="Pinned" style="color:var(--primary);"></i> <?php endif; ?><?php echo htmlspecialchars($t['title']); ?>
                <?php if ($t['locked']): ?><span class="status-badge pending"><i class="fas fa-lock"></i> Locked</span><?php endif; ?></div>
            <div class="lecture-meta">
                <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($t['class_name']); ?></span>
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($t['author']); ?><?php echo $t['author_role'] === 'teacher' ? ' (teacher)' : ''; ?></span>
                <span><i class="fas fa-reply"></i> <?php echo (int) $t['replies']; ?> repl<?php echo $t['replies'] == 1 ? 'y' : 'ies'; ?></span>
                <span><i class="fas fa-clock"></i> <?php echo timeAgo($t['last_post_at']); ?></span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php echo pagerHtml($total, $page_no, $per); ?>
<?php endif; ?>
</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
