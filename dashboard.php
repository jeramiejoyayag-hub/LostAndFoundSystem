<?php
session_start();
include 'db.php';
include 'google_config.php';
include 'retention.php';
include 'surrender_fields.php';
include 'missing_item_alerts.php';
include_once 'web_push.php';
include 'item_storage.php';
$google_enabled = !empty($GOOGLE_CLIENT_ID);
if (!ensure_item_surrender_columns($conn)) {
    error_log("Could not prepare item surrender details: " . $conn->error);
    http_response_code(500);
    exit("Item details are temporarily unavailable. Please contact the administrator.");
}
if (!ensure_missing_item_reports_table($conn)) {
    error_log("Could not prepare missing-item reports: " . $conn->error);
    http_response_code(500);
    exit("Missing-item alerts are temporarily unavailable. Please contact the administrator.");
}

// This home screen is open to everyone. A login is only needed to message the admin.
$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? intval($_SESSION['user_id']) : 0;
$user_role = $is_logged_in ? ($_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student') : 'guest';
$is_admin = ($user_role === 'admin');
$is_student = ($is_logged_in && !$is_admin);
$web_push_available = false;
$web_push_public_key = '';
$web_push_subscribed = false;
$web_push_csrf = '';
if ($is_student) {
    if (empty($_SESSION['web_push_csrf'])) {
        $_SESSION['web_push_csrf'] = bin2hex(random_bytes(32));
    }
    $web_push_csrf = $_SESSION['web_push_csrf'];
    if (web_push_ensure_subscriptions_table($conn)) {
        $web_push_keys = web_push_vapid_keys($conn);
        if ($web_push_keys !== false) {
            $web_push_available = true;
            $web_push_public_key = $web_push_keys['publicKey'];
            $push_status = $conn->prepare("SELECT COUNT(*) AS total FROM web_push_subscriptions WHERE student_id = ?");
            if ($push_status) {
                $push_status->bind_param("i", $user_id);
                if ($push_status->execute()) {
                    $push_status_row = $push_status->get_result()->fetch_assoc();
                    $web_push_subscribed = intval($push_status_row['total'] ?? 0) > 0;
                } else {
                    error_log("Could not load device notification status: " . $push_status->error);
                }
                $push_status->close();
            } else {
                error_log("Could not prepare device notification status: " . $conn->error);
            }
        }
    } else {
        error_log("Could not prepare device notifications: " . $conn->error);
    }
}

$missing_report_flash = $_SESSION['missing_report_flash'] ?? '';
$missing_report_is_error = false;
unset($_SESSION['missing_report_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_missing_item'])) {
    if (!$is_student) {
        http_response_code(403);
        exit("Only students can submit missing-item reports.");
    }

    $report_title = trim($_POST['report_title'] ?? '');
    $report_description = trim($_POST['report_description'] ?? '');
    $report_location = trim($_POST['report_location'] ?? '');
    $valid_locations = ['', 'MR Building', 'SA Building', 'College of Medicine', 'Andrada Gym', 'Cassiciacum', 'Parking Lot'];

    if ($report_title === '' || $report_description === '') {
        $missing_report_flash = "Enter the item name and a description to create an alert.";
        $missing_report_is_error = true;
    } elseif (mb_strlen($report_title, 'UTF-8') > 255 || mb_strlen($report_description, 'UTF-8') > 1200) {
        $missing_report_flash = "The item name must be at most 255 characters and the description at most 1200 characters.";
        $missing_report_is_error = true;
    } elseif (!in_array($report_location, $valid_locations, true)) {
        $missing_report_flash = "Select a valid location or choose Not sure.";
        $missing_report_is_error = true;
    } else {
        $stmt = $conn->prepare("INSERT INTO missing_item_reports (student_id, title, description, location) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isss", $user_id, $report_title, $report_description, $report_location);
            if ($stmt->execute()) {
                $_SESSION['missing_report_flash'] = "Your missing-item report is saved. We'll alert you if a newly posted found item may match.";
                $stmt->close();
                header("Location: dashboard.php");
                exit();
            }
            error_log("Could not save missing-item report: " . $stmt->error);
            $stmt->close();
        } else {
            error_log("Could not prepare missing-item report: " . $conn->error);
        }
        $missing_report_flash = "Could not save your report. Please try again.";
        $missing_report_is_error = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ack_missing_alert'])) {
    if (!$is_student) {
        http_response_code(403);
        exit("Only students can acknowledge item alerts.");
    }

    $alert_id = intval($_POST['alert_id'] ?? 0);
    $lookup = $conn->prepare("SELECT item_id FROM missing_item_alerts WHERE id = ? AND student_id = ? AND read_at IS NULL");
    if (!$lookup) {
        error_log("Could not prepare missing-item alert lookup: " . $conn->error);
        $missing_report_flash = "Could not open the item alert. Please refresh and try again.";
        $missing_report_is_error = true;
    } else {
        $lookup->bind_param("ii", $alert_id, $user_id);
        if (!$lookup->execute()) {
            error_log("Could not load missing-item alert: " . $lookup->error);
            $missing_report_flash = "Could not open the item alert. Please refresh and try again.";
            $missing_report_is_error = true;
            $lookup->close();
        } else {
            $alert = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if ($alert) {
                $mark_read = $conn->prepare("UPDATE missing_item_alerts SET read_at = NOW() WHERE id = ? AND student_id = ? AND read_at IS NULL");
                if (!$mark_read) {
                    error_log("Could not prepare missing-item alert acknowledgement: " . $conn->error);
                    $missing_report_flash = "Could not acknowledge the alert. Please try again.";
                    $missing_report_is_error = true;
                } else {
                    $mark_read->bind_param("ii", $alert_id, $user_id);
                    if ($mark_read->execute()) {
                        $item_id = intval($alert['item_id']);
                        $mark_read->close();
                        header("Location: dashboard.php#item_" . $item_id);
                        exit();
                    }
                    error_log("Could not acknowledge missing-item alert: " . $mark_read->error);
                    $mark_read->close();
                    $missing_report_flash = "Could not acknowledge the alert. Please try again.";
                    $missing_report_is_error = true;
                }
            } else {
                header("Location: dashboard.php");
                exit();
            }
        }
    }
}

$retention = retention_settings($conn);
archive_expired_items($conn, $retention['item_days']);
$expiring_items = expiring_items($conn, $retention['item_days'], $retention['warn_days']);
$item_days = max(1, intval($retention['item_days']));

$admin_id_query = $conn->query("SELECT id FROM users WHERE role = 'admin' OR usertype = 'admin' ORDER BY id ASC LIMIT 1");
$admin_target = ($admin_id_query && $admin_id_query->num_rows > 0) ? intval($admin_id_query->fetch_assoc()['id']) : 1;

$pending_item_alerts = [];
if ($is_student) {
    $alert_query = $conn->prepare("SELECT id, item_id, item_title, serial_no, location, created_at FROM missing_item_alerts WHERE student_id = ? AND read_at IS NULL ORDER BY created_at DESC");
    if (!$alert_query) {
        error_log("Could not prepare pending item alerts: " . $conn->error);
        http_response_code(500);
        exit("Item alerts are temporarily unavailable. Please try again.");
    }
    $alert_query->bind_param("i", $user_id);
    if (!$alert_query->execute()) {
        error_log("Could not load pending item alerts: " . $alert_query->error);
        $alert_query->close();
        http_response_code(500);
        exit("Item alerts are temporarily unavailable. Please try again.");
    }
    $alert_result = $alert_query->get_result();
    while ($alert = $alert_result->fetch_assoc()) {
        $pending_item_alerts[] = $alert;
    }
    $alert_query->close();
}

