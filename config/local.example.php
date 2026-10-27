<?php
// Copy this file to config/local.php and fill in the values for THIS server. config/local.php is ignored by Git — never commit it.
//   secret_key: a long random string that protects stored secrets (e-mail password, AI keys, two-step secrets).
//               Create one with:  php -r 'echo bin2hex(random_bytes(32)), "\n";'
return [
  'db_host'    => 'localhost',
  'db_name'    => 'smartclass_db',
  'db_user'    => 'CHANGE_ME',
  'db_pass'    => 'CHANGE_ME',
  'secret_key' => 'CHANGE_ME',
  'base_path'  => '',   // URL path of the site: '' when it is at the domain root (https://school.example.com/), '/smartclass' for https://example.com/smartclass
  // 'trust_proxy' => true,   // ONLY when a proxy/CDN you control ends HTTPS in front of this server (Cloudflare, nginx proxy, hosting panel)
];
