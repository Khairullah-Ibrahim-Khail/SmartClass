<?php
/**
 * SmartClass - health and monitoring (loaded by includes/functions.php)
 *   cronBeat($job)            a background job calls this when it runs (stored as setting cron_<job>_last)
 *   cronJobs()                the expected jobs: label, how often, how late is "late", the command to schedule
 *   cronStatus()              per job: ok / late / never, minutes since the last run
 *   healthProblems($days)     recent problems from the PHP error log, failed AI calls and failed e-mails, grouped, newest first
 *   healthSummaryForAdmins()  once a day: tell the school admins (in-app, and by e-mail when e-mail is on) if anything went wrong
 * Shown in Settings › System check. Nothing here changes data except the heartbeat settings.
 */

function cronJobs() {
    return [
        'send_emails'  => ['label' => 'E-mail sender and scheduled posts', 'every' => 'every 5 minutes', 'late_after' => 20 * 60,
                           'cmd' => '*/5 * * * * php ' . dirname(__DIR__) . '/cron/send_emails.php'],
        'digest'       => ['label' => 'Daily e-mail summaries', 'every' => 'once a day', 'late_after' => 36 * 3600,
                           'cmd' => '0 18 * * * php ' . dirname(__DIR__) . '/cron/send_emails.php --digest'],
        'housekeeping' => ['label' => 'Daily clean-up and health summary', 'every' => 'once a day', 'late_after' => 36 * 3600,
                           'cmd' => '30 2 * * * php ' . dirname(__DIR__) . '/cron/housekeeping.php'],
    ];
}

function cronBeat($job) {
    if (isset(cronJobs()[$job])) setSetting('cron_' . $job . '_last', date('c'));
}

function cronStatus() {
    $out = [];
    foreach (cronJobs() as $key => $j) {
        $last = (string) getSetting('cron_' . $key . '_last', '');
        $ts = $last !== '' ? strtotime($last) : 0;
        $age = $ts ? time() - $ts : null;
        $out[$key] = $j + ['last' => $ts ?: null, 'age' => $age, 'state' => $ts === 0 ? 'never' : ($age > $j['late_after'] ? 'late' : 'ok')];
    }
    return $out;
}

/** Recent problems, grouped by message: [['kind', 'level', 'what', 'where', 'count', 'last']] newest first. */
function healthProblems($days = 7, $limit = 30, $log = null) {
    global $db;
    $since = time() - $days * 86400;
    $groups = [];
    // 1. PHP error log (the last 400 KB is enough)
    $log = $log ?? (dirname(__DIR__) . '/logs/php-error.log');
    if (is_readable($log)) {
        $size = filesize($log);
        $fh = fopen($log, 'rb');
        if ($fh) {
            if ($size > 400000) fseek($fh, -400000, SEEK_END);
            $text = stream_get_contents($fh); fclose($fh);
            foreach (preg_split('/\R/', $text) as $line) {
                if (!preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}) [^\]]*\] (PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Error)?:?\s*(.*)$/', $line, $m)) continue;
                $ts = strtotime($m[1]); if (!$ts || $ts < $since) continue;
                $lvl = in_array($m[3], ['Fatal error', 'Parse error', 'Error'], true) ? 'error' : ($m[3] === 'Warning' ? 'warning' : ($m[3] === '' ? 'error' : 'notice'));
                $msg = preg_replace('#/[^ ]*/(pages|includes|config|cron)/#', '$1/', $m[4]);
                $msg = preg_replace('/ on line \d+$/', '', mb_substr($msg, 0, 220));
                $k = 'php|' . $msg;
                if (!isset($groups[$k])) $groups[$k] = ['kind' => 'PHP', 'level' => $lvl, 'what' => $msg, 'count' => 0, 'last' => 0];
                $groups[$k]['count']++; $groups[$k]['last'] = max($groups[$k]['last'], $ts);
            }
        }
    }
    // 2. failed AI calls
    try {
        foreach ($db->fetchAll("SELECT feature, provider, error AS error_message, COUNT(*) n, MAX(created_at) last FROM ai_usage WHERE status = 'error' AND created_at >= ? GROUP BY feature, provider, error ORDER BY last DESC LIMIT 20", [date('Y-m-d H:i:s', $since)]) as $r) {
            $groups['ai|' . $r['feature'] . $r['error_message']] = ['kind' => 'AI', 'level' => 'warning', 'what' => ($r['provider'] ?: 'provider') . ' (' . $r['feature'] . '): ' . mb_substr((string) $r['error_message'], 0, 160), 'count' => (int) $r['n'], 'last' => strtotime($r['last'])];
        }
    } catch (Throwable $e) {}
    // 3. failed e-mails
    try {
        foreach ($db->fetchAll("SELECT last_error, COUNT(*) n, MAX(created_at) last FROM email_queue WHERE status = 'failed' AND created_at >= ? GROUP BY last_error ORDER BY last DESC LIMIT 10", [date('Y-m-d H:i:s', $since)]) as $r) {
            $groups['mail|' . $r['last_error']] = ['kind' => 'E-mail', 'level' => 'warning', 'what' => mb_substr((string) ($r['last_error'] ?: 'sending failed'), 0, 160), 'count' => (int) $r['n'], 'last' => strtotime($r['last'])];
        }
    } catch (Throwable $e) {}
    $order = ['error' => 0, 'warning' => 1, 'notice' => 2];
    uasort($groups, fn($a, $b) => ($order[$a['level']] <=> $order[$b['level']]) ?: ($b['last'] <=> $a['last']));
    return array_slice(array_values($groups), 0, $limit);
}

/** Count of real problems (errors and warnings, not notices) in the last day. */
function healthAlertCount($days = 1) {
    $n = 0;
    foreach (healthProblems($days, 200) as $p) if ($p['level'] !== 'notice') $n += $p['count'];
    return $n;
}

/** Tell every school admin once per day when something went wrong yesterday (in-app; e-mailed too when e-mail is on). Returns how many were told. */
function healthSummaryForAdmins() {
    global $db;
    $n = healthAlertCount(1);
    $late = array_filter(cronStatus(), fn($j) => $j['state'] === 'late');
    if ($n === 0 && !$late) return 0;
    $parts = [];
    if ($n) $parts[] = $n . ' problem' . ($n == 1 ? '' : 's') . ' in the last 24 hours';
    if ($late) $parts[] = count($late) . ' background job' . (count($late) == 1 ? ' is' : 's are') . ' late';
    $told = 0;
    foreach ($db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1") as $a) {
        notify((int) $a['id'], 'system_health', 'System check: ' . implode(', ', $parts), 'Open Settings › System check to see what happened.', 'pages/settings/system.php');
        $told++;
    }
    return $told;
}

/** Move the PHP error log aside (logs/php-error.log -> php-error-YYYYmmdd-HHMMSS.log); the 5 newest archives are kept. Returns the archive name or null. */
function healthArchiveLog() {
    $dir = dirname(__DIR__) . '/logs'; $log = $dir . '/php-error.log';
    if (!is_file($log) || !is_writable($dir) || filesize($log) === 0) return null;
    $to = $dir . '/php-error-' . date('Ymd-His') . '.log';
    if (!@rename($log, $to)) return null;
    @touch($log); @chmod($log, 0666);
    $old = glob($dir . '/php-error-*.log') ?: []; sort($old);
    foreach (array_slice($old, 0, max(0, count($old) - 5)) as $f) @unlink($f);
    return basename($to);
}
