<?php
/** Hide one dashboard suggestion for 14 days (POST, teacher only, JSON). */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
header('Content-Type: application/json');
if (!isTeacher() || $_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
echo json_encode(['ok' => suggestionDismiss($current_user_id, (string) ($_POST['key'] ?? ''))]);