// Public statistics
$count_total_posted = $conn->query("SELECT COUNT(*) as total FROM items WHERE status != 'Archived'")->fetch_assoc()['total'];
$count_unclaimed = $conn->query("SELECT COUNT(*) as total FROM items WHERE status NOT IN ('Archived', 'Claimed')")->fetch_assoc()['total'];

// Logged-in student: account details and the existing chat with the admin
$account_name = '';
$account_email = '';
$chat_messages = [];

if ($is_student) {
    $account_name = $_SESSION['full_name'] ?? '';

    $eq = $conn->prepare("SELECT email FROM users WHERE id = ?");
    if ($eq) {
        $eq->bind_param("i", $user_id);
        $eq->execute();
        $erow = $eq->get_result()->fetch_assoc();
        $account_email = $erow['email'] ?? '';
        $eq->close();
    }

    $cm = $conn->prepare("SELECT m.id, m.sender_id, m.message, m.created_at, u.full_name AS sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?) ORDER BY m.id ASC LIMIT 200");
    if ($cm) {
        $cm->bind_param("iiii", $user_id, $admin_target, $admin_target, $user_id);
        $cm->execute();
        $cres = $cm->get_result();
        while ($r = $cres->fetch_assoc()) {
            $chat_messages[] = [
                'id' => intval($r['id']),
                'sender_id' => intval($r['sender_id']),
                'sender_name' => $r['sender_name'],
                'message' => $r['message'],
                'time' => date('M d, h:i A', strtotime($r['created_at'])),
            ];
        }
        $cm->close();
    }
}

