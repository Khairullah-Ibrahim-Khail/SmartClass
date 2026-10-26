<?php
/**
 * Class settings (teachers of the class: owner + co-teachers) — ?id=N
 *   archive / restore are for the class owner only (co-teachers cannot see archived classes)
 *   save        edit name / description / tags / image URL / colour theme / stream permission /
 *               whether students need approval to join
 *   reset_code  new join code (old code and invite link stop working)
 *   archive     hide the class from students and active lists (is_active = 0)
 *   restore     bring an archived class back
 * Inline field errors; SweetAlert2 confirms + task results.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/classes/my-classes.php');
}

// Archived classes are editable too (by the owner, to restore them), so not getAccessibleClass()
$class = $db->fetchOne(
    "SELECT c.*, ct.role AS teacher_role FROM classes c
     INNER JOIN class_teachers ct ON ct.class_id = c.id AND ct.teacher_id = ?
     WHERE c.id = ? AND (c.is_active = 1 OR ct.role = 'owner')", [$current_user_id, (int) ($_GET['id'] ?? 0)]);
$is_primary = $class && $class['teacher_role'] === 'owner';
if (!$class) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}
$class_id = (int) $class['id'];

$field_errors = ['class_name' => '', 'description' => '', 'image' => ''];
$task_result = null;
$form = $class;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save') {
        $form['class_name']  = trim($_POST['class_name'] ?? '');
        $form['description'] = trim($_POST['description'] ?? '');
        $form['tags']        = trim($_POST['tags'] ?? '');
        $form['image']       = trim($_POST['image'] ?? '');
        $form['theme']       = $_POST['theme'] ?? 'auto';
        $form['stream_permission'] = $_POST['stream_permission'] ?? 'comment';
        if ($form['theme'] !== 'auto' && !isset(classThemes()[$form['theme']])) $form['theme'] = 'auto';
        if (!isset(streamPermissionLabels()[$form['stream_permission']])) $form['stream_permission'] = 'comment';
        $form['require_approval'] = !empty($_POST['require_approval']) ? 1 : 0;
        $form['ai_students'] = !empty($_POST['ai_students']) ? 1 : 0;
        $form['ai_student_writing'] = !empty($_POST['ai_student_writing']) ? 1 : 0;
        $form['ai_language'] = in_array($_POST['ai_language'] ?? '', ['screen', 'en', 'question'], true) ? $_POST['ai_language'] : 'screen';

        if ($form['class_name'] === '') {
            $field_errors['class_name'] = 'Class name is required';
        } elseif (mb_strlen($form['class_name']) > 100) {
            $field_errors['class_name'] = 'Class name must be under 100 characters';
        }
        if ($form['description'] === '') {
            $field_errors['description'] = 'Description is required';
        }
        $scheme = strtolower((string) parse_url($form['image'], PHP_URL_SCHEME));
        if ($form['image'] !== '' && (!filter_var($form['image'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true))) {
            $field_errors['image'] = 'Please enter a valid http(s) image URL (or leave it empty)';
        }

        if (!array_filter($field_errors)) {
            $db->update('classes', [
                'class_name'  => $form['class_name'],
                'description' => $form['description'],
                'tags'        => mb_substr($form['tags'], 0, 255) ?: null,
                'image'       => $form['image'] !== '' ? $form['image'] : null,
                'theme'       => $form['theme'],
                'stream_permission' => $form['stream_permission'],
                'require_approval'  => $form['require_approval'],
                'ai_students'       => $form['ai_students'],
                'ai_student_writing' => $form['ai_student_writing'],
                'ai_language'       => $form['ai_language'],
            ], 'id = ?', [$class_id]);
            logActivity('edit_class', 'classes', "Edited class #{$class_id} {$form['class_name']}");
            Session::flash('edit_msg', ['success', 'Saved', 'Class details updated.']);
            redirect(APP_URL . '/pages/classes/edit.php?id=' . $class_id);
        }
    } elseif ($action === 'reset_code') {
        do {
            $code = generateClassCode(8);
        } while ($db->exists('classes', 'class_code', $code));
        $db->update('classes', ['class_code' => $code], 'id = ?', [$class_id]);
        logActivity('reset_class_code', 'classes', "Reset join code of {$class['class_name']}");
        Session::flash('edit_msg', ['success', 'New class code: ' . $code, 'The old code and invite link no longer work.']);
        redirect(APP_URL . '/pages/classes/edit.php?id=' . $class_id);
    } elseif (($action === 'archive' || $action === 'restore') && !$is_primary) {
        $task_result = ['error', 'Not allowed', 'Only the class owner can archive or restore the class.'];
    } elseif ($action === 'archive' || $action === 'restore') {
        $active = $action === 'restore' ? 1 : 0;
        $db->update('classes', ['is_active' => $active], 'id = ? AND teacher_id = ?', [$class_id, $current_user_id]);
        logActivity($action . '_class', 'classes', ucfirst($action) . "d class {$class['class_name']}");
        Session::flash('classes_msg', $active
            ? ['success', 'Class restored', $class['class_name'] . ' is active again.']
            : ['success', 'Class archived', $class['class_name'] . ' is hidden from students. Restore it any time from My Classes.']);
        redirect(APP_URL . ($active ? '/pages/classes/stream.php?id=' . $class_id : '/pages/classes/my-classes.php'));
    }
}

$task_result = Session::flash('edit_msg') ?? $task_result;

$page_title = 'Class Settings';
$page_subtitle = $class['class_name'] . ($class['is_active'] ? '' : ' (archived)');
$active_menu = 'classes';
$page_script = "
    const form = document.getElementById('editClassForm');
    bindLiveClear(form);
    form.addEventListener('submit', function(e) {
        let ok = true;
        const name = document.getElementById('class_name');
        const desc = document.getElementById('description');
        const img  = document.getElementById('image');
        if (!name.value.trim()) { setFieldError(name, 'Class name is required'); ok = false; }
        if (!desc.value.trim()) { setFieldError(desc, 'Description is required'); ok = false; }
        if (img.value.trim() && !/^https?:\\/\\/.+/i.test(img.value.trim())) { setFieldError(img, 'Please enter a valid http(s) image URL (or leave it empty)'); ok = false; }
        if (!ok) e.preventDefault();
    });
    document.querySelectorAll('form[data-confirm]').forEach(function(f) {
        f.addEventListener('submit', function(e) {
            if (f.dataset.confirmed) return;
            e.preventDefault();
            Swal.fire({ icon: 'warning', titleText: f.dataset.confirm, text: f.dataset.confirmText || '',
                        showCancelButton: true, confirmButtonText: f.dataset.confirmButton, confirmButtonColor: '#ef476f' })
                .then(function(r) { if (r.isConfirmed) { f.dataset.confirmed = '1'; f.submit(); } });
        });
    });";
if ($task_result) {
    $page_script .= "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<?php if ($class['is_active']): ?>
<a class="back-link" href="<?php ob_start(); ?>stream.php?id=<?php echo $class_id; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Back to class</a>
<?php else: ?>
<a class="back-link" href="<?php ob_start(); ?>my-classes.php<?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Back to My Classes</a>
<?php endif; ?>

<div class="form-card" style="margin-bottom:20px;">
    <form method="POST" id="editClassForm" novalidate>
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label for="class_name">Class Name <span class="required-mark">*</span></label>
            <input type="text" id="class_name" name="class_name" maxlength="100"
                class="<?php echo $field_errors['class_name'] ? 'is-invalid' : ''; ?>"
                value="<?php echo htmlspecialchars($form['class_name']); ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_name']); ?></div>
        </div>
        <div class="form-group">
            <label for="description">Description <span class="required-mark">*</span></label>
            <?php echo aiWriteButton('class', '#description', ['class_id' => $class_id, 'title' => '#class_name', 'use' => true, 'ctx' => ['tags' => '#tags']]); ?>
            <textarea id="description" name="description"
                class="<?php echo $field_errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description'] ?? ''); ?></textarea>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['description']); ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="tags">Category Tags (comma separated)</label>
                <input type="text" id="tags" name="tags" maxlength="255" value="<?php echo htmlspecialchars($form['tags'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="image">Banner Image URL</label>
                <input type="url" id="image" name="image" placeholder="https://example.com/image.jpg"
                    class="<?php echo $field_errors['image'] ? 'is-invalid' : ''; ?>"
                    value="<?php echo htmlspecialchars($form['image'] ?? ''); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['image']); ?></div>
            </div>
        </div>
        <div class="form-group">
            <label>Colour Theme</label>
            <div class="theme-picker" role="radiogroup" aria-label="Colour theme">
                <label class="theme-swatch" title="Automatic" style="background: <?php echo classTheme(['id' => $class_id, 'theme' => 'auto']); ?>;">
                    <input type="radio" name="theme" value="auto" <?php echo ($form['theme'] ?? 'auto') === 'auto' ? 'checked' : ''; ?>><span>Auto</span>
                </label>
                <?php foreach (classThemes() as $key => [$label, $bg]): ?>
                <label class="theme-swatch" title="<?php echo $label; ?>" style="background: <?php echo $bg; ?>;">
                    <input type="radio" name="theme" value="<?php echo $key; ?>" <?php echo ($form['theme'] ?? '') === $key ? 'checked' : ''; ?>><span><?php echo $label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group" id="join-settings">
            <label class="switch-row">
                <input type="hidden" name="require_approval" value="0">
                <input type="checkbox" name="require_approval" value="1" <?php echo !empty($form['require_approval']) ? 'checked' : ''; ?>>
                <span><strong>Students need my approval to join</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">When on, students who use the class code or invite link appear as join requests on the People page until you approve them.</small></span>
            </label>
        </div>

        <div class="form-group" id="ai-settings">
            <label class="switch-row">
                <input type="hidden" name="ai_students" value="0">
                <input type="checkbox" name="ai_students" value="1" <?php echo !empty($form['ai_students']) ? 'checked' : ''; ?>>
                <span><strong>AI for students in this class</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">When off, no AI helper works for students in this class — not even with their own key.
                        When on, they use their own key, your shared key (Settings › My AI, with a monthly limit per student) or the school's.</small></span>
            </label>
        </div>

        <div class="form-group" id="ai-student-writing">
            <label class="switch-row">
                <input type="hidden" name="ai_student_writing" value="0">
                <input type="checkbox" name="ai_student_writing" value="1" <?php echo !empty($form['ai_student_writing']) ? 'checked' : ''; ?>>
                <span><strong>AI writing help for students (language check and hints)</strong>
                    <small class="form-hint" style="display:block;margin:2px 0 0;">Off by default — you decide. When on, students see two small buttons under the box where they write an assignment answer or a forum reply:
                        “Check my language” (fixes spelling and grammar, keeps their own ideas) and “Give me hints” (what to look at — never the answer). It never writes the answer for them and is never shown in quizzes or exams.</small></span>
            </label>
        </div>

        <div class="form-group" id="ai-language">
            <label for="ai_language">AI answers in</label>
            <select id="ai_language" name="ai_language">
                <?php foreach (['screen' => "The language of the student's screen (recommended)", 'en' => 'Always English (language classes)', 'question' => 'The language of the question'] as $k => $label): ?>
                <option value="<?php echo $k; ?>" <?php echo ($form['ai_language'] ?? 'screen') === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="form-hint">Applies to the AI Tutor and the lecture study helper. A student can still ask for another language. Code and technical terms stay as they are.</small>
        </div>

        <div class="form-group" id="stream-settings">
            <label for="stream_permission">Stream</label>
            <select id="stream_permission" name="stream_permission">
                <?php foreach (streamPermissionLabels() as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo ($form['stream_permission'] ?? 'comment') === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <small class="form-hint">Lets students start discussions, only reply, or just read.</small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-app"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
</div>

<div class="form-card settings-list">
    <div class="settings-row">
        <div>
            <strong>Class code</strong>
            <p class="form-hint" style="margin:2px 0 0;">Current code: <span class="code-chip"><?php echo htmlspecialchars($class['class_code']); ?></span>
                — resetting it stops the old code and invite link from working.</p>
        </div>
        <form method="POST" data-confirm="Reset the class code?" data-confirm-text="Students who already joined stay in the class. The old code and invite link stop working." data-confirm-button="Reset code">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reset_code">
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-sync-alt"></i> Reset code</button>
        </form>
    </div>
    <?php if ($class['is_active']): ?>
    <div class="settings-row">
        <div>
            <strong>Copy this class</strong>
            <p class="form-hint" style="margin:2px 0 0;">Start a new class (for a new term or group) from this one — keep the lectures, assignments, quizzes and files you choose. No students or grades are copied.</p>
        </div>
        <a class="btn-app btn-sm btn-outline" href="copy.php?id=<?php echo $class_id; ?>"><i class="fas fa-copy"></i> Copy class</a>
    </div>
    <?php endif; ?>
    <div class="settings-row">
        <?php if (!$is_primary): ?>
        <div>
            <strong>Archive class</strong>
            <p class="form-hint" style="margin:2px 0 0;">Only the class owner can archive this class. You are a co-teacher.</p>
        </div>
        <?php elseif ($class['is_active']): ?>
        <div>
            <strong>Archive class</strong>
            <p class="form-hint" style="margin:2px 0 0;">Hides the class from students and your active classes. Nothing is deleted — you can restore it later.</p>
        </div>
        <form method="POST" data-confirm="Archive this class?" data-confirm-text="Students will no longer see it. You can restore it any time from My Classes." data-confirm-button="Archive">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="archive">
            <button type="submit" class="btn-app btn-sm btn-danger"><i class="fas fa-archive"></i> Archive</button>
        </form>
        <?php else: ?>
        <div>
            <strong>This class is archived</strong>
            <p class="form-hint" style="margin:2px 0 0;">Students cannot see it. Restore it to make it active again.</p>
        </div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="restore">
            <button type="submit" class="btn-app btn-sm"><i class="fas fa-undo"></i> Restore</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
