<?php
/**
 * Class — Stream: ?id=N
 * Posts (teacher; students too when the class allows it) with comments,
 * pinned posts first, auto-posts for new class work, and an "Upcoming" panel.
 *   POST post_announcement   teacher, or student when stream_permission = post
 *   POST edit_announcement    the post's author only (marked "Edited")
 *   POST publish_now          class teacher: a scheduled post goes out now
 * Teacher posts can be scheduled (publish_at): students do not see them, or comment,
 * until then; the class is notified once when the time comes (publishDueAnnouncements).
 * Links (http/https) in posts and comments are clickable (linkify).
 *   POST delete_announcement  the post's author or the class teacher
 *   POST pin / unpin          class teacher
 *   POST post_comment         teacher, or student unless stream_permission = teacher_only
 *   POST delete_comment       the comment's author or the class teacher
 * Every POST is CSRF-checked and uses Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$self = classUrl('stream');
$composer_error = '';
$composer_body = '';
$schedule_error = '';
$schedule_value = '';
$comment_errors = [];   // announcement id => message
$edit_errors = [];      // announcement id => message (edit form re-opened with the text)
$edit_drafts = [];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'post_announcement' && canPostToStream($class)) {
        $composer_body = trim($_POST['body'] ?? '');
        // Teacher only: optional publish time (<input type="datetime-local"> → "2026-10-25T08:00")
        $schedule_value = $is_owner ? trim((string) ($_POST['publish_at'] ?? '')) : '';
        $publish_at = null;
        if ($schedule_value !== '') {
            $when = DateTime::createFromFormat('Y-m-d\TH:i', $schedule_value);
            if (!$when || $when->format('Y-m-d\TH:i') !== $schedule_value) {
                $schedule_error = 'Please enter a valid date and time';
            } elseif ($when->getTimestamp() <= time() + 60) {
                $schedule_error = 'Pick a time in the future, or clear it to post now';
            } elseif ($when->getTimestamp() > strtotime('+1 year')) {
                $schedule_error = 'You can schedule up to one year ahead';
            } else {
                $publish_at = $when->format('Y-m-d H:i:00');
            }
        }
        if ($composer_body === '') {
            $composer_error = 'Write something to share with your class';
        } elseif (mb_strlen($composer_body) > 5000) {
            $composer_error = 'Post must be under 5,000 characters';
        } elseif ($schedule_error === '' && $publish_at) {
            $aid = $db->insert('announcements', ['class_id' => $class_id, 'user_id' => $current_user_id, 'body' => $composer_body,
                                                 'publish_at' => $publish_at]);   // notified_at stays NULL until it is due
            logActivity('schedule_announcement', 'classes', "Scheduled a post in {$class['class_name']} (#{$aid}) for {$publish_at}");
            Session::flash('class_msg', ['success', 'Scheduled', 'Students will see it on ' . date('M d, Y \a\t h:i A', strtotime($publish_at)) . ' and be notified then.']);
            redirect($self . '#post-' . $aid);
        } elseif ($schedule_error === '') {
            $aid = $db->insert('announcements', ['class_id' => $class_id, 'user_id' => $current_user_id, 'body' => $composer_body,
                                                 'notified_at' => date('Y-m-d H:i:s')]);
            logActivity('post_announcement', 'classes', "Posted to the stream of {$class['class_name']} (#{$aid})");
            $post_link = 'pages/classes/stream.php?id=' . $class_id . '#post-' . $aid;
            if ($is_owner) {
                notifyClassStudents($class_id, 'announcement_posted', 'New announcement in ' . $class['class_name'],
                    mb_strimwidth($composer_body, 0, 140, '…'), $post_link, $current_user_id);
                Session::flash('class_msg', ['success', 'Posted', 'Your class has been notified.']);
            } else {
                notifyClassTeachers($class_id, 'announcement_posted', $current_user_name . ' posted in ' . $class['class_name'],
                    mb_strimwidth($composer_body, 0, 140, '…'), $post_link, $current_user_id);
                Session::flash('class_msg', ['success', 'Posted', 'Your post is visible to the class.']);
            }
            redirect($self . '#post-' . $aid);
        }
    } elseif ($action === 'edit_announcement') {
        $ann = $db->fetchOne("SELECT id, user_id FROM announcements WHERE id = ? AND class_id = ?",
            [(int) ($_POST['announcement_id'] ?? 0), $class_id]);
        $edit_body = trim($_POST['body'] ?? '');
        if (!$ann || (int) $ann['user_id'] !== (int) $current_user_id) {
            $task_result = ['error', 'Not allowed', 'You can only edit your own posts.'];
        } elseif ($edit_body === '') {
            $edit_errors[$ann['id']] = 'The post cannot be empty';
            $edit_drafts[$ann['id']] = $edit_body;
        } elseif (mb_strlen($edit_body) > 5000) {
            $edit_errors[$ann['id']] = 'Post must be under 5,000 characters';
            $edit_drafts[$ann['id']] = $edit_body;
        } else {
            $db->update('announcements', ['body' => $edit_body, 'edited_at' => date('Y-m-d H:i:s')], 'id = ?', [$ann['id']]);
            logActivity('edit_announcement', 'classes', "Edited a stream post in {$class['class_name']} (#{$ann['id']})");
            Session::flash('class_msg', ['success', 'Saved', 'Your post was updated.']);
            redirect($self . '#post-' . $ann['id']);
        }
    } elseif ($action === 'publish_now' && $is_owner) {
        $ann = $db->fetchOne("SELECT id FROM announcements WHERE id = ? AND class_id = ? AND notified_at IS NULL",
            [(int) ($_POST['announcement_id'] ?? 0), $class_id]);
        if ($ann) {
            $db->update('announcements', ['publish_at' => null, 'created_at' => date('Y-m-d H:i:s')], 'id = ?', [$ann['id']]);
            publishDueAnnouncements($class_id);
            Session::flash('class_msg', ['success', 'Posted', 'Your class has been notified.']);
        }
        redirect($self . ($ann ? '#post-' . $ann['id'] : ''));
    } elseif ($action === 'reuse_post' && $is_owner) {
        // Post a copy of one of this class's posts to another class the teacher teaches (or to this one again)
        $ann = $db->fetchOne("SELECT id, body FROM announcements WHERE id = ? AND class_id = ? AND (publish_at IS NULL OR publish_at <= NOW())",
            [(int) ($_POST['announcement_id'] ?? 0), $class_id]);
        $target = getAccessibleClass((int) ($_POST['target_class_id'] ?? 0), $current_user_id, 'teacher');
        if (!$ann || !$target || !$target['is_owner'] || !$target['is_active']) {
            $task_result = ['error', 'Not possible', 'Choose one of your own active classes.'];
        } else {
            $aid = $db->insert('announcements', ['class_id' => $target['id'], 'user_id' => $current_user_id, 'body' => $ann['body'], 'notified_at' => date('Y-m-d H:i:s')]);
            logActivity('reuse_announcement', 'classes', "Reused post #{$ann['id']} of {$class['class_name']} in {$target['class_name']} (#{$aid})");
            notifyClassStudents($target['id'], 'announcement_posted', 'New announcement in ' . $target['class_name'],
                mb_strimwidth($ann['body'], 0, 140, '…'), 'pages/classes/stream.php?id=' . $target['id'] . '#post-' . $aid, $current_user_id);
            Session::flash('class_msg', ['success', 'Posted again', $target['class_name'] . ' has the post, and its students were notified.']);
            redirect(APP_URL . '/pages/classes/stream.php?id=' . $target['id'] . '#post-' . $aid);
        }
    } elseif ($action === 'delete_announcement') {
        $ann = $db->fetchOne("SELECT id, user_id FROM announcements WHERE id = ? AND class_id = ?",
            [(int) ($_POST['announcement_id'] ?? 0), $class_id]);
        if ($ann && ($is_owner || (int) $ann['user_id'] === (int) $current_user_id)) {
            $db->delete('announcements', 'id = ?', [$ann['id']]);
            Session::flash('class_msg', ['success', 'Deleted', 'The post and its comments were removed.']);
            redirect($self);
        }
        $task_result = ['error', 'Not allowed', 'You can only delete your own posts.'];
    } elseif (($action === 'pin_announcement' || $action === 'unpin_announcement') && $is_owner) {
        $db->update('announcements', ['pinned' => $action === 'pin_announcement' ? 1 : 0],
            'id = ? AND class_id = ?', [(int) ($_POST['announcement_id'] ?? 0), $class_id]);
        redirect($self);
    } elseif ($action === 'post_comment' && canCommentOnStream($class)) {
        // Only posts the commenter can see (a scheduled post is hidden from students until its time)
        $ann = $db->fetchOne("SELECT a.id, a.user_id FROM announcements a WHERE a.id = ? AND a.class_id = ? AND " . announcementVisibleSql(),
            [(int) ($_POST['announcement_id'] ?? 0), $class_id, $current_user_id]);
        $body = trim($_POST['body'] ?? '');
        if (!$ann) {
            $task_result = ['error', 'Not found', 'That post no longer exists.'];
        } elseif ($body === '') {
            $comment_errors[$ann['id']] = 'Write a comment first';
        } elseif (mb_strlen($body) > 2000) {
            $comment_errors[$ann['id']] = 'Comment must be under 2,000 characters';
        } else {
            $db->insert('announcement_comments', ['announcement_id' => $ann['id'], 'user_id' => $current_user_id, 'body' => $body]);
            if ((int) $ann['user_id'] !== (int) $current_user_id) {
                notify($ann['user_id'], 'comment_posted', $current_user_name . ' commented in ' . $class['class_name'],
                    mb_strimwidth($body, 0, 140, '…'), 'pages/classes/stream.php?id=' . $class_id . '#post-' . $ann['id'],
                    $current_user_id, $class_id);
            }
            redirect($self . '#post-' . $ann['id']);
        }
    } elseif ($action === 'delete_comment') {
        // The comment's author or the class teacher may delete it
        $c = $db->fetchOne(
            "SELECT ac.id, ac.user_id, ac.announcement_id FROM announcement_comments ac
             INNER JOIN announcements a ON ac.announcement_id = a.id
             WHERE ac.id = ? AND a.class_id = ?", [(int) ($_POST['comment_id'] ?? 0), $class_id]);
        if ($c && ($is_owner || (int) $c['user_id'] === (int) $current_user_id)) {
            $db->delete('announcement_comments', 'id = ?', [$c['id']]);
            redirect($self . '#post-' . $c['announcement_id']);
        }
        $task_result = ['error', 'Not allowed', 'You can only delete your own comments.'];
    } else {
        $task_result = ['error', 'Not allowed', 'You do not have permission to do that.'];
    }
}

$task_result = Session::flash('class_msg') ?? $task_result;

// ---------------------------------------------------------------
// Data
// ---------------------------------------------------------------
$now = date('Y-m-d H:i:s');

// ---------- Upcoming (due, not done yet for students) ----------
$upcoming = [];
foreach ($db->fetchAll(
    "SELECT a.id, a.title, a.due_date,
            (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?) AS mine
     FROM assignments a WHERE a.class_id = ? AND a.due_date >= ? ORDER BY a.due_date LIMIT 5",
    [$current_user_id, $class_id, $now]) as $a) {
    if (!$is_owner && $a['mine']) continue;
    $upcoming[] = ['icon' => 'fa-tasks', 'title' => $a['title'], 'due' => $a['due_date'], 'url' => '../assignments/view.php?id=' . (int) $a['id']];
}
foreach ($db->fetchAll(
    "SELECT q.id, q.title, q.due_date,
            (SELECT COUNT(*) FROM quiz_attempts t WHERE t.quiz_id = q.id AND t.student_id = ? AND t.submitted_at IS NOT NULL) AS mine
     FROM quizzes q WHERE q.class_id = ? AND q.due_date >= ? ORDER BY q.due_date LIMIT 5",
    [$current_user_id, $class_id, $now]) as $q) {
    if (!$is_owner && $q['mine']) continue;
    $upcoming[] = ['icon' => 'fa-question-circle', 'title' => $q['title'], 'due' => $q['due_date'], 'url' => '../quizzes/view.php?id=' . (int) $q['id']];
}
usort($upcoming, fn($a, $b) => strcmp($a['due'], $b['due']));
$upcoming = array_slice($upcoming, 0, 5);

// ---------- Feed: announcements + auto-posts for class work ----------
$feed = [];
$announcements = $db->fetchAll(
    "SELECT a.*, COALESCE(a.publish_at, a.created_at) AS shown_at, u.name AS author, u.role AS author_role
     FROM announcements a INNER JOIN users u ON a.user_id = u.id
     WHERE a.class_id = ? AND " . announcementVisibleSql() . "
     ORDER BY a.pinned DESC, shown_at DESC LIMIT 40", [$class_id, $current_user_id]);
$comments = [];
if ($announcements) {
    $ids = array_column($announcements, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach ($db->fetchAll(
        "SELECT c.*, u.name AS author, u.role FROM announcement_comments c INNER JOIN users u ON c.user_id = u.id
         WHERE c.announcement_id IN ({$in}) ORDER BY c.created_at, c.id", $ids) as $c) {
        $comments[$c['announcement_id']][] = $c;
    }
}
$pinned = [];
foreach ($announcements as $a) {
    $a['scheduled'] = $a['notified_at'] === null && $a['publish_at'] !== null && strtotime($a['publish_at']) > time();
    if ($a['pinned'] || $a['scheduled']) {   // the author's scheduled posts stay at the top until they go out
        $pinned[] = ['kind' => 'announcement', 'time' => $a['shown_at'], 'row' => $a];
    } else {
        $feed[] = ['kind' => 'announcement', 'time' => $a['shown_at'], 'row' => $a];
    }
}
$work_queries = [
    // the author is whoever made it (owner or co-teacher)
    ['lecture',    'fa-video',           'posted a new lecture',    "SELECT x.id, x.title, COALESCE(x.publish_at, x.created_at) AS created_at, u.name AS author FROM lectures x LEFT JOIN users u ON u.id = x.teacher_id WHERE x.class_id = ? AND " . lectureVisibleSql('x') . " ORDER BY created_at DESC LIMIT 15",    '../lectures/view.php?id='],
    ['resource',   'fa-folder-open',     'shared a new resource',   "SELECT x.id, x.title, x.created_at, u.name AS author FROM resources x LEFT JOIN users u ON u.id = x.uploaded_by WHERE x.class_id = ? ORDER BY x.created_at DESC LIMIT 15",   '../resources/view.php?class_id=' . $class_id . '#res-'],
    ['assignment', 'fa-tasks',           'posted a new assignment', "SELECT x.id, x.title, x.created_at, u.name AS author FROM assignments x LEFT JOIN users u ON u.id = x.teacher_id WHERE x.class_id = ? ORDER BY x.created_at DESC LIMIT 15", '../assignments/view.php?id='],
    ['quiz',       'fa-question-circle', 'posted a new quiz',       "SELECT x.id, x.title, x.created_at, u.name AS author FROM quizzes x LEFT JOIN users u ON u.id = x.teacher_id WHERE x.class_id = ? ORDER BY x.created_at DESC LIMIT 15",     '../quizzes/view.php?id='],
];
foreach ($work_queries as [$kind, $icon, $verb, $sql, $url]) {
    foreach ($db->fetchAll($sql, [$class_id]) as $w) {
        $feed[] = ['kind' => 'work', 'time' => $w['created_at'], 'icon' => $icon, 'verb' => $verb,
                   'title' => $w['title'], 'url' => $url . (int) $w['id'], 'author' => $w['author'] ?: $class['teacher_name']];
    }
}
usort($feed, fn($a, $b) => strcmp($b['time'], $a['time']));
$feed = array_merge($pinned, array_slice($feed, 0, 40));
$can_post = canPostToStream($class);
$can_comment = canCommentOnStream($class);
$perm_note = streamPermissionLabels()[$class['stream_permission'] ?? 'comment'];

/** Initial-letter avatar. */
function streamAvatar($name, $teacher = false) {
    $letter = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '?';
    return '<span class="avatar' . ($teacher ? ' teacher' : '') . '" aria-hidden="true">' . htmlspecialchars($letter) . '</span>';
}

