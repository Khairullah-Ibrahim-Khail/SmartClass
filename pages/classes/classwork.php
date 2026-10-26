<?php
/**
 * Class — Classwork: ?id=N[&type=lecture|resource|assignment|quiz]
 * Lectures, resources, assignments and quizzes grouped by topic (unit),
 * newest first inside each topic, filterable by type.
 * Teacher: Create menu, add / rename / reorder / delete topics, move work between topics.
 *   POST add_topic | rename_topic | delete_topic | move_topic_up | move_topic_down | set_topic  (class teacher)
 *   POST toggle_done   a student marks a lecture / resource done (or not done)
 * Teacher sees per lecture / resource how many students opened it (link to activity.php).
 * Search box and collapsible topics are client-side (assets/js/class.js).
 * Every POST is CSRF-checked and uses Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$self = classUrl('classwork');
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'toggle_done' && !$is_owner) {
        $it_type = (string) ($_POST['item_type'] ?? '');
        $item = progressItem($class_id, $it_type, $_POST['item_id'] ?? 0);
        if ($item) {
            setContentDone($class_id, $current_user_id, $it_type, $item['id'], !empty($_POST['done']));
        }
        redirect($self . (isset($_GET['type']) ? '&type=' . urlencode((string) $_GET['type']) : '') . ($item ? '#' . $it_type . '-' . (int) $item['id'] : ''));
    } elseif (!$is_owner) {
        $task_result = ['error', 'Not allowed', 'Only the class teacher can organise classwork.'];
    } elseif ($action === 'add_topic' || $action === 'rename_topic') {
        $name = trim($_POST['topic_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $task_result = ['error', 'Topic not saved', 'A topic name is required (up to 100 characters).'];
        } elseif ($action === 'add_topic') {
            $pos = (int) $db->fetchOne("SELECT COALESCE(MAX(position), 0) + 1 AS p FROM class_topics WHERE class_id = ?", [$class_id])['p'];
            $db->insert('class_topics', ['class_id' => $class_id, 'name' => $name, 'position' => $pos]);
            redirect($self);
        } else {
            $db->update('class_topics', ['name' => $name], 'id = ? AND class_id = ?', [(int) ($_POST['topic_id'] ?? 0), $class_id]);
            redirect($self);
        }
    } elseif ($action === 'delete_topic') {
        // Classwork in the topic is kept and moves to "No topic" (FK ON DELETE SET NULL)
        $db->delete('class_topics', 'id = ? AND class_id = ?', [(int) ($_POST['topic_id'] ?? 0), $class_id]);
        Session::flash('class_msg', ['success', 'Topic deleted', 'Its classwork was kept and moved to "No topic".']);
        redirect($self);
    } elseif ($action === 'move_topic_up' || $action === 'move_topic_down') {
        $ids = array_map('intval', array_column(getClassTopics($class_id), 'id'));
        $i = array_search((int) ($_POST['topic_id'] ?? 0), $ids, true);
        $j = $action === 'move_topic_up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $pos => $tid) {
                $db->update('class_topics', ['position' => $pos], 'id = ? AND class_id = ?', [$tid, $class_id]);
            }
        }
        redirect($self);
    } elseif ($action === 'set_topic') {
        // Move one piece of classwork into a topic of THIS class (or "No topic")
        $tables = ['lecture' => 'lectures', 'resource' => 'resources', 'assignment' => 'assignments', 'quiz' => 'quizzes'];
        $table = $tables[$_POST['item_type'] ?? ''] ?? null;
        if ($table) {
            $db->update($table, ['topic_id' => validTopicId($_POST['topic_id'] ?? 0, $class_id)],
                'id = ? AND class_id = ?', [(int) ($_POST['item_id'] ?? 0), $class_id]);
        }
        redirect($self . (isset($_GET['type']) ? '&type=' . urlencode($_GET['type']) : ''));
    } else {
        $task_result = ['error', 'Not allowed', 'Unknown action.'];
    }
}

$task_result = Session::flash('class_msg') ?? $task_result;

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$types = ['all' => 'All', 'lecture' => 'Lectures', 'resource' => 'Resources', 'assignment' => 'Assignments', 'quiz' => 'Quizzes'];
$type = $_GET['type'] ?? 'all';
if (!isset($types[$type])) $type = 'all';
$now_ts = time();
$progress = $is_owner ? [] : getStudentProgress($class_id, $current_user_id);
$reach = $is_owner ? getClassReach($class_id) : [];

$items = [];
if ($type === 'all' || $type === 'lecture') {
    // Students: published lectures only; teachers also see drafts / scheduled (marked in the meta line)
    foreach ($db->fetchAll("SELECT l.id, l.topic_id, l.title, l.video_url, l.status, l.publish_at, COALESCE(l.publish_at, l.created_at) AS created_at FROM lectures l
                            WHERE l.class_id = ?" . ($is_owner ? '' : ' AND ' . lectureVisibleSql('l')), [$class_id]) as $r) {
        $state = lectureState($r);
        $items[] = ['id' => (int) $r['id'], 'topic' => $r['topic_id'] ? (int) $r['topic_id'] : 0, 'type' => 'lecture', 'icon' => 'fa-video', 'title' => $r['title'], 'time' => $r['created_at'],
                    'meta' => ($state === 'draft' ? 'Draft · ' : ($state === 'scheduled' ? 'Scheduled ' . formatDate($r['publish_at'], 'M d, h:i A') . ' · ' : ''))
                              . ($r['video_url'] ? 'Lecture · video' : 'Lecture'), 'url' => '../lectures/view.php?id=' . (int) $r['id']];
    }
}
if ($type === 'all' || $type === 'resource') {
    foreach ($db->fetchAll("SELECT id, topic_id, title, file_type, file_size, created_at FROM resources WHERE class_id = ?", [$class_id]) as $r) {
        $items[] = ['id' => (int) $r['id'], 'topic' => $r['topic_id'] ? (int) $r['topic_id'] : 0, 'type' => 'resource', 'icon' => 'fa-folder-open', 'title' => $r['title'], 'time' => $r['created_at'],
                    'meta' => 'Resource · ' . strtoupper((string) $r['file_type']) . ' · ' . formatBytes($r['file_size']),
                    'url' => '../resources/view.php?class_id=' . $class_id . '#res-' . (int) $r['id'],
                    'download' => '../resources/download.php?id=' . (int) $r['id']];
    }
}
if ($type === 'all' || $type === 'assignment') {
    foreach ($db->fetchAll(
        "SELECT a.id, a.topic_id, a.title, a.due_date, a.max_marks, a.created_at, a.release_mode, a.results_released_at,
                (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS submitted,
                (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.status IN ('submitted', 'late')) AS ungraded,
                ms.status AS my_status, ms.marks_obtained AS my_marks
         FROM assignments a
         LEFT JOIN submissions ms ON ms.assignment_id = a.id AND ms.student_id = ?
         WHERE a.class_id = ?", [$current_user_id, $class_id]) as $r) {
        $due = $r['due_date'] ? 'Due ' . formatDate($r['due_date'], 'M d, h:i A') : 'No due date';
        if ($is_owner) {
            $status = [null, $r['submitted'] . '/' . $student_count . ' turned in'];
        } elseif ($r['my_status'] === 'graded' && !resultsReleased($r)) {
            $status = ['submitted', 'Result pending'];   // graded, but the teacher has not released the results
        } elseif ($r['my_status'] === 'graded') {
            $status = ['graded', 'Graded ' . (0 + $r['my_marks']) . '/' . (int) $r['max_marks']];
        } elseif ($r['my_status']) {
            $status = [$r['my_status'] === 'late' ? 'late' : 'submitted', $r['my_status'] === 'late' ? 'Turned in late' : 'Turned in'];
        } else {
            $status = ($r['due_date'] && $now_ts > strtotime($r['due_date'])) ? ['missing', 'Missing'] : ['pending', 'Assigned'];
        }
        $items[] = ['id' => (int) $r['id'], 'topic' => $r['topic_id'] ? (int) $r['topic_id'] : 0, 'type' => 'assignment', 'icon' => 'fa-tasks', 'title' => $r['title'], 'time' => $r['created_at'],
                    'meta' => 'Assignment · ' . $due . ' · ' . (int) $r['max_marks'] . ' marks', 'status' => $status, 'due' => $r['due_date'],
                    'to_grade' => $is_owner ? (int) $r['ungraded'] : 0,
                    'soon' => !$is_owner && $r['due_date'] && !$r['my_status'] && strtotime($r['due_date']) > $now_ts && strtotime($r['due_date']) - $now_ts < 172800,
                    'url' => '../assignments/view.php?id=' . (int) $r['id']];
    }
}
if ($type === 'all' || $type === 'quiz') {
    foreach ($db->fetchAll(
        "SELECT q.id, q.topic_id, q.title, q.due_date, q.time_limit, q.created_at, q.release_mode, q.results_released_at,
                (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS qcount,
                (SELECT COUNT(*) FROM quiz_attempts t WHERE t.quiz_id = q.id AND t.submitted_at IS NOT NULL) AS done,
                mt.submitted_at AS my_done, mt.score AS my_score, mt.max_score AS my_max, mt.id AS my_attempt
         FROM quizzes q
         LEFT JOIN quiz_attempts mt ON mt.quiz_id = q.id AND mt.student_id = ?
         WHERE q.class_id = ?", [$current_user_id, $class_id]) as $r) {
        $due = $r['due_date'] ? 'Due ' . formatDate($r['due_date'], 'M d, h:i A') : 'No due date';
        if ($is_owner) {
            $status = [null, $r['done'] . '/' . $student_count . ' completed'];
        } elseif ($r['my_done'] && !resultsReleased($r)) {
            $status = ['submitted', 'Result pending'];
        } elseif ($r['my_done']) {
            $status = ['graded', 'Score ' . (0 + $r['my_score']) . '/' . (int) $r['my_max']];
        } elseif ($r['my_attempt']) {
            $status = ['late', 'In progress'];
        } else {
            $status = ($r['due_date'] && $now_ts > strtotime($r['due_date'])) ? ['missing', 'Missed'] : ['pending', 'Not started'];
        }
        $items[] = ['id' => (int) $r['id'], 'topic' => $r['topic_id'] ? (int) $r['topic_id'] : 0, 'type' => 'quiz', 'icon' => 'fa-question-circle', 'title' => $r['title'], 'time' => $r['created_at'],
                    'meta' => 'Quiz · ' . (int) $r['qcount'] . ' questions · ' . $due . ($r['time_limit'] ? ' · ' . (int) $r['time_limit'] . ' min' : ''),
                    'status' => $status, 'due' => $r['due_date'], 'url' => '../quizzes/view.php?id=' . (int) $r['id']];
    }
}
usort($items, fn($a, $b) => strcmp($b['time'], $a['time']));

// "Due this week": assignments and quizzes due in the next 7 days, soonest first
$week_end = strtotime('+7 days', $now_ts);
$due_week = array_values(array_filter($items, fn($it) => !empty($it['due'])
    && strtotime($it['due']) >= $now_ts && strtotime($it['due']) <= $week_end));
usort($due_week, fn($a, $b) => strcmp($a['due'], $b['due']));

/** "Today", "Tomorrow" or the weekday of a due date. */
function dueDayLabel($due) {
    $d = date('Y-m-d', strtotime($due));
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('+1 day'))) return 'Tomorrow';
    return date('l', strtotime($due));
}

