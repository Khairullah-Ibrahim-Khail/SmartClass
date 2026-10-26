<?php
/**
 * Settings › Profile — your name, e-mail and photo.
 *   POST save_details   name + e-mail (e-mail stays unique)
 *   POST upload_photo   JPG / PNG / WEBP, content-checked, max 2 MB, random file name
 *   POST remove_photo
 * Inline field errors; SweetAlert2 for task results; CSRF; Post/Redirect/Get.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

define('PROFILE_PHOTO_MAX', 2 * 1024 * 1024);
$photo_types = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']];

$errors = ['name' => '', 'email' => '', 'photo' => '', 'guardian_email' => '', 'guardian_phone' => '', 'guardian_name' => ''];
$task_result = null;
$form = ['name' => $user['name'], 'email' => $user['email'], 'guardian_name' => (string) $user['guardian_name'], 'guardian_email' => (string) $user['guardian_email'], 'guardian_phone' => (string) $user['guardian_phone']];

if (postBodyTooLarge()) {
    $errors['photo'] = 'Image is too large (max 2 MB)';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
    $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'export_me') {
        // Everything the system holds about the signed-in person, as a file they can keep
        $data = privacyExport($current_user_id);
        logActivity('privacy_export', 'profile', 'Downloaded own data');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-smartclass-data-' . date('Y-m-d') . '.json"');
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    } elseif ($action === 'ask_delete') {
        // The school decides (it may have to keep school records): the administrators are told, once per day
        $g = rateGuard('data_request', (string) $current_user_id, 1, 86400);
        if ($g['ok']) {
            foreach ($db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1") as $a) {
                notify((int) $a['id'], 'data_request', $current_user_name . ' asked for their data to be removed', 'Settings › Privacy & data → find the person → Anonymise or Delete.', 'pages/settings/privacy.php?q=' . rawurlencode((string) $user['email']));
            }
            logActivity('privacy_request', 'profile', 'Asked the school to remove their data');
        }
        Session::flash('profile_msg', ['success', 'Request sent', 'Your school administrator has been told. They will contact you.']);
        redirect(APP_URL . '/pages/settings/profile.php');
    } elseif ($action === 'save_guardian' && isStudent()) {
        $g = ['guardian_name' => trim((string) ($_POST['guardian_name'] ?? '')), 'guardian_email' => strtolower(trim((string) ($_POST['guardian_email'] ?? ''))), 'guardian_phone' => trim((string) ($_POST['guardian_phone'] ?? ''))];
        if (mb_strlen($g['guardian_name']) > 100) $errors['guardian_name'] = 'Name must be under 100 characters';
        if ($g['guardian_email'] !== '' && (!filter_var($g['guardian_email'], FILTER_VALIDATE_EMAIL) || strlen($g['guardian_email']) > 100)) $errors['guardian_email'] = 'Enter a valid e-mail address (or leave it empty)';
        if ($g['guardian_phone'] !== '' && !preg_match('/^[0-9 +()\-]{5,40}$/', $g['guardian_phone'])) $errors['guardian_phone'] = 'Use digits, spaces, + ( ) and - only';
        $form = array_merge($form, $g);
        if (!$errors['guardian_name'] && !$errors['guardian_email'] && !$errors['guardian_phone']) {
            $db->update('users', ['guardian_name' => $g['guardian_name'] ?: null, 'guardian_email' => $g['guardian_email'] ?: null, 'guardian_phone' => $g['guardian_phone'] ?: null,
                                  'guardian_alerts' => !empty($_POST['guardian_alerts']) ? 1 : 0], 'id = ?', [$current_user_id]);
            logActivity('guardian_contact', 'profile', 'Updated parent / guardian contact');
            Session::flash('profile_msg', ['success', 'Guardian contact saved', 'Your parent / guardian can now be told when you are absent.']);
            redirect(APP_URL . '/pages/settings/profile.php');
        }
    } elseif ($action === 'save_details') {
        $form['name'] = trim($_POST['name'] ?? '');
        $form['email'] = trim($_POST['email'] ?? '');
        if ($form['name'] === '') {
            $errors['name'] = 'Full name is required';
        } elseif (mb_strlen($form['name']) > 100) {
            $errors['name'] = 'Name must be under 100 characters';
        }
        if ($form['email'] === '') {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 100) {
            $errors['email'] = 'Please enter a valid email address';
        } elseif ($db->fetchOne("SELECT id FROM users WHERE email = ? AND id <> ?", [$form['email'], $current_user_id])) {
            $errors['email'] = 'This email is already used by another account';
        }
        if (!$errors['name'] && !$errors['email']) {
            $db->update('users', ['name' => $form['name'], 'email' => $form['email']], 'id = ?', [$current_user_id]);
            $_SESSION['user_name'] = $form['name'];
            $_SESSION['user_email'] = $form['email'];
            logActivity('update_profile', 'profile', 'Updated name/email');
            Session::flash('profile_msg', ['success', 'Profile saved', 'Your details were updated.']);
            redirect(APP_URL . '/pages/settings/profile.php');
        }
    } elseif ($action === 'upload_photo') {
        $file = $_FILES['photo'] ?? null;
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['photo'] = 'Choose an image first';
        } elseif (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $file['size'] > PROFILE_PHOTO_MAX) {
            $errors['photo'] = 'Image is too large (max 2 MB)';
        } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors['photo'] = 'Upload failed, please try again';
        } elseif (!isset($photo_types[$ext]) || !in_array((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']), $photo_types[$ext], true)
                  || @getimagesize($file['tmp_name']) === false) {
            $errors['photo'] = 'Use a JPG, PNG or WEBP image';
        } else {
            $dir = ensureProtectedUploadDir('avatars');
            $name = 'u' . $current_user_id . '_' . bin2hex(random_bytes(12)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            if ($dir === false || !move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
                error_log('SmartClass: cannot store avatar in uploads/avatars');
                $errors['photo'] = 'Server upload folder is not writable';
            } else {
                $old = uploadAbsolutePath((string) $user['photo']);
                $db->update('users', ['photo' => 'uploads/avatars/' . $name], 'id = ?', [$current_user_id]);
                if ($old) @unlink($old);
                logActivity('update_photo', 'profile', 'Changed profile photo');
                Session::flash('profile_msg', ['success', 'Photo updated', 'Your new photo is shown across SmartClass.']);
                redirect(APP_URL . '/pages/settings/profile.php');
            }
        }
    } elseif ($action === 'remove_photo') {
        $old = uploadAbsolutePath((string) $user['photo']);
        $db->update('users', ['photo' => null], 'id = ?', [$current_user_id]);
        if ($old) @unlink($old);
        Session::flash('profile_msg', ['success', 'Photo removed', 'Your initial is shown instead.']);
        redirect(APP_URL . '/pages/settings/profile.php');
    }
}

$task_result = Session::flash('profile_msg') ?? $task_result;
$user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$current_user_id]);   // fresh after changes
$class_count = isTeacher()
    ? (int) $db->count('classes', '' . teachesClassSql('classes') . ' AND is_active = 1', [$current_user_id])
    : (int) $db->count('class_members', 'student_id = ?', [$current_user_id]);

$page_title = 'Profile';
$page_subtitle = 'Your name, e-mail and photo';
$active_menu = 'settings';
$settings_page = true;
$page_script = "
    document.querySelectorAll('.profile-form').forEach(function(f) { bindLiveClear(f); });
    const photo = document.getElementById('photo');
    photo.addEventListener('change', function() {
        const f = photo.files[0];
        const err = document.getElementById('photoError');
        if (!f) return;
        if (f.size > " . PROFILE_PHOTO_MAX . ") { photo.classList.add('is-invalid'); err.textContent = 'Image is too large (max 2 MB)'; err.classList.add('show'); photo.value = ''; return; }
        photo.classList.remove('is-invalid'); err.classList.remove('show');
        document.getElementById('photoPreview').src = URL.createObjectURL(f);
        document.getElementById('photoPreview').hidden = false;
        document.getElementById('photoInitial').hidden = true;
        document.getElementById('photoSave').hidden = false;
    });" . ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ",
                text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee'"
                . ($task_result[0] === 'success' ? ", timer: 1800, showConfirmButton: false" : "") . " });" : "");

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Photo</h3><p>Shown next to your name across SmartClass.</p></header>
    <div class="body">
        <div class="photo-row">
            <div class="profile-photo">
                <img id="photoPreview" src="<?php echo !empty($user['photo']) ? APP_URL . '/pages/profile/photo.php?u=' . (int) $user['id'] . '&amp;v=' . urlencode(basename($user['photo'])) : ''; ?>"
                     alt="Profile photo" <?php echo empty($user['photo']) ? 'hidden' : ''; ?>>
                <span id="photoInitial" class="avatar teacher" <?php echo !empty($user['photo']) ? 'hidden' : ''; ?> aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($user['name'], 0, 1))); ?></span>
            </div>
            <div class="photo-side">
                <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                <span class="role-chip"><?php echo isTeacher() ? 'Teacher' : 'Student'; ?></span>
                <span class="form-hint" style="margin:0;"><?php echo $class_count; ?> <?php echo isTeacher() ? 'class' . ($class_count == 1 ? '' : 'es') . ' taught' : 'class' . ($class_count == 1 ? '' : 'es') . ' joined'; ?> · member since <?php echo formatDate($user['created_at'], 'M Y'); ?></span>
                <form method="POST" enctype="multipart/form-data" class="profile-form photo-actions">
                    <?php echo csrfField(); ?><input type="hidden" name="action" value="upload_photo">
                    <label for="photo" class="btn-app btn-sm btn-outline" style="cursor:pointer;"><i class="fas fa-camera"></i> Choose photo</label>
                    <input type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp" hidden class="<?php echo $errors['photo'] ? 'is-invalid' : ''; ?>">
                    <button type="submit" class="btn-app btn-sm" id="photoSave" hidden><i class="fas fa-upload"></i> Save photo</button>
                    <small class="form-hint" style="margin:0;">JPG, PNG or WEBP · max 2 MB</small>
                    <div class="invalid-feedback <?php echo $errors['photo'] ? 'show' : ''; ?>" id="photoError" style="width:100%;"><?php echo htmlspecialchars($errors['photo']); ?></div>
                </form>
                <?php if (!empty($user['photo'])): ?>
                <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="remove_photo">
                    <button type="submit" class="link-btn" style="color:var(--danger);"><i class="fas fa-trash"></i> Remove photo</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<section class="set-section">
    <header><h3>Your details</h3><p>Your name and the e-mail you sign in with.</p></header>
    <div class="body">
        <form method="POST" class="profile-form" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save_details">
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Full name <span class="required-mark">*</span></label>
                    <input type="text" id="name" name="name" maxlength="100" class="<?php echo $errors['name'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['name']); ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div>
                </div>
                <div class="form-group">
                    <label for="email">E-mail <span class="required-mark">*</span></label>
                    <input type="text" id="email" name="email" maxlength="100" autocomplete="email" class="<?php echo $errors['email'] ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($form['email']); ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['email']); ?></div>
                    <small class="form-hint">You sign in with this e-mail.</small>
                </div>
            </div>
            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save details</button></div>
        </form>
    </div>
</section>

<?php if (isStudent()): ?>
<section class="set-section">
    <header><h3>Parent / guardian</h3><p>Optional. If your school switched on guardian alerts, this person is e-mailed when you are marked absent.</p></header>
    <div class="body">
        <form method="POST" class="profile-form" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save_guardian">
            <div class="form-row">
                <div class="form-group"><label for="guardian_name">Name</label>
                    <input type="text" id="guardian_name" name="guardian_name" maxlength="100" value="<?php echo htmlspecialchars($form['guardian_name']); ?>" class="<?php echo $errors['guardian_name'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['guardian_name']); ?></div></div>
                <div class="form-group"><label for="guardian_email">E-mail</label>
                    <input type="text" id="guardian_email" name="guardian_email" maxlength="100" value="<?php echo htmlspecialchars($form['guardian_email']); ?>" class="<?php echo $errors['guardian_email'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['guardian_email']); ?></div></div>
                <div class="form-group"><label for="guardian_phone">Phone</label>
                    <input type="text" id="guardian_phone" name="guardian_phone" maxlength="40" value="<?php echo htmlspecialchars($form['guardian_phone']); ?>" class="<?php echo $errors['guardian_phone'] ? 'is-invalid' : ''; ?>">
                    <div class="invalid-feedback"><?php echo htmlspecialchars($errors['guardian_phone']); ?></div></div>
            </div>
            <label class="switch-row"><input type="checkbox" name="guardian_alerts" value="1" <?php echo (int) $user['guardian_alerts'] ? 'checked' : ''; ?>> <span>E-mail my guardian about absences</span></label>
            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save guardian contact</button></div>
        </form>
    </div>
</section>
<?php endif; ?>
<section class="set-section">
    <header><h3>Your data</h3><p>You can keep a copy of everything this system holds about you, and ask the school to remove it.</p></header>
    <div class="body">
        <form method="POST" style="display:inline;"><?php echo csrfField(); ?><input type="hidden" name="action" value="export_me"><button type="submit" class="btn-app btn-outline" data-no-ajax><i class="fas fa-download"></i> Download my data</button></form>
        <form method="POST" style="display:inline;" data-confirm="Ask the school to remove your data?" data-confirm-text="Your administrator decides: marks and attendance may have to stay in the school's records, but your personal details can be removed." data-confirm-button="Send the request">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="ask_delete"><button type="submit" class="btn-app btn-outline"><i class="fas fa-user-slash"></i> Ask the school to remove my data</button></form>
        <p class="form-hint" style="margin-top:10px;">The file is plain text (JSON): your account, classes, work you handed in, marks, attendance, messages, AI Tutor conversations and notifications. Passwords and secret keys are never included.</p>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
