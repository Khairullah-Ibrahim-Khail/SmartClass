<?php
// Router for `php -S`: a fake browser push service for the tests. Every request is appended to the file named in SC_PUSH_LOG.
// /ok → 201 Created · /gone → 410 (the phone unsubscribed) · /fail → 500
$line = json_encode(['path' => $_SERVER['REQUEST_URI'], 'method' => $_SERVER['REQUEST_METHOD'], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'ttl' => $_SERVER['HTTP_TTL'] ?? '', 'len' => $_SERVER['CONTENT_LENGTH'] ?? '', 'body' => file_get_contents('php://input')]);
file_put_contents((string) getenv('SC_PUSH_LOG'), $line . "\n", FILE_APPEND | LOCK_EX);
if (strpos($_SERVER['REQUEST_URI'], '/gone') === 0) { http_response_code(410); exit; }
if (strpos($_SERVER['REQUEST_URI'], '/fail') === 0) { http_response_code(500); exit; }
http_response_code(201);
