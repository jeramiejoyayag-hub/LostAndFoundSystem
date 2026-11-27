<?php
ini_set('display_errors', 1); error_reporting(E_ALL);
session_start();
include 'db.php';
include 'retention.php';
include 'surrender_fields.php';
include 'claim_fields.php';
include 'missing_item_alerts.php';
include 'item_storage.php';

// Verify Admin Privileges
if (!isset($_SESSION['user_id'])) {
    header("Location: dashboard.php?open=login");
    exit();
}

$user_role = $_SESSION['role'] ?? $_SESSION['usertype'] ?? 'student';
if ($user_role !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

if (!ensure_item_surrender_columns($conn)) {
    http_response_code(500);
    exit("Could not prepare surrender details: " . htmlspecialchars($conn->error));
}
if (!ensure_item_claims_table($conn)) {
    http_response_code(500);
    exit("Could not prepare claim details: " . htmlspecialchars($conn->error));
}
if (!ensure_missing_item_reports_table($conn)) {
    http_response_code(500);
    exit("Could not prepare missing-item alerts: " . htmlspecialchars($conn->error));
}

$admin_id = intval($_SESSION['user_id']);
if (empty($_SESSION['expiry_ack_csrf'])) {
    $_SESSION['expiry_ack_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['claim_csrf'])) {
    $_SESSION['claim_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['thread_archive_csrf'])) {
    $_SESSION['thread_archive_csrf'] = bin2hex(random_bytes(32));
}
$retention = retention_settings($conn);
archive_expired_items($conn, $retention['item_days']);
$expiring_items = expiring_items($conn, $retention['item_days'], $retention['warn_days']);

// Message shown after an action (set before the redirect, so refreshing never re-submits a form)
$admin_msg = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$flash = '';

function notify_user($conn, $uid, $title, $msg) {
    $n = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    if ($n) {
        $n->bind_param("iss", $uid, $title, $msg);
        $n->execute();
        $n->close();
    }
}

// Detect when the whole form was dropped because it exceeded post_max_size
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-alert'/></svg>The upload was too large and nothing was saved. Please choose smaller photos. (Server limit: post_max_size = " . ini_get('post_max_size') . ", upload_max_filesize = " . ini_get('upload_max_filesize') . ")</div>";
}

// ---------------------------------------------------------------
// POST A NEW ITEM (only the admin can post items)
// ---------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['post_item'])) {
    $item_type = 'Found';
    $title = trim($_POST['title'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $valid_locations = [
        'MR Building', 'SA Building', 'College of Medicine', 'Andrada Gym',
        'Cassiciacum', 'Parking Lot', 'Carthage', 'Kalinangan', 'Sto. Nino Building',
        'Padre Pio Building', 'Chapel', 'Mini Forest Garden', 'Agostino Building',
    ];
    $surrendered_by = trim($_POST['surrendered_by'] ?? '');
    $surrendered_to = trim($_POST['surrendered_to'] ?? '');
    $surrendered_at_input = trim($_POST['surrendered_at'] ?? '');
    $surrenderer_contact = trim($_POST['surrenderer_contact'] ?? '');
    $surrendered_at = null;
    $surrender_time_valid = false;
    if ($surrendered_at_input !== '') {
        $surrender_time = DateTime::createFromFormat('Y-m-d\TH:i', $surrendered_at_input);
        if ($surrender_time && $surrender_time->format('Y-m-d\TH:i') === $surrendered_at_input) {
            $surrendered_at = $surrender_time->format('Y-m-d H:i:s');
            $surrender_time_valid = true;
        }
    }

    if ($title === '' || $location === '' || $description === '') {
        $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-alert'/></svg>Please fill in the title, location and description.</div>";
    } elseif (!in_array($location, $valid_locations, true)) {
        $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-alert'/></svg>Select a valid item location.</div>";
    } elseif ($surrendered_by === '' || $surrendered_to === '' || !$surrender_time_valid || $surrenderer_contact === '') {
        $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-alert'/></svg>Complete all surrender details: who surrendered the item, who received it, the time, and contact.</div>";
    } elseif (mb_strlen($surrendered_by, 'UTF-8') > 255 ||
        mb_strlen($surrendered_to, 'UTF-8') > 255 || mb_strlen($surrenderer_contact, 'UTF-8') > 50) {
        $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-alert'/></svg>Surrender names must be 255 characters or fewer and contact must be 50 characters or fewer.</div>";
    } else {
        $saved_images = [];
        $upload_errors = [];
        $max_photos = 3;

        $upload_error_texts = [
            UPLOAD_ERR_INI_SIZE   => 'file is larger than the server limit (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE  => 'file is larger than the form limit',
            UPLOAD_ERR_PARTIAL    => 'file was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'server temp folder is missing',
            UPLOAD_ERR_CANT_WRITE => 'server could not write the file to disk',
            UPLOAD_ERR_EXTENSION  => 'upload was blocked by a PHP extension',
        ];

        if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

            $file_count = count($_FILES['images']['name']);
            for ($i = 0; $i < $file_count; $i++) {
                $orig_name = $_FILES['images']['name'][$i];
                $err = $_FILES['images']['error'][$i];

                if ($err === UPLOAD_ERR_NO_FILE) continue;

                if (count($saved_images) >= $max_photos) {
                    $upload_errors[] = htmlspecialchars($orig_name) . " was not attached (maximum of $max_photos photos).";
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $reason = $upload_error_texts[$err] ?? ('error code ' . $err);
                    $upload_errors[] = htmlspecialchars($orig_name) . " failed: " . $reason . ".";
                    continue;
                }

                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) {
                    $upload_errors[] = htmlspecialchars($orig_name) . " was skipped (only JPG, PNG and WEBP are allowed).";
                    continue;
                }

                $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
                $content_type = mime_content_type($_FILES['images']['tmp_name'][$i]) ?: 'application/octet-stream';
                if (item_storage_store_file($_FILES['images']['tmp_name'][$i], $new_name, $content_type, true)) {
                    $saved_images[] = $new_name;
                } else {
                    $upload_errors[] = htmlspecialchars($orig_name) . " could not be saved to image storage.";
                }
            }
        }

        $primary_image = !empty($saved_images) ? $saved_images[0] : NULL;

        $inst = $conn->prepare("INSERT INTO items (user_id, title, location, image, description, item_type, status, surrendered_by, surrendered_to, surrendered_at, surrenderer_contact) VALUES (?, ?, ?, ?, ?, ?, 'Available', ?, ?, ?, ?)");
        if ($inst) {
            $inst->bind_param("isssssssss", $admin_id, $title, $location, $primary_image, $description, $item_type, $surrendered_by, $surrendered_to, $surrendered_at, $surrenderer_contact);
            if ($inst->execute()) {
                $new_item_id = $inst->insert_id;

                // AUTOMATIC SERIAL NUMBER, e.g. LF-2026-0001 (based on the item's unique ID, so it never repeats)
                $serial = 'LF-' . date('Y') . '-' . str_pad($new_item_id, 4, '0', STR_PAD_LEFT);
                $sn = $conn->prepare("UPDATE items SET serial_no = ? WHERE id = ?");
                if ($sn) {
                    $sn->bind_param("si", $serial, $new_item_id);
                    if (!$sn->execute()) {
                        $upload_errors[] = "Serial number could not be saved: " . htmlspecialchars($sn->error);
                    }
                    $sn->close();
                } else {
                    $upload_errors[] = "Serial number could not be saved. Did you run the SQL to add the serial_no column? (" . htmlspecialchars($conn->error) . ")";
                }

                if (!empty($saved_images)) {
                    $p_stmt = $conn->prepare("INSERT INTO item_photos (item_id, photo_path) VALUES (?, ?)");
                    if ($p_stmt) {
                        foreach ($saved_images as $photo_name) {
                            $p_stmt->bind_param("is", $new_item_id, $photo_name);
                            if (!$p_stmt->execute()) {
                                $upload_errors[] = "Database error saving a photo: " . htmlspecialchars($p_stmt->error);
                            }
                        }
                        $p_stmt->close();
                    } else {
                        $upload_errors[] = "Database error (item_photos): " . htmlspecialchars($conn->error);
                    }
                }

                $device_notifications_sent = web_push_notify_all_students(
                    $conn,
                    "New item posted",
                    "A new item has been posted. Check it out.",
                    "dashboard.php#item_" . $new_item_id,
                    "new-item-" . $new_item_id
                );
                if ($device_notifications_sent === false) {
                    error_log("The item was posted, but its device notifications could not be sent.");
                }

                $matched_students = notify_matching_missing_item_reports(
                    $conn,
                    $admin_id,
                    $new_item_id,
                    $title,
                    $description,
                    $location,
                    $serial
                );
                if ($matched_students === false) {
                    $upload_errors[] = "The item was posted, but matching students could not be notified.";
                }

                $flash = "<div class='alert alert-success'><svg class='icon'><use href='#i-check'/></svg>Item posted with serial number <strong>" . htmlspecialchars($serial) . "</strong> (" . count($saved_images) . " photo(s) attached).</div>";
                if ($matched_students > 0) {
                    $flash .= "<div class='alert alert-success'>Potential match notifications sent to " . $matched_students . " student" . ($matched_students === 1 ? '' : 's') . ".</div>";
                }
            } else {
                $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-x'/></svg>Could not post the item: " . htmlspecialchars($inst->error) . "</div>";
            }
            $inst->close();
        } else {
            $flash = "<div class='alert alert-danger'><svg class='icon'><use href='#i-x'/></svg>Database error: " . htmlspecialchars($conn->error) . "</div>";
        }

        if (!empty($upload_errors)) {
            $flash .= "<div class='alert alert-danger'>" . implode('<br>', $upload_errors) . "</div>";
        }
    }
}

// ---------------------------------------------------------------
// SAVE ITEM RETENTION SETTINGS
// ---------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_retention'])) {
    $item_days = filter_var($_POST['item_days'] ?? null, FILTER_VALIDATE_INT);
    $warn_days = filter_var($_POST['warn_days'] ?? null, FILTER_VALIDATE_INT);
    if ($item_days === false || $item_days < 1 || $item_days > 3650 ||
        $warn_days === false || $warn_days < 0 || $warn_days > 3650 ||
        ($item_days !== false && $warn_days !== false && $warn_days > $item_days)) {
        $flash = "<div class='alert alert-danger'>Enter a posting duration from 1 to 3650 days and a warning window from 0 days up to the posting duration.</div>";
    } elseif (save_retention($conn, $item_days, $warn_days)) {
        $_SESSION['admin_flash'] = "<div class='alert alert-success'>Posting duration and warning window saved.</div>";
        header("Location: admin.php");
        exit();
    } else {
        $flash = "<div class='alert alert-danger'>Could not save the retention settings. Please check the database connection and try again.</div>";
    }
}

