<?php
/*
 * Daily Reset Script for OPD Queueing System
 * 
 * This script should be scheduled to run daily at midnight to:
 * 1. Archive current day's statistics to historical_data table
 * 2. Export historical data to CSV files
 * 3. Clear daily_statistics table for the new day
 * 4. Optionally clear old queue data
 * 
 * To schedule this script to run every day at midnight Philippine time (UTC+8):
 * 
 * Windows Task Scheduler:
 * 1. Open Task Scheduler
 * 2. Create Basic Task
 * 3. Name: "OPD Daily Reset"
 * 4. Trigger: Daily at 12:00:00 AM
 * 5. Action: Start a program
 *    - Program: C:\xampp\php\php.exe
 *    - Arguments: C:\xampp\htdocs\PHPOPDQueueing\daily_reset.php
 * 
 * Linux Cron:
 * 0 0 * * * /usr/bin/php /path/to/PHPOPDQueueing/daily_reset.php
 */

require 'config.php';

// Set timezone to Philippine time
date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

echo "Starting daily reset process for $yesterday...\n";

// 1. Archive yesterday's statistics to historical_data table
$archive_sql = "INSERT INTO historical_data (date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients)
                SELECT date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients
                FROM daily_statistics 
                WHERE date = '$yesterday'
                ON DUPLICATE KEY UPDATE 
                patients_served = VALUES(patients_served),
                patients_pending = VALUES(patients_pending),
                patients_cancelled = VALUES(patients_cancelled),
                total_patients = VALUES(total_patients)";

if ($conn->query($archive_sql)) {
    echo "✓ Archived yesterday's statistics to historical_data table\n";
} else {
    echo "✗ Error archiving statistics: " . $conn->error . "\n";
}

// 2. Export historical data to CSV
$export_sql = "SELECT date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients
               FROM historical_data 
               WHERE date = '$yesterday' AND exported_to_csv = 0
               ORDER BY table_number, doctor_name";

$result = $conn->query($export_sql);

if ($result && $result->num_rows > 0) {
    $csv_filename = "exports/daily_report_" . $yesterday . ".csv";
    
    // Create exports directory if it doesn't exist
    if (!is_dir('exports')) {
        mkdir('exports', 0755, true);
    }
    
    $csv_file = fopen($csv_filename, 'w');
    
    if ($csv_file) {
        // Write CSV header
        fputcsv($csv_file, ['Date', 'Table Number', 'Doctor Name', 'Patients Served', 'Patients Pending', 'Patients Cancelled', 'Total Patients']);
        
        // Write data rows
        while ($row = $result->fetch_assoc()) {
            fputcsv($csv_file, [
                $row['date'],
                $row['table_number'],
                $row['doctor_name'],
                $row['patients_served'],
                $row['patients_pending'],
                $row['patients_cancelled'],
                $row['total_patients']
            ]);
        }
        
        fclose($csv_file);
        
        // Mark as exported
        $update_sql = "UPDATE historical_data SET exported_to_csv = 1, export_date = NOW() WHERE date = '$yesterday'";
        $conn->query($update_sql);
        
        echo "✓ Exported historical data to $csv_filename\n";
    } else {
        echo "✗ Error creating CSV file: $csv_filename\n";
    }
} else {
    echo "✓ No new data to export for $yesterday\n";
}

// 3. Clear daily_statistics table for the new day (keep only today's data)
$clear_sql = "DELETE FROM daily_statistics WHERE date < '$today'";
if ($conn->query($clear_sql)) {
    echo "✓ Cleared old daily statistics\n";
} else {
    echo "✗ Error clearing daily statistics: " . $conn->error . "\n";
}

// 4. Optional: Clear old queue data (older than 7 days)
$clear_old_queue_sql = "DELETE FROM queue WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
if ($conn->query($clear_old_queue_sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✓ Cleared $affected_rows old queue records (older than 7 days)\n";
} else {
    echo "✗ Error clearing old queue data: " . $conn->error . "\n";
}

// 5. Optional: Clear old log data (older than 30 days)
$clear_old_logs_sql = "DELETE FROM doctor_appointments_log WHERE log_time < DATE_SUB(NOW(), INTERVAL 30 DAY)";
if ($conn->query($clear_old_logs_sql)) {
    $affected_rows = $conn->affected_rows;
    echo "✓ Cleared $affected_rows old log records (older than 30 days)\n";
} else {
    echo "✗ Error clearing old log data: " . $conn->error . "\n";
}

echo "Daily reset process completed successfully!\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
?> 