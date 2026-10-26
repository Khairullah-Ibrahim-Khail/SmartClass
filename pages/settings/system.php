<?php
/**
 * System check (super admin) — "Settings › System check": can the web server do what the app needs, and do the files and the
 * database agree?
 *   server     PHP version and the extensions we use, upload limits
 *   folders    uploads / backups / logs / sessions: exist, and a real write test as the web server (the usual reason uploads fail)
 *   sync       rows that point to a file that is not on disk (missing) and files in uploads/ that no row points to (orphans)
 * Read-only: it never deletes anything. Works per customer (STORAGE_ROOT).
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$ok = fn($b) => $b ? '<span class="status-badge graded">OK</span>' : '<span class="status-badge missing">Problem</span>';

// ---------- folders: a real write test ----------
$folders = [];
$web_user = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
foreach (['uploads', 'uploads/resources', 'uploads/submissions', 'uploads/lectures', 'uploads/profile', 'uploads/avatars', 'backups', 'sessions', 'cache'] as $rel) {
    $dir = STORAGE_ROOT . '/' . $rel;
    $exists = is_dir($dir);
    $w = false;
    if ($exists) {
        $probe = $dir . '/.chk' . bin2hex(random_bytes(4));   // (tempnam() would silently fall back to /tmp)
        $w = @file_put_contents($probe, 'x') !== false;
        if ($w) @unlink($probe);
    }
    $folders[] = ['rel' => $rel, 'exists' => $exists, 'writable' => $w, 'owner' => $exists ? (function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dir))['name'] ?? '?') : '?') : '-',
                  'mode' => $exists ? substr(sprintf('%o', fileperms($dir)), -3) : '-'];
}
$uploads_ok = true;
foreach ($folders as $f) if (in_array($f['rel'], ['uploads/resources', 'uploads/submissions'], true) && !$f['writable'] && $f['exists']) $uploads_ok = false;
$root_unwritable = !empty(array_filter($folders, fn($f) => $f['rel'] === 'uploads' && !$f['writable']));
$in_home = strpos(STORAGE_ROOT, '/home/') === 0;

// ---------- sync: database rows <-> files ----------
/** [missing rows, orphan files, referenced paths] — recomputed on the server every time (never trusted from the browser). */
function syncScan() {
    global $db;
    $tracked = [['resources', 'file_path', '1=1'], ['submissions', 'file_path', 'file_path IS NOT NULL'], ['lecture_images', 'file_path', '1=1'], ['users', 'photo', 'photo IS NOT NULL']];
    $missing = []; $referenced = []; $orphans = [];
    foreach ($tracked as [$t, $c, $w]) {
        try {
            foreach ($db->fetchAll("SELECT {$c} AS p FROM {$t} WHERE {$w} AND {$c} <> ''") as $r) {
                $p = (string) $r['p'];
                $referenced[$p] = true;
                if (!is_file(STORAGE_ROOT . '/' . $p)) $missing[] = ['table' => $t, 'path' => $p];
            }
        } catch (Throwable $e) {}
    }
    $root = STORAGE_ROOT . '/uploads';
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!$f->isFile() || $f->getFilename()[0] === '.') continue;
            $rel = 'uploads/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
            if (!isset($referenced[$rel]) && !preg_match('#^uploads/branding/#', $rel)) $orphans[] = $rel;
        }
    }
    return [$missing, $orphans, $referenced];
}
$task_result = Session::flash('sys_msg');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_log') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        $name = healthArchiveLog();
        logActivity('archive_error_log', 'settings', $name ? "Archived the error log as {$name}" : 'Error log: nothing to archive');
        Session::flash('sys_msg', $name ? ['success', 'Done', 'The old problems were moved to logs/' . $name . ' — the list starts fresh.'] : ['info', 'Nothing to move', 'The error log is empty or cannot be moved.']);
        redirect(APP_URL . '/pages/settings/system.php');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_orphans') {
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } else {
        [, $orph] = syncScan();
        $n = 0;
        foreach ($orph as $rel) { if (preg_match('#^uploads/[A-Za-z0-9_./-]+$#', $rel) && strpos($rel, '..') === false && @unlink(STORAGE_ROOT . '/' . $rel)) $n++; }
        logActivity('remove_orphan_files', 'settings', "Removed {$n} unreferenced upload file(s)");
        Session::flash('sys_msg', ['success', 'Done', $n . ' unreferenced file' . ($n == 1 ? ' was' : 's were') . ' removed. Files that a row points to are never touched.']);
        redirect(APP_URL . '/pages/settings/system.php');
    }
}
[$missing, $orphans, $referenced] = syncScan();
$exts = ['gd' => 'Pictures (profile photo, lecture images, logo)', 'fileinfo' => 'Checks the real type of uploaded files', 'mbstring' => 'Text handling', 'curl' => 'AI providers', 'openssl' => 'Encrypted keys', 'pdo_mysql' => 'Database', 'zlib' => 'Compressed backups'];