// ---------------------------------------------------------------
// ARCHIVE AN ITEM (removes it from the dashboard)
// ---------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_item'])) {
    $archive_id = intval($_POST['item_id'] ?? 0);
    if ($archive_id > 0) {
        $conn->query("UPDATE items SET status = 'Archived' WHERE id = $archive_id");
        $flash = "<div class='alert alert-success'><svg class='icon'><use href='#i-check'/></svg>Item archived. It no longer appears on the dashboard.</div>";
    }
}

// ---------------------------------------------------------------
// MARK AN ITEM AS CLAIMED (after the owner was verified in the chat)
// ---------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['claim_item'])) {
    $submitted_claim_csrf = $_POST['claim_csrf'] ?? '';
    if (!is_string($submitted_claim_csrf) || !hash_equals($_SESSION['claim_csrf'], $submitted_claim_csrf)) {
        http_response_code(403);
        exit("Invalid claim confirmation request.");
    }

    $claim_id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $claimant_name = is_string($_POST['claimant_name'] ?? null) ? trim($_POST['claimant_name']) : '';
    $claimant_year = is_string($_POST['claimant_year'] ?? null) ? trim($_POST['claimant_year']) : '';
    $claimant_contact = is_string($_POST['claimant_contact'] ?? null) ? trim($_POST['claimant_contact']) : '';

    if ($claim_id === false || $claim_id < 1) {
        $flash = "<div class='alert alert-danger'>Select a valid item to record the claim.</div>";
    } elseif ($claimant_name === '' || $claimant_year === '') {
        $flash = "<div class='alert alert-danger'>Enter the claimant's name and year/program.</div>";
    } elseif (mb_strlen($claimant_name, 'UTF-8') > 255 ||
        mb_strlen($claimant_year, 'UTF-8') > 100 ||
        mb_strlen($claimant_contact, 'UTF-8') > 255) {
        $flash = "<div class='alert alert-danger'>Claimant name, year/program, or contact information is too long.</div>";
    } else {
        $claim_update = $conn->prepare(
            "UPDATE items SET status = 'Claimed'
             WHERE id = ? AND status NOT IN ('Archived', 'Claimed')"
        );
        $claim_insert = $conn->prepare(
            "INSERT INTO item_claims (item_id, claimant_name, claimant_year, claimant_contact, claimed_at)
             VALUES (?, ?, ?, ?, NOW())"
        );
        if (!$claim_update || !$claim_insert) {
            error_log("Could not prepare item claim: " . $conn->error);
            $flash = "<div class='alert alert-danger'>Could not record the claim. Please try again.</div>";
            if ($claim_update) $claim_update->close();
            if ($claim_insert) $claim_insert->close();
        } elseif (!$conn->begin_transaction()) {
            error_log("Could not start item claim transaction: " . $conn->error);
            $flash = "<div class='alert alert-danger'>Could not record the claim. Please try again.</div>";
            $claim_update->close();
            $claim_insert->close();
        } else {
            $claim_update->bind_param("i", $claim_id);
            if (!$claim_update->execute()) {
                error_log("Could not mark item as claimed: " . $claim_update->error);
                $conn->rollback();
                $flash = "<div class='alert alert-danger'>Could not record the claim. Please try again.</div>";
            } elseif ($claim_update->affected_rows !== 1) {
                $conn->rollback();
                $flash = "<div class='alert alert-danger'>This item could not be claimed. It may already be claimed, archived, or no longer available.</div>";
            } else {
                $claimant_contact_value = $claimant_contact === '' ? null : $claimant_contact;
                $claim_insert->bind_param("isss", $claim_id, $claimant_name, $claimant_year, $claimant_contact_value);
                if (!$claim_insert->execute()) {
                    error_log("Could not record claimant details: " . $claim_insert->error);
                    $conn->rollback();
                    $flash = "<div class='alert alert-danger'>Could not record the claim. Please try again.</div>";
                } elseif (!$conn->commit()) {
                    error_log("Could not commit item claim: " . $conn->error);
                    $conn->rollback();
                    $flash = "<div class='alert alert-danger'>Could not record the claim. Please try again.</div>";
                } else {
                    $flash = "<div class='alert alert-success'><svg class='icon'><use href='#i-check'/></svg>Claim details recorded and item marked as claimed.</div>";
                }
            }
            $claim_update->close();
            $claim_insert->close();
        }
    }
}

