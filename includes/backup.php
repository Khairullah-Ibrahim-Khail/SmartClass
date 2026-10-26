<?php
/**
 * SmartClass - database backup and restore (loaded by includes/functions.php)
 *
 *   backupWrite($path)              write a complete backup (gzip SQL) of every table to a file
 *   backupParse($sql)               check an uploaded backup and split it into statements ([stmts, tables] or an error string)
 *   backupRestore($sql, $tables)    replace the data with a backup; takes a safety backup first and puts it back if anything fails
 *   backupFiles() / backupPrune()   the safety backups kept in backups/ (newest BACKUP_KEEP are kept)
 *
 * The file is plain SQL written by backupWrite(): one statement per block, blocks separated by a
 * "-- @@" line, strings with line breaks escaped so a row never spans lines. There is no USE / CREATE
 * DATABASE in it, and a restore only runs statements of exactly these kinds, on tables that exist in
 * this installation: SET FOREIGN_KEY_CHECKS / SET NAMES, DROP TABLE IF EXISTS `t`, CREATE TABLE `t`,
 * INSERT INTO `t`. Anything else in an uploaded file makes the restore refuse before touching the data.
 */

const BACKUP_MARK = '-- SmartClass backup v1';
const BACKUP_SEP = "\n-- @@\n";
const BACKUP_KEEP = 5;
const BACKUP_MAX_BYTES = 200 * 1024 * 1024;   // decompressed size limit

function backupDir() {
    return STORAGE_ROOT . '/backups';
}

/** Tables of this installation (base tables only). */
function backupTables(?PDO $pdo = null) {
    $pdo = $pdo ?? $GLOBALS['db']->getConnection();
    return array_map(fn($r) => array_values($r)[0], $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_ASSOC));
}

/** Tables this version of SmartClass knows: the ones in the database now plus those the install file creates (so a table that went missing can be restored). */
function backupKnownTables() {
    $names = backupTables();
    $schema = dirname(__DIR__) . '/config/smartclass_db.sql';
    if (is_readable($schema) && preg_match_all('/^CREATE TABLE `?([a-z0-9_]+)`?/mi', (string) file_get_contents($schema), $m)) $names = array_merge($names, $m[1]);
    return array_flip($names);
}

/** A value as an SQL literal that stays on one line. */
function backupLiteral(PDO $pdo, $v) {
    if ($v === null) return 'NULL';
    $v = (string) $v;
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) return '0x' . bin2hex($v);   // binary data
    return str_replace(["\r", "\n", "\0", "\x1a"], ['\\r', '\\n', '\\0', '\\Z'], $pdo->quote($v));
}

/** Write a full backup to $path (gzip). Returns the number of rows saved. */
function backupWrite($path, ?PDO $pdo = null) {
    $pdo = $pdo ?? $GLOBALS['db']->getConnection();
    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException('Could not create the backup file');
    $rows_total = 0;
    $w = fn($s) => gzwrite($gz, $s . BACKUP_SEP);
    gzwrite($gz, BACKUP_MARK . ' | ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . ' | ' . date('Y-m-d H:i:s') . BACKUP_SEP);
    $w('SET NAMES utf8mb4');
    $w('SET FOREIGN_KEY_CHECKS = 0');
    foreach (backupTables($pdo) as $t) {
        $w("DROP TABLE IF EXISTS `{$t}`");
        $create = array_values($pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC))[1];
        $w($create);
        $cols = null;
        $batch = [];
        $flush = function () use (&$batch, &$cols, $t, $w) {
            if ($batch) { $w("INSERT INTO `{$t}` ({$cols}) VALUES " . implode(',', $batch)); $batch = []; }
        };
        $stmt = $pdo->query("SELECT * FROM `{$t}`", PDO::FETCH_ASSOC);
        foreach ($stmt as $row) {
            if ($cols === null) $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($row)));
            $batch[] = '(' . implode(',', array_map(fn($v) => backupLiteral($pdo, $v), $row)) . ')';
            $rows_total++;
            if (count($batch) >= 100) $flush();
        }
        $flush();
    }
    $w('SET FOREIGN_KEY_CHECKS = 1');
    gzwrite($gz, '-- end of backup' . BACKUP_SEP);
    gzclose($gz);
    return $rows_total;
}

