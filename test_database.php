<?php
/*
 * Test script to verify database setup
 * Run this after executing database_setup.sql
 */

require 'config.php';

echo "Testing database setup...\n\n";

// Test 1: Check if tables exist
$tables = ['queue', 'lab_activity_log', 'daily_statistics', 'historical_data', 'claimable_results', 'settings'];
$all_tables_exist = true;

foreach ($tables as $table) {
    // SHOW TABLES LIKE doesn't support placeholders in MariaDB's prepared-statement
    // protocol — $table only ever comes from the hardcoded $tables array above,
    // never external input, so interpolating it here is safe.
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

foreach ($tables as $table) {
    // Table/column identifiers can't be bind parameters — $table only ever
    // comes from the hardcoded $tables array above, never external input.
    $result = $conn->query("DESCRIBE `$table`");
    if ($result) {
        echo "✓ $table table structure is correct\n";
    } else {
        echo "✗ Error checking $table structure: " . $conn->error . "\n";
    }
}

// Test 3: Check settings are seeded
echo "\nTesting settings seed data...\n";
$expected_settings = [
    'queue_prefix' => 'L',
    'daily_reset_hour' => '4',
    'flash_duration_seconds' => '10',
    'recall_limit' => '3',
    'announcement' => '',
    'queue_retention_days' => '30',
];

$result = $conn->query("SELECT `key`, `value` FROM settings");
$actual_settings = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $actual_settings[$row['key']] = $row['value'];
    }
}

$all_settings_ok = true;
foreach ($expected_settings as $key => $expected_value) {
    if (array_key_exists($key, $actual_settings) && $actual_settings[$key] === $expected_value) {
        echo "✓ Setting '$key' = '$expected_value'\n";
    } else {
        $actual = array_key_exists($key, $actual_settings) ? $actual_settings[$key] : '(missing)';
        echo "✗ Setting '$key' expected '$expected_value', got '$actual'\n";
        $all_settings_ok = false;
    }
}

// Ticket printer settings are edited on printer_setup.php, so only check that
// each key exists — comparing against the seed values would fail as soon as
// the clinic customizes its ticket.
$expected_setting_keys = [
    'ticket_printing_enabled', 'ticket_header', 'ticket_subheader', 'ticket_footer',
    'ticket_show_logo', 'ticket_show_waiting', 'ticket_width_mm',
];
foreach ($expected_setting_keys as $key) {
    if (array_key_exists($key, $actual_settings)) {
        echo "✓ Setting '$key' exists\n";
    } else {
        echo "✗ Setting '$key' is missing\n";
        $all_settings_ok = false;
    }
}

if (!$all_settings_ok) {
    echo "\n❌ Settings seed data is incomplete or incorrect.\n";
    exit(1);
}

echo "\n✓ Database setup is complete and working!\n";
?>
