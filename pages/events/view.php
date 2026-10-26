<?php
/**
 * Events — one-off events of the user's classes (trip, meeting, exam day): when, where, details.
 *   ?class_id=N one class;  ?past=1 events that already happened.
 * Teachers delete their own classes' events here (POST + CSRF); events are created on create.php.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif (!isTeacher() || ($_POST['action'] ?? '') !== 'delete') {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can delete an event.'];
    } else {
        $ev = $db->fetchOne("SELECT e.id, e.title FROM class_events e INNER JOIN classes c ON e.class_id = c.id WHERE e.id = ? AND " . teachesClassSql('c'),
            [(int) ($_POST['event_id'] ?? 0), $current_user_id]);
        if (!$ev) {
            $task_result = ['error', 'Not found', 'That event is not in one of your classes.'];
        } else {
            $db->delete('class_events', 'id = ?', [$ev['id']]);
            logActivity('delete_event', 'events', "Deleted event #{$ev['id']} \"{$ev['title']}\"");
            Session::flash('event_msg', ['success', 'Event deleted', '"' . $ev['title'] . '" was removed.']);
            redirect(APP_URL . '/pages/events/view.php');
        }
    }
}
$task_result = Session::flash('event_msg') ?? $task_result;

$classes = getUserClasses($current_user_id, $current_user_role);
$class_ids = array_map('intval', array_column($classes, 'id'));
$filter = (int) ($_GET['class_id'] ?? 0);
if ($filter && !in_array($filter, $class_ids, true)) $filter = 0;
$past = !empty($_GET['past']);
$use_ids = $filter ? [$filter] : $class_ids;

$q = trim((string) ($_GET['q'] ?? ''));
$events = []; $total = 0; $per = pagerPerPage();
if ($use_ids) {
    $in = implode(',', array_fill(0, count($use_ids), '?'));
    $params = $use_ids; $search = '';
    if ($q !== '') { $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%'; $search = ' AND (e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)'; array_push($params, $like, $like, $like); }
    $when = $past ? "COALESCE(e.ends_at, e.starts_at) < NOW()" : "COALESCE(e.ends_at, e.starts_at) >= NOW()";
    $total = (int) $db->fetchOne("SELECT COUNT(*) c FROM class_events e WHERE e.class_id IN ({$in}) AND {$when}{$search}", $params)['c'];
    [$page_no, $pages, $offset] = pagerWindow($total, $per);
    $events = $db->fetchAll(
        "SELECT e.*, c.class_name, u.name AS author FROM class_events e INNER JOIN classes c ON e.class_id = c.id LEFT JOIN users u ON e.created_by = u.id
         WHERE e.class_id IN ({$in}) AND {$when}{$search}
         ORDER BY e.starts_at " . ($past ? 'DESC' : 'ASC') . " LIMIT {$per} OFFSET {$offset}", $params);
}

$page_title = 'Events';
$page_subtitle = isTeacher() ? 'Trips, meetings and special days in your classes' : 'What is coming up in your classes';
$active_menu = 'events';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '');
$page_scripts = isTeacher() ? ['assets/js/class.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="page-toolbar">
    <form method="GET" class="filter-form" data-live data-no-ajax>
        <?php if ($classes): ?>
        <select id="classFilter" name="class_id" aria-label="Filter by class">
            <option value="0">All my classes</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $filter === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search events…" aria-label="Search events" style="min-width:180px;">
        <?php if ($past): ?><input type="hidden" name="past" value="1"><?php endif; ?>
        <noscript><button type="submit" class="btn-app btn-sm">Filter</button></noscript>
            <?php echo pagerPerSelect($per, $total); ?>
    </form>
    <div class="action-buttons">
        <a class="btn-app btn-outline" href="view.php?<?php echo http_build_query(array_filter(['class_id' => $filter, 'past' => $past ? null : 1])); ?>"><i class="fas fa-history"></i> <?php echo $past ? 'Upcoming events' : 'Past events'; ?></a>
        <?php if (isTeacher() && $classes): ?><a href="create.php<?php echo $filter ? '?class_id=' . $filter : ''; ?>" class="btn-app"><i class="fas fa-plus"></i> New Event</a><?php endif; ?>
    </div>
</div>
<div data-live-results>

<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-calendar-check"></i><h3>No classes yet</h3></div>
<?php elseif (!$events): ?>
<div class="empty-state"><i class="fas fa-calendar-check"></i><h3><?php echo $q !== '' ? 'No events match your search' : ($past ? 'No past events' : 'No upcoming events'); ?></h3></div>
<?php else: ?>
<div class="lecture-list">
    <?php foreach ($events as $e): $s = strtotime($e['starts_at']); ?>
    <div class="lecture-card event-card">
        <div class="event-date" aria-hidden="true"><span><?php echo date('M', $s); ?></span><strong><?php echo date('j', $s); ?></strong></div>
        <div class="lecture-info">
            <div class="lecture-title"><?php echo htmlspecialchars($e['title']); ?></div>
            <div class="lecture-meta">
                <span><i class="fas fa-chalkboard"></i> <?php echo htmlspecialchars($e['class_name']); ?></span>
                <span><i class="fas fa-clock"></i> <?php echo date('D, M j · h:i A', $s); ?><?php echo $e['ends_at'] ? ' – ' . (date('Y-m-d', $s) === date('Y-m-d', strtotime($e['ends_at'])) ? date('h:i A', strtotime($e['ends_at'])) : date('D, M j · h:i A', strtotime($e['ends_at']))) : ''; ?></span>
                <?php if ($e['location']): ?><span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($e['location']); ?></span><?php endif; ?>
            </div>
            <?php if ($e['description']): ?><div class="prewrap" style="margin-top:6px;"><?php echo linkify($e['description']); ?></div><?php endif; ?>
        </div>
        <?php if (isTeacher()): ?>
        <div class="lecture-actions">
            <a class="icon-btn" href="create.php?id=<?php echo (int) $e['id']; ?>" title="Edit event" aria-label="Edit event <?php echo htmlspecialchars($e['title']); ?>"><i class="fas fa-pen"></i></a>
            <form method="POST" data-confirm="Delete this event?" data-confirm-text="Students will no longer see it." data-confirm-button="Delete">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="<?php echo (int) $e['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Delete event" aria-label="Delete event <?php echo htmlspecialchars($e['title']); ?>"><i class="fas fa-trash"></i></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php echo pagerHtml($total, $page_no, $per); ?>
<?php endif; ?>
</div><!-- /data-live-results -->
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
