<?php
/**
 * School (super admin) — sidebar "Settings › School": the name, logo and accent colour this installation shows.
 *   POST save    name (≤ 60), accent colour (#rrggbb), optional new logo (JPG / PNG / WEBP, decoded and re-encoded as a
 *                PNG of at most 256 px — metadata and anything hidden in the file are dropped) or "remove logo"
 * Inline field errors; CSRF; Post/Redirect/Get. Each customer installation has its own values.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$errors = ['school_name' => '', 'admin_title' => '', 'brand_color' => '', 'logo' => ''];
$task_result = Session::flash('school_msg');
$form = ['school_name' => (string) getSetting('school_name', ''), 'admin_title' => (string) getSetting('admin_title', ''), 'brand_color' => (string) getSetting('brand_color', '#4361ee')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $form['school_name'] = trim((string) ($_POST['school_name'] ?? ''));
        $form['admin_title'] = trim((string) ($_POST['admin_title'] ?? ''));
        if (mb_strlen($form['admin_title']) > 30) $errors['admin_title'] = 'The title must be under 30 characters';
        $form['brand_color'] = strtolower(trim((string) ($_POST['brand_color'] ?? '')));
        if (mb_strlen($form['school_name']) > 60) $errors['school_name'] = 'The name must be under 60 characters';
        if (!preg_match('/^#[0-9a-f]{6}$/', $form['brand_color'])) $errors['brand_color'] = 'Choose a colour';
        $new_logo = null;
        $file = $_FILES['logo'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && empty($_POST['remove_logo'])) {
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) $errors['logo'] = 'Upload failed, please try again';
            elseif ($file['size'] > 2 * 1024 * 1024) $errors['logo'] = 'The logo must be under 2 MB';
            elseif (!function_exists('imagecreatefromstring')) $errors['logo'] = 'Image support (PHP GD) is not installed on this server';
            else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $src = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? @imagecreatefromstring((string) file_get_contents($file['tmp_name'])) : false;
                if (!$src) $errors['logo'] = 'Use a JPG, PNG or WEBP picture';
                else {
                    $w = imagesx($src); $h = imagesy($src);
                    $scale = min(1, 256 / max($w, $h));
                    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagealphablending($dst, false); imagesavealpha($dst, true);
                    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    if (!ensureProtectedUploadDir('branding')) $errors['logo'] = 'The server cannot save the logo yet (uploads folder is not writable)';
                    else {
                        $name = 'logo-' . bin2hex(random_bytes(6)) . '.png';
                        if (!imagepng($dst, STORAGE_ROOT . '/uploads/branding/' . $name)) $errors['logo'] = 'The logo could not be saved';
                        else $new_logo = 'uploads/branding/' . $name;
                    }
                }
            }
        }
        if (!array_filter($errors)) {
            setSetting('school_name', $form['school_name'], $current_user_id);
            setSetting('admin_title', $form['admin_title'], $current_user_id);
            setSetting('brand_color', $form['brand_color'], $current_user_id);
            $old = (string) getSetting('school_logo', '');
            if ($new_logo !== null || !empty($_POST['remove_logo'])) {
                setSetting('school_logo', $new_logo ?? '', $current_user_id);
                if ($old !== '' && ($abs = uploadAbsolutePath($old))) @unlink($abs);
            }
            logActivity('school_settings', 'settings', 'Updated the school name / logo / colour');
            Session::flash('school_msg', ['success', 'Saved', 'The new look is in use everywhere.']);
            redirect(APP_URL . '/pages/settings/school.php');
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted fields.'];
    }
}
$has_logo = (string) getSetting('school_logo', '') !== '';

$page_title = 'School';
$page_subtitle = 'The name, logo and colour of this installation';
$active_menu = 'settings';
$settings_page = true;
$page_script = ($task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '') . "
    const sf = document.getElementById('schoolForm'); if (sf) bindLiveClear(sf);";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<form method="POST" enctype="multipart/form-data" id="schoolForm" class="form-card" novalidate>
    <?php echo csrfField(); ?>
    <div class="form-group">
        <label for="school_name">School name <span class="form-hint" style="display:inline;margin:0;">shown in the sidebar, page titles and emails</span></label>
        <input type="text" id="school_name" name="school_name" maxlength="60" placeholder="SmartClass" value="<?php echo htmlspecialchars($form['school_name']); ?>" class="<?php echo $errors['school_name'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['school_name']); ?></div>
    </div>
    <div class="form-group">
        <label for="admin_title">Title of the school administrator <span class="form-hint" style="display:inline;margin:0;">shown under the administrator's name at the top; empty = "School admin"</span></label>
        <input type="text" id="admin_title" name="admin_title" maxlength="30" placeholder="School admin" value="<?php echo htmlspecialchars($form['admin_title']); ?>" class="<?php echo $errors['admin_title'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['admin_title']); ?></div>
    </div>
    <div class="form-group">
        <label for="brand_color">Accent colour</label>
        <input type="color" id="brand_color" name="brand_color" value="<?php echo htmlspecialchars($form['brand_color']); ?>" style="height:44px;padding:4px;max-width:120px;" class="<?php echo $errors['brand_color'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['brand_color']); ?></div>
    </div>
    <div class="form-group">
        <label for="logo">Logo <span class="form-hint" style="display:inline;margin:0;">JPG, PNG or WEBP, up to 2 MB — shown at 256 px at most</span></label>
        <?php if ($has_logo): ?><div style="margin-bottom:8px;"><img src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename((string) getSetting('school_logo', ''))); ?>" alt="Current logo" style="max-height:64px;border:1px solid var(--line);border-radius:10px;padding:6px;background:#fff;"></div><?php endif; ?>
        <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" class="<?php echo $errors['logo'] ? 'is-invalid' : ''; ?>">
        <div class="invalid-feedback"><?php echo htmlspecialchars($errors['logo']); ?></div>
        <?php if ($has_logo): ?><label class="switch-row" style="margin-top:8px;"><input type="checkbox" name="remove_logo" value="1"> <span>Remove the current logo</span></label><?php endif; ?>
    </div>
    <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save</button></div>
</form>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
