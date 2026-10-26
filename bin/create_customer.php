<?php
// Create a new customer installation: its own database + database login, a clean schema (no demo data), the first
// admin account, its own storage folder, a config file, and a row on the Customers page of the original system.
//
//   php bin/create_customer.php --name="Friend School" --domain=friend.example.com --admin-email=owner@friend.example.com \
//        [--admin-name="Owner"] [--password="..."] [--base-path=""] [--contact="..."]
//
// Prints the admin's temporary password (shown once) and the web-server steps for the domain.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/customers.php';

$opt = getopt('', ['name:', 'domain:', 'admin-email:', 'admin-name:', 'password:', 'base-path:', 'contact:']);
foreach (['name', 'domain', 'admin-email'] as $need) {
    if (empty($opt[$need])) { fwrite(STDERR, "Missing --{$need}=… (see the top of bin/create_customer.php).\n"); exit(1); }
}
try {
    $r = customerCreate(['name' => $opt['name'], 'domain' => $opt['domain'], 'admin_email' => $opt['admin-email'], 'admin_name' => $opt['admin-name'] ?? '',
                         'password' => $opt['password'] ?? '', 'base_path' => $opt['base-path'] ?? '', 'contact' => $opt['contact'] ?? '']);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
$dir = dirname(__DIR__);
echo "\nCustomer created: {$r['name']}\n  Domain       {$r['domain']}\n  Database     {$r['db_name']}   (login {$r['db_user']}, only this database)\n  Storage      instances/{$r['slug']}/\n";
echo "  Admin login  {$r['admin_email']}\n  Password     {$r['password']}   (temporary — shown once; the admin must accept the terms and choose a new password)\n\n";
echo "Web server: point the domain's DocumentRoot at {$dir}  (the same folder for every customer):\n";
echo "  <VirtualHost *:80>\n      ServerName {$r['domain']}\n      DocumentRoot {$dir}\n      <Directory {$dir}>\n          AllowOverride All\n          Require all granted\n      </Directory>\n  </VirtualHost>\n";
echo "Then:  sudo certbot --apache -d {$r['domain']}   and   sudo chown -R www-data:www-data instances/{$r['slug']}\n";
