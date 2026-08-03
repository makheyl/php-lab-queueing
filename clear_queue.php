<?php
/*
 * This script deletes all rows from the 'queue' table.
 *
 * To schedule this script to run every day at midnight Philippine time (UTC+8):
 *
 * 1. Find your PHP executable path (for XAMPP, usually):
 *    C:\xampp\php\php.exe
 *
 * 2. Find your script path:
 *    C:\xampp\htdocs\PHPQueueing\clear_queue.php
 *
 * 3. Open Windows Task Scheduler:
 *    - Press Win+S, type "Task Scheduler", and open it.
 *    - Click "Create Basic Task..."
 *    - Name: Clear Clinic Queue Daily
 *    - Description: Deletes all queue data at midnight Philippine time.
 *
 * 4. Set the Trigger:
 *    - Choose "Daily"
 *    - Start: Set the date and time to the next midnight in Philippine time (UTC+8).
 *      (If your PC is set to Philippine time, use 12:00:00 AM. If not, convert midnight PH time to your local time.)
 *
 * 5. Set the Action:
 *    - Choose "Start a program"
 *    - Program/script:
 *        C:\xampp\php\php.exe
 *    - Add arguments:
 *        C:\xampp\htdocs\PHPQueueing\clear_queue.php
 *
 * 6. Finish:
 *    - Review and finish the wizard.
 *
 * 7. (Optional) Test the Task:
 *    - Right-click your new task in Task Scheduler and choose "Run" to make sure it works.
 *    - Check your database to confirm the 'queue' table is empty.
 *
 * For Linux (cron job), add this to your crontab (adjust path and time zone as needed):
 *    0 0 * * * /usr/bin/php /path/to/PHPQueueing/clear_queue.php
 *
 * If you need help, ask your system administrator or refer to the documentation for Task Scheduler or cron.
 */
require 'config.php';
$conn->query("DELETE FROM queue");
?> 