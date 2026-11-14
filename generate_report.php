<?php
session_start();
include 'db.php';

// Ensure user is logged in and holds Admin privileges
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit("Unauthorized access.");
}

// System Logging: Record report generation activity
$admin_id = $_SESSION['user_id'];
$log_stmt = $conn->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, 'Exported System PDF Report', 'Generated PDF report for lost and found items')");
if ($log_stmt) {
    $log_stmt->bind_param("i", $admin_id);
    $log_stmt->execute();
    $log_stmt->close();
}

// Fetch Department Statistics (Most common item per department)
$dept_summary_query = "
    SELECT 
        course, 
        item_title, 
        COUNT(*) AS total_count 
    FROM item_requests 
    WHERE course IS NOT NULL AND course != ''
    GROUP BY course, item_title 
    ORDER BY course ASC, total_count DESC
";
$dept_result = $conn->query($dept_summary_query);

$department_stats = [];
if ($dept_result && $dept_result->num_rows > 0) {
    $processed_departments = [];
    while ($row = $dept_result->fetch_assoc()) {
        $dept = $row['course'];
        if (!in_array($dept, $processed_departments)) {
            $department_stats[] = [
                'department' => $dept,
                'item_title' => $row['item_title'],
                'count'      => $row['total_count']
            ];
            $processed_departments[] = $dept;
        }
    }
}

// Fetch Detailed Feed Records
$items_query = "SELECT id, title, location, description, status, image, created_at FROM items ORDER BY created_at DESC";
$items_result = $conn->query($items_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LCUP Lost and Found System Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 20px;
            background: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #0095f6;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            color: #1a1a1a;
        }
        .header h2 {
            margin: 5px 0;
            font-size: 16px;
            color: #0095f6;
        }
        .header p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }
        .section-title {
            font-size: 15px;
            font-weight: bold;
            margin-top: 25px;
            margin-bottom: 10px;
            color: #1a1a1a;
            border-left: 4px solid #0095f6;
            padding-left: 8px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #e1e8ed;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background-color: #0095f6;
            color: #ffffff;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9fbfd;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                margin: 0;
            }
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="background-color: #2e7d32; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer;">
            🖨️ Save as PDF / Print
        </button>
        <button onclick="handleClose()" style="background-color: #555; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-left: 8px;">
            Close
        </button>
    </div>

    <div class="header">
        <h1>La Consolacion University Philippines</h1>
        <h2>Lost & Found System - Analytical Summary Report</h2>
        <p>Generated Date: <?php echo date('F j, Y, g:i a'); ?></p>
    </div>

    <!-- Section 1: Department Statistics -->
    <div class="section-title">📊 Department Analytics: Most Common Reported Items</div>
    <table>
        <thead>
            <tr>
                <th>Department / Course</th>
                <th>Most Common Reported Item</th>
                <th>Total Reports</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($department_stats)): ?>
                <?php foreach ($department_stats as $stat): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($stat['department']); ?></strong></td>
                        <td><?php echo htmlspecialchars($stat['item_title']); ?></td>
                        <td><?php echo htmlspecialchars($stat['count']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="3" style="text-align: center; color: #777;">No student report data available.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Section 2: Detailed Feed Item Records -->
    <div class="section-title">📋 Detailed Feed Item Records</div>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Item Title</th>
                <th>Location</th>
                <th>Description</th>
                <th>Status</th>
                <th>Date Reported</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($items_result && $items_result->num_rows > 0): ?>
                <?php while ($item = $items_result->fetch_assoc()): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($item['id']); ?></td>
                        <td><strong><?php echo htmlspecialchars($item['title']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['location']); ?></td>
                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                        <td><strong><?php echo htmlspecialchars($item['status']); ?></strong></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($item['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: #777;">No active items found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <script>
        function handleClose() {
            window.close();
            // Fallback in case window was not opened via script
            setTimeout(function() {
                window.location.href = 'admin.php';
            }, 100);
        }

        // Automatically trigger print popup when page loads
        window.onload = function() {
            window.print();
        };
    </script>

</body>
</html>