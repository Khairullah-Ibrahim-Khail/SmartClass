<?php
/**
 * One assignment, teacher side. Included by view.php (which holds every POST handler: delete, release, bulk, remind).
 * Two tabs: "Student work" (what needs me, the numbers, the class list with a Grade button per student → grade.php) and "Instructions" (what the students see).
 * Needs: $assignment $class $roster $materials $rubric $past_due $submitted_count $graded_count $status_labels
 */
$aid = (int) $assignment['id']; $max = (int) $assignment['max_marks'];
$tab = ($_GET['tab'] ?? '') === 'instructions' ? 'instructions' : 'work';
$filter = in_array($_GET['f'] ?? '', ['todo', 'missing', 'graded', 'returned'], true) ? $_GET['f'] : 'all';
$counts = ['all' => count($roster), 'todo' => 0, 'missing' => 0, 'graded' => 0, 'returned' => 0, 'turned_in' => 0];
$sum = 0.0; $bucket = [0, 0, 0, 0];
foreach ($roster as $i => $r) {
    $roster[$i]['st'] = $st = submissionStatus($r['submission_id'] ? $r : null, $assignment['due_date']);
    if ($r['submission_id'] && $st !== 'returned') $counts['turned_in']++;
    if (in_array($st, ['submitted', 'late'], true)) $counts['todo']++;
    elseif (isset($counts[$st])) $counts[$st]++;
    if ($st === 'graded' && $max > 0) { $pct = (float) $r['marks_obtained'] / $max * 100; $sum += $pct; $bucket[$pct >= 80 ? 3 : ($pct >= 60 ? 2 : ($pct >= 40 ? 1 : 0))]++; }
}
$avg = $counts['graded'] ? round($sum / $counts['graded']) : null;
$shown = array_values(array_filter($roster, fn($r) => $filter === 'all' || ($filter === 'todo' ? in_array($r['st'], ['submitted', 'late'], true) : $r['st'] === $filter)));
$base = APP_URL . '/pages/assignments/';
$gradeUrl = fn($sid = null, $f = 'all') => $base . 'grade.php?id=' . $aid . ($sid ? '&amp;s=' . (int) $sid : '') . '&amp;f=' . $f;   // only used in href="…"
$tabUrl = fn($t, $f = null) => $base . 'view.php?id=' . $aid . '&amp;tab=' . $t . ($f && $f !== 'all' ? '&amp;f=' . $f : '');
$released = resultsReleased($assignment);
$held_n = $assignment['release_mode'] === 'manual' && !$released ? heldResultCount('assignment', $assignment['id']) : 0;
$missing_n = count(array_filter($roster, fn($x) => !$x['submission_id']));
$due_txt = $assignment['due_date'] ? formatDate($assignment['due_date'], 'M d, Y h:i A') : null;
?>
<header class="asg-head">
    <a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $class['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> <?php echo t('All assignments in'); ?> <?php echo htmlspecialchars($class['class_name']); ?></a>
    <div class="asg-head-row">
        <div class="asg-title"><h1><?php echo htmlspecialchars($assignment['title']); ?></h1>
            <div class="asg-chips"><span class="chip-i"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($class['class_name']); ?></span>
                <span class="chip-i"><i class="fas fa-star"></i> <?php echo $max; ?> <?php echo t('marks'); ?></span>
                <span class="chip-i<?php echo $past_due ? ' bad' : ''; ?>"><i class="fas fa-clock"></i> <?php echo $due_txt ? ($past_due ? t('Closed') . ' · ' : t('Due') . ' ') . $due_txt : t('No due date'); ?></span></div></div>
        <div class="asg-actions">
            <a class="btn-app" href="<?php echo $gradeUrl($counts['todo'] ? null : null, $counts['todo'] ? 'todo' : 'all'); ?>"><i class="fas fa-pen-to-square"></i> <?php echo t('Grade work'); ?><?php echo $counts['todo'] ? ' <span class="count-pill">' . $counts['todo'] . '</span>' : ''; ?></a>
            <a href="create.php?id=<?php echo $aid; ?>" class="btn-app btn-outline"><i class="fas fa-pen"></i> <?php echo t('Edit'); ?></a>
            <form method="POST" id="deleteAssignment"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_assignment">
                <button type="submit" class="btn-app btn-outline btn-danger-outline" title="<?php echo htmlspecialchars(t('Delete')); ?>"><i class="fas fa-trash"></i></button></form>
        </div>
    </div>
    <nav class="asg-tabs" aria-label="<?php echo htmlspecialchars(t('Assignment')); ?>">
        <a class="asg-tab<?php echo $tab === 'work' ? ' on' : ''; ?>" href="<?php echo $tabUrl('work'); ?>"><i class="fas fa-users"></i> <?php echo t('Student work'); ?><?php echo $counts['todo'] ? ' <b>' . $counts['todo'] . '</b>' : ''; ?></a>
        <a class="asg-tab<?php echo $tab === 'instructions' ? ' on' : ''; ?>" href="<?php echo $tabUrl('instructions'); ?>"><i class="fas fa-file-lines"></i> <?php echo t('Instructions'); ?></a>
    </nav>
</header>

<?php if ($tab === 'instructions'): ?>
<!-- ============ Instructions: what the students see ============ -->
<section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-file-lines"></i> <?php echo t('Instructions'); ?></h2></div>
    <div class="card-inner"><?php if (trim((string) $assignment['description']) !== ''): ?><div class="asg-text"><?php echo richText($assignment['description']); ?></div>
        <?php else: ?><p class="form-hint" style="margin:0;"><?php echo t('No instructions were written.'); ?> <a href="create.php?id=<?php echo $aid; ?>"><?php echo t('Add them'); ?></a></p><?php endif; ?></div></section>
<?php if ($materials): ?>
<section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-paperclip"></i> <?php echo t('Materials'); ?></h2></div>
    <div class="materials-list"><?php foreach ($materials as $m): ?>
        <?php if ($m['kind'] === 'resource'): ?>
        <a class="material-item" href="<?php echo APP_URL; ?>/pages/resources/download.php?id=<?php echo (int) $m['resource_id']; ?>"><i class="fas fa-file-alt" aria-hidden="true"></i>
            <span class="m-text"><strong><?php echo htmlspecialchars($m['resource_title']); ?></strong><span class="notif-meta"><?php echo t('Resource'); ?> · <?php echo htmlspecialchars(strtoupper((string) $m['file_type'])); ?> · <?php echo formatBytes($m['file_size']); ?></span></span></a>
        <?php elseif (in_array(strtolower((string) parse_url($m['url'], PHP_URL_SCHEME)), ['http', 'https'], true)): ?>
        <a class="material-item link" href="<?php echo htmlspecialchars($m['url']); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-link" aria-hidden="true"></i>
            <span class="m-text"><strong><?php echo htmlspecialchars($m['title'] ?: (parse_url($m['url'], PHP_URL_HOST) ?: $m['url'])); ?></strong><span class="notif-meta"><?php echo htmlspecialchars($m['url']); ?></span></span></a>
        <?php endif; ?><?php endforeach; ?></div></section>
<?php endif; ?>
<?php if ($rubric): ?>
<section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-clipboard-list"></i> <?php echo t('Rubric'); ?></h2><span class="form-hint" style="margin:0;"><?php echo $max; ?> <?php echo t('marks'); ?></span></div>
    <div class="rubric-view"><?php foreach ($rubric as $c): ?>
        <div class="rubric-view-row"><div><strong><?php echo htmlspecialchars($c['title']); ?></strong><?php echo $c['description'] !== '' ? '<div class="notif-meta">' . htmlspecialchars($c['description']) . '</div>' : ''; ?></div>
            <span class="status-badge submitted"><?php echo (int) $c['points']; ?> <?php echo t('pts'); ?></span></div><?php endforeach; ?></div></section>
<?php endif; ?>

<?php else: ?>
<!-- ============ Student work ============ -->
<?php if (!$roster): ?>
<div class="empty-state"><i class="fas fa-user-graduate"></i><h3><?php echo t('No students yet'); ?></h3><p><?php echo t('No students are enrolled in this class yet.'); ?></p></div>
<?php else: ?>
<section class="asg-stats" aria-label="<?php echo htmlspecialchars(t('Summary')); ?>">
    <a class="stat-tile<?php echo $counts['todo'] ? ' hot' : ''; ?>" href="<?php echo $gradeUrl(null, 'todo'); ?>"><span class="n"><?php echo $counts['todo']; ?></span><span class="l"><?php echo t('To grade'); ?></span><small><?php echo $counts['todo'] ? t('Waiting for you') : t('Nothing waiting'); ?></small></a>
    <a class="stat-tile" href="<?php echo $tabUrl('work', 'all'); ?>"><span class="n"><?php echo $counts['turned_in']; ?><i>/<?php echo $counts['all']; ?></i></span><span class="l"><?php echo t('Handed in'); ?></span><small><?php echo $missing_n ? $missing_n . ' ' . t($past_due ? 'missing' : 'not yet') : t('Everyone'); ?></small></a>
    <a class="stat-tile" href="<?php echo $gradeUrl(null, 'graded'); ?>"><span class="n"><?php echo $counts['graded']; ?></span><span class="l"><?php echo t('Graded'); ?></span><small><?php echo $avg !== null ? t('Average') . ' ' . $avg . '%' : t('No grades yet'); ?></small></a>
    <a class="stat-tile<?php echo $counts['returned'] ? ' wait' : ''; ?>" href="<?php echo $gradeUrl(null, 'returned'); ?>"><span class="n"><?php echo $counts['returned']; ?></span><span class="l"><?php echo t('Returned'); ?></span><small><?php echo t('Waiting for the student'); ?></small></a>
</section>

<div class="asg-two">
    <section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-chart-simple"></i> <?php echo t('How the class did'); ?></h2></div>
        <div class="card-inner"><?php if (!$counts['graded']): ?><p class="form-hint" style="margin:0;"><?php echo t('Grades appear here as you mark the work.'); ?></p><?php else:
            $top = max(1, max($bucket)); foreach ([['80–100%', 3, 'ok'], ['60–79%', 2, 'good'], ['40–59%', 1, 'mid'], ['0–39%', 0, 'low']] as [$lab, $i, $cls]): ?>
            <div class="dist-row"><span class="dist-l"><?php echo $lab; ?></span><span class="dist-bar"><i class="<?php echo $cls; ?>" style="width:<?php echo round($bucket[$i] / $top * 100); ?>%"></i></span><span class="dist-n"><?php echo $bucket[$i]; ?></span></div>
            <?php endforeach; endif; ?></div></section>

    <section class="content-card asg-card release-card<?php echo $assignment['release_mode'] === 'manual' && !$released ? ' is-held' : ''; ?>"><div class="card-head"><h2><i class="fas fa-bullhorn"></i> <?php echo t('Results'); ?></h2></div>
        <div class="card-inner">
            <p class="form-hint" style="margin:0 0 10px;"><?php if ($assignment['release_mode'] === 'instant'): echo t('Students see their grade as soon as it is graded.');
                elseif (!$released): echo $held_n . ' ' . t($held_n == 1 ? 'graded result is hidden from students until you release it.' : 'graded results are hidden from students until you release them.');
                else: echo t('Released on') . ' ' . formatDate($assignment['results_released_at'], 'M d, Y h:i A') . ' — ' . t('new grades are visible at once.'); endif; ?></p>
            <div class="release-actions">
                <form method="POST" class="release-mode-form"><?php echo csrfField(); ?><input type="hidden" name="action" value="set_release_mode">
                    <select name="release_mode" aria-label="<?php echo htmlspecialchars(t('When students see their grade')); ?>" data-autosubmit>
                        <?php foreach (releaseModes() as $k => [$label]): ?><option value="<?php echo $k; ?>" <?php echo $assignment['release_mode'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select></form>
                <?php if ($assignment['release_mode'] === 'manual' && !$released): ?>
                <form method="POST" data-confirm="Release the results?" data-confirm-text="Every student with a grade is notified now (in the app and by email) and can see it. Grades you give later are visible at once." data-confirm-button="Release">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="release_results"><button type="submit" class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> <?php echo t('Release results'); ?></button></form>
                <?php endif; ?></div></div></section>
</div>

<section class="content-card asg-card">
    <div class="card-head asg-list-head"><h2><i class="fas fa-users"></i> <?php echo t('Students'); ?></h2>
        <div class="asg-chips-f"><?php foreach (['all' => 'All', 'todo' => 'To grade', 'missing' => 'Missing', 'graded' => 'Graded', 'returned' => 'Returned'] as $k => $lab): ?>
            <a class="grade-tab<?php echo $filter === $k ? ' on' : ''; ?>" href="<?php echo $tabUrl('work', $k); ?>"><?php echo t($lab); ?> <b><?php echo $counts[$k]; ?></b></a><?php endforeach; ?></div>
        <input type="search" id="asgFind" class="grade-find" style="max-width:220px;margin:0;" placeholder="<?php echo htmlspecialchars(t('Find a student')); ?>" aria-label="<?php echo htmlspecialchars(t('Find a student')); ?>" autocomplete="off"></div>
    <div class="bulk-bar">
        <span id="bulkCount" class="form-hint" style="margin:0;"><?php echo t('Tick submissions to grade them together'); ?></span>
        <button type="button" class="btn-app btn-sm btn-outline" id="bulkGradeBtn" data-max="<?php echo $max; ?>" disabled><i class="fas fa-check-double"></i> <?php echo t('Give marks to selected'); ?></button>
        <button type="button" class="btn-app btn-sm btn-outline" id="remindBtn" data-missing="<?php echo $missing_n; ?>" <?php echo $missing_n ? '' : 'disabled'; ?>><i class="fas fa-bell"></i> Remind <?php echo $missing_n; ?> student<?php echo $missing_n == 1 ? '' : 's'; ?> who did not hand in</button>
    </div>
    <form method="POST" id="bulkGradeForm"><?php echo csrfField(); ?><input type="hidden" name="action" value="bulk_grade"><input type="hidden" name="marks"><input type="hidden" name="feedback"></form>
    <form method="POST" id="remindForm"><?php echo csrfField(); ?><input type="hidden" name="action" value="remind_missing"><input type="hidden" name="note"></form>
    <div class="table-wrap">
        <table class="app-table asg-table stack-table" id="asgTable">
            <thead><tr><th style="width:34px;"><input type="checkbox" id="bulkAll" aria-label="<?php echo htmlspecialchars(t('Select all submissions')); ?>"></th><th><?php echo t('Student'); ?></th><th><?php echo t('Status'); ?></th><th><?php echo t('Work'); ?></th><th><?php echo t('Marks'); ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($shown as $r): $st = $r['st']; ?>
            <tr data-name="<?php echo htmlspecialchars(mb_strtolower($r['name'])); ?>">
                <td><?php if ($r['submission_id']): ?><input type="checkbox" class="bulk-pick" form="bulkGradeForm" name="ids[]" value="<?php echo (int) $r['submission_id']; ?>" aria-label="<?php echo htmlspecialchars(t('Select')); ?> <?php echo htmlspecialchars($r['name']); ?>"><?php endif; ?></td>
                <td><span class="asg-who"><?php echo avatarHtml($r['student_id'], $r['name']); ?> <span><strong><?php echo htmlspecialchars($r['name']); ?></strong><small><?php echo htmlspecialchars($r['email']); ?></small></span></span></td>
                <td data-label="<?php echo htmlspecialchars(t('Status')); ?>"><span class="status-badge <?php echo $st; ?>"><?php echo t($status_labels[$st]); ?></span><?php if ($r['submitted_at']): ?><div class="form-hint" style="margin:2px 0 0;"><?php echo formatDate($r['submitted_at'], 'M d, h:i A'); ?><?php echo (int) ($r['revision'] ?? 1) > 1 ? ' · v' . (int) $r['revision'] : ''; ?></div><?php endif; ?></td>
                <td data-label="<?php echo htmlspecialchars(t('Work')); ?>"><?php if (!empty($r['file_path'])): echo submissionFileHtml($r['submission_id'], $r['original_name'], $r['file_path']); endif;
                    if (!empty($r['content'])): ?><div class="asg-snippet"><?php echo htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', $r['content']), 0, 90, '…')); ?></div><?php endif;
                    if (!$r['submission_id']): ?><span class="form-hint" style="margin:0;">—</span><?php endif; ?></td>
                <td data-label="<?php echo htmlspecialchars(t('Marks')); ?>"><?php echo $st === 'graded' ? '<strong>' . formatMarks($r['marks_obtained']) . '</strong><span class="form-hint" style="display:inline;margin:0;"> / ' . $max . '</span>' : '<span class="form-hint" style="margin:0;">—</span>'; ?></td>
                <td class="nowrap"><?php if ($r['submission_id']): ?><a class="btn-app btn-sm<?php echo in_array($st, ['submitted', 'late'], true) ? '' : ' btn-outline'; ?>" href="<?php echo $gradeUrl($r['student_id'], $filter); ?>"><?php echo t(in_array($st, ['submitted', 'late'], true) ? 'Grade' : 'Open'); ?> <i class="fas fa-arrow-right"></i></a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$shown): ?><tr><td colspan="6"><p class="form-hint" style="margin:6px 0;"><?php echo t('Nobody here.'); ?></p></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
