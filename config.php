<?php
// config.php - Database connection, configuration, session initialization, and database auto-setup

// Prevent direct access to config if run directly, but allow inclusion
date_default_timezone_set('Asia/Kolkata');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$db   = 'civic_app_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// SMTP Email Configurations from user's Desktop International Gems project
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'kagamergamero@gmail.com');
define('SMTP_PASS', 'aqsc ftjw edkh yhgl'); // App-specific password
define('SMTP_SECURE', 'ssl');
define('SMTP_FROM_EMAIL', 'kagamergamero@gmail.com');
define('SMTP_FROM_NAME', 'City Civic Portal');

$dsn = "mysql:host=$host;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Connect to MySQL server without selecting DB initially
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Auto-create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `$db`;"); // Select the database
    
    $conn = $pdo;
    
    // Check if tables exist, if not, import schema.sql
    $table_check = $conn->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$table_check) {
        $schema_path = __DIR__ . '/schema.sql';
        if (file_exists($schema_path)) {
            $schema_sql = file_get_contents($schema_path);
            // Split and run each query statement
            $queries = explode(';', $schema_sql);
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $conn->exec($query);
                }
            }
        }
    } else {
        // Migration safe-checks
        $cols = $conn->query("DESCRIBE `traffic_reports`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('violation_type', $cols)) {
            $conn->exec("ALTER TABLE `traffic_reports` ADD COLUMN `violation_type` VARCHAR(100) DEFAULT 'General Violation' AFTER `user_id`");
        }
    }

    // Auto-migration checks for users table profile columns
    $user_cols = $conn->query("DESCRIBE `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('full_name', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(150) NULL AFTER `email`");
    }
    if (!in_array('phone', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(30) NULL AFTER `full_name`");
    }
    if (!in_array('address', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `address` TEXT NULL AFTER `phone`");
    }
    if (!in_array('profile_pic', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `profile_pic` VARCHAR(255) NULL AFTER `address`");
    }
    if (!in_array('eco_points', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `eco_points` INT DEFAULT 0 AFTER `wallet_balance`");
    }

    // Ensure CO2 tables exist
    $conn->exec("CREATE TABLE IF NOT EXISTS `co2_products` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `barcode` VARCHAR(50) NOT NULL UNIQUE,
      `name` VARCHAR(150) NOT NULL,
      `brand` VARCHAR(100) NOT NULL,
      `category` VARCHAR(100) NOT NULL,
      `weight` VARCHAR(50) NOT NULL,
      `co2_impact` DECIMAL(10,2) NOT NULL,
      `comparison_text` TEXT NOT NULL,
      `points_reward` INT NOT NULL DEFAULT 10
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->exec("CREATE TABLE IF NOT EXISTS `co2_user_scans` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `product_id` INT NOT NULL,
      `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `points_earned` INT NOT NULL,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`product_id`) REFERENCES `co2_products`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->exec("CREATE TABLE IF NOT EXISTS `eco_task_claims` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `task_name` VARCHAR(150) NOT NULL,
      `description` TEXT NOT NULL,
      `photo_path` VARCHAR(255) NOT NULL,
      `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
      `points_reward` INT NOT NULL,
      `cash_reward` DECIMAL(10,2) NOT NULL,
      `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Ensure vehicle_challans table exists & seed MH39BA3148
    $conn->exec("CREATE TABLE IF NOT EXISTS `vehicle_challans` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `vehicle_number` VARCHAR(30) NOT NULL UNIQUE,
      `owner_name` VARCHAR(150) NOT NULL,
      `violation_type` VARCHAR(255) NOT NULL,
      `challan_amount` DECIMAL(10,2) NOT NULL DEFAULT 2000.00,
      `status` ENUM('Unpaid', 'Paid') DEFAULT 'Unpaid',
      `location` VARCHAR(255) NOT NULL,
      `issued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Seed MH39BA3148 and backup demo vehicles
    $conn->exec("INSERT IGNORE INTO `vehicle_challans` (`vehicle_number`, `owner_name`, `violation_type`, `challan_amount`, `status`, `location`) 
    VALUES 
    ('MH39BA3148', 'Ayush Sharma', 'Signal Jumping & Over-Speeding at Dharampeth Square', 2000.00, 'Unpaid', 'Dharampeth Square, Nagpur'),
    ('MH31AB1234', 'Rajesh Kumar', 'No Helmet & Wrong-Way Driving', 1000.00, 'Unpaid', 'Sitabuldi Flyover, Nagpur'),
    ('MH40XY5678', 'Priya Deshmukh', 'Illegal Parking in No-Parking Zone', 500.00, 'Unpaid', 'Manewada Ring Road, Nagpur');");

    // Ensure pothole_votes table exists for community voting/verification polls
    $conn->exec("CREATE TABLE IF NOT EXISTS `pothole_votes` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `pothole_id` INT NOT NULL,
      `user_id` INT NOT NULL,
      `vote_type` ENUM('upvote', 'downvote') NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `unique_user_pothole_vote` (`pothole_id`, `user_id`),
      FOREIGN KEY (`pothole_id`) REFERENCES `pothole_reports`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Add eco_points column to users if it doesn't exist
    $user_cols = $conn->query("DESCRIBE `users`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('eco_points', $user_cols)) {
        $conn->exec("ALTER TABLE `users` ADD COLUMN `eco_points` INT DEFAULT 0 AFTER `wallet_balance`");
    }

    // Seed default products
    $stmt = $conn->prepare("INSERT INTO `co2_products` (`barcode`, `name`, `brand`, `category`, `weight`, `co2_impact`, `comparison_text`, `points_reward`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `co2_impact`=VALUES(`co2_impact`), `points_reward`=VALUES(`points_reward`)");
    
    // 1. Bisleri 100ml
    $stmt->execute([
        '8901152010118', 
        'Bisleri 100ml', 
        'Bisleri International', 
        'Plastic Bottle', 
        '100 ML', 
        0.12, 
        'Scanning this plastic bottle earns points. Make sure to dispose of it in a recycling bin to earn bonus rewards!', 
        15
    ]);
    
    // 2. Pepsi 500ml
    $stmt->execute([
        '8901491101850', 
        'Pepsi 500ml', 
        'PepsiCo', 
        'Beverage Bottle', 
        '500 ML', 
        0.35, 
        'Soft drinks in PET bottles have high carbon footprint due to manufacturing emissions.', 
        10
    ]);
    
    // 3. Eco Jute Bag
    $stmt->execute([
        '8901234567890', 
        'Eco Jute Bag', 
        'GreenEarth', 
        'Reusable Bag', 
        '1 Unit', 
        0.02, 
        'Jute bags are biodegradable and extremely low carbon. Awesome choice!', 
        30
    ]);
    
    // 4. Aluminum Soda Can
    $stmt->execute([
        '8901765432109', 
        'Coca Cola 330ml Can', 
        'The Coca-Cola Company', 
        'Aluminum Can', 
        '330 ML', 
        0.22, 
        'Aluminum is highly recyclable. Recycling it saves 95% of the energy needed to make new aluminum!', 
        12
    ]);

    // 5. Bisleri 250ml
    $stmt->execute([
        '8901152010125', 
        'Bisleri 250ml', 
        'Bisleri International', 
        'Plastic Bottle', 
        '250 ML', 
        0.18, 
        'Small plastic bottles contribute to ocean plastic. Choose larger bottles or a reusable container if possible!', 
        12
    ]);
} catch (\PDOException $e) {
    die("Database connection or initialization failed: " . $e->getMessage());
}

// Helper function for file upload
if (!function_exists('handle_file_upload')) {
    function handle_file_upload($file, $prefix = 'img_') {
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $target_dir = __DIR__ . "/uploads/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            return null;
        }
        $filename = $prefix . uniqid() . '.' . $ext;
        $target_file = $target_dir . $filename;
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            return "uploads/" . $filename;
        }
        return null;
    }
}

// Authentication Helpers
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function get_logged_in_user($conn) {
    if (!is_logged_in()) {
        return null;
    }
    $stmt = $conn->prepare("SELECT u.*, w.name as ward_name FROM users u LEFT JOIN wards w ON u.ward_id = w.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function require_login($conn) {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
    $user = get_logged_in_user($conn);
    if (!$user) {
        header("Location: logout.php");
        exit;
    }
    return $user;
}

function require_admin($conn) {
    $user = require_login($conn);
    if ($user['role'] !== 'admin') {
        header("Location: dashboard.php");
        exit;
    }
    return $user;
}

// Helper to format currency
function format_currency($amount) {
    return '₹' . number_format($amount, 2);
}

// Helper to mask emails for citizen privacy
function mask_email($email) {
    if (empty($email)) return 'Citizen';
    $parts = explode('@', $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $masked_name = (strlen($name) <= 3) ? $name . '***' : substr($name, 0, 3) . str_repeat('*', max(0, strlen($name) - 3));
    return $masked_name . '@' . $domain;
}
?>
