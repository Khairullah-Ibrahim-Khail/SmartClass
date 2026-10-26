<?php
/**
 * SmartClass - managing customer installations (used by the Customers page and bin/*.php; original system only)
 *   customerCreate($o)        new database + login, clean schema, first admin, storage, config, Customers row (rolls back on failure)
 *   customerDelete($domain)   final backup into instances/_archive/, then drop the database and login, remove config + storage
 *   customerStats($cfg)       counts, last activity, terms, disk use of one customer (read from its own database)
 *   customerSetStatus / customerSetServices   suspend, resume, switch services (kept in the provider's database)
 *   customerSupportLink()     a one-time link that signs the provider in to the customer's admin side for a limited time
 *   customerLog()             the provider's own record of what happened
 * Everything here talks to databases with the provider's (root) login and reads config/instances/*.php, so it only
 * runs in the original system (SC_IS_MASTER) or from the command line.
 */
require_once __DIR__ . '/instances.php';
require_once __DIR__ . '/migrator.php';




function customerRootPdo() {
    $m = instanceMasterConfig();
    return instancePdo($m);
}

/** Remove a folder tree of THIS app's instances/ folder only (guarded: the path must be inside instances/ and not be it). */
function removeTree($dir) {
    $base = realpath(dirname(__DIR__) . '/instances');
    $real = realpath($dir);
    if (!$base || !$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return false;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    return @rmdir($real);
}

function customerLog(PDO $root, $customer_id, $name, $event, $details = null, $user_id = null) {
    $root->prepare("INSERT INTO customer_events (customer_id, customer_name, event, details, created_by) VALUES (?, ?, ?, ?, ?)")
         ->execute([$customer_id, $name, $event, $details !== null ? mb_substr($details, 0, 500) : null, $user_id]);
}

/** Options: name, domain, admin_email, admin_name?, password?, base_path?, contact?. Returns the facts the caller prints. */
function customerCreate(array $o) {
    $name = trim((string) $o['name']);
    $domain = strtolower(trim((string) $o['domain']));
    $email = strtolower(trim((string) $o['admin_email']));
    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Give the customer a name (up to 120 characters).');
    if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,180}[a-z0-9])?$/', $domain) || strpos($domain, '..') !== false) throw new InvalidArgumentException('That domain is not valid.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('That admin email is not valid.');
    $tempPass = (string) ($o['password'] ?? '') !== '' ? (string) $o['password'] : substr(bin2hex(random_bytes(8)), 0, 12);
    if (strlen($tempPass) < 8) throw new InvalidArgumentException('The password must be at least 8 characters.');
    if (instanceConfig($domain)) throw new InvalidArgumentException('A customer with this domain already exists.');

    $master = instanceMasterConfig();
    $root = customerRootPdo();
    $slug = instanceSlug($domain);
    $dbName = 'sc_' . substr($slug, 0, 40);
    $dbUser = 'sc_' . substr($slug, 0, 20) . '_' . bin2hex(random_bytes(2));
    $dbPass = bin2hex(random_bytes(16));
    $q = $root->prepare("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?"); $q->execute([$dbName]);
    if ($q->fetch()) throw new InvalidArgumentException("The database {$dbName} already exists — pick another domain or delete it first.");
    $q = $root->prepare("SELECT 1 FROM customers WHERE domain = ? OR slug = ?"); $q->execute([$domain, $slug]);
    if ($q->fetch()) throw new InvalidArgumentException('This domain is already on the Customers page.');

    $root_dir = dirname(__DIR__);
    $storage = $root_dir . '/instances/' . $slug;
    $cfgFile = instanceConfigDir() . '/' . $domain . '.php';
    // The web server (or the user running this) must be able to write here — say so before anything is created
    foreach ([instanceConfigDir(), is_dir($storage) ? $storage : $root_dir . '/instances'] as $must) {
        if (!is_dir($must) || !is_writable($must)) throw new InvalidArgumentException("This server cannot write in {$must}. On the local Apache run: sh bin/fix_local_apache.sh (once); on a real server: sudo chown -R www-data:www-data config/instances instances.");
    }
    $storage_existed = is_dir($storage);
    $made = ['db' => false, 'user' => false, 'cfg' => false, 'row' => false, 'dir' => false];
    try {
        $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $made['db'] = true;
        $root->exec("CREATE USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'"); $made['user'] = true;
        $root->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");

        $pdo = instancePdo(['db_host' => $master['db_host'], 'db_name' => $dbName, 'db_user' => $master['db_user'], 'db_pass' => $master['db_pass']]);
        foreach (migrationStatements(file_get_contents($root_dir . '/config/install_schema.sql')) as $stmt) $pdo->exec($stmt);
        $r = migrateDatabase($pdo, ['baseline' => '999']);
        if ($r['error']) throw new RuntimeException($r['error']);

        $pdo->prepare("INSERT INTO users (name, email, password, role, is_admin, is_approved, is_active) VALUES (?, ?, ?, 'teacher', 1, 1, 1)")
            ->execute([(string) ($o['admin_name'] ?? '') !== '' ? $o['admin_name'] : 'School Admin', $email, password_hash($tempPass, PASSWORD_DEFAULT)]);
        $admin_id = (string) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('school_name', ?), ('must_change_password_user', ?)")->execute([$name, $admin_id]);

        foreach (['', '/uploads', '/sessions', '/backups', '/cache'] as $sub) {
            if (!is_dir($storage . $sub) && !mkdir($storage . $sub, 0777, true)) throw new RuntimeException("Cannot create {$storage}{$sub}");
            @chmod($storage . $sub, 0777);
        }
        $made['dir'] = true;
        if (!is_file($root_dir . '/instances/.htaccess')) file_put_contents($root_dir . '/instances/.htaccess', "Require all denied\n");

        $provFile = $root_dir . '/config/provider.php';
        if (!is_file($provFile)) {
            file_put_contents($provFile, "<?php\nreturn " . var_export(['status_user' => 'sc_status', 'status_pass' => bin2hex(random_bytes(12))], true) . ";\n");
            @chmod($provFile, 0640);
        }
        $prov = require $provFile;
        $root->exec("CREATE USER IF NOT EXISTS '{$prov['status_user']}'@'localhost' IDENTIFIED BY '{$prov['status_pass']}'");
        $root->exec("GRANT SELECT (domain, status, suspended_reason, services_off, max_teachers, max_students, max_classes, last_seen_at), UPDATE (last_seen_at) ON `{$master['db_name']}`.customers TO '{$prov['status_user']}'@'localhost'");
        $secret = bin2hex(random_bytes(32));
        $root->prepare("INSERT INTO customers (name, domain, slug, db_name, db_user, contact, support_secret) VALUES (?, ?, ?, ?, ?, ?, ?)")
             ->execute([$name, $domain, $slug, $dbName, $dbUser, (string) ($o['contact'] ?? '') !== '' ? $o['contact'] : $email, $secret]);
        $cid = (int) $root->lastInsertId(); $made['row'] = true;
        customerLog($root, $cid, $name, 'created', "Domain {$domain}, database {$dbName}", $o['by'] ?? null);

        $cfg = ['slug' => $slug, 'domain' => $domain, 'db_host' => $master['db_host'], 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass,
                'base_path' => (string) ($o['base_path'] ?? ''), 'support_secret' => $secret,
                'master' => ['host' => $master['db_host'], 'db' => $master['db_name'], 'user' => $prov['status_user'], 'pass' => $prov['status_pass']]];
        file_put_contents($cfgFile, "<?php\n// Customer: {$name} — written by customerCreate()\nreturn " . var_export($cfg, true) . ";\n");
        @chmod($cfgFile, 0644); $made['cfg'] = true;
    } catch (Throwable $e) {
        if ($made['row']) $root->prepare("DELETE FROM customers WHERE domain = ?")->execute([$domain]);
        if ($made['user']) $root->exec("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
        if ($made['db']) $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        if ($made['cfg'] && is_file($cfgFile)) @unlink($cfgFile);
        if ($made['dir'] && !$storage_existed) removeTree($storage);   // the empty folders made for this attempt
        throw $e;
    }
    return ['id' => $cid, 'name' => $name, 'domain' => $domain, 'slug' => $slug, 'db_name' => $dbName, 'db_user' => $dbUser, 'admin_email' => $email, 'password' => $tempPass, 'storage' => $storage];
}

