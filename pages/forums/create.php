<?php
/** New forum topic (teachers and students of the class): class, title, first message. Teachers of the class are told about a student's topic. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$classes = getUserClasses($current_user_id, $current_user_role);
$by_id = array_column($classes, 'class_name', 'id');
$form = ['class_id' => (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0), 'title' => trim((string) ($_POST['title'] ?? '')), 'body' => trim((string) ($_POST['body'] ?? ''))];
$errors = ['class_id' => '', 'title' => '', 'body' => '', 'file' => ''];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        if (!isset($by_id[$form['class_id']])) $errors['class_id'] = 'Choose one of your classes';
        if ($form['title'] === '') $errors['title'] = 'Give the topic a title'; elseif (mb_strlen($form['title']) > 200) $errors['title'] = 'Title must be under 200 characters';
        if ($form['body'] === '') $errors['body'] = 'Write your message'; elseif (mb_strlen($form['body']) > 5000) $errors['body'] = 'Message must be under 5,000 characters';
        if (!array_filter($errors)) {
            $file = $_FILES['file'] ?? null;
            $has_file = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($has_file) {   // check the file first so a bad one does not leave a topic behind
                $probe = storeUploadCheck($file);
                if ($probe) $errors['file'] = $probe;
            }
        }
        if (!array_filter($errors)) {
            $id = $db->insert('forum_threads', ['class_id' => $form['class_id'], 'user_id' => $current_user_id, 'title' => $form['title'], 'body' => $form['body']]);
            if ($has_file) {
                $r = forumAttach($id, null, $current_user_id, $file);
                if (!$r['ok']) Session::flash('forum_msg', ['warning', 'Topic posted without the file', $r['error']]);
            }
            logActivity('create_forum_topic', 'forums', "Started forum topic #{$id} \"{$form['title']}\" in {$by_id[$form['class_id']]}");
            if (isStudent()) {
                notifyClassTeachers($form['class_id'], 'forum_post', "New forum topic in {$by_id[$form['class_id']]}", $current_user_name . ': ' . $form['title'], 'pages/forums/thread.php?id=' . $id, $current_user_id);
            } else {
                notifyClassStudents($form['class_id'], 'forum_post', "New forum topic in {$by_id[$form['class_id']]}", $form['title'], 'pages/forums/thread.php?id=' . $id, $current_user_id);
            }
            redirect(APP_URL . '/pages/forums/thread.php?id=' . $id);
        }
        $task_result = ['error', 'Not posted', 'Fix the highlighted fields.'];
    }
}

$page_title = 'New Topic';
$page_subtitle = 'Start a discussion';
$active_menu = 'forums';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const tf = document.getElementById('topicForm');
    if (tf) { bindLiveClear(tf); tf.addEventListener('submit', function (e) {
        let ok = true;
        [['class_id', 'Choose one of your classes'], ['title', 'Give the topic a title'], ['body', 'Write your message']].forEach(function (p) { const el = document.getElementById(p[0]); if (!el.value.trim()) { setFieldError(el, p[1]); ok = false; } });
        if (!ok) e.preventDefault();
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> All topics</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You are not in any class yet</h3></div>
<?php else: ?>
<form method="POST" id="topicForm" class="form-card" novalidate enctype="multipart/form-data">
    <?php echo csrfField(); ?>
    <div class="form-group">
        <label for="class_id">Class <span class="required-mark">*</span></label>
        <select id="class_id" name="class_id" class="<?php echo $errors['class_id'] ? 'is-invalid' : ''; ?>">
            <option value="">Choose a class</option>
            <?php foreach ($classes as $c): ?><option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option><?php endforeach; ?>
        </select>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['class_id']); ?></div>
    </div>
    <div class="form-group">
        <label for="title">Title <span class="required-mark">*</span></label>
        <input type="text" id="title" name="title" maxlength="200" value="<?php echo htmlspecialchars($form['title']); ?>" class="<?php echo $errors['title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['title']); ?></div>
    </div>
    <div class="form-group">
        <label for="body">Message <span class="required-mark">*</span></label>
        <?php echo aiWriteButton('forum', '#body', ['class' => '#class_id', 'title' => '#title', 'use' => true]); ?>
        <textarea id="body" name="body" rows="6" maxlength="5000" class="<?php echo $errors['body'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['body']); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['body']); ?></div>
    </div>
    <div class="form-group">
        <label for="file">Attach a file <span class="form-hint" style="display:inline;margin:0;">optional — <?php echo strtoupper(implode(', ', array_keys(allowedUploadTypes()))); ?>, up to <?php echo formatBytes(maxUploadBytes()); ?></span></label>
        <input type="file" id="file" name="file" class="<?php echo $errors['file'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['file']); ?></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-paper-plane"></i> Post topic</button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