// Redirect after any action so a page refresh does not submit the form again
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ack_expiry_notifications'])) {
    if (!hash_equals($_SESSION['expiry_ack_csrf'], $_POST['expiry_ack_csrf'] ?? '')) {
        http_response_code(403);
        exit("Invalid expiry notification request.");
    }
    $acknowledge = $conn->prepare("UPDATE item_expiry_notifications SET read_at = NOW() WHERE admin_id = ? AND read_at IS NULL");
    if ($acknowledge) {
        $acknowledge->bind_param("i", $admin_id);
        if ($acknowledge->execute()) {
            $flash = "<div class='alert alert-success'>Expired-item notifications marked as reviewed.</div>";
        } else {
            error_log("Could not acknowledge item expiry notifications: " . $acknowledge->error);
            $flash = "<div class='alert alert-danger'>Could not mark expiry notifications as reviewed.</div>";
        }
        $acknowledge->close();
    } else {
        error_log("Could not prepare item expiry notification acknowledgement: " . $conn->error);
        $flash = "<div class='alert alert-danger'>Could not mark expiry notifications as reviewed.</div>";
    }
}

if ($flash !== '') {
    $_SESSION['admin_flash'] = $flash;
    header("Location: admin.php");
    exit();
}

// Admin Metrics
$count_total_posted = $conn->query("SELECT COUNT(*) as total FROM items WHERE status != 'Archived'")->fetch_assoc()['total'];
$count_unclaimed = $conn->query("SELECT COUNT(*) as total FROM items WHERE status NOT IN ('Archived', 'Claimed')")->fetch_assoc()['total'];
$count_unread = $conn->query("SELECT COUNT(*) as total FROM messages WHERE receiver_id = $admin_id AND is_read = 0")->fetch_assoc()['total'];

// Query Feeds
$posted_items = $conn->query(
    "SELECT i.*, c.claimant_name AS recorded_claimant_name,
            c.claimant_year AS recorded_claimant_year,
            c.claimant_contact AS recorded_claimant_contact,
            c.claimed_at AS recorded_claimed_at
     FROM items i
     LEFT JOIN item_claims c ON c.item_id = i.id
     WHERE i.status != 'Archived'
     ORDER BY i.created_at DESC LIMIT 30"
);
$expiry_notifications = [];
$expiry_notification_query = $conn->prepare(
    "SELECT item_title, serial_no, expired_at
     FROM item_expiry_notifications
     WHERE admin_id = ? AND read_at IS NULL
     ORDER BY expired_at ASC"
);
if (!$expiry_notification_query) {
    error_log("Could not prepare item expiry notifications: " . $conn->error);
    http_response_code(500);
    exit("Expiry notifications are temporarily unavailable.");
}
$expiry_notification_query->bind_param("i", $admin_id);
if (!$expiry_notification_query->execute()) {
    error_log("Could not load item expiry notifications: " . $expiry_notification_query->error);
    $expiry_notification_query->close();
    http_response_code(500);
    exit("Expiry notifications are temporarily unavailable.");
}
$expiry_notification_result = $expiry_notification_query->get_result();
while ($notification = $expiry_notification_result->fetch_assoc()) {
    $expiry_notifications[] = $notification;
}
$expiry_notification_query->close();

