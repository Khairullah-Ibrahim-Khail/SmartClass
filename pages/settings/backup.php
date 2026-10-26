<?php
/**
 * Backup (super admin) — sidebar "Settings › Backup".
 *   GET  ?download=1            download a full backup now (gzip SQL) — also a POST-free link guarded by a token
 *   POST download               the same, as a form (CSRF)
 *   POST restore                replace ALL data with an uploaded backup. Needs: the file, your password and the
 *                               word RESTORE. A safety backup of the current data is saved first (and put back
 *                               if the restore fails); you are signed out afterwards.
 * Backups contain every account, password hash and encrypted key: keep them private.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

requireAdmin();

$self = APP_URL . '/pages/settings/backup.php';
$errors = ['file' => '', 'password' => '', 'confirm' => ''];
$task_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'download') {
        $tmp = tempnam(sys_get_temp_dir(), 'scbk');
        try {
            $rows = backupWrite($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            Session::flash('backup_msg', ['error', 'Backup failed', $e->getMessage()]);
            redirect($self);
        }
        logActivity('backup_download', 'settings', "Downloaded a backup ({$rows} rows)");
        $name = 'smartclass-backup-' . date('Ymd-His') . '.sql.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    } elseif ($action === 'restore' && customerStatus()['status'] === 'suspended') {
        $task_result = ['error', 'Paused', 'This installation is paused, so a backup cannot be restored. You can still download your data.'];
    } elseif ($action === 'restore') {
        $file = $_FILES['backup'] ?? null;
        $me = $db->fetchOne("SELECT password FROM users WHERE id = ?", [$current_user_id]);
        if (!$file || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['file'] = 'Choose the backup file';
        } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors['file'] = 'The file is larger than this server accepts (' . ini_get('upload_max_filesize') . ')';
        } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors['file'] = 'Upload failed, please try again';
        }
        if (($_POST['password'] ?? '') === '') $errors['password'] = 'Enter your password';
        elseif (!$me || !password_verify((string) $_POST['password'], $me['password'])) $errors['password'] = 'That is not your password';
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'RESTORE') $errors['confirm'] = 'Type the word RESTORE to confirm';

        if (!array_filter($errors)) {
            $sql = backupRead($file['tmp_name']);
            $parsed = backupParse($sql);
            if (is_string($parsed)) {
                $errors['file'] = $parsed;
            } else {
                $r = backupRestore($parsed[0]);
                if ($r['ok']) {
                    // The accounts may be different now: end this session and sign in again
                    $_SESSION = [];
                    session_destroy();
                    header('Location: ' . APP_URL . '/pages/auth/login.php?message=restored');
                    exit;
                }
                $task_result = ['error', 'Not restored', $r['message']];
            }
        }
        if (!$task_result && array_filter($errors)) $task_result = ['error', 'Not restored', 'Fix the highlighted fields. Nothing was changed.'];
    }
}
$task_result = Session::flash('backup_msg') ?? $task_result;

$files = backupFiles();
$stats = $db->fetchOne("SELECT COUNT(*) AS t, COALESCE(SUM(table_rows), 0) AS r, COALESCE(SUM(data_length + index_length), 0) AS b
                        FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");

$page_title = 'Backup';
$page_subtitle = 'Download a copy of all school data, or restore one';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_script .= "
    const rf = document.getElementById('restoreForm');
    if (rf) {
        bindLiveClear(rf);
        rf.addEventListener('submit', function (e) {
            if (rf.dataset.confirmed) return;
            e.preventDefault();
            let ok = true;
            const f = rf.querySelector('[name=backup]'), p = rf.querySelector('[name=password]'), c = rf.querySelector('[name=confirm]');
            if (!f.files.length) { setFieldError(f, 'Choose the backup file'); ok = false; }
            if (!p.value) { setFieldError(p, 'Enter your password'); ok = false; }
            if (c.value.trim() !== 'RESTORE') { setFieldError(c, 'Type the word RESTORE to confirm'); ok = false; }
            if (!ok) return;
            Swal.fire({ icon: 'warning', title: 'Replace ALL data?', text: 'Everything now in the system is replaced by the backup. A safety copy is saved first, and you will be signed out.',
                        showCancelButton: true, confirmButtonText: 'Yes, restore', confirmButtonColor: '#ef476f' })
                .then(function (r) { if (r.isConfirmed) { rf.dataset.confirmed = '1'; rf.submit(); } });
        });
    }";

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-download"></i> Download a backup</h2></div>
    <div class="card-inner">
        <p>A complete copy of the database — <?php echo (int) $stats['t']; ?> tables, about <?php echo number_format($stats['b'] / 1048576, 1); ?> MB before compression —
            as one compressed file. Uploaded files (images, resources, submissions) are in the <code>uploads/</code> folder and are not part of it.</p>
        <p class="form-hint">The file contains every account, password hash and saved key. Keep it private. Saved AI keys and email passwords can only be read again on a system with the same database login.</p>
        <form method="POST">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="download">
            <button type="submit" class="btn-app"><i class="fas fa-download"></i> Download backup</button>
        </form>
    </div>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-history"></i> Restore a backup</h2></div>
    <div class="card-inner">
        <div class="alert-box warn" style="margin-bottom:14px;"><i class="fas fa-exclamation-triangle"></i>
            <span>Restoring <strong>replaces everything</strong> now in the system (people, classes, work, grades, settings) with the backup. A safety copy of the current data is saved first.</span></div>
        <form method="POST" enctype="multipart/form-data" id="restoreForm" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="action" value="restore">
            <div class="form-group">
                <label for="backup">Backup file <span class="required-mark">*</span> <span class="form-hint" style="display:inline;margin:0;">.sql.gz or .sql, up to <?php echo htmlspecialchars(ini_get('upload_max_filesize')); ?></span></label>
                <input type="file" id="backup" name="backup" accept=".gz,.sql" class="<?php echo $errors['file'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['file']); ?></div>
            </div>
            <div class="form-group">
                <label for="password">Your password <span class="required-mark">*</span></label>
                <input type="password" id="password" name="password" autocomplete="current-password" class="<?php echo $errors['password'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['password']); ?></div>
            </div>
            <div class="form-group">
                <label for="confirm">Type RESTORE to confirm <span class="required-mark">*</span></label>
                <input type="text" id="confirm" name="confirm" autocomplete="off" spellcheck="false" class="<?php echo $errors['confirm'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback"><?php echo htmlspecialchars($errors['confirm']); ?></div>
            </div>
            <button type="submit" class="btn-app btn-danger"><i class="fas fa-undo"></i> Restore</button>
        </form>
    </div>
</div>

<?php if ($files): ?>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-shield-alt"></i> Safety copies on the server</h2></div>
    <div class="card-inner">
        <p class="form-hint">Saved automatically just before each restore (the newest <?php echo BACKUP_KEEP; ?> are kept) in the <code>backups/</code> folder, which the web server does not serve.
            To go back, download one with your file manager and restore it here.</p>
        <ul class="plain-list">
            <?php foreach ($files as $f): ?>
            <li><i class="fas fa-file-archive"></i> <?php echo htmlspecialchars($f['name']); ?> — <?php echo number_format($f['size'] / 1024, 0); ?> KB, <?php echo date('M d, Y h:i A', $f['time']); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
