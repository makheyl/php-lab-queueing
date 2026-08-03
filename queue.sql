CREATE DATABASE IF NOT EXISTS queuedb;
USE queuedb;

CREATE TABLE IF NOT EXISTS queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    queue_number INT NOT NULL,
    status ENUM('waiting','serving','served') DEFAULT 'waiting',
    doctor_name VARCHAR(100) DEFAULT NULL,
    table_number INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    served_at DATETIME NULL
); 