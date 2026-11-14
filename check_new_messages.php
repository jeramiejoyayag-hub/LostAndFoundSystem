<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'has_new' => false]);
    exit();
}

$user_id = intval($_SESSION['user_id']);

$query = $conn->prepare("
    SELECT m.id, m.message, u.full_name AS sender_name 
    FROM messages m 
    JOIN users u ON m.sender_id = u.id 
    WHERE m.receiver_id = ? AND m.is_read = 0 AND (u.role = 'admin' OR u.usertype = 'admin' OR u.id = 1)
    ORDER BY m.created_at DESC LIMIT 1
");

$query->bind_param("i", $user_id);
$query->execute();
$result = $query->get_result();

if ($result && $result->num_rows > 0) {
    $msg_data = $result->fetch_assoc();
    $msg_id = $msg_data['id'];

    $mark_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
    $mark_stmt->bind_param("i", $msg_id);
    $mark_stmt->execute();
    $mark_stmt->close();

    echo json_encode([
        'success' => true,
        'has_new' => true,
        'sender' => $msg_data['sender_name'],
        'message' => $msg_data['message']
    ]);
} else {
    echo json_encode(['success' => true, 'has_new' => false]);
}
?>