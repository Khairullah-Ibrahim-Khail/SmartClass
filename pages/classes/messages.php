<?php
/**
 * Class — Messages: ?id=N[&s=student&c=class|assignment|quiz&cid=N]
 * Private conversations between each student and the class teacher:
 *   General ("Message teacher"), and one per assignment / quiz (private comments).
 * Teacher: every conversation of the class, unread first-class, can start one with any student.
 * Student: only their own conversations; "Message teacher" is always there.
 * Opening a conversation marks the other side's messages as read.
 *   POST send   CSRF; the student must be in the class; the context must belong to the class.
 * The page works without a reload (assets/js/messages.js): the same file answers fetch calls with JSON
 *   GET  ajax=open   conversation list + thread pane       GET ajax=poll&after=ID   new messages + read marks
 *   POST ajax=1      send (JSON: the new bubble)
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';

$task_result = null;
$send_error = '';
$draft = '';

/** Is this student (still) a member of the class? */
function isClassMember($class_id, $student_id) {
    global $db;
    return (bool) $db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [(int) $class_id, (int) $student_id]);
}

// ---------- Which conversation is open ----------
$sel_student = $is_owner ? (int) ($_GET['s'] ?? $_POST['s'] ?? 0) : (int) $current_user_id;
$sel_ctx = null;
if ($sel_student) {
    $sel_ctx = messageContext($class_id, $_GET['c'] ?? $_POST['c'] ?? 'class', $_GET['cid'] ?? $_POST['cid'] ?? null);
    if (!$sel_ctx) $sel_ctx = messageContext($class_id, 'class', null);
}
// A teacher may only open conversations of students who are in the class (or who wrote here before)
if ($is_owner && $sel_student && !isClassMember($class_id, $sel_student)
    && !$db->fetchOne("SELECT id FROM private_messages WHERE class_id = ? AND student_id = ? LIMIT 1", [$class_id, $sel_student])) {
    $sel_student = 0;
    $sel_ctx = null;
}

$ctx_icon = ['class' => 'fa-comments', 'assignment' => 'fa-tasks', 'quiz' => 'fa-question-circle', 'lecture' => 'fa-video'];
$ctx_page = ['assignment' => 'assignments', 'quiz' => 'quizzes', 'lecture' => 'lectures'];
$ajax = (string) ($_GET['ajax'] ?? $_POST['ajax'] ?? '');

function msgJson(array $data) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** One message bubble. */
function msgBubble($m) {
    global $current_user_id;
    $mine = (int) $m['user_id'] === (int) $current_user_id;
    ob_start(); ?>
<div class="bubble-row<?php echo $mine ? ' mine' : ''; ?>" data-id="<?php echo (int) $m['id']; ?>">
    <div class="bubble">
        <div class="prewrap"><?php echo htmlspecialchars($m['body']); ?></div>
        <span class="bubble-meta"><?php echo $mine ? 'You' : htmlspecialchars($m['author']); ?> · <?php echo formatDate($m['created_at'], 'M d, h:i A'); ?>
            <?php if ($mine): ?> · <span class="seen-state"><?php echo $m['is_read'] ? 'Seen' : 'Sent'; ?></span><?php endif; ?></span>
    </div>
</div>
<?php
    return ob_get_clean();
}

/** The conversation links on the left (also re-sent after every action so unread counts stay true). */
function msgListHtml($conversations, $open_key) {
    global $class, $class_id, $is_owner, $current_user_id, $ctx_icon;
    $student_has_general = !$is_owner && array_filter($conversations, fn($c) => $c['context_type'] === 'class');
    ob_start();
    if (!$is_owner && !$student_has_general): ?>
<a data-no-ajax class="conv-item<?php echo $open_key === $current_user_id . '|class|0' ? ' active' : ''; ?>" data-key="<?php echo (int) $current_user_id; ?>|class|0" href="<?php echo APP_URL . '/' . htmlspecialchars(messageThreadUrl($class_id, $current_user_id, 'class', null)); ?>">
    <span class="avatar teacher" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($class['teacher_name'], 0, 1))); ?></span>
    <span class="conv-main"><strong>Message teacher</strong><span class="notif-meta"><?php echo htmlspecialchars($class['teacher_name']); ?> · General</span></span>
