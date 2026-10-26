<?php
/**
 * New announcement (teachers) — one message to one or several of your classes at once.
 *   POST: classes[] (classes you teach), body, optional pin, optional publish time (then students see it and
 *   are notified at that time, as with a scheduled stream post). CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (!isTeacher()) redirect(APP_URL . '/pages/announcements/view.php');

$classes = getUserClasses($current_user_id, 'teacher');
$by_id = array_column($classes, 'class_name', 'id');
$errors = ['classes' => '', 'body' => '', 'publish_at' => ''];
$task_result = null;
$body = trim((string) ($_POST['body'] ?? ''));
$picked = array_map('intval', (array) ($_POST['classes'] ?? ((int) ($_GET['class_id'] ?? 0) ? [(int) $_GET['class_id']] : [])));
$pin = !empty($_POST['pin']);
$when_raw = trim((string) ($_POST['publish_at'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please submit the form again.'];
    } else {
        $targets = array_values(array_filter(array_unique($picked), fn($id) => isset($by_id[$id])));   // only classes this teacher teaches
        $publish_at = null;
        if (!$targets) $errors['classes'] = 'Choose at least one class';
        if ($body === '') $errors['body'] = 'Write the announcement';
        elseif (mb_strlen($body) > 5000) $errors['body'] = 'Announcement must be under 5,000 characters';
        if ($when_raw !== '') {
            $w = DateTime::createFromFormat('Y-m-d\TH:i', $when_raw);
            if (!$w || $w->format('Y-m-d\TH:i') !== $when_raw) $errors['publish_at'] = 'Please enter a valid date and time';
            elseif ($w->getTimestamp() <= time() + 60) $errors['publish_at'] = 'Pick a time in the future, or clear it to post now';
            elseif ($w->getTimestamp() > strtotime('+1 year')) $errors['publish_at'] = 'You can schedule up to one year ahead';
            else $publish_at = $w->format('Y-m-d H:i:00');
        }
        if (!array_filter($errors)) {
            foreach ($targets as $cid) {
                $aid = $db->insert('announcements', ['class_id' => $cid, 'user_id' => $current_user_id, 'body' => $body, 'pinned' => $pin ? 1 : 0,
                                                     'publish_at' => $publish_at, 'notified_at' => $publish_at ? null : date('Y-m-d H:i:s')]);
                if (!$publish_at) {
                    notifyClassStudents($cid, 'announcement_posted', 'New announcement in ' . $by_id[$cid], mb_strimwidth($body, 0, 140, '…'),
                        'pages/classes/stream.php?id=' . $cid . '#post-' . $aid, $current_user_id);
                }
            }
            logActivity('post_announcement', 'announcements', 'Announced to ' . count($targets) . ' class(es)' . ($publish_at ? " (scheduled {$publish_at})" : ''));
            Session::flash('ann_msg', ['success', $publish_at ? 'Scheduled' : 'Posted', count($targets) . ' class' . (count($targets) == 1 ? '' : 'es') . ($publish_at ? ' will get it at the chosen time.' : ' — students were notified.')]);
            redirect(APP_URL . '/pages/announcements/view.php');
        }
        $task_result = ['error', 'Not posted', 'Fix the highlighted fields.'];
    }
}

$page_title = 'New Announcement';
$page_subtitle = 'Tell one or several classes something important';
$active_menu = 'announcements';
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const af = document.getElementById('annForm');
    if (af) { bindLiveClear(af); af.addEventListener('submit', function (e) {
        let ok = true;
        if (!af.querySelector('[name=\"classes[]\"]:checked')) { document.getElementById('classesErr').textContent = 'Choose at least one class'; document.getElementById('classesErr').classList.add('show'); ok = false; }
        const b = af.querySelector('[name=body]'); if (!b.value.trim()) { setFieldError(b, 'Write the announcement'); ok = false; }
        if (!ok) e.preventDefault();
    }); }";
$can_ai_write = aiAvailableFor('writing', $current_user_id);
$page_scripts = $can_ai_write ? ['assets/js/ai-assist.js'] : [];

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>view.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> All announcements</a>
<?php if (!$classes): ?>
<div class="empty-state"><i class="fas fa-chalkboard"></i><h3>You have no classes yet</h3></div>
<?php else: ?>
<form method="POST" id="annForm" class="form-card" novalidate>
    <?php echo csrfField(); ?>
    <div class="form-group">
        <label>Classes <span class="required-mark">*</span></label>
        <div class="ann-classes">
            <?php foreach ($classes as $c): ?>
            <label class="material-check"><input type="checkbox" name="classes[]" value="<?php echo (int) $c['id']; ?>" <?php echo in_array((int) $c['id'], $picked, true) ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($c['class_name']); ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="invalid-feedback<?php echo $errors['classes'] ? ' show' : ''; ?>" id="classesErr"><?php echo htmlspecialchars($errors['classes']); ?></div>
    </div>
    <div class="form-group">
        <label for="body">Announcement <span class="required-mark">*</span></label>
        <textarea id="body" name="body" rows="5" maxlength="5000" class="<?php echo $errors['body'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($body); ?></textarea>
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['body']); ?></div>
        <?php if ($can_ai_write): ?>
        <div class="ai-assist-row">
            <button type="button" class="btn-app btn-sm btn-outline" data-ai-task="announcement" data-target="#body" data-prompt="What do you want to announce?"><i class="fas fa-robot"></i> Write with AI</button>
            <button type="button" class="btn-app btn-sm btn-outline" data-ai-task="translate" data-target="#body" data-use-target="1"><i class="fas fa-language"></i> Translate</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="publish_at">Post on <span class="form-hint" style="display:inline;margin:0;">optional — leave empty to post now</span></label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="<?php echo htmlspecialchars($when_raw); ?>" class="<?php echo $errors['publish_at'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($errors['publish_at']); ?></div>
        </div>
        <div class="form-group">
            <label class="switch-row" style="margin-top:26px;"><input type="checkbox" name="pin" value="1" <?php echo $pin ? 'checked' : ''; ?>> <span><strong>Pin to the top</strong></span></label>
        </div>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-bullhorn"></i> Post</button><a href="view.php" class="btn-app btn-outline">Cancel</a></div>
</form>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
