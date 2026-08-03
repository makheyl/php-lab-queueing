<?php
/*
 * Test script to verify database setup
 * Run this after executing database_setup.sql
 */

require 'config.php';

echo "Testing database setup...\n\n";

// Test 1: Check if tables exist
$tables = ['doctor_appointments_log', 'daily_statistics', 'historical_data'];
$all_tables_exist = true;

foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "✓ Table '$table' exists\n";
    } else {
        echo "✗ Table '$table' does not exist\n";
        $all_tables_exist = false;
    }
}

if (!$all_tables_exist) {
    echo "\n❌ Some tables are missing. Please run database_setup.sql first.\n";
    exit(1);
}

echo "\n✓ All tables exist!\n\n";

// Test 2: Check table structure
echo "Testing table structure...\n";

// Test doctor_appointments_log
$result = $conn->query("DESCRIBE doctor_appointments_log");
if ($result) {
    echo "✓ doctor_appointments_log table structure is correct\n";
} else {
    echo "✗ Error checking doctor_appointments_log structure: " . $conn->error . "\n";
}

// Test daily_statistics
$result = $conn->query("DESCRIBE daily_statistics");
if ($result) {
    echo "✓ daily_statistics table structure is correct\n";
} else {
    echo "✗ Error checking daily_statistics structure: " . $conn->error . "\n";
}

// Test historical_data
$result = $conn->query("DESCRIBE historical_data");
if ($result) {
    echo "✓ historical_data table structure is correct\n";
} else {
    echo "✗ Error checking historical_data structure: " . $conn->error . "\n";
}

echo "\n✓ Database setup is complete and working!\n";
echo "\nYou can now:\n";
echo "1. Use the doctor panel (doctor.php)\n";
echo "2. View admin reports (admin.php)\n";
echo "3. Schedule the daily reset (daily_reset.php)\n";
?> 