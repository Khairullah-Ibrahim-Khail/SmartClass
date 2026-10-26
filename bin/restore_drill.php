<?php
// Restore drill (command line): proves that a backup of the LIVE database can really be restored.
//   php bin/restore_drill.php            back up the live database, restore it into a throw-away database, compare every table, drop it
//   php bin/restore_drill.php --keep     leave the throw-away database in place (name is printed) for a look around
// Only reads the live database. The throw-away database is called sc_drill_<random>; nothing is executed before the connection
// is checked to be that database. Exit code 0 = every table has the same number of rows and the same checksum.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
define('STORAGE_ROOT', dirname(__DIR__)); define('SC_IS_MASTER', true);
require_once dirname(__DIR__) . '/includes/backup.php';
$keep = in_array('--keep', $argv, true);
$cfg = instanceMasterConfig();
$live = instancePdo($cfg);
$name = 'sc_drill_' . bin2hex(random_bytes(4));
$file = sys_get_temp_dir() . '/' . $name . '.sql.gz';
$t0 = microtime(true);
$ok = false;
try {
    $rows = backupWrite($file, $live);
    echo "1. backup written: " . number_format($rows) . " rows, " . number_format(filesize($file) / 1024, 1) . " KB\n";
    $live->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");
    $drill = new PDO("mysql:host={$cfg['db_host']};dbname={$name};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($drill->query('SELECT DATABASE()')->fetchColumn() !== $name || strpos($name, 'sc_drill_') !== 0) throw new RuntimeException('Not connected to the throw-away database — stopping');
    $sql = backupRead($file);
    $parsed = backupParse($sql, true);
    if (!is_array($parsed)) throw new RuntimeException('Backup could not be read back: ' . $parsed);
    foreach ($parsed[0] as $s) $drill->exec($s);
    echo "2. restored into {$name}: " . count($parsed[1]) . " tables\n";
    $bad = 0;
    $tables = backupTables($live);
    foreach ($tables as $t) {
        $a = (int) $live->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        try { $b = (int) $drill->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); } catch (Throwable $e) { $b = -1; }
        $ca = $live->query("CHECKSUM TABLE `{$t}`")->fetch()['Checksum'] ?? null;
        $cb = null; try { $cb = $drill->query("CHECKSUM TABLE `{$t}`")->fetch()['Checksum'] ?? null; } catch (Throwable $e) {}
        if ($a !== $b || (string) $ca !== (string) $cb) { $bad++; echo "   MISMATCH {$t}: live {$a} rows / restored {$b} rows, checksum " . var_export($ca, true) . ' vs ' . var_export($cb, true) . "\n"; }
    }
    $fk_live = (int) $live->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_TYPE='FOREIGN KEY' AND TABLE_SCHEMA = DATABASE()")->fetchColumn();
    $fk_drill = (int) $drill->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_TYPE='FOREIGN KEY' AND TABLE_SCHEMA = DATABASE()")->fetchColumn();
    if ($fk_live !== $fk_drill) { $bad++; echo "   MISMATCH foreign keys: live {$fk_live} / restored {$fk_drill}\n"; }
    echo "3. compared " . count($tables) . " tables (rows + checksum) and {$fk_live} foreign keys: " . ($bad ? "{$bad} problem(s)" : 'all identical') . "\n";
    $ok = $bad === 0;
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
} finally {
    @unlink($file);
    if (!$keep) { try { $live->exec("DROP DATABASE IF EXISTS `{$name}`"); echo "4. throw-away database dropped\n"; } catch (Throwable $e) { echo "could not drop {$name}: " . $e->getMessage() . "\n"; } }
    else echo "4. kept: {$name}\n";
}
printf("restore drill %s in %.1f s\n", $ok ? 'PASSED' : 'FAILED', microtime(true) - $t0);
exit($ok ? 0 : 1);