// Fetch Student Threads with Unread Badges
$thread_archive_table = $conn->query(
    "CREATE TABLE IF NOT EXISTS admin_archived_threads (
        admin_id INT NOT NULL,
        student_id INT NOT NULL,
        archived_at DATETIME NOT NULL,
        PRIMARY KEY (admin_id, student_id)
    ) ENGINE=InnoDB"
);
if (!$thread_archive_table) {
    error_log("Could not prepare archived student conversations: " . $conn->error);
    http_response_code(500);
    exit("Archived student conversations are temporarily unavailable.");
}
$student_threads = $conn->query("
    SELECT DISTINCT u.id as student_id, u.full_name,
    (SELECT COUNT(*) FROM messages m2 WHERE m2.sender_id = u.id AND m2.receiver_id = $admin_id AND m2.is_read = 0) as unread_count
    FROM messages m 
    JOIN users u ON (m.sender_id = u.id OR m.receiver_id = u.id) 
    WHERE u.id != $admin_id
    AND NOT EXISTS (
        SELECT 1 FROM admin_archived_threads a
        WHERE a.admin_id = $admin_id
          AND a.student_id = u.id
          AND a.archived_at >= (
              SELECT MAX(mm.created_at) FROM messages mm
              WHERE (mm.sender_id = u.id AND mm.receiver_id = $admin_id)
                 OR (mm.sender_id = $admin_id AND mm.receiver_id = u.id)
          )
    )
    ORDER BY unread_count DESC, m.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Control Center - Findersync Lost and Found System</title>
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
            overflow: hidden;
            transition: width 0.2s ease, flex-basis 0.2s ease;
        }

        body.sidebar-collapsed .sidebar { width: 0; flex-basis: 0; border-radius: 0; box-shadow: none; }

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
            margin-bottom: 15px;
            flex-shrink: 0;
        }

        .top-header-title { display: flex; align-items: center; gap: 12px; }
        .top-header-title h1 { color: var(--navy-dark); margin: 0; font-size: 18px; font-weight: 800; }
        .top-header-title p { margin: 2px 0 0 0; color: #6b7280; font-size: 11px; font-weight: 600; }
        .sidebar-toggle { width: 36px; height: 36px; flex-shrink: 0; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: var(--navy-dark); font-size: 19px; cursor: pointer; }
        .sidebar-toggle:hover { background: #eff6ff; }
        .sidebar-toggle:focus-visible { outline: 2px solid var(--navy-light); outline-offset: 2px; }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
            flex-shrink: 0;
        }

        .action-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            cursor: pointer;
            border: 1px solid #e5e7eb;
            text-decoration: none;
            color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .action-card .icon-box { font-size: 20px; }
        .action-card span { font-size: 13px; font-weight: 700; color: var(--navy-dark); }

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
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            flex-grow: 1;
            min-height: 0;
        }

        .scrollable-column { overflow-y: auto; padding-right: 8px; height: 100%; }
        .scrollable-column::-webkit-scrollbar { width: 6px; }
        .scrollable-column::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .panel-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.03);
            border: 1px solid #e5e7eb;
            margin-bottom: 15px;
        }

        .panel-title { margin-top: 0; margin-bottom: 12px; font-size: 14px; font-weight: 800; color: var(--navy-dark); }

        .request-item-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            background: #f8fafc;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .request-item-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border-color: var(--navy-light);
        }

        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            margin-right: 6px;
            vertical-align: middle;
        }
        .type-badge.missing { background: #fef3c7; color: #92400e; }
        .type-badge.found { background: #dbeafe; color: #1e40af; }

        .alert { padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 12px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-danger { background: #fee2e2; color: #991b1b; }

        .modal-overlay {
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

        .modal-content {
            background: #ffffff;
            width: 90%;
            max-width: 650px;
            height: 520px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: var(--navy-dark);
            color: #ffffff;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 15px;
            font-weight: 700;
        }

        .modal-header span.close-btn { cursor: pointer; font-size: 18px; }

        .modal-body {
            padding: 18px;
            display: flex;
            gap: 15px;
            flex-grow: 1;
            height: calc(100% - 50px);
        }

        .student-list {
            width: 180px;
            border-right: 1px solid #e2e8f0;
            overflow-y: auto;
            padding-right: 10px;
        }

        .student-item {
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            margin-bottom: 4px;
            background: #f1f5f9;
            font-weight: normal;
            color: #475569;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .student-item.unread {
            font-weight: 800 !important;
            background: #eff6ff;
            color: var(--navy-dark);
            border-left: 3px solid var(--navy-dark);
        }

        .unread-badge {
            background: #dc2626;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .sidebar-menu .unread-badge { margin-left: auto; flex-shrink: 0; }

        .student-item:hover, .student-item.active {
            background: var(--navy-light);
            color: #fff;
        }

        .chat-window { flex-grow: 1; display: flex; flex-direction: column; }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            background: #f8fafc;
            margin-bottom: 10px;
        }

        .inspect-modal-content {
            background: #ffffff;
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .inspect-modal-body { padding: 20px; overflow-y: auto; }

        .inspect-photo-gallery {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding: 8px 0;
            margin-bottom: 12px;
        }

        .inspect-photo-box { display: flex; flex-direction: column; align-items: center; }

        .inspect-photo-item {
            height: 180px;
            width: auto;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            object-fit: contain;
            background: #f8fafc;
        }

        .inspect-photo-label {
            font-size: 10px;
            font-weight: bold;
            color: var(--navy-dark);
            margin-top: 4px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        .detail-row span.label { font-weight: bold; color: #64748b; }
        .detail-row span.val { color: #1e293b; font-weight: 600; }

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

        /* ===== Generate Report pop-up ===== */
        #reportModal .modal-content { max-width: 420px; height: auto; }
        #retentionModal .modal-content { max-width: 420px; height: auto; }
        #claimModal .modal-content { max-width: 520px; height: auto; max-height: 90vh; overflow-y: auto; }
        .report-body { padding: 18px 20px 20px; }
        .report-body p { margin: 0 0 14px 0; font-size: 12px; color: #64748b; line-height: 1.5; }
        .report-body label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 3px; }
        .report-body select, .report-body input[type=date] {
            width: 100%;
            padding: 9px 10px;
            margin-bottom: 12px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            font-size: 13px;
            font-family: inherit;
            outline: none;
        }
        .report-row { display: flex; gap: 10px; }
        .report-row > div { flex: 1; min-width: 0; }
        .report-submit {
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
        .claim-form { padding: 18px 20px 20px; overflow-y: auto; }
        .claim-form p { margin: 0 0 14px; color: #64748b; font-size: 13px; }
        .claim-form label { display: block; margin: 0 0 4px; font-size: 12px; font-weight: 700; }
        .claim-form input {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
        }
        .claim-form-actions { display: flex; gap: 10px; margin-top: 4px; }
        .claim-form-actions button { flex: 1; padding: 10px; border: 0; border-radius: 7px; font-weight: 700; cursor: pointer; }
        .claim-confirm { background: #16a34a; color: #fff; }
        .claim-cancel { background: #e2e8f0; color: #334155; }
        .report-submit:hover { background: var(--navy-light); }
        .retention-body { padding: 18px 20px 20px; }
        .retention-body p { margin: 0 0 14px; font-size: 12px; color: #64748b; line-height: 1.5; }
        .retention-body label { display: block; font-size: 11px; font-weight: 700; margin-bottom: 4px; }
        .retention-body input { width: 100%; padding: 9px 10px; margin-bottom: 12px; border-radius: 6px; border: 1px solid #d1d5db; font-size: 13px; font-family: inherit; }
        .expiry-notification-list { max-height: 130px; overflow-y: auto; margin: 0 0 10px; padding: 0; list-style: none; }
        .expiry-notification-list li { padding: 7px 0; border-bottom: 1px solid #e2e8f0; font-size: 12px; }
        .expiry-notification-list li:last-child { border-bottom: 0; }
        @media (max-width: 1000px) {
            html, body { height: auto; min-height: 100%; overflow: auto; }
            .main-wrapper { height: auto; overflow: visible; }
            .content-split { grid-template-columns: 1fr; }
            .scrollable-column { height: auto; overflow: visible; }
        }
        @media (max-width: 650px) {
            .main-wrapper { padding: 14px; }
        }
    </style>
    <link rel="stylesheet" href="theme.css">
    <script src="theme.js"></script>
</head>
<body>
<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="i-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></symbol><symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol><symbol id="i-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></symbol><symbol id="i-chat" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol><symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></symbol><symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol><symbol id="i-mic" viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></symbol><symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol><symbol id="i-check" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></symbol><symbol id="i-x" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></symbol><symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol><symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol><symbol id="i-id" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></symbol><symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></symbol><symbol id="i-close" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol><symbol id="i-bot" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></symbol><symbol id="i-mail" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></symbol><symbol id="i-package" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></symbol><symbol id="i-inbox" viewBox="0 0 24 24"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol><symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></symbol><symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol><symbol id="i-camera" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol><symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></symbol><symbol id="i-login" viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></symbol><symbol id="i-hash" viewBox="0 0 24 24"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></symbol><symbol id="i-archive" viewBox="0 0 24 24"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></symbol><symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></symbol></svg>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="logo.jpg" alt="LCUP Logo" onerror="this.style.display='none'">
            <h2>La Consolacion University Philippines</h2>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><svg class="icon"><use href="#i-dashboard"/></svg>Student Dashboard</a></li>
            <li class="active"><a href="admin.php"><svg class="icon"><use href="#i-shield"/></svg>Admin Control</a></li>
            <li>
                <button onclick="toggleStudentMessagesModal()">
                    <svg class="icon"><use href="#i-mail"/></svg>
                    <span>Messages from Students</span>
                    <?php if (intval($count_unread) > 0): ?>
                        <span class="unread-badge" id="unreadMessageBadge"><?php echo intval($count_unread); ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li><a href="logout.php"><svg class="icon"><use href="#i-logout"/></svg>Logout</a></li>
            <li><button onclick="toggleReportModal()"><svg class="icon"><use href="#i-file"/></svg>Generate Report (Excel)</button></li>
        </ul>
    </div>

    <!-- Main Outer Wrapper -->
    <div class="main-wrapper">

        <!-- Top Header Bar -->
        <div class="top-header-bar">
            <div class="top-header-title">
                <button type="button" class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-controls="sidebar" aria-expanded="true" aria-label="Hide navigation" title="Hide navigation"><span aria-hidden="true">☰</span></button>
                <div>
                    <h1>ADMIN CONTROL CENTER</h1>
                    <p>Findersync Lost and Found System - Management</p>
                </div>
            </div>
        </div>

        <?php echo $admin_msg; ?>

        <?php if (!empty($expiry_notifications)): ?>
            <div class="panel-card" style="border-left:4px solid #dc2626; padding:12px 16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                    <h3 class="panel-title" style="margin:0 0 8px; color:#991b1b;">
                        <svg class="icon"><use href="#i-bell"/></svg>
                        <?php echo count($expiry_notifications); ?> item<?php echo count($expiry_notifications) === 1 ? '' : 's'; ?> expired and archived
                    </h3>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="expiry_ack_csrf" value="<?php echo htmlspecialchars($_SESSION['expiry_ack_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" name="ack_expiry_notifications" value="1" style="background:#1b357a; color:#fff; border:0; border-radius:5px; padding:6px 10px; cursor:pointer; font-size:11px;">Mark reviewed</button>
                    </form>
                </div>
                <ul class="expiry-notification-list">
                    <?php foreach ($expiry_notifications as $notification): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($notification['item_title']); ?></strong>
                            <span class="serial-badge"><?php echo htmlspecialchars($notification['serial_no']); ?></span>
                            <span style="color:#64748b;">Expired <?php echo date('M d, Y', strtotime($notification['expired_at'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Quick Actions Row -->
        <div class="action-grid">
            <div class="action-card" onclick="toggleRetentionModal()">
                <div class="icon-box"><svg class="icon"><use href="#i-clock"/></svg></div>
                <span>Posting Duration</span>
            </div>
        </div>

        <!-- Top KPI Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><svg class="icon"><use href="#i-package"/></svg></div>
                <div class="stat-info">
                    <h3><?php echo $count_total_posted; ?></h3>
                    <p>Total Items Posted</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><svg class="icon"><use href="#i-file"/></svg></div>
                <div class="stat-info">
                    <h3><?php echo $count_unclaimed; ?></h3>
                    <p>Still Unclaimed</p>
                </div>
            </div>
            <div class="stat-card" onclick="toggleStudentMessagesModal()" style="cursor:pointer;">
                <div class="stat-icon"><svg class="icon"><use href="#i-mail"/></svg></div>
                <div class="stat-info">
                    <h3 id="unreadMessageStat"><?php echo intval($count_unread); ?></h3>
                    <p>Unread Messages</p>
                </div>
            </div>
        </div>

        <!-- Workspace Split Columns -->
        <div class="content-split">

            <!-- Left Column: Post New Item -->
            <div class="scrollable-column">
                <div class="panel-card">
                    <h3 class="panel-title"><svg class="icon"><use href="#i-plus"/></svg>Post New Item</h3>
                    <p style="font-size:11px; color:#64748b; margin-top:-6px; margin-bottom:12px;"><svg class="icon"><use href="#i-info"/></svg>Every post gets its own serial number automatically.</p>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="post_item" value="1">

                        <div>
                            <label style="font-size:11px; font-weight:bold;">Where found / last seen *</label>
                            <select name="location" required style="width:100%; padding:6px; margin-bottom:8px; border-radius:4px; border:1px solid #ccc;">
                                <option value="">Select Location</option>
                                <option value="MR Building">MR Building</option>
                                <option value="SA Building">SA Building</option>
                                <option value="College of Medicine">College of Medicine</option>
                                <option value="Andrada Gym">Andrada Gym</option>
                                <option value="Cassiciacum">Cassiciacum</option>
                                <option value="Parking Lot">Parking Lot</option>
                                <option value="Carthage">Carthage</option>
                                <option value="Kalinangan">Kalinangan</option>
                                <option value="Sto. Nino Building">Sto. Nino Building</option>
                                <option value="Padre Pio Building">Padre Pio Building</option>
                                <option value="Chapel">Chapel</option>
                                <option value="Mini Forest Garden">Mini Forest Garden</option>
                                <option value="Agostino Building">Agostino Building</option>
                            </select>
                        </div>

                        <div id="surrenderFields" style="padding:8px; margin-bottom:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <div style="margin-bottom:8px;">
                                <label style="font-size:11px; font-weight:bold;">Surrendered by *</label>
                                <input type="text" name="surrendered_by" maxlength="255" required style="width:100%; padding:6px; border-radius:4px; border:1px solid #ccc;">
                                <small id="surrenderedByHint" style="font-size:9px; color:#64748b;">Enter the person who handed in this found item.</small>
                            </div>
                            <div id="surrenderDetailsFields" style="display:grid; grid-template-columns:1fr 1fr; gap:0 8px;">
                                <div>
                                    <label style="font-size:11px; font-weight:bold;">Surrendered to *</label>
                                    <input type="text" name="surrendered_to" maxlength="255" required style="width:100%; padding:6px; margin-bottom:8px; border-radius:4px; border:1px solid #ccc;">
                                </div>
                                <div>
                                    <label style="font-size:11px; font-weight:bold;">Time surrendered *</label>
                                    <input type="datetime-local" name="surrendered_at" value="<?php echo date('Y-m-d\TH:i'); ?>" required style="width:100%; padding:6px; margin-bottom:8px; border-radius:4px; border:1px solid #ccc;">
                                </div>
                                <div style="grid-column:1 / -1;">
                                    <label style="font-size:11px; font-weight:bold;">Surrenderer contact *</label>
                                    <input type="tel" name="surrenderer_contact" maxlength="50" required style="width:100%; padding:6px; margin-bottom:4px; border-radius:4px; border:1px solid #ccc;">
                                    <small style="font-size:9px; color:#64748b;">Visible to admins only; not shown on the public post.</small>
                                </div>
                            </div>
                        </div>

                        <label style="font-size:11px; font-weight:bold;">Item Title *</label>
                        <input type="text" name="title" required style="width:100%; padding:6px; margin-bottom:8px; border-radius:4px; border:1px solid #ccc;">

                        <label style="font-size:11px; font-weight:bold;">Photos (up to 3)</label>
                        <input type="file" name="images[]" accept="image/*" multiple onchange="previewImages(event)" style="margin-bottom:8px; display:block;">
                        <div id="imagePreviewContainer" class="preview-container"></div>

                        <label style="font-size:11px; font-weight:bold; margin-top:8px; display:block;">Description *</label>
                        <textarea name="description" rows="3" required style="width:100%; padding:6px; border-radius:4px; border:1px solid #ccc;"></textarea>

                        <button type="submit" style="background:var(--navy-dark); color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; margin-top:8px; font-weight:bold; font-size:12px;">Post Item</button>
                    </form>
                </div>
            </div>

            <!-- Right Column: Posted Items -->
            <div class="scrollable-column">
                <div class="panel-card">
                    <h3 class="panel-title"><svg class="icon"><use href="#i-package"/></svg>Posted Items (<?php echo $count_total_posted; ?>)</h3>

                    <?php if ($posted_items && $posted_items->num_rows > 0): ?>
                        <?php while ($it = $posted_items->fetch_assoc()):
                            $it_type = 'Missing';
                            $it_serial = !empty($it['serial_no']) ? $it['serial_no'] : 'ITEM-' . str_pad($it['id'], 4, '0', STR_PAD_LEFT);
                            $it_claimed = ($it['status'] === 'Claimed');
                        ?>
                            <div class="request-item-card" style="cursor:default;">
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <?php if (!empty($it['image'])): ?>
                                        <img src="<?php echo htmlspecialchars(item_storage_url($it['image']), ENT_QUOTES, 'UTF-8'); ?>" style="height:56px; width:56px; object-fit:cover; border-radius:6px; border:1px solid #ccc; flex-shrink:0;">
                                    <?php endif; ?>
                                    <div style="flex-grow:1; min-width:0;">
                                        <div>
                                            <span class="serial-badge"><?php echo htmlspecialchars($it_serial); ?></span>
                                            <span class="type-badge <?php echo strtolower($it_type); ?>"><?php echo strtoupper($it_type); ?></span>
                                            <?php if ($it_claimed): ?><span class="type-badge" style="background:#dcfce7; color:#166534;">CLAIMED</span><?php endif; ?>
                                        </div>
                                        <strong style="font-size:13px; color:var(--navy-dark);"><?php echo htmlspecialchars($it['title']); ?></strong>
                                        <div style="font-size:10px; color:#64748b; margin-top:3px;"><svg class="icon"><use href="#i-pin"/></svg><?php echo htmlspecialchars($it['location']); ?> &nbsp;|&nbsp; <?php echo date('M d, Y', strtotime($it['created_at'])); ?></div>
                                        <?php if (!empty($it['surrendered_by'])): ?>
                                            <div style="font-size:10px; color:#475569; margin-top:4px;">
                                                Surrendered by: <?php echo htmlspecialchars($it['surrendered_by']); ?>
                                                | Received by: <?php echo htmlspecialchars($it['surrendered_to'] ?? ''); ?><br>
                                                Time: <?php echo !empty($it['surrendered_at']) ? date('M d, Y h:i A', strtotime($it['surrendered_at'])) : 'Not recorded'; ?> |
                                                Contact (admin-only): <?php echo htmlspecialchars($it['surrenderer_contact'] ?? ''); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($it_claimed): ?>
                                            <div style="font-size:11px; color:#166534; margin-top:6px;">
                                                <?php if (!empty($it['recorded_claimant_name'])): ?>
                                                    Claimed by: <?php echo htmlspecialchars($it['recorded_claimant_name'], ENT_QUOTES, 'UTF-8'); ?><br>
                                                    Year/Program: <?php echo htmlspecialchars($it['recorded_claimant_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                    | Contact: <?php echo htmlspecialchars(($it['recorded_claimant_contact'] ?? '') !== '' ? $it['recorded_claimant_contact'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?><br>
                                                    Date claimed: <?php echo !empty($it['recorded_claimed_at']) ? date('M d, Y h:i A', strtotime($it['recorded_claimed_at'])) : 'Not recorded'; ?>
                                                <?php else: ?>
                                                    Claim details not recorded (claimed before this update).
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <form method="POST" style="display:flex; gap:8px; margin-top:8px;">
                                    <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
                                    <?php if (!$it_claimed): ?>
                                        <button type="button" onclick="openClaimModal(<?php echo intval($it['id']); ?>, this)" data-item-title="<?php echo htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8'); ?>" style="background:#16a34a; color:#fff; border:none; padding:6px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;"><svg class="icon"><use href="#i-check"/></svg>Mark as Claimed</button>
                                    <?php endif; ?>
                                    <button type="submit" name="archive_item" value="1" onclick="return confirm('Archive this item? It will no longer appear on the home screen.');" style="background:#64748b; color:#fff; border:none; padding:6px 10px; border-radius:4px; font-size:11px; font-weight:bold; cursor:pointer;"><svg class="icon"><use href="#i-archive"/></svg>Archive</button>
                                </form>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="font-size:12px; color:#6b7280; margin:0;">No items posted yet. Use the form to post the first one.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>

    <!-- POP-UP MODAL: CONFIRM ITEM CLAIM -->
    <div class="modal-overlay" id="claimModal" onclick="if (event.target === this) closeClaimModal()">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="claimModalTitle">
            <div class="modal-header">
                <span id="claimModalTitle">Claim Item Confirmation</span>
                <button type="button" class="close-btn" onclick="closeClaimModal()" aria-label="Close claim form" style="background:none; border:0; color:inherit;"><svg class="icon"><use href="#i-close"/></svg></button>
            </div>
            <form method="POST" class="claim-form">
                <p>Enter the verified owner's information for <strong id="claimItemTitle"></strong>. The claim date will be recorded automatically.</p>
                <input type="hidden" name="claim_item" value="1">
                <input type="hidden" name="claim_csrf" value="<?php echo htmlspecialchars($_SESSION['claim_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="item_id" id="claimItemId" value="">

                <label for="claimantName">Full Name of Claimant *</label>
                <input type="text" name="claimant_name" id="claimantName" maxlength="255" placeholder="e.g. Juan Dela Cruz" autocomplete="name" required>

                <label for="claimantYear">Year / Program *</label>
                <input type="text" name="claimant_year" id="claimantYear" maxlength="100" placeholder="e.g. 2nd Year, BSIT" required>

                <label for="claimantContact">Contact Information (optional)</label>
                <input type="text" name="claimant_contact" id="claimantContact" maxlength="255" placeholder="Contact number or email" autocomplete="tel">

                <div class="claim-form-actions">
                    <button type="button" class="claim-cancel" onclick="closeClaimModal()">Cancel</button>
                    <button type="submit" class="claim-confirm">Confirm and Record Claim</button>
                </div>
            </form>
        </div>
    </div>

    <!-- POP-UP MODAL: ITEM POSTING DURATION -->
    <div class="modal-overlay" id="retentionModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><svg class="icon"><use href="#i-clock"/></svg>Item Posting Duration</span>
                <span class="close-btn" onclick="toggleRetentionModal()"><svg class="icon"><use href="#i-close"/></svg></span>
            </div>
            <div class="retention-body">
                <p>Active items are automatically archived after the posting duration. A pop-up warns visitors when an item is within the warning window.</p>
                <form method="POST">
                    <input type="hidden" name="save_retention" value="1">
                    <label for="item_days">Posting duration (days)</label>
                    <input type="number" id="item_days" name="item_days" min="1" max="3650" required value="<?php echo intval($retention['item_days']); ?>">
                    <label for="warn_days">Show warning this many days before removal (0 disables warnings)</label>
                    <input type="number" id="warn_days" name="warn_days" min="0" max="3650" required value="<?php echo intval($retention['warn_days']); ?>">
                    <button type="submit" class="report-submit">Save Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- POP-UP MODAL: GENERATE REPORT (EXCEL) -->
    <div class="modal-overlay" id="reportModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><svg class="icon"><use href="#i-file"/></svg>Generate Report (Excel)</span>
                <span class="close-btn" onclick="toggleReportModal()"><svg class="icon"><use href="#i-close"/></svg></span>
            </div>
            <div class="report-body">
                <p>Preview the report, including monthly trends and year/department rankings, before downloading it. Leave the dates empty to include every date.</p>
                <form method="GET" action="export_report.php" target="_blank" onsubmit="setTimeout(toggleReportModal, 600);">
                    <input type="hidden" name="preview" value="1">
                    <label for="rep_status">Status</label>
                    <select name="status" id="rep_status">
                        <option value="all">All items</option>
                        <option value="unclaimed">Unclaimed</option>
                        <option value="claimed">Claimed</option>
                        <option value="archived">Archived</option>
                    </select>
                    <div class="report-row">
                        <div>
                            <label for="rep_from">From</label>
                            <input type="date" name="from" id="rep_from">
                        </div>
                        <div>
                            <label for="rep_to">To</label>
                            <input type="date" name="to" id="rep_to">
                        </div>
                    </div>
                    <button type="submit" class="report-submit">Preview Report</button>
                </form>
            </div>
        </div>
    </div>

    <!-- POP-UP MODAL FOR MESSAGES FROM STUDENTS -->
    <div class="modal-overlay" id="studentMessagesModal">
        <div class="modal-content">
            <div class="modal-header">
                <span><svg class="icon"><use href="#i-mail"/></svg>Messages from Students</span>
                <span class="close-btn" onclick="toggleStudentMessagesModal()"><svg class="icon"><use href="#i-close"/></svg></span>
            </div>
            <div class="modal-body">
                <div class="student-list">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                        <span style="font-size:11px; font-weight:bold; color:#64748b;">CONVERSATIONS</span>
                        <button type="button" id="siSearchBtn" title="Search names" aria-label="Search names" style="background:none; border:none; cursor:pointer; padding:2px 4px; display:inline-flex; align-items:center; margin-left:auto;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1b357a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </button>
                    </div>
                    <div id="siSearchWrap" style="display:none; margin:0 0 8px;">
                        <input type="search" id="siSearchInput" placeholder="Search name..." style="width:100%; padding:7px 9px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
                    </div>
                    <p id="siNoMatch" style="display:none; font-size:11px; color:#94a3b8; text-align:center; margin:10px 0;">No matching names.</p>
                    <?php if ($student_threads && $student_threads->num_rows > 0): ?>
                        <?php while ($s = $student_threads->fetch_assoc()): 
                            $is_unread = ($s['unread_count'] > 0);
                        ?>
                            <div class="student-item <?php echo $is_unread ? 'unread' : ''; ?>" id="student_item_<?php echo $s['student_id']; ?>" onclick="loadConversation(<?php echo $s['student_id']; ?>, '<?php echo htmlspecialchars($s['full_name']); ?>')">
                                <span><svg class="icon"><use href="#i-user"/></svg><?php echo htmlspecialchars($s['full_name']); ?></span>
                                <?php if ($is_unread): ?>
                                    <span class="unread-badge"><?php echo $s['unread_count']; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p style="font-size:11px; color:#94a3b8;">No messages yet.</p>
                    <?php endif; ?>
                </div>

                <div class="chat-window">
                    <div style="font-size:12px; font-weight:bold; margin-bottom:6px;" id="activeStudentTitle">Select a student to view messages</div>
                    <div class="chat-messages" id="adminChatBox">
                        <p style="font-size:11px; color:#94a3b8; text-align:center; margin-top:20px;">Click on any student on the left column to view the chat history.</p>
                    </div>

                    <form method="POST" action="send_message.php" id="adminReplyForm" style="display:none; gap:6px;">
                        <input type="hidden" name="receiver_id" id="activeStudentId">
                        <input type="text" name="message" placeholder="Type reply..." required style="flex-grow:1; padding:8px; border-radius:6px; border:1px solid #ccc; font-size:12px;">
                        <button type="submit" name="send_msg" style="background:var(--navy-dark); color:#fff; border:none; padding:8px 14px; border-radius:6px; font-size:12px; font-weight:bold; cursor:pointer;">Reply</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php render_expiry_popup($expiring_items, 'admin', true); ?>

    <script>
    function toggleSidebar() {
        var collapsed = document.body.classList.toggle("sidebar-collapsed");
        var button = document.getElementById("sidebarToggle");
        var label = collapsed ? "Show navigation" : "Hide navigation";
        button.setAttribute("aria-expanded", collapsed ? "false" : "true");
        button.setAttribute("aria-label", label);
        button.title = label;
    }

    var unreadMessageCount = <?php echo intval($count_unread); ?>;

    function updateUnreadMessageBadge() {
        var badge = document.getElementById("unreadMessageBadge");
        if (badge) {
            badge.textContent = unreadMessageCount;
            badge.style.display = unreadMessageCount > 0 ? "inline-block" : "none";
        }
        var stat = document.getElementById("unreadMessageStat");
        if (stat) stat.textContent = unreadMessageCount;
    }

    function toggleStudentMessagesModal() {
        var modal = document.getElementById("studentMessagesModal");
        if (modal) {
            modal.style.display = (modal.style.display === "none" || modal.style.display === "") ? "flex" : "none";
        }
    }

    if (new URLSearchParams(window.location.search).get("open") === "messages") {
        toggleStudentMessagesModal();
        if (window.history && history.replaceState) history.replaceState(null, "", "admin.php");
    }

    function toggleReportModal() {
        var modal = document.getElementById("reportModal");
        if (modal) {
            modal.style.display = (modal.style.display === "none" || modal.style.display === "") ? "flex" : "none";
        }
    }

    function toggleRetentionModal() {
        var modal = document.getElementById("retentionModal");
        if (modal) {
            modal.style.display = (modal.style.display === "none" || modal.style.display === "") ? "flex" : "none";
        }
    }

    function openClaimModal(itemId, button) {
        var modal = document.getElementById("claimModal");
        document.getElementById("claimItemId").value = itemId;
        document.getElementById("claimItemTitle").textContent = button.getAttribute("data-item-title") || "this item";
        modal.style.display = "flex";
        document.getElementById("claimantName").focus();
    }

    function closeClaimModal() {
        document.getElementById("claimModal").style.display = "none";
    }

    function previewImages(event) {
        var container = document.getElementById("imagePreviewContainer");
        if (!container) return;
        container.innerHTML = "";
        var files = event.target.files;

        if (files.length > 3) {
            alert("Maximum limit reached! You can only attach up to 3 photos.");
            event.target.value = "";
            return;
        }

        Array.from(files).forEach(function(file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var img = document.createElement("img");
                img.src = e.target.result;
                container.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    }

    function loadConversation(studentId, studentName) {
        var chatTitle = document.getElementById("activeStudentTitle");
        chatTitle.innerHTML = '<svg class="icon"><use href="#i-chat"/></svg>';
        chatTitle.appendChild(document.createTextNode("Chat with " + studentName));
        document.getElementById("activeStudentId").value = studentId;
        document.getElementById("adminReplyForm").style.display = "flex";

        var el = document.getElementById("student_item_" + studentId);
        if (el) {
            el.classList.remove("unread");
            var badge = el.querySelector(".unread-badge");
            if (badge) {
                unreadMessageCount = Math.max(0, unreadMessageCount - (parseInt(badge.textContent, 10) || 0));
                badge.remove();
                updateUnreadMessageBadge();
            }
        }

        // Save "read" in the database so it stays read after logout
        var fd = new FormData();
        fd.append('student_id', studentId);
        fetch('mark_read.php', { method: 'POST', body: fd })
            .catch(err => console.error("Error marking as read:", err));

        fetch('fetch_messages.php?student_id=' + studentId)
            .then(res => res.text())
            .then(html => {
                var box = document.getElementById("adminChatBox");
                box.innerHTML = html;
                box.scrollTop = box.scrollHeight;
            })
            .catch(err => console.error("Error loading chat:", err));
    }
    </script>
    <script>
document.getElementById('adminReplyForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = this;
    var input = form.querySelector('input[name="message"]');
    var studentId = document.getElementById('activeStudentId').value;
    if (!input.value.trim() || !studentId) return;


    var fd = new FormData(form);
    fd.append('send_msg', '1');


    fetch('send_message.php', { method: 'POST', body: fd, redirect: 'manual' })
        .then(function () {
            input.value = '';
            return fetch('fetch_messages.php?student_id=' + studentId);
        })
        .then(function (res) { return res.text(); })
        .then(function (html) {
            var box = document.getElementById('adminChatBox');
            box.innerHTML = html;
            box.scrollTop = box.scrollHeight;
        })
        .catch(function (err) { console.error('Error sending reply:', err); });
});
</script>
    <script>
(function () {
    var items = document.querySelectorAll('.student-item');
    if (!items.length) return;
    var list = items[0].parentNode;

    var css = document.createElement('style');
    css.textContent =
      '.student-item{position:relative;touch-action:pan-y;user-select:none;}' +
      '.si-check{display:none;margin-right:8px;width:16px;height:16px;flex-shrink:0;}' +
      '.select-mode .si-check{display:inline-block;}' +
      '.select-mode .student-item > span:first-of-type{flex:1;}' +
      '.si-archive{display:none;position:absolute;right:6px;top:50%;transform:translateY(-50%);background:#0b2a6f;color:#fff;border:none;border-radius:6px;padding:6px 10px;font-size:11px;font-weight:bold;cursor:pointer;}' +
      '.student-item.show-actions .si-archive{display:block;}' +
      '.select-mode .si-archive{display:none !important;}' +
      '.si-toolbar{display:none;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;font-size:11px;}' +
      '.select-mode .si-toolbar{display:flex;}' +
      '.si-toolbar button{border:1px solid #cbd5e1;background:#fff;border-radius:6px;padding:5px 9px;font-size:11px;cursor:pointer;}' +
      '.si-select{display:inline-flex;align-items:center;justify-content:center;background:none;border:none;cursor:pointer;padding:2px 4px;}' +
      '.si-toolbar label{display:none;align-items:center;gap:4px;}' +
      '.select-mode .si-toolbar label{display:flex;}' +
      '.si-toolbar .si-go,.si-toolbar .si-done{display:none;}' +
      '.select-mode .si-toolbar .si-go,.select-mode .si-toolbar .si-done{display:inline-block;}' +
      '.si-toolbar .si-go{background:#0b2a6f;color:#fff;border-color:#0b2a6f;}' +
      '.select-mode .si-select{display:none;}';
    document.head.appendChild(css);

    var bar = document.createElement('div');
    bar.className = 'si-toolbar';
    bar.innerHTML =
      '<button type="button" class="si-select" title="Select conversations to archive" aria-label="Select conversations to archive"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1b357a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></button>' +
      '<label><input type="checkbox" class="si-all"> Select all</label>' +
      '<button type="button" class="si-go">Archive selected (0)</button>' +
      '<button type="button" class="si-done">Done</button>';
    list.insertBefore(bar, items[0]);

    var allBox = bar.querySelector('.si-all');
    var goBtn = bar.querySelector('.si-go');
    var selBtn = bar.querySelector('.si-select');
    var searchBtn = document.getElementById('siSearchBtn');
    if (searchBtn) searchBtn.insertAdjacentElement('afterend', selBtn);

    function sidOf(item) { return item.id.replace('student_item_', ''); }
    function boxes() {
        return Array.prototype.filter.call(list.querySelectorAll('.si-check'), function (checkbox) {
            return checkbox.closest('.student-item').style.display !== 'none';
        });
    }
    function checkedBoxes() {
        return Array.prototype.filter.call(boxes(), function (checkbox) { return checkbox.checked; });
    }
    function syncCount() {
        var selected = checkedBoxes().length;
        var visible = boxes().length;
        goBtn.textContent = 'Archive selected (' + selected + ')';
        allBox.checked = visible > 0 && selected === visible;
    }
    function closeActions() {
        document.querySelectorAll('.student-item.show-actions').forEach(function (item) {
            item.classList.remove('show-actions');
        });
    }
    function archive(ids) {
        if (!confirm('Archive ' + ids.length + ' conversation(s)?\nThey are hidden from the inbox but kept in the database. A new message from the student brings them back.')) return;
        var formData = new FormData();
        formData.append('action', 'archive');
        formData.append('csrf_token', <?php echo json_encode($_SESSION['thread_archive_csrf']); ?>);
        ids.forEach(function (id) { formData.append('student_ids[]', id); });
        fetch('manage_threads.php', { method: 'POST', body: formData })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data.ok) throw new Error(data.error || 'Could not archive conversations.');
                    return data;
                });
            })
            .then(function () {
                var active = document.getElementById('activeStudentId');
                ids.forEach(function (id) {
                    var item = document.getElementById('student_item_' + id);
                    if (item) item.remove();
                    if (active && active.value === String(id)) {
                        document.getElementById('adminChatBox').innerHTML =
                            '<p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:20px;">Conversation archived.</p>';
                        document.getElementById('activeStudentTitle').textContent = 'Select a student to view messages';
                        document.getElementById('adminReplyForm').style.display = 'none';
                        active.value = '';
                    }
                });
                syncCount();
            })
            .catch(function (error) { alert(error.message || 'Could not reach the server. Please try again.'); });
    }

    items.forEach(function (item) {
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'si-check';
        checkbox.addEventListener('click', function (event) { event.stopPropagation(); syncCount(); });
        item.insertBefore(checkbox, item.firstChild);

        var archiveButton = document.createElement('button');
        archiveButton.type = 'button';
        archiveButton.className = 'si-archive';
        archiveButton.textContent = 'Archive';
        archiveButton.addEventListener('click', function (event) {
            event.stopPropagation();
            archive([sidOf(item)]);
        });
        item.appendChild(archiveButton);
    });

    selBtn.onclick = function () {
        closeActions();
        list.classList.add('select-mode');
        syncCount();
    };
    bar.querySelector('.si-done').onclick = function () {
        list.classList.remove('select-mode');
        boxes().forEach(function (checkbox) { checkbox.checked = false; });
        syncCount();
    };
    allBox.onchange = function () {
        boxes().forEach(function (checkbox) { checkbox.checked = allBox.checked; });
        syncCount();
    };
    goBtn.onclick = function () {
        var ids = checkedBoxes().map(function (checkbox) { return sidOf(checkbox.closest('.student-item')); });
        if (ids.length) archive(ids);
    };

    var swiped = false, startX = null, startItem = null;
    list.addEventListener('pointerdown', function (event) {
        var item = event.target.closest('.student-item');
        if (!item || list.classList.contains('select-mode')) return;
        startX = event.clientX;
        startItem = item;
    });
    document.addEventListener('pointerup', function (event) {
        if (startX === null) return;
        var deltaX = event.clientX - startX;
        if (deltaX < -40) { closeActions(); startItem.classList.add('show-actions'); swiped = true; }
        else if (deltaX > 40) { startItem.classList.remove('show-actions'); swiped = true; }
        startX = null;
        startItem = null;
        setTimeout(function () { swiped = false; }, 50);
    });
    document.addEventListener('pointercancel', function () { startX = null; startItem = null; });
    list.addEventListener('click', function (event) {
        var item = event.target.closest('.student-item');
        if (!item) return;
        if (event.target.classList.contains('si-check') || event.target.classList.contains('si-archive')) return;
        if (swiped) { event.stopPropagation(); event.preventDefault(); return; }
        if (list.classList.contains('select-mode')) {
            event.stopPropagation();
            event.preventDefault();
            var checkbox = item.querySelector('.si-check');
            checkbox.checked = !checkbox.checked;
            syncCount();
        }
    }, true);
})();
</script>
    <script>
(function () {
    var button = document.getElementById('siSearchBtn');
    var wrap = document.getElementById('siSearchWrap');
    var input = document.getElementById('siSearchInput');
    var noMatch = document.getElementById('siNoMatch');
    if (!button || !wrap || !input || !noMatch) return;

    function applyFilter() {
        var term = input.value.trim().toLowerCase();
        var shown = 0;
        document.querySelectorAll('.student-item').forEach(function (item) {
            var name = item.querySelector('span');
            var matches = !term || (name || item).textContent.toLowerCase().indexOf(term) !== -1;
            item.style.display = matches ? '' : 'none';
            item.classList.remove('show-actions');
            if (matches) shown++;
        });
        noMatch.style.display = term && !shown ? 'block' : 'none';
        document.querySelectorAll('.si-check').forEach(function (checkbox) { checkbox.checked = false; });
        var archiveButton = document.querySelector('.si-go');
        if (archiveButton) archiveButton.textContent = 'Archive selected (0)';
        var selectAll = document.querySelector('.si-all');
        if (selectAll) selectAll.checked = false;
    }

    button.addEventListener('click', function () {
        var isOpen = wrap.style.display === 'block';
        wrap.style.display = isOpen ? 'none' : 'block';
        if (isOpen) { input.value = ''; applyFilter(); }
        else { input.focus(); }
    });
    input.addEventListener('input', applyFilter);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            input.value = '';
            wrap.style.display = 'none';
            applyFilter();
        }
    });
})();
</script>
</body>
</html>