<?php
/**
 * Copy a class (teachers of the class) — ?id=N
 *   POST copy   a new class with the same settings + the lectures / assignments / quizzes / resources ticked
 *               (see includes/class_copy.php for what is and is not copied). Lectures arrive as drafts,
 *               assignments and quizzes without due dates. Students, grades and the stream are never copied.
 * Inline field errors; CSRF; Post/Redirect/Get to the new class.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/classes/my-classes.php');
}
$source = getAccessibleClass((int) ($_GET['id'] ?? 0), $current_user_id, 'teacher');
if (!$source) {
    redirect(APP_URL . '/pages/classes/my-classes.php?error=not_found');
}
$class_id = (int) $source['id'];
$self = APP_URL . '/pages/classes/copy.php?id=' . $class_id;

$part_labels = ['lectures' => 'Lectures (as drafts — you publish them)', 'assignments' => 'Assignments (without due dates)',
                'quizzes' => 'Quizzes (without due dates)', 'resources' => 'Resources (the files are copied)'];
$counts = [
    'lectures'    => $db->count('lectures', 'class_id = ?', [$class_id]),
    'assignments' => $db->count('assignments', 'class_id = ?', [$class_id]),
    'quizzes'     => $db->count('quizzes', 'class_id = ?', [$class_id]),
    'resources'   => $db->count('resources', 'class_id = ?', [$class_id]),
];
$name = $_SERVER['REQUEST_METHOD'] === 'POST' ? trim((string) ($_POST['class_name'] ?? '')) : mb_substr('Copy of ' . $source['class_name'], 0, 100);
$picked = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array) ($_POST['parts'] ?? []) : array_keys(array_filter($counts));
$name_error = '';
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $parts = array_values(array_intersect(array_keys($part_labels), array_map('strval', $picked)));
        if ($name === '') $name_error = 'Give the new class a name';
        elseif ($limit_msg = limitReached('classes')) $name_error = $limit_msg;
        elseif (mb_strlen($name) > 100) $name_error = 'Class name must be under 100 characters';
        else {
            try {
                $r = copyClass($source, $current_user_id, $name, $parts);
                logActivity('copy_class', 'classes', "Copied class {$source['class_name']} (#{$class_id}) to {$name} (#{$r['class_id']})");
                $c = $r['counts'];
                $bits = [];
                foreach (['lectures' => 'lecture', 'assignments' => 'assignment', 'quizzes' => 'quiz', 'resources' => 'resource', 'images' => 'picture'] as $k => $word) {
                    if ($c[$k]) $bits[] = $c[$k] . ' ' . $word . ($c[$k] == 1 ? '' : 's');
                }
                Session::flash('class_msg', ['success', 'Class copied', ($bits ? 'Copied ' . implode(', ', $bits) . '. ' : 'The new class is empty. ')
                    . ($c['lectures'] ? 'Open Lectures to review and publish the copied lectures. ' : '')
                    . ($r['skipped_files'] ? $r['skipped_files'] . ' file(s) could not be copied and were left out. ' : '') . 'New join code: ' . $r['code'] . '.']);
                redirect(APP_URL . '/pages/classes/stream.php?id=' . $r['class_id']);
            } catch (Throwable $e) {
                error_log('SmartClass: copy class failed: ' . $e->getMessage());
                $task_result = ['error', 'Not copied', 'Something went wrong; nothing was created.'];
            }
        }
    }
}

$page_title = 'Copy class';
$page_subtitle = $source['class_name'];
$active_menu = 'classes';
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_script .= "
    const cf = document.getElementById('copyForm');
    if (cf) { bindLiveClear(cf); cf.addEventListener('submit', function (e) {
        const n = document.getElementById('class_name');
        if (!n.value.trim()) { setFieldError(n, 'Give the new class a name'); e.preventDefault(); }
    }); }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<a class="back-link" href="<?php ob_start(); ?>edit.php?id=<?php echo $class_id; ?><?php echo htmlspecialchars(backUrl(ob_get_clean())); ?>"><i class="fas fa-arrow-left"></i> Back to class settings</a>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-copy"></i> Copy “<?php echo htmlspecialchars($source['class_name']); ?>”</h2></div>
    <div class="card-inner">
        <form method="POST" id="copyForm" novalidate>
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label for="class_name">Name of the new class <span class="required-mark">*</span></label>
                <input type="text" id="class_name" name="class_name" maxlength="100" value="<?php echo htmlspecialchars($name); ?>" class="<?php echo $name_error ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($name_error); ?></div>
            </div>
            <fieldset class="release-field" style="border:0;padding:0;margin:0 0 14px;">
                <legend style="font-size:.95rem;font-weight:600;">What to copy</legend>
                <?php foreach ($part_labels as $k => $label): ?>
                <label class="material-check" style="display:flex;gap:8px;align-items:center;margin:6px 0;">
                    <input type="checkbox" name="parts[]" value="<?php echo $k; ?>" <?php echo in_array($k, $picked, true) && $counts[$k] ? 'checked' : ''; ?> <?php echo $counts[$k] ? '' : 'disabled'; ?>>
                    <span><?php echo htmlspecialchars($label); ?> <span class="form-hint" style="display:inline;margin:0;">— <?php echo (int) $counts[$k]; ?> in this class</span></span>
                </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="form-hint">Always copied: description, tags, banner, colour, stream and joining rules, AI switch, topics. Never copied: students, join requests, submissions, grades, messages and the stream. The new class gets its own join code and you are its owner.</p>
            <button type="submit" class="btn-app"><i class="fas fa-copy"></i> Create the copy</button>
            <a href="edit.php?id=<?php echo $class_id; ?>" class="btn-app btn-outline">Cancel</a>
        </form>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
