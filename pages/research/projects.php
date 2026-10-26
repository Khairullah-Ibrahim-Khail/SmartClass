<?php
/**
 * Research › Projects — the research PROCESS between a student and the class teacher.
 * Student: own projects + "Start a project". Teacher: the projects of their classes, the ones waiting for a decision first.
 *   ?class_id=N  ?status=draft|proposed|changes|active|completed|closed
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$task_result = Session::flash('research_msg');
$teacher = isTeacher();
$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0); if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$statuses = researchStatuses();
$status = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$projects = researchList($current_user_id, $current_user_role, $filter, $status);
$needs = fn($p) => $teacher ? ($p['status'] === 'proposed' || ($p['status'] === 'active' && (int) $p['ms_review'] > 0)) : ($p['status'] === 'changes' || ($p['status'] === 'active' && (int) $db->fetchOne("SELECT COUNT(*) c FROM research_milestones WHERE project_id = ? AND status = 'changes'", [$p['id']])['c'] > 0));
usort($projects, fn($a, $b) => (int) $needs($b) <=> (int) $needs($a));   // what waits for you comes first (stable order otherwise)
$total = count($projects); $per = pagerPerPage(); [$page_no, $pages, $offset] = pagerWindow($total, $per);
$projects = array_slice($projects, $offset, $per);

$page_title = 'Research';
$page_subtitle = $teacher ? 'Research projects of your students — from the proposal to the final grade' : 'Your research projects with your teacher';
$active_menu = 'research';
$research_tab = 'projects';
$page_script = ($task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '');
include dirname(__DIR__, 2) . '/includes/app_header.php';
include __DIR__ . '/_tabs.php';
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form" data-live data-no-ajax id="researchFilter">
        <select name="class_id" aria-label="Class"><option value="0">All my classes</option><?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?></select>
        <select name="status" aria-label="Stage"><option value="">All stages</option><?php foreach ($statuses as $k => [$l]): ?><option value="<?php echo $k; ?>" <?php echo $status === $k ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <?php if (!$teacher): ?><a href="start.php" class="btn-app"><i class="fas fa-plus"></i> Start a project</a><?php endif; ?>
</div>
<div data-live-results>

<?php if (!$projects): ?>
<div class="empty-state"><i class="fas fa-diagram-project"></i>
    <h3>No research projects yet</h3>
    <p style="margin-top:6px;"><?php echo $teacher ? 'When a student sends a proposal, it appears here for your decision.' : 'Choose a topic, write your question and plan, and send it to your teacher. They approve it, set milestones, and guide you to the finish.'; ?></p>
    <?php if (!$teacher): ?><p style="margin-top:14px;"><a href="start.php" class="btn-app"><i class="fas fa-plus"></i> Start a project</a></p><?php endif; ?>
</div>
<?php else: ?>
<div class="rs-list">
    <?php foreach ($projects as $p): [$sl, $sb, $si] = $statuses[$p['status']]; $wait = $needs($p); $pct = (int) $p['ms_total'] ? round($p['ms_done'] / $p['ms_total'] * 100) : 0; ?>
    <a class="rs-card<?php echo $wait ? ' waiting' : ''; ?>" href="<?php echo $p['status'] === 'draft' && !$teacher ? 'start.php?id=' . (int) $p['id'] : 'project.php?id=' . (int) $p['id']; ?>">
        <div class="rs-main">
            <div class="rs-title"><?php echo htmlspecialchars($p['title']); ?></div>
            <div class="rs-meta">
                <?php if ($teacher): ?><span><i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($p['student_name']); ?></span><?php endif; ?>
                <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($p['class_name']); ?></span>
                <?php if ($p['next_due'] && $p['status'] === 'active'): ?><span class="<?php echo $p['next_due'] < date('Y-m-d') ? 'rs-late' : ''; ?>"><i class="fas fa-calendar-day"></i> Next due <?php echo date('M j', strtotime($p['next_due'])); ?></span><?php endif; ?>
                <span><i class="fas fa-clock"></i> <?php echo timeAgo($p['updated_at']); ?></span>
            </div>
            <?php if ((int) $p['ms_total']): ?>
            <div class="rs-progress" role="img" aria-label="<?php echo (int) $p['ms_done']; ?> of <?php echo (int) $p['ms_total']; ?> milestones accepted"><i style="width:<?php echo $pct; ?>%"></i></div>
            <span class="form-hint" style="margin:4px 0 0;"><?php echo (int) $p['ms_done']; ?> / <?php echo (int) $p['ms_total']; ?> milestones accepted</span>
            <?php endif; ?>
        </div>
        <div class="rs-side">
            <span class="status-badge <?php echo $sb; ?>"><i class="fas <?php echo $si; ?>" aria-hidden="true"></i> <?php echo $sl; ?></span>
            <?php if ($wait): ?><span class="rs-flag"><?php echo $teacher ? 'Needs your review' : 'Needs your action'; ?></span><?php endif; ?>
            <?php if ($p['status'] === 'completed' && $p['grade'] !== null): ?><strong class="rs-grade"><?php echo rtrim(rtrim(number_format((float) $p['grade'], 2), '0'), '.'); ?>/100</strong><?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php echo pagerHtml($total, $page_no, $per); ?>
<?php endif; ?>
</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
