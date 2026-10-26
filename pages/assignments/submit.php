<?php
/**
 * Submit Assignment (student only) — ?id=<assignment id>
 * Written answer and/or one file. Students can resubmit until the work is graded;
 * after the due date the submission is still accepted but marked late.
 * Files are validated and stored under uploads/submissions/ with a random name.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isStudent()) {
    redirect(APP_URL . '/pages/assignments/view.php');
}

$assignment = $db->fetchOne("SELECT * FROM assignments WHERE id = ?", [(int) ($_GET['id'] ?? 0)]);
// Membership check: the student must be enrolled in the assignment's class
$class = $assignment ? getAccessibleClass($assignment['class_id'], $current_user_id, 'student') : null;
if (!$class) {
    redirect(APP_URL . '/pages/assignments/view.php?error=not_found');
}

$existing = $db->fetchOne(
    "SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?",
    [$assignment['id'], $current_user_id]
);
$graded = $existing && $existing['status'] === 'graded';
$max_bytes = maxUploadBytes();
$allowed_ext = array_keys(allowedUploadTypes());
$is_late_now = !empty($assignment['due_date']) && time() > strtotime($assignment['due_date']);

$field_errors = ['content' => '', 'file' => ''];
$task_error = '';
$saved = false;
$content = trim($_POST['content'] ?? ($existing['content'] ?? ''));

if (postBodyTooLarge()) {
    $field_errors['file'] = 'File is too large (max ' . formatBytes($max_bytes) . ')';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $task_error = 'Your session expired. Please submit again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $graded) {
    $task_error = 'This assignment has already been graded and can no longer be changed.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $has_new_file = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $keeps_old_file = $existing && !empty($existing['file_path']);

    if (mb_strlen($content) > 20000) {
        $field_errors['content'] = 'Answer is too long (max 20,000 characters)';
    } elseif ($content === '' && !$has_new_file && !$keeps_old_file) {
        $field_errors['content'] = 'Write an answer or attach a file';
    }

    $upload = null;
    if (!array_filter($field_errors) && $has_new_file) {
        $upload = storeUpload($_FILES['file'], 'submissions');
        if (!$upload['ok']) {
            $field_errors['file'] = $upload['error'];
            $upload = null;
        }
    }

    if (!array_filter($field_errors)) {
        $now = date('Y-m-d H:i:s');
        $data = [
            'content'      => $content !== '' ? $content : null,
            'submitted_at' => $now,
            'status'       => $is_late_now ? 'late' : 'submitted',
        ];
        if ($existing && $existing['status'] === 'returned') $data['revision'] = (int) $existing['revision'] + 1;   // handed in again after changes were asked for
        if ($upload) {
            $data['file_path'] = $upload['path'];
            $data['original_name'] = $upload['original_name'];
        }

        if ($existing) {
            // Only an ungraded row can change (re-checked in SQL against a race with grading)
            $db->update('submissions', $data, "id = ? AND status <> 'graded'", [$existing['id']]);
            $now_graded = $db->fetchOne("SELECT status FROM submissions WHERE id = ?", [$existing['id']])['status'] === 'graded';
            if ($now_graded) {
                // Graded meanwhile: nothing changed, discard the new file
                if ($upload && ($new = uploadAbsolutePath($upload['path']))) @unlink($new);
                $task_error = 'This assignment has already been graded and can no longer be changed.';
            } elseif ($upload && !empty($existing['file_path'])) {
                // Replaced file: remove the old one from disk
                $old = uploadAbsolutePath($existing['file_path']);
                if ($old) @unlink($old);
            }
        } else {
            $data += ['assignment_id' => $assignment['id'], 'student_id' => $current_user_id];
            $db->insert('submissions', $data);
        }

        if ($task_error === '') {
            $verb = $existing ? 'Resubmitted' : 'Submitted';
            logActivity('submit_assignment', 'assignments', "{$verb} assignment #{$assignment['id']} \"{$assignment['title']}\"" . ($is_late_now ? ' (late)' : ''));
            notifyClassTeachers(
                $class['id'],
                'submission_received',
                "{$verb}: {$assignment['title']}",
                "{$current_user_name} {$verb} work for {$assignment['title']}" . ($is_late_now ? ' (late)' : '') . '.',
                'pages/assignments/view.php?id=' . $assignment['id'],
                $current_user_id
            );
            $saved = true;
        }
    }
}

$page_title = 'Submit: ' . $assignment['title'];
$page_subtitle = $class['class_name'] . ' · Due ' . formatDate($assignment['due_date'], 'M d, Y h:i A');
$active_menu = 'assignments';

if ($saved) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        title: " . json_encode($is_late_now ? 'Submitted (late)' : 'Submitted!') . ",
        text: 'Your teacher has been notified.',
        confirmButtonText: 'View Assignment',
        confirmButtonColor: '#4361ee'
    }).then(function() {
        window.location.href = '" . APP_URL . "/pages/assignments/view.php?id={$assignment['id']}';
    });";
} else {
    $page_script = ($task_error ? "
    Swal.fire({ icon: 'error', title: 'Not submitted', text: " . json_encode($task_error) . ",
                confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('submitForm');
    if (form) {
        const MAX = {$max_bytes};
        const ALLOWED = " . json_encode($allowed_ext) . ";
        const HAS_OLD_FILE = " . ($existing && !empty($existing['file_path']) ? 'true' : 'false') . ";
        const fileInput = document.getElementById('file');
        const fileErr = document.getElementById('fileError');
        bindLiveClear(form);
        fileInput.addEventListener('change', function() { fileInput.classList.remove('is-invalid'); fileErr.classList.remove('show'); });
        form.addEventListener('submit', function(e) {
            let ok = true;
            const content = document.getElementById('content');
            const f = fileInput.files[0];
            if (!content.value.trim() && !f && !HAS_OLD_FILE) {
                setFieldError(content, 'Write an answer or attach a file'); ok = false;
            }
            if (f) {
                const ext = f.name.split('.').pop().toLowerCase();
                let msg = '';
                if (ALLOWED.indexOf(ext) === -1) msg = 'File type not allowed. Allowed: ' + ALLOWED.join(', ').toUpperCase();
                else if (f.size > MAX) msg = 'File is too large (max " . formatBytes($max_bytes) . ")';
                if (msg) { fileInput.classList.add('is-invalid'); fileErr.textContent = msg; fileErr.classList.add('show'); ok = false; }
            }
            if (!ok) e.preventDefault();
        });
    }";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<a class="back-link" href="<?php ob_start(); ?>view.php?id=<?php echo (int) $assignment['id']; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>">
    <i class="fas fa-arrow-left"></i> Back to assignment
</a>

<div class="content-card">
    <div class="card-head">
        <h2><i class="fas fa-tasks"></i> <?php echo htmlspecialchars($assignment['title']); ?></h2>
        <span class="status-badge <?php echo $is_late_now ? 'late' : 'pending'; ?>">
            <?php echo $is_late_now ? 'Past due — will be marked late' : 'Open'; ?>
        </span>
    </div>
    <div class="card-inner">
        <div class="prewrap"><?php echo htmlspecialchars($assignment['description'] ?? ''); ?></div>
    </div>
</div>

<?php if ($graded): ?>
<div class="empty-state">
    <i class="fas fa-lock"></i>
    <h3>Already graded</h3>
    <p style="margin-top:6px;">This submission was graded and can no longer be changed.</p>
</div>
<?php else: ?>
<div class="form-card">
    <form method="POST" id="submitForm" enctype="multipart/form-data" novalidate>
        <?php echo csrfField(); ?>

        <?php if ($existing): ?>
        <p class="form-hint" style="margin:0;">
            <i class="fas fa-info-circle"></i>
            You submitted on <?php echo formatDate($existing['submitted_at'], 'M d, Y h:i A'); ?>.
            Submitting again replaces your previous answer<?php echo !empty($existing['file_path']) ? ' (attach a new file to replace your current file)' : ''; ?>.
        </p>
        <?php endif; ?>

        <div class="form-group">
            <label for="content">Your Answer</label>
            <textarea id="content" name="content" rows="8" placeholder="Type your answer here (optional if you attach a file)"
                class="<?php echo $field_errors['content'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($content); ?></textarea>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['content']); ?></div>
        </div>

        <div class="form-group">
            <label for="file">Attach File</label>
            <input type="file" id="file" name="file" accept="<?php echo '.' . implode(',.', $allowed_ext); ?>"
                class="<?php echo $field_errors['file'] ? 'is-invalid' : ''; ?>">
            <div class="invalid-feedback <?php echo $field_errors['file'] ? 'show' : ''; ?>" id="fileError"><?php echo htmlspecialchars($field_errors['file']); ?></div>
            <small class="form-hint">
                <?php if ($existing && !empty($existing['file_path'])): ?>
                Current file: <a href="download.php?id=<?php echo (int) $existing['id']; ?>"><?php echo htmlspecialchars($existing['original_name'] ?? 'file'); ?></a> ·
                <?php endif; ?>
                Allowed: <?php echo strtoupper(implode(', ', $allowed_ext)); ?> · Max <?php echo formatBytes($max_bytes); ?>
            </small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-app">
                <i class="fas fa-paper-plane"></i> <?php echo $existing ? 'Resubmit' : 'Submit'; ?>
            </button>
            <a href="view.php?id=<?php echo (int) $assignment['id']; ?>" class="btn-app btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