// Group by topic: "No topic" first (like Google Classroom), then topics in their order
$topics = getClassTopics($class_id);
$groups = [0 => ['id' => 0, 'name' => null, 'items' => []]];
foreach ($topics as $t) {
    $groups[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['name'], 'items' => []];
}
foreach ($items as $it) {
    $groups[isset($groups[$it['topic']]) ? $it['topic'] : 0]['items'][] = $it;
}


$create = [
    ['../lectures/add.php',       'fa-video',           'Lecture'],
    ['../resources/upload.php',   'fa-folder-open',     'Resource'],
    ['../assignments/create.php', 'fa-tasks',           'Assignment'],
    ['../quizzes/create.php',     'fa-question-circle', 'Quiz'],
];

$page_title = $class['class_name'] . ' - Classwork';
$page_script = classResultScript($task_result);

include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('classwork');
?>
<div class="page-toolbar">
    <div class="tab-pills">
        <?php foreach ($types as $key => $label): ?>
        <a href="<?php echo classUrl('classwork', $key === 'all' ? '' : '&amp;type=' . $key); ?>"
           class="<?php echo $type === $key ? 'active' : ''; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($is_owner): ?>
    <div class="create-menu">
        <span class="form-hint" style="margin:0 6px 0 0;">Create:</span>
        <?php foreach ($create as [$url, $icon, $label]): ?>
        <a href="<?php echo $url; ?>?class_id=<?php echo $class_id; ?>" class="btn-app btn-sm btn-outline"><i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($is_owner): ?>