</a>
<?php endif;
    if (!$conversations && $is_owner): ?>
<p class="form-hint conv-empty">No private messages yet. Students can message you from this tab or from an assignment or quiz.</p>
<?php endif;
    foreach ($conversations as $cv):
        $key = $cv['student_id'] . '|' . $cv['context_type'] . '|' . (int) $cv['context_id']; ?>
<a data-no-ajax class="conv-item<?php echo $key === $open_key ? ' active' : ''; ?><?php echo $cv['unread'] ? ' unread' : ''; ?>" data-key="<?php echo htmlspecialchars($key); ?>"
   href="<?php echo APP_URL . '/' . htmlspecialchars(messageThreadUrl($class_id, $cv['student_id'], $cv['context_type'], $cv['context_id'])); ?>">
    <span class="avatar<?php echo $is_owner ? '' : ' teacher'; ?>" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($is_owner ? $cv['student_name'] : $class['teacher_name'], 0, 1))); ?></span>
    <span class="conv-main">
        <strong><?php echo htmlspecialchars($is_owner ? $cv['student_name'] : ($cv['context_type'] === 'class' ? 'Message teacher' : $cv['context_title'])); ?></strong>
        <span class="notif-meta"><i class="fas <?php echo $ctx_icon[$cv['context_type']]; ?>"></i>
            <?php echo htmlspecialchars($cv['context_type'] === 'class' ? 'General' : $cv['context_title']); ?> · <?php echo timeAgo($cv['last_at']); ?></span>
        <span class="conv-preview"><?php echo htmlspecialchars(mb_strimwidth($cv['last_author'] . ': ' . $cv['last_body'], 0, 70, '…')); ?></span>
    </span>
    <?php if ($cv['unread']): ?><span class="notif-badge-inline" aria-label="<?php echo $cv['unread']; ?> unread"><?php echo $cv['unread']; ?></span><?php endif; ?>
</a>
<?php endforeach;
    return ob_get_clean();
}

/** The right-hand pane: header, bubbles, reply box. */
function msgThreadHtml($sel_student, $sel_ctx, $sel_name, $thread, $members_count) {
    global $class, $class_id, $is_owner, $current_user_id, $ctx_icon, $ctx_page, $can_ai_write;
    ob_start();
    if (!$sel_ctx): ?>
<div class="empty-state" style="border:none;">
    <i class="fas fa-user-lock"></i>
    <h3>Private messages</h3>
    <p style="margin-top:6px;">Only you and the student can see a conversation. Pick one on the left<?php echo $members_count ? ', or message a student' : ''; ?>.</p>
</div>
<?php else:
    $last_id = $thread ? (int) end($thread)['id'] : 0;
    $who = $is_owner ? $sel_name : $class['teacher_name']; ?>
<div class="card-head thread-head">
    <span class="avatar<?php echo $is_owner ? '' : ' teacher'; ?>" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($who, 0, 1))); ?></span>
    <div class="thread-title">
        <h2><?php echo htmlspecialchars($who); ?></h2>
        <span class="form-hint"><i class="fas <?php echo $ctx_icon[$sel_ctx[0]]; ?>"></i> <?php echo htmlspecialchars($sel_ctx[0] === 'class' ? 'General' : $sel_ctx[2]); ?></span>
    </div>
    <?php if ($sel_ctx[0] !== 'class'): ?>
    <a class="link-btn" href="<?php echo APP_URL; ?>/pages/<?php echo $ctx_page[$sel_ctx[0]]; ?>/view.php?id=<?php echo (int) $sel_ctx[1]; ?>">Open <?php echo $sel_ctx[0]; ?> <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>
<div class="thread-body" id="threadBody" data-last="<?php echo $last_id; ?>" data-key="<?php echo (int) $sel_student . '|' . htmlspecialchars($sel_ctx[0]) . '|' . (int) $sel_ctx[1]; ?>"
     data-s="<?php echo (int) $sel_student; ?>" data-c="<?php echo htmlspecialchars($sel_ctx[0]); ?>" data-cid="<?php echo (int) $sel_ctx[1]; ?>">
    <p class="form-hint thread-empty" style="text-align:center;margin:30px 0;<?php echo $thread ? 'display:none;' : ''; ?>">No messages yet — write the first one below.</p>
    <?php foreach ($thread as $m) echo msgBubble($m); ?>
