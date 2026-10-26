<?php
/**
 * Marks — the student's page (included by view.php; the teacher's page is unchanged).
 *   Tab "Final marks": a summary, then one row per class (percent so far, grade, the five parts as chips); open a row for the parts, the class average,
 *   the weakest part and how the grade is worked out. Tab "Exam results": every published exam with the student's mark and the class average.
 * Needs from view.php: $classes, $use_ids, $filter, $rows (published exam results), $overall, $fmt, $current_user_id.
 * A class average is shown only when at least 3 students have marks (so nobody can read another student's mark from it).
 */
$tab = ($_GET['tab'] ?? '') === 'exams' ? 'exams' : 'final';
$comps = markingComponents();
$cards = [];
foreach ($classes as $fc) {
    if (!in_array((int) $fc['id'], $use_ids, true)) continue;
    $cid = (int) $fc['id'];
    $fm = classFinalMarks($cid, true, $current_user_id);
    $me = $fm['rows'][0] ?? null;
    $all = classFinalMarks($cid, true, null);   // everyone's PUBLISHED marks: only averages leave this block
    $avg = ['n' => 0, 'percent' => null, 'parts' => []];
    $pcts = array_values(array_filter(array_column($all['rows'], 'percent'), fn($v) => $v !== null));
    if (count($pcts) >= 3) {
        $avg['n'] = count($pcts); $avg['percent'] = round(array_sum($pcts) / count($pcts), 1);
        foreach ($fm['scheme'] as $k => $w) {
            $v = array_values(array_filter(array_map(fn($r) => $r['parts'][$k]['pct'], $all['rows']), fn($x) => $x !== null));
            if (count($v) >= 3) $avg['parts'][$k] = round(array_sum($v) / count($v), 1);
        }
    }
    $band = ($me && $me['percent'] !== null) ? gradeBand($me['percent']) : null;
    $bands_n = count(gradingScale()['bands']);
    $weak = null;
    if ($me) {
        $marked_parts = array_filter($me['parts'], fn($p) => $p['pct'] !== null);
        if (count($marked_parts) >= 2) foreach ($marked_parts as $kk => $pp) if ($weak === null || $pp['pct'] < $marked_parts[$weak]['pct']) $weak = $kk;   // the part with the lowest percent
    }
    $cards[] = ['class' => $fc, 'fm' => $fm, 'me' => $me, 'avg' => $avg, 'band' => $band, 'lowest' => $band && $bands_n > 1 && $band['index'] === $bands_n - 1, 'weak' => $weak,
                'css' => gradingCss(), 'ranges' => gradingRanges($fm['weight_all']), 'gpa' => gradingShowGpa(), 'basis' => gradingBasis(), 'cid' => $cid];
}
$with = array_values(array_filter($cards, fn($c) => $c['me'] && $c['me']['percent'] !== null));
$best = null; foreach ($with as $c) if ($best === null || $c['me']['percent'] > $best['me']['percent']) $best = $c;
$low = array_values(array_filter($with, fn($c) => $c['lowest']));
$mean = $with ? round(array_sum(array_map(fn($c) => $c['me']['percent'], $with)) / count($with), 1) : null;
usort($cards, fn($a, $b) => (($a['me']['percent'] ?? -1) <=> ($b['me']['percent'] ?? -1)) * -1);   // best first; classes without marks last
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form">
        <?php if ($classes): ?>
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
    </form>
    <div class="tab-pills">
        <a href="?<?php echo http_build_query(array_filter(['class_id' => $filter ?: null])); ?>" class="<?php echo $tab === 'final' ? 'active' : ''; ?>"><i class="fas fa-award"></i> Final marks</a>
        <a href="?<?php echo http_build_query(array_filter(['tab' => 'exams', 'class_id' => $filter ?: null])); ?>" class="<?php echo $tab === 'exams' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> Exam results<?php echo $rows ? ' <span class="count-pill">' . count($rows) . '</span>' : ''; ?></a>
    </div>
</div>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chart-line"></i><h3>No classes yet</h3><p style="margin-top:6px;">Join a class to see your marks here.</p></div>
<?php elseif ($tab === 'final'): ?>

