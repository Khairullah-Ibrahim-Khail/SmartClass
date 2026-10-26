<?php
/**
 * Research — the shared research library of the user's classes: papers, articles, books, datasets, projects.
 *   ?class_id=N, ?kind=paper|article|…, ?q=search (title, authors, summary, tags)
 * Everyone in the class can read and open links; teachers delete items of their classes (POST + CSRF);
 * items are added on add.php. Links open in a new tab with rel="noopener noreferrer".
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$kinds = ['paper' => 'Paper', 'article' => 'Article', 'book' => 'Book', 'dataset' => 'Dataset', 'project' => 'Project', 'other' => 'Other'];
$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can remove research items.'];
    } else {
        $it = $db->fetchOne("SELECT r.id, r.title FROM research_items r INNER JOIN classes c ON r.class_id = c.id WHERE r.id = ? AND " . teachesClassSql('c'), [(int) ($_POST['item_id'] ?? 0), $current_user_id]);
        if (!$it) {
            $task_result = ['error', 'Not found', 'That item is not in one of your classes.'];
        } else {
            $db->delete('research_items', 'id = ?', [$it['id']]);
            logActivity('delete_research', 'research', "Deleted research item #{$it['id']} \"{$it['title']}\"");
            Session::flash('research_msg', ['success', 'Removed', '"' . $it['title'] . '" was removed from the library.']);
            redirect(APP_URL . '/pages/research/view.php');
        }
    }
}
$task_result = Session::flash('research_msg') ?? $task_result;

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$kind = isset($kinds[$_GET['kind'] ?? '']) ? $_GET['kind'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$use_ids = $filter ? [$filter] : $class_ids;

$items = []; $total = 0; $per = pagerPerPage();
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    $params = $use_ids;
    $where = '';
    if ($kind !== '') { $where .= ' AND r.kind = ?'; $params[] = $kind; }
    if ($q !== '') { $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%'; $where .= ' AND (r.title LIKE ? OR r.authors LIKE ? OR r.summary LIKE ? OR r.tags LIKE ?)'; array_push($params, $like, $like, $like, $like); }
    $total = (int) $db->fetchOne("SELECT COUNT(*) c FROM research_items r WHERE r.class_id IN ({$in}){$where}", $params)['c'];
    [$page_no, $pages, $offset] = pagerWindow($total, $per);
    $items = $db->fetchAll("SELECT r.*, c.class_name, u.name AS adder FROM research_items r INNER JOIN classes c ON r.class_id = c.id LEFT JOIN users u ON r.added_by = u.id
                            WHERE r.class_id IN ({$in}){$where} ORDER BY r.created_at DESC, r.id DESC LIMIT {$per} OFFSET {$offset}", $params);
}

$page_title = 'Research';
$page_subtitle = 'Papers, articles, books and datasets shared with your classes';
$active_menu = 'research';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '');
$page_scripts = isTeacher() ? ['assets/js/class.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php $research_tab = 'library'; include __DIR__ . '/_tabs.php'; ?>
<div class="page-toolbar">
    <form method="GET" class="filter-form" data-live data-no-ajax id="researchFilter">
        <?php if ($classes): ?>
        <select name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <select name="kind" aria-label="Filter by type">
            <option value="">All types</option>
            <?php foreach ($kinds as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo $kind === $k ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search title, author, tag…" aria-label="Search research" style="min-width:220px;">
        <?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <?php if (isTeacher() && $classes): ?><a href="add.php<?php echo $filter ? '?class_id=' . $filter : ''; ?>" class="btn-app"><i class="fas fa-plus"></i> Add Research</a><?php endif; ?>
</div>
<div data-live-results>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-flask"></i><h3>No classes yet</h3></div>
<?php elseif (!$items): ?>
<div class="empty-state"><i class="fas fa-flask"></i><h3><?php echo ($q !== '' || $kind !== '') ? 'Nothing matches' : 'The library is empty'; ?></h3></div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($items as $r): ?>
    <div class="lecture-card">
        <div class="lecture-info">
            <div class="lecture-title"><?php echo htmlspecialchars($r['title']); ?> <span class="status-badge submitted"><?php echo $kinds[$r['kind']]; ?></span></div>
            <div class="lecture-meta">
                <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($r['class_name']); ?></span>
                <?php if ($r['authors']): ?><span><i class="fas fa-user-edit"></i> <?php echo htmlspecialchars($r['authors']); ?><?php echo $r['year'] ? ' (' . (int) $r['year'] . ')' : ''; ?></span><?php elseif ($r['year']): ?><span><i class="fas fa-calendar"></i> <?php echo (int) $r['year']; ?></span><?php endif; ?>
                <?php if ($r['tags']): foreach (array_filter(array_map('trim', explode(',', $r['tags']))) as $tg): ?><span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($tg); ?></span><?php endforeach; endif; ?>
            </div>
            <?php if ($r['summary']): ?><div class="prewrap" style="margin-top:6px;"><?php echo htmlspecialchars($r['summary']); ?></div><?php endif; ?>
        </div>
        <div class="lecture-actions">
            <?php if ($r['url']): ?><a class="btn-app btn-sm" href="<?php echo htmlspecialchars($r['url']); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt"></i> Open</a><?php endif; ?>
            <?php if (isTeacher()): ?>
            <a class="icon-btn" href="add.php?id=<?php echo (int) $r['id']; ?>" title="Edit" aria-label="Edit <?php echo htmlspecialchars($r['title']); ?>"><i class="fas fa-pen"></i></a>
            <form method="POST" data-confirm="Remove this item?" data-confirm-text="Students will no longer see it." data-confirm-button="Remove">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="item_id" value="<?php echo (int) $r['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Remove" aria-label="Remove <?php echo htmlspecialchars($r['title']); ?>"><i class="fas fa-trash"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php echo pagerHtml($total, $page_no, $per); ?>
<?php endif; ?>
</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
