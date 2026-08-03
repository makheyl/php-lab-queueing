-- Database setup for OPD Queueing System with Doctor Appointment Tracking
-- Run this SQL to create the necessary tables

-- Create doctor_appointments_log table if it doesn't exist
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
  KEY `idx_log_time` (`log_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create daily_statistics table for tracking daily counts
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
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`),
  KEY `idx_date` (`date`),
  KEY `idx_table_number` (`table_number`),
  KEY `idx_doctor_name` (`doctor_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create historical_data table for storing past records
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
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`),
  KEY `idx_date` (`date`),
  KEY `idx_exported` (`exported_to_csv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci; 