function customerDelete($domain, $by = null) {
    $cfg = instanceConfig($domain);
    if (!$cfg) throw new InvalidArgumentException('No such customer.');
    $root = customerRootPdo();
    $row = $root->prepare("SELECT id, name FROM customers WHERE domain = ?"); $row->execute([$cfg['domain']]); $row = $row->fetch();
    $root_dir = dirname(__DIR__);
    $archive = $root_dir . '/instances/_archive';
    if (!is_dir($archive)) mkdir($archive, 0770, true);
    file_put_contents($archive . '/.htaccess', "Require all denied\n");
    require_once __DIR__ . '/backup.php';
    $stamp = date('Ymd-His');
    $dump = "{$archive}/{$cfg['slug']}-{$stamp}.sql.gz";
    backupWrite($dump, instancePdo($cfg));                     // the final copy is made first; nothing is deleted if it fails
    $root->exec("DROP DATABASE IF EXISTS `{$cfg['db_name']}`");
    $root->exec("DROP USER IF EXISTS '{$cfg['db_user']}'@'localhost'");
    $store = $root_dir . '/instances/' . $cfg['slug'];
    if (is_dir($store)) @rename($store, "{$archive}/{$cfg['slug']}-{$stamp}-files");
    @unlink(instanceConfigDir() . '/' . $cfg['domain'] . '.php');
    $root->prepare("DELETE FROM customers WHERE domain = ?")->execute([$cfg['domain']]);
    customerLog($root, $row['id'] ?? null, $row['name'] ?? $cfg['domain'], 'deleted', "Final backup: instances/_archive/" . basename($dump), $by);
    return $dump;
}

