<?php
/**
 * Edit an announcement (its author): the text, the pin, and — while it is still scheduled — the time it goes out.
 * A post that is already published is marked "Edited"; a scheduled one is simply changed. Inline errors; CSRF; PRG.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/announcements/view.php');
$a = $db->fetchOne("SELECT a.*, c.class_name FROM announcements a INNER JOIN classes c ON a.class_id = c.id WHERE a.id = ? AND a.user_id = ? AND " . teachesClassSql('c'), [(int) ($_GET['id'] ?? $_POST['id'] ?? 0), $current_user_id, $current_user_id]);
if (!$a) { Session::flash('ann_msg', ['error', 'Not found', 'You can only edit your own announcements.']); redirect(APP_URL . '/pages/announcements/view.php'); }
$scheduled = $a['publish_at'] !== null && $a['notified_at'] === null && strtotime($a['publish_at']) > time();
$fmt = fn($d) => $d ? date('Y-m-d\\TH:i', strtotime($d)) : '';
$form = ['body' => $a['body'], 'pin' => (int) $a['pinned'] === 1, 'publish_at' => $fmt($a['publish_at'])];
$errors = ['body' => '', 'publish_at' => ''];
$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = ['body' => trim((string) ($_POST['body'] ?? '')), 'pin' => !empty($_POST['pin']), 'publish_at' => trim((string) ($_POST['publish_at'] ?? ''))];
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if ($form['body'] === '') $errors['body'] = 'Write the announcement'; elseif (mb_strlen($form['body']) > 5000) $errors['body'] = 'Announcement must be under 5,000 characters';
        $new_at = $a['publish_at'];
        if ($scheduled) {
            $w = DateTime::createFromFormat('Y-m-d\\TH:i', $form['publish_at']);
            if (!$w || $w->format('Y-m-d\\TH:i') !== $form['publish_at']) $errors['publish_at'] = 'Please enter a valid date and time';
            elseif ($w->getTimestamp() <= time() + 60) $errors['publish_at'] = 'Pick a time in the future (or post it now from the class page)';
            elseif ($w->getTimestamp() > strtotime('+1 year')) $errors['publish_at'] = 'You can schedule up to one year ahead';
            else $new_at = $w->format('Y-m-d H:i:00');
        }
        if (!array_filter($errors)) {
            $row = ['body' => $form['body'], 'pinned' => $form['pin'] ? 1 : 0, 'publish_at' => $new_at];
            if (!$scheduled && $a['body'] !== $form['body']) $row['edited_at'] = date('Y-m-d H:i:s');
            $db->update('announcements', $row, 'id = ?', [$a['id']]);
            logActivity('edit_announcement', 'announcements', "Edited announcement #{$a['id']} in {$a['class_name']}");
            Session::flash('ann_msg', ['success', 'Saved', $scheduled ? 'The announcement was updated; it still goes out at the scheduled time.' : 'The announcement was updated and is marked as edited.']);
            redirect(APP_URL . '/pages/announcements/view.php?class_id=' . (int) $a['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}
$page_title = 'Edit announcement';
$page_subtitle = $a['class_name'] . ($scheduled ? ' — scheduled' : '');
$active_menu = 'announcements';
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $a['class_id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Announcements</a>
<form method="POST" class="form-card" novalidate>
    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
    <div class="form-group">
        <label for="body">Announcement <span class="required-mark">*</span></label>
        <?php echo aiWriteButton('improve', '#body', ['class_id' => (int) $a['class_id'], 'use' => true, 'label' => 'Improve the wording with AI']); ?>
        <textarea id="body" name="body" rows="7" maxlength="5000" class="<?php echo $errors['body'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['body']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['body']); ?></div>
        <?php if (!$scheduled): ?><small class="form-hint">Students already saw this post, so it will be marked “Edited”.</small><?php endif; ?>
    </div>
    <?php if ($scheduled): ?>
    <div class="form-group">
        <label for="publish_at">Goes out at</label>
        <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo htmlspecialchars($form['publish_at']); ?>" class="<?php echo $errors['publish_at'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['publish_at']); ?></div>
    </div>
    <?php endif; ?>
    <label class="material-check" style="margin-bottom:14px;"><input type="checkbox" name="pin" value="1" <?php echo $form['pin'] ? 'checked' : ''; ?>><span>Pin to the top</span></label>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save changes</button><a href="view.php?class_id=<?php echo (int) $a['class_id']; ?>" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
