<?php
/**
 * Edit a resource (teachers): title, description, category. The file itself stays — to replace the file, upload a new resource.
 * Inline field errors (client + server); CSRF; Post/Redirect/Get. Only the teachers of the resource's class.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/resources/view.php');
$categories = resourceCategories();
$r = $db->fetchOne("SELECT r.*, c.class_name FROM resources r INNER JOIN classes c ON r.class_id = c.id WHERE r.id = ? AND " . teachesClassSql('c'), [(int) ($_GET['id'] ?? $_POST['id'] ?? 0), $current_user_id]);
if (!$r) { redirect(APP_URL . '/pages/resources/view.php?error=not_found'); }
$form = ['title' => $r['title'], 'description' => (string) $r['description'], 'category' => $r['category']];
$errors = ['title' => '', 'description' => '', 'category' => ''];
$task_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = ['title' => trim((string) ($_POST['title'] ?? '')), 'description' => trim((string) ($_POST['description'] ?? '')), 'category' => trim((string) ($_POST['category'] ?? ''))];
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if ($form['title'] === '') $errors['title'] = 'Title is required'; elseif (mb_strlen($form['title']) > 200) $errors['title'] = 'Title must be under 200 characters';
        if (mb_strlen($form['description']) > 1000) $errors['description'] = 'Description must be under 1,000 characters';
        if (!isset($categories[$form['category']])) $errors['category'] = 'Please select a category';
        if (!array_filter($errors)) {
            $db->update('resources', ['title' => $form['title'], 'description' => $form['description'] !== '' ? $form['description'] : null, 'category' => $form['category']], 'id = ?', [$r['id']]);
            logActivity('edit_resource', 'resources', "Edited resource #{$r['id']} \"{$form['title']}\" in {$r['class_name']}");
            Session::flash('resource_msg', ['success', 'Saved', '"' . $form['title'] . '" was updated.']);
            redirect(APP_URL . '/pages/resources/view.php?class_id=' . (int) $r['class_id']);
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}
$page_title = 'Edit resource';
$page_subtitle = $r['class_name'] . ' — change the title, description or category';
$active_menu = 'resources';
$page_script = $task_result ? "Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php?class_id=<?php echo (int) $r['class_id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Resources</a>
<form method="POST" class="form-card" novalidate>
    <?php echo csrfField(); ?><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
    <div class="form-group">
        <label for="title">Title <span class="required-mark">*</span></label>
        <input type="text" id="title" name="title" maxlength="200" value="<?php echo htmlspecialchars($form['title']); ?>" class="<?php echo $errors['title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['title']); ?></div>
    </div>
    <div class="form-group">
        <label for="category">Category <span class="required-mark">*</span></label>
        <select id="category" name="category" class="<?php echo $errors['category'] ? 'is-invalid' : ''; ?>"><?php foreach ($categories as $k => $l): ?><option value="<?php echo htmlspecialchars($k); ?>" <?php echo $form['category'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($l); ?></option><?php endforeach; ?></select>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['category']); ?></div>
    </div>
    <div class="form-group">
        <label for="description">Description <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
        <?php echo aiWriteButton('resource', '#description', ['class_id' => (int) $r['class_id'], 'title' => '#title', 'use' => true, 'ctx' => ['category' => '#category']]); ?>
        <textarea id="description" name="description" rows="4" maxlength="1000" class="<?php echo $errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['description']); ?></div>
    </div>
    <p class="form-hint"><i class="fas fa-paperclip"></i> File: <?php echo htmlspecialchars((string) $r['original_name']); ?> (<?php echo formatBytes($r['file_size']); ?>). To replace the file, upload a new resource and delete this one.</p>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save changes</button><a href="view.php?class_id=<?php echo (int) $r['class_id']; ?>" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
