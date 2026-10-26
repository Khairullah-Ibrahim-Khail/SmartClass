<?php
// Update every customer after you changed the code: for each database — maintenance page on, backup, apply new migrations,
// maintenance page off. A customer where something fails stays on its backup state and is reported; the others go on.
//   php bin/update_all.php            (--dry shows what would run)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
require_once dirname(__DIR__) . '/includes/migrator.php';
define('STORAGE_ROOT', dirname(__DIR__)); define('SC_IS_MASTER', true);
require_once dirname(__DIR__) . '/includes/backup.php';
$dry = in_array('--dry', $argv, true);
$dir = dirname(__DIR__) . '/backups/updates/' . date('Ymd-His');
if (!$dry) {
    @mkdir($dir, 0770, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        // The project's backups/ folder belongs to the web server user; when this runs as another user (your own login) the
        // backups go to a private folder in that user's home instead — the update never runs without a backup.
        $home = rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/');
        $dir = $home . '/smartclass-backups/updates/' . date('Ymd-His');
        @mkdir($dir, 0700, true);
        if (!is_dir($dir) || !is_writable($dir)) exit("No writable place for the backups (tried backups/updates and {$home}/smartclass-backups). Run this as the web server user:  sudo -u www-data php bin/update_all.php\n");
        echo "Backups go to {$dir} (the project's backups/ folder is not writable for this user).\n";
    } else {
        @file_put_contents(dirname($dir) . '/.htaccess', "Require all denied\n");
    }
}
$fails = 0;
$targets = ['(original system)' => instanceMasterConfig()] + array_map(fn($c) => $c, instanceConfigs());
foreach ($targets as $label => $cfg) {
    $flag = $cfg['slug'] === 'default' ? null : dirname(__DIR__) . '/instances/' . $cfg['slug'] . '/maintenance.flag';
    try {
        $pdo = instancePdo($cfg);
        $todo = migrateDatabase($pdo, ['dry' => true, 'baseline' => '030']);
        if (!$todo['done']) { echo "up to date  {$label}\n"; continue; }
        if ($dry) { echo "would apply  {$label}: " . implode(', ', $todo['done']) . "\n"; continue; }
        if ($flag && @file_put_contents($flag, date('c')) === false) { $flag = null; echo "note: could not switch the maintenance page on for {$label} (folder not writable) — updating anyway\n"; }
        backupWrite("{$dir}/{$cfg['slug']}.sql.gz", $pdo);
        $r = migrateDatabase($pdo, ['baseline' => '030']);
        if ($flag) @unlink($flag);
        if ($r['error']) { $fails++; echo "FAILED      {$label}: {$r['error']}  (backup: {$dir}/{$cfg['slug']}.sql.gz — restore it from Settings › Backup if needed)\n"; }
        else echo "updated     {$label}: " . implode(', ', $r['done']) . "\n";
    } catch (Throwable $e) { if ($flag) @unlink($flag); $fails++; echo "FAILED      {$label}: " . $e->getMessage() . "\n"; }
}
exit($fails ? 1 : 0);
