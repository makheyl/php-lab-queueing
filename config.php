<?php
// config.php
$host = 'localhost';
$user = 'root'; // change as needed
$pass = '';
$db = 'opdqueue'; // change as needed

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

// Ensure schema supports fair ordering by confirmed arrival time
// Add confirmed_at column to queue table if missing
$checkConfirmedAt = $conn->query("SHOW COLUMNS FROM `queue` LIKE 'confirmed_at'");
if ($checkConfirmedAt && $checkConfirmedAt->num_rows === 0) {
    $conn->query("ALTER TABLE `queue` ADD COLUMN `confirmed_at` DATETIME NULL DEFAULT NULL AFTER `served_at`");
}
if ($checkConfirmedAt instanceof mysqli_result) { $checkConfirmedAt->free(); }
?> 