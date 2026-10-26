<?php
/**
 * One assignment, student side. Included by view.php.
 * Left: what to do (instructions, materials, rubric). Right: "Your work" — one clear state (not handed in / handed in / returned for changes / graded) and one clear button.
 * Needs: $assignment $class $mine $materials $rubric $past_due $status_labels $threads $pc_errors $current_user_id
 */
$aid = (int) $assignment['id']; $max = (int) $assignment['max_marks'];
$st = submissionStatus($mine, $assignment['due_date']);
$released = resultsReleased($assignment);
$held = $st === 'graded' && !$released;
$due_txt = $assignment['due_date'] ? formatDate($assignment['due_date'], 'M d, Y h:i A') : null;
$base = APP_URL . '/pages/assignments/';
$pct = ($st === 'graded' && $released && $max > 0) ? round((float) $mine['marks_obtained'] / $max * 100) : null;
?>
<header class="asg-head">
    <a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $class['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> <?php echo t('All assignments in'); ?> <?php echo htmlspecialchars($class['class_name']); ?></a>
    <div class="asg-head-row"><div class="asg-title"><h1><?php echo htmlspecialchars($assignment['title']); ?></h1>
        <div class="asg-chips"><span class="chip-i"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($class['class_name']); ?></span>
            <span class="chip-i"><i class="fas fa-user"></i> <?php echo htmlspecialchars($class['teacher_name']); ?></span>
            <span class="chip-i"><i class="fas fa-star"></i> <?php echo $max; ?> <?php echo t('marks'); ?></span>
            <span class="chip-i<?php echo $past_due && !$mine ? ' bad' : ''; ?>"><i class="fas fa-clock"></i> <?php echo $due_txt ? ($past_due ? t('Closed') . ' · ' : t('Due') . ' ') . $due_txt : t('No due date'); ?></span></div></div></div>
</header>

<div class="asg-cols">
    <div class="asg-main">
        <section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-file-lines"></i> <?php echo t('Instructions'); ?></h2></div>
            <div class="card-inner"><?php if (trim((string) $assignment['description']) !== ''): ?><div class="asg-text"><?php echo richText($assignment['description']); ?></div>
                <?php else: ?><p class="form-hint" style="margin:0;"><?php echo t('Your teacher did not write instructions.'); ?></p><?php endif; ?></div></section>
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
        <section class="content-card asg-card"><div class="card-head"><h2><i class="fas fa-clipboard-list"></i> <?php echo t('How it is graded'); ?></h2><span class="form-hint" style="margin:0;"><?php echo $max; ?> <?php echo t('marks'); ?></span></div>
            <div class="rubric-view"><?php foreach ($rubric as $c): ?>
                <div class="rubric-view-row"><div><strong><?php echo htmlspecialchars($c['title']); ?></strong><?php echo $c['description'] !== '' ? '<div class="notif-meta">' . htmlspecialchars($c['description']) . '</div>' : ''; ?></div>
                    <span class="status-badge submitted"><?php echo (int) $c['points']; ?> <?php echo t('pts'); ?></span></div><?php endforeach; ?></div></section>
        <?php endif; ?>
        <section class="content-card asg-card" id="comments"><div class="card-head"><h2><i class="fas fa-user-lock"></i> <?php echo t('Private comments'); ?></h2><span class="form-hint" style="margin:0;"><?php echo t('Only you and your teacher'); ?></span></div>
            <div class="card-inner"><?php echo privateThread($threads[$current_user_id] ?? [], $current_user_id, $pc_errors[$current_user_id] ?? ''); ?></div></section>
    </div>

    <aside class="asg-side">
        <section class="content-card asg-card your-work state-<?php echo $held ? 'held' : $st; ?>">
            <div class="card-head"><h2><i class="fas fa-file-circle-check"></i> <?php echo t('Your work'); ?></h2><span class="status-badge <?php echo $held ? 'held' : $st; ?>"><?php echo t($status_labels[$held ? 'held' : $st]); ?></span></div>
            <div class="card-inner">
            <?php if ($st === 'returned'): ?>
                <div class="grade-banner wait" style="margin-bottom:12px;"><i class="fas fa-rotate-left"></i><div><strong><?php echo t('Your teacher asked for changes'); ?></strong>
                    <div class="prewrap"><?php echo htmlspecialchars((string) $mine['return_note']); ?></div></div></div>
            <?php elseif ($st === 'graded' && $released): ?>
                <div class="my-grade"><span class="big"><?php echo formatMarks($mine['marks_obtained']); ?></span><span class="of">/ <?php echo $max; ?></span><?php echo $pct !== null ? '<span class="pct">' . $pct . '%</span>' : ''; ?></div>
                <?php if (!empty($mine['feedback'])): ?><div class="my-feedback"><strong><?php echo t('Feedback'); ?></strong><div class="prewrap"><?php echo htmlspecialchars($mine['feedback']); ?></div></div><?php endif; ?>
            <?php elseif ($held): ?>
                <p class="form-hint" style="margin:0 0 10px;"><i class="fas fa-hourglass-half"></i> <?php echo t('Your work has been graded. Your teacher will release the results soon — you will get a notification and an email.'); ?></p>
            <?php elseif ($st === 'missing'): ?>
                <p class="form-hint" style="margin:0 0 10px;"><?php echo t('The due date has passed and nothing was handed in. You can still hand it in late.'); ?></p>
            <?php elseif ($st === 'pending'): ?>
                <p class="form-hint" style="margin:0 0 10px;"><?php echo t('Nothing handed in yet.'); ?></p>
            <?php endif; ?>

            <?php if ($mine): ?>
                <div class="my-sub"><div class="form-hint" style="margin:0 0 6px;"><?php echo t('Handed in'); ?> <?php echo formatDate($mine['submitted_at'], 'M d, h:i A'); ?><?php echo $mine['status'] === 'late' ? ' · ' . t('late') : ''; ?><?php echo (int) $mine['revision'] > 1 ? ' · ' . t('version') . ' ' . (int) $mine['revision'] : ''; ?></div>
                    <?php if (!empty($mine['content'])): ?><div class="prewrap my-answer"><?php echo htmlspecialchars($mine['content']); ?></div><?php endif; ?>
                    <?php if (!empty($mine['file_path'])): echo submissionFileHtml($mine['id'], $mine['original_name'] ?? 'Attached file', $mine['file_path'], '', false); endif; ?></div>
            <?php endif; ?>

            <?php if ($st !== 'graded'): ?>
                <a href="submit.php?id=<?php echo $aid; ?>" class="btn-app asg-cta"><i class="fas fa-paper-plane"></i>
                    <?php echo t($st === 'returned' ? 'Fix it and hand in again' : ($mine ? 'Edit and hand in again' : ($past_due ? 'Hand in late' : 'Hand in'))); ?></a>
            <?php endif; ?>
            </div>
        </section>
    </aside>
</div>
