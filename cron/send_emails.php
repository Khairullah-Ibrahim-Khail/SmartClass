<?php
// SmartClass - email sender (command line only).
//
//   php cron/send_emails.php            send waiting emails (run every 5 minutes)
//   php cron/send_emails.php --digest   build + send the daily summaries (run once a day)
//
// Every run first publishes scheduled stream posts whose time has come (in-app
// notifications too, so this matters even with email turned off).
//
// cPanel -> Cron Jobs:   */5 * * * *  php /path/to/SmartClass/cron/send_emails.php
//                         0 18 * * *   php /path/to/SmartClass/cron/send_emails.php --digest
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/db.php';

// Only one sender at a time (cron overlap protection)
$lock = fopen(sys_get_temp_dir() . '/smartclass_send_emails.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another sender is running.\n";
    exit(0);
}

cronBeat(in_array('--digest', $argv, true) ? 'digest' : 'send_emails');   // heartbeat for Settings › System check
$published = publishDueAnnouncements() + publishDueLectures();
try { $wa = waDailyJobs(); if (array_sum($wa['sent']) || $wa['deadline'] || $wa['todo']) echo date('Y-m-d H:i:s') . ' whatsapp: ' . json_encode($wa) . "\n"; } catch (Throwable $e) { echo 'whatsapp skipped: ' . $e->getMessage() . "\n"; }
if ($published) {
    echo date('Y-m-d H:i:s') . " published {$published} scheduled post(s)\n";
}

$cfg = emailConfig();
if (!$cfg['enabled']) {
    echo "Email notifications are off (Settings › Email Settings).\n";
    exit(0);
}

if (in_array('--digest', $argv, true)) {
    echo 'Daily summaries built: ' . buildDailyDigests() . "\n";
}

$total = ['sent' => 0, 'failed' => 0];
do {
    $r = processEmailQueue(50);
    $total['sent'] += $r['sent'];
    $total['failed'] += $r['failed'];
} while ($r['sent'] + $r['failed'] === 50);

echo date('Y-m-d H:i:s') . " sent {$total['sent']}, failed {$total['failed']}\n";
