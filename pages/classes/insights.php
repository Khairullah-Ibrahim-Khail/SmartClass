<?php
/**
 * Class — Insights: ?id=N (class teacher only)
 * Headline numbers, students who need attention (with reasons), and per-item
 * stats: turn-in rate, late count, average score, ungraded work, hardest quiz question.
 * Computed from the same data as the gradebook (getClassGradebook).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

if (!$is_owner) {
    redirect(classUrl('stream'));
}

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$book = getClassGradebook($class_id);
$cols = $book['columns'];
$rows = $book['rows'];
$n_students = count($rows);
$now_ts = time();

// ---------- Per-item stats ----------
$items = [];
foreach ($cols as $i => $c) {
    $done = 0; $late = 0; $ungraded = 0; $missing = 0; $scores = [];
    foreach ($rows as $r) {
        $cell = $r['cells'][$i];
        if (in_array($cell['state'], ['submitted', 'late', 'graded', 'done', 'timeout'], true)) $done++;
        if ($cell['state'] === 'late') $late++;
        if (in_array($cell['state'], ['submitted', 'late'], true)) $ungraded++;
        if ($cell['state'] === 'missing') $missing++;
        if ($cell['score'] !== null && $c['max'] > 0) $scores[] = $cell['score'] / $c['max'] * 100;
    }
    $items[] = $c + [
        'done' => $done, 'late' => $late, 'ungraded' => $ungraded, 'missing' => $missing,
        'rate' => $n_students ? round($done / $n_students * 100) : 0,
        'avg' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
        'hardest' => null,
    ];
}

// Hardest question per quiz: lowest share of correct answers among submitted attempts
foreach ($db->fetchAll(
    "SELECT qq.quiz_id, qq.question, COUNT(ans.id) AS answered, SUM(ans.is_correct) AS correct
     FROM quiz_questions qq
     INNER JOIN quizzes z ON qq.quiz_id = z.id
     LEFT JOIN quiz_answers ans ON ans.question_id = qq.id
     LEFT JOIN quiz_attempts t ON ans.attempt_id = t.id AND t.submitted_at IS NOT NULL AND t.timed_out = 0
     WHERE z.class_id = ? AND t.id IS NOT NULL
     GROUP BY qq.id
     HAVING answered > 0", [$class_id]) as $q) {
    $pct = round($q['correct'] / $q['answered'] * 100);
    foreach ($items as &$it) {
        if ($it['type'] === 'quiz' && $it['id'] === (int) $q['quiz_id'] && ($it['hardest'] === null || $pct < $it['hardest']['pct'])) {
            $it['hardest'] = ['text' => $q['question'], 'pct' => $pct];
        }
    }
    unset($it);
}

// ---------- Headline numbers ----------
$pcts = array_values(array_filter(array_column($rows, 'percent'), fn($p) => $p !== null));
$avg_grade = $pcts ? round(array_sum($pcts) / count($pcts), 1) : null;
$due_items = array_filter($items, fn($it) => !empty($it['due']) && $now_ts > strtotime($it['due']));
$expected = count($due_items) * $n_students;
$turned_in_due = array_sum(array_column($due_items, 'done'));
$completion = $expected ? round($turned_in_due / $expected * 100) : null;
$to_grade = array_sum(array_column($items, 'ungraded'));

$week_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$active_week = (int) ($db->fetchOne(
    "SELECT COUNT(DISTINCT uid) AS c FROM (
        SELECT s.student_id AS uid FROM submissions s INNER JOIN assignments a ON s.assignment_id = a.id
         WHERE a.class_id = ? AND s.submitted_at >= ?
        UNION SELECT t.student_id FROM quiz_attempts t INNER JOIN quizzes z ON t.quiz_id = z.id
         WHERE z.class_id = ? AND t.started_at >= ?
        UNION SELECT c.user_id FROM announcement_comments c INNER JOIN announcements an ON c.announcement_id = an.id
         WHERE an.class_id = ? AND c.created_at >= ?
        UNION SELECT an.user_id FROM announcements an WHERE an.class_id = ? AND an.created_at >= ?
     ) x INNER JOIN class_members cm ON cm.student_id = x.uid AND cm.class_id = ?",
    [$class_id, $week_ago, $class_id, $week_ago, $class_id, $week_ago, $class_id, $week_ago, $class_id])['c'] ?? 0);

// ---------- Students who need attention ----------
$att_summary = getAttendanceSummary($class_id);
$attention = [];
foreach ($rows as $r) {
    $reasons = [];
    $missing = count(array_filter($r['cells'], fn($c) => $c['state'] === 'missing'));
    if ($missing >= 2) $reasons[] = ['fa-exclamation-circle', $missing . ' missing'];
    elseif ($missing === 1) $reasons[] = ['fa-exclamation-circle', '1 missing'];
    if ($r['percent'] !== null && $r['percent'] < lowGradePct()) $reasons[] = ['fa-arrow-down', 'grade ' . $r['percent'] . '%'];
    $timeouts = count(array_filter($r['cells'], fn($c) => $c['state'] === 'timeout'));
    if ($timeouts) $reasons[] = ['fa-stopwatch', $timeouts . ' quiz over time'];
    $att_rate = $att_summary[$r['id']]['rate'] ?? null;
    if ($att_rate !== null && $att_rate < attLow()) $reasons[] = ['fa-user-times', 'attendance ' . $att_rate . '%'];
    if ($reasons) {
        $attention[] = ['id' => $r['id'], 'name' => $r['name'], 'percent' => $r['percent'], 'reasons' => $reasons,
                        'weight' => $missing * 2 + ($r['percent'] !== null && $r['percent'] < lowGradePct() ? 3 : 0) + $timeouts
                                    + ($att_rate !== null && $att_rate < attLow() ? 2 : 0)];
    }
}
usort($attention, fn($a, $b) => $b['weight'] <=> $a['weight']);

// ---------- Charts ----------
$scale = gradingScale()['bands'];
$buckets = [];
$by_band = [];
foreach ($pcts as $pp) { $gi = gradeBand($pp)['index']; $by_band[$gi] = ($by_band[$gi] ?? 0) + 1; }
foreach ($scale as $bi => $bd) {
    $buckets[] = [$bd['label'] . ' · ' . gradingRanges()[$bi]['pct'], $by_band[$bi] ?? 0, $bd['color']];
}
$not_graded = $n_students - count($pcts);
$bucket_max = max(1, max(array_column($buckets, 1)));
$att_days = array_reverse($db->fetchAll(
    "SELECT date, COUNT(*) AS n, SUM(" . attendedSql('status') . ") AS ok FROM attendance WHERE class_id = ? GROUP BY date ORDER BY date DESC LIMIT 14", [$class_id]));
$att30 = $db->fetchOne("SELECT COUNT(*) AS n, SUM(" . attendedSql('status') . ") AS ok FROM attendance WHERE class_id = ? AND date >= ?", [$class_id, date('Y-m-d', strtotime('-30 days'))]);
$att30_rate = attendancePct($att30['ok'] ?? 0, $att30['n'] ?? 0);
$top = array_values(array_filter($rows, fn($r) => $r['percent'] !== null));
usort($top, fn($a, $b) => $b['percent'] <=> $a['percent']);
$top = array_slice($top, 0, 5);

$page_title = $class['class_name'] . ' - Insights';

include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('insights');
?>
<div class="stats-grid insight-tiles">
    <a class="stat-card" href="<?php echo classUrl('attendance', '&amp;view=report'); ?>" style="text-decoration:none;">
        <div class="stat-icon"><i class="fas fa-user-check" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Attendance · 30d</h3>
            <p class="stat-number"><?php echo $att30_rate === null ? '—' : $att30_rate . '%'; ?></p>
            <span class="stat-change <?php echo ($att30_rate !== null && $att30_rate < 80) ? 'warn' : 'muted'; ?>"><?php echo $att30_rate === null ? 'not taken yet' : 'open the attendance report'; ?></span></div>
    </a>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-check-double" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Turn-in rate</h3>
            <p class="stat-number"><?php echo $completion === null ? '—' : $completion . '%'; ?></p>
            <span class="stat-change muted"><?php echo $expected ? $turned_in_due . ' of ' . $expected . ' past-due items' : 'nothing past due yet'; ?></span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-user-clock" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Active this week</h3>
            <p class="stat-number"><?php echo $active_week; ?> / <?php echo $n_students; ?></p>
            <span class="stat-change muted">submitted, took a quiz or posted</span></div>
    </div>
    <a class="stat-card" href="<?php echo classUrl('grades'); ?>" style="text-decoration:none;">
        <div class="stat-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></div>
        <div class="stat-info"><h3>Waiting to grade</h3>
            <p class="stat-number"><?php echo $to_grade; ?></p>
            <span class="stat-change <?php echo $to_grade ? 'warn' : 'muted'; ?>"><?php echo $to_grade ? 'open the gradebook' : 'all caught up'; ?></span></div>
    </a>
</div>

<div class="ins-charts">
    <section class="content-card ins-card">
        <div class="card-head"><h2><i class="fas fa-chart-pie"></i> Grade spread</h2><span class="form-hint" style="margin:0;"><?php echo $avg_grade === null ? 'no graded work' : 'average ' . $avg_grade . '%'; ?></span></div>
        <div class="ins-body">
            <?php foreach ($buckets as [$label, $count, $tone]): ?>
            <div class="ins-bar"><span class="ins-label"><?php echo htmlspecialchars($label); ?></span>
                <span class="ins-track"><i style="background:<?php echo $tone; ?>;width:<?php echo $count / $bucket_max * 100; ?>%"></i></span>
                <b><?php echo $count; ?></b></div>
            <?php endforeach; ?>
            <?php if ($not_graded): ?><p class="form-hint" style="margin:8px 0 0;"><?php echo $not_graded; ?> student<?php echo $not_graded == 1 ? ' has' : 's have'; ?> no graded work yet.</p><?php endif; ?>
        </div>
    </section>

    <section class="content-card ins-card">
        <div class="card-head"><h2><i class="fas fa-calendar-check"></i> Attendance by day</h2><span class="form-hint" style="margin:0;">last <?php echo count($att_days); ?> days</span></div>
        <div class="ins-body">
            <?php if (!$att_days): ?>
            <p class="form-hint" style="text-align:center;margin:30px 0;">Take attendance to see the trend.</p>
            <?php else: ?>
            <div class="ins-cols" role="img" aria-label="Attendance rate per day taken">
                <?php foreach ($att_days as $d): $r = round($d['ok'] / max(1, $d['n']) * 100); ?>
                <a class="ins-col" href="<?php echo classUrl('attendance', '&amp;date=' . $d['date']); ?>" title="<?php echo date('D, M j', strtotime($d['date'])) . ': ' . $r . '% (' . (int) $d['ok'] . ' of ' . (int) $d['n'] . ')'; ?>">
                    <span class="ins-fill <?php echo $r >= 85 ? 'good' : ($r >= 70 ? 'mid' : 'low'); ?>" style="height:<?php echo max(4, $r); ?>%"></span>
                    <small><?php echo date('j', strtotime($d['date'])); ?></small>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="content-card ins-card">
        <div class="card-head"><h2><i class="fas fa-trophy"></i> Top performers</h2></div>
        <div class="ins-body">
            <?php if (!$top): ?><p class="form-hint" style="text-align:center;margin:30px 0;">Nothing graded yet.</p><?php endif; ?>
            <?php foreach ($top as $i => $t): ?>
            <a class="ins-top" href="student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo (int) $t['id']; ?>">
                <span class="ins-rank"><?php echo $i + 1; ?></span><span class="ins-name"><?php echo htmlspecialchars($t['name']); ?></span><b><?php echo $t['percent']; ?>%</b></a>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<div class="insights-layout">
    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-hands-helping"></i> Students who need attention<?php if ($attention): ?> <span class="count-pill"><?php echo count($attention); ?></span><?php endif; ?></h2></div>
        <?php if (!$n_students): ?>
        <div class="empty-state" style="border:none;"><i class="fas fa-user-graduate"></i><p>No students yet.</p></div>
        <?php elseif (!$attention): ?>
        <div class="empty-state" style="border:none;"><i class="fas fa-check-circle"></i><p>Everyone is on track — no missing work or low grades.</p></div>
        <?php else: ?>
        <?php foreach ($attention as $a): ?>
        <div class="classwork-item">
            <?php echo avatarHtml($a['id'], $a['name']); ?>
            <span class="classwork-main">
                <a class="classwork-title" href="student.php?class_id=<?php echo $class_id; ?>&amp;student_id=<?php echo (int) $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></a>
                <span class="attention-reasons">
                    <?php foreach ($a['reasons'] as [$icon, $text]): ?>
                    <span class="status-badge missing"><i class="fas <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($text); ?></span>
                    <?php endforeach; ?>
                </span>
            </span>
            <a class="icon-btn" href="<?php echo classUrl('messages', '&amp;c=class&amp;s=' . (int) $a['id']); ?>" title="Message <?php echo htmlspecialchars($a['name']); ?>" aria-label="Message <?php echo htmlspecialchars($a['name']); ?>"><i class="fas fa-comment-dots"></i></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-chart-bar"></i> Work at a glance</h2>
            <span class="form-hint" style="margin:0;">Bar = share of students who turned it in</span></div>
        <?php if (!$items): ?>
        <div class="empty-state" style="border:none;"><i class="fas fa-book-open"></i><p>No assignments or quizzes yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="app-table insight-table">
                <thead><tr><th>Work</th><th style="min-width:180px;">Turned in</th><th>Late</th><th>Average</th><th>To grade</th></tr></thead>
                <tbody>
                    <?php foreach (array_reverse($items) as $it): ?>
                    <tr>
                        <td>
                            <a class="person-link" href="<?php echo APP_URL . '/' . $it['url']; ?>">
                                <i class="fas <?php echo $it['type'] === 'quiz' ? 'fa-question-circle' : 'fa-tasks'; ?>" style="color:var(--primary);" aria-hidden="true"></i>
                                <strong><?php echo htmlspecialchars($it['title']); ?></strong></a>
                            <?php if ($it['hardest']): ?>
                            <div class="form-hint" style="margin:2px 0 0;">Hardest question (<?php echo $it['hardest']['pct']; ?>% correct):
                                <?php echo htmlspecialchars(mb_strimwidth($it['hardest']['text'], 0, 70, '…')); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="meter" title="<?php echo $it['done']; ?> of <?php echo $n_students; ?> students (<?php echo $it['rate']; ?>%)<?php echo $it['missing'] ? ' · ' . $it['missing'] . ' missing' : ''; ?>">
                                <div class="meter-track"><div class="meter-fill" style="width: <?php echo $it['rate']; ?>%;"></div></div>
                                <span class="meter-value"><?php echo $it['done']; ?>/<?php echo $n_students; ?></span>
                            </div>
                        </td>
                        <td><?php echo $it['late'] ?: '—'; ?></td>
                        <td><?php echo $it['avg'] === null ? '—' : $it['avg'] . '%'; ?></td>
                        <td><?php echo $it['type'] === 'quiz' ? '<span class="form-hint" style="margin:0;">auto</span>' : ($it['ungraded'] ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
