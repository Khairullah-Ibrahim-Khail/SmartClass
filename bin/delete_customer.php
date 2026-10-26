<?php
// Delete a customer: a final backup is written to instances/_archive/ first, then the database, its login, the config file
// and the storage folder are removed (the folder is moved to the archive). Cannot be undone from the app.
//   php bin/delete_customer.php friend.example.com --yes
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/customers.php';
$domain = $argv[1] ?? '';
if ($domain === '' || !in_array('--yes', $argv, true)) { fwrite(STDERR, "Usage: php bin/delete_customer.php <domain> --yes\n"); exit(1); }
try {
    $dump = customerDelete($domain);
    echo "Deleted {$domain}. Final backup: {$dump}\n";
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n"); exit(1); }
