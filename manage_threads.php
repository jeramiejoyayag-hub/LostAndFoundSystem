<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');


// Use the same session key as send_message.php; change this line if it differs.
$admin_id = (int)($_SESSION['user_id'] ?? 0);


$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();


if (!$row || $row['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!is_string($csrf_token) || empty($_SESSION['thread_archive_csrf']) ||
    !hash_equals($_SESSION['thread_archive_csrf'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request token']);
    exit;
}

$action = $_POST['action'] ?? 'archive';
if (!in_array($action, ['archive', 'restore'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}
$ids = $_POST['student_ids'] ?? [];
if (!is_array($ids)) { $ids = []; }
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
$ids = array_values(array_diff($ids, [$admin_id]));


if (!$ids) {
    echo json_encode(['ok' => false, 'error' => 'Nothing selected']);
    exit;
}


if ($action === 'restore') {
    $stmt = $conn->prepare("DELETE FROM admin_archived_threads WHERE admin_id = ? AND student_id = ?");
} else {
    $stmt = $conn->prepare(
        "INSERT INTO admin_archived_threads (admin_id, student_id, archived_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE archived_at = NOW()"
    );
}

if (!$stmt) {
    error_log("Could not prepare conversation archive action: " . $conn->error);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database operation failed']);
    exit;
}

$count = 0;
foreach ($ids as $sid) {
    $stmt->bind_param("ii", $admin_id, $sid);
    if (!$stmt->execute()) {
        error_log("Could not update archived conversation: " . $stmt->error);
        $stmt->close();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database operation failed']);
        exit;
    }
    $count++;
}
$stmt->close();


echo json_encode(['ok' => true, 'count' => $count]);