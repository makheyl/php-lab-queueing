-- Database setup for Laboratory Queueing System
-- Run this SQL to create the necessary tables

-- Create queue table if it doesn't exist
CREATE TABLE IF NOT EXISTS `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_date` date NOT NULL,
  `queue_number` int(11) NOT NULL,
  `status` enum('waiting','interviewing','awaiting_payment','ready_for_extraction','extracting','completed','no_show','cancelled') NOT NULL DEFAULT 'waiting',
  `payment_required` tinyint(1) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `interview_called_at` datetime DEFAULT NULL,
  `interview_completed_at` datetime DEFAULT NULL,
  `interview_station` int(11) DEFAULT NULL,
  `interview_by` varchar(100) DEFAULT NULL,
  `payment_confirmed_at` datetime DEFAULT NULL,
  `payment_confirmed_by` varchar(100) DEFAULT NULL,
  `payment_reference` varchar(50) DEFAULT NULL,
  -- extraction_eligible_at is the sort key for the extraction queue. NULL means
  -- the patient is still out paying and is not in the queue. It is stamped at
  -- interview completion for no-charge patients, and at payment confirmation
  -- for paying patients. The extraction queue is NEVER ordered by queue_number.
  -- datetime(6) (microsecond precision) is deliberate: two rows stamped with
  -- plain NOW() in the same second would tie, and any tiebreak that falls
  -- back to queue_number would silently reintroduce queue_number ordering.
  `extraction_eligible_at` datetime(6) DEFAULT NULL,
  `extraction_called_at` datetime DEFAULT NULL,
  `extraction_completed_at` datetime DEFAULT NULL,
  `extraction_station` int(11) DEFAULT NULL,
  `extraction_by` varchar(100) DEFAULT NULL,
  `recall_count` int(11) NOT NULL DEFAULT 0,
  `no_show_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_service_date_queue_number` (`service_date`, `queue_number`),
  KEY `idx_service_date_status_extraction_eligible` (`service_date`, `status`, `extraction_eligible_at`),
  KEY `idx_service_date_queue_number` (`service_date`, `queue_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create lab_activity_log table if it doesn't exist
CREATE TABLE IF NOT EXISTS `lab_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_name` varchar(100) NOT NULL,
  `station` int(11) NOT NULL,
  `queue_number` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `log_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_name` (`staff_name`),
  KEY `idx_station` (`station`),
  KEY `idx_log_time` (`log_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create daily_statistics table for tracking daily counts
CREATE TABLE IF NOT EXISTS `daily_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `station` int(11) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `no_charge_count` int(11) DEFAULT 0,
  `for_payment_count` int(11) DEFAULT 0,
  `no_show_count` int(11) DEFAULT 0,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_station_staff` (`date`, `station`, `staff_name`),
  KEY `idx_date` (`date`),
  KEY `idx_station` (`station`),
  KEY `idx_staff_name` (`staff_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create historical_data table for storing past records
CREATE TABLE IF NOT EXISTS `historical_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `station` int(11) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `no_charge_count` int(11) DEFAULT 0,
  `for_payment_count` int(11) DEFAULT 0,
  `no_show_count` int(11) DEFAULT 0,
  `exported_to_csv` tinyint(1) DEFAULT 0,
  `export_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_station_staff` (`date`, `station`, `staff_name`),
  KEY `idx_date` (`date`),
  KEY `idx_exported` (`exported_to_csv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create settings table if it doesn't exist
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(50) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('queue_prefix', 'L'),
  ('daily_reset_hour', '4'),
  ('flash_duration_seconds', '10'),
  ('recall_limit', '3'),
  ('announcement', ''),
  ('queue_retention_days', '30')
ON DUPLICATE KEY UPDATE `key` = `key`;
