<?php
// SmartClass - daily clean-up and health summary (command line only).
//   php cron/housekeeping.php        run once a day, e.g.   30 2 * * *  php /path/to/SmartClass/cron/housekeeping.php
// What it does (each part is safe to run again):
//   - records that it ran (Settings › System check shows "last run")
//   - tells the school admins if something went wrong in the last 24 hours or a background job is late
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/db.php';
$lock = fopen(sys_get_temp_dir() . '/smartclass_housekeeping.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Another housekeeping run is going.\n"; exit(0); }
cronBeat('housekeeping');
echo date('Y-m-d H:i:s') . ' health: told ' . healthSummaryForAdmins() . " admin(s)\n";
if (is_file(dirname(__DIR__) . '/logs/php-error.log') && filesize(dirname(__DIR__) . '/logs/php-error.log') > 2 * 1024 * 1024) echo 'error log archived as ' . (healthArchiveLog() ?? '(could not)') . "\n";
supportRequestsClean();
try { echo 'research reminders: ' . json_encode(researchReminders()) . "\n"; } catch (Throwable $e) { echo "research reminders skipped: " . $e->getMessage() . "\n"; }
$ret = retentionRun(false);
echo 'retention: ' . json_encode($ret) . "\n";
echo 'rate-limit rows removed: ' . rateCleanup() . "\n";
