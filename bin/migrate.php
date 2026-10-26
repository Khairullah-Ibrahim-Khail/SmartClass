<?php
// Apply the migrations a database has not had yet.
//   php bin/migrate.php                  the original system's database
//   php bin/migrate.php customer.com     one customer (config/instances/customer.com.php)
//   php bin/migrate.php --dry            list what would run, change nothing
// A database that predates migration tracking is treated as being at migration 030 (baseline).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/migrator.php';
require_once dirname(__DIR__) . '/includes/instances.php';
$dry = in_array('--dry', $argv, true);
$target = null;
foreach (array_slice($argv, 1) as $a) if ($a[0] !== '-') $target = $a;
$cfg = $target ? instanceConfig($target) : instanceMasterConfig();
if (!$cfg) { fwrite(STDERR, "No such customer: {$target}\n"); exit(1); }
$r = migrateDatabase(instancePdo($cfg), ['dry' => $dry, 'baseline' => '030']);
echo ($dry ? 'Would apply: ' : 'Applied: ') . ($r['done'] ? implode(', ', $r['done']) : 'nothing (up to date)') . "\n";
if ($r['error']) { fwrite(STDERR, "FAILED: {$r['error']}\n"); exit(1); }
