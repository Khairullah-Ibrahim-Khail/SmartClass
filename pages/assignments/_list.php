<?php
/**
 * The assignment list. Included by view.php.
 * Teacher: grouped by what needs doing — "Needs grading" first, then "Open", then "Closed" — each card shows how far the class is (graded / to grade / returned / missing) and one clear button.
 * Student: "Overdue", "Returned for changes", "To do", "Handed in", "Done" — each card shows the due date in words and one clear button.
 * Needs: $assignments $classes $class
 */
$teacher = isTeacher(); $now = time();
$groups = $teacher
    ? ['grade' => ['Needs grading', 'fa-pen-to-square', 'hot'], 'open' => ['Open', 'fa-hourglass-half', ''], 'closed' => ['Closed', 'fa-lock', '']]
    : ['overdue' => ['Overdue', 'fa-triangle-exclamation', 'bad'], 'returned' => ['Returned for changes', 'fa-rotate-left', 'wait'], 'todo' => ['To do', 'fa-list-check', 'hot'], 'handed' => ['Handed in', 'fa-inbox', ''], 'done' => ['Done', 'fa-circle-check', '']];
$bucket = array_fill_keys(array_keys($groups), []);
foreach ($assignments as $a) {
    $past = !empty($a['due_date']) && $now > strtotime($a['due_date']);
    if ($teacher) { $g = (int) $a['todo_count'] > 0 ? 'grade' : ($past ? 'closed' : 'open'); }
    else {
        $stt = $a['submission_id'] ? $a['sub_status'] : null;
        $g = $stt === 'returned' ? 'returned' : ($stt === 'graded' ? 'done' : ($stt ? 'handed' : ($past ? 'overdue' : 'todo')));
    }
    $bucket[$g][] = $a;
}
foreach ($bucket as $g => &$list) {   // soonest due first; closed ones latest first
    usort($list, fn($x, $y) => ($g === 'closed' || $g === 'done') ? strcmp((string) $y['due_date'], (string) $x['due_date']) : strcmp($x['due_date'] ?? '9999', $y['due_date'] ?? '9999'));
}
unset($list);
$total = count($assignments);
?>
<div class="asg-list-top">
    <div class="asg-chips-f" id="asgGroups"><a class="grade-tab on" href="#" data-g="all"><?php echo t('All'); ?> <b><?php echo $total; ?></b></a>
        <?php foreach ($groups as $g => [$lab]): if (!$bucket[$g]) continue; ?><a class="grade-tab" href="#" data-g="<?php echo $g; ?>"><?php echo t($lab); ?> <b><?php echo count($bucket[$g]); ?></b></a><?php endforeach; ?></div>
    <input type="search" id="asgSearch" class="grade-find" style="max-width:240px;margin:0;" placeholder="<?php echo htmlspecialchars(t('Search assignments')); ?>" aria-label="<?php echo htmlspecialchars(t('Search assignments')); ?>" autocomplete="off">
</div>

<?php foreach ($groups as $g => [$lab, $icon, $tone]): if (!$bucket[$g]) continue; ?>
<section class="asg-group" data-g="<?php echo $g; ?>">
    <h2 class="asg-group-h <?php echo $tone; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo t($lab); ?> <span><?php echo count($bucket[$g]); ?></span></h2>
    <div class="asg-cards">
    <?php foreach ($bucket[$g] as $a):
        $past = !empty($a['due_date']) && $now > strtotime($a['due_date']);
        $cls = trim(($a['due_date'] && $past && $g !== 'done' && $g !== 'handed' ? 'bad ' : '')); ?>
        <article class="asg-card-row" data-find="<?php echo htmlspecialchars(mb_strtolower($a['title'] . ' ' . $a['class_name'])); ?>">
            <div class="acr-main">
                <a class="acr-title" href="view.php?id=<?php echo (int) $a['id']; ?>"><?php echo htmlspecialchars($a['title']); ?></a>
                <div class="acr-meta">
                    <?php if (!$class): ?><span class="chip-i"><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($a['class_name']); ?></span><?php endif; ?>
                    <span class="chip-i <?php echo $cls; ?>" title="<?php echo $a['due_date'] ? htmlspecialchars(formatDate($a['due_date'], 'M d, Y h:i A')) : ''; ?>"><i class="fas fa-clock"></i> <?php echo t(assignmentDueText($a['due_date'])); ?></span>
                    <span class="chip-i"><i class="fas fa-star"></i> <?php echo (int) $a['max_marks']; ?> <?php echo t('marks'); ?></span>
                </div>
                <?php if ($teacher): $n = max(1, (int) $a['student_count']); $gr = (int) $a['graded_count']; $td = (int) $a['todo_count']; $rt = (int) $a['returned_count']; ?>
                <div class="acr-prog" title="<?php echo $gr; ?> graded · <?php echo $td; ?> to grade · <?php echo $rt; ?> returned · <?php echo (int) $a['student_count']; ?> students">
                    <span class="seg ok" style="width:<?php echo round($gr / $n * 100, 1); ?>%"></span><span class="seg todo" style="width:<?php echo round($td / $n * 100, 1); ?>%"></span><span class="seg wait" style="width:<?php echo round($rt / $n * 100, 1); ?>%"></span></div>
                <div class="acr-legend"><span><b><?php echo $gr; ?></b> <?php echo t('graded'); ?></span> · <span><b><?php echo $td; ?></b> <?php echo t('to grade'); ?></span><?php echo $rt ? ' · <span><b>' . $rt . '</b> ' . t('returned') . '</span>' : ''; ?> · <span><?php echo (int) $a['submitted_count']; ?>/<?php echo (int) $a['student_count']; ?> <?php echo t('handed in'); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="acr-side">
            <?php if ($teacher): ?>
                <?php if ((int) $a['todo_count'] > 0): ?><a class="btn-app" href="grade.php?id=<?php echo (int) $a['id']; ?>&amp;f=todo"><i class="fas fa-pen-to-square"></i> <?php echo t('Grade'); ?> <span class="count-pill"><?php echo (int) $a['todo_count']; ?></span></a>
                <?php else: ?><a class="btn-app btn-outline" href="view.php?id=<?php echo (int) $a['id']; ?>"><?php echo t('Open'); ?></a><?php endif; ?>
            <?php else:
                $label = $g === 'done' ? ((resultsReleased($a) && $a['marks_obtained'] !== null) ? formatMarks($a['marks_obtained']) . ' / ' . (int) $a['max_marks'] : t('Result pending')) : null; ?>
                <?php if ($label !== null): ?><span class="acr-grade"><?php echo $label; ?></span><?php endif; ?>
                <a class="btn-app<?php echo in_array($g, ['todo', 'overdue', 'returned'], true) ? '' : ' btn-outline'; ?>" href="<?php echo in_array($g, ['todo', 'overdue', 'returned'], true) ? 'submit.php?id=' . (int) $a['id'] : 'view.php?id=' . (int) $a['id']; ?>">
                    <?php echo t($g === 'returned' ? 'Fix & hand in' : ($g === 'overdue' ? 'Hand in late' : ($g === 'todo' ? 'Hand in' : 'Open'))); ?></a>
            <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<p class="form-hint" id="asgNone" hidden style="text-align:center;margin:20px 0;"><?php echo t('No assignment matches.'); ?></p>
