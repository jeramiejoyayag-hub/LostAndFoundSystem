<?php
include 'db.php';

$email = 'admin@lcup.edu.ph';
$password = 'admin123';
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// Clear old entries
$conn->query("DELETE FROM users WHERE email = '$email'");

// Direct SQL insert matching your schema
$sql = "INSERT INTO users (full_name, email, password, usertype, role) 
        VALUES ('System Admin', '$email', '$hashed_password', 'Admin', 'admin')";

if ($conn->query($sql) === TRUE) {
    echo "<h2 style='color: green; font-family: sans-serif;'>✅ Admin Account Restored!</h2>";
    echo "<p style='font-family: sans-serif;'>You can now log in with:</p>";
    echo "<ul style='font-family: sans-serif;'><li><b>Email:</b> admin@lcup.edu.ph</li><li><b>Password:</b> admin123</li></ul>";
    echo "<a href='login.php?type=admin' style='font-family: sans-serif;'>Go to Admin Login Page</a>";
} else {
    echo "<h2 style='color: red;'>Error: " . $conn->error . "</h2>";
}
?>