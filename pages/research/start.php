<?php
/**
 * Research › Start a project / edit the proposal (student). A full page, not a pop-up.
 *   ?id=N  edit your own draft or a proposal the teacher sent back for changes.
 *   POST action = save (keep as a draft) | send (save and send the proposal to the teacher). Errors show under the field.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isStudent()) redirect(APP_URL . '/pages/research/projects.php');
$classes = getUserClasses($current_user_id, $current_user_role);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p = $id ? researchProject($id, $current_user_id, $current_user_role) : null;
if ($id && (!$p || !in_array($p['status'], ['draft', 'changes'], true))) { Session::flash('research_msg', ['info', 'Not editable', 'That project cannot be edited — open it to talk with your teacher.']); redirect(APP_URL . '/pages/research/projects.php'); }

$errors = []; $old = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = ['class_id' => (int) ($_POST['class_id'] ?? 0), 'title' => (string) ($_POST['title'] ?? ''), 'question' => (string) ($_POST['question'] ?? ''), 'plan' => (string) ($_POST['plan'] ?? '')];
    if (!verifyCsrf()) { $errors['form'] = 'Your session expired. Please reload the page.'; }
    elseif (($_POST['action'] ?? '') === 'delete' && $p) {
        $r = researchDeleteDraft($p, $current_user_id);
        Session::flash('research_msg', $r['ok'] ? ['success', 'Deleted', 'The draft was removed.'] : ['error', 'Not deleted', $r['error']]);
        redirect(APP_URL . '/pages/research/projects.php');
    } else {
        $class_id = $p ? (int) $p['class_id'] : $old['class_id'];
        $r = researchSave($current_user_id, $class_id, $p ? $p['id'] : 0, $old['title'], $old['question'], $old['plan']);
        if ($r['ok'] && ($_POST['action'] ?? '') === 'send') {
            $np = researchProject($r['id'], $current_user_id, $current_user_role);
            $r2 = researchSubmitProposal($np, $current_user_id);
            if ($r2['ok']) { Session::flash('research_msg', ['success', 'Proposal sent', 'Your teacher was told. You will be notified of the decision.']); redirect(APP_URL . '/pages/research/project.php?id=' . (int) $r['id']); }
            $errors = $r2['errors'] ?: ['form' => $r2['error']]; $p = $np;   // saved, but not complete enough to send
        } elseif ($r['ok']) {
            Session::flash('research_msg', ['success', 'Draft saved', 'You can finish it later and send it when it is ready.']); redirect(APP_URL . '/pages/research/projects.php');
        } else { $errors = $r['errors'] ?: ['form' => $r['error']]; }
    }
}
$v = $old ?? ($p ? ['class_id' => (int) $p['class_id'], 'title' => $p['title'], 'question' => $p['question'], 'plan' => (string) $p['plan']] : ['class_id' => (int) ($classes[0]['id'] ?? 0), 'title' => '', 'question' => '', 'plan' => '']);
$last_change = $p && $p['status'] === 'changes' ? $db->fetchOne("SELECT body FROM research_updates WHERE project_id = ? AND kind = 'decision' ORDER BY id DESC LIMIT 1", [$p['id']]) : null;

$page_title = $p ? 'Edit your proposal' : 'Start a research project';
$page_subtitle = 'Tell your teacher what you want to find out and how — they will approve it or ask for changes';
$active_menu = 'research';
$page_scripts = ['assets/js/class.js'];
$page_script = !empty($errors['form']) ? "Swal.fire({ icon: 'error', titleText: 'Not saved', text: " . json_encode(t($errors['form'])) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
$err = fn($k) => !empty($errors[$k]) ? '<div class="invalid-feedback show">' . htmlspecialchars(t($errors[$k])) . '</div>' : '';
?>
<?php echo backLink('Research projects', 'projects.php'); ?>
<?php if ($last_change): ?>
<div class="att-warning" role="status"><i class="fas fa-rotate-left"></i><div><strong>Your teacher asked for changes</strong><div class="prewrap"><?php echo htmlspecialchars(preg_replace('/^Changes requested:\s*/', '', $last_change['body'])); ?></div></div></div>
<?php endif; ?>
<form method="POST" class="content-card form-card rs-form" novalidate>
    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo $p ? (int) $p['id'] : 0; ?>">
    <div class="form-group">
        <label for="rsClass">Class</label>
        <?php if ($p): ?><input type="hidden" name="class_id" value="<?php echo (int) $p['class_id']; ?>"><input type="text" id="rsClass" value="<?php echo htmlspecialchars($p['class_name']); ?>" disabled>
        <?php else: ?><select name="class_id" id="rsClass"><?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $v['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php echo $err('class'); ?>
    </div>
    <div class="form-group">
        <label for="rsTitle">Project title <span class="req">*</span></label>
        <input type="text" id="rsTitle" name="title" maxlength="200" value="<?php echo htmlspecialchars($v['title']); ?>" placeholder="e.g. How does sleep affect exam results?" class="<?php echo !empty($errors['title']) ? 'is-invalid' : ''; ?>">
        <?php echo $err('title'); ?>
    </div>
    <div class="form-group">
        <label for="rsQuestion">Research question <span class="req">*</span></label>
        <input type="text" id="rsQuestion" name="question" maxlength="500" value="<?php echo htmlspecialchars($v['question']); ?>" placeholder="The one question your project will answer" class="<?php echo !empty($errors['question']) ? 'is-invalid' : ''; ?>">
        <small class="form-hint">Needed before you can send it. A clear question is the start of a good project.</small>
        <?php echo $err('question'); ?>
    </div>
    <div class="form-group">
        <label for="rsPlan">Plan <span class="req">*</span></label>
        <textarea id="rsPlan" name="plan" rows="8" maxlength="8000" placeholder="What will you do, in what steps, with which sources or methods, and what do you expect to hand in?" class="<?php echo !empty($errors['plan']) ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($v['plan']); ?></textarea>
        <?php echo $err('plan'); ?>
    </div>
    <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;">
        <span style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" name="action" value="send" class="btn-app"><i class="fas fa-paper-plane"></i> Send to my teacher</button>
            <button type="submit" name="action" value="save" class="btn-app btn-outline"><i class="fas fa-save"></i> Save as draft</button>
            <a href="projects.php" class="btn-app btn-outline">Cancel</a>
        </span>
    </div>
</form>
<?php if ($p && $p['status'] === 'draft'): ?>
<form method="POST" class="rs-delete" data-confirm="Delete this draft?" data-confirm-text="The project is removed for good. Nothing was sent to your teacher." data-confirm-button="Delete">
    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>"><input type="hidden" name="action" value="delete">
    <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-trash"></i> Delete this draft</button>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
