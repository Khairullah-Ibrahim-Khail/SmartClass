<?php
// Re-encrypt the stored secrets (v1 = tied to the database login) with the independent secret_key of config/local.php (v2).
//   php bin/rotate_secrets.php --dry     show what would change
//   php bin/rotate_secrets.php           do it (a backup of the database is written first, to ~/smartclass-backups or the temp folder)
// Covers: the e-mail (SMTP) password, AI provider keys, two-step secrets. Secrets that cannot be read any more are listed and left alone.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/backup.php';
if (SC_SECRET_KEY === '') { fwrite(STDERR, "No secret_key configured. Run  php bin/init_local_config.php  first.\n"); exit(1); }
$dry = in_array('--dry', $argv, true);
$stores = [['app_settings', 'setting_key', 'setting_value', "setting_key = 'smtp_password'"], ['ai_providers', 'id', 'api_key', 'api_key IS NOT NULL'], ['users', 'id', 'totp_secret', 'totp_secret IS NOT NULL']];
$todo = []; $bad = [];
foreach ($stores as [$table, $idc, $col, $where]) {
    foreach ($db->fetchAll("SELECT {$idc} AS k, {$col} AS v FROM {$table} WHERE {$where}") as $r) {
        if (!is_string($r['v']) || $r['v'] === '') continue;
        if (strpos($r['v'], 'v2:') === 0) continue;   // already done
        $plain = decryptSecret($r['v']);
        if ($plain === '') { $bad[] = "{$table}.{$col} #{$r['k']}"; continue; }
        $todo[] = [$table, $idc, $col, $r['k'], $plain];
    }
}
echo count($todo) . " secret(s) to re-encrypt" . ($bad ? ', ' . count($bad) . " unreadable (left alone): " . implode(', ', $bad) : '') . ($dry ? "  (dry run)\n" : "\n");
if ($dry || !$todo) exit(0);
$dir = rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/') . '/smartclass-backups'; @mkdir($dir, 0700, true);
$bk = $dir . '/before-rotate-' . date('Ymd-His') . '.sql.gz'; backupWrite($bk); echo "backup: {$bk}\n";
foreach ($todo as [$table, $idc, $col, $k, $plain]) {
    $db->query("UPDATE {$table} SET {$col} = ? WHERE {$idc} = ?", [encryptSecret($plain), $k]);
}
echo "done: " . count($todo) . " re-encrypted with the independent key.\n";