// Search (title, description, location or serial number)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $query_stmt = $conn->prepare("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL $item_days DAY)) AS seconds_left FROM items WHERE status != 'Archived' AND (title LIKE ? OR description LIKE ? OR location LIKE ? OR serial_no LIKE ?) ORDER BY created_at DESC");
    $query_stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
    $query_stmt->execute();
    $items = $query_stmt->get_result();
} else {
    $items = $conn->query("SELECT *, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL $item_days DAY)) AS seconds_left FROM items WHERE status != 'Archived' ORDER BY created_at DESC");
}
$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Consolacion University Philippines - Lost & Found</title>
    <style>
        :root {
            --navy-dark: #1b357a;
            --navy-light: #2a4fa7;
            --amber-accent: #f59e0b;
            --bg-gray: #f3f4f6;
            --card-bg: #ffffff;
        }

        * { box-sizing: border-box; }

        html, body {
            height: 100vh;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Roboto, Arial, sans-serif;
            background-color: var(--bg-gray);
            overflow: hidden;
        }

        body { display: flex; }

        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--navy-dark) 0%, var(--navy-light) 100%);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            border-top-right-radius: 20px;
            border-bottom-right-radius: 20px;
            box-shadow: 4px 0 15px rgba(0,0,0,0.1);
            flex-shrink: 0;
            height: 100vh;
        }

        .sidebar-brand {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            background: #fff;
            border-radius: 8px;
            padding: 2px;
        }

        .sidebar-brand h2 { font-size: 12px; line-height: 1.25; margin: 0; font-weight: 700; }

        .sidebar-menu { list-style: none; padding: 15px 0; margin: 0; flex-grow: 1; }

        .sidebar-menu li a, .sidebar-menu li button {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            width: 100%;
            background: transparent;
            border: none;
            cursor: pointer;
            text-align: left;
        }

        .sidebar-menu li.active a, .sidebar-menu li a:hover, .sidebar-menu li button:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            border-left: 4px solid var(--amber-accent);
        }

        .main-wrapper {
            flex-grow: 1;
            padding: 20px 25px;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .top-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .top-header-title h1 { color: var(--navy-dark); margin: 0; font-size: 18px; font-weight: 800; }
        .top-header-title p { margin: 2px 0 0 0; color: #6b7280; font-size: 11px; font-weight: 600; }

        .search-bar-container {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 30px;
            padding: 5px 14px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }

        .search-bar-container .search-icon { color: #9ca3af; font-size: 14px; margin-right: 8px; }

        .search-bar-container input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 12px;
            color: #1f2937;
        }

        .search-bar-container .voice-btn {
            background: transparent;
            border: none;
            color: var(--navy-dark);
            font-size: 15px;
            cursor: pointer;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 12px;
            flex-shrink: 0;
        }

        .action-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 10px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            cursor: pointer;
            border: 1px solid #e5e7eb;
            text-decoration: none;
            color: #333;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .action-card .icon-box { font-size: 20px; margin-bottom: 2px; }
        .action-card span { font-size: 11px; font-weight: 700; color: #4b5563; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            border: 1px solid #e5e7eb;
        }

        .stat-icon {
            font-size: 22px;
            background: #eff6ff;
            color: var(--navy-dark);
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-info h3 { margin: 0; font-size: 16px; font-weight: 800; color: var(--navy-dark); }
        .stat-info p { margin: 0; font-size: 10px; color: #6b7280; font-weight: 700; text-transform: uppercase; }

        .content-split {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 15px;
            flex-grow: 1;
            min-height: 0;
        }

        .posts-only-scroll { overflow-y: auto; padding-right: 10px; padding-bottom: 20px; height: 100%; }
        .posts-only-scroll::-webkit-scrollbar { width: 6px; }
        .posts-only-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .panel-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.03);
            border: 1px solid #e5e7eb;
            margin-bottom: 15px;
        }

        .panel-title { margin-top: 0; margin-bottom: 10px; font-size: 14px; font-weight: 800; color: var(--navy-dark); }

        .feed-photo-gallery {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 6px 0;
            margin: 8px 0;
        }

        .feed-photo-item {
            height: 180px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            background-color: #f9fafb;
        }

        .preview-container { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
        .preview-container img { height: 70px; width: auto; border-radius: 6px; border: 1px solid #d1d5db; }

        .comments-section { margin-top: 12px; padding-top: 10px; border-top: 1px solid #f1f5f9; }

        .comments-list {
            max-height: 150px;
            overflow-y: auto;
            margin-bottom: 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .comment-item {
            background: #f8fafc;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 11px;
            border: 1px solid #e2e8f0;
        }

        .comment-item strong { color: var(--navy-dark); }
        .comment-item small { color: #94a3b8; font-size: 9px; float: right; }

        .comment-form { display: flex; gap: 6px; }

        .comment-form input {
            flex-grow: 1;
            padding: 6px 10px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 11px;
            outline: none;
        }

        .comment-form button {
            background: var(--navy-dark);
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }

        .notice-item {
            background: #fef3c7;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            border-left: 4px solid var(--amber-accent);
        }

        .notice-item.blue { background: #dbeafe; border-left-color: var(--navy-light); }
        .notice-item h4 { margin: 0 0 3px 0; font-size: 12px; color: #1e293b; }
        .notice-item p { margin: 0; font-size: 11px; color: #475569; }

        .alert { padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 12px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-danger { background: #fee2e2; color: #991b1b; }

        .chat-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .chat-modal-content {
            background: #ffffff;
            width: 90%;
            max-width: 520px;
            height: 500px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .chat-modal-header {
            background: var(--navy-dark);
            color: #ffffff;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 15px;
            font-weight: 700;
        }

        .chat-modal-header span.close-btn { cursor: pointer; font-size: 18px; }

        .chat-modal-body {
            padding: 18px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            height: calc(100% - 50px);
        }

        @media (max-width: 1000px) {
            html, body { overflow: auto; height: auto; }
            .main-wrapper { height: auto; overflow: visible; }
            .action-grid { grid-template-columns: 1fr; }
            .content-split { grid-template-columns: 1fr; }
            .posts-only-scroll { overflow-y: visible; height: auto; }
        }

        /* ===== SVG icons (replace emojis) ===== */
        .icon {
            width: 1.15em;
            height: 1.15em;
            vertical-align: -0.22em;
            margin-right: 0.4em;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }
        .sidebar-menu .icon { width: 18px; height: 18px; margin-right: 0; vertical-align: middle; }
        .icon-box .icon, .stat-icon .icon, .search-icon .icon, .voice-btn .icon,
        .close-btn .icon { margin-right: 0; vertical-align: middle; }
        .icon-box .icon { width: 22px; height: 22px; color: var(--navy-dark); }
        .stat-icon .icon { width: 22px; height: 22px; }
        .search-icon .icon { width: 15px; height: 15px; }
        .voice-btn .icon { width: 16px; height: 16px; }
        .close-btn .icon { width: 16px; height: 16px; }
        .alert .icon, .alert-error .icon, .alert-success .icon { width: 1.2em; height: 1.2em; }

        .serial-badge {
            display: inline-block;
            background: #eff6ff;
            color: var(--navy-dark);
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 2px 7px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.4px;
            margin-right: 8px;
            vertical-align: middle;
        }
        .preview-container { display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; }
        .preview-container img { height: 70px; width: auto; border-radius: 6px; border: 1px solid #d1d5db; }

        /* ===== Chat with the admin + assistant ===== */
        #privateMessageModal .chat-modal-content { height: 580px; max-height: 92vh; }
        .chat-history-box {
            flex-grow: 1;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            background: #f8fafc;
            margin-bottom: 10px;
        }
        .msg-row { display: flex; margin-bottom: 10px; }
        .msg-row.me { justify-content: flex-end; }
        .msg-bubble { max-width: 84%; padding: 8px 12px; border-radius: 10px; font-size: 12.5px; line-height: 1.45; }
        .msg-bubble.me { background: #1b357a; color: #ffffff; }
        .msg-bubble.admin { background: #e2e8f0; color: #111827; }
        .msg-bubble.bot { background: #dbeafe; color: #1e3a8a; border-left: 3px solid #f59e0b; }
        .msg-name { font-size: 10px; font-weight: 800; opacity: 0.85; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.3px; }
        .msg-text { white-space: pre-wrap; word-wrap: break-word; }
        .msg-time { font-size: 9px; opacity: 0.65; margin-top: 3px; }
        .quick-replies { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
        .quick-replies:empty { display: none; }
        .quick-replies button {
            background: #ffffff;
            border: 1px solid #1b357a;
            color: #1b357a;
            border-radius: 14px;
            padding: 5px 11px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        .quick-replies button:hover { background: #1b357a; color: #ffffff; }
        .new-dot { background: #dc2626; color: #fff; font-size: 9px; font-weight: bold; padding: 2px 6px; border-radius: 10px; margin-left: auto; }

        /* ===== Login / Create Account pop-up ===== */
        .auth-card {
            background: #ffffff;
            width: 92%;
            max-width: 420px;
            max-height: 92vh;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .auth-body { padding: 18px 20px 22px; overflow-y: auto; }
        .auth-note { margin: 0 0 14px 0; font-size: 12px; color: #64748b; line-height: 1.5; }
        .auth-tabs { display: flex; border-bottom: 2px solid #e2e8f0; margin-bottom: 14px; }
        .auth-tabs button {
            flex: 1;
            background: transparent;
            border: none;
            padding: 9px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            font-family: inherit;
        }
        .auth-tabs button.active { color: var(--navy-dark); border-bottom-color: var(--amber-accent); }
        .auth-body label { display: block; font-size: 11px; font-weight: 700; margin-bottom: 3px; text-transform: uppercase; }
        .auth-input {
            width: 100%;
            padding: 9px 10px;
            margin-bottom: 11px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 13px;
            font-family: inherit;
            outline: none;
        }
        .auth-input:focus { border-color: var(--navy-light); box-shadow: 0 0 0 3px rgba(42, 79, 167, 0.15); }
        .auth-row { display: flex; gap: 10px; }
        .auth-row > div { flex: 1; min-width: 0; }
        .auth-submit {
            width: 100%;
            background: var(--navy-dark);
            color: #fff;
            border: none;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        .auth-submit:hover { background: var(--navy-light); }
        .auth-submit:disabled { opacity: 0.6; cursor: wait; }
        .how-to ol { margin: 0; padding-left: 18px; font-size: 12px; line-height: 1.7; color: #475569; }
        .how-to li { margin-bottom: 4px; }
        .auth-or { display: flex; align-items: center; gap: 10px; margin: 12px 0 14px; font-size: 11px; color: #94a3b8; font-weight: 700; text-transform: uppercase; }
        .auth-or::before, .auth-or::after { content: ""; flex: 1; height: 1px; background: #e2e8f0; }
        #googleBtn { display: flex; justify-content: center; min-height: 44px; }
        .sidebar-foot { padding: 14px 20px; font-size: 11px; }
        .sidebar-foot a { color: rgba(255,255,255,0.55); text-decoration: none; }
        .sidebar-foot a:hover { color: #ffffff; text-decoration: underline; }
        #chatInput { resize: none; max-height: 130px; line-height: 1.4; font-family: inherit; }
        .match-alert-overlay { position:fixed; inset:0; z-index:2100; display:flex; align-items:center; justify-content:center; padding:16px; background:rgba(0,0,0,0.6); }
        .match-alert-card { width:100%; max-width:480px; max-height:88vh; overflow-y:auto; padding:20px; border-radius:14px; background:#fff; box-shadow:0 12px 35px rgba(0,0,0,0.3); }
        .match-alert-card h2 { margin:0 0 8px; color:#1b357a; font-size:17px; }
        .match-alert-card p { margin:0 0 14px; color:#475569; font-size:12px; line-height:1.5; }
        .match-alert-item { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; padding:12px; border:1px solid #fde68a; border-radius:8px; background:#fffbeb; }
        .match-alert-item strong { display:block; color:#1e293b; font-size:13px; }
        .match-alert-item small { color:#475569; font-size:11px; }
        .match-alert-item button { flex-shrink:0; border:0; border-radius:6px; padding:8px 11px; color:#fff; background:#1b357a; font-weight:700; cursor:pointer; }
    </style>
    <link rel="stylesheet" href="theme.css">
    <script src="theme.js"></script>
</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="i-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></symbol><symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol><symbol id="i-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></symbol><symbol id="i-chat" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol><symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol><symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol><symbol id="i-mic" viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></symbol><symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol><symbol id="i-check" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol><symbol id="i-x" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></symbol><symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol><symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol><symbol id="i-id" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></symbol><symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></symbol><symbol id="i-close" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol><symbol id="i-bot" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></symbol><symbol id="i-mail" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></symbol><symbol id="i-package" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></symbol><symbol id="i-inbox" viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol><symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></symbol><symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol><symbol id="i-camera" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol><symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></symbol><symbol id="i-login" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></symbol><symbol id="i-hash" viewBox="0 0 24 24"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></symbol><symbol id="i-archive" viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></symbol><symbol id="i-paperclip" viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></symbol><symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol></svg>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <img src="logo.jpg" alt="LCUP Logo" onerror="this.style.display='none'">
            <h2>La Consolacion University Philippines</h2>
        </div>
        <ul class="sidebar-menu">
            <li class="active"><a href="dashboard.php"><svg class="icon"><use href="#i-dashboard"/></svg>Dashboard</a></li>
            <?php if ($is_admin): ?>
                <li><a href="admin.php"><svg class="icon"><use href="#i-shield"/></svg>Admin Control</a></li>
            <?php else: ?>
                <li><button onclick="openMessageAdmin()"><svg class="icon"><use href="#i-chat"/></svg>Message Admin <span class="new-dot" id="msgDot" style="display:none;">New</span></button></li>
            <?php endif; ?>
            <?php if ($is_logged_in): ?>
                <li><a href="logout.php"><svg class="icon"><use href="#i-logout"/></svg>Logout</a></li>
            <?php endif; ?>
        </ul>
        <?php if (!$is_logged_in): ?>
            <div class="sidebar-foot"><a href="#" onclick="openAuthModal(); return false;">Staff / Admin login</a></div>
        <?php endif; ?>
    </div>

    <!-- Main Outer Wrapper -->
    <div class="main-wrapper">

        <!-- Top Header Bar -->
        <div class="top-header-bar">
            <div class="top-header-title">
                <h1>LA CONSOLACION UNIVERSITY PHILIPPINES</h1>
                <p>Lost & Found System Portal</p>
            </div>

            <form method="GET" class="search-bar-container">
                <span class="search-icon"><svg class="icon"><use href="#i-search"/></svg></span>
                <input type="text" id="searchInput" name="search" placeholder="Search items, locations, serial no..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="button" class="voice-btn" onclick="startVoiceSearch()" title="Voice Search"><svg class="icon"><use href="#i-mic"/></svg></button>
            </form>
        </div>

        <!-- Quick Actions -->
        <?php if ($is_admin): ?>
        <div class="action-grid">
            <a href="admin.php" class="action-card">
                <div class="icon-box"><svg class="icon"><use href="#i-shield"/></svg></div>
                <span>Go to Admin Panel</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><svg class="icon"><use href="#i-package"/></svg></div>
                <div class="stat-info">
                    <h3><?php echo $count_total_posted; ?></h3>
                    <p>Items Posted</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><svg class="icon"><use href="#i-file"/></svg></div>
                <div class="stat-info">
                    <h3><?php echo $count_unclaimed; ?></h3>
                    <p>Still Unclaimed</p>
                </div>
            </div>
        </div>

        <!-- Main Workspace Split -->
        <div class="content-split">

            <div class="posts-only-scroll">

                <!-- Item Feed Cards -->
                <?php if ($items && $items->num_rows > 0): ?>
                    <?php while ($row = $items->fetch_assoc()):
                        $serial_label = !empty($row['serial_no']) ? $row['serial_no'] : 'ITEM-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT);
                        $days_left = max(0, (int)ceil(intval($row['seconds_left']) / 86400));
                    ?>
                        <div class="panel-card" id="item_<?php echo $row['id']; ?>" data-serial="<?php echo htmlspecialchars($serial_label); ?>" data-title="<?php echo htmlspecialchars($row['title']); ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-weight:bold; font-size:12px; color:#475569;"><span class="serial-badge"><?php echo htmlspecialchars($serial_label); ?></span><svg class="icon"><use href="#i-pin"/></svg><?php echo (($row['item_type'] ?? '') === 'Found') ? 'Found at: ' : 'Location: '; ?><?php echo htmlspecialchars($row['location']); ?></span>
                                <?php
                                // Badge: CLAIMED once returned, otherwise MISSING or FOUND
                                $type_src = $row['item_type'] ?? '';
                                if ($type_src === '') {
                                    $type_src = in_array(strtolower($row['status']), ['missing', 'found']) ? $row['status'] : 'Missing';
                                }
                                if ($row['status'] === 'Claimed') {
                                    $badge_text = 'CLAIMED';
                                    $badge_style = '#dcfce7; color:#166534;';
                                } elseif (strtolower($type_src) === 'found') {
                                    $badge_text = 'FOUND';
                                    $badge_style = '#dbeafe; color:#1e40af;';
                                } else {
                                    $badge_text = 'MISSING';
                                    $badge_style = '#fef3c7; color:#92400e;';
                                }
                                ?>
                                <span style="padding:3px 8px; border-radius:10px; font-size:10px; font-weight:bold; background:<?php echo $badge_style; ?>">
                                    <?php echo $badge_text; ?>
                                </span>
                            </div>

                            <div class="feed-photo-gallery">
                                <?php
                                $current_item_id = intval($row['id']);
                                $feed_photos = [];

                                $p_query = $conn->query("SELECT photo_path FROM item_photos WHERE item_id = $current_item_id ORDER BY id ASC");
                                if ($p_query && $p_query->num_rows > 0) {
                                    while ($p = $p_query->fetch_assoc()) {
                                        if (!empty($p['photo_path']) && !in_array($p['photo_path'], $feed_photos)) {
                                            $feed_photos[] = $p['photo_path'];
                                        }
                                    }
                                }
                                if (!empty($row['image']) && !in_array($row['image'], $feed_photos)) {
                                    array_unshift($feed_photos, $row['image']);
                                }
                                foreach ($feed_photos as $photo_file): ?>
                                    <img src="<?php echo htmlspecialchars(item_storage_url($photo_file), ENT_QUOTES, 'UTF-8'); ?>" class="feed-photo-item" alt="">
                                <?php endforeach; ?>
                            </div>

                            <h3 style="margin:6px 0 3px 0; font-size:15px; color:var(--navy-dark);"><?php echo htmlspecialchars($row['title']); ?></h3>
                            <p style="margin:0 0 10px 0; color:#475569; font-size:12px;"><?php echo htmlspecialchars($row['description']); ?></p>
                            <?php if (($row['item_type'] ?? '') === 'Found' && !empty($row['surrendered_by'])): ?>
                                <p style="margin:0 0 10px 0; color:#475569; font-size:11px; line-height:1.6;">
                                    Found/surrendered by: <strong><?php echo htmlspecialchars($row['surrendered_by']); ?></strong><br>
                                    Surrendered to: <?php echo htmlspecialchars($row['surrendered_to'] ?? ''); ?><br>
                                    Time surrendered: <?php echo !empty($row['surrendered_at']) ? date('M d, Y h:i A', strtotime($row['surrendered_at'])) : 'Not recorded'; ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($row['status'] !== 'Claimed'): ?>
                                <p style="margin:0 0 10px 0; color:#92400e; font-size:12px; font-weight:700;">
                                    <svg class="icon"><use href="#i-clock"/></svg>
                                    <?php echo $days_left > 0 ? 'Claim within ' . $days_left . ' day' . ($days_left === 1 ? '' : 's') : 'Less than 1 day left to claim'; ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($row['status'] === 'Claimed'): ?>
                                <div class="alert alert-success" style="text-align:center; padding:6px; margin:0;">
                                    <svg class="icon"><use href="#i-check"/></svg>Returned to its owner
                                </div>
                            <?php elseif (!$is_admin): ?>
                                <button onclick="openMessageAdmin(<?php echo $row['id']; ?>)" style="background:var(--navy-light); color:#fff; border:none; padding:7px 14px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:11px;">
                                    <svg class="icon"><use href="#i-chat"/></svg>Is this yours? Message Admin
                                </button>
                            <?php endif; ?>

                            <?php
                            // Older comments are still shown (read-only)
                            $old_comments = $conn->query("SELECT c.comment, c.created_at, u.full_name FROM item_comments c JOIN users u ON c.user_id = u.id WHERE c.item_id = $current_item_id ORDER BY c.created_at ASC");
                            if ($old_comments && $old_comments->num_rows > 0): ?>
                                <div class="comments-section">
                                    <h4 style="font-size:12px; margin:0 0 6px 0; color:#475569;"><svg class="icon"><use href="#i-chat"/></svg>Comments</h4>
                                    <div class="comments-list">
                                        <?php while ($cmt = $old_comments->fetch_assoc()): ?>
                                            <div class="comment-item">
                                                <small><svg class="icon"><use href="#i-clock"/></svg><?php echo date('M d, h:i A', strtotime($cmt['created_at'])); ?></small>
                                                <strong><?php echo htmlspecialchars($cmt['full_name']); ?>:</strong>
                                                <span><?php echo htmlspecialchars($cmt['comment']); ?></span>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="panel-card" style="text-align:center; color:#6b7280;">No items found.</div>
                <?php endif; ?>

            </div>

            <!-- How to claim -->
            <div>
                <div class="panel-card how-to">
                    <h3 class="panel-title"><svg class="icon"><use href="#i-info"/></svg>How to claim an item</h3>
                    <ol>
                        <li>Find your item in the list and note its serial number.</li>
                        <li>Click <strong>Is this yours? Message Admin</strong>.</li>
                        <li>Log in or create an account when asked.</li>
                        <li>Answer the assistant's questions: name, year and section, contact number, email, and details about your item.</li>
                        <li>The admin will review your details and reply in the chat.</li>
                    </ol>
                </div>
                <?php if ($is_student): ?>
                    <div class="panel-card">
                        <h3 class="panel-title"><svg class="icon"><use href="#i-bell"/></svg>Get alerts for a missing item</h3>
                        <p style="font-size:11px; color:#64748b; line-height:1.5;">Submit the item details once. We'll notify you when a newly posted found item may match. Device alerts require HTTPS (localhost is supported) and the server to be running.</p>
                        <button type="button" id="devicePushButton" class="auth-submit" onclick="toggleDeviceNotifications()" <?php echo $web_push_available ? '' : 'disabled'; ?>>
                            <?php echo $web_push_subscribed ? 'Turn off device notifications' : 'Enable device notifications'; ?>
                        </button>
                        <p id="devicePushStatus" role="status" style="font-size:11px; color:#64748b; line-height:1.5; margin:6px 0 12px;">
                            <?php echo $web_push_available ? ($web_push_subscribed ? 'Device notifications are enabled on this browser.' : 'Allow notifications to receive match alerts and admin replies on this device, even when this page is closed.') : 'Device notifications are not configured on this server.'; ?>
                        </p>
                        <?php if ($missing_report_flash !== ''): ?>
                            <div class="alert <?php echo $missing_report_is_error ? 'alert-danger' : 'alert-success'; ?>">
                                <?php echo htmlspecialchars($missing_report_flash); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="dashboard.php">
                            <input type="hidden" name="report_missing_item" value="1">
                            <label for="report_title" style="display:block; font-size:11px; font-weight:bold;">Item name *</label>
                            <input id="report_title" name="report_title" type="text" maxlength="255" required style="width:100%; padding:8px; margin:4px 0 8px; border:1px solid #ccc; border-radius:5px;">
                            <label for="report_description" style="display:block; font-size:11px; font-weight:bold;">Description and identifying details *</label>
                            <textarea id="report_description" name="report_description" maxlength="1200" rows="3" required style="width:100%; padding:8px; margin:4px 0 8px; border:1px solid #ccc; border-radius:5px; font-family:inherit;"></textarea>
                            <label for="report_location" style="display:block; font-size:11px; font-weight:bold;">Last known location (optional)</label>
                            <select id="report_location" name="report_location" style="width:100%; padding:8px; margin:4px 0 10px; border:1px solid #ccc; border-radius:5px;">
                                <option value="">Not sure</option>
                                <option value="MR Building">MR Building</option>
                                <option value="SA Building">SA Building</option>
                                <option value="College of Medicine">College of Medicine</option>
                                <option value="Andrada Gym">Andrada Gym</option>
                                <option value="Cassiciacum">Cassiciacum</option>
                                <option value="Parking Lot">Parking Lot</option>
                            </select>
                            <button type="submit" class="auth-submit">Save Missing-Item Alert</button>
                        </form>
                    </div>
                <?php elseif (!$is_logged_in): ?>
                    <div class="panel-card">
                        <h3 class="panel-title"><svg class="icon"><use href="#i-bell"/></svg>Get alerts for a missing item</h3>
                        <p style="font-size:11px; color:#64748b;">Log in or create an account to submit a missing-item report and receive matching alerts.</p>
                        <button type="button" class="auth-submit" onclick="openAuthModal()">Log in to report an item</button>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

    <?php if (!$is_logged_in): ?>
    <!-- POP-UP: LOGIN / CREATE ACCOUNT (guests) -->
    <div class="chat-modal-overlay" id="authModal">
        <div class="auth-card">
            <div class="chat-modal-header">
                <span><svg class="icon"><use href="#i-login"/></svg>Sign in to message the admin</span>
                <span class="close-btn" onclick="closeAuthModal()"><svg class="icon"><use href="#i-close"/></svg></span>
            </div>
            <div class="auth-body">
                <p class="auth-note">You can browse every item without an account. You only need to log in to message the admin and confirm that an item is yours.</p>

                <?php if ($google_enabled): ?>
                    <div id="googleBtn"></div>
                    <div class="auth-or">or use your account</div>
                <?php endif; ?>

                <div class="auth-tabs">
                    <button type="button" id="tabLogin" class="active" onclick="switchAuthTab('login')">Login</button>
                    <button type="button" id="tabRegister" onclick="switchAuthTab('register')">Create Account</button>
                </div>

                <div id="authError" class="alert alert-danger" style="display:none;"></div>

                <form id="loginForm" onsubmit="return submitAuth(event, 'login')">
                    <label for="login_id">Email or Username</label>
                    <input class="auth-input" type="text" id="login_id" name="email_or_username" required autocomplete="username">
                    <label for="login_password">Password</label>
                    <input class="auth-input" type="password" id="login_password" name="password" required autocomplete="current-password">
                    <button type="submit" class="auth-submit">Login</button>
                </form>

                <form id="registerForm" style="display:none;" onsubmit="return submitAuth(event, 'register')">
                    <label for="reg_full_name">Full Name</label>
                    <input class="auth-input" type="text" id="reg_full_name" name="full_name" required>
                    <div class="auth-row">
                        <div>
                            <label for="reg_username">Username</label>
                            <input class="auth-input" type="text" id="reg_username" name="username" required autocomplete="username">
                        </div>
                        <div>
                            <label for="reg_email">Email</label>
                            <input class="auth-input" type="email" id="reg_email" name="email" required autocomplete="email">
                        </div>
                    </div>
                    <div class="auth-row">
                        <div>
                            <label for="reg_password">Password</label>
                            <input class="auth-input" type="password" id="reg_password" name="password" minlength="6" required autocomplete="new-password">
                        </div>
                        <div>
                            <label for="reg_confirm">Confirm Password</label>
                            <input class="auth-input" type="password" id="reg_confirm" name="confirm_password" minlength="6" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="auth-submit">Create Account</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_student): ?>
    <!-- POP-UP: MESSAGE ADMIN (assistant replies first) -->
    <div class="chat-modal-overlay" id="privateMessageModal">
        <div class="chat-modal-content">
            <div class="chat-modal-header">
                <span><svg class="icon"><use href="#i-chat"/></svg>Message Admin</span>
                <span class="close-btn" onclick="closeMessageAdmin()"><svg class="icon"><use href="#i-close"/></svg></span>
            </div>
            <div class="chat-modal-body">
                <div id="studentChatHistory" class="chat-history-box"></div>
                <div id="quickReplies" class="quick-replies"></div>
                <form id="chatForm" onsubmit="return sendChatForm(event)" style="display:flex; gap:8px; align-items:flex-end;">
                    <textarea id="chatInput" rows="1" maxlength="1500" placeholder="Type your message..." style="flex-grow:1; padding:10px 14px; border-radius:8px; border:1px solid #ccc; font-size:13px;"></textarea>
                    <button type="submit" id="chatSendBtn" style="background:var(--navy-dark); color:#fff; border:none; padding:10px 18px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:bold; height:40px;">Send</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($pending_item_alerts)): ?>
        <div class="match-alert-overlay" id="matchAlertOverlay" role="dialog" aria-modal="true" aria-labelledby="matchAlertTitle">
            <div class="match-alert-card">
                <h2 id="matchAlertTitle">A found item may match your report</h2>
                <p>This alert stays here until you open the item.</p>
                <?php foreach ($pending_item_alerts as $alert): ?>
                    <div class="match-alert-item">
                        <div>
                            <strong><?php echo htmlspecialchars($alert['item_title']); ?></strong>
                            <small><?php echo htmlspecialchars($alert['serial_no']); ?> &middot; Found at <?php echo htmlspecialchars($alert['location']); ?></small>
                        </div>
                        <form method="POST" action="dashboard.php">
                            <input type="hidden" name="ack_missing_alert" value="1">
                            <input type="hidden" name="alert_id" value="<?php echo intval($alert['id']); ?>">
                            <button type="submit">View item</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php render_expiry_popup($expiring_items, $is_admin ? 'admin' : 'student'); ?>

    <script>
    var IS_LOGGED_IN = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
    var IS_STUDENT = <?php echo $is_student ? 'true' : 'false'; ?>;
    var MY_ID = <?php echo intval($user_id); ?>;
    var ACCOUNT_NAME = <?php echo json_encode($account_name, $json_flags); ?>;
    var ACCOUNT_EMAIL = <?php echo json_encode($account_email, $json_flags); ?>;
    var CHAT_MESSAGES = <?php echo json_encode($chat_messages, $json_flags); ?>;
    var WEB_PUSH_AVAILABLE = <?php echo $web_push_available ? 'true' : 'false'; ?>;
    var WEB_PUSH_PUBLIC_KEY = <?php echo json_encode($web_push_public_key, $json_flags); ?>;
    var WEB_PUSH_CSRF = <?php echo json_encode($web_push_csrf, $json_flags); ?>;
    var WEB_PUSH_SUBSCRIBED = <?php echo $web_push_subscribed ? 'true' : 'false'; ?>;
    var INITIAL_MATCH_ALERT_IDS = <?php echo json_encode(array_map(function($alert) { return intval($alert['id']); }, $pending_item_alerts), $json_flags); ?>;

    var GOOGLE_CLIENT_ID = <?php echo json_encode($google_enabled ? $GOOGLE_CLIENT_ID : '', $json_flags); ?>;
    var googleReady = false;
    var googleRendered = false;

    var pendingItem = null;   // item the guest was looking at before logging in
    var intake = null;        // set while the assistant is waiting for the details message
    var lastMsgId = 0;
    var flowOffered = false;

    /* ---------------------------------------------------------- Login / Register pop-up */
    function openAuthModal() {
        var m = document.getElementById("authModal");
        if (m) m.style.display = "flex";
        renderGoogleButton();
    }

    /* ---------------------------------------------------------- Google sign-in */
    function initGoogle() {
        if (!GOOGLE_CLIENT_ID || !window.google || !google.accounts) return;
        google.accounts.id.initialize({ client_id: GOOGLE_CLIENT_ID, callback: handleGoogleCredential });
        googleReady = true;
        renderGoogleButton();
    }

    function renderGoogleButton() {
        var holder = document.getElementById("googleBtn");
        if (!googleReady || googleRendered || !holder) return;
        google.accounts.id.renderButton(holder, { theme: "outline", size: "large", text: "continue_with", shape: "pill", width: 300 });
        googleRendered = true;
    }

    function handleGoogleCredential(response) {
        var fd = new FormData();
        fd.append("action", "google");
        fd.append("credential", response.credential);
        fd.append("next", pendingItem ? "item-" + pendingItem : "messages");
        showAuthError("");

        fetch("auth_ajax.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    showAuthError(data.error || "Google sign-in failed. Please try again.");
                }
            })
            .catch(function() {
                showAuthError("Could not reach the server. Please try again.");
            });
    }
    function closeAuthModal() {
        var m = document.getElementById("authModal");
        if (m) m.style.display = "none";
    }
    function switchAuthTab(tab) {
        document.getElementById("loginForm").style.display = (tab === "login") ? "block" : "none";
        document.getElementById("registerForm").style.display = (tab === "register") ? "block" : "none";
        document.getElementById("tabLogin").classList.toggle("active", tab === "login");
        document.getElementById("tabRegister").classList.toggle("active", tab === "register");
        showAuthError("");
    }
    function showAuthError(text) {
        var box = document.getElementById("authError");
        if (!box) return;
        box.textContent = text;
        box.style.display = text ? "block" : "none";
    }
    function submitAuth(e, mode) {
        e.preventDefault();
        var form = e.target;
        var fd = new FormData(form);
        fd.append("action", mode);
        fd.append("next", pendingItem ? "item-" + pendingItem : "messages");
        var btn = form.querySelector("button[type=submit]");
        var oldLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Please wait...";
        showAuthError("");

        fetch("auth_ajax.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    showAuthError(data.error || "Something went wrong. Please try again.");
                    btn.disabled = false;
                    btn.textContent = oldLabel;
                }
            })
            .catch(function() {
                showAuthError("Could not reach the server. Please try again.");
                btn.disabled = false;
                btn.textContent = oldLabel;
            });
        return false;
    }

    /* ---------------------------------------------------------- Message Admin */
    var BOT = "LCUP Assistant";

    function openMessageAdmin(itemId) {
        var item = null;
        if (itemId) {
            var el = document.getElementById("item_" + itemId);
            if (el) item = { id: itemId, serial: el.dataset.serial, title: el.dataset.title };
        }
        if (!IS_LOGGED_IN) {
            pendingItem = itemId || null;
            openAuthModal();
            return;
        }
        if (!IS_STUDENT) return;
        showChat(item);
    }

    function closeMessageAdmin() {
        document.getElementById("privateMessageModal").style.display = "none";
    }

    function chatIsOpen() {
        var m = document.getElementById("privateMessageModal");
        return m && m.style.display === "flex";
    }

    function addBubble(kind, text, who, time) {
        var box = document.getElementById("studentChatHistory");
        var row = document.createElement("div");
        row.className = "msg-row " + kind;
        var b = document.createElement("div");
        b.className = "msg-bubble " + kind;
        if (who) {
            var n = document.createElement("div");
            n.className = "msg-name";
            n.textContent = who;
            b.appendChild(n);
        }
        if (text) {
            var t = document.createElement("div");
            t.className = "msg-text";
            t.textContent = text;
            b.appendChild(t);
        }
        if (time) {
            var tm = document.createElement("div");
            tm.className = "msg-time";
            tm.textContent = time;
            b.appendChild(tm);
        }
        row.appendChild(b);
        box.appendChild(row);
        box.scrollTop = box.scrollHeight;
    }

    function setChips(list) {
        var c = document.getElementById("quickReplies");
        c.innerHTML = "";
        list.forEach(function(ch) {
            var b = document.createElement("button");
            b.type = "button";
            b.textContent = ch.label;
            b.onclick = ch.action;
            c.appendChild(b);
        });
    }

    function autosizeChat() {
        var el = document.getElementById("chatInput");
        el.style.height = "auto";
        el.style.height = Math.min(el.scrollHeight, 130) + "px";
    }

    function showChat(item) {
        document.getElementById("privateMessageModal").style.display = "flex";
        var dot = document.getElementById("msgDot");
        if (dot) dot.style.display = "none";

        if (item) {
            startIntake(item);
        } else if (!intake && !flowOffered && CHAT_MESSAGES.length === 0) {
            startIntake(null);
        } else if (!intake) {
            setChips([{ label: "Start a new ownership inquiry", action: function() { startIntake(null); } }]);
        }
        var box = document.getElementById("studentChatHistory");
        box.scrollTop = box.scrollHeight;
        document.getElementById("chatInput").focus();
    }

    /* ---------------------------------------------------------- The assistant: ONE message with a short form */
    function startIntake(item) {
        flowOffered = true;
        intake = { item: item || null };
        setChips([]);

        var msg = "Hello! I am the LCUP Lost & Found Assistant. To help the admin confirm that the item is yours, please send these details in one message:\n\n"
                + "- Name\n"
                + "- Year & Section\n"
                + "- Contact No.\n"
                + "- Email\n"
                + "- About the item/s (what it looks like, any marks or contents, and where and when you lost it)\n\n"
                + "I filled in the form in the box below. Just complete it and press Send.";
        if (item) msg = "You are asking about item " + item.serial + " (" + item.title + ").\n\n" + msg;
        addBubble("bot", msg, BOT);

        fillIntakeTemplate();
    }

    function fillIntakeTemplate() {
        var input = document.getElementById("chatInput");
        var yearLine = "Year & Section: ";
        input.value = "Name: " + ACCOUNT_NAME + "\n" + yearLine + "\nContact No.: \nEmail: " + ACCOUNT_EMAIL + "\nAbout the item/s: ";
        input.placeholder = "Fill in the details...";
        autosizeChat();
        input.focus();
        var pos = input.value.indexOf(yearLine) + yearLine.length;
        input.setSelectionRange(pos, pos);
    }

    // Reads the details out of the message the student filled in
    function parseIntake(text) {
        var m = text.match(/About the item(?:\/s|s)?\s*:([\s\S]*)$/i);
        var item = m ? m[1].trim() : "";
        var head = m ? text.slice(0, m.index) : text;
        function line(re) {
            var x = head.match(re);
            return x ? x[1].trim() : "";
        }
        return {
            name: line(/^\s*Name\s*:(.*)$/im),
            year_section: line(/^\s*Year\s*&\s*Section\s*:(.*)$/im),
            contact: line(/^\s*Contact(?:\s*No\.?)?\s*:(.*)$/im),
            email: line(/^\s*Email\s*:(.*)$/im),
            item: item
        };
    }

    function sendIntake(text) {
        var p = parseIntake(text);
        var missing = [];
        if (!p.name) missing.push("Name");
        if (!p.year_section) missing.push("Year & Section");
        if (!p.contact) missing.push("Contact No.");
        if (!p.email) missing.push("Email");
        if (!p.item) missing.push("About the item/s");
        if (missing.length) {
            addBubble("bot", "Please complete: " + missing.join(", ") + ".", BOT);
            return false;
        }
        if (!/^[0-9+()\-\s]{7,20}$/.test(p.contact)) {
            addBubble("bot", "That does not look like a contact number. Please use numbers only, for example 09123456789.", BOT);
            return false;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.email)) {
            addBubble("bot", "That does not look like an email address. Please check it, for example name@email.com.", BOT);
            return false;
        }

        var fd = new FormData();
        fd.append("action", "inquiry");
        fd.append("name", p.name);
        fd.append("year_section", p.year_section);
        fd.append("contact", p.contact);
        fd.append("email", p.email);
        fd.append("item", p.item);
        fd.append("item_id", intake.item ? intake.item.id : 0);

        postChat(fd, function(message) {
            addBubble("me", message.message, "", message.time);
            addBubble("bot", "Your details were sent to the admin. Please wait for the admin to reply in this chat. You can keep typing below.", BOT);
            intake = null;
            document.getElementById("chatInput").value = "";
            document.getElementById("chatInput").placeholder = "Type your message...";
            autosizeChat();
        });
        return false;
    }

    /* ---------------------------------------------------------- Sending */
    var sending = false;

    function postChat(fd, onSuccess) {
        if (sending) return;
        sending = true;
        document.getElementById("chatSendBtn").disabled = true;
        fetch("student_chat.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    lastMsgId = Math.max(lastMsgId, data.message.id);
                    onSuccess(data.message);
                } else {
                    addBubble("bot", data.error || "Your message could not be sent.", BOT);
                }
            })
            .catch(function() {
                addBubble("bot", "Could not reach the server. Please try again.", BOT);
            })
            .then(function() {
                sending = false;
                document.getElementById("chatSendBtn").disabled = false;
            });
    }

    function sendChatForm(e) {
        e.preventDefault();
        var input = document.getElementById("chatInput");
        var text = input.value.trim();
        if (!text) return false;

        if (intake) return sendIntake(text);

        var fd = new FormData();
        fd.append("action", "send");
        fd.append("message", text);
        postChat(fd, function(message) {
            addBubble("me", message.message, "", message.time);
            input.value = "";
            autosizeChat();
        });
        return false;
    }

    /* ---------------------------------------------------------- Replies from the admin */
    var matchAlertSignature = "";
    var knownMatchAlerts = {};
    INITIAL_MATCH_ALERT_IDS.forEach(function(id) { knownMatchAlerts[id] = true; });

    function showMatchAlerts(alerts) {
        var signature = alerts.map(function(alert) { return alert.id; }).join(",");
        var overlay = document.getElementById("matchAlertOverlay");
        if (!signature) {
            if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
            matchAlertSignature = "";
            return;
        }
        if (overlay && signature === matchAlertSignature) return;

        if (!overlay) {
            overlay = document.createElement("div");
            overlay.id = "matchAlertOverlay";
            overlay.className = "match-alert-overlay";
            overlay.setAttribute("role", "dialog");
            overlay.setAttribute("aria-modal", "true");
            overlay.setAttribute("aria-labelledby", "matchAlertTitle");
            document.body.appendChild(overlay);
        }
        overlay.innerHTML = "";

        var card = document.createElement("div");
        card.className = "match-alert-card";
        var heading = document.createElement("h2");
        heading.id = "matchAlertTitle";
        heading.textContent = alerts.length === 1 ? "A found item may match your report" : "Found items may match your reports";
        card.appendChild(heading);

        var note = document.createElement("p");
        note.textContent = "This alert stays here until you open the item.";
        card.appendChild(note);

        alerts.forEach(function(alert) {
            var item = document.createElement("div");
            item.className = "match-alert-item";
            var details = document.createElement("div");
            var title = document.createElement("strong");
            title.textContent = alert.item_title;
            var meta = document.createElement("small");
            meta.textContent = alert.serial_no + " - Found at " + alert.location;
            details.appendChild(title);
            details.appendChild(meta);
            item.appendChild(details);

            var form = document.createElement("form");
            form.method = "POST";
            form.action = "dashboard.php";
            var action = document.createElement("input");
            action.type = "hidden";
            action.name = "ack_missing_alert";
            action.value = "1";
            var alertId = document.createElement("input");
            alertId.type = "hidden";
            alertId.name = "alert_id";
            alertId.value = alert.id;
            var button = document.createElement("button");
            button.type = "submit";
            button.textContent = "View item";
            form.appendChild(action);
            form.appendChild(alertId);
            form.appendChild(button);
            item.appendChild(form);
            card.appendChild(item);
        });

        overlay.appendChild(card);
        matchAlertSignature = signature;
    }

    function pollMessages() {
        if (!IS_STUDENT) return;
        fetch("student_chat.php?action=history&after_id=" + lastMsgId, { credentials: "same-origin" })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    console.error(data.error || "Could not load messages and item alerts.");
                    return;
                }
                data.messages.forEach(function(m) {
                    lastMsgId = Math.max(lastMsgId, m.id);
                    if (m.sender_id != MY_ID) {
                        addBubble("admin", m.message, m.sender_name, m.time);
                        if (m.message.indexOf("A newly posted found item may match your missing-item report.") !== 0) {
                            triggerDeviceNotification(m.id);
                        }
                        if (!chatIsOpen()) {
                            var dot = document.getElementById("msgDot");
                            if (dot) dot.style.display = "inline-block";
                        }
                    }
                });
                var alerts = data.alerts || [];
                alerts.forEach(function(alert) {
                    if (!knownMatchAlerts[alert.id]) {
                        knownMatchAlerts[alert.id] = true;
                        triggerMatchDeviceNotification(alert);
                    }
                });
                showMatchAlerts(alerts);
            })
            .catch(function(error) {
                console.error("Could not poll messages and item alerts:", error);
            });
    }

    function showDeviceNotification(title, body, tag, url) {
        if (!("Notification" in window) || Notification.permission !== "granted") return;
        var options = {
            body: body,
            icon: "logo.jpg",
            tag: tag,
            data: { url: url }
        };
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.ready.then(function(registration) {
                return registration.showNotification(title, options);
            }).catch(function(error) {
                console.error("Could not show the device notification:", error);
            });
        } else {
            var notification = new Notification(title, options);
            notification.onclick = function() { window.focus(); showChat(null); };
        }
    }

    function triggerDeviceNotification(messageId) {
        showDeviceNotification("New message from the Admin", "The admin replied to your message.",
            "admin-reply-" + messageId, "dashboard.php?open=messages");
    }

    function triggerMatchDeviceNotification(alert) {
        showDeviceNotification("A found item may match your report",
            alert.item_title + " - Found at " + alert.location,
            "missing-item-" + alert.id, "dashboard.php#item_" + alert.item_id);
    }

    function pushBase64ToBytes(value) {
        var padding = "=".repeat((4 - value.length % 4) % 4);
        var base64 = (value + padding).replace(/-/g, "+").replace(/_/g, "/");
        var raw = window.atob(base64);
        var bytes = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
        return bytes;
    }

    function pushSubscriptionRequest(action, subscription) {
        return fetch("web_push_subscription.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: action,
                csrfToken: WEB_PUSH_CSRF,
                subscription: subscription,
                endpoint: subscription ? subscription.endpoint : ""
            })
        }).then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok || !data.success) throw new Error(data.error || "Could not update device notifications.");
                return data;
            });
        });
    }

    function updateDevicePushUI(statusText) {
        var button = document.getElementById("devicePushButton");
        var status = document.getElementById("devicePushStatus");
        if (button) {
            button.textContent = WEB_PUSH_SUBSCRIBED ? "Turn off device notifications" : "Enable device notifications";
            button.disabled = !WEB_PUSH_AVAILABLE || !("Notification" in window) || !("serviceWorker" in navigator) ||
                !("PushManager" in window) || !window.isSecureContext;
        }
        if (status && statusText) status.textContent = statusText;
    }

    function toggleDeviceNotifications() {
        var status = document.getElementById("devicePushStatus");
        var button = document.getElementById("devicePushButton");
        if (!WEB_PUSH_AVAILABLE || !("Notification" in window) || !("serviceWorker" in navigator) ||
            !("PushManager" in window) || !window.isSecureContext) {
            if (status) status.textContent = "Device notifications require a supported browser and a secure connection (HTTPS or localhost).";
            return;
        }
        if (button) button.disabled = true;
        if (status) status.textContent = WEB_PUSH_SUBSCRIBED ? "Turning off notifications..." : "Enabling notifications...";

        navigator.serviceWorker.register("service-worker.js").then(function() {
            return navigator.serviceWorker.ready;
        }).then(function(registration) {
            return registration.pushManager.getSubscription().then(function(subscription) {
                if (WEB_PUSH_SUBSCRIBED) {
                    if (!subscription) throw new Error("This browser no longer has the saved subscription. Reload and enable notifications again.");
                    return pushSubscriptionRequest("unsubscribe", subscription).then(function() {
                        return subscription.unsubscribe();
                    }).then(function(unsubscribed) {
                        if (!unsubscribed) throw new Error("The browser could not turn off this device subscription.");
                        WEB_PUSH_SUBSCRIBED = false;
                        updateDevicePushUI("Device notifications are turned off on this browser.");
                    });
                }
                var permission = Notification.permission === "granted"
                    ? Promise.resolve("granted")
                    : Notification.requestPermission();
                return permission.then(function(result) {
                    if (result !== "granted") throw new Error("Allow notifications in your browser settings to receive device alerts.");
                    return subscription || registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: pushBase64ToBytes(WEB_PUSH_PUBLIC_KEY)
                    });
                }).then(function(newSubscription) {
                    return pushSubscriptionRequest("subscribe", newSubscription).then(function() {
                        WEB_PUSH_SUBSCRIBED = true;
                        updateDevicePushUI("Device notifications are enabled on this browser.");
                    });
                });
            });
        }).catch(function(error) {
            if (status) status.textContent = error.message || "Could not enable device notifications.";
            console.error("Could not update device notifications:", error);
        }).then(function() {
            updateDevicePushUI();
            if (button) button.disabled = !WEB_PUSH_AVAILABLE || !("Notification" in window) || !("serviceWorker" in navigator) ||
                !("PushManager" in window) || !window.isSecureContext;
        });
    }

    /* ---------------------------------------------------------- Voice search */
    function startVoiceSearch() {
        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            var recognition = new SpeechRecognition();
            recognition.lang = 'en-US';
            recognition.start();
            recognition.onresult = function(event) {
                document.getElementById('searchInput').value = event.results[0][0].transcript;
                document.querySelector('.search-bar-container').submit();
            };
        } else {
            alert("Voice recognition is not supported in this browser.");
        }
    }

    /* ---------------------------------------------------------- Start-up */
    window.addEventListener('DOMContentLoaded', function() {
        if (IS_STUDENT) {
            updateDevicePushUI();
            if (!window.isSecureContext) {
                updateDevicePushUI("Device notifications need HTTPS (or localhost).");
            } else if (!("PushManager" in window)) {
                updateDevicePushUI("This browser does not support background device notifications.");
            } else if ("serviceWorker" in navigator) {
                navigator.serviceWorker.register("service-worker.js").catch(function(error) {
                    console.error("Could not register the notification service worker:", error);
                });
            }
            // Show the saved conversation
            CHAT_MESSAGES.forEach(function(m) {
                lastMsgId = Math.max(lastMsgId, m.id);
                if (m.sender_id == MY_ID) addBubble("me", m.message, "", m.time);
                else addBubble("admin", m.message, m.sender_name, m.time);
            });
            pollMessages();
            setInterval(pollMessages, 5000);

            var chatBox = document.getElementById("chatInput");
            chatBox.addEventListener("input", autosizeChat);
            chatBox.addEventListener("keydown", function(e) {
                // Enter sends, Shift+Enter makes a new line
                if (e.key === "Enter" && !e.shiftKey) {
                    sendChatForm(e);
                }
            });
        }

        // After logging in, continue where the student was
        var open = new URLSearchParams(window.location.search).get('open');
        if (open && IS_STUDENT) {
            if (open === 'messages') {
                showChat(null);
            } else {
                var m = open.match(/^item-(\d+)$/);
                if (m) openMessageAdmin(parseInt(m[1], 10));
            }
            if (window.history && history.replaceState) history.replaceState(null, "", "dashboard.php");
        }
        if (open && !IS_LOGGED_IN && (open === 'login' || open === 'register')) {
            openAuthModal();
            if (open === 'register') switchAuthTab('register');
            if (window.history && history.replaceState) history.replaceState(null, "", "dashboard.php");
        }
    });
    </script>
    <?php if ($google_enabled && !$is_logged_in): ?>
        <script src="https://accounts.google.com/gsi/client" async defer onload="initGoogle()"></script>
    <?php endif; ?>
</body>
</html>