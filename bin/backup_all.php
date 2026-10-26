<?php
// Back up the original system and every customer into backups/all/<date>/ (gzip SQL, one file each; keeps the newest 14 days).
//   php bin/backup_all.php            (put it in cron: 0 3 * * *  php /path/bin/backup_all.php)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
define('STORAGE_ROOT', dirname(__DIR__)); define('SC_IS_MASTER', true);
require_once dirname(__DIR__) . '/includes/backup.php';
$dir = dirname(__DIR__) . '/backups/all/' . date('Ymd-His');
if (!mkdir($dir, 0770, true)) { fwrite(STDERR, "Cannot create {$dir}\n"); exit(1); }
file_put_contents(dirname($dir) . '/.htaccess', "Require all denied\n");
$bad = 0;
foreach (array_merge([instanceMasterConfig()], array_values(instanceConfigs())) as $cfg) {
    $name = $cfg['slug'];
    try { backupWrite("{$dir}/{$name}.sql.gz", instancePdo($cfg)); echo "OK   {$name}\n"; }
    catch (Throwable $e) { $bad++; echo "FAIL {$name}: " . $e->getMessage() . "\n"; }
}
foreach (glob(dirname($dir) . '/*', GLOB_ONLYDIR) ?: [] as $old) {   // keep 14 days
    if (filemtime($old) < time() - 14 * 86400) { array_map('unlink', glob("{$old}/*")); @rmdir($old); }
}
exit($bad ? 1 : 0);