<form method="POST" class="topic-add">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="add_topic">
    <input type="text" name="topic_name" maxlength="100" placeholder="New topic, e.g. Week 1 or Chapter 3" aria-label="New topic name" required>
    <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-layer-group"></i> Add topic</button>
</form>
<?php endif; ?>

<?php if ($items): ?>
<div class="cw-tools" data-class-id="<?php echo $class_id; ?>">
    <div class="cw-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="cwSearch" placeholder="Search classwork…" aria-label="Search classwork" autocomplete="off">
    </div>
    <?php if ($topics): ?>
    <button type="button" class="link-btn" id="cwCollapseAll" data-collapse-label="Collapse all topics" data-expand-label="Expand all topics">Collapse all topics</button>
    <?php endif; ?>
    <p class="form-hint cw-nomatch" id="cwNoMatch" hidden>No classwork matches your search.</p>
</div>
<?php endif; ?>

<?php if ($due_week || ($items && $type !== 'lecture' && $type !== 'resource')): ?>
<section class="content-card due-week" aria-labelledby="dueWeekTitle">
    <div class="card-head">
        <h2 id="dueWeekTitle"><i class="fas fa-calendar-week"></i> Due this week</h2>
        <span class="form-hint" style="margin:0;">next 7 days</span>
    </div>
    <?php if (!$due_week): ?>
    <p class="form-hint due-week-empty"><?php echo $is_owner ? 'Nothing is due in the next 7 days.' : 'Nothing due in the next 7 days — you are all set.'; ?></p>
    <?php else: ?>
    <?php foreach ($due_week as $it): ?>
    <a class="due-row" href="<?php echo htmlspecialchars($it['url']); ?>">
        <span class="due-day<?php echo dueDayLabel($it['due']) === 'Today' ? ' today' : ''; ?>">
            <strong><?php echo dueDayLabel($it['due']); ?></strong><span><?php echo formatDate($it['due'], 'M d · h:i A'); ?></span>
        </span>
        <span class="work-icon type-<?php echo $it['type']; ?>"><i class="fas <?php echo $it['icon']; ?>"></i></span>
        <span class="classwork-title"><?php echo htmlspecialchars($it['title']); ?></span>
        <?php if (!empty($it['status'])): ?>
        <span class="status-badge <?php echo $it['status'][0] ?? 'pending'; ?>"><?php echo htmlspecialchars($it['status'][1]); ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$items && !$topics): ?>
