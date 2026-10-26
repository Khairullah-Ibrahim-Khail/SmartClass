<?php
/**
 * Add / Edit Lecture (teachers of the class: owner or co-teacher)
 *   add.php?class_id=N   new lecture       add.php?id=N   edit (the class stays fixed)
 * Title, one-line summary, content (formatting toolbar + Write / Preview — same renderer
 * students see), teacher notes, video link, materials (class resources + links / videos),
 * and when students get it:
 *   publish   now — students are notified (once)
 *   schedule  at a date / time — hidden until then, notified then (publishDueLectures)
 *   draft     only teachers see it
 * Inline field errors; SweetAlert2 for the task result; CSRF + Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/lectures/view.php');
}

$classes = getUserClasses($current_user_id, 'teacher');

// Edit mode: the lecture must be in a class this teacher teaches
$editing = null;
if (!empty($_GET['id'])) {
    $editing = $db->fetchOne(
        "SELECT l.* FROM lectures l INNER JOIN classes c ON l.class_id = c.id
         WHERE l.id = ? AND " . teachesClassSql('c'), [(int) $_GET['id'], $current_user_id]);
    if (!$editing) {
        redirect(APP_URL . '/pages/lectures/view.php?error=not_found');
    }
}

$field_errors = ['class_id' => '', 'title' => '', 'summary' => '', 'content' => '', 'video_url' => '', 'publish_at' => ''];
$link_errors = [];
$task_result = null;
$is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
$val = fn($k, $default = '') => trim((string) ($is_post || !$editing ? ($_POST[$k] ?? $default) : ($editing[$k] ?? $default)));
$form = [
    'class_id'  => $editing ? (int) $editing['class_id'] : (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0),
    'title'     => $val('title'),
    'summary'   => $val('summary'),
    'content'   => $is_post || !$editing ? (string) ($_POST['content'] ?? '') : (string) $editing['content'],
    'notes'     => $val('notes'),
    'video_url' => $val('video_url'),
];
$form['content'] = rtrim(str_replace("\r\n", "\n", $form['content']));
$form_topic = $is_post ? (int) ($_POST['topic_id'] ?? 0) : (int) ($editing['topic_id'] ?? 0);
// When: publish | schedule | draft
if ($is_post) {
    $when = in_array($_POST['when'] ?? '', ['publish', 'schedule', 'draft'], true) ? $_POST['when'] : 'publish';
    $publish_at_value = trim((string) ($_POST['publish_at'] ?? ''));
} elseif ($editing) {
    $state = lectureState($editing);
    $when = $state === 'draft' ? 'draft' : ($state === 'scheduled' ? 'schedule' : 'publish');
    $publish_at_value = $editing['publish_at'] && $state === 'scheduled' ? date('Y-m-d\TH:i', strtotime($editing['publish_at'])) : '';
} else {
    $when = 'publish';
    $publish_at_value = '';
}
// Materials shown in the form
if ($is_post) {
    $form_links = [];
    foreach (array_slice((array) ($_POST['link_url'] ?? []), 0, MAX_ASSIGNMENT_LINKS) as $i => $u) {
        $form_links[] = ['url' => trim((string) $u), 'title' => trim((string) (((array) ($_POST['link_title'] ?? []))[$i] ?? ''))];
    }
    $form_resources = array_map('intval', (array) ($_POST['resource_ids'] ?? []));
} else {
    $saved = $editing ? getLectureMaterials($editing['id']) : [];
    $form_links = array_map(fn($m) => ['url' => $m['url'], 'title' => (string) $m['title']], array_values(array_filter($saved, fn($m) => $m['kind'] === 'link')));
    $form_resources = array_map('intval', array_column(array_filter($saved, fn($m) => $m['kind'] === 'resource'), 'resource_id'));
}
while (count($form_links) < 2) $form_links[] = ['url' => '', 'title' => ''];
$class_ids = array_map('intval', array_column($classes, 'id'));
$all_resources = [];
if ($class_ids) {
    $in = implode(',', array_fill(0, count($class_ids), '?'));
    $all_resources = $db->fetchAll("SELECT id, class_id, title, file_type FROM resources WHERE class_id IN ({$in}) ORDER BY title", $class_ids);
}

if ($is_post && !verifyCsrf()) {
    $task_result = ['error', 'Session expired', 'Please submit the form again — your text is still here.'];
} elseif ($is_post) {
    $class = getAccessibleClass($form['class_id'], $current_user_id, 'teacher');
    if (!$class) $field_errors['class_id'] = 'Please select one of your classes';

    if ($form['title'] === '') $field_errors['title'] = 'Lecture title is required';
    elseif (mb_strlen($form['title']) > 200) $field_errors['title'] = 'Title must be under 200 characters';
    if (mb_strlen($form['summary']) > 300) $field_errors['summary'] = 'Summary must be under 300 characters';
    if (trim($form['content']) === '') $field_errors['content'] = 'Lecture content is required';
    elseif (mb_strlen($form['content']) > 60000) $field_errors['content'] = 'Lecture content is too long (max 60,000 characters)';
    if ($form['video_url'] !== '') {
        if (!filter_var($form['video_url'], FILTER_VALIDATE_URL) || !isHttpUrl($form['video_url'])) $field_errors['video_url'] = 'Enter a full video link starting with http:// or https://';
        elseif (strlen($form['video_url']) > 500) $field_errors['video_url'] = 'Video link must be under 500 characters';
    }
    $publish_at = null;
    if ($when === 'schedule') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $publish_at_value);
        if ($publish_at_value === '') $field_errors['publish_at'] = 'Choose when students should see it';
        elseif (!$dt || $dt->format('Y-m-d\TH:i') !== $publish_at_value) $field_errors['publish_at'] = 'Please enter a valid date and time';
        elseif ($dt->getTimestamp() <= time() + 60) $field_errors['publish_at'] = 'Pick a time in the future (or choose Publish now)';
        elseif ($dt->getTimestamp() > strtotime('+1 year')) $field_errors['publish_at'] = 'You can schedule up to one year ahead';
        else $publish_at = $dt->format('Y-m-d H:i:00');
    }
    [$mat_links, $mat_resources, $link_errors] = validateAssignmentMaterials($class['id'] ?? 0, $_POST['link_url'] ?? [], $_POST['link_title'] ?? [], $_POST['resource_ids'] ?? []);

    if (!array_filter($field_errors) && !$link_errors) {
        $data = [
            'topic_id'   => validTopicId($_POST['topic_id'] ?? 0, $class['id']),
            'title'      => $form['title'],
            'summary'    => $form['summary'] !== '' ? $form['summary'] : null,
            'content'    => $form['content'],
            'notes'      => $form['notes'] !== '' ? $form['notes'] : null,
            'video_url'  => $form['video_url'] !== '' ? $form['video_url'] : null,
            'status'     => $when === 'draft' ? 'draft' : 'published',
            'publish_at' => $when === 'schedule' ? $publish_at : ($when === 'publish' && $editing && $editing['publish_at'] && lectureState($editing) === 'published' ? $editing['publish_at'] : null),
        ];
        if ($editing) {
            $db->update('lectures', $data, 'id = ?', [$editing['id']]);
            $lecture_id = (int) $editing['id'];
            logActivity('edit_lecture', 'lectures', "Edited lecture #{$lecture_id} \"{$form['title']}\" in {$class['class_name']} ({$when})");
        } else {
            $lecture_id = (int) $db->insert('lectures', $data + ['class_id' => $class['id'], 'teacher_id' => $current_user_id]);
            logActivity('add_lecture', 'lectures', "Added lecture #{$lecture_id} \"{$form['title']}\" to {$class['class_name']} ({$when})");
        }
        saveLectureMaterials($lecture_id, $mat_links, $mat_resources);
        // Publish now → notify the class now (once — a lecture that was already announced is not announced again)
        $notified = publishDueLectures($class['id']);
        Session::flash('lecture_msg', $when === 'draft'
            ? ['success', 'Draft saved', 'Only teachers can see it. Publish it when it is ready.']
            : ($when === 'schedule'
                ? ['success', 'Lecture scheduled', 'Students will see it on ' . date('M d, Y \a\t h:i A', strtotime($publish_at)) . ' and be notified then.']
                : ['success', $editing ? 'Lecture saved' : 'Lecture published', $notified ? 'Students in this class have been notified.' : 'Your changes are live.']));
        redirect(APP_URL . '/pages/lectures/view.php?id=' . $lecture_id);
    }
    $task_result = $task_result ?? ['error', 'Not saved yet', 'Fix the highlighted fields — your text is still here.'];
}

$page_title = $editing ? 'Edit Lecture' : 'New Lecture';
$page_subtitle = $editing ? 'Edit the lecture — students see changes right away if it is published' : 'Write, format and publish a lecture — or save it as a draft';
$active_menu = 'lectures';
$page_scripts = ['assets/js/lecture-editor.js'];
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    lectureEditorInit(" . json_encode(['draftKey' => 'sc-lecture-draft-' . ($editing ? 'edit-' . (int) $editing['id'] : 'new-' . (int) $current_user_id),
                                       'saved' => $editing && !$is_post ? substr(hash('sha256', $form['content']), 0, 16) : null,
                                       'clearDraft' => false]) . ");";

$can_ai_write = aiAvailableFor('writing', $current_user_id);
if ($can_ai_write) $page_scripts[] = 'assets/js/ai-assist.js';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Lectures', 'view.php'); ?>

<?php if (empty($classes)): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Create a class first, then add lectures to it.</p>
    <div style="margin-top:16px;"><a href="<?php echo APP_URL; ?>/pages/classes/create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a></div>
</div>
<?php else: ?>
<form method="POST" id="lectureForm" class="lec-editor-layout" novalidate>
    <?php echo csrfField(); ?>
    <section class="form-card lec-editor-main" style="max-width:none;">
        <div class="form-group">
            <label for="title">Title <span class="required-mark">*</span></label>
            <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Lists and loops in Python"
                class="lec-title-input <?php echo $field_errors['title'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['title']); ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['title']); ?></div>
        </div>
        <div class="form-group">
            <label for="summary">Summary <span class="form-hint" style="display:inline;margin:0;">one line — shown in the lecture list</span></label>
            <input type="text" id="summary" name="summary" maxlength="300" placeholder="What will students learn?"
                class="<?php echo $field_errors['summary'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['summary']); ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['summary']); ?></div>
        </div>

        <div class="form-group lec-editor">
            <div class="lec-editor-top">
                <label for="content">Content <span class="required-mark">*</span></label>
                <div class="lec-tabs" role="tablist">
                    <button type="button" role="tab" class="active" data-tab="write" aria-selected="true"><i class="fas fa-pen"></i> Write</button>
                    <button type="button" role="tab" data-tab="preview" aria-selected="false"><i class="fas fa-eye"></i> Preview</button>
                </div>
            </div>
            <div class="lec-toolbar" role="toolbar" aria-label="Formatting">
                <button type="button" data-md="h2" title="Section heading">H2</button>
                <button type="button" data-md="h3" title="Sub-heading">H3</button>
                <span class="sep"></span>
                <button type="button" data-md="bold" title="Bold (Ctrl+B)"><i class="fas fa-bold"></i></button>
                <button type="button" data-md="italic" title="Italic (Ctrl+I)"><i class="fas fa-italic"></i></button>
                <button type="button" data-md="code" title="Code in a sentence"><i class="fas fa-terminal"></i></button>
                <div class="lec-color">
                    <button type="button" class="lec-color-btn" aria-haspopup="true" aria-expanded="false" title="Text colour / highlight"><i class="fas fa-palette"></i></button>
                    <div class="lec-color-menu" role="menu" hidden>
                        <?php foreach (lectureColors() as $ck => $cl): ?>
                        <button type="button" role="menuitem" data-color="<?php echo $ck; ?>" title="<?php echo $cl; ?>"><span class="lc-<?php echo $ck; ?>"><?php echo $ck === 'mark' ? 'Highlight' : 'Aa'; ?></span> <?php echo $ck === 'mark' ? '' : $cl; ?></button>
                        <?php endforeach; ?>
                        <button type="button" role="menuitem" data-color="" class="lec-color-none"><i class="fas fa-eraser"></i> No colour</button>
                    </div>
                </div>
                <button type="button" data-md="link" title="Link (Ctrl+K)"><i class="fas fa-link"></i></button>
                <span class="sep"></span>
                <button type="button" data-md="ul" title="Bullet list"><i class="fas fa-list-ul"></i></button>
                <button type="button" data-md="ol" title="Numbered list"><i class="fas fa-list-ol"></i></button>
                <button type="button" data-md="quote" title="Quote / tip"><i class="fas fa-quote-right"></i></button>
                <button type="button" data-md="hr" title="Divider line"><i class="fas fa-minus"></i></button>
                <span class="sep"></span>
                <button type="button" id="imgToggle" class="wide" title="Insert an image (upload, drag & drop or paste)" aria-expanded="false" aria-controls="imgPanel"><i class="fas fa-image"></i> Image</button>
                <button type="button" data-md="codeblock" class="wide" title="Code block (students can ask the AI to explain it)"><i class="fas fa-code"></i> Code block</button>
 <button type="button" data-md="diagram" class="wide" title="Diagram — a flowchart or other picture drawn from a few lines of text"><i class="fas fa-diagram-project"></i> Diagram</button>
                <button type="button" data-md="chart" class="wide" title="Chart — a bar, line or pie chart drawn from numbers you write"><i class="fas fa-chart-column"></i> Chart</button>
                <button type="button" data-md="outblock" class="wide" title="Output panel — write what the code above prints (students also get a Run button on Python / JavaScript blocks)"><i class="fas fa-terminal"></i> Output</button>
                <select id="codeLang" aria-label="Language of the code block" title="Language of the code block">
                    <?php foreach (['python', 'javascript', 'php', 'java', 'c', 'cpp', 'csharp', 'html', 'css', 'sql', 'bash', 'text'] as $lang): ?>
                    <option value="<?php echo $lang; ?>"><?php echo $lang; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lec-imgpanel" id="imgPanel" hidden>
                <div class="lec-drop" id="imgDrop">
                    <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                    <span>Drop an image here, paste one into the text (Ctrl+V), or</span>
                    <button type="button" class="btn-app btn-sm btn-outline" id="imgPick"><i class="fas fa-folder-open"></i> Choose a file</button>
                    <input type="file" id="imgFile" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                    <small class="form-hint" style="margin:0;">JPG, PNG, WEBP or GIF · up to 8 MB · large pictures are resized automatically</small>
                </div>
                <p class="lec-img-error" id="imgError" role="alert" hidden></p>
                <div class="lec-imglib-head"><strong>Images in this class</strong><span class="form-hint" style="margin:0;">Click one to insert it where the cursor is</span></div>
                <div class="lec-imglib" id="imgLib"><p class="form-hint">Choose the class first.</p></div>
            </div>
            <textarea id="content" name="content" rows="20" spellcheck="true"
                placeholder="## Introduction&#10;Write the lecture here. Use the toolbar for headings, lists and code blocks.&#10;&#10;```python&#10;print('Students can ask the AI to explain code blocks')&#10;```"
                class="<?php echo $field_errors['content'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['content']); ?></textarea>
            <div class="lec-preview lec-body" id="lecPreview" hidden aria-live="polite"></div>
            <p class="form-hint lec-preview-hint" id="previewHint" hidden><i class="fas fa-hand-pointer"></i> In Preview: drag a picture to move it (drop left, centre or right of the text), drag its corner handles to resize it, and use the buttons above it to align, re-caption or remove it.</p>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['content']); ?></div>
            <?php if ($can_ai_write): ?>
            <div class="ai-assist-row"><button type="button" class="btn-app btn-sm btn-outline" data-ai-task="lecture" data-target="#content" data-class-source="#class_id" data-title-source="#title" data-summary-source="#summary" data-prompt="Outline or notes for the AI (what should the lecture cover?)"><i class="fas fa-robot"></i> Draft lecture with AI</button></div>
            <?php endif; ?>
            <div class="lec-editor-foot">
                <span id="lecStats" class="form-hint" style="margin:0;"></span>
                <span id="lecDraftNote" class="form-hint" style="margin:0;" hidden></span>
            </div>
        </div>

        <div class="form-group">
            <label for="notes">Teacher notes <span class="form-hint" style="display:inline;margin:0;">optional — shown under the lecture (homework, reading list…)</span></label>
            <textarea id="notes" name="notes" rows="3"><?php echo htmlspecialchars($form['notes']); ?></textarea>
        </div>
    </section>

    <aside class="lec-editor-side">
        <div class="form-card" style="max-width:none;">
            <div class="form-group">
                <label for="class_id">Class <span class="required-mark">*</span></label>
                <select id="class_id" name="class_id" <?php echo $editing ? 'disabled' : ''; ?> class="<?php echo $field_errors['class_id'] ? 'is-invalid' : ''; ?>">
                    <option value="">Select a class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_id']); ?></div>
            </div>
            <?php if ($editing): ?><input type="hidden" name="class_id" value="<?php echo (int) $editing['class_id']; ?>"><?php endif; ?>
            <?php echo topicSelectField($classes, $form_topic); ?>

            <fieldset class="lec-when">
                <legend>When do students get it?</legend>
                <label class="switch-row"><input type="radio" name="when" value="publish" <?php echo $when === 'publish' ? 'checked' : ''; ?>>
                    <span><strong>Publish now</strong><small class="form-hint" style="display:block;margin:0;">Students see it and are notified</small></span></label>
                <label class="switch-row"><input type="radio" name="when" value="schedule" <?php echo $when === 'schedule' ? 'checked' : ''; ?>>
                    <span><strong>Schedule</strong><small class="form-hint" style="display:block;margin:0;">Hidden until the time you choose</small></span></label>
                <div class="form-group lec-when-time" id="whenTime" <?php echo $when === 'schedule' ? '' : 'hidden'; ?>>
                    <input type="datetime-local" id="publish_at" name="publish_at" aria-label="Publish on" value="<?php echo htmlspecialchars($publish_at_value); ?>"
                        class="<?php echo $field_errors['publish_at'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['publish_at']); ?></div>
                </div>
                <label class="switch-row"><input type="radio" name="when" value="draft" <?php echo $when === 'draft' ? 'checked' : ''; ?>>
                    <span><strong>Save as draft</strong><small class="form-hint" style="display:block;margin:0;">Only teachers see it</small></span></label>
            </fieldset>

            <div class="form-actions" style="margin-top:14px;">
                <button type="submit" class="btn-app" id="saveBtn"><i class="fas fa-paper-plane"></i> <span>Publish</span></button>
                <a href="<?php echo APP_URL; ?>/pages/lectures/view.php<?php echo $editing ? '?id=' . (int) $editing['id'] : ''; ?>" class="btn-app btn-outline">Cancel</a>
            </div>
        </div>

        <div class="form-card" style="max-width:none;">
            <div class="form-group">
                <label for="video_url">Main video <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
                <input type="url" id="video_url" name="video_url" maxlength="500" placeholder="https://www.youtube.com/watch?v=…"
                    class="<?php echo $field_errors['video_url'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['video_url']); ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['video_url']); ?></div>
                <small class="form-hint">YouTube videos play inside the lecture.</small>
            </div>

            <fieldset class="materials-field" style="margin:0;">
                <legend>Materials</legend>
                <div class="form-group" id="materialResources">
                    <label>Class files</label>
                    <div class="material-checks">
                        <?php foreach ($all_resources as $r): ?>
                        <label class="material-check" data-class="<?php echo (int) $r['class_id']; ?>">
                            <input type="checkbox" name="resource_ids[]" value="<?php echo (int) $r['id']; ?>" <?php echo in_array((int) $r['id'], $form_resources, true) ? 'checked' : ''; ?>>
                            <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($r['title']); ?>
                            <span class="notif-meta"><?php echo htmlspecialchars(strtoupper((string) $r['file_type'])); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="form-hint" id="noClassResources">No files in this class yet — upload them under Resources.</small>
                </div>
                <label>Links and videos</label>
                <?php foreach ($form_links as $i => $l): ?>
                <div class="form-group material-link">
                    <div class="material-link-row" style="grid-template-columns:1fr;">
                        <input type="url" name="link_url[]" maxlength="500" placeholder="https://…" aria-label="Link <?php echo $i + 1; ?> address"
                            class="<?php echo isset($link_errors[$i]) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($l['url']); ?>">
                        <input type="text" name="link_title[]" maxlength="200" placeholder="Title (optional)" aria-label="Link <?php echo $i + 1; ?> title" value="<?php echo htmlspecialchars($l['title']); ?>">
                    </div>
                    <div class="invalid-feedback"><?php echo htmlspecialchars($link_errors[$i] ?? ''); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (count($form_links) < MAX_ASSIGNMENT_LINKS): ?>
                <button type="button" class="link-btn" id="addLinkRow" data-max="<?php echo MAX_ASSIGNMENT_LINKS; ?>"><i class="fas fa-plus"></i> Add another link</button>
                <?php endif; ?>
                <small class="form-hint" style="display:block;margin-top:6px;">YouTube links become videos in the lecture.</small>
            </fieldset>
        </div>
    </aside>
</form>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
