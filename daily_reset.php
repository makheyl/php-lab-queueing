<?php
/*
 * Daily Reset Script for the Laboratory Queueing System
 *
 * Run this once a day, shortly after settings.daily_reset_hour, to:
 * 1. Archive the previous service day's daily_statistics into historical_data
 * 2. Export that month's historical_data to report_month_YYYY_MM.csv
 * 3. Clear daily_statistics so the new day starts from zero
 * 4. Close out any queue rows still stuck in a non-terminal status
 * 5. Optionally purge queue rows older than settings.queue_retention_days
 *
 * Numbering does NOT need resetting here — queue.queue_number is scoped
 * per service_date via the UNIQUE (service_date, queue_number) key, so a
 * fresh day starts back at whatever number is first added.
 *
 * To schedule this to run every day at 4:10 AM Philippine time (ten minutes
 * after the default daily_reset_hour, so any request mid-rollover has
 * settled first):
 *
 * Windows Task Scheduler:
 * 1. Open Task Scheduler
 * 2. Create Basic Task
 * 3. Name: "Lab Queueing Daily Reset"
 * 4. Trigger: Daily at 4:10:00 AM
 * 5. Action: Start a program
 *    - Program:   C:\xampp\php\php.exe
 *    - Arguments: C:\xampp\htdocs\PHPLabQueueing\daily_reset.php
 *    - Start in:  C:\xampp\htdocs\PHPLabQueueing
 *
 * Linux Cron (if ever deployed there):
 * 10 4 * * * /usr/bin/php /path/to/PHPLabQueueing/daily_reset.php
 */

require 'config.php';
date_default_timezone_set('Asia/Manila');
require 'queue_functions.php';

echo "=== Laboratory Queueing — Daily Reset ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n\n";

// service_date_now() is reset-hour-aware, so this is the service day that
// just closed, not necessarily calendar "yesterday".
$new_service_date = service_date_now($conn);
$previous_service_date = date('Y-m-d', strtotime($new_service_date . ' -1 day'));
echo "Closing out service_date: $previous_service_date\n\n";

// 1. Archive yesterday's statistics to historical_data
$stmt = $conn->prepare(
    "INSERT INTO historical_data
        (date, station, staff_name, patients_served, patients_pending, patients_cancelled,
         total_patients, no_charge_count, for_payment_count, no_show_count)
     SELECT date, station, staff_name, patients_served, patients_pending, patients_cancelled,
            total_patients, no_charge_count, for_payment_count, no_show_count
     FROM daily_statistics WHERE date = ?
     ON DUPLICATE KEY UPDATE
        patients_served = VALUES(patients_served),
        patients_pending = VALUES(patients_pending),
        patients_cancelled = VALUES(patients_cancelled),
        total_patients = VALUES(total_patients),
        no_charge_count = VALUES(no_charge_count),
        for_payment_count = VALUES(for_payment_count),
        no_show_count = VALUES(no_show_count)"
);
$stmt->bind_param('s', $previous_service_date);
if ($stmt->execute()) {
    echo "✓ Archived $previous_service_date daily_statistics into historical_data ({$stmt->affected_rows} row(s))\n";
} else {
    echo "✗ Failed to archive daily_statistics: " . $conn->error . "\n";
}
$stmt->close();

// 2. Export the whole month to CSV, regenerated fresh so re-running this
// script never produces duplicate rows in the file.
$month_start = date('Y-m-01', strtotime($previous_service_date));
$month_label = date('Y_m', strtotime($previous_service_date));
$stmt = $conn->prepare(
    "SELECT date, station, staff_name, patients_served, patients_pending, patients_cancelled,
            total_patients, no_charge_count, for_payment_count, no_show_count
     FROM historical_data WHERE date >= ? AND date < DATE_ADD(?, INTERVAL 1 MONTH)
     ORDER BY date ASC, station ASC, staff_name ASC"
);
$stmt->bind_param('ss', $month_start, $month_start);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$csv_filename = __DIR__ . "/report_month_{$month_label}.csv";
$csv_file = @fopen($csv_filename, 'w');
if ($csv_file) {
    fputcsv($csv_file, ['Date', 'Station', 'Staff Name', 'Patients Served', 'Patients Pending', 'Patients Cancelled', 'Total Patients', 'No Charge', 'For Payment', 'No Show']);
    foreach ($rows as $row) {
        fputcsv($csv_file, [
            $row['date'], $row['station'], $row['staff_name'], $row['patients_served'],
            $row['patients_pending'], $row['patients_cancelled'], $row['total_patients'],
            $row['no_charge_count'], $row['for_payment_count'], $row['no_show_count'],
        ]);
    }
    fclose($csv_file);
    echo '✓ Exported ' . count($rows) . ' row(s) to ' . basename($csv_filename) . "\n";

    $stmt = $conn->prepare("UPDATE historical_data SET exported_to_csv = 1, export_date = NOW() WHERE date = ?");
    $stmt->bind_param('s', $previous_service_date);
    $stmt->execute();
    $stmt->close();
} else {
    echo "✗ Could not open $csv_filename for writing\n";
}

// 3. Clear daily_statistics so the new day starts from zero
$stmt = $conn->prepare("DELETE FROM daily_statistics WHERE date < ?");
$stmt->bind_param('s', $new_service_date);
if ($stmt->execute()) {
    echo "✓ Cleared daily_statistics before $new_service_date ({$stmt->affected_rows} row(s))\n";
} else {
    echo "✗ Failed to clear daily_statistics: " . $conn->error . "\n";
}
$stmt->close();

// 4. Close out any stragglers left in a non-terminal status from the day
// that just closed, so they never show up in today's queues.
$non_terminal = ['interviewing', 'awaiting_payment', 'ready_for_extraction', 'extracting'];
$placeholders = implode(',', array_fill(0, count($non_terminal), '?'));
$types = 's' . str_repeat('s', count($non_terminal));
$stmt = $conn->prepare(
    "UPDATE queue SET status = 'cancelled',
        notes = TRIM(CONCAT(COALESCE(notes, ''), ' [auto-cancelled by daily_reset: incomplete at service_date rollover]'))
     WHERE service_date = ? AND status IN ($placeholders)"
);
$stmt->bind_param($types, $previous_service_date, ...$non_terminal);
if ($stmt->execute()) {
    echo "✓ Closed out {$stmt->affected_rows} straggler row(s) left in a non-terminal status\n";
} else {
    echo "✗ Failed to close out stragglers: " . $conn->error . "\n";
}
$stmt->close();

// 5. Optionally purge queue rows older than settings.queue_retention_days
$retention_days = (int) get_setting($conn, 'queue_retention_days', 30);
if ($retention_days > 0) {
    $stmt = $conn->prepare("DELETE FROM queue WHERE service_date < DATE_SUB(?, INTERVAL ? DAY)");
    $stmt->bind_param('si', $new_service_date, $retention_days);
    if ($stmt->execute()) {
        echo "✓ Purged {$stmt->affected_rows} queue row(s) older than $retention_days day(s)\n";
    } else {
        echo "✗ Failed to purge old queue rows: " . $conn->error . "\n";
    }
    $stmt->close();
} else {
    echo "- Skipping purge (queue_retention_days is 0)\n";
}

echo "\nDaily reset complete.\n";
?>
