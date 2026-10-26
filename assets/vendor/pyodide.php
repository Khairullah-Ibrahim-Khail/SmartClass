<?php
/**
 * Serves the Python engine (Pyodide) stored in assets/vendor/pyodide/ — used by the lecture "Run" button.
 * It runs inside a sandboxed iframe (a "null" origin), so every file needs cross-origin headers; this script adds them
 * without needing any Apache module:   /assets/vendor/pyodide.php/<file>
 * Only the files of the engine itself are served (fixed list), read-only, cached for a day.
 */
$file = basename((string) parse_url($_SERVER['PATH_INFO'] ?? '', PHP_URL_PATH));
$types = ['pyodide.js' => 'text/javascript', 'pyodide.mjs' => 'text/javascript', 'pyodide.asm.js' => 'text/javascript', 'pyodide.asm.wasm' => 'application/wasm',
          'python_stdlib.zip' => 'application/zip', 'pyodide-lock.json' => 'application/json'];
$path = __DIR__ . '/pyodide/' . $file;
if (!isset($types[$file]) || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . $types[$file]);
header('Access-Control-Allow-Origin: *');
header('Cross-Origin-Resource-Policy: cross-origin');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
$etag = '"' . md5($file . filesize($path) . filemtime($path)) . '"';
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($path));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
