<?php
/**
 * Create Class (teacher only) — per designs/pages/create/createClass.html
 * Inline field errors; SweetAlert2 for the task result.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isTeacher()) {
    redirect(APP_URL . '/pages/classes/my-classes.php');
}

$field_errors = ['class_name' => '', 'description' => '', 'image' => ''];
$created = null;

$csrf_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $csrf_error = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Stored raw; every page escapes on output
    $class_name  = trim($_POST['class_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tags        = trim($_POST['tags'] ?? '');
    $image       = trim($_POST['image'] ?? '');

    if ($class_name === '') {
        $field_errors['class_name'] = 'Class name is required';
    } elseif (mb_strlen($class_name) > 100) {
        $field_errors['class_name'] = 'Class name must be under 100 characters';
    }

    if ($description === '') {
        $field_errors['description'] = 'Description is required';
    }

    if ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http', 'https'], true))) {
        $field_errors['image'] = 'Please enter a valid image URL (or leave it empty)';
    }

    if (!array_filter($field_errors) && ($limit_msg = limitReached('classes'))) {
        $field_errors['class_name'] = $limit_msg;
    }

    if (!array_filter($field_errors)) {
        // Generate a unique class code
        do {
            $class_code = generateClassCode(8);
        } while ($db->exists('classes', 'class_code', $class_code));

        $class_id = $db->insert('classes', [
            'teacher_id'  => $current_user_id,
            'class_name'  => $class_name,
            'class_code'  => $class_code,
            'description' => $description,
            'tags'        => $tags !== '' ? $tags : null,
            'image'       => $image !== '' ? $image : null,
            'year_id'     => currentAcademicYearId(),
        ]);

        addClassOwner($class_id, $current_user_id);
        logActivity('create_class', 'classes', "Created class {$class_name} ({$class_code})");

        $created = ['id' => $class_id, 'code' => $class_code];
    }
}

$page_title = 'Create New Class';
$page_subtitle = 'Create a class and share its code with your students';
$active_menu = 'classes';

if ($created) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        title: 'Class Created!',
        html: 'Share this code with your students:<br><h2 style=\"letter-spacing:3px;color:#4361ee;margin-top:10px\">{$created['code']}</h2>',
        confirmButtonText: 'Open Class',
        confirmButtonColor: '#4361ee',
        showCancelButton: true,
        cancelButtonText: 'My Classes'
    }).then(function(r) {
        window.location.href = r.isConfirmed
            ? '" . APP_URL . "/pages/classes/stream.php?id={$created['id']}'
            : '" . APP_URL . "/pages/classes/my-classes.php';
    });";
} else {
    $page_script = ($csrf_error ? "
    Swal.fire({ icon: 'error', title: 'Session expired',
                text: 'Please submit the form again.', confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('createClassForm');
    bindLiveClear(form);
    form.addEventListener('submit', function(e) {
        let ok = true;
        const name = document.getElementById('class_name');
        const desc = document.getElementById('description');
        const img  = document.getElementById('image');
        if (!name.value.trim()) { setFieldError(name, 'Class name is required'); ok = false; }
        if (!desc.value.trim()) { setFieldError(desc, 'Description is required'); ok = false; }
        if (img.value.trim() && !/^https?:\\/\\/.+/.test(img.value.trim())) {
            setFieldError(img, 'Please enter a valid image URL (or leave it empty)'); ok = false;
        }
        if (!ok) e.preventDefault();
    });";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('My Classes', 'my-classes.php'); ?>

<div class="form-card">
    <form method="POST" id="createClassForm" novalidate>
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="class_name">Class Name</label>
            <input type="text" id="class_name" name="class_name" placeholder="Enter class name"
                class="<?php echo $field_errors['class_name'] ? 'is-invalid' : ''; ?>"
                value="<?php echo sanitize($_POST['class_name'] ?? ''); ?>">
            <div class="invalid-feedback"><?php echo $field_errors['class_name']; ?></div>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <?php echo aiWriteButton('class', '#description', ['title' => '#class_name', 'use' => true, 'ctx' => ['tags' => '#tags']]); ?>
            <textarea id="description" name="description" placeholder="Enter class description"
                class="<?php echo $field_errors['description'] ? 'is-invalid' : ''; ?>"><?php echo sanitize($_POST['description'] ?? ''); ?></textarea>
            <div class="invalid-feedback"><?php echo $field_errors['description']; ?></div>
        </div>

        <div class="form-group">
            <label for="tags">Category Tags (comma separated)</label>
            <input type="text" id="tags" name="tags" placeholder="e.g. Beginner,Python"
                value="<?php echo sanitize($_POST['tags'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="image">Class Image URL (optional)</label>
            <input type="text" id="image" name="image" placeholder="https://example.com/image.jpg"
                class="<?php echo $field_errors['image'] ? 'is-invalid' : ''; ?>"
                value="<?php echo sanitize($_POST['image'] ?? ''); ?>">
            <div class="invalid-feedback"><?php echo $field_errors['image']; ?></div>
        </div>

        <button type="submit" class="btn-app">
            <i class="fas fa-plus-circle"></i> Create Class
        </button>
    </form>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
