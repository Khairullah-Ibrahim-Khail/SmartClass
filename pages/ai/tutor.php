<?php
/**
 * AI Tutor (students): a full-height chat tied to one of the student's classes.
 *   page                      ?class_id=N[&chat=ID][&lecture=ID]   (lecture = start from that lecture)
 *   GET  ?ajax=chat&id=ID     -> { ok, messages[] }
 *   POST ajax=ask             class_id, chat_id (0 = new), lecture_id, text -> { ok, chat_id, reply, chats[] } | { ok:false, error }
 *   POST ajax=delete          chat_id                                       -> { ok, chats[] }
 * Everything is checked against the signed-in student; the AI rules (class switch, keys) are in tutorAsk().
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isStudent()) redirect(APP_URL . '/pages/dashboard.php');

$classes = tutorClasses($current_user_id);
$by_id = array_column($classes, null, 'id');
$class_id = (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0);
if (!isset($by_id[$class_id]) && !isset($_GET['ajax']) && empty($_POST['ajax'])) $class_id = $classes ? (int) $classes[0]['id'] : 0;

$json = function ($data, $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data); exit; };
$chatList = fn($cid) => array_map(fn($c) => ['id' => (int) $c['id'], 'title' => $c['title'], 'when' => date('M j', strtotime($c['updated_at']))], tutorChats($current_user_id, $cid));

if (($_GET['ajax'] ?? '') === 'list' && isset($by_id[$class_id])) $json(['ok' => true, 'chats' => $chatList($class_id)]);
if (($_GET['ajax'] ?? '') === 'chat') {
    $chat = tutorChat($current_user_id, (int) ($_GET['id'] ?? 0));
    if (!$chat) $json(['ok' => false, 'error' => 'Conversation not found.'], 404);
    $json(['ok' => true, 'class_id' => (int) $chat['class_id'], 'messages' => array_map(fn($m) => ['role' => $m['role'], 'body' => $m['body']], tutorMessages($chat['id']))]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') !== '') {
    if (!verifyCsrf()) $json(['ok' => false, 'error' => 'Your session expired. Reload the page.'], 403);
    if (!isset($by_id[$class_id])) $json(['ok' => false, 'error' => 'Choose one of your classes.'], 403);
    if ($_POST['ajax'] === 'ask') {
        $live = !empty($_POST['stream']);
        if ($live) {   // the reply is sent piece by piece; errors and the final answer come as events too
            $json = function ($data, $code = 200) { aiSseSend($data['ok'] ?? false ? ['done' => true] + $data : ['error' => $data['error'] ?? 'Error']); exit; };
            aiSseOpen();
        }
        $r = tutorAsk($current_user_id, $class_id, (int) ($_POST['chat_id'] ?? 0), $_POST['text'] ?? '', (int) ($_POST['lecture_id'] ?? 0));
        if ($r['ok']) $r['chats'] = $chatList($class_id);
        $json($r, $r['ok'] ? 200 : 422);
    }
    if ($_POST['ajax'] === 'delete') {
        $chat = tutorChat($current_user_id, (int) ($_POST['chat_id'] ?? 0));
        if (!$chat) $json(['ok' => false, 'error' => 'Conversation not found.'], 404);
        $db->delete('ai_tutor_chats', 'id = ? AND student_id = ?', [$chat['id'], $current_user_id]);
        $json(['ok' => true, 'chats' => $chatList($class_id)]);
    }
    $json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$class = $by_id[$class_id] ?? null;
$ai_on = $class ? aiAvailableFor('study', $current_user_id, $class_id) : false;
[, $ai_reason] = $class ? aiSources('study', $current_user_id, $class_id) : [[], ''];
$ctx = $class ? tutorContext($class_id) : ['lectures' => [], 'assignments' => []];
$lecture_id = (int) ($_GET['lecture'] ?? 0);
if (!in_array($lecture_id, array_map('intval', array_column($ctx['lectures'], 'id')), true)) $lecture_id = 0;
$open_chat = (int) ($_GET['chat'] ?? 0);
if ($open_chat && !tutorChat($current_user_id, $open_chat)) $open_chat = 0;

$page_title = 'AI Tutor';
$page_subtitle = 'Ask anything about your class';
$active_menu = 'tutor';
$page_script = '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>Join a class first</h3><p>Your AI Tutor works inside your classes.</p></div>
<?php else: ?>
<div class="tutor" id="tutor" data-url="<?php echo htmlspecialchars(APP_URL . '/pages/ai/tutor.php'); ?>" data-csrf="<?php echo htmlspecialchars(csrfToken()); ?>"
     data-class="<?php echo $class_id; ?>" data-chat="<?php echo $open_chat; ?>" data-lecture="<?php echo $lecture_id; ?>" data-ai="<?php echo $ai_on ? 1 : 0; ?>">
    <aside class="tutor-side">
        <label for="tutorClass">Class</label>
        <select id="tutorClass">
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $class_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <label for="tutorLecSel" class="tutor-lecsel">Lecture</label>
        <select id="tutorLecSel" class="tutor-lecsel"><option value="0">Whole class</option><?php foreach ($ctx['lectures'] as $l): ?><option value="<?php echo (int) $l['id']; ?>" <?php echo (int) $l['id'] === $lecture_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['title']); ?></option><?php endforeach; ?></select>
        <button type="button" class="btn-app" id="tutorNew"><i class="fas fa-plus"></i> New chat</button>
        <div class="tutor-chats" id="tutorChats"></div>
    </aside>
    <section class="tutor-main">
        <div class="tutor-scroll" id="tutorScroll" aria-live="polite">
            <div class="tutor-welcome" id="tutorWelcome">
                <i class="fas fa-graduation-cap"></i>
                <h2>What do you want to understand?</h2>
                <p>I can explain a lecture, quiz you, or walk you through a method. I won't write answers for graded work — I'll help you do it yourself.</p>
                <div class="tutor-starters">
                    <?php foreach (['Summarize my latest lecture', 'Quiz me with 3 questions', 'Explain it like I am new to this', 'What should I study first this week?'] as $s): ?>
                    <button type="button" class="chip tutor-start"><?php echo htmlspecialchars($s); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php if (!$ai_on): ?><div class="tutor-off"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($ai_reason ?: 'The AI Tutor is not available for this class.'); ?></div><?php endif; ?>
        <form class="tutor-compose" id="tutorForm" data-no-ajax onsubmit="return false;" <?php echo $ai_on ? '' : 'hidden'; ?>>
            <div class="tutor-error" id="tutorError" hidden></div>
            <div class="tutor-row">
                <textarea id="tutorText" rows="2" maxlength="1000" aria-label="Your question" placeholder="Ask a question…  (Enter to send, Shift+Enter for a new line)"></textarea>
                <button type="submit" class="btn-app" id="tutorSend" aria-label="Send"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
            </div>
        </form>
    </section>
    <aside class="tutor-context">
        <h3><i class="fas fa-book-open"></i> Use a lecture</h3>
        <p class="form-hint">Pick one and the tutor reads it before answering.</p>
        <div class="tutor-lectures" id="tutorLectures">
            <button type="button" class="tl-item<?php echo $lecture_id ? '' : ' on'; ?>" data-id="0">Whole class</button>
            <?php foreach ($ctx['lectures'] as $l): ?><button type="button" class="tl-item<?php echo (int) $l['id'] === $lecture_id ? ' on' : ''; ?>" data-id="<?php echo (int) $l['id']; ?>"><?php echo htmlspecialchars($l['title']); ?></button><?php endforeach; ?>
        </div>
        <?php if ($ctx['assignments']): ?>
        <h3><i class="fas fa-tasks"></i> Open assignments</h3>
        <ul class="tutor-asg"><?php foreach ($ctx['assignments'] as $a): ?><li><a href="<?php echo APP_URL; ?>/pages/assignments/view.php?id=<?php echo (int) $a['id']; ?>"><?php echo htmlspecialchars($a['title']); ?></a><small><?php echo $a['due_date'] ? 'due ' . date('M j', strtotime($a['due_date'])) : 'no due date'; ?></small></li><?php endforeach; ?></ul>
        <p class="form-hint">The tutor explains methods for these but will not write your answers.</p>
        <?php endif; ?>
    </aside>
</div>
<script src="<?php echo APP_URL; ?>/assets/js/ai-tutor.js?v=<?php echo @filemtime(dirname(__DIR__, 2) . '/assets/js/ai-tutor.js'); ?>" data-page></script>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
