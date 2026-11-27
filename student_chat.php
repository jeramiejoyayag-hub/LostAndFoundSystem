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

$rate_limit_table = $conn->query("CREATE TABLE IF NOT EXISTS student_message_rate_limits (
    user_id INT NOT NULL PRIMARY KEY,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cooldown_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
if (!$rate_limit_table) {
    error_log("Could not initialize student message rate limits: " . $conn->error);
    out(['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.']);
}

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
    if (!$stmt) {
        error_log("Could not prepare student message: " . $conn->error);
        return 0;
    }
    $stmt->bind_param("iis", $from, $to, $text);
    $ok = $stmt->execute();
    if (!$ok) error_log("Could not save student message: " . $stmt->error);
    $id = $ok ? $stmt->insert_id : 0;
    $stmt->close();
    return $id;
}

function message_cooldown_seconds($conn, $user_id) {
    $stmt = $conn->prepare("SELECT CASE WHEN cooldown_until > NOW() THEN GREATEST(TIMESTAMPDIFF(SECOND, NOW(), cooldown_until), 1) ELSE 0 END AS remaining_seconds FROM student_message_rate_limits WHERE user_id = ?");
    if (!$stmt) {
        error_log("Could not check student message cooldown: " . $conn->error);
        return false;
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Could not check student message cooldown: " . $stmt->error);
        $stmt->close();
        return false;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? intval($row['remaining_seconds']) : 0;
}

function save_student_message_with_limit($conn, $user_id, $admin_target, $text) {
    if (!$conn->begin_transaction()) {
        error_log("Could not begin student message transaction: " . $conn->error);
        return ['success' => false, 'error' => 'Your message could not be sent. Please try again.'];
    }

    $insert = $conn->prepare("INSERT IGNORE INTO student_message_rate_limits (user_id) VALUES (?)");
    if (!$insert) {
        error_log("Could not prepare student message rate limit: " . $conn->error);
        $conn->rollback();
        return ['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.'];
    }
    $insert->bind_param("i", $user_id);
    if (!$insert->execute()) {
        error_log("Could not initialize student message rate limit: " . $insert->error);
        $insert->close();
        $conn->rollback();
        return ['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.'];
    }
    $insert->close();

    $rate = $conn->prepare("SELECT CASE WHEN updated_at <= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 0 ELSE attempt_count END AS consecutive_count, cooldown_until, CASE WHEN cooldown_until > NOW() THEN GREATEST(TIMESTAMPDIFF(SECOND, NOW(), cooldown_until), 1) ELSE 0 END AS remaining_seconds FROM student_message_rate_limits WHERE user_id = ? FOR UPDATE");
    if (!$rate) {
        error_log("Could not prepare student message cooldown check: " . $conn->error);
        $conn->rollback();
        return ['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.'];
    }
    $rate->bind_param("i", $user_id);
    if (!$rate->execute()) {
        error_log("Could not load student message cooldown: " . $rate->error);
        $rate->close();
        $conn->rollback();
        return ['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.'];
    }
    $rate_row = $rate->get_result()->fetch_assoc();
    $rate->close();
    if (!$rate_row) {
        error_log("Student message rate limit row was not available for user " . $user_id);
        $conn->rollback();
        return ['success' => false, 'error' => 'Messaging is temporarily unavailable. Please try again later.'];
    }

    $remaining = intval($rate_row['remaining_seconds']);
    if ($remaining > 0) {
        $conn->rollback();
        return [
            'success' => false,
            'error' => 'Messaging has been temporarily paused to prevent excessive messages. Please try again in 15 minutes.',
            'cooldown_seconds' => $remaining,
        ];
    }

    $id = save_message($conn, $user_id, $admin_target, $text);
    if (!$id) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Your message could not be sent.'];
    }

    $next_count = intval($rate_row['consecutive_count']) + 1;
    $cooldown_started = $next_count >= 3;
    $stored_count = $cooldown_started ? 0 : $next_count;
    $update = $conn->prepare("UPDATE student_message_rate_limits SET attempt_count = ?, cooldown_until = CASE WHEN ? = 1 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE NULL END, updated_at = NOW() WHERE user_id = ?");
    if (!$update) {
        error_log("Could not prepare student message rate limit update: " . $conn->error);
        $conn->rollback();
        return ['success' => false, 'error' => 'Your message could not be sent. Please try again.'];
    }
    $cooldown_flag = $cooldown_started ? 1 : 0;
    $update->bind_param("iii", $stored_count, $cooldown_flag, $user_id);
    if (!$update->execute()) {
        error_log("Could not update student message rate limit: " . $update->error);
        $update->close();
        $conn->rollback();
        return ['success' => false, 'error' => 'Your message could not be sent. Please try again.'];
    }
    $update->close();

    if (!$conn->commit()) {
        error_log("Could not commit student message transaction: " . $conn->error);
        $conn->rollback();
        return ['success' => false, 'error' => 'Your message could not be sent. Please try again.'];
    }

    return [
        'success' => true,
        'id' => $id,
        'cooldown_seconds' => $cooldown_started ? 900 : 0,
    ];
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
    $cooldown_seconds = message_cooldown_seconds($conn, $user_id);
    if ($cooldown_seconds === false) {
        out(['success' => false, 'error' => 'Message status is temporarily unavailable.']);
    }
    out(['success' => true, 'messages' => $list, 'alerts' => $alerts, 'cooldown_seconds' => $cooldown_seconds]);
}

