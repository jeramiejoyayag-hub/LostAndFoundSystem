<?php
include 'db.php';

// Generate a fresh hash for 'admin123' directly on your PHP environment
$newPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);
$adminEmail = 'admin@lcup.edu.ph';

// Update or Insert the Admin user
$stmt = $conn->prepare("INSERT INTO users (full_name, email, student_id, password, role) 
    VALUES ('System Admin', ?, 'ADMIN-01', ?, 'admin') 
    ON DUPLICATE KEY UPDATE password = ?");

$stmt->bind_param("sss", $adminEmail, $newPasswordHash, $newPasswordHash);

if ($stmt->execute()) {
    echo "<h2 style='color: green; font-family: sans-serif;'>✅ Admin password successfully reset!</h2>";
    echo "<p style='font-family: sans-serif;'>You can now log in with:</p>";
    echo "<ul style='font-family: sans-serif;'><li><b>Email:</b> admin@lcup.edu.ph</li><li><b>Password:</b> admin123</li></ul>";
    echo "<a href='login.php?type=admin' style='font-family: sans-serif;'>Go to Admin Login</a>";
} else {
    echo "<h2 style='color: red;'>Error updating password: " . $conn->error . "</h2>";
}
?>