$page_title = $class['class_name'];
$page_script = classResultScript($task_result) . "
    const sched = document.getElementById('publish_at');
    if (sched) {
        const btn = sched.form.querySelector('button[type=submit]');
        const label = function () {
            btn.innerHTML = sched.value ? '<i class=\"fas fa-clock\"></i> Schedule' : '<i class=\"fas fa-bullhorn\"></i> Post';
        };
        sched.addEventListener('input', function () { sched.classList.remove('is-invalid'); label(); });
        label();
    }";

$reuse_targets = [];
if ($is_owner) {
    foreach ($db->fetchAll("SELECT c.id, c.class_name FROM classes c INNER JOIN class_teachers ct ON ct.class_id = c.id AND ct.teacher_id = ? AND ct.role = 'owner'
                            WHERE c.is_active = 1 ORDER BY c.class_name", [$current_user_id]) as $rt) {
        $reuse_targets[(int) $rt['id']] = $rt['class_name'] . ((int) $rt['id'] === (int) $class_id ? ' (this class — post again)' : '');
    }
    if ($reuse_targets) $page_scripts[] = 'assets/js/reuse-post.js';
}
$can_ai_write = $is_owner && aiAvailableFor('writing', $current_user_id);
$page_scripts[] = 'assets/js/stream-filter.js';
if ($can_ai_write) $page_scripts[] = 'assets/js/ai-assist.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('stream');
?>
<?php if ($reuse_targets): ?>
<form method="POST" id="reuseForm" hidden data-targets="<?php echo htmlspecialchars(json_encode($reuse_targets, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>">
    <?php echo csrfField(); ?><input type="hidden" name="action" value="reuse_post"><input type="hidden" name="announcement_id"><input type="hidden" name="target_class_id">
</form>
<?php endif; ?>
<?php
?>
<div class="stream-layout">
    <aside class="stream-side">
        <?php if ($is_owner): ?>
        <div class="content-card side-card">
            <h3>Class code</h3>
            <button type="button" class="class-code-big" data-copy="<?php echo htmlspecialchars($class['class_code']); ?>" data-copy-label="Class code">
                <?php echo htmlspecialchars($class['class_code']); ?> <i class="fas fa-copy"></i>
            </button>
            <button type="button" class="link-btn" data-copy="<?php echo htmlspecialchars($invite_link); ?>" data-copy-label="Invite link">
                <i class="fas fa-link"></i> Copy invite link
            </button>
        </div>
        <?php endif; ?>
        <div class="content-card side-card">
            <h3>Upcoming</h3>
            <?php if (!$upcoming): ?>
            <p class="form-hint" style="margin:0;"><?php echo $is_owner ? 'No work due soon.' : 'Woohoo, no work due soon!'; ?></p>
            <?php endif; ?>
            <?php foreach ($upcoming as $u): ?>
            <a class="upcoming-item" href="<?php echo htmlspecialchars($u['url']); ?>">
                <span class="form-hint" style="margin:0;">Due <?php echo formatDate($u['due'], 'D, M d · h:i A'); ?></span>
                <span><i class="fas <?php echo $u['icon']; ?>"></i> <?php echo htmlspecialchars($u['title']); ?></span>
            </a>
            <?php endforeach; ?>
            <a class="link-btn" href="<?php echo classUrl('classwork'); ?>" style="margin-top:8px;display:inline-block;">View all</a>
        </div>
    </aside>

    <div class="stream-main">
        <?php if ($can_post): ?>
        <form method="POST" class="content-card stream-composer" id="composer">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="post_announcement">
            <div class="composer-row">
                <?php echo streamAvatar($current_user_name, $is_owner); ?>
                <div class="form-group" style="flex:1;margin:0;">
                    <textarea name="body" rows="3" maxlength="5000" aria-label="New post"
                        placeholder="<?php echo $is_owner ? 'Announce something to your class' : 'Share something with your class'; ?>"
                        class="<?php echo $composer_error ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($composer_body); ?></textarea>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($composer_error); ?></div>
                </div>
            </div>
            <?php if ($is_owner): ?>
            <details class="composer-schedule"<?php echo $schedule_value !== '' ? ' open' : ''; ?>>
                <summary><i class="fas fa-clock"></i> Schedule for later</summary>
                <div class="form-group">
                    <label for="publish_at">Post on</label>
                    <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo htmlspecialchars($schedule_value); ?>"
                        class="<?php echo $schedule_error ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($schedule_error); ?></div>
                    <small class="form-hint">Students see the post — and get the notification — at this time. Leave empty to post now.</small>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($can_ai_write): ?>
            <div class="ai-assist-row">
                <button type="button" class="btn-app btn-sm btn-outline" data-ai-task="announcement" data-target="#composer textarea[name=body]" data-class="<?php echo (int) $class_id; ?>" data-prompt="What do you want to announce?"><i class="fas fa-robot"></i> Write with AI</button>
                <button type="button" class="btn-app btn-sm btn-outline" data-ai-task="translate" data-target="#composer textarea[name=body]" data-class="<?php echo (int) $class_id; ?>" data-use-target="1"><i class="fas fa-language"></i> Translate</button>
            </div>
            <?php endif; ?>
            <div class="composer-actions">
                <?php if ($is_owner): ?>
                <a class="form-hint" style="margin:0 auto 0 0;" href="edit.php?id=<?php echo $class_id; ?>#stream-settings"><i class="fas fa-comments"></i> <?php echo htmlspecialchars($perm_note); ?></a>
                <?php endif; ?>
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-bullhorn"></i> Post</button>
            </div>
        </form>
        <?php elseif ($class['stream_permission'] === 'teacher_only'): ?>
        <p class="form-hint stream-note"><i class="fas fa-lock"></i> Only your teacher can post in this class.</p>
        <?php endif; ?>

        <?php if (!$feed): ?>
        <div class="empty-state">
            <i class="fas fa-stream"></i>
            <h3>This is where you talk to your class</h3>
            <p style="margin-top:6px;"><?php echo $is_owner
                ? 'Post an announcement, or add classwork — new lectures, resources, assignments and quizzes appear here too.'
                : 'Announcements and new class work from your teacher will appear here.'; ?></p>
        </div>
        <?php endif; ?>

        <?php if (count($feed) > 5): ?>
        <div class="stream-filter" id="streamFilter">
            <label class="pf-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="streamSearch" placeholder="Search the stream" autocomplete="off" aria-label="Search the stream"></label>
            <span class="pf-chips" role="group" aria-label="Filter the stream">
                <button type="button" class="chip active" data-sfilter="all">All</button>
                <button type="button" class="chip" data-sfilter="post">Announcements</button>
                <button type="button" class="chip" data-sfilter="work">Classwork</button>
            </span>
            <span class="form-hint" id="streamCount" style="margin:0;" aria-live="polite"></span>
        </div>
        <p class="form-hint" id="streamNone" hidden style="text-align:center;">Nothing matches.</p>
        <?php endif; ?>

        <?php foreach ($feed as $item): ?>
        <?php if ($item['kind'] === 'work'): ?>
        <a class="content-card stream-work" data-kind="work" data-search="<?php echo htmlspecialchars(mb_strtolower($item['author'] . ' ' . $item['verb'] . ' ' . $item['title'])); ?>" href="<?php echo htmlspecialchars($item['url']); ?>">
            <span class="work-icon"><i class="fas <?php echo $item['icon']; ?>"></i></span>
            <span class="work-text">
                <strong><?php echo htmlspecialchars($item['author']); ?></strong> <?php echo $item['verb']; ?>:
                <?php echo htmlspecialchars($item['title']); ?>
                <span class="notif-meta"><?php echo formatDate($item['time'], 'M d'); ?></span>
            </span>
        </a>
        <?php else: $a = $item['row']; $acomments = $comments[$a['id']] ?? []; ?>
        <article class="content-card stream-post<?php echo $a['pinned'] ? ' pinned' : ''; ?><?php echo $a['scheduled'] ? ' scheduled' : ''; ?>" id="post-<?php echo (int) $a['id']; ?>"
                 data-kind="post" data-search="<?php echo htmlspecialchars(mb_strtolower($a['author'] . ' ' . mb_substr((string) $a['body'], 0, 600))); ?>">
            <header class="post-head">
                <?php echo streamAvatar($a['author'], $a['author_role'] === 'teacher'); ?>
                <div>
                    <strong><?php echo htmlspecialchars($a['author']); ?></strong>
                    <?php if ($a['author_role'] === 'teacher'): ?><span class="role-tag">Teacher</span><?php endif; ?>
                    <?php if ($a['scheduled']): ?>
                    <div class="scheduled-tag"><i class="fas fa-clock"></i> Scheduled for <?php echo formatDate($a['publish_at'], 'M d, Y · h:i A'); ?> — only you can see it</div>
                    <?php endif; ?>
                    <div class="notif-meta"><?php echo formatDate($a['shown_at'], 'M d, Y · h:i A'); ?>
                        <?php if ($a['edited_at']): ?> · <span title="Edited <?php echo formatDate($a['edited_at'], 'M d, Y · h:i A'); ?>">Edited</span><?php endif; ?>
                        <?php if ($a['pinned']): ?> · <i class="fas fa-thumbtack"></i> Pinned<?php endif; ?></div>
                </div>
                <div class="post-tools">
                    <?php if ($is_owner && $a['scheduled']): ?>
                    <form method="POST" data-confirm="Post this now?" data-confirm-text="Students will see it and be notified right away." data-confirm-button="Post now">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="publish_now">
                        <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                        <button type="submit" class="icon-btn" title="Post now" aria-label="Post now"><i class="fas fa-paper-plane"></i></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($is_owner): ?>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="<?php echo $a['pinned'] ? 'unpin_announcement' : 'pin_announcement'; ?>">
                        <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                        <button type="submit" class="icon-btn<?php echo $a['pinned'] ? ' active' : ''; ?>" title="<?php echo $a['pinned'] ? 'Unpin' : 'Pin to top'; ?>" aria-label="<?php echo $a['pinned'] ? 'Unpin' : 'Pin to top'; ?>"><i class="fas fa-thumbtack"></i></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($is_owner && !$a['scheduled']): ?>
                    <button type="button" class="icon-btn" data-reuse="<?php echo (int) $a['id']; ?>" title="Reuse in another class" aria-label="Reuse this post in a class"><i class="fas fa-share"></i></button>
                    <?php endif; ?>
                    <?php if ((int) $a['user_id'] === (int) $current_user_id): ?>
                    <button type="button" class="icon-btn" data-toggle-target="edit-post-<?php echo (int) $a['id']; ?>" title="Edit post" aria-label="Edit post"><i class="fas fa-pen"></i></button>
                    <?php endif; ?>
                    <?php if ($is_owner || (int) $a['user_id'] === (int) $current_user_id): ?>
                    <form method="POST" data-confirm="Delete this post?" data-confirm-text="Its comments will be deleted too." data-confirm-button="Delete">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                        <button type="submit" class="icon-btn danger" title="Delete post" aria-label="Delete post"><i class="fas fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
            </header>
            <div class="post-body"><?php echo richText($a['body']); ?></div>
            <?php if ((int) $a['user_id'] === (int) $current_user_id): $eid = (int) $a['id']; ?>
            <form method="POST" class="post-edit" id="edit-post-<?php echo $eid; ?>" action="<?php echo $self; ?>#post-<?php echo $eid; ?>"<?php echo isset($edit_errors[$eid]) ? '' : ' hidden'; ?>>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit_announcement">
                <input type="hidden" name="announcement_id" value="<?php echo $eid; ?>">
                <div class="form-group" style="margin:0;">
                    <textarea name="body" rows="4" maxlength="5000" aria-label="Edit post"
                        class="<?php echo isset($edit_errors[$eid]) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($edit_drafts[$eid] ?? $a['body']); ?></textarea>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($edit_errors[$eid] ?? ''); ?></div>
                </div>
                <div class="post-edit-actions">
                    <button type="button" class="btn-app btn-sm btn-outline" data-toggle-target="edit-post-<?php echo $eid; ?>">Cancel</button>
                    <button type="submit" class="btn-app btn-sm"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
            <?php endif; ?>

            <div class="post-comments">
                <?php if ($acomments): ?>
                <div class="comment-count"><i class="fas fa-comments"></i> <?php echo count($acomments); ?> class comment<?php echo count($acomments) == 1 ? '' : 's'; ?></div>
                <?php endif; ?>
                <?php foreach ($acomments as $c): ?>
                <div class="comment">
                    <?php echo streamAvatar($c['author'], $c['role'] === 'teacher'); ?>
                    <div class="comment-body">
                        <strong><?php echo htmlspecialchars($c['author']); ?></strong>
                        <span class="notif-meta"><?php echo timeAgo($c['created_at']); ?></span>
                        <div class="prewrap"><?php echo linkify($c['body']); ?></div>
                    </div>
                    <?php if ($is_owner || (int) $c['user_id'] === (int) $current_user_id): ?>
                    <form method="POST" data-confirm="Delete this comment?" data-confirm-button="Delete">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_comment">
                        <input type="hidden" name="comment_id" value="<?php echo (int) $c['id']; ?>">
                        <button type="submit" class="icon-btn danger" title="Delete comment" aria-label="Delete comment"><i class="fas fa-times"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($can_comment): ?>
                <form method="POST" class="comment-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="post_comment">
                    <input type="hidden" name="announcement_id" value="<?php echo (int) $a['id']; ?>">
                    <?php echo streamAvatar($current_user_name, $is_owner); ?>
                    <div class="form-group" style="flex:1;margin:0;">
                        <textarea name="body" rows="1" maxlength="2000" placeholder="Add class comment…" aria-label="Add class comment"
                            class="<?php echo isset($comment_errors[$a['id']]) ? 'is-invalid' : ''; ?>"></textarea>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($comment_errors[$a['id']] ?? ''); ?></div>
                    </div>
                    <button type="submit" class="icon-btn" title="Post comment" aria-label="Post comment"><i class="fas fa-paper-plane"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
