<?php
/**
 * SmartClass - rate limits (loaded by includes/functions.php)
 * Counters in the table rate_limits: "at most $max things per $seconds for this key".
 *   rateHit($bucket, $key, $seconds)            count one more (starts a new window when the old one is over); returns the count in the window
 *   rateCount($bucket, $key, $seconds)          the count in the current window without counting
 *   rateBlocked($bucket, $key, $max, $seconds)  [true, secondsToWait] when the limit is reached, else [false, 0]
 *   rateReset($bucket, $key)                    forget (a successful sign-in clears the account's failures)
 *   rateGuard($bucket, $key, $max, $seconds)    count this attempt and tell whether it may go on: ['ok' => bool, 'wait' => seconds]
 *   clientIp()                                  the visitor's address (REMOTE_ADDR — forwarded headers are not trusted)
 * Keys are stored hashed. Old rows are removed by cron/housekeeping.php (rateCleanup()).
 */

function clientIp() { return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

function rateKey($key) { return hash('sha256', mb_strtolower((string) $key)); }

function rateHit($bucket, $key, $seconds) {
    global $db;
    $k = rateKey($key); $seconds = max(1, (int) $seconds);
    try {
        $db->query("INSERT INTO rate_limits (bucket, rkey, hits, window_start) VALUES (?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE hits = IF(window_start < NOW() - INTERVAL {$seconds} SECOND, 1, hits + 1),
                                            window_start = IF(window_start < NOW() - INTERVAL {$seconds} SECOND, NOW(), window_start)", [$bucket, $k]);
        return (int) $db->fetchOne("SELECT hits FROM rate_limits WHERE bucket = ? AND rkey = ?", [$bucket, $k])['hits'];
    } catch (Throwable $e) { error_log('SmartClass: rate limit unavailable: ' . $e->getMessage()); return 0; }   // never lock people out because the counter table is missing
}

function rateCount($bucket, $key, $seconds) {
    global $db;
    try {
        $r = $db->fetchOne("SELECT hits FROM rate_limits WHERE bucket = ? AND rkey = ? AND window_start >= NOW() - INTERVAL " . max(1, (int) $seconds) . " SECOND", [$bucket, rateKey($key)]);
        return $r ? (int) $r['hits'] : 0;
    } catch (Throwable $e) { return 0; }
}

function rateBlocked($bucket, $key, $max, $seconds) {
    global $db;
    if (rateCount($bucket, $key, $seconds) < $max) return [false, 0];
    try {
        $r = $db->fetchOne("SELECT GREATEST(1, ? - TIMESTAMPDIFF(SECOND, window_start, NOW())) AS w FROM rate_limits WHERE bucket = ? AND rkey = ?", [(int) $seconds, $bucket, rateKey($key)]);
        return [true, (int) ($r['w'] ?? $seconds)];
    } catch (Throwable $e) { return [false, 0]; }
}

function rateReset($bucket, $key) {
    global $db;
    try { $db->query("DELETE FROM rate_limits WHERE bucket = ? AND rkey = ?", [$bucket, rateKey($key)]); } catch (Throwable $e) {}
}

function rateGuard($bucket, $key, $max, $seconds) {
    [$blocked, $wait] = rateBlocked($bucket, $key, $max, $seconds);
    if ($blocked) return ['ok' => false, 'wait' => $wait];
    rateHit($bucket, $key, $seconds);
    return ['ok' => true, 'wait' => 0];
}

function rateCleanup() {
    global $db;
    try { return $db->query("DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 2 DAY")->rowCount(); } catch (Throwable $e) { return 0; }
}

/** "3 minutes" / "40 seconds" for messages. */
function rateWaitText($seconds) {
    $seconds = max(1, (int) $seconds);
    return $seconds >= 90 ? (int) ceil($seconds / 60) . ' minutes' : $seconds . ' seconds';
}
