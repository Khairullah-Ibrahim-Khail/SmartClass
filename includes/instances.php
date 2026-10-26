<?php
/**
 * SmartClass - customers (instances) as seen from scripts and from the original system's Customers page.
 * A customer = config/instances/<domain>.php returning ['slug', 'domain', 'db_host', 'db_name', 'db_user', 'db_pass',
 * 'base_path', 'support_secret', 'master' => [host, db, user, pass]] plus its storage folder instances/<slug>/.
 *   instanceConfig($domain)      one customer's config array (or null)
 *   instanceConfigs()            every customer config, by domain
 *   instanceMasterConfig()       the original system's database settings (read from config/db.php without loading it)
 *   instancePdo($cfg)            a PDO connection with the same options the app uses
 *   instanceSlug($domain)        a safe short name for a domain
 */

function instanceSlug($domain) {
    return substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($domain)), '_'), 0, 40);
}

function instanceConfigDir() { return dirname(__DIR__) . '/config/instances'; }

function instanceConfig($domain) {
    $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower((string) $domain));
    $file = instanceConfigDir() . '/' . $domain . '.php';
    if ($domain === '' || strpos($domain, '..') !== false || !is_file($file) || !is_readable($file)) return null;
    $cfg = require $file;
    return is_array($cfg) ? $cfg + ['domain' => $domain] : null;
}

function instanceConfigs() {
    $out = [];
    foreach (glob(instanceConfigDir() . '/*.php') ?: [] as $f) {
        $cfg = instanceConfig(basename($f, '.php'));
        if ($cfg) $out[$cfg['domain']] = $cfg;
    }
    ksort($out);
    return $out;
}

function instanceMasterConfig() {
    $src = file_get_contents(dirname(__DIR__) . '/config/db.php');
    $local = is_file(dirname(__DIR__) . '/config/local.php') ? (@include dirname(__DIR__) . '/config/local.php') : [];
    if (!is_array($local)) $local = [];
    $val = function ($name, $key, $default) use ($src, $local) {
        if (!empty($local[$key])) return $local[$key];   // config/local.php wins over the defaults written in db.php
        return preg_match("/define\\('" . $name . "',\\s*(?:\\\$sc_(?:inst|local)\\['[a-z_]+'\\] \\?\\? )*'([^']*)'\\)/", $src, $m) ? $m[1] : $default;
    };
    return ['slug' => 'default', 'db_host' => $val('DB_HOST', 'db_host', 'localhost'), 'db_name' => $val('DB_NAME', 'db_name', 'smartclass_db'),
            'db_user' => $val('DB_USER', 'db_user', 'root'), 'db_pass' => $val('DB_PASS', 'db_pass', ''), 'domain' => '(original system)',
            'secret_key' => (string) ($local['secret_key'] ?? '')];
}

function instancePdo(array $cfg) {
    return new PDO("mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