$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
$page_scripts = ['assets/js/class.js'];
$page_title = 'System check';
$page_subtitle = 'Can the server do what the app needs, and do files and database agree?';
$active_menu = 'settings';
$settings_page = true;
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php if (!$uploads_ok || $root_unwritable): ?>
<div class="alert-box warn" style="margin-bottom:16px;"><i class="fas fa-exclamation-triangle"></i> <span><strong>Uploads cannot be saved</strong> — the web server (<code><?php echo htmlspecialchars($web_user); ?></code>) cannot write in
    <code><?php echo htmlspecialchars(STORAGE_ROOT); ?>/uploads</code>.
    <?php if ($in_home): ?>This folder is under <code>/home</code>, which a default systemd Apache setup (<code>ProtectHome=read-only</code>) keeps read-only.
    <strong>Fix (once):</strong> run <code>sh bin/fix_local_apache.sh</code> in the project folder (asks for your sudo password), or give the folder to a place outside /home.
    <?php else: ?>Give the web server write access: <code>sudo chown -R www-data:www-data <?php echo htmlspecialchars(STORAGE_ROOT); ?>/uploads</code>.<?php endif; ?></span></div>
<?php else: ?>
<div class="alert-box" style="margin-bottom:16px;background:rgba(6,214,160,.12);"><i class="fas fa-check-circle" style="color:#06d6a0;"></i> <span>Uploads can be saved.</span></div>
<?php endif; ?>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-folder-open"></i> Folders (web server user: <?php echo htmlspecialchars($web_user); ?>)</h2></div>
    <div class="table-wrap"><table class="app-table"><thead><tr><th>Folder</th><th>Exists</th><th>Web server can write</th><th>Owner</th><th>Mode</th></tr></thead><tbody>
        <?php foreach ($folders as $f): ?>
        <tr><td><code><?php echo htmlspecialchars($f['rel']); ?></code></td><td><?php echo $f['exists'] ? 'yes' : 'no (created on first use)'; ?></td>
            <td><?php echo $f['exists'] ? $ok($f['writable']) : '—'; ?></td><td><?php echo htmlspecialchars($f['owner']); ?></td><td><?php echo htmlspecialchars($f['mode']); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-sync"></i> Files and database</h2></div>
    <div class="card-inner">
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Rows whose file is missing</div><div class="value"><?php echo count($missing); ?> <?php echo $ok(!$missing); ?></div></div>
            <div class="detail-item"><div class="label">Files no row points to</div><div class="value"><?php echo count($orphans); ?> <?php echo $ok(!$orphans); ?></div></div>
            <div class="detail-item"><div class="label">Files checked</div><div class="value"><?php echo count($referenced); ?> referenced</div></div>
        </div>
        <?php if ($missing): ?><h3 style="font-size:.95rem;margin:14px 0 6px;">Missing files (first 15)</h3><ul class="plain-list">
            <?php foreach (array_slice($missing, 0, 15) as $m): ?><li><code><?php echo htmlspecialchars($m['table']); ?></code> → <?php echo htmlspecialchars($m['path']); ?></li><?php endforeach; ?></ul>
            <p class="form-hint">A row without its file usually means the file was saved on another machine or deleted by hand. Download / open links for these items will say "not found".</p><?php endif; ?>
        <?php if ($orphans): ?><h3 style="font-size:.95rem;margin:14px 0 6px;">Orphan files (first 15)</h3><ul class="plain-list">
            <?php foreach (array_slice($orphans, 0, 15) as $o): ?><li><?php echo htmlspecialchars($o); ?></li><?php endforeach; ?></ul>
            <p class="form-hint">Harmless leftovers (for example from an upload that was cancelled or a row that was deleted). Removing them frees space; files that a row points to are never touched.</p>
            <form method="POST" data-confirm="Remove <?php echo count($orphans); ?> unreferenced file<?php echo count($orphans) == 1 ? '' : 's'; ?>?" data-confirm-text="Only files that no row in the database points to are deleted." data-confirm-button="Remove">
                <?php echo csrfField(); ?><input type="hidden" name="action" value="remove_orphans">
                <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-broom"></i> Remove unreferenced files</button></form><?php endif; ?>
        <?php if (!$missing && !$orphans): ?><p class="form-hint" style="margin:10px 0 0;">Every file the database knows about is on disk, and every file on disk is known to the database.</p><?php endif; ?>
    </div>
</div>

