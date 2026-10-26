<?php
/**
 * Class — who opened a lecture / resource: ?id=N&type=lecture|resource&item=M  (class teacher only)
 * Every member of the class with: not opened / opened (first + last time, how often) / marked done.
 * Opens are recorded by lectures/view.php (viewing) and resources/download.php (downloading);
 * "done" is set by the student on Classwork or the lecture page.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

if (!$is_owner) {
    redirect(classUrl('classwork'));
}
$type = (string) ($_GET['type'] ?? '');
$item = progressItem($class_id, $type, $_GET['item'] ?? 0);
if (!$item) {
    redirect(classUrl('classwork'));
}
$label = progressItemTypes()[$type][1];
$roster = getItemRoster($class_id, $type, $item['id']);
$opened = count(array_filter($roster, fn($r) => $r['first_opened_at'] !== null));
$done = count(array_filter($roster, fn($r) => $r['done_at'] !== null));
$item_url = $type === 'lecture'
    ? APP_URL . '/pages/lectures/view.php?id=' . (int) $item['id']
    : APP_URL . '/pages/resources/view.php?class_id=' . $class_id . '#res-' . (int) $item['id'];

$page_title = $item['title'] . ' - Student activity';
include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('classwork');
?>

<a class="back-link" href="<?php ob_start(); ?><?php echo classUrl('classwork'); ?>#<?php echo $type . '-' . (int) $item['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Back to Classwork</a>

<div class="content-card">
    <div class="card-head">
        <h2><i class="fas <?php echo $type === 'lecture' ? 'fa-video' : 'fa-folder-open'; ?>"></i> <?php echo htmlspecialchars($item['title']); ?></h2>
        <a class="link-btn" href="<?php echo $item_url; ?>">Open <?php echo strtolower($label); ?> <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="activity-summary">
        <div><strong><?php echo $opened; ?>/<?php echo count($roster); ?></strong><span><?php echo $type === 'lecture' ? 'opened it' : 'downloaded it'; ?></span></div>
        <div><strong><?php echo $done; ?>/<?php echo count($roster); ?></strong><span>marked it done</span></div>
        <div><strong><?php echo count($roster) - $opened; ?></strong><span>not opened yet</span></div>
    </div>

    <?php if (!$roster): ?>
    <p class="form-hint" style="padding:0 20px 18px;">No students in this class yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="app-table">
            <thead><tr><th>Student</th><th>Status</th><th>First opened</th><th>Last opened</th><th>Times</th></tr></thead>
            <tbody>
            <?php foreach ($roster as $r): ?>
            <tr>
                <td><a class="person-link" href="student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo (int) $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></a>
                    <div class="notif-meta"><?php echo htmlspecialchars($r['email']); ?></div></td>
                <td><?php if ($r['done_at']): ?><span class="status-badge graded" title="<?php echo formatDate($r['done_at'], 'M d, h:i A'); ?>"><i class="fas fa-check"></i> Done</span>
                    <?php elseif ($r['first_opened_at']): ?><span class="status-badge submitted">Opened</span>
                    <?php else: ?><span class="status-badge pending">Not opened</span><?php endif; ?></td>
                <td><?php echo $r['first_opened_at'] ? formatDate($r['first_opened_at'], 'M d, h:i A') : '—'; ?></td>
                <td><?php echo $r['last_opened_at'] ? timeAgo($r['last_opened_at']) : '—'; ?></td>
                <td><?php echo (int) $r['open_count']; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