// ------------------------------------------------ NORMAL MESSAGE
if ($action === 'send') {
    $text = trim($_POST['message'] ?? '');
    if ($text === '' || mb_strlen($text) > 1000) {
        out(['success' => false, 'error' => 'Please type a message (up to 1000 characters).']);
    }
    $result = save_student_message_with_limit($conn, $user_id, $admin_target, $text);
    if (!$result['success']) out($result);
    out([
        'success' => true,
        'message' => load_message($conn, $result['id']),
        'cooldown_seconds' => $result['cooldown_seconds'],
    ]);
}

// ------------------------------------------------ OWNERSHIP INQUIRY (from the assistant)
if ($action === 'inquiry') {
    $account_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ? LIMIT 1");
    if (!$account_stmt) {
        error_log("Could not prepare ownership inquiry account lookup: " . $conn->error);
        out(['success' => false, 'error' => 'Could not verify your account. Please try again.']);
    }
    $account_stmt->bind_param("i", $user_id);
    if (!$account_stmt->execute()) {
        error_log("Could not load ownership inquiry account: " . $account_stmt->error);
        $account_stmt->close();
        out(['success' => false, 'error' => 'Could not verify your account. Please try again.']);
    }
    $account = $account_stmt->get_result()->fetch_assoc();
    $account_stmt->close();
    if (!$account) {
        out(['success' => false, 'error' => 'Could not verify your account. Please try again.']);
    }

    $name = preg_replace('/[\r\n]+/', ' ', trim($account['full_name'] ?? ''));
    $year_section = trim($_POST['year_section'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($account['email'] ?? '');
    $item_details = trim($_POST['item'] ?? '');
    $item_id = intval($_POST['item_id'] ?? 0);

    if ($name === '' || $year_section === '' || $contact === '' || $email === '' || $item_details === '') {
        out(['success' => false, 'error' => 'Some details are missing. Please answer every question.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out(['success' => false, 'error' => 'The email saved on your account is not valid. Please contact the admin to update it.']);
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

    $result = save_student_message_with_limit($conn, $user_id, $admin_target, $text);
    if (!$result['success']) out($result);
    out([
        'success' => true,
        'message' => load_message($conn, $result['id']),
        'cooldown_seconds' => $result['cooldown_seconds'],
    ]);
}

out(['success' => false, 'error' => 'Unknown action.']);