<?php $weak = weakDemoAccounts(); ?>
<?php if ($weak): ?>
<div class="alert-box" style="margin-bottom:16px;border-left:4px solid #ef476f;"><i class="fas fa-key" style="color:#ef476f;"></i>
    <span><strong><?php echo count($weak); ?> account<?php echo count($weak) == 1 ? ' has' : 's have'; ?> a well-known password</strong> (such as "password"). Anyone who knows it can sign in as them. Change it now: sign in as each person → Settings › Password &amp; security.
        <?php echo htmlspecialchars(implode(', ', array_map(fn($w) => $w['email'], array_slice($weak, 0, 6)))); ?><?php echo count($weak) > 6 ? ' …' : ''; ?>
        <?php if (!isDevHost()): ?><br><strong>This looks like a real school address — do this today.</strong><?php endif; ?></span></div>
<?php endif; ?>

<?php $jobs = cronStatus(); $problems = healthProblems(7); $lvl = ['error' => ['Error', 'missing'], 'warning' => ['Warning', 'late'], 'notice' => ['Notice', 'pending']]; ?>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-clock"></i> Background jobs</h2></div>
    <div class="table-wrap"><table class="app-table"><thead><tr><th>Job</th><th>Runs</th><th>Last run</th><th>State</th></tr></thead><tbody>
        <?php foreach ($jobs as $key => $j): ?>
        <tr><td><?php echo htmlspecialchars($j['label']); ?></td><td><?php echo htmlspecialchars($j['every']); ?></td>
            <td><?php echo $j['last'] ? htmlspecialchars(date('M j, g:i A', $j['last'])) . ' <span class="form-hint" style="display:inline;margin:0;">(' . (int) round($j['age'] / 60) . ' min ago)</span>' : '—'; ?></td>
            <td><?php if ($j['state'] === 'ok'): ?><span class="status-badge graded">Running</span>
                <?php elseif ($j['state'] === 'late'): ?><span class="status-badge missing">Late</span>
                <?php else: ?><span class="status-badge pending">Not started</span><?php endif; ?></td></tr>
        <?php if ($j['state'] !== 'ok'): ?><tr><td colspan="4"><span class="form-hint" style="margin:0;">Add this to the server's cron jobs: <code><?php echo htmlspecialchars($j['cmd']); ?></code></span></td></tr><?php endif; ?>
        <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-triangle-exclamation"></i> Recent problems <span class="form-hint" style="display:inline;margin:0 0 0 8px;">last 7 days</span></h2></div>
    <?php if (!$problems): ?>
    <div class="card-inner"><p class="form-hint" style="margin:0;"><i class="fas fa-check-circle" style="color:#06d6a0;"></i> Nothing went wrong in the last 7 days (PHP errors, failed AI calls, failed e-mails).</p></div>
    <?php else: ?>
    <div class="card-inner" style="padding-bottom:0;"><form method="POST" data-confirm="Start the list fresh?" data-confirm-text="The error log is moved to a dated file in logs/ (the 5 newest are kept), so only new problems show here." data-confirm-button="Archive">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="archive_log"><button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-box-archive"></i> I have dealt with these — start the list fresh</button></form></div>
    <div class="table-wrap"><table class="app-table"><thead><tr><th>Kind</th><th>What happened</th><th>Times</th><th>Last</th></tr></thead><tbody>
        <?php foreach ($problems as $p): ?>
        <tr><td><span class="status-badge <?php echo $lvl[$p['level']][1]; ?>"><?php echo $lvl[$p['level']][0]; ?></span> <span class="form-hint" style="display:inline;margin:0;"><?php echo htmlspecialchars($p['kind']); ?></span></td>
            <td style="max-width:520px;overflow-wrap:anywhere;"><?php echo htmlspecialchars($p['what']); ?></td><td><?php echo (int) $p['count']; ?></td><td><?php echo htmlspecialchars(date('M j, g:i A', $p['last'])); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-server"></i> Server</h2></div>
    <div class="table-wrap"><table class="app-table"><tbody>
        <tr><td>PHP</td><td><?php echo htmlspecialchars(PHP_VERSION); ?></td><td><?php echo $ok(version_compare(PHP_VERSION, '8.0', '>=')); ?></td></tr>
        <?php foreach ($exts as $e => $why): ?><tr><td><code><?php echo $e; ?></code></td><td><?php echo htmlspecialchars($why); ?></td><td><?php echo $ok(extension_loaded($e)); ?></td></tr><?php endforeach; ?>
        <tr><td>Uploads allowed</td><td><?php echo ini_get('file_uploads') ? 'yes' : 'no'; ?>, one file up to <?php echo htmlspecialchars(ini_get('upload_max_filesize')); ?> (form total <?php echo htmlspecialchars(ini_get('post_max_size')); ?>); the app accepts up to <?php echo formatBytes(maxUploadBytes()); ?></td><td><?php echo $ok((bool) ini_get('file_uploads')); ?></td></tr>
    </tbody></table></div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
