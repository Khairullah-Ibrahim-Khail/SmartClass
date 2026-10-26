<?php
/**
 * SmartClass - migration runner (used by bin/migrate.php, bin/update_all.php, bin/create_customer.php)
 * Works on any PDO connection, so one command can update every customer's database.
 *   migrationFiles()                 config/migrations/*.sql in name order
 *   migrationStatements($sql)        a file split into single statements (no ';' inside strings in our files)
 *   migrateDatabase($pdo, $options)  apply the files this database has not had; ['done' => [...], 'error' => string|null]
 *     options: baseline => '030' (a database that predates tracking: files up to that number count as applied),
 *              dry => true (only list what would run)
 */

function migrationFiles() {
    $files = glob(dirname(__DIR__) . '/config/migrations/*.sql') ?: [];
    sort($files);
    return $files;
}

function migrationStatements($sql) {
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $out = [];
    foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
        $lines = array_filter(array_map('rtrim', explode("\n", $stmt)), fn($l) => trim($l) !== '' && strpos(ltrim($l), '--') !== 0);
        $body = trim(implode("\n", $lines), " \n;");
        if ($body !== '') $out[] = $body;
    }
    return $out;
}

function migrateDatabase(PDO $pdo, array $options = []) {
    $files = migrationFiles();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $have = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $have = array_flip($have);
    // First run on a database that already has everything up to the baseline: record those files without running them
    if (!$have && isset($options['baseline'])) {
        $mark = $pdo->prepare("INSERT IGNORE INTO schema_migrations (name) VALUES (?)");
        foreach ($files as $f) {
            if ((int) basename($f) <= (int) $options['baseline']) { $mark->execute([basename($f)]); $have[basename($f)] = true; }
        }
    }
    $done = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (isset($have[$name])) continue;
        if (!empty($options['dry'])) { $done[] = $name; continue; }
        try {
            foreach (migrationStatements(file_get_contents($f)) as $stmt) $pdo->exec($stmt);
        } catch (Throwable $e) {
            return ['done' => $done, 'error' => "{$name}: " . $e->getMessage()];
        }
        $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")->execute([$name]);
        $done[] = $name;
    }
    return ['done' => $done, 'error' => null];
}
