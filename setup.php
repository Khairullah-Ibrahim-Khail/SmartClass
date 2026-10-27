<?php
/**
 * SmartClass - Setup / Installer
 *
 * Run this once to prepare the system:
 *   1. Checks PHP requirements and the MySQL server connection
 *   2. Creates the database and imports config/install_schema.sql (the complete, current schema)
 *   3. Creates the upload folders (profile / resources / submissions)
 *   4. Lets you create the first teacher account
 *
 * When installation is already complete it only shows the status and a
 * link to the login page. Re-importing the schema (which WIPES all data)
 * requires typing the confirmation phrase.
 *
 * Delete this file from the server once setup is done.
 */

// ---------- Read DB credentials from config/db.php (without loading it,
// ---------- because db.php dies when the database does not exist yet) ----------
$configFile = __DIR__ . '/config/db.php';
$configSrc  = file_get_contents($configFile);

function configValue($src, $name, $default = '') {
    if (preg_match("/define\\('" . preg_quote($name, '/') . "',\\s*'([^']*)'\\)/", $src, $m)) {
        return $m[1];
    }
    return $default;
}

// Same source as the app and the command-line tools: config/local.php first, then the defaults in config/db.php
require_once __DIR__ . '/includes/instances.php';
$master = instanceMasterConfig();
$dbHost = $master['db_host'];
$dbName = $master['db_name'];
$dbUser = $master['db_user'];
$dbPass = $master['db_pass'];
$appUrl = '';

require_once __DIR__ . '/includes/migrator.php';
$schemaFile = __DIR__ . '/config/install_schema.sql';

// ---------- Helpers ----------

function serverConnect($dbHost, $dbUser, $dbPass, $dbName = null) {
    $dsn = "mysql:host={$dbHost};charset=utf8mb4" . ($dbName ? ";dbname={$dbName}" : '');
    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function databaseExists(PDO $pdo, $dbName) {
    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([$dbName]);
    return (bool) $stmt->fetch();
}

function tableExists(PDO $pdo, $dbName, $table) {
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
    );
    $stmt->execute([$dbName, $table]);
    return (bool) $stmt->fetch();
}

/** Execute a .sql file statement by statement (skips -- comments). */
function importSqlFile(PDO $pdo, $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read schema file: {$file}");
    }

    // Strip full-line comments, then split on semicolons.
    $lines = array_filter(explode("\n", $sql), function ($line) {
        return strpos(ltrim($line), '--') !== 0;
    });
    $statements = array_filter(array_map('trim', explode(';', implode("\n", $lines))));

    $count = 0;
    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $count++;
    }
    return $count;
}

function createUploadDirs() {
    $created = [];
    foreach (['uploads/profile', 'uploads/resources', 'uploads/submissions'] as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            if (!mkdir($path, 0755, true)) {
                throw new RuntimeException("Failed to create folder: {$dir}");
            }
            $created[] = $dir;
        }
    }
    return $created;
}

// ---------- Gather current status ----------

$errors   = [];
$messages = [];
$status   = [
    'php_version'   => PHP_VERSION,
    'php_ok'        => version_compare(PHP_VERSION, '7.4.0', '>='),
    'pdo_mysql'     => extension_loaded('pdo_mysql'),
    'schema_file'   => is_readable($schemaFile),
    'server_ok'     => false,
    'db_exists'     => false,
    'tables_ok'     => false,
    'teacher_count' => 0,
    'user_count'    => 0,
    'admin_count'   => 0,
];

$server = null;
try {
    $server = serverConnect($dbHost, $dbUser, $dbPass);
    $status['server_ok'] = true;
    $status['db_exists'] = databaseExists($server, $dbName);
    if ($status['db_exists']) {
        $status['tables_ok'] = tableExists($server, $dbName, 'users');
        if ($status['tables_ok']) {
            $db = serverConnect($dbHost, $dbUser, $dbPass, $dbName);
            $status['teacher_count'] = (int) $db->query("SELECT COUNT(*) AS c FROM users WHERE role = 'teacher'")->fetch()['c'];
            $status['user_count']    = (int) $db->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
            $status['admin_count']   = (int) $db->query("SELECT COUNT(*) AS c FROM users WHERE is_admin = 1")->fetch()['c'];
        }
    }
} catch (PDOException $e) {
    $errors[] = 'Database server connection failed: ' . htmlspecialchars($e->getMessage());
}

$installed = $status['tables_ok'];

