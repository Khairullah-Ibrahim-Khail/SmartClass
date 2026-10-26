<?php
/**
 * Class — Import students from a CSV file: ?id=N (teachers of the class)
 *   POST preview   the file (name,email — first row may be a header; comma or semicolon; up to 500 rows, 1 MB) is read and checked,
 *                  nothing is changed; the rows are kept in the session for the next step
 *   POST import    CSRF; every checked row is created / enrolled:
 *                    - new e-mail         a student account (approved — you vouch for them), joined to this class
 *                    - existing student   joined to this class (no second account)
 *                    - in this class already, a teacher's e-mail, a bad row, a duplicate in the file, or over the provider's student limit → skipped, with the reason
 *   Imported students get a random password they never see: they sign in with the e-mail code (or "forgot password").
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/class_header.php';
if (!$is_owner) redirect(classUrl('stream'));

const IMPORT_MAX_ROWS = 500;
$task_result = null;
$preview = null;      // [ ['line','name','email','state'(new|existing|member|bad|dup|teacher),'note'] ]
$result = null;

/** Read the uploaded CSV into [ [line, name, email] ] (header row skipped), or an error message. */
function importParse($file) {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a CSV file first.';
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return 'The upload failed — try again.';
    if ($file['size'] > 1024 * 1024) return 'The file is larger than 1 MB.';
    $text = (string) file_get_contents($file['tmp_name']);
    if (strpos($text, "\xEF\xBB\xBF") === 0) $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) return 'Save the file as CSV (UTF-8) and try again.';
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
        if (trim($line) === '') continue;
        $cells = str_getcsv($line, substr_count($line, ';') > substr_count($line, ',') ? ';' : ',', '"', '\\');
        $a = trim((string) ($cells[0] ?? '')); $b = trim((string) ($cells[1] ?? ''));
        if ($i === 0 && preg_match('/^(name|student|full)/i', $a) && preg_match('/mail/i', $b)) continue;   // header
        if (count($rows) >= IMPORT_MAX_ROWS) return 'A file may have at most ' . IMPORT_MAX_ROWS . ' students — split it.';
        $rows[] = ['line' => $i + 1, 'name' => $a, 'email' => strtolower($b)];
    }
    return $rows ?: 'The file has no rows.';
}

