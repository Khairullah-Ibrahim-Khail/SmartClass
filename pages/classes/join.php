<?php
/**
 * Join Class by code (student only)
 * Live class preview while typing; inline errors; SweetAlert2 result.
 * Classes that require approval (the default) turn the code into a join request
 * the class teacher approves on the People page; other classes join instantly.
 * Logic lives in requestToJoinClass() (includes/classes.php).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!isStudent()) {
    redirect(APP_URL . '/pages/classes/my-classes.php');
}

$field_errors = ['class_code' => ''];
$joined = null;

$csrf_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $csrf_error = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_code = strtoupper(trim($_POST['class_code'] ?? ''));

    if ($class_code === '') {
        $field_errors['class_code'] = 'Class code is required';
    } else {
        $class = $db->fetchOne(
            "SELECT c.*, u.name AS teacher_name FROM classes c
             INNER JOIN users u ON c.teacher_id = u.id
             WHERE c.class_code = ? AND c.is_active = 1",
            [$class_code]
        );

        if (!$class) {
            $field_errors['class_code'] = 'Invalid class code — no class found';
        } else {
            $result = requestToJoinClass($class, $current_user_id, $current_user_name);
            if ($result === 'member') {
                $field_errors['class_code'] = 'You have already joined this class';
            } elseif ($result === 'pending') {
                $field_errors['class_code'] = 'Your request to join this class is already waiting for the teacher';
            } else {
                $joined = $class + ['result' => $result];
            }
        }
    }
}

$page_title = 'Join Class';
$page_subtitle = 'Enter the class code your teacher shared with you';
$active_menu = 'classes';

if ($joined && $joined['result'] === 'requested') {
    $page_script = "
    Swal.fire({
        icon: 'info',
        titleText: 'Request sent',
        text: " . json_encode('You will get a notification as soon as your teacher approves you for ' . $joined['class_name'] . '.') . ",
        confirmButtonText: 'My Classes',
        confirmButtonColor: '#4361ee'
    }).then(function() {
        window.location.href = '" . APP_URL . "/pages/classes/my-classes.php';
    });";
} elseif ($joined) {
    $page_script = "
    Swal.fire({
        icon: 'success',
        titleText: " . json_encode('Welcome to ' . $joined['class_name'] . '!') . ",
        text: 'You have joined the class successfully.',
        confirmButtonText: 'Open Class',
        confirmButtonColor: '#4361ee'
    }).then(function() {
        window.location.href = '" . APP_URL . "/pages/classes/stream.php?id={$joined['id']}';
    });";
} else {
    $page_script = ($csrf_error ? "
    Swal.fire({ icon: 'error', title: 'Session expired',
                text: 'Please submit the form again.', confirmButtonColor: '#4361ee' });" : "") . "
    const form = document.getElementById('joinForm');
    const codeInput = document.getElementById('class_code');
    const preview = document.getElementById('classPreview');
    bindLiveClear(form);

    form.addEventListener('submit', function(e) {
        if (!codeInput.value.trim()) {
            setFieldError(codeInput, 'Class code is required');
            e.preventDefault();
        }
    });

    // Live class preview while typing (real lookup, debounced)
    let t;
    codeInput.addEventListener('input', function() {
        clearTimeout(t);
        const code = codeInput.value.trim().toUpperCase();
        if (code.length < 4) { preview.style.display = 'none'; return; }
        t = setTimeout(function() {
            fetch('" . APP_URL . "/ajax/handler.php?action=checkClassCode&code=' + encodeURIComponent(code), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Names are user data: insert as text, never as HTML
                    preview.innerHTML = '<i class=\"fas fa-check-circle\" style=\"color:#06d6a0\"></i> <strong></strong>';
                    preview.querySelector('strong').textContent = data.data.class_name;
                    preview.style.display = 'block';
                } else {
                    preview.innerHTML = '<i class=\"fas fa-times-circle\" style=\"color:#ef476f\"></i> No class found with this code';
                    preview.style.display = 'block';
                }
            })
            .catch(function() { preview.style.display = 'none'; });
        }, 400);
    });
    // Arrived from an invite link (?code=...): show the class right away
    if (codeInput.value.trim()) codeInput.dispatchEvent(new Event('input'));";
}

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php echo backLink('My Classes', 'my-classes.php'); ?>

<div class="form-card" style="max-width: 520px;">
    <form method="POST" id="joinForm" novalidate>
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="class_code">Class Code</label>
            <input type="text" id="class_code" name="class_code" placeholder="e.g. PYTHON2024"
                style="text-transform: uppercase; letter-spacing: 2px; font-weight: 700;"
                class="<?php echo $field_errors['class_code'] ? 'is-invalid' : ''; ?>"
                value="<?php echo htmlspecialchars(strtoupper(trim($_POST['class_code'] ?? $_GET['code'] ?? ''))); ?>">
            <div class="invalid-feedback"><?php echo $field_errors['class_code']; ?></div>
            <div id="classPreview"
                style="display:none; margin-top:10px; padding:10px 14px; background:var(--gray-100); border-radius:8px; font-size:14px;">
            </div>
        </div>

        <button type="submit" class="btn-app">
            <i class="fas fa-sign-in-alt"></i> Join Class
        </button>
    </form>
</div>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