function dirSize($dir) {
    $n = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) $n += $f->getSize(); }
    return $n;
}

function customerStats(array $cfg) {
    $out = ['ok' => false, 'teachers' => null, 'students' => null, 'classes' => null, 'last_activity' => null, 'terms_at' => null, 'disk' => dirSize(dirname(__DIR__) . '/instances/' . $cfg['slug']), 'error' => null];
    try {
        $pdo = instancePdo($cfg);
        $one = fn($sql) => $pdo->query($sql)->fetchColumn();
        $out['teachers'] = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND is_active = 1");
        $out['students'] = (int) $one("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1");
        $out['classes'] = (int) $one("SELECT COUNT(*) FROM classes WHERE is_active = 1");
        $out['last_activity'] = $one("SELECT MAX(created_at) FROM activity_log");
        $out['terms_at'] = $one("SELECT setting_value FROM app_settings WHERE setting_key = 'terms_accepted_at'") ?: null;
        $out['ok'] = true;
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    return $out;
}

function customerClearCache(array $cfg) { @unlink(dirname(__DIR__) . '/instances/' . $cfg['slug'] . '/cache/status.json'); }

function customerSetStatus(PDO $root, array $row, $status, $reason, $by) {
    $root->prepare("UPDATE customers SET status = ?, suspended_reason = ?, suspended_at = ? WHERE id = ?")
         ->execute([$status, $status === 'suspended' ? mb_substr($reason, 0, 255) : null, $status === 'suspended' ? date('Y-m-d H:i:s') : null, $row['id']]);
    customerLog($root, $row['id'], $row['name'], $status === 'suspended' ? 'suspended' : 'resumed', $status === 'suspended' ? $reason : null, $by);
    if ($cfg = instanceConfig($row['domain'])) customerClearCache($cfg);
}

function customerSetServices(PDO $root, array $row, array $off, $by) {
    $off = array_values(array_intersect(array_keys(SC_SERVICES), $off));
    $root->prepare("UPDATE customers SET services_off = ? WHERE id = ?")->execute([implode(',', $off), $row['id']]);
    customerLog($root, $row['id'], $row['name'], 'services', $off ? 'Switched off: ' . implode(', ', $off) : 'All services on', $by);
    if ($cfg = instanceConfig($row['domain'])) customerClearCache($cfg);
}

/** Limits: null / '' = none. Values are whole numbers 1..100000. */
function customerSetLimits(PDO $root, array $row, array $limits, $by) {
    $clean = [];
    foreach (['teachers', 'students', 'classes'] as $k) {
        $v = trim((string) ($limits[$k] ?? ''));
        $clean[$k] = ($v !== '' && ctype_digit($v) && (int) $v >= 1 && (int) $v <= 100000) ? (int) $v : null;
    }
    $root->prepare("UPDATE customers SET max_teachers = ?, max_students = ?, max_classes = ? WHERE id = ?")->execute([$clean['teachers'], $clean['students'], $clean['classes'], $row['id']]);
    customerLog($root, $row['id'], $row['name'], 'limits', 'Teachers ' . ($clean['teachers'] ?? '∞') . ', students ' . ($clean['students'] ?? '∞') . ', classes ' . ($clean['classes'] ?? '∞'), $by);
    if ($cfg = instanceConfig($row['domain'])) customerClearCache($cfg);
}

/** Billing notes (plan, price, paid until, a note) — only written down here; the system never charges anyone. Returns the clean values. */
function customerSetBilling(PDO $root, array $row, array $in, $by) {
    $plan = mb_substr(trim((string) ($in['plan'] ?? '')), 0, 60); $price = mb_substr(trim((string) ($in['price'] ?? '')), 0, 40);
    $note = mb_substr(trim((string) ($in['billing_note'] ?? '')), 0, 500); $until = trim((string) ($in['paid_until'] ?? ''));
    $d = $until !== '' ? DateTime::createFromFormat('Y-m-d', $until) : null;
    $until = ($d && $d->format('Y-m-d') === $until) ? $until : null;
    $root->prepare("UPDATE customers SET plan = ?, price = ?, paid_until = ?, billing_note = ? WHERE id = ?")->execute([$plan ?: null, $price ?: null, $until, $note ?: null, $row['id']]);
    customerLog($root, $row['id'], $row['name'], 'billing', ($plan ?: 'no plan') . ', paid until ' . ($until ?: '—'), $by);
    return ['plan' => $plan, 'price' => $price, 'paid_until' => $until, 'billing_note' => $note];
}

/** 'none' (no date), 'ok', 'soon' (within 14 days) or 'overdue' for a paid-until date. */
function customerPaidState($paidUntil, $today = null) {
    if (!$paidUntil) return 'none';
    $today = $today ?: date('Y-m-d');
    if ($paidUntil < $today) return 'overdue';
    return (strtotime($paidUntil) - strtotime($today)) <= 14 * 86400 ? 'soon' : 'ok';
}

/** A signed one-time link: payload = slug|expires|nonce|reason (base64url) + HMAC with the customer's support secret. */
function customerSupportLink(array $row, $reason, $https = true) {
    $cfg = instanceConfig($row['domain']);
    if (!$cfg) throw new InvalidArgumentException('No such customer.');
    $payload = rtrim(strtr(base64_encode(json_encode(['s' => $cfg['slug'], 'e' => time() + SUPPORT_LINK_MINUTES * 60, 'n' => bin2hex(random_bytes(8)), 'r' => mb_substr($reason, 0, 200)])), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $payload, $cfg['support_secret']);
    $root = !empty($cfg['public_url']) ? rtrim($cfg['public_url'], '/') : ($https ? 'https://' : 'http://') . $cfg['domain'];
    return $root . ($cfg['base_path'] ?? '') . '/pages/auth/support.php?t=' . $payload . '.' . $sig;
}