/** Plain text of an uploaded backup (.sql or .gz), or an error string. */
function backupRead($path) {
    $head = (string) file_get_contents($path, false, null, 0, 2);
    if ($head === "\x1f\x8b") {
        $gz = gzopen($path, 'rb');
        if (!$gz) return 'That file could not be opened';
        $sql = '';
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 1 << 20);
            if (strlen($sql) > BACKUP_MAX_BYTES) { gzclose($gz); return 'The backup is too large to restore here'; }
        }
        gzclose($gz);
        return $sql;
    }
    if (filesize($path) > BACKUP_MAX_BYTES) return 'The backup is too large to restore here';
    return (string) file_get_contents($path);
}

/** The statement with every quoted string and `identifier` removed — what is left must hold no ';'. */
function backupSkeleton($stmt) {
    return preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|`[^`]*`/s", '?', $stmt);
}

/** @return array|string [statements, tables in the file] or an error message */
function backupParse($sql, $own = false) {
    if (strncmp($sql, BACKUP_MARK, strlen(BACKUP_MARK)) !== 0) return 'This is not a SmartClass backup file';
    $live = $own ? null : backupKnownTables();   // $own: our own safety backup — its tables are known good even if a failed restore dropped them
    $parts = explode(BACKUP_SEP, $sql);
    $stmts = [];
    $tables = [];
    foreach ($parts as $i => $p) {
        $p = trim($p);
        if ($i === 0 || $p === '' || $p === '-- end of backup') continue;
        if (preg_match('/^SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS = [01])$/', $p)) { $stmts[] = $p; continue; }
        if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([a-z0-9_]{1,64})`/', $p, $m)) {
            if ($live !== null && !isset($live[$m[2]])) return "The backup has a table (“{$m[2]}”) that this installation does not have — it comes from a different version";
            // exactly ONE statement: nothing after a DROP's table name, and no ';' outside quoted text anywhere
            if ($m[1] === 'DROP TABLE IF EXISTS' && trim(substr($p, strlen($m[0]))) !== '') return 'The backup file is damaged (unexpected SQL)';
            if (strpos(backupSkeleton($p), ';') !== false) return 'The backup file is damaged (unexpected SQL)';
            $tables[$m[2]] = true;
            $stmts[] = $p;
            continue;
        }
        return 'The backup file contains something that is not a backup statement — it was not restored';
    }
    if (!$tables) return 'The backup file is empty';
    return [$stmts, array_keys($tables)];
}

/** Run parsed statements. Returns null or the error text. */
function backupRun(array $stmts) {
    global $db;
    $pdo = $db->getConnection();
    try {
        try { $pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, false); } catch (Throwable $e) {}   // belt and braces: one statement per call
        foreach ($stmts as $s) $pdo->exec($s);
        return null;
    } catch (Throwable $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $x) {}
        return $e->getMessage();
    }
}

/**
 * Replace the data with a parsed backup. A safety backup of the current data is written first; if
 * the restore fails half-way the safety backup is put back. Returns ['ok' => bool, 'message', 'safety' => file name].
 */
function backupRestore(array $stmts) {
    $dir = backupDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return ['ok' => false, 'message' => 'The backups folder cannot be created'];
    $safety = $dir . '/before-restore-' . date('Ymd-His') . '.sql.gz';
    try {
        backupWrite($safety);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not save a safety backup first (' . $e->getMessage() . ') — nothing was changed'];
    }
    $err = backupRun($stmts);
    if ($err === null) {
        backupPrune();
        return ['ok' => true, 'message' => 'Restored', 'safety' => basename($safety)];
    }
    // Put the data back from the safety backup
    $parsed = backupParse((string) backupRead($safety), true);
    $back = is_array($parsed) ? backupRun($parsed[0]) : 'safety backup unreadable';
    return ['ok' => false, 'safety' => basename($safety),
            'message' => 'The restore failed (' . mb_strimwidth($err, 0, 160, '…') . ').' . ($back === null ? ' Your data was put back as it was.' : ' Putting the data back failed too — restore ' . basename($safety) . ' from the backups folder.')];
}

/** Safety backups kept on the server, newest first: [['name', 'size', 'time']]. */
function backupFiles() {
    $out = [];
    foreach (glob(backupDir() . '/before-restore-*.sql.gz') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function backupPrune() {
    foreach (array_slice(backupFiles(), BACKUP_KEEP) as $f) @unlink(backupDir() . '/' . $f['name']);
}
