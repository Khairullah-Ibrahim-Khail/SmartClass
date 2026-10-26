<?php
// Move the private settings of this server into config/local.php (not part of the code, git-ignored):
//   php bin/init_local_config.php         creates config/local.php with the CURRENT database login and a new random secret_key
//   php bin/init_local_config.php --force overwrite an existing config/local.php (the secret_key is kept unless --new-key)
// After this, change the database password / user freely (edit config/local.php) — saved e-mail passwords, AI keys and two-step
// secrets no longer depend on it once you run:  php bin/rotate_secrets.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/instances.php';
$file = dirname(__DIR__) . '/config/local.php';
$force = in_array('--force', $argv, true);
if (is_file($file) && !$force) { echo "config/local.php already exists (use --force to rewrite it).\n"; exit(0); }
$cfg = instanceMasterConfig();
$old = is_file($file) ? (@include $file) : [];
$key = (!in_array('--new-key', $argv, true) && is_array($old) && !empty($old['secret_key'])) ? $old['secret_key'] : bin2hex(random_bytes(32));
$php = "<?php\n// Private settings of this server — never commit or share this file.\nreturn " . var_export(['db_host' => $cfg['db_host'], 'db_name' => $cfg['db_name'], 'db_user' => $cfg['db_user'], 'db_pass' => $cfg['db_pass'], 'secret_key' => $key], true) . ";\n";
if (file_put_contents($file, $php) === false) { fwrite(STDERR, "Cannot write {$file}\n"); exit(1); }
@chmod($file, 0644);   // the web server (another user) must be able to read it; config/.htaccess keeps it off the web
$gi = dirname(__DIR__) . '/.gitignore';
if (!is_file($gi) || strpos((string) file_get_contents($gi), 'config/local.php') === false) file_put_contents($gi, "config/local.php\nlogs/*.log\n", FILE_APPEND);
echo "Wrote config/local.php (database {$cfg['db_name']} as {$cfg['db_user']}, new secret_key).\nNext:  php bin/rotate_secrets.php --dry   then   php bin/rotate_secrets.php\n";
