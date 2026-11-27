<?php
require_once __DIR__ . '/web_push.php';

function ensure_missing_item_reports_table($conn) {
    $reports_created = $conn->query("
        CREATE TABLE IF NOT EXISTS missing_item_reports (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            location VARCHAR(255) NULL,
            year_level VARCHAR(50) NULL,
            department VARCHAR(150) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_missing_report_status (status),
            INDEX idx_missing_report_student (student_id, status)
        )
    ");
    if (!$reports_created) return false;

    $report_columns = $conn->query("SHOW COLUMNS FROM missing_item_reports");
    if (!$report_columns) return false;
    $existing_columns = [];
    while ($column = $report_columns->fetch_assoc()) {
        $existing_columns[$column['Field']] = true;
    }

    $columns_to_add = [
        'year_level' => 'VARCHAR(50) NULL',
        'department' => 'VARCHAR(150) NULL',
    ];
    foreach ($columns_to_add as $name => $definition) {
        if (!isset($existing_columns[$name]) && !$conn->query("ALTER TABLE missing_item_reports ADD COLUMN $name $definition")) {
            return false;
        }
    }

    return (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS missing_item_alerts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            item_id INT NOT NULL,
            item_title VARCHAR(255) NOT NULL,
            serial_no VARCHAR(100) NOT NULL,
            location VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            UNIQUE KEY uq_missing_alert_student_item (student_id, item_id),
            INDEX idx_missing_alert_unread (student_id, read_at)
        )
    ");
}

function missing_item_terms($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) return [];
    $stop_words = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'found', 'i', 'in', 'is', 'it', 'my', 'of', 'on', 'or', 'the', 'to', 'was', 'were', 'with', 'lost', 'missing', 'item'];
    $terms = [];

    foreach ($words as $word) {
        if (mb_strlen($word, 'UTF-8') >= 2 && !in_array($word, $stop_words, true)) {
            $terms[] = $word;
        }
    }

    return array_values(array_unique($terms));
}

function missing_item_report_matches($report_title, $report_description, $report_location, $posted_title, $posted_description, $posted_location) {
    $report_title_terms = missing_item_terms($report_title);
    $posted_title_terms = missing_item_terms($posted_title);
    sort($report_title_terms);
    sort($posted_title_terms);

    if (!empty($report_title_terms) && $report_title_terms === $posted_title_terms) {
        return true;
    }

    $report_terms = array_unique(array_merge($report_title_terms, missing_item_terms($report_description)));
    $posted_terms = array_unique(array_merge($posted_title_terms, missing_item_terms($posted_description)));
    $shared_terms = array_intersect($report_terms, $posted_terms);
    if (count($shared_terms) >= 2) return true;

    return count($shared_terms) >= 1
        && trim($report_location) !== ''
        && mb_strtolower(trim($report_location), 'UTF-8') === mb_strtolower(trim($posted_location), 'UTF-8');
}

function notify_matching_missing_item_reports($conn, $admin_id, $item_id, $item_title, $item_description, $item_location, $serial_no) {
    $reports = $conn->query("SELECT student_id, title, description, location FROM missing_item_reports WHERE status = 'Active'");
    if (!$reports) {
        error_log("Could not load missing-item reports: " . $conn->error);
        return false;
    }

    $recipients = [];
    while ($report = $reports->fetch_assoc()) {
        if (missing_item_report_matches($report['title'], $report['description'], $report['location'] ?? '', $item_title, $item_description, $item_location)) {
            $recipients[intval($report['student_id'])] = true;
        }
    }
    $reports->free();

    if (empty($recipients)) return 0;

    $alert_stmt = $conn->prepare("INSERT IGNORE INTO missing_item_alerts (student_id, item_id, item_title, serial_no, location) VALUES (?, ?, ?, ?, ?)");
    if (!$alert_stmt) {
        error_log("Could not prepare persistent missing-item alerts: " . $conn->error);
        return false;
    }

    $message_stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, 0)");
    if (!$message_stmt) {
        error_log("Could not prepare missing-item match notifications: " . $conn->error);
        $alert_stmt->close();
        return false;
    }

    $message = "A newly posted found item may match your missing-item report.\n"
        . "Item: " . $item_title . "\n"
        . "Serial number: " . $serial_no . "\n"
        . "Found at: " . $item_location . "\n"
        . "Review the item on the dashboard and message the admin if it may be yours.";
    $sent = 0;

    foreach (array_keys($recipients) as $student_id) {
        $alert_stmt->bind_param("iisss", $student_id, $item_id, $item_title, $serial_no, $item_location);
        if (!$alert_stmt->execute()) {
            error_log("Could not save persistent missing-item alert: " . $alert_stmt->error);
            $alert_stmt->close();
            $message_stmt->close();
            return false;
        }
        if ($alert_stmt->affected_rows === 0) continue;

        $message_stmt->bind_param("iis", $admin_id, $student_id, $message);
        if (!$message_stmt->execute()) {
            error_log("Could not send missing-item match notification: " . $message_stmt->error);
            $alert_stmt->close();
            $message_stmt->close();
            return false;
        }
        web_push_notify_student(
            $conn,
            $student_id,
            "A found item may match your report",
            $item_title . " - Found at " . $item_location,
            "dashboard.php#item_" . $item_id,
            "missing-item-" . $item_id
        );
        $sent++;
    }

    $alert_stmt->close();
    $message_stmt->close();
    return $sent;
}