// LOCK: once the system has an administrator, this page must not do anything for an anonymous visitor (it has no sign-in): no wipe, no new
// accounts. Someone with access to the server's files can allow a deliberate reinstall by creating an empty file config/allow_reinstall.
$locked = $installed && $status['admin_count'] > 0 && !is_file(__DIR__ . '/config/allow_reinstall');
if ($locked) {
    $errors[] = 'Setup is finished and locked: this system already has an administrator. Delete setup.php from the server. (To reinstall on purpose, create an empty file config/allow_reinstall on the server first and delete it afterwards.)';
}

// ---------- Handle actions ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status['server_ok'] && !$locked) {
    $action = $_POST['action'] ?? '';

    // --- Install / reinstall the database schema ---
    if ($action === 'install') {
        $confirm = trim($_POST['confirm_reset'] ?? '');
        if ($installed && $confirm !== 'RESET') {
            $errors[] = 'The database is already installed. To wipe it and reinstall, type RESET in the confirmation box.';
        } else {
            try {
                $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $target = serverConnect($dbHost, $dbUser, $dbPass, $dbName);
                $stmtCount = 0;
                foreach (migrationStatements(file_get_contents($schemaFile)) as $stmt) { $target->exec($stmt); $stmtCount++; }
                $mig = migrateDatabase($target, ['baseline' => '999']);   // the schema file already contains every migration: record them as applied
                if ($mig['error']) throw new RuntimeException($mig['error']);
                $created   = createUploadDirs();
                $messages[] = "Database <strong>{$dbName}</strong> installed ({$stmtCount} statements executed).";
                if ($created) {
                    $messages[] = 'Created upload folders: ' . implode(', ', $created);
                }
                // Refresh status
                $status['db_exists'] = true;
                $status['tables_ok'] = true;
                $db = serverConnect($dbHost, $dbUser, $dbPass, $dbName);
                $status['teacher_count'] = (int) $db->query("SELECT COUNT(*) AS c FROM users WHERE role = 'teacher'")->fetch()['c'];
                $status['user_count']    = (int) $db->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
                $installed = true;
            } catch (Exception $e) {
                $errors[] = 'Installation failed: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // --- Create the first teacher account ---
    if ($action === 'create_teacher') {
        if (!$installed) {
            $errors[] = 'Install the database first.';
        } else {
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($name === '' || $email === '' || $password === '') {
                $errors[] = 'Please fill in name, email and password.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } else {
                try {
                    $db = serverConnect($dbHost, $dbUser, $dbPass, $dbName);
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $errors[] = 'This email is already registered.';
                    } else {
                        $stmt = $db->prepare(
                            "INSERT INTO users (name, email, password, role, is_admin, is_approved, is_active)
                             VALUES (?, ?, ?, 'teacher', ?, 1, 1)"
                        );
                        // The first teacher created here is the school's super admin
                        $has_admin = (int) $db->query("SELECT COUNT(*) AS c FROM users WHERE is_admin = 1")->fetch()['c'] > 0;
                        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $has_admin ? 0 : 1]);
                        $messages[] = 'Teacher account <strong>' . htmlspecialchars($email) . '</strong> created. You can now log in.';
                        $status['teacher_count']++;
                        $status['user_count']++;
                    }
                } catch (PDOException $e) {
                    $errors[] = 'Failed to create teacher: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
}

$loginUrl = ($appUrl ?: '.') . '/pages/auth/login.php';

function badge($ok, $okText = 'OK', $failText = 'Missing') {
    return $ok
        ? '<span class="badge bg-success">' . $okText . '</span>'
        : '<span class="badge bg-danger">' . $failText . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - SmartClass</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
        min-height: 100vh;
        padding: 40px 15px;
    }

    .setup-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        overflow: hidden;
        max-width: 680px;
        margin: 0 auto;
    }

    .setup-header {
        background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
        color: white;
        padding: 30px;
        text-align: center;
    }

    .setup-body {
        padding: 30px;
    }

    .status-table td {
        vertical-align: middle;
    }

    .btn-setup {
        background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
        border: none;
        color: #fff;
        font-weight: 600;
    }

    .btn-setup:hover {
        background: linear-gradient(135deg, #3a56d4 0%, #5e0896 100%);
        color: #fff;
    }
    </style>
</head>

<body>
    <div class="setup-card">
        <div class="setup-header">
            <i class="fas fa-graduation-cap fa-3x mb-3"></i>
            <h2 class="mb-0">SmartClass Setup</h2>
            <p class="mb-0 mt-2 opacity-75">Database installation &amp; first account</p>
        </div>

        <div class="setup-body">
            <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?></div>
            <?php endforeach; ?>

            <?php foreach ($messages as $message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo $message; ?></div>
            <?php endforeach; ?>

            <!-- Status -->
            <h5 class="mb-3"><i class="fas fa-heartbeat me-2"></i>System Status</h5>
            <table class="table table-sm status-table mb-4">
                <tr>
                    <td>PHP version (<?php echo $status['php_version']; ?>)</td>
                    <td class="text-end"><?php echo badge($status['php_ok'], 'OK', 'Needs 7.4+'); ?></td>
                </tr>
                <tr>
                    <td>PDO MySQL extension</td>
                    <td class="text-end"><?php echo badge($status['pdo_mysql']); ?></td>
                </tr>
                <tr>
                    <td>Schema file (config/install_schema.sql)</td>
                    <td class="text-end"><?php echo badge($status['schema_file'], 'Found', 'Missing'); ?></td>
                </tr>
                <tr>
                    <td>MySQL server (<?php echo htmlspecialchars($dbHost); ?> as <?php echo htmlspecialchars($dbUser); ?>)</td>
                    <td class="text-end"><?php echo badge($status['server_ok'], 'Connected', 'Failed'); ?></td>
                </tr>
                <tr>
                    <td>Database <code><?php echo htmlspecialchars($dbName); ?></code> with tables</td>
                    <td class="text-end"><?php echo badge($status['tables_ok'], 'Installed', 'Not installed'); ?></td>
                </tr>
                <tr>
                    <td>Accounts (<?php echo $status['teacher_count']; ?> teachers / <?php echo $status['user_count']; ?> total users)</td>
                    <td class="text-end"><?php echo badge($status['teacher_count'] > 0, 'Ready', 'No teacher yet'); ?></td>
                </tr>
            </table>

            <!-- Step 1: Install database -->
            <h5 class="mb-3"><i class="fas fa-database me-2"></i>Step 1: Install Database</h5>
            <?php if ($locked): ?>
            <div class="alert alert-secondary mb-2"><i class="fas fa-lock me-2"></i>Locked: the system is installed and has an administrator.</div>
            <?php elseif (!$installed): ?>
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="install">
                <p class="text-muted">Creates <code><?php echo htmlspecialchars($dbName); ?></code> (if needed) and imports the
                    complete current schema (no sample data). The first teacher you create in step 2 becomes the administrator.</p>
                <button type="submit" class="btn btn-setup">
                    <i class="fas fa-download me-2"></i>Install Database
                </button>
            </form>
            <?php else: ?>
            <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <span><i class="fas fa-check me-2"></i>Database is installed.</span>
                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse"
                    data-bs-target="#resetBox">
                    Reinstall (wipes data)
                </button>
            </div>
            <div class="collapse mb-4" id="resetBox">
                <form method="POST" class="border border-danger rounded p-3">
                    <input type="hidden" name="action" value="install">
                    <p class="text-danger mb-2"><i class="fas fa-exclamation-triangle me-2"></i>
                        This deletes ALL existing data and reimports the schema. Type <strong>RESET</strong> to
                        confirm.</p>
                    <div class="d-flex gap-2">
                        <input type="text" name="confirm_reset" class="form-control" placeholder="Type RESET to confirm">
                        <button type="submit" class="btn btn-danger flex-shrink-0">Wipe &amp; Reinstall</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Step 2: Create first teacher -->
            <h5 class="mb-3 mt-4"><i class="fas fa-user-tie me-2"></i>Step 2: Create Teacher Account</h5>
            <?php if ($locked): ?>
            <p class="text-muted">Locked.</p>
            <?php elseif (!$installed): ?>
            <p class="text-muted">Install the database first.</p>
            <?php else: ?>
            <form method="POST" class="mb-4">
                <input type="hidden" name="action" value="create_teacher">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" required
                            value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password (min 8 characters)</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-setup w-100">
                            <i class="fas fa-user-plus me-2"></i>Create Teacher
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>

            <hr>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="btn btn-outline-primary">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
                <small class="text-muted"><i class="fas fa-shield-alt me-1"></i>Delete <code>setup.php</code> after
                    setup is complete.</small>
            </div>
        </div>
    </div>

    <script src="<?php echo asset('assets/vendor/bootstrap/bootstrap.bundle.min.js'); ?>"></script>
</body>

</html>
