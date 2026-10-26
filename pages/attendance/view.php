<?php
/**
 * View Attendance — sidebar "Attendance › View Attendance" and the header link.
 * Teacher: each class with days marked, class attendance rate and students below the attendance limit.
 * Student: their attendance rate in every class they are enrolled in.
 * Each row opens the class Attendance page (report for teachers, own record for students).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$classes = getUserClasses($current_user_id, $current_user_role);
$rows = [];
foreach ($classes as $c) {
    $class = $db->fetchOne("SELECT * FROM classes WHERE id = ?", [$c['id']]);
    $summary = getAttendanceSummary($c['id'], isTeacher() ? null : $current_user_id);
    $days = (int) $db->fetchOne("SELECT COUNT(DISTINCT date) AS c FROM attendance WHERE class_id = ?", [$c['id']])['c'];
    if (isTeacher()) {
        $rates = array_values(array_filter(array_column($summary, 'rate'), fn($r) => $r !== null));
        $rate = $rates ? round(array_sum($rates) / count($rates), 1) : null;
        $low = count(array_filter($rates, fn($r) => $r < attLow()));
        $absent = array_sum(array_column($summary, 'absent'));
    } else {
        $mine = $summary[(int) $current_user_id] ?? null;
        $rate = $mine['rate'] ?? null;
        $low = 0;
        $absent = $mine['absent'] ?? 0;
    }
    $rows[] = ['class' => $class, 'days' => $days, 'rate' => $rate, 'low' => $low, 'absent' => $absent];
}

$today_rows = isTeacher() ? attendanceTodayList($current_user_id) : [];
$needs = array_values(array_filter($today_rows, fn($r) => in_array($r['state'], ['todo', 'partial'], true)));

// Teacher extras: today's counts per class, the last 7 days across all classes, students who need attention
$today_counts = []; $week = []; $attention = [];
if (isTeacher() && $classes) {
    $ids = array_map(fn($c) => (int) $c['id'], $classes); $in = implode(',', array_fill(0, count($ids), '?'));
    foreach ($db->fetchAll("SELECT class_id, status, COUNT(*) n FROM attendance WHERE class_id IN ($in) AND date = CURDATE() GROUP BY class_id, status", $ids) as $r) $today_counts[(int) $r['class_id']][$r['status']] = (int) $r['n'];
    $wk = [];
    foreach ($db->fetchAll("SELECT date, COUNT(*) n, SUM(" . attendedSql() . ") att, COUNT(DISTINCT class_id) classes FROM attendance WHERE class_id IN ($in) AND date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY date", $ids) as $r) $wk[$r['date']] = $r;
    for ($i = 6; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $w = $wk[$d] ?? null; $week[] = ['date' => $d, 'rate' => $w && $w['n'] ? round($w['att'] / $w['n'] * 100) : null, 'classes' => $w ? (int) $w['classes'] : 0]; }
    $names = [];
    foreach ($rows as $r) {
        $cid = (int) $r['class']['id'];
        foreach (getAttendanceSummary($cid) as $sid => $sm) if ($sm['rate'] !== null && $sm['rate'] < attLow()) $attention[] = ['sid' => $sid, 'cid' => $cid, 'class' => $r['class']['class_name'], 'rate' => $sm['rate'], 'absent' => $sm['absent']];
    }
    usort($attention, fn($a, $b) => $a['rate'] <=> $b['rate']); $attention = array_slice($attention, 0, 6);
    if ($attention) {
        $uids = array_values(array_unique(array_column($attention, 'sid')));
        foreach ($db->fetchAll("SELECT id, name FROM users WHERE id IN (" . implode(',', array_fill(0, count($uids), '?')) . ")", $uids) as $u) $names[(int) $u['id']] = $u['name'];
    }
}
$class_rates = array_values(array_filter(array_column($rows, 'rate'), fn($r) => $r !== null));
$avg_rate = $class_rates ? round(array_sum($class_rates) / count($class_rates), 1) : null;
$taken_today = count(array_filter($today_rows, fn($r) => $r['state'] === 'done'));

$page_title = 'Attendance';
$page_subtitle = isTeacher() ? 'Attendance across your classes' : 'Your attendance in every class';
$active_menu = 'attendance';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<?php if (isTeacher() && $today_rows): ?>
<div class="att-hub-stats">
    <div class="ahs"><span class="ahs-n"><?php echo count($needs); ?></span><span class="ahs-l">Classes still to take today</span></div>
    <div class="ahs"><span class="ahs-n"><?php echo $taken_today; ?><small> / <?php echo count($today_rows); ?></small></span><span class="ahs-l">Taken today</span></div>
    <div class="ahs"><span class="ahs-n"><?php echo $avg_rate !== null ? $avg_rate . '%' : '—'; ?></span><span class="ahs-l">Average attendance</span></div>
    <div class="ahs<?php echo $attention ? ' warn' : ''; ?>"><span class="ahs-n"><?php echo count($attention); ?><?php echo count($attention) >= 6 ? '+' : ''; ?></span><span class="ahs-l">Students below <?php echo attLow(); ?>%</span></div>
</div>

<section aria-labelledby="attTodayTitle">
    <div class="ah-head"><h2 id="attTodayTitle"><i class="fas fa-calendar-day"></i> Today — <?php echo date('l, F j'); ?></h2>
        <span class="ah-actions"><?php if ($needs): ?><span class="status-badge missing"><?php echo count($needs); ?> to take</span><?php else: ?><span class="status-badge graded"><i class="fas fa-check" aria-hidden="true"></i> All done</span><?php endif; ?>
            <a href="markAttendance.php" class="btn-app btn-sm"><i class="fas fa-clipboard-check"></i> Take today's attendance</a></span></div>
    <div class="att-cards">
        <?php foreach ($today_rows as $tr): $cid = (int) $tr['id']; $all = (int) $tr['students']; $tc = $today_counts[$cid] ?? [];
            $pct = $all ? min(100, round((int) $tr['marked_today'] / $all * 100)) : 0; $todo = in_array($tr['state'], ['todo', 'partial'], true); ?>
        <div class="att-card state-<?php echo $tr['state']; ?>">
            <div class="ac-top">
                <span class="work-icon" style="background: <?php echo classTheme($tr); ?>;"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
                <div class="ac-title"><strong><?php echo htmlspecialchars($tr['class_name']); ?></strong>
                    <span class="form-hint"><?php echo $all; ?> student<?php echo $all == 1 ? '' : 's'; ?></span>
                    <span class="form-hint">last taken <?php echo $tr['last_marked'] ? date('M d', strtotime($tr['last_marked'])) : 'never'; ?></span></div>
                <?php if ($tr['state'] === 'empty'): ?><span class="status-badge pending">No students</span>
                <?php elseif ($tr['state'] === 'done'): ?><span class="status-badge graded"><i class="fas fa-check" aria-hidden="true"></i> Taken today</span>
                <?php elseif ($tr['state'] === 'partial'): ?><span class="status-badge late"><?php echo (int) $tr['marked_today']; ?> / <?php echo $all; ?> marked today</span>
                <?php elseif ($tr['state'] === 'noclass'): ?><span class="status-badge pending">No class today</span>
                <?php elseif ($tr['state'] === 'off'): ?><span class="status-badge pending">Day off</span>
                <?php else: ?><span class="status-badge missing">Not taken today</span><?php endif; ?>
            </div>
            <?php if ($all && $tr['state'] !== 'noclass'): ?>
            <div class="ac-bar" role="img" aria-label="<?php echo (int) $tr['marked_today']; ?> of <?php echo $all; ?> marked"><i style="width:<?php echo $pct; ?>%"></i></div>
            <div class="ac-counts">
                <?php if ($tc): ?>
                <span class="c-p"><b><?php echo (int) ($tc['present'] ?? 0); ?></b> Present</span><span class="c-l"><b><?php echo (int) ($tc['late'] ?? 0); ?></b> Late</span><span class="c-a"><b><?php echo (int) ($tc['absent'] ?? 0); ?></b> Absent</span><span class="c-o"><b><?php echo (int) ($tc['official'] ?? 0); ?></b> Official</span>
                <?php else: ?><span class="form-hint" style="margin:0;">Nobody marked yet today</span><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($tr['state'] !== 'empty'): ?>
            <a class="btn-app<?php echo $todo ? '' : ' btn-outline'; ?> ac-go" href="<?php echo attendanceUrl($cid, '&amp;date=' . date('Y-m-d')); ?>"><i class="fas <?php echo $todo ? 'fa-clipboard-check' : 'fa-pen'; ?>" aria-hidden="true"></i> <?php echo $todo ? 'Take attendance' : 'Open today'; ?></a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="att-hub-two">
    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-chart-column"></i> Last 7 days</h2><span class="form-hint" style="margin:0;">all your classes together</span></div>
        <div class="att-week">
            <?php foreach ($week as $w): $tone = $w['rate'] === null ? 'none' : ($w['rate'] >= 85 ? 'good' : ($w['rate'] >= attLow() ? 'mid' : 'low')); ?>
            <div class="aw-day<?php echo $w['date'] === date('Y-m-d') ? ' today' : ''; ?>" title="<?php echo date('D, M j', strtotime($w['date'])) . ($w['rate'] === null ? ' · nothing taken' : ' · ' . $w['rate'] . '% attended · ' . $w['classes'] . ' class' . ($w['classes'] == 1 ? '' : 'es')); ?>">
                <span class="aw-val"><?php echo $w['rate'] === null ? '—' : $w['rate'] . '%'; ?></span>
                <span class="aw-bar"><i class="<?php echo $tone; ?>" style="height:<?php echo $w['rate'] === null ? 0 : max(4, $w['rate']); ?>%"></i></span>
                <span class="aw-name"><?php echo date('D', strtotime($w['date'])); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="content-card">
        <div class="card-head"><h2><i class="fas fa-exclamation-triangle" style="color:#e9a23b;"></i> Needs attention</h2></div>
        <?php if (!$attention): ?><p class="form-hint" style="margin:12px 18px 18px;"><i class="fas fa-check-circle" style="color:#12a67a;"></i> Nobody is below <?php echo attLow(); ?>%.</p>
        <?php else: foreach ($attention as $at): ?>
        <a class="ah-risk" href="<?php echo APP_URL; ?>/pages/classes/student.php?class_id=<?php echo $at['cid']; ?>&amp;student_id=<?php echo $at['sid']; ?>">
            <span class="avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($names[$at['sid']] ?? '?', 0, 1))); ?></span>
            <span class="ah-who"><strong><?php echo htmlspecialchars($names[$at['sid']] ?? ''); ?></strong><span class="form-hint"><?php echo htmlspecialchars($at['class']); ?> · <?php echo (int) $at['absent']; ?> absent</span></span>
            <span class="rd-rate <?php echo $at['rate'] < 60 ? 'low' : 'mid'; ?>"><?php echo $at['rate']; ?>%</span>
        </a>
        <?php endforeach; endif; ?>
    </section>
</div>
<?php endif; ?>

<?php if (!isTeacher() && $rows): ?>
<div class="att-cards">
    <?php foreach ($rows as $r): $cid = (int) $r['class']['id']; $tone = $r['rate'] === null ? 'none' : ($r['rate'] >= 85 ? 'good' : ($r['rate'] >= attLow() ? 'mid' : 'low')); ?>
    <a class="att-card att-mine" href="<?php echo attendanceUrl($cid); ?>">
        <div class="ac-top">
            <span class="work-icon" style="background: <?php echo classTheme($r['class']); ?>;"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
            <div class="ac-title"><strong><?php echo htmlspecialchars($r['class']['class_name']); ?></strong>
                <span class="form-hint"><?php echo $r['days']; ?> day<?php echo $r['days'] == 1 ? '' : 's'; ?> marked · <?php echo $r['absent']; ?> absent</span></div>
            <span class="ac-ring <?php echo $tone; ?>" style="--p:<?php echo (float) ($r['rate'] ?? 0); ?>"><b><?php echo $r['rate'] !== null ? round($r['rate']) . '%' : '—'; ?></b></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (isTeacher() && $classes): ?>
<div class="ah-head" style="margin-top:22px;"><h2><i class="fas fa-table-list"></i> All classes</h2></div>
<?php endif; ?>

<?php if (!$rows): ?>
<div class="empty-state">
    <i class="fas fa-clipboard-check"></i>
    <h3>No classes yet</h3>
    <p style="margin-top:6px;"><?php echo isTeacher() ? 'Create a class to start taking attendance.' : 'Join a class to see your attendance.'; ?></p>
</div>
<?php elseif (isTeacher()): ?>
<div class="content-card">
    <div class="table-wrap">
        <table class="app-table insight-table">
            <thead>
                <tr>
                    <th>Class</th>
                    <th style="min-width:200px;"><?php echo isTeacher() ? 'Class attendance' : 'Your attendance'; ?></th>
                    <th>Days marked</th>
                    <th><?php echo isTeacher() ? 'Absences' : 'Times absent'; ?></th>
                    <?php if (isTeacher()): ?><th>Students below <?php echo attLow(); ?>%</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $cid = (int) $r['class']['id']; ?>
                <tr>
                    <td><a class="person-link" href="<?php echo attendanceUrl($cid); ?><?php echo isTeacher() ? '&amp;view=report' : ''; ?>">
                        <strong><?php echo htmlspecialchars($r['class']['class_name']); ?></strong></a></td>
                    <td>
                        <?php if ($r['rate'] !== null): ?>
                        <div class="meter" title="<?php echo $r['rate']; ?>% attended">
                            <div class="meter-track"><div class="meter-fill att-rate-<?php echo $r['rate'] >= 85 ? 'good' : ($r['rate'] >= attLow() ? 'mid' : 'low'); ?>" style="width: <?php echo $r['rate']; ?>%;"></div></div>
                            <span class="meter-value"><?php echo $r['rate']; ?>%</span>
                        </div>
                        <?php else: ?><span class="form-hint" style="margin:0;">Not taken yet</span><?php endif; ?>
                    </td>
                    <td><?php echo $r['days']; ?></td>
                    <td><?php echo $r['absent']; ?></td>
                    <?php if (isTeacher()): ?>
                    <td><?php echo $r['low'] ? '<span class="status-badge missing"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> ' . $r['low'] . '</span>' : '—'; ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
