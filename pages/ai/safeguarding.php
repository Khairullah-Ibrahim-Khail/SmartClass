<?php
/**
 * Safeguarding (teachers): messages students wrote to the AI Tutor that mention harm (self-harm, abuse, violence, bullying).
 * The AI never saw them; the student got a fixed, kind answer. Class teachers see the flags of their own classes, administrators see all.
 *   POST handle   flag_id, note?   (CSRF) — marks it handled and records who and when
 * Only the start of the message is kept here (300 characters). Handle these with care: talk to the student in person, follow your school's child-protection procedure.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/dashboard.php');

$admin = isAdmin();
$scope = $admin ? '1=1' : "f.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = " . (int) $current_user_id . ")";
$task_result = Session::flash('sg_msg');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $f = $db->fetchOne("SELECT f.id FROM safeguarding_flags f WHERE f.id = ? AND {$scope} AND f.status = 'open'", [(int) ($_POST['flag_id'] ?? 0)]);
        if (!$f) {
            $task_result = ['error', 'Not found', 'That flag was already handled or is not in your classes.'];
        } else {
            $db->update('safeguarding_flags', ['status' => 'handled', 'handled_by' => $current_user_id, 'handled_at' => date('Y-m-d H:i:s'), 'note' => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null], 'id = ?', [$f['id']]);
            logActivity('safeguarding_handled', 'ai', 'Flag #' . (int) $f['id'] . ' marked handled');
            Session::flash('sg_msg', ['success', 'Marked as handled', 'Thank you for looking after your student.']);
            redirect(APP_URL . '/pages/ai/safeguarding.php');
        }
    }
}
$flags = $db->fetchAll("SELECT f.*, u.name AS student, c.class_name, h.name AS handler FROM safeguarding_flags f
    INNER JOIN users u ON f.student_id = u.id INNER JOIN classes c ON f.class_id = c.id LEFT JOIN users h ON f.handled_by = h.id
    WHERE {$scope} ORDER BY f.status = 'open' DESC, f.created_at DESC LIMIT 200");
$cats = ['self_harm' => 'May harm themselves', 'abuse' => 'May be being hurt', 'violence' => 'Mentioned hurting others', 'bullying' => 'May be bullied', 'distress' => 'Is hurting / may need support'];

$page_title = 'Safeguarding';
$page_subtitle = 'Students who may need support';
$active_menu = 'safeguarding';
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<div class="alert-box" style="margin-bottom:16px;"><i class="fas fa-hand-holding-heart"></i>
    <span>When a student writes something to the AI Tutor that suggests they are in danger or hurting, the AI is <strong>not</strong> asked — the student gets a fixed, kind answer to speak to a trusted adult, and you are told here. If a message slips past the word check but the tutor's own answer sounds like a crisis answer, you are told here too.
        Talk to the student in person and follow your school's child-protection steps. Only the start of the message is kept.</span></div>
<?php if (!$flags): ?>
<div class="empty-state"><i class="fas fa-heart"></i><h2>Nothing to look at</h2><p>No student message has been flagged.</p></div>
<?php else: ?>
<div class="content-card"><div class="table-wrap"><table class="app-table">
    <thead><tr><th>Student</th><th>Class</th><th>Why</th><th>What they wrote (start)</th><th>When</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($flags as $f): ?>
    <tr><td><strong><?php echo htmlspecialchars($f['student']); ?></strong></td><td><?php echo htmlspecialchars($f['class_name']); ?></td>
        <td><span class="status-badge <?php echo $f['category'] === 'bullying' ? 'late' : 'missing'; ?>"><?php echo htmlspecialchars($cats[$f['category']] ?? $f['category']); ?></span></td>
        <td style="max-width:340px;overflow-wrap:anywhere;"><?php echo htmlspecialchars($f['snippet']); ?></td>
        <td><?php echo htmlspecialchars(date('M j, g:i A', strtotime($f['created_at']))); ?></td>
        <td><?php if ($f['status'] === 'open'): ?>
            <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;"><?php echo csrfField(); ?><input type="hidden" name="flag_id" value="<?php echo (int) $f['id']; ?>">
                <input type="text" name="note" maxlength="255" placeholder="What you did (optional)" aria-label="What you did" style="min-width:170px;">
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-check"></i> Handled</button></form>
            <?php else: ?><span class="status-badge graded">Handled</span> <span class="form-hint" style="display:inline;margin:0;"><?php echo htmlspecialchars(($f['handler'] ?? '') . ' · ' . date('M j', strtotime($f['handled_at'])) . ($f['note'] ? ' · ' . $f['note'] : '')); ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