<div class="mk-summary">
    <div class="mk-sum"><span class="mk-sum-n"><?php echo $mean !== null ? $mean . '%' : '—'; ?></span><span class="mk-sum-l">Overall average</span><small><?php echo $mean !== null ? 'across ' . count($with) . ' class' . (count($with) == 1 ? '' : 'es') . ' with marks' : 'No marks yet'; ?></small></div>
    <div class="mk-sum"><span class="mk-sum-n"><?php echo count($with); ?><small> / <?php echo count($cards); ?></small></span><span class="mk-sum-l">Classes with marks</span></div>
    <div class="mk-sum"><span class="mk-sum-n mk-sum-name"><?php echo $best ? htmlspecialchars($best['class']['class_name']) : '—'; ?></span><span class="mk-sum-l">Best class</span><small><?php echo $best ? $best['me']['percent'] . '%' : ''; ?></small></div>
    <div class="mk-sum<?php echo $low ? ' warn' : ''; ?>"><span class="mk-sum-n"><?php echo count($low); ?></span><span class="mk-sum-l">Needs attention</span><small><?php echo $low ? 'in the lowest grade' : 'None — well done'; ?></small></div>
</div>

<div class="mk-list">
<?php foreach ($cards as $c): $me = $c['me']; $fm = $c['fm']; $cn = htmlspecialchars($c['class']['class_name']); ?>
<style><?php echo $c['css']; ?></style>
<details class="mk-class<?php echo $c['lowest'] ? ' low' : ''; ?>"<?php echo $filter ? ' open' : ''; ?>>
    <summary>
        <span class="mk-ring<?php echo $me && $me['percent'] !== null ? '' : ' none'; ?>" style="--p:<?php echo $me && $me['percent'] !== null ? min(100, (float) $me['percent']) : 0; ?><?php echo $c['band'] ? ';--c:' . htmlspecialchars($c['band']['color']) : ''; ?>"><b><?php echo $me && $me['percent'] !== null ? round($me['percent']) . '%' : '—'; ?></b></span>
        <span class="mk-name"><strong><?php echo $cn; ?></strong>
            <?php if ($me && $me['percent'] !== null): $part_done = $fm['weight_all'] > 0 ? round($me['weight_done'] / $fm['weight_all'] * 100) : 0; ?>
            <span class="form-hint"><span><?php echo $part_done >= 100 ? 'All parts marked' : $part_done . '% of the marks are in'; ?></span><?php echo $c['avg']['percent'] !== null ? ' · <span>class average ' . $c['avg']['percent'] . '%</span>' : ''; ?></span>
            <?php else: ?><span class="form-hint">Your marks appear here when your teacher enters them</span><?php endif; ?></span>
        <span class="mk-chips" aria-label="Parts">
            <?php foreach ($fm['scheme'] as $k => $w): $pt = $me['parts'][$k] ?? ['pct' => null]; ?>
            <span class="mk-chip <?php echo $pt['pct'] === null ? 'na' : ($pt['pct'] >= 75 ? 'hi' : ($pt['pct'] >= 50 ? 'mid' : 'lo')); ?>" title="<?php echo $comps[$k] . ': ' . ($pt['pct'] === null ? 'not marked yet' : $pt['pct'] . '%'); ?>"><?php echo $comps[$k]; ?> <b><?php echo $pt['pct'] === null ? '—' : round($pt['pct']) . '%'; ?></b></span>
            <?php endforeach; ?>
        </span>
        <span class="mk-grade-box">
            <?php if ($c['band']): ?><span class="fm-grade gc-bg <?php echo $c['band']['cls']; ?>"><?php echo htmlspecialchars($c['band']['label']); ?></span>
                <?php if ($me['weight_done'] < $fm['weight_all']): ?><small class="mk-prov">Provisional</small><?php endif; ?>
            <?php else: ?><span class="status-badge pending">Pending</span><?php endif; ?>
        </span>
        <i class="fas fa-chevron-down mk-caret" aria-hidden="true"></i>
    </summary>
    <div class="mk-detail">
    <?php if (!$me || $me['percent'] === null): ?>
        <p class="form-hint" style="margin:6px 0;">Nothing is marked yet for the mid exam, final exam, activities, quizzes or assignments of this class.</p>
    <?php else: ?>
        <div class="mk-points"><span class="mk-points-n"><?php echo $fmt($me['total']); ?></span> <span class="form-hint" style="margin:0;"><span>of <?php echo $fmt($fm['weight_all']); ?> points</span><?php echo $c['gpa'] && $c['band'] ? ' · <span>GPA ' . number_format(gradeGpa($me['percent']), 2) . '</span>' : ''; ?><?php if ($me['weight_done'] < $fm['weight_all']): ?> · <span><?php echo $me['percent_so_far']; ?>% of the parts marked so far</span><?php endif; ?></span></div>
        <div class="mk-parts">
        <?php foreach ($fm['scheme'] as $k => $w): $pt = $me['parts'][$k]; $ap = $c['avg']['parts'][$k] ?? null; ?>
            <div class="mk-part<?php echo $c['weak'] === $k ? ' weak' : ''; ?>">
                <span class="mk-pname"><?php echo $comps[$k]; ?><?php echo $c['weak'] === $k ? ' <em>· <span>weakest part</span></em>' : ''; ?></span>
                <?php if ($pt['pct'] === null): ?>
                <span class="mk-pbar na" role="img" aria-label="Not marked yet"></span><span class="mk-pval na"><span>Not marked yet</span> <small>worth <?php echo $fmt($w); ?></small></span>
                <?php else: ?>
                <span class="mk-pbar" role="img" aria-label="<?php echo $pt['pct']; ?> percent"><i style="width:<?php echo min(100, $pt['pct']); ?>%"></i><?php if ($ap !== null): ?><u style="inset-inline-start:<?php echo min(100, $ap); ?>%" title="Class average <?php echo $ap; ?>%"></u><?php endif; ?></span>
                <span class="mk-pval"><b><?php echo $fmt($pt['earned']); ?></b> <small>/ <?php echo $fmt($w); ?> · <?php echo round($pt['pct']); ?>%<?php echo $ap !== null ? ' · <span>class ' . round($ap) . '%</span>' : ''; ?></small></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($c['avg']['percent'] !== null): ?><p class="form-hint mk-legend-note"><span class="mk-avg-key"></span> The thin line on a bar is the class average.</p><?php endif; ?>
    <?php endif; ?>
        <details class="mk-how">
            <summary>How grades work</summary>
            <p class="form-hint" style="margin:6px 0;"><?php echo $c['basis'] === 'all' ? 'Your percent = your points ÷ all ' . $fmt($fm['weight_all']) . ' points (a part without marks counts as 0). Exams count once their results are published.' : 'Your percent = your points ÷ the points of the parts that are marked so far. Exams count once their results are published.'; ?></p>
            <div class="fm-ranges" aria-label="Grade ranges"><?php foreach ($c['ranges'] as $rg): ?><span class="fm-grade gc-bg <?php echo $rg['cls']; ?>"><?php echo htmlspecialchars($rg['label']); ?> <small><?php echo htmlspecialchars($rg['marks'] . ' marks · ' . $rg['pct']); ?></small></span><?php endforeach; ?></div>
        </details>
        <div class="mk-actions"><a class="btn-app btn-sm" target="_blank" href="<?php echo APP_URL; ?>/pages/marks/print.php?class_id=<?php echo (int) $c['cid']; ?>"><i class="fas fa-file-pdf"></i> Statement (PDF)</a></div>
    </div>
