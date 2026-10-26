<?php
/**
 * Web app manifest — makes SmartClass installable ("Add to home screen"). Built per school: the name and colour come from Settings › School,
 * the addresses from the folder / domain the app runs on. Served as JSON; no sign-in needed (it holds nothing private).
 */
require_once __DIR__ . '/config/db.php';
$name = trim((string) getSetting('school_name', '')) ?: APP_NAME;
$brand = strtolower((string) getSetting('brand_color', '#4361ee'));
if (!preg_match('/^#[0-9a-f]{6}$/', $brand)) $brand = '#4361ee';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => $name . ' — Learning', 'short_name' => mb_substr($name, 0, 12), 'description' => 'Classes, lectures, assignments, attendance and grades.',
    'id' => BASE_PATH . '/', 'start_url' => BASE_PATH . '/pages/dashboard.php?source=app', 'scope' => BASE_PATH . '/', 'display' => 'standalone',
    'background_color' => '#ffffff', 'theme_color' => $brand, 'orientation' => 'any',
    'icons' => [
        ['src' => BASE_PATH . '/assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_PATH . '/assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => BASE_PATH . '/assets/img/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [['name' => 'Dashboard', 'url' => BASE_PATH . '/pages/dashboard.php'], ['name' => 'My classes', 'url' => BASE_PATH . '/pages/classes/my-classes.php']],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