</div>
<?php if (isClassMember($class_id, $sel_student)): ?>
<form method="POST" data-no-ajax class="thread-reply comment-form" action="<?php echo APP_URL . '/' . htmlspecialchars(messageThreadUrl($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1])); ?>">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="send">
    <input type="hidden" name="s" value="<?php echo (int) $sel_student; ?>">
    <input type="hidden" name="c" value="<?php echo htmlspecialchars($sel_ctx[0]); ?>">
    <input type="hidden" name="cid" value="<?php echo (int) $sel_ctx[1]; ?>">
    <div class="form-group" style="flex:1;margin:0;">
        <textarea name="body" rows="1" maxlength="2000" placeholder="Write a message…  (Enter sends)" title="Enter sends · Shift+Enter adds a new line" aria-label="Private message"></textarea>
        <div class="invalid-feedback"></div>
    </div>
    <button type="submit" class="btn-app btn-sm send-btn"><i class="fas fa-paper-plane"></i> Send</button>
    <?php if ($can_ai_write && !empty($thread)): ?>
    <div class="ai-assist-row" style="flex-basis:100%;">
        <button type="button" class="btn-app btn-sm btn-outline" data-ai-task="reply" data-target=".thread-reply textarea[name=body]" data-class="<?php echo (int) $class_id; ?>"
                data-student="<?php echo (int) $sel_student; ?>" data-ctx="<?php echo htmlspecialchars($sel_ctx[0]); ?>" data-cid="<?php echo (int) $sel_ctx[1]; ?>"><i class="fas fa-robot"></i> Suggest a reply</button>
    </div>
    <?php endif; ?>
</form>
<?php else: ?>
<p class="form-hint" style="padding:12px 18px;margin:0;">This student is no longer in the class, so you cannot reply here.</p>
<?php endif;
    endif;
    return ob_get_clean();
}

$can_ai_write = $is_owner && aiAvailableFor('writing', $current_user_id);

// ---------- Send ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $draft = trim($_POST['body'] ?? '');
    $fail = null;   // [field error, general error]
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
        $fail = ['', 'Session expired — please reload the page.'];
    } elseif (!$sel_student || !$sel_ctx || !isClassMember($class_id, $sel_student)) {
        $task_result = ['error', 'Not sent', 'That student is not in this class.'];
        $fail = ['', 'That student is not in this class.'];
    } elseif ($draft === '') {
        $send_error = 'Write a message first';
        $fail = [$send_error, ''];
    } elseif (mb_strlen($draft) > 2000) {
        $send_error = 'Message must be under 2,000 characters';
        $fail = [$send_error, ''];
    }
    if (!$fail) {
        $new_id = postPrivateMessage($class, $sel_student, $current_user_id, $current_user_name, $sel_ctx, $draft);
        if ($ajax) {
            markThreadRead($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1], $current_user_id);
            $sent = null;
            foreach (getThreadMessages($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1]) as $m) if ((int) $m['id'] === (int) $new_id) $sent = $m;
            $key = $sel_student . '|' . $sel_ctx[0] . '|' . (int) $sel_ctx[1];
            msgJson(['ok' => true, 'id' => (int) $new_id, 'bubble' => $sent ? msgBubble($sent) : '',
                     'list' => msgListHtml(getConversations($class_id, $current_user_id, $is_owner), $key)]);
        }
        redirect(APP_URL . '/' . messageThreadUrl($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1]) . '#latest');
    }
    if ($ajax) msgJson(['ok' => false, 'field' => $fail[0], 'error' => $fail[1]]);
}

