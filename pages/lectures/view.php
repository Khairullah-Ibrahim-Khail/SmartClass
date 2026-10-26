<?php
/**
 * Lectures
 *   ?id=N                     one lecture: contents, reading time, videos, formatted text with
 *                             "Explain this code" (AI), notes, materials, previous / next,
 *                             mark as done (student) / who opened it (teacher), ask the teacher
 *   (none) [&class_id&topic&status&q]   every lecture of the user's classes, grouped by class + topic,
 *                             searchable; teachers see draft / scheduled, students done / new + progress
 * Teachers: classes they teach (owner or co-teacher). Students: enrolled classes, published lectures only.
 *   POST delete       teacher of the class
 *   POST toggle_done  enrolled student
 *   POST ask          enrolled student — private message to the class teachers about this lecture
 * Rendering: renderLecture() in includes/lectures.php (safe; everything escaped).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$lecture_id = (int) ($_GET['id'] ?? 0);
$lecture = null;
$class = null;
$task_result = null;
$ask_error = '';
$ask_body = '';

if ($lecture_id > 0) {
    // ---------- Single lecture ----------
    $lecture = $db->fetchOne(
        "SELECT l.*, u.name AS teacher_name, t.name AS topic_name FROM lectures l
         INNER JOIN users u ON l.teacher_id = u.id
         LEFT JOIN class_topics t ON l.topic_id = t.id
         WHERE l.id = ?", [$lecture_id]);
    $class = $lecture ? getAccessibleClass($lecture['class_id'], $current_user_id, $current_user_role) : null;
    // Students only see published lectures whose time has come
    if ($class && !$class['is_owner'] && lectureState($lecture) !== 'published') $class = null;
    if (!$class) {
        redirect(APP_URL . '/pages/lectures/view.php?error=not_found');
    }
    $self = APP_URL . '/pages/lectures/view.php?id=' . $lecture['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if (!verifyCsrf()) {
            $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        } elseif ($action === 'delete' && $class['is_owner']) {
            $db->delete('lectures', 'id = ? AND class_id = ?', [$lecture['id'], $class['id']]);
            deleteItemProgress('lecture', $lecture['id']);
            logActivity('delete_lecture', 'lectures', "Deleted lecture #{$lecture['id']} \"{$lecture['title']}\" from {$class['class_name']}");
            Session::flash('lecture_msg', ['success', 'Lecture deleted', '"' . $lecture['title'] . '" was removed.']);
            redirect(APP_URL . '/pages/lectures/view.php?class_id=' . $class['id']);
        } elseif ($action === 'toggle_done' && !$class['is_owner']) {
            setContentDone($class['id'], $current_user_id, 'lecture', $lecture['id'], !empty($_POST['done']));
            redirect($self . '#progress');
        } elseif ($action === 'ask' && !$class['is_owner']) {
            $ask_body = trim((string) ($_POST['body'] ?? ''));
            if ($ask_body === '') {
                $ask_error = 'Write your question first';
            } elseif (mb_strlen($ask_body) > 2000) {
                $ask_error = 'Your question must be under 2,000 characters';
            } else {
                postPrivateMessage($class, $current_user_id, $current_user_id, $current_user_name, ['lecture', (int) $lecture['id'], $lecture['title']], $ask_body);
                Session::flash('lecture_msg', ['success', 'Question sent', 'Your teacher will answer in Messages — you will get a notification.']);
                redirect($self . '#ask');
            }
        } else {
            $task_result = ['error', 'Not allowed', 'You do not have permission to do that.'];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        recordContentOpen($class, $current_user_id, 'lecture', $lecture['id']);
    }
    $can_explain = aiAvailableFor('study', $current_user_id, $class['id']);
    $doc = renderLecture($lecture['content'], ['explain' => $can_explain, 'run' => true, 'class_id' => (int) $class['id']]);
    $materials = getLectureMaterials($lecture['id']);
    [$prev, $next] = lectureNeighbours($class['id'], $lecture['id'], $class['is_owner']);
    $state = lectureState($lecture);
    $videos = [];
    if ($lecture['video_url'] && isHttpUrl($lecture['video_url'])) $videos[] = ['url' => $lecture['video_url'], 'title' => null];
    $files = [];
    $links = [];
    foreach ($materials as $m) {
        if ($m['kind'] === 'resource') $files[] = $m;
        elseif (isHttpUrl($m['url'])) {
            if (youtubeId($m['url'])) $videos[] = ['url' => $m['url'], 'title' => $m['title']];
            else $links[] = $m;
        }
    }
    if ($class['is_owner']) {
        $reach = getClassReach($class['id'])['lecture:' . $lecture['id']] ?? ['opened' => 0, 'done' => 0];
        $member_count = (int) $db->count('class_members', 'class_id = ?', [$class['id']]);
    } else {
        $my_progress = getStudentProgress($class['id'], $current_user_id)['lecture:' . $lecture['id']] ?? null;
        $my_questions = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM private_messages WHERE class_id = ? AND student_id = ? AND context_type = 'lecture' AND context_id = ?",
            [$class['id'], $current_user_id, $lecture['id']])['c'] ?? 0);
    }

    $page_title = $lecture['title'];
    $hide_page_head = true;   // the lecture header is the page heading (title shown once)
} else {
    // ---------- Lecture list ----------
    $classes = getUserClasses($current_user_id, $current_user_role);
    $class_ids = array_map('intval', array_column($classes, 'id'));
    $is_teacher_view = isTeacher();

    $filter_id = (int) ($_GET['class_id'] ?? 0);
    if ($filter_id > 0) {
        $class = getAccessibleClass($filter_id, $current_user_id, $current_user_role);
        if (!$class) {
            redirect(APP_URL . '/pages/lectures/view.php?error=not_found');
        }
        $class_ids = [(int) $class['id']];
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $topic_filter = (int) ($_GET['topic'] ?? 0);
    $status_options = $is_teacher_view
        ? ['' => 'All lectures', 'published' => 'Published', 'scheduled' => 'Scheduled', 'draft' => 'Drafts']
        : ['' => 'All lectures', 'todo' => 'Not done yet', 'done' => 'Done'];
    $status_filter = isset($status_options[$_GET['status'] ?? '']) ? (string) ($_GET['status'] ?? '') : '';

    $lectures = [];
    if ($class_ids) {
        $in = implode(',', array_fill(0, count($class_ids), '?'));
        $params = $class_ids;
        $where = "l.class_id IN ({$in})" . ($is_teacher_view ? '' : ' AND ' . lectureVisibleSql('l'));
        if ($q !== '') {
            $where .= " AND (l.title LIKE ? OR l.summary LIKE ? OR l.content LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($class && $topic_filter > 0) {
            $where .= " AND l.topic_id = ?";
            $params[] = $topic_filter;
        }
        $lectures = $db->fetchAll(
            "SELECT l.id, l.class_id, l.topic_id, l.title, l.summary, l.content, l.video_url, l.status, l.publish_at, l.created_at,
                    c.class_name, t.name AS topic_name,
                    (SELECT COUNT(*) FROM lecture_materials m WHERE m.lecture_id = l.id) AS material_count
             FROM lectures l
             INNER JOIN classes c ON l.class_id = c.id
             LEFT JOIN class_topics t ON l.topic_id = t.id
             WHERE {$where}
             ORDER BY c.class_name, c.id, t.position IS NULL, t.position, l.created_at, l.id LIMIT 1000", $params);
    }
    // Progress (students) / reach (teachers) per class
    $progress = [];
    $reach_all = [];
    $class_totals = [];
    foreach (array_unique(array_column($lectures, 'class_id')) as $cid) {
        if ($is_teacher_view) {
            $reach_all[$cid] = getClassReach($cid);
            $class_totals[$cid] = (int) $db->count('class_members', 'class_id = ?', [$cid]);
        } else {
            $progress[$cid] = getStudentProgress($cid, $current_user_id);
        }
    }
    // Status filter (after computing state / progress)
    $lectures = array_values(array_filter($lectures, function ($l) use ($status_filter, $is_teacher_view, $progress) {
        if ($status_filter === '') return true;
        if ($is_teacher_view) return lectureState($l) === $status_filter;
        $done = !empty($progress[$l['class_id']]['lecture:' . $l['id']]['done_at']);
        return $status_filter === 'done' ? $done : !$done;
    }));
    // Group: class → topic
    $groups = [];
    foreach ($lectures as $l) {
        $groups[$l['class_id']]['name'] = $l['class_name'];
        $groups[$l['class_id']]['topics'][$l['topic_id'] ? $l['topic_name'] : ''][] = $l;
    }
    // Student progress per class: done / visible lectures (unfiltered)
    $class_progress = [];
    if (!$is_teacher_view && $class_ids) {
        foreach ($class_ids as $cid) {
            $total = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM lectures l WHERE l.class_id = ? AND " . lectureVisibleSql('l'), [$cid])['c'] ?? 0);
            $done = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM content_progress p INNER JOIN lectures l ON l.id = p.item_id
                WHERE p.item_type = 'lecture' AND p.student_id = ? AND p.done_at IS NOT NULL AND l.class_id = ? AND " . lectureVisibleSql('l'), [$current_user_id, $cid])['c'] ?? 0);
            $class_progress[$cid] = [$done, $total];
        }
    }
    $topics = $class ? getClassTopics($class['id']) : [];

    $page_title = $class ? $class['class_name'] . ' - Lectures' : 'Lectures';
    $page_subtitle = $is_teacher_view ? 'Write, schedule and follow up your lectures' : 'Read your lectures, mark them done and ask your teacher';
}

$active_menu = 'lectures';
if ((($_GET['error'] ?? '') === 'not_found')) {
    $task_result = ['error', 'Not available', 'That lecture or class does not exist, is not published yet, or you do not have access to it.'];
}
$task_result = Session::flash('lecture_msg') ?? $task_result;
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ",
                confirmButtonColor: '#4361ee'" . ($task_result[0] === 'success' ? ", timer: 2000, showConfirmButton: false" : "") . " });" : '');
$page_scripts = $lecture ? ['assets/js/lecture.js', 'assets/js/code-run.js', 'assets/js/class.js'] : ['assets/js/class.js'];   // class.js: the delete confirmations

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<?php if ($lecture): ?>
<!-- ===== One lecture ===== -->
<div class="lec-layout">
    <article class="lec-main content-card" data-lecture="<?php echo (int) $lecture['id']; ?>">
        <nav class="lec-crumbs" aria-label="Breadcrumb">
            <a href="view.php"><i class="fas fa-video"></i> Lectures</a> ›
            <a href="view.php?class_id=<?php echo (int) $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></a>
            <?php if ($lecture['topic_name']): ?> › <a href="view.php?class_id=<?php echo (int) $class['id']; ?>&amp;topic=<?php echo (int) $lecture['topic_id']; ?>"><?php echo htmlspecialchars($lecture['topic_name']); ?></a><?php endif; ?>
        </nav>
        <header class="lec-head">
            <h1><?php echo htmlspecialchars($lecture['title']); ?></h1>
            <div class="lec-meta">
                <span><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($lecture['teacher_name']); ?></span>
                <span><i class="fas fa-calendar"></i> <?php echo formatDate($lecture['publish_at'] ?: $lecture['created_at'], 'M d, Y'); ?></span>
                <span><i class="fas fa-clock"></i> <?php echo $doc['minutes']; ?> min read</span>
                <?php if ($videos): ?><span><i class="fas fa-play-circle"></i> <?php echo count($videos); ?> video<?php echo count($videos) === 1 ? '' : 's'; ?></span><?php endif; ?>
                <?php if ($class['is_owner'] && $state !== 'published'): ?>
                <span class="status-badge <?php echo $state === 'draft' ? 'pending' : 'submitted'; ?>"><?php echo $state === 'draft' ? 'Draft — students cannot see it' : 'Scheduled for ' . formatDate($lecture['publish_at'], 'M d, h:i A'); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($class['is_owner']): ?>
            <div class="item-tools">
                <a href="add.php?id=<?php echo (int) $lecture['id']; ?>" class="btn-app btn-sm btn-outline"><i class="fas fa-pen"></i> Edit</a>
                <form method="POST" data-confirm="Delete this lecture?" data-confirm-text="Students will no longer see it. This cannot be undone." data-confirm-button="Delete">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn-app btn-sm btn-outline btn-danger-outline"><i class="fas fa-trash"></i> Delete</button>
                </form>
            </div>
            <?php endif; ?>
        </header>

        <?php if ($lecture['summary']): ?><p class="lec-summary"><?php echo htmlspecialchars($lecture['summary']); ?></p><?php endif; ?>

        <?php foreach ($videos as $v): $yt = youtubeId($v['url']); ?>
        <?php if ($yt): ?>
        <div class="video-embed">
            <iframe src="https://www.youtube-nocookie.com/embed/<?php echo $yt; ?>" title="<?php echo htmlspecialchars($v['title'] ?: 'Lecture video'); ?>" loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
        <?php else: ?>
        <p><a class="btn-app btn-outline btn-sm" href="<?php echo htmlspecialchars($v['url']); ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-play-circle"></i> Watch the lecture video</a></p>
        <?php endif; ?>
        <?php endforeach; ?>

        <div class="lec-body"><?php echo $doc['html']; ?></div>

        <?php if (!empty($lecture['notes'])): ?>
        <aside class="lec-notes">
            <strong><i class="fas fa-sticky-note"></i> Teacher notes</strong>
            <?php echo renderLecture($lecture['notes'], ['class_id' => (int) $class['id']])['html']; ?>
        </aside>
        <?php endif; ?>

        <?php if ($files || $links): ?>
        <section class="lec-materials">
            <h2 class="lec-h2"><i class="fas fa-paperclip"></i> Materials</h2>
            <div class="materials-list" style="padding:0;">
                <?php foreach ($files as $m): ?>
                <a class="material-item" href="<?php echo APP_URL; ?>/pages/resources/download.php?id=<?php echo (int) $m['resource_id']; ?>">
                    <i class="fas fa-file-alt" aria-hidden="true"></i>
                    <span class="m-text"><strong><?php echo htmlspecialchars($m['resource_title']); ?></strong>
                        <span class="notif-meta">File · <?php echo htmlspecialchars(strtoupper((string) $m['file_type'])); ?> · <?php echo formatBytes($m['file_size']); ?></span></span>
                </a>
                <?php endforeach; ?>
                <?php foreach ($links as $m): ?>
                <a class="material-item link" href="<?php echo htmlspecialchars($m['url']); ?>" target="_blank" rel="noopener noreferrer">
                    <i class="fas fa-link" aria-hidden="true"></i>
                    <span class="m-text"><strong><?php echo htmlspecialchars($m['title'] ?: (parse_url($m['url'], PHP_URL_HOST) ?: $m['url'])); ?></strong>
                        <span class="notif-meta"><?php echo htmlspecialchars($m['url']); ?></span></span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <nav class="lec-pager" aria-label="Previous and next lecture">
            <?php if ($prev): ?>
            <a class="lec-pager-link" href="view.php?id=<?php echo (int) $prev['id']; ?>"><span><i class="fas fa-arrow-left"></i> Previous</span><strong><?php echo htmlspecialchars($prev['title']); ?></strong></a>
            <?php else: ?><span></span><?php endif; ?>
            <?php if ($next): ?>
            <a class="lec-pager-link next" href="view.php?id=<?php echo (int) $next['id']; ?>"><span>Next <i class="fas fa-arrow-right"></i></span><strong><?php echo htmlspecialchars($next['title']); ?></strong></a>
            <?php endif; ?>
        </nav>
    </article>

    <aside class="lec-side">
        <?php if (count($doc['toc']) > 1): ?>
        <div class="content-card lec-toc">
            <h2 class="lec-h2"><i class="fas fa-list-ul"></i> On this page</h2>
            <nav aria-label="Lecture contents">
                <?php foreach ($doc['toc'] as [$level, $id, $text]): ?>
                <a href="#<?php echo $id; ?>" class="lvl-<?php echo $level; ?>"><?php echo htmlspecialchars($text); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php endif; ?>

        <div class="content-card lec-progress" id="progress">
            <?php if ($class['is_owner']): ?>
            <h2 class="lec-h2"><i class="fas fa-users"></i> Students</h2>
            <div class="lec-stat"><strong><?php echo $reach['opened']; ?>/<?php echo $member_count; ?></strong> opened it</div>
            <div class="lec-stat"><strong><?php echo $reach['done']; ?>/<?php echo $member_count; ?></strong> marked it done</div>
            <a class="btn-app btn-sm btn-outline" href="<?php echo APP_URL; ?>/pages/classes/activity.php?id=<?php echo (int) $class['id']; ?>&amp;type=lecture&amp;item=<?php echo (int) $lecture['id']; ?>"><i class="fas fa-users"></i> See who opened it</a>
            <?php else: $is_done = !empty($my_progress['done_at']); ?>
            <h2 class="lec-h2"><i class="fas fa-check-circle"></i> My progress</h2>
            <form method="POST" class="done-form">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_done"><input type="hidden" name="done" value="<?php echo $is_done ? '0' : '1'; ?>">
                <?php if ($is_done): ?>
                <span class="progress-stat done"><i class="fas fa-check-circle"></i> Done on <?php echo formatDate($my_progress['done_at'], 'M d, h:i A'); ?></span>
                <button type="submit" class="btn-app btn-sm btn-outline">Mark as not done</button>
                <?php else: ?>
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-check"></i> Mark as done</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$class['is_owner'] && $can_explain && trim((string) $lecture['content']) !== ''): ?>
        <div class="content-card lec-study" id="study" data-class="<?php echo (int) $class['id']; ?>" data-tutor="<?php echo htmlspecialchars(APP_URL . '/pages/ai/tutor.php'); ?>">
            <h2 class="lec-h2"><i class="fas fa-robot"></i> AI study chat</h2>
            <div class="study-buttons">
                <button type="button" class="btn-app btn-sm btn-outline" data-study="summary"><i class="fas fa-align-left"></i> Summarize</button>
                <button type="button" class="btn-app btn-sm btn-outline" data-study="terms"><i class="fas fa-key"></i> Key terms</button>
                <button type="button" class="btn-app btn-sm btn-outline" data-study="practice"><i class="fas fa-vial"></i> Practice quiz</button>
            </div>
            <div class="study-log" id="studyLog" aria-live="polite">
                <div class="st-b ai">Hi! Ask me anything about this lecture, or use the buttons above. I explain and quiz — I won't write answers for graded work.</div>
            </div>
            <form class="study-chat" id="studyForm" data-no-ajax onsubmit="return false;">
                <textarea id="studyText" rows="2" maxlength="1000" placeholder="Ask about this lecture…  (Enter to send)" aria-label="Ask a question about this lecture"></textarea>
                <button type="submit" class="btn-app" id="studySend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
            </form>
            <p class="form-hint" style="margin:8px 0 0;">AI can make mistakes — ask your teacher if unsure.</p>
        </div>
        <?php endif; ?>

        <?php if (!$class['is_owner']): ?>
        <div class="content-card lec-ask" id="ask">
            <h2 class="lec-h2"><i class="fas fa-question-circle"></i> Ask your teacher</h2>
            <form method="POST" novalidate>
                <?php echo csrfField(); ?><input type="hidden" name="action" value="ask">
                <div class="form-group" style="margin:0 0 10px;">
                    <textarea name="body" rows="3" maxlength="2000" aria-label="Your question about this lecture" placeholder="Something unclear in this lecture? Only your teacher sees it."
                        class="<?php echo $ask_error ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($ask_body); ?></textarea>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($ask_error); ?></div>
                </div>
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> Send question</button>
            </form>
            <?php if ($my_questions): ?>
            <a class="link-btn lec-thread-link" href="<?php echo APP_URL . '/' . htmlspecialchars(messageThreadUrl($class['id'], $current_user_id, 'lecture', $lecture['id'])); ?>">Your conversation about this lecture (<?php echo $my_questions; ?>) <i class="fas fa-arrow-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$can_explain && $doc['code'] && !$class['is_owner']): ?>
        <p class="form-hint lec-ai-hint"><i class="fas fa-robot"></i> "Explain this code" appears when your teacher switches AI on for this class.</p>
        <?php endif; ?>
    </aside>
</div>

<?php else: ?>
<!-- ===== Lecture list ===== -->
<form method="GET" class="lec-filters" data-live data-no-ajax>
    <div class="cw-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search lectures…" aria-label="Search lectures">
    </div>
    <?php if (!empty($classes)): ?>
    <select name="class_id" aria-label="Class">
        <option value="0">All my classes</option>
        <?php foreach ($classes as $c): ?>
        <option value="<?php echo (int) $c['id']; ?>" <?php echo ($class && (int) $class['id'] === (int) $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($topics): ?>
    <select name="topic" aria-label="Topic">
        <option value="0">All topics</option>
        <?php foreach ($topics as $t): ?>
        <option value="<?php echo (int) $t['id']; ?>" <?php echo $topic_filter === (int) $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="status" aria-label="Show">
        <?php foreach ($status_options as $k => $label): ?>
        <option value="<?php echo $k; ?>" <?php echo $status_filter === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-search"></i> Search</button>
    <?php if ($is_teacher_view && !empty($classes)): ?>
    <a href="add.php<?php echo $class ? '?class_id=' . (int) $class['id'] : ''; ?>" class="btn-app btn-sm lec-add"><i class="fas fa-plus"></i> Add Lecture</a>
    <?php endif; ?>
</form>
<div data-live-results>

<?php if (empty($lectures)): ?>
<div class="empty-state">
    <i class="fas fa-video"></i>
    <h3><?php echo ($q !== '' || $status_filter !== '' || $topic_filter) ? 'No lectures match' : 'No lectures yet'; ?></h3>
    <p style="margin-top:6px;">
        <?php if ($q !== '' || $status_filter !== '' || $topic_filter): ?>Try other words or filters.
        <?php elseif (empty($classes)): ?><?php echo $is_teacher_view ? 'Create a class first, then add lectures to it.' : 'Join a class to see its lectures.'; ?>
        <?php else: ?><?php echo $is_teacher_view ? 'Add the first lecture for your students.' : 'Your teacher has not published any lectures yet.'; ?><?php endif; ?>
    </p>
</div>
<?php else: ?>
<?php foreach ($groups as $cid => $g): ?>
<section class="lec-group">
    <header class="lec-group-head">
        <h2><a href="view.php?class_id=<?php echo (int) $cid; ?>"><?php echo htmlspecialchars($g['name']); ?></a></h2>
        <?php if (!$is_teacher_view && isset($class_progress[$cid]) && $class_progress[$cid][1] > 0): [$d, $tot] = $class_progress[$cid]; ?>
        <div class="lec-progressbar" title="<?php echo $d; ?> of <?php echo $tot; ?> lectures done">
            <span class="bar"><span style="width:<?php echo round($d / $tot * 100); ?>%"></span></span>
            <span class="form-hint" style="margin:0;"><?php echo $d; ?> of <?php echo $tot; ?> done</span>
        </div>
        <?php endif; ?>
    </header>
    <?php foreach ($g['topics'] as $topic_name => $items): ?>
    <?php if ($topic_name !== ''): ?><h3 class="lec-topic"><?php echo htmlspecialchars($topic_name); ?></h3><?php endif; ?>
    <div class="lec-cards">
        <?php foreach ($items as $l):
            $st = lectureState($l);
            $p = $progress[$l['class_id']]['lecture:' . $l['id']] ?? null;
            $r = $reach_all[$l['class_id']]['lecture:' . $l['id']] ?? ['opened' => 0, 'done' => 0];
            $excerpt = $l['summary'] ?: mb_strimwidth(trim(preg_replace('/\s+/', ' ', preg_replace('/```.*?```|[#*_`>\[\]]/s', ' ', (string) $l['content']))), 0, 140, '…');
            $mins = max(1, (int) ceil(str_word_count((string) $l['content']) / 200)); ?>
        <a class="lec-card<?php echo !$is_teacher_view && !empty($p['done_at']) ? ' is-done' : ''; ?>" href="view.php?id=<?php echo (int) $l['id']; ?>">
            <span class="lec-card-icon" aria-hidden="true"><i class="fas <?php echo !$is_teacher_view && !empty($p['done_at']) ? 'fa-check' : ($l['video_url'] ? 'fa-play' : 'fa-book-open'); ?>"></i></span>
            <span class="lec-card-body">
                <strong><?php echo htmlspecialchars($l['title']); ?></strong>
                <span class="lec-card-excerpt"><?php echo htmlspecialchars($excerpt); ?></span>
                <span class="notif-meta"><?php echo formatDate($l['publish_at'] ?: $l['created_at'], 'M d, Y'); ?> · <?php echo $mins; ?> min<?php echo $l['video_url'] ? ' · video' : ''; ?><?php echo $l['material_count'] ? ' · ' . (int) $l['material_count'] . ' material' . ($l['material_count'] == 1 ? '' : 's') : ''; ?></span>
            </span>
            <span class="lec-card-badges">
                <?php if ($is_teacher_view): ?>
                <?php if ($st === 'draft'): ?><span class="status-badge pending">Draft</span>
                <?php elseif ($st === 'scheduled'): ?><span class="status-badge submitted" title="Students see it on <?php echo formatDate($l['publish_at'], 'M d, h:i A'); ?>">Scheduled <?php echo formatDate($l['publish_at'], 'M d'); ?></span>
                <?php else: ?><span class="reach-chip"><i class="fas fa-eye"></i> <?php echo $r['opened']; ?>/<?php echo $class_totals[$l['class_id']] ?? 0; ?></span><?php endif; ?>
                <?php else: ?>
                <?php if (!empty($p['done_at'])): ?><span class="status-badge graded">Done</span>
                <?php elseif (!$p): ?><span class="status-badge submitted">New</span><?php endif; ?>
                <?php endif; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
</div><!-- /data-live-results -->
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
