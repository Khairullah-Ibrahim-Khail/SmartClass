<?php
/**
 * SmartClass - provider control over customer installations (loaded by includes/functions.php)
 *
 * The ORIGINAL system (SC_IS_MASTER) is never limited. A customer installation asks the provider's
 * database (config/instances/<domain>.php › 'master', a read-only login) for its row in `customers`:
 *   status        active | suspended
 *   services_off  comma list of switched-off services: ai, email, uploads, signups
 * The answer is kept for 60 seconds (STORAGE_ROOT/cache/status.json); if the provider's database cannot be
 * reached the last known answer is used, or "active" — a hiccup on the provider's side never stops a school.
 * Customer settings stay theirs: these switches live in the provider's database, not in the customer's.
 *
 *   customerStatus()           ['status', 'reason', 'services_off' => [...], 'source' => 'live'|'cache'|'default']
 *   serviceEnabled($name)      false when the provider switched that service off for this customer
 *   enforceCustomerAccess()    maintenance page and suspended page (called once per web request from config/db.php)
 * A suspended customer's admin can still sign in and DOWNLOAD a backup (their data is never held back);
 * restoring, and everything else, is paused.
 */

const SC_SERVICES = ['ai' => 'AI helpers', 'email' => 'Email', 'uploads' => 'File uploads', 'signups' => 'New sign-ups'];
const SC_STATUS_TTL = 60;
const SUPPORT_LINK_MINUTES = 10;      // how long a support link can be opened
const SUPPORT_SESSION_MINUTES = 60;   // how long the support session lasts once opened

function customerStatus() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $default = ['status' => 'active', 'reason' => '', 'services_off' => [], 'limits' => [], 'source' => 'default'];
    if (SC_IS_MASTER) return $memo = $default;
    $cacheFile = STORAGE_ROOT . '/cache/status.json';
    $cached = is_file($cacheFile) ? json_decode((string) @file_get_contents($cacheFile), true) : null;
    if (is_array($cached) && time() - (int) ($cached['at'] ?? 0) < SC_STATUS_TTL) return $memo = ['source' => 'cache'] + $cached['data'];
    $cfg = $GLOBALS['sc_inst']['master'] ?? null;
    $data = null;
    if (is_array($cfg)) {
        try {
            $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4", $cfg['user'], $cfg['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $st = $pdo->prepare("SELECT status, suspended_reason, services_off, max_teachers, max_students, max_classes FROM customers WHERE domain = ?");
            $st->execute([$GLOBALS['sc_inst']['domain'] ?? '']);
            if ($row = $st->fetch()) {
                $data = ['status' => $row['status'] === 'suspended' ? 'suspended' : 'active', 'reason' => (string) $row['suspended_reason'],
                         'services_off' => array_values(array_filter(array_map('trim', explode(',', (string) $row['services_off'])))),
                         'limits' => ['teachers' => $row['max_teachers'] === null ? null : (int) $row['max_teachers'], 'students' => $row['max_students'] === null ? null : (int) $row['max_students'],
                                      'classes' => $row['max_classes'] === null ? null : (int) $row['max_classes']]];
                try { $pdo->prepare("UPDATE customers SET last_seen_at = NOW() WHERE domain = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL 5 MINUTE)")->execute([$GLOBALS['sc_inst']['domain'] ?? '']); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            error_log('SmartClass: provider status unavailable: ' . $e->getMessage());
        }
    }
    if ($data === null) return $memo = is_array($cached) ? ['source' => 'cache'] + $cached['data'] : $default;
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0777, true);
    @file_put_contents($cacheFile, json_encode(['at' => time(), 'data' => $data]));
    return $memo = ['source' => 'live'] + $data;
}

/**
 * Has this installation reached the limit the provider set (teachers / students / classes)? Returns the message to show, or null.
 * $extra: how many more are about to be added (e.g. pending invites). The original system has no limits.
 */
function limitReached($what, $extra = 0) {
    global $db;
    $limit = customerStatus()['limits'][$what] ?? null;
    if ($limit === null) return null;
    $sql = ['teachers' => "SELECT COUNT(*) FROM users WHERE role = 'teacher' AND is_active = 1",
            'students' => "SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 1",
            'classes'  => "SELECT COUNT(*) FROM classes WHERE is_active = 1"][$what] ?? null;
    if ($sql === null) return null;
    $now = (int) $db->query($sql)->fetchColumn() + (int) $extra;
    if ($now < $limit) return null;
    return 'This installation has reached its limit of ' . $limit . ' ' . $what . '. Please contact your provider to increase it.';
}

function serviceEnabled($name) {
    return !in_array($name, customerStatus()['services_off'], true);
}

function pausedPage($title, $text, $extra = '') {
    http_response_code(503);
    header('Retry-After: 300');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fb;font-family:Poppins,"Segoe UI",system-ui,sans-serif;color:#172033}'
        . '.c{max-width:460px;margin:20px;background:#fff;border:1px solid #e4e8f1;border-radius:16px;padding:34px;text-align:center;box-shadow:0 1px 3px rgba(16,24,40,.06)}'
        . 'h1{font-size:1.3rem;margin:0 0 10px}p{color:#667085;line-height:1.6;margin:0 0 10px}a{color:#4361ee;font-weight:600}</style></head><body><div class="c">'
        . '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($text) . '</p>' . $extra . '</div></body></html>';
    exit;
}

function enforceCustomerAccess() {
    if (PHP_SAPI === 'cli' || SC_IS_MASTER && !is_file(STORAGE_ROOT . '/maintenance.flag')) return;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_file(STORAGE_ROOT . '/maintenance.flag')) {
        pausedPage('Back in a moment', 'This site is being updated and will be back in a few minutes. Nothing is lost.');
    }
    $st = customerStatus();
    if ($st['status'] !== 'suspended') return;
    $allowed = preg_match('#/pages/(auth/[a-z_]+|settings/backup)\.php$#', $path) || preg_match('#/pages/branding/logo\.php$#', $path);
    if ($allowed) return;   // sign in, and download a backup (restore is blocked in backup.php)
    $extra = '<p><a href="' . htmlspecialchars(APP_URL) . '/pages/settings/backup.php">Sign in to download your data</a></p>';
    pausedPage('This service is paused', 'The provider has paused this installation' . ($st['reason'] !== '' ? ' (' . $st['reason'] . ')' : '') . '. Your data is safe. Please contact your provider to resume it.', $extra);
}

/** A note on settings pages when the provider switched that service off for this installation. */
function providerServiceNotice($service) {
    if (serviceEnabled($service)) return;
    echo '<div class="alert-box warn" style="margin-bottom:16px;"><i class="fas fa-info-circle"></i> <span><strong>' . htmlspecialchars(SC_SERVICES[$service] ?? $service)
        . ' is switched off for this installation by the service provider.</strong> Settings saved here are kept, but the feature stays off until the provider switches it on again.</span></div>';
}