</details>
<?php endforeach; ?>
</div>

<?php else: /* ---- exam results ---- */ ?>
<?php if (!$rows): ?>
<div class="empty-state"><i class="fas fa-file-alt"></i><h3>No exam results yet</h3><p style="margin-top:6px;">Results appear here after your teacher publishes them.</p></div>
<?php else: ?>
<?php if ($overall !== null): ?>
<div class="content-card"><div class="card-inner" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
    <div><div class="form-hint" style="margin:0;">Overall across published exams</div><div style="font-size:2rem;font-weight:700;"><?php echo $overall; ?>%</div></div>
    <div class="qstat-bar" style="flex:1;min-width:200px;"><span style="width:<?php echo (int) min(100, $overall); ?>%"></span></div>
</div></div>
<?php endif; ?>
<div class="content-card"><div class="table-wrap"><table class="app-table">
    <thead><tr><th>Exam</th><th>Class</th><th>Date</th><th>Your mark</th><th>%</th><th>Class average</th><th>Remarks</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><strong><?php echo htmlspecialchars($r['title']); ?></strong></td>
        <td><?php echo htmlspecialchars($r['class_name']); ?></td>
        <td><?php echo formatDate($r['exam_date'], 'M d, Y'); ?></td>
        <?php if ($r['absent']): ?><td colspan="2"><span class="status-badge missing">Absent</span></td>
        <?php elseif ($r['marks'] === null): ?><td colspan="2"><span class="status-badge pending">No mark recorded</span></td>
        <?php else: $pct = round($r['marks'] / $r['max_marks'] * 100, 1); ?>
        <td class="marks"><?php echo $fmt($r['marks']); ?> / <?php echo (int) $r['max_marks']; ?></td>
        <td><span class="status-badge <?php echo $pct >= 50 ? 'graded' : 'late'; ?>"><?php echo $pct; ?>%</span></td>
        <?php endif; ?>
        <td><?php echo $r['class_avg'] !== null ? $fmt($r['class_avg']) . ' / ' . (int) $r['max_marks'] : '—'; ?></td>
        <td><?php echo htmlspecialchars((string) $r['remarks']); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
