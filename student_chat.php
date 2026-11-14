<?php
// Chat between a logged-in student and the admin (used by the Message Admin pop-up).
session_start();
include 'db.php';
header('Content-Type: application/json');

function out($data) {
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    out(['success' => false, 'error' => 'Please log in first.']);
}
$user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student';
if ($role === 'admin') {
    out(['success' => false, 'error' => 'Admins reply from the Admin Control page.']);
}

$admin_q = $conn->query("SELECT id FROM users WHERE role = 'admin' OR usertype = 'admin' ORDER BY id ASC LIMIT 1");
$admin_target = ($admin_q && $admin_q->num_rows > 0) ? intval($admin_q->fetch_assoc()['id']) : 1;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function load_message($conn, $id) {
    $stmt = $conn->prepare("SELECT m.id, m.sender_id, u.full_name AS sender_name, m.message, m.created_at FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'id' => intval($row['id']),
        'sender_id' => intval($row['sender_id']),
        'sender_name' => $row['sender_name'],
        'message' => $row['message'],
        'time' => date('M d, h:i A', strtotime($row['created_at'])),
    ];
}

function save_message($conn, $from, $to, $text) {
    $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
    if (!$stmt) return 0;
    $stmt->bind_param("iis", $from, $to, $text);
    $ok = $stmt->execute();
    $id = $ok ? $stmt->insert_id : 0;
    $stmt->close();
    return $id;
}

// ------------------------------------------------ NEW MESSAGES (polling)
if ($action === 'history') {
    $after = intval($_GET['after_id'] ?? 0);
    $stmt = $conn->prepare("SELECT m.id, m.sender_id, u.full_name AS sender_name, m.message, m.created_at FROM messages m JOIN users u ON m.sender_id = u.id WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.id > ? ORDER BY m.id ASC LIMIT 200");
    $stmt->bind_param("iiiii", $user_id, $admin_target, $admin_target, $user_id, $after);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($r = $res->fetch_assoc()) {
        $list[] = [
            'id' => intval($r['id']),
            'sender_id' => intval($r['sender_id']),
            'sender_name' => $r['sender_name'],
            'message' => $r['message'],
            'time' => date('M d, h:i A', strtotime($r['created_at'])),
        ];
    }
    $stmt->close();

    $alert_stmt = $conn->prepare("SELECT id, item_id, item_title, serial_no, location, created_at FROM missing_item_alerts WHERE student_id = ? AND read_at IS NULL ORDER BY created_at DESC");
    if (!$alert_stmt) {
        error_log("Could not prepare live missing-item alerts: " . $conn->error);
        out(['success' => false, 'error' => 'Item alerts are temporarily unavailable.']);
    }
    $alert_stmt->bind_param("i", $user_id);
    if (!$alert_stmt->execute()) {
        error_log("Could not load live missing-item alerts: " . $alert_stmt->error);
        $alert_stmt->close();
        out(['success' => false, 'error' => 'Item alerts are temporarily unavailable.']);
    }
    $alert_result = $alert_stmt->get_result();
    $alerts = [];
    while ($alert = $alert_result->fetch_assoc()) {
        $alerts[] = [
            'id' => intval($alert['id']),
            'item_id' => intval($alert['item_id']),
            'item_title' => $alert['item_title'],
            'serial_no' => $alert['serial_no'],
            'location' => $alert['location'],
        ];
    }
    $alert_stmt->close();
    out(['success' => true, 'messages' => $list, 'alerts' => $alerts]);
}

// ------------------------------------------------ NORMAL MESSAGE
if ($action === 'send') {
    $text = trim($_POST['message'] ?? '');
    if ($text === '' || mb_strlen($text) > 1000) {
        out(['success' => false, 'error' => 'Please type a message (up to 1000 characters).']);
    }
    $id = save_message($conn, $user_id, $admin_target, $text);
    if (!$id) out(['success' => false, 'error' => 'Your message could not be sent.']);
    out(['success' => true, 'message' => load_message($conn, $id)]);
}

// ------------------------------------------------ OWNERSHIP INQUIRY (from the assistant)
if ($action === 'inquiry') {
    $name = trim($_POST['name'] ?? '');
    $year_section = trim($_POST['year_section'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $item_details = trim($_POST['item'] ?? '');
    $item_id = intval($_POST['item_id'] ?? 0);

    if ($name === '' || $year_section === '' || $contact === '' || $email === '' || $item_details === '') {
        out(['success' => false, 'error' => 'Some details are missing. Please answer every question.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out(['success' => false, 'error' => 'Please enter a valid email address.']);
    }
    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $contact)) {
        out(['success' => false, 'error' => 'Please enter a valid contact number.']);
    }

    // Look up the item from the database, so the admin sees the real serial number and title
    $item_line = '';
    if ($item_id > 0) {
        $iq = $conn->prepare("SELECT serial_no, title FROM items WHERE id = ?");
        $iq->bind_param("i", $item_id);
        $iq->execute();
        $irow = $iq->get_result()->fetch_assoc();
        $iq->close();
        if ($irow) {
            $item_line = "Item asked about: " . (!empty($irow['serial_no']) ? $irow['serial_no'] . " - " : "") . $irow['title'] . "\n";
        }
    }

    $text = "OWNERSHIP VERIFICATION REQUEST\n"
          . $item_line
          . "Name: " . mb_substr($name, 0, 100) . "\n"
          . "Year & Section: " . mb_substr($year_section, 0, 60) . "\n"
          . "Contact No.: " . mb_substr($contact, 0, 30) . "\n"
          . "Email: " . mb_substr($email, 0, 100) . "\n"
          . "About the item/s: " . mb_substr($item_details, 0, 600);

    $id = save_message($conn, $user_id, $admin_target, $text);
    if (!$id) out(['success' => false, 'error' => 'Your details could not be sent.']);
    out(['success' => true, 'message' => load_message($conn, $id)]);
}

out(['success' => false, 'error' => 'Unknown action.']);