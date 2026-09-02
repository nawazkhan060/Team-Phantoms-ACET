-- MySQL Schema for Civic Application
CREATE DATABASE IF NOT EXISTS `civic_app_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `civic_app_db`;

-- Wards table
CREATE TABLE IF NOT EXISTS `wards` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table (email, otp, otp_expiry, ward_id, role, wallet_balance)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `otp` VARCHAR(6) DEFAULT NULL,
  `otp_expiry` DATETIME DEFAULT NULL,
  `ward_id` INT DEFAULT NULL,
  `role` ENUM('citizen', 'admin') DEFAULT 'citizen',
  `wallet_balance` DECIMAL(10,2) DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Loadshedding schedule (date, start time, end time, reason)
CREATE TABLE IF NOT EXISTS `loadshedding_schedule` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ward_id` INT NOT NULL,
  `date` DATE NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Water supply schedule
CREATE TABLE IF NOT EXISTS `water_schedule` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ward_id` INT NOT NULL,
  `area_name` VARCHAR(150) NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utility complaints (loadshedding unscheduled outages & water issues)
CREATE TABLE IF NOT EXISTS `utility_complaints` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `ward_id` INT NOT NULL,
  `type` ENUM('loadshedding', 'water') NOT NULL,
  `complaint_type` VARCHAR(100) NOT NULL, -- 'unscheduled_outage', 'water_not_received', 'low_pressure'
  `details` TEXT DEFAULT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('Pending', 'Resolved') DEFAULT 'Pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traffic reports (Crowdsourced Traffic Challan: photo, auto GPS + timestamp, status, reward)
CREATE TABLE IF NOT EXISTS `traffic_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `violation_type` VARCHAR(100) DEFAULT 'General Violation',
  `photo_path` VARCHAR(255) NOT NULL,
  `latitude` DECIMAL(10,8) NOT NULL,
  `longitude` DECIMAL(11,8) NOT NULL,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
  `reward_credited` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pothole reports (photo + geo-tag + description, status tracker)
CREATE TABLE IF NOT EXISTS `pothole_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `photo_path` VARCHAR(255) NOT NULL,
  `latitude` DECIMAL(10,8) NOT NULL,
  `longitude` DECIMAL(11,8) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('Reported', 'Acknowledged', 'In Progress', 'Resolved') DEFAULT 'Reported',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Waste collection schedule
CREATE TABLE IF NOT EXISTS `waste_schedule` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ward_id` INT NOT NULL,
  `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
  `collection_time` TIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Waste complaints (missed pickup, illegal dumping with photo)
CREATE TABLE IF NOT EXISTS `waste_complaints` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `ward_id` INT NOT NULL,
  `complaint_type` ENUM('missed_pickup', 'illegal_dumping') NOT NULL,
  `photo_path` VARCHAR(255) DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `status` ENUM('Pending', 'Resolved') DEFAULT 'Pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AQI Readings
CREATE TABLE IF NOT EXISTS `aqi_readings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ward_id` INT NOT NULL,
  `aqi_value` INT NOT NULL,
  `co2_value` INT DEFAULT NULL,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- News posts (title, category, content, image, is_emergency, ward_id)
CREATE TABLE IF NOT EXISTS `news_posts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `image_path` VARCHAR(255) DEFAULT NULL,
  `is_emergency` TINYINT(1) DEFAULT 0,
  `ward_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ward_id`) REFERENCES `wards`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reward Transactions
CREATE TABLE IF NOT EXISTS `reward_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `transaction_type` ENUM('credit', 'debit') NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default Wards (10 Zones of Nagpur Municipal Corporation)
INSERT INTO `wards` (`id`, `name`) VALUES
(1, 'Zone 1 - Laxmi Nagar'),
(2, 'Zone 2 - Dharampeth'),
(3, 'Zone 3 - Hanuman Nagar'),
(4, 'Zone 4 - Dhantoli'),
(5, 'Zone 5 - Nehru Nagar'),
(6, 'Zone 6 - Gandhibagh'),
(7, 'Zone 7 - Satranjipura'),
(8, 'Zone 8 - Lakadganj'),
(9, 'Zone 9 - Ashi Nagar'),
(10, 'Zone 10 - Mangalwari')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Insert a default admin account
INSERT INTO `users` (`email`, `role`, `wallet_balance`) VALUES
('admin@civic.gov', 'admin', 0.00)
ON DUPLICATE KEY UPDATE `role`='admin';
