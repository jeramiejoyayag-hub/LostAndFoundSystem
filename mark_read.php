<?php
// Marks all messages from one student to the logged-in admin as read.
session_start();
include 'db.php';
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Not allowed']);
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$student_id = intval($_POST['student_id'] ?? 0);

if ($student_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No student selected']);
    exit();
}

$stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => $conn->error]);
    exit();
}
$stmt->bind_param("ii", $student_id, $admin_id);
$stmt->execute();
echo json_encode(['success' => true, 'marked' => $stmt->affected_rows]);
$stmt->close();