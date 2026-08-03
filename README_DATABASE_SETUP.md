# OPD Queueing System - Database Setup & Daily Reset

## Overview
This system now includes comprehensive doctor appointment tracking with automatic daily statistics reset and historical data archiving.

## Database Tables

### 1. `doctor_appointments_log`
Tracks all doctor actions (serve, pending, cancel, notify) with timestamps.

```sql
CREATE TABLE IF NOT EXISTS `doctor_appointments_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_name` varchar(100) NOT NULL,
  `table_number` int(11) NOT NULL,
  `patient_queue_number` int(11) NOT NULL,
  `action` enum('served','pending','cancelled','notified') NOT NULL DEFAULT 'served',
  `log_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doctor_name` (`doctor_name`),
  KEY `idx_table_number` (`table_number`),
  KEY `idx_log_time` (`log_time`),
  KEY `idx_date` (DATE(`log_time`))
);
```

### 2. `daily_statistics`
Tracks daily counts per doctor/table combination. Automatically resets each day.

```sql
CREATE TABLE IF NOT EXISTS `daily_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`)
);
```

### 3. `historical_data`
Stores archived daily statistics for historical reporting and CSV export.

```sql
CREATE TABLE IF NOT EXISTS `historical_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `exported_to_csv` tinyint(1) DEFAULT 0,
  `export_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`)
);
```

## Setup Instructions

### 1. Run Database Setup
Execute the SQL commands in `database_setup.sql` to create the required tables:

```bash
mysql -u root -p opdqueue < database_setup.sql
```

### 2. Schedule Daily Reset
Set up the daily reset script to run automatically at midnight:

#### Windows Task Scheduler:
1. Open Task Scheduler
2. Create Basic Task
3. Name: "OPD Daily Reset"
4. Trigger: Daily at 12:00:00 AM
5. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\PHPOPDQueueing\daily_reset.php`

#### Linux Cron:
```bash
# Add to crontab (crontab -e)
0 0 * * * /usr/bin/php /path/to/PHPOPDQueueing/daily_reset.php
```

## Features

### 1. Real-time Statistics
- **Doctor Panel**: Shows current day's statistics (served, pending, cancelled)
- **Table Badges**: Displays served counts for all tables
- **Auto-refresh**: Updates automatically when data changes

### 2. Daily Reset System
- **Automatic Archiving**: Moves daily statistics to historical_data table
- **CSV Export**: Automatically exports daily reports to CSV files
- **Data Cleanup**: Removes old queue and log data
- **Zero Downtime**: Resets happen at midnight without affecting operations

### 3. Admin Reporting
- **Daily Statistics**: View comprehensive daily statistics
- **Historical Data**: Access past records and trends
- **CSV Export**: Download reports in CSV format
- **Print Support**: Print-friendly reports

### 4. Database Monitoring
- **Performance Indexes**: Optimized queries with proper indexing
- **Data Integrity**: Foreign key constraints and validation
- **Backup Ready**: Structured for easy backup and restore

## File Structure

```
PHPOPDQueueing/
├── config.php                 # Database configuration
├── database_setup.sql         # Database table creation
├── daily_reset.php           # Daily reset script
├── doctor.php                # Doctor panel (updated)
├── admin.php                 # Admin reports (updated)
├── index.php                 # Main queue interface
├── display.php               # Queue display
├── exports/                  # CSV export directory
│   └── daily_report_YYYY-MM-DD.csv
└── README_DATABASE_SETUP.md  # This file
```

## Usage

### For Doctors:
1. Access `doctor.php`
2. Enter name and table number
3. View real-time statistics
4. Serve, pending, or cancel patients
5. Statistics update automatically

### For Administrators:
1. Access `admin.php`
2. Select date to view statistics
3. Export data to CSV
4. Print reports
5. Monitor daily trends

### Daily Reset Process:
1. Runs automatically at midnight
2. Archives current day's data
3. Exports to CSV file
4. Clears daily statistics
5. Cleans up old data

## Monitoring Queries

### Current Day Statistics:
```sql
SELECT * FROM daily_statistics 
WHERE date = CURDATE() 
ORDER BY table_number, doctor_name;
```

### Historical Data:
```sql
SELECT * FROM historical_data 
WHERE date = '2024-01-15' 
ORDER BY table_number, doctor_name;
```

### Doctor Performance:
```sql
SELECT doctor_name, 
       SUM(patients_served) as total_served,
       SUM(patients_pending) as total_pending,
       SUM(patients_cancelled) as total_cancelled
FROM historical_data 
WHERE date BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY doctor_name;
```

## Troubleshooting

### Common Issues:

1. **Tables not created**: Run `database_setup.sql` manually
2. **Daily reset not working**: Check file permissions and PHP path
3. **Statistics not updating**: Verify database connection in `config.php`
4. **CSV export failing**: Ensure `exports/` directory exists and is writable

### Log Files:
- Check PHP error logs for script issues
- Monitor database logs for connection problems
- Review task scheduler/cron logs for scheduling issues

## Support

For technical support or questions about the database setup, please refer to the system documentation or contact the development team. 