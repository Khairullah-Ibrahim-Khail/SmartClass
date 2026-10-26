<?php
/**
 * Class — Calendar: ?id=N[&m=YYYY-MM]
 * Month view of the class: assignment and quiz due dates, and attendance days.
 * Teacher: each due item shows how many students turned it in; attendance days show attended / marked.
 * Student: each due item shows their own state (done, missing, ...); attendance days show their own mark.
 * Read-only page (no POST). On phones the month becomes a list of the days that have something.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

// ---------- Month ----------
$m = $_GET['m'] ?? date('Y-m');
if (!is_string($m) || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $m) || (int) substr($m, 0, 4) < 2000 || (int) substr($m, 0, 4) > 2100) {
    $m = date('Y-m');
}
$first = strtotime($m . '-01');
$days_in_month = (int) date('t', $first);
$month_start = date('Y-m-01', $first);
$month_end = date('Y-m-t', $first);
$prev = date('Y-m', strtotime('-1 month', $first));
$next = date('Y-m', strtotime('+1 month', $first));
$today = date('Y-m-d');

// ---------- Due items (same data as the gradebook, so states match Grades) ----------
$book = getClassGradebook($class_id, $is_owner ? null : (int) $current_user_id, !$is_owner);
$state_labels = [
    'graded' => ['graded', 'Graded'], 'submitted' => ['submitted', 'Turned in'], 'late' => ['late', 'Turned in late'],
    'done' => ['graded', 'Done'], 'timeout' => ['late', 'Time ran out'], 'open' => ['late', 'In progress'],
    'missing' => ['missing', 'Missing'], 'pending' => ['pending', 'Assigned'], 'held' => ['submitted', 'Result pending'], 'returned' => ['late', 'Returned for changes'],
];
$events = [];   // 'Y-m-d' => list of ['kind', ...]
foreach ($book['columns'] as $i => $col) {
    if (empty($col['due'])) continue;
    $day = date('Y-m-d', strtotime($col['due']));
    if ($day < $month_start || $day > $month_end) continue;
    if ($is_owner) {
        $done = 0;
        foreach ($book['rows'] as $r) {
            if (in_array($r['cells'][$i]['state'], ['submitted', 'late', 'graded', 'done', 'timeout'], true)) $done++;
        }
        $badge = [count($book['rows']) && $done === count($book['rows']) ? 'graded' : 'pending', $done . '/' . count($book['rows'])];
    } else {
        $badge = $state_labels[$book['rows'][0]['cells'][$i]['state'] ?? 'pending'] ?? $state_labels['pending'];
    }
    $events[$day][] = ['kind' => $col['type'], 'title' => $col['title'], 'time' => date('h:i A', strtotime($col['due'])),
                       'url' => APP_URL . '/' . $col['url'], 'badge' => $badge];
}

// ---------- Attendance days ----------
$statuses = attendanceStatuses();
if ($is_owner) {
    foreach ($db->fetchAll(
        "SELECT date, COUNT(*) AS marked, SUM(" . attendedSql('status') . ") AS attended
         FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? GROUP BY date", [$class_id, $month_start, $month_end]) as $a) {
        $events[$a['date']][] = ['kind' => 'attendance', 'title' => 'Attendance', 'url' => classUrl('attendance', '&date=' . $a['date']),
                                 'badge' => [(int) $a['attended'] === (int) $a['marked'] ? 'graded' : 'late', (int) $a['attended'] . '/' . (int) $a['marked'] . ' present']];
    }
} else {
    foreach ($db->fetchAll(
        "SELECT date, status FROM attendance WHERE class_id = ? AND student_id = ? AND date BETWEEN ? AND ?",
        [$class_id, $current_user_id, $month_start, $month_end]) as $a) {
        $st = $statuses[$a['status']];
        $events[$a['date']][] = ['kind' => 'attendance', 'title' => 'Attendance', 'url' => classUrl('attendance'), 'badge' => [$st[1], $st[0]]];
    }
}
// Days with no class (holiday, exam week …) set on the Attendance tab
foreach (attendanceNoClassDays($class_id, $month_start, $month_end) as $nd => $nl) {
    $events[$nd][] = ['kind' => 'attendance', 'title' => 'No class: ' . $nl, 'url' => $is_owner ? classUrl('attendance', '&date=' . $nd) : classUrl('attendance'), 'badge' => ['pending', 'No class']];
}

// Due items first (by time), attendance last
foreach ($events as &$list) {
    usort($list, fn($a, $b) => ($a['kind'] === 'attendance') <=> ($b['kind'] === 'attendance') ?: strcmp($a['time'] ?? '', $b['time'] ?? ''));
}
unset($list);

$kind_icon = ['assignment' => 'fa-tasks', 'quiz' => 'fa-question-circle', 'attendance' => 'fa-clipboard-check'];
$lead = (int) date('N', $first) - 1;   // Monday-first grid: empty cells before the 1st
$cells = array_merge(array_fill(0, $lead, null), range(1, $days_in_month));
while (count($cells) % 7) $cells[] = null;
$month_due = count(array_filter(array_merge(...array_values($events ?: [[]])), fn($e) => $e['kind'] !== 'attendance'));

$page_title = $class['class_name'] . ' - Calendar';
$page_script = '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('calendar');
?>

<div class="content-card cal-card">
    <div class="cal-toolbar">
        <div class="cal-nav">
            <a class="icon-btn" href="<?php echo classUrl('calendar', '&amp;m=' . $prev); ?>" title="Previous month" aria-label="Previous month"><i class="fas fa-chevron-left"></i></a>
            <h2><?php echo date('F Y', $first); ?></h2>
            <a class="icon-btn" href="<?php echo classUrl('calendar', '&amp;m=' . $next); ?>" title="Next month" aria-label="Next month"><i class="fas fa-chevron-right"></i></a>
            <?php if ($m !== date('Y-m')): ?>
            <a class="btn-app btn-sm btn-outline" href="<?php echo classUrl('calendar'); ?>">Today</a>
            <?php endif; ?>
        </div>
        <div class="cal-legend" aria-label="Legend">
            <span><i class="fas fa-tasks type-assignment-c"></i> Assignment due</span>
            <span><i class="fas fa-question-circle type-quiz-c"></i> Quiz due</span>
            <span><i class="fas fa-clipboard-check type-attendance-c"></i> Attendance</span>
        </div>
    </div>

    <?php if (!$events): ?>
    <p class="form-hint cal-empty">Nothing due and no attendance in <?php echo date('F', $first); ?>.</p>
    <?php else: ?>
    <p class="form-hint cal-empty"><?php echo $month_due; ?> item<?php echo $month_due == 1 ? '' : 's'; ?> due this month<?php echo $is_owner ? ' — the badge shows how many students turned it in' : ''; ?>.</p>
    <?php endif; ?>

    <div class="cal-grid" role="table" aria-label="<?php echo date('F Y', $first); ?>">
        <div class="cal-row cal-weekdays" role="row">
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $wd): ?>
            <div role="columnheader"><?php echo $wd; ?></div>
            <?php endforeach; ?>
        </div>
        <?php foreach (array_chunk($cells, 7) as $week): ?>
        <div class="cal-row" role="row">
            <?php foreach ($week as $d):
                if ($d === null): ?>
            <div class="cal-day blank" role="cell"></div>
            <?php continue; endif;
                $date = $m . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
                $day_events = $events[$date] ?? []; ?>
            <div class="cal-day<?php echo $date === $today ? ' today' : ''; ?><?php echo $day_events ? ' has-events' : ''; ?>" role="cell">
                <span class="cal-date"><span class="cal-wd"><?php echo date('D', strtotime($date)); ?></span> <?php echo $d; ?></span>
                <?php foreach ($day_events as $e): ?>
                <a class="cal-event kind-<?php echo $e['kind']; ?>" href="<?php echo htmlspecialchars($e['url']); ?>"
                   title="<?php echo htmlspecialchars($e['title'] . (isset($e['time']) ? ' · due ' . $e['time'] : '') . ' · ' . $e['badge'][1]); ?>">
                    <i class="fas <?php echo $kind_icon[$e['kind']]; ?>"></i>
                    <span class="cal-title"><?php echo htmlspecialchars($e['title']); ?></span>
                    <span class="status-badge <?php echo $e['badge'][0]; ?>"><?php echo htmlspecialchars($e['badge'][1]); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
