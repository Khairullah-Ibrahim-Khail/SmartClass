<?php
/**
 * One forum topic — ?id=N. Members and teachers of its class only.
 *   POST reply            anyone in the class (unless locked): adds a reply, notifies the topic's author
 *   POST pin / lock       teacher of the class (toggle)
 *   POST delete_topic     teacher of the class, or the topic's author
 *   POST delete_reply     teacher of the class, or the reply's author
 * CSRF on everything; Post/Redirect/Get; inline error under the reply box.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$thread = $db->fetchOne("SELECT t.*, u.name AS author, u.role AS author_role FROM forum_threads t INNER JOIN users u ON t.user_id = u.id WHERE t.id = ?", [(int) ($_GET['id'] ?? 0)]);
$class = $thread ? getAccessibleClass($thread['class_id'], $current_user_id, $current_user_role) : null;
if (!$class) redirect(APP_URL . '/pages/forums/view.php');
$is_teacher_here = isTeacher();
$self = APP_URL . '/pages/forums/thread.php?id=' . $thread['id'];
$reply_error = '';
$reply_body = '';
$task_result = Session::flash('forum_msg');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'reply') {
        $reply_body = trim((string) ($_POST['body'] ?? ''));
        $rfile = $_FILES['file'] ?? null;
        $has_file = is_array($rfile) && ($rfile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($has_file && ($fe = storeUploadCheck($rfile))) $reply_error = $fe;
        elseif ($thread['locked']) $reply_error = 'This topic is locked';
        elseif ($reply_body === '') $reply_error = 'Write your reply';
        elseif (mb_strlen($reply_body) > 5000) $reply_error = 'Reply must be under 5,000 characters';
        else {
            $new_post = $db->insert('forum_posts', ['thread_id' => $thread['id'], 'user_id' => $current_user_id, 'body' => $reply_body]);
            if ($has_file) forumAttach($thread['id'], $new_post, $current_user_id, $rfile);
            $db->update('forum_threads', ['last_post_at' => date('Y-m-d H:i:s')], 'id = ?', [$thread['id']]);
            if ((int) $thread['user_id'] !== (int) $current_user_id) {
                notify($thread['user_id'], 'forum_post', 'New reply: ' . mb_strimwidth($thread['title'], 0, 80, '…'), $current_user_name . ': ' . mb_strimwidth($reply_body, 0, 120, '…'),
                    'pages/forums/thread.php?id=' . $thread['id'] . '#replies', $current_user_id, $thread['class_id']);
            }
            redirect($self . '#replies');
        }
    } elseif (in_array($action, ['pin', 'lock'], true) && $is_teacher_here) {
        $col = $action === 'pin' ? 'pinned' : 'locked';
        $db->update('forum_threads', [$col => $thread[$col] ? 0 : 1], 'id = ?', [$thread['id']]);
        redirect($self);
    } elseif ($action === 'delete_topic' && ($is_teacher_here || (int) $thread['user_id'] === (int) $current_user_id)) {
        forumDeleteFiles($thread['id']);   // the files go too
        $db->delete('forum_threads', 'id = ?', [$thread['id']]);
        logActivity('delete_forum_topic', 'forums', "Deleted forum topic #{$thread['id']}");
        Session::flash('forum_msg', ['success', 'Topic deleted', 'The topic and its replies were removed.']);
        redirect(APP_URL . '/pages/forums/view.php');
    } elseif ($action === 'delete_reply') {
        $p = $db->fetchOne("SELECT id, user_id FROM forum_posts WHERE id = ? AND thread_id = ?", [(int) ($_POST['post_id'] ?? 0), $thread['id']]);
        if ($p && ($is_teacher_here || (int) $p['user_id'] === (int) $current_user_id)) {
            forumDeleteFiles($thread['id'], $p['id']);
            $db->delete('forum_posts', 'id = ?', [$p['id']]);
            Session::flash('forum_msg', ['success', 'Reply deleted', 'The reply was removed.']);
            redirect($self . '#replies');
        }
        $task_result = ['error', 'Not allowed', 'You can only delete your own replies.'];
    }
}
$thread = $db->fetchOne("SELECT t.*, u.name AS author, u.role AS author_role FROM forum_threads t INNER JOIN users u ON t.user_id = u.id WHERE t.id = ?", [$thread['id']]);
$posts = $db->fetchAll("SELECT p.*, u.name AS author, u.role AS author_role FROM forum_posts p INNER JOIN users u ON p.user_id = u.id WHERE p.thread_id = ? ORDER BY p.created_at, p.id", [$thread['id']]);

$page_title = $thread['title'];
$page_subtitle = $class['class_name'] . ' · Forum';
$active_menu = 'forums';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const rf = document.getElementById('replyForm');
    if (rf) { bindLiveClear(rf); rf.addEventListener('submit', function (e) { const b = rf.querySelector('textarea'); if (!b.value.trim()) { setFieldError(b, 'Write your reply'); e.preventDefault(); } }); }";
$page_scripts = ['assets/js/class.js'];

$files = forumAttachments($thread['id']);
$avatar = fn($n, $t) => '<span class="avatar' . ($t ? ' teacher' : '') . '" aria-hidden="true">' . htmlspecialchars(mb_strtoupper(mb_substr($n, 0, 1))) . '</span>';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $thread['class_id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> All topics</a>
<article class="content-card stream-post<?php echo $thread['pinned'] ? ' pinned' : ''; ?>">
    <header class="post-head">
        <?php echo $avatar($thread['author'], $thread['author_role'] === 'teacher'); ?>
        <div>
            <h2 style="font-size:1.15rem;margin:0 0 2px;"><?php echo htmlspecialchars($thread['title']); ?></h2>
            <div class="notif-meta"><?php echo htmlspecialchars($thread['author']); ?><?php echo $thread['author_role'] === 'teacher' ? ' (teacher)' : ''; ?> · <?php echo formatDate($thread['created_at'], 'M d, Y · h:i A'); ?>
                <?php if ($thread['pinned']): ?> · <i class="fas fa-thumbtack"></i> Pinned<?php endif; ?><?php if ($thread['locked']): ?> · <i class="fas fa-lock"></i> Locked<?php endif; ?></div>
        </div>
        <div class="post-tools">
            <?php if ($is_teacher_here): ?>
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="pin"><button type="submit" class="icon-btn<?php echo $thread['pinned'] ? ' active' : ''; ?>" title="<?php echo $thread['pinned'] ? 'Unpin' : 'Pin to top'; ?>" aria-label="Pin"><i class="fas fa-thumbtack"></i></button></form>
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="lock"><button type="submit" class="icon-btn<?php echo $thread['locked'] ? ' active' : ''; ?>" title="<?php echo $thread['locked'] ? 'Unlock replies' : 'Lock replies'; ?>" aria-label="Lock"><i class="fas fa-lock"></i></button></form>
            <?php endif; ?>
            <?php if ($is_teacher_here || (int) $thread['user_id'] === (int) $current_user_id): ?>
            <form method="POST" data-confirm="Delete this topic?" data-confirm-text="All its replies are deleted too." data-confirm-button="Delete"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_topic"><button type="submit" class="icon-btn danger" title="Delete topic" aria-label="Delete topic"><i class="fas fa-trash"></i></button></form>
            <?php endif; ?>
        </div>
    </header>
    <div class="post-body prewrap"><?php echo linkify($thread['body']); ?></div>
    <?php echo forumFilesHtml($files[0] ?? []); ?>
</article>

<div class="content-card" id="replies">
    <div class="card-head"><h2><i class="fas fa-reply"></i> <?php echo count($posts); ?> repl<?php echo count($posts) == 1 ? 'y' : 'ies'; ?></h2></div>
    <div class="card-inner">
        <?php if (!$posts): ?><p class="form-hint" style="margin:0;">No replies yet — be the first.</p><?php endif; ?>
        <?php foreach ($posts as $p): ?>
        <div class="comment" id="reply-<?php echo (int) $p['id']; ?>">
            <?php echo $avatar($p['author'], $p['author_role'] === 'teacher'); ?>
            <div class="comment-body">
                <strong><?php echo htmlspecialchars($p['author']); ?></strong><?php echo $p['author_role'] === 'teacher' ? ' <span class="role-tag">Teacher</span>' : ''; ?> <span class="notif-meta"><?php echo timeAgo($p['created_at']); ?></span>
                <div class="prewrap"><?php echo linkify($p['body']); ?></div>
                <?php echo forumFilesHtml($files[(int) $p['id']] ?? []); ?>
            </div>
            <?php if ($is_teacher_here || (int) $p['user_id'] === (int) $current_user_id): ?>
            <form method="POST" data-confirm="Delete this reply?" data-confirm-button="Delete"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_reply"><input type="hidden" name="post_id" value="<?php echo (int) $p['id']; ?>">
                <button type="submit" class="icon-btn danger" title="Delete reply" aria-label="Delete reply"><i class="fas fa-times"></i></button></form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($thread['locked']): ?>
        <p class="form-hint"><i class="fas fa-lock"></i> This topic is locked — no new replies.</p>
        <?php else: ?>
        <form method="POST" id="replyForm" class="comment-form" enctype="multipart/form-data" data-no-ajax action="<?php echo $self; ?>#replies" novalidate style="margin-top:14px;">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="reply">
            <div class="form-group" style="flex:1;margin:0;">
                <textarea name="body" rows="3" maxlength="5000" placeholder="Write a reply…" aria-label="Your reply" class="<?php echo $reply_error ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($reply_body); ?></textarea>
                <div class="invalid-feedback"><?php echo htmlspecialchars($reply_error); ?></div>
            </div>
            <label class="btn-app btn-sm btn-outline" for="replyFile" style="cursor:pointer;" title="Attach a file"><i class="fas fa-paperclip"></i> <span id="replyFileName">Attach</span></label>
            <input type="file" id="replyFile" name="file" hidden onchange="document.getElementById('replyFileName').textContent = this.files[0] ? this.files[0].name.slice(0, 24) : 'Attach'">
            <button type="submit" class="btn-app btn-sm"><i class="fas fa-paper-plane"></i> Reply</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