/** Check each row against the database. */
function importClassify(array $rows, $class_id) {
    global $db;
    $seen = [];
    foreach ($rows as &$r) {
        $r['state'] = 'new'; $r['note'] = '';
        if ($r['name'] === '' || mb_strlen($r['name']) > 100) { $r['state'] = 'bad'; $r['note'] = 'Name is missing or over 100 characters'; continue; }
        if (!filter_var($r['email'], FILTER_VALIDATE_EMAIL) || strlen($r['email']) > 100) { $r['state'] = 'bad'; $r['note'] = 'Not a valid e-mail address'; continue; }
        if (isset($seen[$r['email']])) { $r['state'] = 'dup'; $r['note'] = 'Same e-mail as line ' . $seen[$r['email']]; continue; }
        $seen[$r['email']] = $r['line'];
        $u = $db->fetchOne("SELECT id, role, is_active FROM users WHERE email = ?", [$r['email']]);
        if (!$u) continue;
        if ($u['role'] !== 'student') { $r['state'] = 'teacher'; $r['note'] = 'This e-mail belongs to a teacher account'; continue; }
        $r['user_id'] = (int) $u['id'];
        if (!$u['is_active']) { $r['state'] = 'bad'; $r['note'] = 'This account is switched off'; continue; }
        $r['state'] = $db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [$class_id, $u['id']]) ? 'member' : 'existing';
        $r['note'] = $r['state'] === 'member' ? 'Already in this class' : 'Has an account — will be added to the class';
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'preview') {
        $parsed = importParse($_FILES['csv'] ?? null);
        if (is_string($parsed)) {
            $task_result = ['error', 'Cannot read the file', $parsed];
        } else {
            $preview = importClassify($parsed, $class_id);
            $_SESSION['import_rows'] = ['class' => $class_id, 'rows' => $preview, 'at' => time()];
        }
    } elseif ($action === 'import') {
        $stash = $_SESSION['import_rows'] ?? null;
        unset($_SESSION['import_rows']);
        if (!$stash || (int) $stash['class'] !== $class_id || time() - (int) $stash['at'] > 1800) {
            $task_result = ['error', 'Start again', 'The preview expired. Choose the file again.'];
        } else {
            $rows = importClassify($stash['rows'], $class_id);   // checked again right before writing
            $new = count(array_filter($rows, fn($r) => $r['state'] === 'new'));
            $limit_msg = $new ? limitReached('students', $new - 1) : null;
            $made = $added = $skipped = 0;
            foreach ($rows as $r) {
                if ($r['state'] === 'new') {
                    if ($limit_msg) { $skipped++; continue; }
                    $uid = $db->insert('users', ['name' => $r['name'], 'email' => $r['email'], 'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                                                 'role' => 'student', 'is_approved' => 1, 'is_active' => 1]);
                    $db->insert('class_members', ['class_id' => $class_id, 'student_id' => $uid]);
                    $made++;
                } elseif ($r['state'] === 'existing') {
                    $db->query("INSERT IGNORE INTO class_members (class_id, student_id) VALUES (?, ?)", [$class_id, $r['user_id']]);
                    $added++;
                } else {
                    $skipped++;
                }
            }
            logActivity('import_students', 'classes', "Imported students into {$class['class_name']}: {$made} new accounts, {$added} existing added, {$skipped} skipped");
            $result = ['made' => $made, 'added' => $added, 'skipped' => $skipped, 'limit' => $limit_msg, 'rows' => $rows];
            $student_count = (int) $db->count('class_members', 'class_id = ?', [$class_id]);
        }
    }
}
if ($preview === null && isset($_SESSION['import_rows']) && $result === null && (int) $_SESSION['import_rows']['class'] === $class_id && ($_POST['action'] ?? '') === '') unset($_SESSION['import_rows']);

$counts = $preview ? array_count_values(array_column($preview, 'state')) : [];
$page_title = $class['class_name'] . ' - Import students';
$page_script = classResultScript($task_result);
include dirname(__DIR__, 2) . '/includes/app_header.php';
classTop('people');
$state_label = ['new' => ['New account', 'graded'], 'existing' => ['Add to class', 'submitted'], 'member' => ['Already in class', 'pending'], 'bad' => ['Skipped', 'missing'],
                'dup' => ['Duplicate', 'missing'], 'teacher' => ['Skipped', 'missing']];
?>
<section class="content-card">
    <div class="card-head"><h2><i class="fas fa-file-import"></i> Import students from a CSV file</h2>
        <a class="btn-app btn-sm btn-outline" href="people.php?id=<?php echo $class_id; ?>"><i class="fas fa-arrow-left"></i> People</a></div>

<?php if ($result): ?>
    <div class="card-inner">
        <div class="att-warning" style="background:rgba(6,167,125,.1);border-color:rgba(6,167,125,.4);"><i class="fas fa-check-circle" style="color:#06a77d;"></i>
            <div><strong>Done.</strong> <?php echo $result['made']; ?> new account<?php echo $result['made'] == 1 ? '' : 's'; ?>, <?php echo $result['added']; ?> existing student<?php echo $result['added'] == 1 ? '' : 's'; ?> added, <?php echo $result['skipped']; ?> skipped.
            <?php echo $result['limit'] ? '<br>' . htmlspecialchars($result['limit']) : ''; ?>
            <br>New students sign in with the e-mail code on the sign-in page (or "Forgot password").</div></div>
    </div>
    <?php $preview = $result['rows']; ?>
<?php elseif (!$preview): ?>
    <form method="POST" enctype="multipart/form-data" class="card-inner" novalidate>
        <?php echo csrfField(); ?><input type="hidden" name="action" value="preview">
        <p class="form-hint" style="margin-top:0;">One student per line: <strong>name, e-mail</strong>. A header row is fine. Up to <?php echo IMPORT_MAX_ROWS; ?> students, 1 MB. Nothing is saved until you confirm the preview.</p>
        <pre class="import-sample">Name,Email
Aisha Rahimi,aisha@school.example
Omar Siddiqui,omar@school.example</pre>
        <div class="form-group"><label for="csv">CSV file</label><input type="file" id="csv" name="csv" accept=".csv,.txt,text/csv" required></div>
        <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-eye"></i> Preview</button></div>
    </form>
<?php endif; ?>

<?php if ($preview): ?>
    <?php if (!$result): ?>
    <div class="card-inner" style="padding-bottom:0;">
        <p style="margin:0 0 10px;"><strong><?php echo count($preview); ?> rows.</strong>
            <?php foreach (['new' => 'new accounts', 'existing' => 'existing students to add', 'member' => 'already in the class', 'bad' => 'cannot be used', 'dup' => 'duplicates', 'teacher' => 'teacher e-mails'] as $k => $l) if (!empty($counts[$k])) echo '<span class="status-badge ' . $state_label[$k][1] . '" style="margin-right:6px;">' . $counts[$k] . ' ' . $l . '</span>'; ?></p>
    </div>
    <?php endif; ?>
    <div class="table-wrap"><table class="app-table">
        <thead><tr><th>Line</th><th>Name</th><th>E-mail</th><th>What happens</th></tr></thead>
        <tbody>
        <?php foreach ($preview as $r): [$lbl, $cls] = $state_label[$r['state']]; ?>
            <tr><td><?php echo (int) $r['line']; ?></td><td><?php echo htmlspecialchars($r['name']); ?></td><td><?php echo htmlspecialchars($r['email']); ?></td>
                <td><span class="status-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span> <span class="form-hint" style="margin:0;"><?php echo htmlspecialchars($r['note']); ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (!$result && (!empty($counts['new']) || !empty($counts['existing']))): ?>
    <form method="POST" class="card-inner" style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="import">
        <button type="submit" class="btn-app"><i class="fas fa-user-plus"></i> Import <?php echo (int) ($counts['new'] ?? 0) + (int) ($counts['existing'] ?? 0); ?> student<?php echo ((int) ($counts['new'] ?? 0) + (int) ($counts['existing'] ?? 0)) == 1 ? '' : 's'; ?></button>
        <a class="btn-app btn-outline" href="import.php?id=<?php echo $class_id; ?>">Choose another file</a>
    </form>
    <?php elseif (!$result): ?>
    <div class="card-inner"><p class="form-hint">Nothing can be imported from this file. <a href="import.php?id=<?php echo $class_id; ?>">Choose another file</a>.</p></div>
    <?php endif; ?>
<?php endif; ?>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>
