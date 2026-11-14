<?php
// Shows the conversation between the admin and one student (loaded inside the admin Messages pop-up).
session_start();
include 'db.php';

$role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student';
if (!isset($_SESSION['user_id']) || $role !== 'admin') {
    http_response_code(403);
    echo "<p style='font-size:11px; color:#94a3b8; text-align:center;'>Not allowed.</p>";
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$student_id = intval($_GET['student_id'] ?? 0);

// Opening the chat marks the student's messages as read
$mark = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
if ($mark) {
    $mark->bind_param("ii", $student_id, $admin_id);
    $mark->execute();
    $mark->close();
}

$stmt = $conn->prepare("SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) ORDER BY m.id ASC");
$stmt->bind_param("iiii", $student_id, $admin_id, $admin_id, $student_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<p style='font-size:11px; color:#94a3b8; text-align:center; margin-top:20px;'>No messages yet.</p>";
    exit();
}

while ($m = $res->fetch_assoc()) {
    $is_admin_msg = ($m['sender_id'] == $admin_id);
    $row_style = $is_admin_msg ? 'text-align:right;' : 'text-align:left;';
    $bubble = $is_admin_msg ? 'background:#1b357a; color:#ffffff;' : 'background:#e2e8f0; color:#111827;';
    echo "<div style='" . $row_style . " margin-bottom:10px;'>";
    echo "<div style='display:inline-block; max-width:85%; text-align:left; padding:8px 12px; border-radius:10px; font-size:12px; line-height:1.45; white-space:pre-wrap; word-wrap:break-word; " . $bubble . "'>";
    echo "<strong style='font-size:10px; text-transform:uppercase; letter-spacing:0.3px;'>" . htmlspecialchars($m['sender_name']) . "</strong><br>";
    echo htmlspecialchars($m['message']);
    echo "<div style='font-size:9px; opacity:0.65; margin-top:3px;'>" . date('M d, h:i A', strtotime($m['created_at'])) . "</div>";
    echo "</div></div>";
}