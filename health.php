<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
$result = $conn->query('SELECT 1');
if (!$result) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable']);
    exit;
}

echo json_encode(['status' => 'ok']);
