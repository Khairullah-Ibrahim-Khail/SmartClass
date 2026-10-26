<?php
/**
 * Upload Resource (teacher only) — per designs/pages/resources/createResources.html
 * Title, description, category + one file (drag & drop) for one of the teacher's classes.
 * File type is checked by extension AND detected content type, size is capped,
 * and the file is stored under uploads/resources/ with a random name (never the
 * user's filename). Students download through pages/resources/download.php.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/resources/view.php');
}

$classes = getUserClasses($current_user_id, 'teacher');
$categories = resourceCategories();
$max_bytes = maxUploadBytes();
$allowed_ext = array_keys(allowedUploadTypes());

$field_errors = ['class_id' => '', 'title' => '', 'description' => '', 'category' => '', 'file' => ''];
$task_error = '';
$created = null;

$form = [
    'class_id'    => (int) ($_POST['class_id'] ?? $_GET['class_id'] ?? 0),
    'title'       => trim($_POST['title'] ?? ''),
    'description' => trim($_POST['description'] ?? ''),
    'category'    => trim($_POST['category'] ?? ''),
];

if (postBodyTooLarge()) {
    $field_errors['file'] = 'File is too large (max ' . formatBytes($max_bytes) . ')';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $task_error = 'Your session expired. Please submit the form again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Server-side ownership check: the class must belong to this teacher
    $class = getAccessibleClass($form['class_id'], $current_user_id, 'teacher');
    if (!$class) {
        $field_errors['class_id'] = 'Please select one of your classes';
    }

    if ($form['title'] === '') {
        $field_errors['title'] = 'Title is required';
    } elseif (mb_strlen($form['title']) > 200) {
        $field_errors['title'] = 'Title must be under 200 characters';
    }

    if ($form['description'] === '') {
        $field_errors['description'] = 'Description is required';
    } elseif (mb_strlen($form['description']) > 2000) {
        $field_errors['description'] = 'Description must be under 2,000 characters';
    }

    if (!isset($categories[$form['category']])) {
        $field_errors['category'] = 'Please select a category';
    }

    // Store the file only once every other field is valid (no orphan files)
    if (!array_filter($field_errors)) {
        $upload = storeUpload($_FILES['file'] ?? null, 'resources');
        if (!$upload['ok']) {
            $field_errors['file'] = $upload['error'];
        }
    }

    if (!array_filter($field_errors)) {
        $resource_id = $db->insert('resources', [
            'topic_id'    => validTopicId($_POST['topic_id'] ?? 0, $class['id']),
            'class_id'      => $class['id'],
            'uploaded_by'   => $current_user_id,
            'title'         => $form['title'],
            'description'   => $form['description'],
            'category'      => $form['category'],
            'file_path'     => $upload['path'],
            'original_name' => $upload['original_name'],
            'file_type'     => $upload['ext'],
            'file_size'     => $upload['size'],
        ]);

        logActivity('upload_resource', 'resources', "Uploaded resource #{$resource_id} \"{$form['title']}\" ({$upload['original_name']}) to {$class['class_name']}");
        notifyClassStudents(
            $class['id'],
            'resource_added',
            'New resource: ' . $form['title'],
            "A new study resource was shared in {$class['class_name']}.",
            'pages/resources/view.php?class_id=' . $class['id'],
            $current_user_id
        );

        $created = ['class_id' => $class['id']];
    }
}

$page_title = 'Upload Resource';
$page_subtitle = 'Share a study file with one of your classes';
$active_menu = 'resources';

if ($created) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        title: 'Resource Uploaded!',
        text: 'Students in this class have been notified.',
        confirmButtonText: 'View Resources',
        confirmButtonColor: '#4361ee',
        showCancelButton: true,
        cancelButtonText: 'Upload Another'
    }).then(function(r) {
        window.location.href = r.isConfirmed
            ? '" . APP_URL . "/pages/resources/view.php?class_id={$created['class_id']}'
            : '" . APP_URL . "/pages/resources/upload.php?class_id={$created['class_id']}';
    });";
} else {
    $page_script = ($task_error ? "
    Swal.fire({ icon: 'error', title: 'Upload failed', text: " . json_encode($task_error) . ",
                confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('resourceForm');
    if (form) {
        const MAX = {$max_bytes};
        const ALLOWED = " . json_encode($allowed_ext) . ";
        const zone = document.getElementById('uploadZone');
        const input = document.getElementById('file');
        const preview = document.getElementById('filePreview');
        bindLiveClear(form);

        function fmt(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB'; }
        function zoneError(msg) { zone.classList.add('is-invalid'); document.getElementById('fileError').textContent = msg; document.getElementById('fileError').classList.add('show'); }
        function zoneClear() { zone.classList.remove('is-invalid'); document.getElementById('fileError').classList.remove('show'); }
        function showFile() {
            zoneClear();
            const f = input.files[0];
            if (!f) { preview.classList.remove('show'); return; }
            document.getElementById('fileName').textContent = f.name;
            document.getElementById('fileSize').textContent = fmt(f.size);
            preview.classList.add('show');
        }
        function checkFile() {
            const f = input.files[0];
            if (!f) { zoneError('Please choose a file'); return false; }
            const ext = f.name.split('.').pop().toLowerCase();
            if (ALLOWED.indexOf(ext) === -1) { zoneError('File type not allowed. Allowed: ' + ALLOWED.join(', ').toUpperCase()); return false; }
            if (f.size > MAX) { zoneError('File is too large (max ' + fmt(MAX) + ')'); return false; }
            return true;
        }

        zone.addEventListener('click', function() { input.click(); });
        zone.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
        input.addEventListener('change', showFile);
        ['dragenter', 'dragover'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) { e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) { e.preventDefault(); zone.classList.remove('dragover'); });
        });
        zone.addEventListener('drop', function(e) {
            if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFile(); }
        });
        document.getElementById('removeFile').addEventListener('click', function() { input.value = ''; showFile(); });

        form.addEventListener('submit', function(e) {
            let ok = true;
            const cls = document.getElementById('class_id');
            const title = document.getElementById('title');
            const desc = document.getElementById('description');
            const cat = document.getElementById('category');
            if (!cls.value) { setFieldError(cls, 'Please select one of your classes'); ok = false; }
            if (!title.value.trim()) { setFieldError(title, 'Title is required'); ok = false; }
            if (!desc.value.trim()) { setFieldError(desc, 'Description is required'); ok = false; }
            if (!cat.value) { setFieldError(cat, 'Please select a category'); ok = false; }
            if (!checkFile()) ok = false;
            if (!ok) e.preventDefault();
        });
    }";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('Resources', 'view.php'); ?>

<?php if (empty($classes)): ?>
<div class="empty-state">
    <i class="fas fa-chalkboard"></i>
    <h3>You have no classes yet</h3>
    <p style="margin-top:6px;">Create a class first, then share resources with it.</p>
    <div style="margin-top:16px;">
        <a href="<?php echo APP_URL; ?>/pages/classes/create.php" class="btn-app"><i class="fas fa-plus"></i> Create Class</a>
    </div>
</div>
<?php else: ?>
<div class="form-card">
    <form method="POST" id="resourceForm" enctype="multipart/form-data" novalidate>
        <?php echo csrfField(); ?>

        <div class="form-row">
            <div class="form-group">
                <label for="class_id">Class <span class="required-mark">*</span></label>
                <select id="class_id" name="class_id"
                    class="<?php echo $field_errors['class_id'] ? 'is-invalid' : ''; ?>">
                    <option value="">Select a class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $form['class_id'] === (int) $c['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c['class_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['class_id']); ?></div>
            </div>

            <div class="form-group">
                <label for="category">Category <span class="required-mark">*</span></label>
                <select id="category" name="category"
                    class="<?php echo $field_errors['category'] ? 'is-invalid' : ''; ?>">
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $form['category'] === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['category']); ?></div>
            </div>
        </div>

        <?php echo topicSelectField($classes, (int) ($_POST['topic_id'] ?? 0)); ?>

        <div class="form-group">
            <label for="title">Resource Title <span class="required-mark">*</span></label>
            <input type="text" id="title" name="title" maxlength="200" placeholder="e.g. Week 1 slides"
                class="<?php echo $field_errors['title'] ? 'is-invalid' : ''; ?>"
                value="<?php echo htmlspecialchars($form['title']); ?>">
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['title']); ?></div>
        </div>

        <div class="form-group">
            <label for="description">Description <span class="required-mark">*</span></label>
            <?php echo aiWriteButton('resource', '#description', ['class' => '#class_id', 'title' => '#title', 'use' => true, 'ctx' => ['category' => '#category']]); ?>
            <textarea id="description" name="description" rows="3" maxlength="2000"
                placeholder="What is this file and how should students use it?"
                class="<?php echo $field_errors['description'] ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($form['description']); ?></textarea>
            <div class="invalid-feedback"><?php echo htmlspecialchars($field_errors['description']); ?></div>
        </div>

        <div class="form-group">
            <label for="file">File <span class="required-mark">*</span></label>
            <div class="upload-zone <?php echo $field_errors['file'] ? 'is-invalid' : ''; ?>" id="uploadZone" tabindex="0" role="button"
                aria-label="Choose a file to upload">
                <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                <h3>Drag &amp; Drop Your File</h3>
                <p>or click to browse files</p>
                <p class="upload-info">
                    Supported formats: <?php echo strtoupper(implode(', ', $allowed_ext)); ?><br>
                    Maximum file size: <?php echo formatBytes($max_bytes); ?>
                </p>
            </div>
            <input type="file" id="file" name="file" hidden
                accept="<?php echo '.' . implode(',.', $allowed_ext); ?>">
            <div class="file-preview" id="filePreview">
                <div>
                    <div class="file-name" id="fileName"></div>
                    <div class="file-size" id="fileSize"></div>
                </div>
                <button type="button" class="icon-btn danger" id="removeFile" title="Remove file" aria-label="Remove file">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="invalid-feedback <?php echo $field_errors['file'] ? 'show' : ''; ?>" id="fileError"><?php echo htmlspecialchars($field_errors['file']); ?></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-app">
                <i class="fas fa-upload"></i> Upload Resource
            </button>
            <a href="<?php echo APP_URL; ?>/pages/resources/view.php" class="btn-app btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