<div class="empty-state">
    <i class="fas fa-book-open"></i>
    <h3><?php echo $type === 'all' ? 'No classwork yet' : 'No ' . strtolower($types[$type]) . ' yet'; ?></h3>
    <p style="margin-top:6px;"><?php echo $is_owner ? 'Use Create to add lectures, files, assignments and quizzes. Topics group them into units.' : 'Your teacher has not posted anything here yet.'; ?></p>
</div>
<?php else: ?>
<?php foreach ($groups as $g):
    if ($g['id'] === 0 && !$g['items']) continue;   // hide an empty "No topic" group
    if ($g['id'] !== 0 && !$g['items'] && !$is_owner) continue;
    $ti = array_search($g['id'], array_map('intval', array_column($topics, 'id')), true);
?>
<section class="topic-group" data-topic="<?php echo $g['id']; ?>">
    <?php if ($g['id'] !== 0): ?>
    <header class="topic-head">
        <button type="button" class="topic-toggle" aria-expanded="true" aria-controls="topic-body-<?php echo $g['id']; ?>" title="Collapse / expand">
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
            <h3><?php echo htmlspecialchars($g['name']); ?></h3>
            <span class="topic-count"><?php echo count($g['items']); ?></span>
        </button>
        <?php if ($is_owner): ?>
        <div class="post-tools">
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="move_topic_up"><input type="hidden" name="topic_id" value="<?php echo $g['id']; ?>">
                <button type="submit" class="icon-btn" title="Move up" aria-label="Move topic up" <?php echo $ti === 0 ? 'disabled' : ''; ?>><i class="fas fa-arrow-up"></i></button></form>
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="move_topic_down"><input type="hidden" name="topic_id" value="<?php echo $g['id']; ?>">
                <button type="submit" class="icon-btn" title="Move down" aria-label="Move topic down" <?php echo $ti === count($topics) - 1 ? 'disabled' : ''; ?>><i class="fas fa-arrow-down"></i></button></form>
            <button type="button" class="icon-btn" data-toggle-target="rename-<?php echo $g['id']; ?>" title="Rename" aria-label="Rename topic"><i class="fas fa-pen"></i></button>
            <form method="POST" data-confirm="Delete this topic?" data-confirm-text="Its classwork is kept and moves to No topic." data-confirm-button="Delete topic">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="delete_topic"><input type="hidden" name="topic_id" value="<?php echo $g['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Delete topic" aria-label="Delete topic"><i class="fas fa-trash"></i></button></form>
        </div>
        <?php endif; ?>
    </header>
    <?php if ($is_owner): ?>
    <form method="POST" class="topic-add rename-form" id="rename-<?php echo $g['id']; ?>" hidden>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="rename_topic">
        <input type="hidden" name="topic_id" value="<?php echo $g['id']; ?>">
        <input type="text" name="topic_name" maxlength="100" value="<?php echo htmlspecialchars($g['name']); ?>" aria-label="Topic name" required>
        <button type="submit" class="btn-app btn-sm">Save</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <div class="topic-body" id="topic-body-<?php echo $g['id']; ?>">
    <?php if (!$g['items']): ?>
    <p class="form-hint topic-empty">No <?php echo $type === 'all' ? 'classwork' : strtolower($types[$type]); ?> in this topic yet.</p>
    <?php else: ?>
    <div class="content-card classwork-list">
        <?php foreach ($g['items'] as $it): ?>
        <?php $pkey = $it['type'] . ':' . $it['id']; $tracked = isset(progressItemTypes()[$it['type']]); ?>
        <div class="classwork-item" id="<?php echo $it['type'] . '-' . $it['id']; ?>">
            <span class="work-icon type-<?php echo $it['type']; ?>"><i class="fas <?php echo $it['icon']; ?>"></i></span>
            <a class="classwork-main" href="<?php echo htmlspecialchars($it['url']); ?>">
                <span class="classwork-title"><?php echo htmlspecialchars($it['title']); ?></span>
                <span class="notif-meta"><?php echo htmlspecialchars($it['meta']); ?> · Posted <?php echo formatDate($it['time'], 'M d'); ?></span>
            </a>
            <?php if (!empty($it['to_grade'])): ?>
            <a class="status-badge late" href="<?php echo htmlspecialchars($it['url']); ?>" title="Turned in, waiting for your marks" style="text-decoration:none;"><i class="fas fa-pen"></i> <?php echo $it['to_grade']; ?> to grade</a>
            <?php endif; ?>
            <?php if (!empty($it['soon'])): ?><span class="status-badge late"><i class="fas fa-hourglass-half"></i> Due soon</span><?php endif; ?>
            <?php if (!empty($it['status'])): ?>
            <span class="status-badge <?php echo $it['status'][0] ?? 'pending'; ?>"><?php echo htmlspecialchars($it['status'][1]); ?></span>
            <?php endif; ?>
            <?php if ($tracked && $is_owner): $r = $reach[$pkey] ?? ['opened' => 0, 'done' => 0]; ?>
            <a class="reach-chip" href="activity.php?id=<?php echo $class_id; ?>&amp;type=<?php echo $it['type']; ?>&amp;item=<?php echo $it['id']; ?>" title="See who opened it">
                <i class="fas fa-eye"></i> <?php echo $r['opened']; ?>/<?php echo $student_count; ?><?php if ($r['done']): ?> · <i class="fas fa-check"></i> <?php echo $r['done']; ?><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (!empty($it['download'])): ?>
            <a class="icon-btn" href="<?php echo htmlspecialchars($it['download']); ?>" title="Download" aria-label="Download"><i class="fas fa-download"></i></a>
            <?php endif; ?>
            <?php if ($tracked && !$is_owner): $is_done = !empty($progress[$pkey]['done_at']); ?>
            <form method="POST" class="done-toggle-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="toggle_done">
                <input type="hidden" name="item_type" value="<?php echo $it['type']; ?>">
                <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                <input type="hidden" name="done" value="<?php echo $is_done ? '0' : '1'; ?>">
                <button type="submit" class="done-toggle<?php echo $is_done ? ' is-done' : ''; ?>" aria-pressed="<?php echo $is_done ? 'true' : 'false'; ?>"
                    title="<?php echo $is_done ? 'Done — click to mark as not done' : 'Mark as done'; ?>" aria-label="<?php echo ($is_done ? 'Done: ' : 'Mark as done: ') . htmlspecialchars($it['title']); ?>">
                    <i class="fas fa-check"></i><span><?php echo $is_done ? 'Done' : 'Mark done'; ?></span>
                </button>
            </form>
            <?php endif; ?>
            <?php if ($is_owner && $topics): ?>
            <form method="POST" class="move-topic">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="set_topic">
                <input type="hidden" name="item_type" value="<?php echo $it['type']; ?>">
                <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                <select name="topic_id" data-autosubmit aria-label="Topic for <?php echo htmlspecialchars($it['title']); ?>">
                    <option value="0">No topic</option>
                    <?php foreach ($topics as $t): ?>
                    <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $t['id'] === $it['topic'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn-app btn-sm">Move</button></noscript>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