// ---------- Data ----------
$conversations = getConversations($class_id, $current_user_id, $is_owner);
if ($is_owner && !$sel_student && $conversations && $ajax === '') {
    // open the newest conversation by default
    $first = $conversations[0];
    $sel_student = (int) $first['student_id'];
    $sel_ctx = messageContext($class_id, $first['context_type'], $first['context_id']);
}
$thread = [];
$sel_name = '';
if ($sel_student && $sel_ctx) {
    $thread = getThreadMessages($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1]);
    markThreadRead($class_id, $sel_student, $sel_ctx[0], $sel_ctx[1], $current_user_id);
    $sel_name = $db->fetchOne("SELECT name FROM users WHERE id = ?", [$sel_student])['name'] ?? '';
    foreach ($conversations as &$cv) {   // reflect "now read" in the list
        if ((int) $cv['student_id'] === $sel_student && $cv['context_type'] === $sel_ctx[0] && (int) $cv['context_id'] === (int) $sel_ctx[1]) $cv['unread'] = 0;
    }
    unset($cv);
}
$members = $is_owner ? $db->fetchAll(
    "SELECT u.id, u.name FROM class_members cm INNER JOIN users u ON cm.student_id = u.id WHERE cm.class_id = ? ORDER BY u.name", [$class_id]) : [];
$open_key = $sel_ctx ? $sel_student . '|' . $sel_ctx[0] . '|' . (int) $sel_ctx[1] : '';

// ---------- fetch calls ----------
if ($ajax === 'open') {
    msgJson(['ok' => (bool) $sel_ctx, 'key' => $open_key, 'list' => msgListHtml($conversations, $open_key),
             'thread' => msgThreadHtml($sel_student, $sel_ctx, $sel_name, $thread, count($members))]);
}
if ($ajax === 'poll') {
    if (!$sel_ctx) msgJson(['ok' => false]);
    $after = (int) ($_GET['after'] ?? 0);
    $new = '';
    $last = $after;
    $read = [];
    foreach ($thread as $m) {
        if ((int) $m['id'] > $after) { $new .= msgBubble($m); $last = max($last, (int) $m['id']); }
        if ((int) $m['user_id'] === (int) $current_user_id && $m['is_read']) $read[] = (int) $m['id'];
    }
    msgJson(['ok' => true, 'bubbles' => $new, 'last' => $last, 'read' => $read, 'list' => msgListHtml($conversations, $open_key)]);
}

$page_title = $class['class_name'] . ' - Messages';
$page_script = classResultScript($task_result);
$page_scripts[] = 'assets/js/messages.js';
if ($can_ai_write) $page_scripts[] = 'assets/js/ai-assist.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('messages');
?>

<div class="messages-layout" id="messagesLayout" data-url="<?php echo APP_URL; ?>/pages/classes/messages.php?id=<?php echo (int) $class_id; ?>">
    <aside class="content-card conv-list" aria-label="Conversations">
        <div class="card-head">
            <h2><i class="fas fa-inbox"></i> <?php echo $is_owner ? 'Conversations' : 'Your messages'; ?></h2>
        </div>
        <?php if ($is_owner && $members): ?>
        <div class="conv-new">
            <div class="pick" id="newPick" data-base="<?php echo APP_URL; ?>/pages/classes/messages.php?id=<?php echo (int) $class_id; ?>&amp;c=class&amp;s=">
                <button type="button" class="pick-btn" aria-haspopup="listbox" aria-expanded="false">
                    <i class="fas fa-pen-to-square"></i><span>Message a student…</span><i class="fas fa-chevron-down pick-caret"></i>
                </button>
                <div class="pick-panel" hidden>
                    <label class="pick-search"><i class="fas fa-search"></i>
                        <input type="search" placeholder="Search students" aria-label="Search students" autocomplete="off"></label>
                    <ul role="listbox" aria-label="Students">
                        <?php foreach ($members as $m): ?>
                        <li role="option" tabindex="-1" data-s="<?php echo (int) $m['id']; ?>" data-name="<?php echo htmlspecialchars(mb_strtolower($m['name'])); ?>">
                            <?php echo avatarHtml($m['id'], $m['name']); ?>
                            <span><?php echo htmlspecialchars($m['name']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="pick-none" hidden>No student found</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="conv-items" id="convItems"><?php echo msgListHtml($conversations, $open_key); ?></div>
    </aside>

    <section class="content-card conv-thread" id="convThread">
        <?php echo msgThreadHtml($sel_student, $sel_ctx, $sel_name, $thread, count($members)); ?>
    </section>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
