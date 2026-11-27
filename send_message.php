<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$sender_role = strtolower($_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student');
if ($sender_role !== 'admin' && intval($_SESSION['user_id']) !== 1) {
    http_response_code(403);
    exit("Only admins can send replies from this endpoint.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_msg'])) {
    $sender_id = intval($_SESSION['user_id']);
    $receiver_id = intval($_POST['receiver_id']);
    $message = trim($_POST['message']);

    if (!empty($message) && $receiver_id > 0) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
        if ($stmt) {
            $stmt->bind_param("iis", $sender_id, $receiver_id, $message);
            if ($stmt->execute() &&
                ($_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student') === 'admin') {
                include_once 'web_push.php';
                web_push_notify_student(
                    $conn,
                    $receiver_id,
                    "New message from the Admin",
                    "The admin replied to your message.",
                    "dashboard.php?open=messages",
                    "admin-reply-" . $stmt->insert_id
                );
            }
            $stmt->close();
        }
    }

    if ($sender_id === 1 || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')) {
        header("Location: admin.php?chat_user=" . $receiver_id);
    } else {
        header("Location: dashboard.php?msg_sent=1");
    }
    exit();
}
?>