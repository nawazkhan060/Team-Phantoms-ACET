<?php
// dashboard.php - Citizen Portal
require_once 'config.php';

$is_logged_in = is_logged_in();
$user = null;
if ($is_logged_in) {
    $user = get_logged_in_user($conn);
    if ($user && $user['role'] === 'admin') {
        header("Location: admin.php");
        exit;
    }
}

$active_tab = $_GET['tab'] ?? 'power';
$error = '';
$success = '';

// Helper function for file upload
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

// Stamping coordinates and timestamp directly on the image using GD library
function watermark_image($file_path, $latitude, $longitude, $timestamp) {
    if (!extension_loaded('gd')) {
        return; // GD library is not enabled
    }
    
    $info = getimagesize($file_path);
    if (!$info) return;
    
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
            $im = imagecreatefromjpeg($file_path);
            break;
        case 'image/png':
            $im = imagecreatefrompng($file_path);
            break;
        case 'image/webp':
            $im = imagecreatefromwebp($file_path);
            break;
        case 'image/gif':
            $im = imagecreatefromgif($file_path);
            break;
        default:
            return;
    }
    
    if (!$im) return;
    
    $text = "GPS: " . number_format($latitude, 6) . ", " . number_format($longitude, 6) . " | Date: " . date('Y-m-d H:i:s', strtotime($timestamp));
    
    $width = imagesx($im);
    $height = imagesy($im);
    
    // Choose font size based on image width (GD built-in font size is 1 to 5)
    $font_size = max(3, min(5, intval($width / 150)));
    
    $color_yellow = imagecolorallocate($im, 255, 255, 0);
    $color_black = imagecolorallocate($im, 0, 0, 0);
    
    $margin = 15;
    $font_height = imagefontheight($font_size);
    
    $x = $margin;
    $y = $height - $font_height - $margin;
    
    // Draw dropshadow
    imagestring($im, $font_size, $x - 1, $y - 1, $text, $color_black);
    imagestring($im, $font_size, $x + 1, $y - 1, $text, $color_black);
    imagestring($im, $font_size, $x - 1, $y + 1, $text, $color_black);
    imagestring($im, $font_size, $x + 1, $y + 1, $text, $color_black);
    
    // Draw text
    imagestring($im, $font_size, $x, $y, $text, $color_yellow);
    
    // Save image back to disk
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($im, $file_path, 90);
            break;
        case 'image/png':
            imagepng($im, $file_path);
            break;
        case 'image/webp':
            imagewebp($im, $file_path, 90);
            break;
        case 'image/gif':
            imagegif($im, $file_path);
            break;
    }
    
    imagedestroy($im);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Update Ward (Allows both citizen and guest)
    if (isset($_POST['update_ward'])) {
        $ward_id = intval($_POST['ward_id']);
        $_SESSION['active_ward_id'] = $ward_id;
        if ($is_logged_in) {
            $stmt = $conn->prepare("UPDATE users SET ward_id = ? WHERE id = ?");
            $stmt->execute([$ward_id, $user['id']]);
            $user = get_logged_in_user($conn);
        } else {
            $_SESSION['guest_ward_id'] = $ward_id;
        }
        $success = "Ward updated successfully! Active ward preference saved.";
    }
    
    // Check authentication for other reports submissions
    elseif (!$is_logged_in) {
        $error = "Authentication required. Please log in to file reports.";
    }
    
    // 2. Report Unscheduled Loadshedding Outage
    elseif (isset($_POST['report_outage'])) {
        if (!$user['ward_id']) {
            $error = "Please set your ward first.";
        } else {
            $stmt = $conn->prepare("INSERT INTO utility_complaints (user_id, ward_id, type, complaint_type, details, status) VALUES (?, ?, 'loadshedding', 'unscheduled_outage', 'Unscheduled power outage reported by citizen.', 'Pending')");
            $stmt->execute([$user['id'], $user['ward_id']]);
            $success = "Unscheduled outage reported successfully. Utility team has been notified.";
        }
    }
    
    // 3. Submit Water Supply Complaint
    elseif (isset($_POST['water_complaint'])) {
        $complaint_type = $_POST['complaint_type'] ?? '';
        $details = trim($_POST['details'] ?? '');
        
        if (!$user['ward_id']) {
            $error = "Please set your ward first.";
        } elseif (empty($complaint_type)) {
            $error = "Please select a complaint type.";
        } else {
            $photo_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_path = handle_file_upload($_FILES['photo'], 'water_');
            }
            
            $stmt = $conn->prepare("INSERT INTO utility_complaints (user_id, ward_id, type, complaint_type, details, photo_path, status) VALUES (?, ?, 'water', ?, ?, ?, 'Pending')");
            $stmt->execute([$user['id'], $user['ward_id'], $complaint_type, $details, $photo_path]);
            $success = "Water complaint submitted successfully!";
        }
    }
    
    // 4. Submit Traffic Challan (GPS auto-captured)
    elseif (isset($_POST['traffic_report'])) {
        $lat = floatval($_POST['latitude'] ?? 0);
        $lng = floatval($_POST['longitude'] ?? 0);
        $violation_type = trim($_POST['violation_type'] ?? 'General Violation');
        $photo_path = null;
        
        if ($lat == 0 || $lng == 0) {
            $error = "GPS coordinates could not be captured. Please enable location services.";
        } else {
            // Check if webcam base64 photo is provided
            if (!empty($_POST['webcam_photo'])) {
                $data = $_POST['webcam_photo'];
                if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                    $data = substr($data, strpos($data, ',') + 1);
                    $ext = strtolower($type[1]);
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                        $data = base64_decode($data);
                        if ($data !== false) {
                            $filename = 'traffic_' . uniqid() . '.' . $ext;
                            $target_dir = __DIR__ . "/uploads/";
                            if (!is_dir($target_dir)) {
                                mkdir($target_dir, 0777, true);
                            }
                            if (file_put_contents($target_dir . $filename, $data)) {
                                $photo_path = "uploads/" . $filename;
                            }
                        }
                    }
                }
            }
            // Fallback to normal file upload
            elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_path = handle_file_upload($_FILES['photo'], 'traffic_');
            }
            
            if (!$photo_path) {
                $error = "Please upload or capture a photo of the traffic violation.";
            } else {
                $timestamp = date('Y-m-d H:i:s');
                // Stamp GPS and Timestamp coordinates directly on the picture
                watermark_image(__DIR__ . '/' . $photo_path, $lat, $lng, $timestamp);
                
                $stmt = $conn->prepare("INSERT INTO traffic_reports (user_id, violation_type, photo_path, latitude, longitude, status, timestamp) VALUES (?, ?, ?, ?, ?, 'Pending', ?)");
                $stmt->execute([$user['id'], $violation_type, $photo_path, $lat, $lng, $timestamp]);
                $success = "Traffic report submitted! Admin will review the violation, and you will receive a ₹50 reward if approved. Metadata stamped on image.";
            }
        }
    }
    
    // 5. Submit Pothole Report
    elseif (isset($_POST['pothole_report'])) {
        $lat = floatval($_POST['latitude'] ?? 0);
        $lng = floatval($_POST['longitude'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $photo_path = null;
        
        if ($lat == 0 || $lng == 0) {
            $error = "GPS coordinates could not be captured. Please enable location services.";
        } elseif (empty($description)) {
            $error = "Please enter a description of the damage.";
        } else {
            // Check if webcam base64 photo is provided
            if (!empty($_POST['webcam_photo'])) {
                $data = $_POST['webcam_photo'];
                if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                    $data = substr($data, strpos($data, ',') + 1);
                    $ext = strtolower($type[1]);
                    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                        $data = base64_decode($data);
                        if ($data !== false) {
                            $filename = 'pothole_' . uniqid() . '.' . $ext;
                            $target_dir = __DIR__ . "/uploads/";
                            if (!is_dir($target_dir)) {
                                mkdir($target_dir, 0777, true);
                            }
                            if (file_put_contents($target_dir . $filename, $data)) {
                                $photo_path = "uploads/" . $filename;
                            }
                        }
                    }
                }
            }
            // Fallback to normal file upload
            elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_path = handle_file_upload($_FILES['photo'], 'pothole_');
            }
            
            if (!$photo_path) {
                $error = "Please upload or capture a photo of the road damage.";
            } else {
                $stmt = $conn->prepare("INSERT INTO pothole_reports (user_id, photo_path, latitude, longitude, description, status) VALUES (?, ?, ?, ?, ?, 'Reported')");
                $stmt->execute([$user['id'], $photo_path, $lat, $lng, $description]);
                $success = "Pothole report submitted successfully. You can track progress on the map and community verification poll.";
            }
        }
    }

    // 5b. Vote / Poll on Pothole Report (Thumbs Up / Thumbs Down Verification)
    elseif (isset($_POST['vote_pothole'])) {
        $pothole_id = intval($_POST['pothole_id'] ?? 0);
        $vote_type = ($_POST['vote_type'] === 'downvote') ? 'downvote' : 'upvote';
        
        if ($pothole_id > 0) {
            // Check if user already voted
            $stmt = $conn->prepare("SELECT vote_type FROM pothole_votes WHERE pothole_id = ? AND user_id = ?");
            $stmt->execute([$pothole_id, $user['id']]);
            $existing = $stmt->fetchColumn();
            
            if ($existing === $vote_type) {
                // Toggle off vote if clicking same button
                $stmt = $conn->prepare("DELETE FROM pothole_votes WHERE pothole_id = ? AND user_id = ?");
                $stmt->execute([$pothole_id, $user['id']]);
                $success = "Your vote has been removed.";
            } else {
                // Insert or update vote
                $stmt = $conn->prepare("INSERT INTO pothole_votes (pothole_id, user_id, vote_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote_type = VALUES(vote_type)");
                $stmt->execute([$pothole_id, $user['id'], $vote_type]);
                $success = ($vote_type === 'upvote') ? "👍 Thumbs Up logged! Thank you for verifying this pothole complaint." : "👎 Thumbs Down logged.";
            }
        }
    }
    
    // 6. Submit Waste Complaint
    elseif (isset($_POST['waste_complaint'])) {
        $complaint_type = $_POST['complaint_type'] ?? '';
        $details = trim($_POST['details'] ?? '');
        
        if (!$user['ward_id']) {
            $error = "Please set your ward first.";
        } elseif (empty($complaint_type)) {
            $error = "Please select a complaint type.";
        } else {
            $photo_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_path = handle_file_upload($_FILES['photo'], 'waste_');
            }
            
            $stmt = $conn->prepare("INSERT INTO waste_complaints (user_id, ward_id, complaint_type, details, photo_path, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$user['id'], $user['ward_id'], $complaint_type, $details, $photo_path]);
            $success = "Waste complaint submitted successfully!";
        }
    }
    
    // 7. Submit General Grievance Complaint
    elseif (isset($_POST['submit_grievance'])) {
        $complaint_type = trim($_POST['complaint_type'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $ward_id = intval($_POST['ward_id'] ?? 0);
        
        if ($ward_id === 0) {
            $error = "Please select a municipal ward zone.";
        } elseif (empty($complaint_type)) {
            $error = "Please select a grievance category.";
        } elseif (empty($details)) {
            $error = "Please provide details of your grievance.";
        } else {
            $photo_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $photo_path = handle_file_upload($_FILES['photo'], 'grievance_');
            }
            
            $stmt = $conn->prepare("INSERT INTO utility_complaints (user_id, ward_id, type, complaint_type, details, photo_path, status) VALUES (?, ?, 'grievance', ?, ?, ?, 'Pending')");
            $stmt->execute([$user['id'], $ward_id, $complaint_type, $details, $photo_path]);
            $success = "Grievance complaint filed successfully! Nagpur Mahanagar Palika team has been notified.";
        }
    }
    
    // 8. Pay outstanding property tax or water bill using wallet balance
    elseif (isset($_POST['pay_tax'])) {
        $bill_type = $_POST['bill_type'] ?? 'Property Tax';
        $amount = floatval($_POST['amount'] ?? 0);
        
        if ($amount <= 0) {
            $error = "Invalid transaction amount.";
        } elseif ($user['wallet_balance'] < $amount) {
            $error = "Insufficient wallet balance to complete payment. Earn rewards by reporting violations.";
        } else {
            // Deduct balance from user
            $new_balance = $user['wallet_balance'] - $amount;
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
            $stmt->execute([$new_balance, $user['id']]);
            
            // Log debit transaction
            $desc = "Paid NMC " . $bill_type;
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, description, transaction_type) VALUES (?, ?, ?, 'debit')");
            $stmt->execute([$user['id'], $amount, $desc]);
            
            // Persist payment state in session
            if ($bill_type === 'Property Tax') {
                $_SESSION['property_tax_paid'] = true;
            } else {
                $_SESSION['water_bill_paid'] = true;
            }
            
            $user = get_logged_in_user($conn);
            $success = "Successfully paid " . htmlspecialchars($bill_type) . " of $" . number_format($amount, 2) . " using your Wallet Balance!";
        }
    }
    // 9. Submit Daily Eco Task proof
    elseif (isset($_POST['submit_eco_task'])) {
        $task_name = $_POST['task_name'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $points_reward = intval($_POST['points_reward'] ?? 0);
        $cash_reward = floatval($_POST['cash_reward'] ?? 0.00);
        
        $photo_path = null;
        if (isset($_FILES['proof_photo']) && $_FILES['proof_photo']['error'] === UPLOAD_ERR_OK) {
            $photo_path = handle_file_upload($_FILES['proof_photo'], 'eco_');
        }
        
        if (empty($task_name)) {
            $error = "Task name is required.";
        } elseif (!$photo_path) {
            $error = "Please upload a photo as proof of action.";
        } elseif (empty($description)) {
            $error = "Please provide description notes of what you did.";
        } else {
            // Check if they already submitted this task name today
            $stmt = $conn->prepare("SELECT COUNT(*) FROM eco_task_claims WHERE user_id = ? AND task_name = ? AND DATE(submitted_at) = CURDATE()");
            $stmt->execute([$user['id'], $task_name]);
            $already_submitted = (int)$stmt->fetchColumn() > 0;
            
            if ($already_submitted) {
                $error = "You have already submitted a claim for this challenge today.";
            } else {
                $stmt = $conn->prepare("INSERT INTO eco_task_claims (user_id, task_name, description, photo_path, points_reward, cash_reward, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([$user['id'], $task_name, $description, $photo_path, $points_reward, $cash_reward]);
                $success = "Eco Action proof submitted! Municipal administrators will review the photo and award XP + Cash rewards.";
            }
        }
    }
}

// Fetch all Wards for dropdowns
$wards = $conn->query("SELECT * FROM wards ORDER BY name ASC")->fetchAll();

// Localized Schedules & Ward Info
$active_ward_id = $is_logged_in ? $user['ward_id'] : ($_SESSION['guest_ward_id'] ?? null);
$active_ward_name = '';
if ($active_ward_id) {
    $stmt = $conn->prepare("SELECT name FROM wards WHERE id = ?");
    $stmt->execute([$active_ward_id]);
    $active_ward_name = $stmt->fetchColumn();
}

$loadshedding_schedule = [];
$water_schedule = [];
$waste_schedule = [];

if ($active_ward_id) {
    // Loadshedding
    $stmt = $conn->prepare("SELECT * FROM loadshedding_schedule WHERE ward_id = ? AND date >= CURDATE() ORDER BY date ASC, start_time ASC");
    $stmt->execute([$active_ward_id]);
    $loadshedding_schedule = $stmt->fetchAll();
    
    // Water supply
    $stmt = $conn->prepare("SELECT * FROM water_schedule WHERE ward_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC");
    $stmt->execute([$active_ward_id]);
    $water_schedule = $stmt->fetchAll();
    
    // Waste
    $stmt = $conn->prepare("SELECT * FROM waste_schedule WHERE ward_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), collection_time ASC");
    $stmt->execute([$active_ward_id]);
    $waste_schedule = $stmt->fetchAll();
}

// Fetch User Reports & Wallet Transactions (Logged-in only)
$user_traffic_reports = [];
$user_pothole_reports = [];
$user_grievances = [];
$wallet_transactions = [];

if ($is_logged_in) {
    // Traffic Challans
    $stmt = $conn->prepare("SELECT * FROM traffic_reports WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $user_traffic_reports = $stmt->fetchAll();
    
    // Potholes (for map and personal list)
    $stmt = $conn->prepare("SELECT * FROM pothole_reports WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $user_pothole_reports = $stmt->fetchAll();
    
    // Grievances
    $stmt = $conn->prepare("SELECT uc.*, w.name as ward_name FROM utility_complaints uc LEFT JOIN wards w ON uc.ward_id = w.id WHERE uc.user_id = ? AND uc.type = 'grievance' ORDER BY uc.created_at DESC");
    $stmt->execute([$user['id']]);
    $user_grievances = $stmt->fetchAll();
    
    // Fetch wallet transaction history
    $stmt = $conn->prepare("SELECT * FROM reward_transactions WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $wallet_transactions = $stmt->fetchAll();
}

// Fetch all open pothole reports city-wide for Map View & Community Voting Poll
$pothole_user_id = ($is_logged_in && isset($user['id'])) ? intval($user['id']) : 0;
$potholes_sql = "
    SELECT p.*, u.email as reporter_email,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'upvote') as upvotes,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'downvote') as downvotes,
      (SELECT vote_type FROM pothole_votes v WHERE v.pothole_id = p.id AND v.user_id = {$pothole_user_id}) as my_vote
    FROM pothole_reports p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.status != 'Resolved'
    ORDER BY (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'upvote') DESC, p.created_at DESC";
$all_open_potholes = $conn->query($potholes_sql)->fetchAll();

// Fetch AQI latest reading per ward
$aqi_query = "
    SELECT w.name as ward_name, r.aqi_value, r.co2_value, r.recorded_at 
    FROM aqi_readings r 
    INNER JOIN (
        SELECT ward_id, MAX(recorded_at) as max_date 
        FROM aqi_readings 
        GROUP BY ward_id
    ) latest ON r.ward_id = latest.ward_id AND r.recorded_at = latest.max_date
    INNER JOIN wards w ON r.ward_id = w.id
    ORDER BY w.name ASC";
$aqi_readings = $conn->query($aqi_query)->fetchAll();

// Fetch News (with optional category and ward filters)
$news_cat = $_GET['news_category'] ?? 'All';
$news_ward = $_GET['news_ward'] ?? 'All';

$news_sql = "SELECT n.*, w.name as ward_name FROM news_posts n LEFT JOIN wards w ON n.ward_id = w.id WHERE 1=1";
$params = [];

if ($news_cat !== 'All') {
    $news_sql .= " AND n.category = ?";
    $params[] = $news_cat;
}
if ($news_ward === 'MyWard' && $active_ward_id) {
    $news_sql .= " AND (n.ward_id = ? OR n.ward_id IS NULL)";
    $params[] = $active_ward_id;
}

$news_sql .= " ORDER BY n.is_emergency DESC, n.created_at DESC";
$news_stmt = $conn->prepare($news_sql);
$news_stmt->execute($params);
$news_posts = $news_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Citizen Dashboard - City Civic Portal</title>
  
  <!-- Google Fonts: Poppins -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  
  <!-- Leaflet.js Map CSS -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Custom Design Styles -->
  <link href="style.css" rel="stylesheet" />

  <!-- Theme initialization script (runs immediately to prevent flash) -->
  <script>
    (function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      document.documentElement.setAttribute('data-theme', savedTheme);
    })();
  </script>

  <style>
    /* Specific overrides for dashboard */
    #pothole-map {
      height: 400px;
      border-radius: 20px;
      box-shadow: var(--shadow-soft);
      z-index: 5;
    }
    
    .news-card-emergency {
      border-left: 6px solid var(--color-danger);
      background-color: var(--color-danger-bg);
    }
    
    .news-badge-emergency {
      background-color: var(--color-danger);
      color: white;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 6px;
      letter-spacing: 0.5px;
    }
    
    .bg-aqi-good { background-color: #e6faf0; color: #166534; }
    .bg-aqi-moderate { background-color: #fffbeb; color: #9a3412; }
    .bg-aqi-poor { background-color: #fef2f2; color: #991b1b; }
    
    [data-theme="dark"] .bg-aqi-good { background-color: rgba(22, 163, 74, 0.2); color: #4ade80; }
    [data-theme="dark"] .bg-aqi-moderate { background-color: rgba(217, 119, 6, 0.2); color: #fbbf24; }
    [data-theme="dark"] .bg-aqi-poor { background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }
  </style>
</head>
<body>

  <!-- Top Navigation Bar -->
  <nav class="navbar navbar-expand-lg sticky-top bg-white border-bottom shadow-sm py-2">
    <div class="container-fluid px-md-4">
      <div class="d-flex align-items-center">
        <!-- Hamburger Menu Trigger (Mobile only) -->
        <button class="btn btn-link nav-link p-0 me-3 d-lg-none" type="button" id="sidebar-toggle" aria-label="Toggle Sidebar">
          <i class="bi bi-list fs-3 text-success"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-success me-2" href="index.php">
          <div class="bg-success text-white rounded-3 p-1.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
            <i class="bi bi-building-fill-gear fs-5"></i>
          </div>
          <span class="fw-extrabold text-success" style="font-size: 1.15rem; letter-spacing: -0.5px;">NMC Portal</span>
        </a>
      </div>
      
      <div class="d-flex align-items-center gap-2">
        <!-- Navigation Links (Desktop) -->
        <div class="d-none d-xl-flex align-items-center gap-2 me-1">
          <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 font-monospace fw-bold">
            <i class="bi bi-house-door-fill me-1"></i>Home 🏠
          </a>
          <a href="polls.php" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1.5 font-monospace fw-bold">
            <i class="bi bi-check2-square me-1"></i>Polls 🗳️
          </a>
          <a href="redeem.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 font-monospace fw-bold">
            <i class="bi bi-gift-fill me-1"></i>Rewards 🎁
          </a>
        </div>

        <!-- Wallet Balance Pill -->
        <a href="redeem.php" class="btn btn-success btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 shadow-sm d-flex align-items-center gap-1.5 text-nowrap">
          <i class="bi bi-wallet2"></i>
          <span>₹<?php echo number_format($user['wallet_balance'] ?? 0, 2); ?></span>
        </a>

        <!-- Language Switcher Dropdown -->
        <div class="dropdown me-1">
          <button class="btn btn-outline-secondary btn-sm rounded-pill px-2.5 py-1.5 font-monospace dropdown-toggle d-flex align-items-center gap-1" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-translate"></i> <span id="current-lang-label">EN</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4" aria-labelledby="langDropdown">
            <li><a class="dropdown-item lang-select-btn fw-semibold text-primary" href="#" data-lang="en">English (EN)</a></li>
            <li><a class="dropdown-item lang-select-btn" href="#" data-lang="hi">हिंदी (HI)</a></li>
            <li><a class="dropdown-item lang-select-btn" href="#" data-lang="mr">मराठी (MR)</a></li>
          </ul>
        </div>

        <button id="theme-toggle" class="btn btn-outline-secondary btn-sm rounded-circle p-2" type="button" aria-label="Toggle Theme">
          <i class="bi bi-sun-fill d-none-theme-light text-warning fs-6"></i>
          <i class="bi bi-moon-stars-fill d-none-theme-dark text-primary fs-6"></i>
        </button>
        
        <?php if ($is_logged_in): ?>
          <div class="dropdown">
            <button class="btn btn-outline-success btn-sm rounded-pill px-3 py-1.5 dropdown-toggle d-flex align-items-center gap-1.5 font-monospace fw-bold" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
              <?php if (!empty($user['profile_pic'])): ?>
                <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="User" class="rounded-circle" style="width: 22px; height: 22px; object-fit: cover;">
              <?php else: ?>
                <i class="bi bi-person-circle fs-6"></i>
              <?php endif; ?>
              <span class="d-none d-md-inline text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars(!empty($user['full_name']) ? $user['full_name'] : $user['email']); ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" aria-labelledby="profileDropdown" style="min-width: 220px;">
              <li class="px-3 py-2 border-bottom mb-1">
                <div class="fw-bold text-dark text-truncate"><?php echo htmlspecialchars(!empty($user['full_name']) ? $user['full_name'] : 'Nagpur Citizen'); ?></div>
                <div class="small text-muted text-truncate font-monospace"><?php echo htmlspecialchars($user['email']); ?></div>
              </li>
              <li><a class="dropdown-item rounded-3 py-2" href="profile.php"><i class="bi bi-person-badge-fill text-success me-2"></i>My Citizen Profile</a></li>
              <li><a class="dropdown-item rounded-3 py-2" href="dashboard.php"><i class="bi bi-grid-1x2-fill text-primary me-2"></i>Civic Complaints Hub</a></li>
              <li><a class="dropdown-item rounded-3 py-2" href="water.php"><i class="bi bi-droplet-fill text-info me-2"></i>Water Supply Grid</a></li>
              <li><a class="dropdown-item rounded-3 py-2" href="polls.php"><i class="bi bi-check2-square text-warning me-2"></i>Community Polls</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item rounded-3 py-2 text-danger fw-bold" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out Account</a></li>
            </ul>
          </div>
        <?php else: ?>
          <a href="login.php" class="btn btn-success btn-sm rounded-pill px-3 py-1.5 font-monospace fw-bold shadow-sm">Sign In</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <div class="dashboard-container">
    <!-- Left Sidebar docked on Desktop, Offcanvas on Mobile -->
    <aside class="app-sidebar" id="dashboard-sidebar">
      <nav class="nav flex-column">
        <a class="sidebar-nav-link <?php echo ($active_tab === 'power') ? 'active' : ''; ?>" href="?tab=power" data-translate-key="power_outages">
          <i class="bi bi-lightning-charge-fill text-warning"></i>Power Schedules
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'water') ? 'active' : ''; ?>" href="?tab=water" data-translate-key="water_supply">
          <i class="bi bi-droplet-fill text-info"></i>Water Supply
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'traffic') ? 'active' : ''; ?>" href="?tab=traffic" data-translate-key="traffic_challan">
          <i class="bi bi-car-front-fill text-danger"></i>Traffic Challan
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'potholes') ? 'active' : ''; ?>" href="?tab=potholes" data-translate-key="potholes_map">
          <i class="bi bi-tools text-primary"></i>Potholes Map
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'waste') ? 'active' : ''; ?>" href="?tab=waste" data-translate-key="garbage_pickup">
          <i class="bi bi-trash3-fill text-success"></i>Waste Pickup
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'aqi') ? 'active' : ''; ?>" href="?tab=aqi" data-translate-key="air_quality">
          <i class="bi bi-wind text-info"></i>Air Quality (AQI)
        </a>
        <a class="sidebar-nav-link" href="barcode.php">
          <i class="bi bi-qr-code-scan text-success"></i>CO2 Scanner & Games
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'eco_tasks') ? 'active' : ''; ?>" href="?tab=eco_tasks">
          <i class="bi bi-calendar-check-fill text-success"></i>Daily Eco Tasks
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'news') ? 'active' : ''; ?>" href="?tab=news" data-translate-key="city_news">
          <i class="bi bi-newspaper text-secondary"></i>City News
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'transport') ? 'active' : ''; ?>" href="?tab=transport" data-translate-key="public_transport">
          <i class="bi bi-bus-front-fill text-success"></i>Public Transport
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'complaints') ? 'active' : ''; ?>" href="?tab=complaints" data-translate-key="grievance_tracking">
          <i class="bi bi-shield-fill-check text-danger"></i>Grievance Log
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'tax') ? 'active' : ''; ?>" href="?tab=tax" data-translate-key="tax_pay_status">
          <i class="bi bi-file-earmark-ruled-fill text-primary"></i>Tax Pay Status
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'wallet') ? 'active' : ''; ?>" href="?tab=wallet" data-translate-key="wallet_ledger">
          <i class="bi bi-wallet2 text-success"></i>My Wallet
        </a>
        
        <div class="border-top mt-4 pt-3 px-3">
          <span class="small text-muted d-block mb-2"><i class="bi bi-geo-alt me-1"></i>Active Ward:</span>
          <span class="badge bg-success w-100 py-2 d-block text-truncate text-white"><?php echo $active_ward_name ? htmlspecialchars($active_ward_name) : 'No Ward Set'; ?></span>
        </div>
        
        <?php if ($is_logged_in): ?>
          <div class="border-top mt-3 pt-3 px-3 d-sm-none">
            <a href="logout.php" class="btn btn-outline-danger btn-sm w-100 py-2"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
          </div>
        <?php endif; ?>
      </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content-area">
      <!-- Feedback Alerts -->
      <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
          <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>
      
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Ward Selection Banner (if null) -->
      <?php if (!$active_ward_id): ?>
        <div class="card bg-light border-start border-4 border-warning mb-4">
          <div class="card-body py-4">
            <div class="row align-items-center">
              <div class="col-md-8">
                <h5 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-warning me-2"></i>Select Your Ward</h5>
                <p class="text-muted mb-md-0">Please set your residential ward to view your electricity schedules, water timetables, and local news posts.</p>
              </div>
              <div class="col-md-4 text-md-end">
                <form action="" method="POST" class="d-flex gap-2">
                  <select name="ward_id" class="form-select" required>
                    <option value="" disabled selected>Choose Ward...</option>
                    <?php foreach ($wards as $w): ?>
                      <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="update_ward" class="btn btn-primary px-3 text-nowrap">Save Ward</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Content Row (Full Page Width) -->
      <div class="row">
        <div class="col-12">
        
        <!-- Tab: Power (Loadshedding) -->
        <?php if ($active_tab === 'power'): ?>
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-lightning-charge text-success me-2"></i>Power Cut Schedule</span>
              <?php if ($active_ward_id): ?>
                <span class="badge bg-success"><?php echo htmlspecialchars($active_ward_name); ?></span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if (!$active_ward_id): ?>
                <p class="text-muted text-center py-4">Please set your ward using the selection banner to view loadshedding timetables.</p>
              <?php elseif (empty($loadshedding_schedule)): ?>
                <div class="text-center py-5">
                  <i class="bi bi-emoji-smile text-success" style="font-size: 3rem;"></i>
                  <p class="text-muted mt-3">No power cuts scheduled today or upcoming for your ward!</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Time Window</th>
                        <th>Reason</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($loadshedding_schedule as $ls): ?>
                        <tr>
                          <td data-label="Date"><strong><?php echo date('d-M-Y', strtotime($ls['date'])); ?></strong></td>
                          <td data-label="Time Window">
                            <span class="badge bg-light text-dark font-monospace">
                              <?php echo date('h:i A', strtotime($ls['start_time'])); ?> - <?php echo date('h:i A', strtotime($ls['end_time'])); ?>
                            </span>
                          </td>
                          <td data-label="Reason" class="text-secondary"><?php echo htmlspecialchars($ls['reason'] ?: 'Scheduled Outage'); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-octagon text-danger me-2"></i>Report Unscheduled Outage</div>
            <div class="card-body">
              <?php if ($is_logged_in): ?>
                <p class="text-muted">Is your power currently out but not listed in the schedule? Report it to help utility providers identify localized grid failures.</p>
                <form action="" method="POST">
                  <button type="submit" name="report_outage" class="btn btn-danger-custom" <?php echo !$active_ward_id ? 'disabled' : ''; ?>>
                    <i class="bi bi-broadcast me-2"></i>Report Outage Now
                  </button>
                  <?php if (!$active_ward_id): ?>
                    <span class="text-danger ms-2 small">Requires ward settings.</span>
                  <?php endif; ?>
                </form>
              <?php else: ?>
                <div class="text-center py-3">
                  <i class="bi bi-lock-fill text-warning fs-2 mb-2"></i>
                  <h6>Sign in to Report Outages</h6>
                  <p class="text-muted small">Only logged-in residents can report grid failures.</p>
                  <a href="login.php?redirect=dashboard.php?tab=power" class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: Water -->
        <?php elseif ($active_tab === 'water'): ?>
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-droplet text-success me-2"></i>Weekly Water Timetable</span>
              <?php if ($active_ward_id): ?>
                <span class="badge bg-success"><?php echo htmlspecialchars($active_ward_name); ?></span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if (!$active_ward_id): ?>
                <p class="text-muted text-center py-4">Please set your ward using the selection banner to view water supply timetables.</p>
              <?php elseif (empty($water_schedule)): ?>
                <div class="text-center py-5">
                  <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                  <p class="text-muted mt-3">No water timetable set for your ward by the administrator.</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Day</th>
                        <th>Area Name</th>
                        <th>Timings</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($water_schedule as $ws): ?>
                        <tr>
                          <td data-label="Day"><strong><?php echo $ws['day_of_week']; ?></strong></td>
                          <td data-label="Area Name"><?php echo htmlspecialchars($ws['area_name']); ?></td>
                          <td data-label="Timings">
                            <span class="badge bg-light text-success font-monospace">
                              <?php echo date('h:i A', strtotime($ws['start_time'])); ?> - <?php echo date('h:i A', strtotime($ws['end_time'])); ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="card">
            <div class="card-header"><i class="bi bi-chat-left-text text-success me-2"></i>Water Supply Issues & Complaints</div>
            <div class="card-body">
              <?php if ($is_logged_in): ?>
                <form action="" method="POST" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label fw-bold">Issue Type</label>
                    <select name="complaint_type" class="form-select" required>
                      <option value="" disabled selected>Select issue...</option>
                      <option value="water_not_received">Water Not Received</option>
                      <option value="low_pressure">Low Water Pressure</option>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-bold">Additional Details</label>
                    <textarea name="details" rows="3" class="form-control" placeholder="Provide details such as time frame, block number, etc. (Optional)"></textarea>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-bold">Optional Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                  </div>
                  <button type="submit" name="water_complaint" class="btn btn-primary" <?php echo !$active_ward_id ? 'disabled' : ''; ?>>
                    Submit Complaint
                  </button>
                </form>
              <?php else: ?>
                <div class="text-center py-4 px-3 border rounded-3 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To submit water complaints and track resolution status, please sign in.</p>
                  <a href="login.php?redirect=dashboard.php?tab=water" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: Traffic (Crowdsourced Traffic Violation & Enforcement Hub) -->
        <?php elseif ($active_tab === 'traffic'): ?>
          
          <!-- Traffic Hero Banner Card (Rich Green Theme) -->
          <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-4 text-white" style="background: linear-gradient(135deg, #059669 0%, #10b981 50%, #047857 100%) !important; box-shadow: 0 12px 30px rgba(5, 150, 105, 0.25) !important;">
            <div class="card-body p-4 p-md-5 position-relative">
              <div class="d-inline-flex align-items-center gap-2 bg-black bg-opacity-30 border border-white border-opacity-20 px-3.5 py-1.5 rounded-pill mb-3 small fw-bold text-warning font-monospace">
                <i class="bi bi-shield-exclamation text-warning fs-6"></i>
                <span>NAGPUR TRAFFIC POLICE CITIZEN ENFORCEMENT</span>
              </div>
              <h2 class="fw-extrabold text-white mb-2" style="letter-spacing: -0.5px;">Traffic Violation & Enforcement Hub 🚦🚗</h2>
              <p class="text-white opacity-90 mb-4" style="max-width: 680px; font-size: 0.98rem;">Report traffic infractions (signal jumping, helmet-less riding, illegal sidewalk driving) with instant GPS stamps. Help enforce road safety and earn <strong class="text-warning">₹50.00</strong> in your wallet upon admin approval!</p>

              <div class="d-flex flex-wrap gap-2">
                <div class="bg-black bg-opacity-25 border border-white border-opacity-15 px-3 py-2 rounded-3 d-flex align-items-center gap-2 font-monospace small">
                  <i class="bi bi-camera-fill text-warning fs-5"></i>
                  <div>
                    <span class="d-block text-white opacity-75" style="font-size: 0.72rem;">REWARD PER REPORT</span>
                    <strong class="text-white">₹50.00 Cash</strong>
                  </div>
                </div>

                <div class="bg-black bg-opacity-25 border border-white border-opacity-15 px-3 py-2 rounded-3 d-flex align-items-center gap-2 font-monospace small">
                  <i class="bi bi-geo-alt-fill text-info fs-5"></i>
                  <div>
                    <span class="d-block text-white opacity-75" style="font-size: 0.72rem;">GPS STAMPING</span>
                    <strong class="text-white">Live Geolocation</strong>
                  </div>
                </div>

                <div class="bg-black bg-opacity-25 border border-white border-opacity-15 px-3 py-2 rounded-3 d-flex align-items-center gap-2 font-monospace small">
                  <i class="bi bi-check2-circle text-warning fs-5"></i>
                  <div>
                    <span class="d-block text-white opacity-75" style="font-size: 0.72rem;">YOUR REPORTS</span>
                    <strong class="text-white"><?php echo count($user_traffic_reports); ?> Filed</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Form Card: File Traffic Violation Report -->
          <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white" style="border: 1px solid #ffe4e6 !important;">
            <div class="card-header border-0 p-4" style="background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%); border-bottom: 1px solid #fecdd3 !important;">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle p-2.5 d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 46px; height: 46px; background: #be123c; color: white;">
                  <i class="bi bi-camera-fill fs-5"></i>
                </div>
                <div>
                  <h5 class="fw-bold mb-0 text-dark">File New Traffic Violation Report 📸</h5>
                  <span class="small text-muted">Upload clear photo evidence of the infraction with automatic GPS tagging</span>
                </div>
              </div>
            </div>

            <div class="card-body p-4">
              <?php if ($is_logged_in): ?>
                <form action="" method="POST" enctype="multipart/form-data" id="traffic-form">
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill text-danger me-1.5"></i>Select Traffic Infraction Type</label>
                    <select name="violation_type" class="form-select form-select-lg rounded-3 border-rose-subtle fs-6" required style="border-color: #fca5a5 !important;">
                      <option value="" disabled selected>Choose infraction type...</option>
                      <option value="Traffic Signal Break">🚦 Traffic Signal Break (Red Light Violation)</option>
                      <option value="No Helmet">🪖 Riding Without Helmet</option>
                      <option value="Triple Seat">🏍️ Triple Riding (Three Persons on Bike)</option>
                      <option value="Driving on Sidewalk">🛣️ Driving on Sidewalk / Wrong Side</option>
                      <option value="Illegal Parking">🅿️ Illegal / No-Parking Obstruction</option>
                      <option value="Over-speeding">🏎️ Over-Speeding & Rash Driving</option>
                      <option value="Other Violation">⚠️ Other Traffic Infraction</option>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-bold text-dark"><i class="bi bi-camera-reels-fill text-danger me-1.5"></i>Photo Capture Mode</label>
                    <div class="btn-group w-100 p-1 bg-light border rounded-pill shadow-inner" role="group" style="border-color: #fda4af !important;">
                      <input type="radio" class="btn-check" name="photo_source" id="source-upload" value="upload" checked autocomplete="off">
                      <label class="btn btn-outline-danger border-0 rounded-pill font-monospace fw-bold py-2" for="source-upload"><i class="bi bi-file-earmark-image me-1.5"></i>Upload Image File</label>

                      <input type="radio" class="btn-check" name="photo_source" id="source-webcam" value="webcam" autocomplete="off">
                      <label class="btn btn-outline-danger border-0 rounded-pill font-monospace fw-bold py-2" for="source-webcam"><i class="bi bi-webcam-fill me-1.5"></i>Live Camera Feed</label>
                    </div>
                  </div>

                  <!-- Upload Option Container -->
                  <div id="upload-photo-container" class="mb-3">
                    <label class="form-label fw-bold text-dark">Select Violation Photo from Device</label>
                    <input type="file" name="photo" id="file-photo-input" class="form-control form-control-lg rounded-3 border-rose-subtle" accept="image/*" capture="environment" required style="border-color: #fca5a5 !important;">
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i>On mobile devices, this directly launches your rear camera lens.</small>
                  </div>

                  <!-- Webcam Capture Container -->
                  <div id="webcam-photo-container" class="mb-3 d-none">
                    <label class="form-label fw-bold text-dark">Take Real-Time Live Photo</label>
                    <div class="position-relative bg-dark rounded-4 overflow-hidden border text-center mb-2 shadow-sm" style="max-width: 480px; margin: 0 auto; height: 320px; display: flex; align-items: center; justify-content: center;">
                      <video id="webcam-video" autoplay playsinline class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;"></video>
                      <canvas id="webcam-canvas" class="d-none" width="640" height="480"></canvas>
                      <img id="webcam-preview-img" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;">
                      <div id="webcam-placeholder" class="text-center p-4 text-white">
                        <i class="bi bi-camera fs-1 text-danger d-block mb-2"></i>
                        <span>Click "Start Camera" below to activate live video stream</span>
                      </div>
                    </div>
                    
                    <div class="text-center d-flex justify-content-center gap-2">
                      <button type="button" id="btn-start-webcam" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1.5 fw-bold"><i class="bi bi-power me-1"></i>Start Camera</button>
                      <button type="button" id="btn-capture-webcam" class="btn btn-sm btn-danger rounded-pill px-3 py-1.5 fw-bold d-none"><i class="bi bi-camera-fill me-1"></i>Capture Snapshot</button>
                      <button type="button" id="btn-reset-webcam" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fw-bold d-none"><i class="bi bi-arrow-clockwise me-1"></i>Retake</button>
                    </div>
                    <input type="hidden" name="webcam_photo" id="webcam-data-input">
                  </div>
                  
                  <!-- GPS Stamp Box -->
                  <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                      <label class="form-label fw-bold text-dark">Latitude Coordinates</label>
                      <input type="text" name="latitude" id="traffic-lat" class="form-control bg-light font-monospace fw-bold text-dark rounded-3" placeholder="Retrieving..." readonly required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold text-dark">Longitude Coordinates</label>
                      <input type="text" name="longitude" id="traffic-lng" class="form-control bg-light font-monospace fw-bold text-dark rounded-3" placeholder="Retrieving..." readonly required>
                    </div>
                  </div>
                  
                  <div id="traffic-gps-status" class="alert alert-rose border p-3 rounded-4 small mb-4 d-flex align-items-center gap-2" style="background-color: #fff1f2; border-color: #fecdd3 !important; color: #be123c;">
                    <i class="bi bi-geo-fill fs-5 text-danger spinner-border spinner-border-sm me-1" id="gps-spinner"></i>
                    <span id="gps-status-text" class="fw-semibold">Attempting to acquire precise GPS coordinates via browser Geolocation...</span>
                  </div>
                  
                  <button type="submit" name="traffic_report" class="btn btn-danger btn-lg w-100 rounded-pill font-monospace fw-extrabold py-3 shadow-lg" id="traffic-submit-btn" disabled style="background: linear-gradient(135deg, #e11d48 0%, #be123c 50%, #881337 100%); border: none; box-shadow: 0 10px 25px rgba(190, 18, 60, 0.4) !important;">
                    <i class="bi bi-send-fill me-2"></i>Submit Traffic Violation & Earn ₹50.00
                  </button>
                </form>
              <?php else: ?>
                <div class="text-center py-5 px-3 border rounded-4 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-danger display-3 mb-3 d-block"></i>
                  <h4 class="fw-bold text-dark mb-2">Sign In Required to File Reports</h4>
                  <p class="text-muted small mb-4" style="max-width: 440px; margin: 0 auto;">Sign in with your citizen account to attach live GPS timestamps and claim ₹50.00 cash rewards for verified traffic reports.</p>
                  <a href="login.php?redirect=dashboard.php?tab=traffic" class="btn btn-danger btn-lg rounded-pill px-5 fw-bold shadow"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In Now</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- History Log Card -->
          <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
            <div class="card-header bg-white border-0 p-4 d-flex justify-content-between align-items-center border-bottom">
              <div>
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-danger me-2"></i>Your Submitted Traffic Reports</h5>
                <span class="small text-muted">Track approval status and wallet credits for your submissions</span>
              </div>
              <span class="badge bg-danger bg-opacity-10 text-danger font-monospace px-3 py-2 rounded-pill fw-bold border border-danger-subtle"><?php echo count($user_traffic_reports); ?> Reports Filed</span>
            </div>

            <div class="card-body p-4">
              <?php if (empty($user_traffic_reports)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="bi bi-shield-check display-3 text-secondary mb-3 d-block"></i>
                  <p class="mb-0 fw-semibold">No traffic violations reported by you yet.</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead class="table-light">
                      <tr>
                        <th>Infraction Photo</th>
                        <th>Violation Type</th>
                        <th>GPS Location</th>
                        <th>Timestamp</th>
                        <th>Status</th>
                        <th>Reward Credited</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($user_traffic_reports as $tr): ?>
                        <tr>
                          <td data-label="Infraction Photo">
                            <a href="<?php echo htmlspecialchars($tr['photo_path']); ?>" target="_blank">
                              <img src="<?php echo htmlspecialchars($tr['photo_path']); ?>" class="rounded-3 border shadow-sm" style="width: 64px; height: 64px; object-fit: cover;">
                            </a>
                          </td>
                          <td data-label="Violation Type">
                            <span class="badge rounded-pill text-danger bg-danger bg-opacity-10 border border-danger-subtle font-monospace px-3 py-1.5 fw-bold">
                              <?php echo htmlspecialchars($tr['violation_type'] ?? 'General Infraction'); ?>
                            </span>
                          </td>
                          <td data-label="GPS Location" class="small">
                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $tr['latitude']; ?>,<?php echo $tr['longitude']; ?>" target="_blank" class="fw-semibold text-danger text-decoration-none">
                              <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo number_format($tr['latitude'], 4); ?>, <?php echo number_format($tr['longitude'], 4); ?> ↗
                            </a>
                          </td>
                          <td data-label="Timestamp" class="small font-monospace text-muted"><?php echo date('d M Y, h:i A', strtotime($tr['timestamp'])); ?></td>
                          <td data-label="Status">
                            <?php if ($tr['status'] === 'Pending'): ?>
                              <span class="badge bg-warning text-dark font-monospace px-3 py-1.5 rounded-pill shadow-sm"><i class="bi bi-hourglass-split me-1"></i>Pending Review</span>
                            <?php elseif ($tr['status'] === 'Approved'): ?>
                              <span class="badge bg-success text-white font-monospace px-3 py-1.5 rounded-pill shadow-sm"><i class="bi bi-check-circle-fill me-1"></i>Approved & Paid</span>
                            <?php else: ?>
                              <span class="badge bg-danger text-white font-monospace px-3 py-1.5 rounded-pill shadow-sm"><i class="bi bi-x-circle-fill me-1"></i>Rejected</span>
                            <?php endif; ?>
                          </td>
                          <td data-label="Reward Credited" class="fw-extrabold font-monospace text-success fs-6">
                            <?php echo $tr['reward_credited'] ? '+₹50.00' : '--'; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: Potholes -->
        <?php elseif ($active_tab === 'potholes'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-triangle text-success me-2"></i>Report Road Damage / Potholes</div>
            <div class="card-body">
              <?php if ($is_logged_in): ?>
                <form action="" method="POST" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label fw-bold">Select Photo Source</label>
                    <div class="d-flex gap-3">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="pothole_photo_source" id="pothole-source-upload" value="upload" checked>
                        <label class="form-check-label" for="pothole-source-upload">
                          Upload Image File
                        </label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="pothole_photo_source" id="pothole-source-webcam" value="webcam">
                        <label class="form-check-label" for="pothole-source-webcam">
                          Use Real-time Camera
                        </label>
                      </div>
                    </div>
                  </div>

                  <!-- Upload Photo Container -->
                  <div id="pothole-upload-photo-container" class="mb-3">
                    <label class="form-label fw-bold">Damage Photo</label>
                    <input type="file" name="photo" id="pothole-file-photo-input" class="form-control" accept="image/*" required>
                  </div>

                  <!-- Webcam Capture Container -->
                  <div id="pothole-webcam-photo-container" class="mb-3 d-none">
                    <label class="form-label fw-bold">Webcam Capture</label>
                    <div class="position-relative bg-light rounded-4 overflow-hidden border text-center mb-2" style="max-width: 480px; margin: 0 auto; height: 320px; display: flex; align-items: center; justify-content: center;">
                      <video id="pothole-webcam-video" autoplay playsinline class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;"></video>
                      <canvas id="pothole-webcam-canvas" class="d-none" width="640" height="480"></canvas>
                      <img id="pothole-webcam-preview-img" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;">
                      <div id="pothole-webcam-placeholder" class="text-center p-3 text-secondary">
                        <i class="bi bi-camera fs-1 d-block mb-2"></i>
                        <span>Click "Start Camera" below to activate webcam feed</span>
                      </div>
                    </div>
                    
                    <div class="text-center d-flex justify-content-center gap-2">
                      <button type="button" id="pothole-btn-start-webcam" class="btn btn-sm btn-outline-success"><i class="bi bi-power me-1"></i>Start Camera</button>
                      <button type="button" id="pothole-btn-capture-webcam" class="btn btn-sm btn-success d-none"><i class="bi bi-camera-fill me-1"></i>Capture Snapshot</button>
                      <button type="button" id="pothole-btn-reset-webcam" class="btn btn-sm btn-outline-danger d-none"><i class="bi bi-arrow-clockwise me-1"></i>Retake</button>
                    </div>
                    <input type="hidden" name="webcam_photo" id="pothole-webcam-data-input">
                  </div>
                  
                  <div class="row mb-3">
                    <div class="col-md-6 mb-2">
                      <label class="form-label fw-bold">Latitude</label>
                      <input type="text" name="latitude" id="pothole-lat" class="form-control bg-light font-monospace" placeholder="Retrieving..." readonly required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold">Longitude</label>
                      <input type="text" name="longitude" id="pothole-lng" class="form-control bg-light font-monospace" placeholder="Retrieving..." readonly required>
                    </div>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Description of Damage</label>
                    <textarea name="description" rows="3" class="form-control" placeholder="E.g., Deep pothole in left lane, severe cracks blocking traffic..." required></textarea>
                  </div>
                  
                  <div id="pothole-gps-status" class="alert alert-secondary py-2 small mb-3">
                    <span>Getting location...</span>
                  </div>
                  
                  <button type="submit" name="pothole_report" class="btn btn-primary" id="pothole-submit-btn" disabled>
                    Submit Road Report
                  </button>
                </form>
              <?php else: ?>
                <div class="text-center py-4 px-3 border rounded-3 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To report road damage or track potholes on the public map, please log in.</p>
                  <a href="login.php?redirect=dashboard.php?tab=potholes" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Leaflet Map View of All Potholes -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-map-fill text-success me-2"></i>Active Potholes Map (City-wide)</span>
              <span class="badge bg-secondary"><?php echo count($all_open_potholes); ?> Open Reports</span>
            </div>
            <div class="card-body p-0">
              <div id="pothole-map"></div>
            </div>
          </div>

          <!-- Pothole Progress timeline logs -->
          <?php if ($is_logged_in): ?>
            <div class="card">
              <div class="card-header"><i class="bi bi-truck text-success me-2"></i>Your Pothole Reports Tracking</div>
              <div class="card-body">
                <?php if (empty($user_pothole_reports)): ?>
                  <p class="text-muted text-center py-3">No pothole reports filed by you yet.</p>
                <?php else: ?>
                  <?php foreach ($user_pothole_reports as $pr): 
                    // Status steps logic
                    $step = 1;
                    if ($pr['status'] === 'Acknowledged') $step = 2;
                    elseif ($pr['status'] === 'In Progress') $step = 3;
                    elseif ($pr['status'] === 'Resolved') $step = 4;
                  ?>
                    <div class="border rounded p-3 mb-3 shadow-sm bg-white">
                      <div class="row align-items-center">
                        <div class="col-md-3 text-center mb-3 mb-md-0">
                          <img src="<?php echo htmlspecialchars($pr['photo_path']); ?>" class="img-thumbnail rounded" style="max-height: 100px; object-fit: cover;">
                           <span class="d-block mt-2">
                             <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $pr['latitude']; ?>,<?php echo $pr['longitude']; ?>" target="_blank" class="map-coordinates-link">
                               <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo $pr['latitude']; ?>, <?php echo $pr['longitude']; ?>
                             </a>
                           </span>
                        </div>
                        <div class="col-md-9">
                          <p class="mb-2 fw-semibold">Description: <span class="fw-normal text-secondary"><?php echo htmlspecialchars($pr['description']); ?></span></p>
                          
                          <!-- Timeline Step Indicator -->
                          <div class="timeline-steps">
                            <div class="timeline-step <?php echo ($step >= 1) ? 'active' : ''; ?> <?php echo ($step > 1) ? 'completed' : ''; ?>">
                              <span class="step-circle">1</span>
                              <span class="step-label">Reported</span>
                            </div>
                            <div class="timeline-step <?php echo ($step >= 2) ? 'active' : ''; ?> <?php echo ($step > 2) ? 'completed' : ''; ?>">
                              <span class="step-circle">2</span>
                              <span class="step-label">Acknowledged</span>
                            </div>
                            <div class="timeline-step <?php echo ($step >= 3) ? 'active' : ''; ?> <?php echo ($step > 3) ? 'completed' : ''; ?>">
                              <span class="step-circle">3</span>
                              <span class="step-label">In Progress</span>
                            </div>
                            <div class="timeline-step <?php echo ($step >= 4) ? 'active' : ''; ?>">
                              <span class="step-circle">4</span>
                              <span class="step-label">Resolved</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Community Pothole Verification & Voting Poll -->
          <div class="card mt-4 border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-success text-white py-3 px-4 d-flex justify-content-between align-items-center">
              <div>
                <h5 class="fw-bold mb-0"><i class="bi bi-hand-thumbs-up-fill me-2"></i>Community Pothole Verification Polls</h5>
                <span class="small opacity-75">Vote to verify reported potholes so Municipal Authorities prioritize genuine repairs!</span>
              </div>
              <span class="badge bg-white text-success font-monospace px-3 py-2 fs-7"><?php echo count($all_open_potholes); ?> Open Polls</span>
            </div>

            <div class="card-body p-3 p-md-4 bg-light">
              <?php if (empty($all_open_potholes)): ?>
                <div class="text-center py-4 text-muted">
                  <i class="bi bi-check-circle-fill text-success fs-1 mb-2"></i>
                  <p class="mb-0">No active pothole polls currently open.</p>
                </div>
              <?php else: ?>
                <div class="row g-3">
                  <?php foreach ($all_open_potholes as $ph): 
                    $up = intval($ph['upvotes']);
                    $down = intval($ph['downvotes']);
                    $net = $up - $down;
                    $my_v = $ph['my_vote'];
                  ?>
                    <div class="col-md-6">
                      <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden bg-white d-flex flex-column justify-content-between">
                        <div class="position-relative">
                          <img src="<?php echo htmlspecialchars($ph['photo_path']); ?>" class="card-img-top w-100" style="height: 190px; object-fit: cover;" alt="Pothole Photo">
                          
                          <!-- Direct Google Maps GPS Link Badge -->
                          <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 backdrop-blur text-white text-decoration-none px-2.5 py-2 rounded-pill shadow-sm" title="Open GPS Location in Google Maps">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo number_format($ph['latitude'], 4); ?>, <?php echo number_format($ph['longitude'], 4); ?>
                            <i class="bi bi-box-arrow-up-right ms-1 small text-warning"></i>
                          </a>
                          
                          <?php if ($net >= 3): ?>
                            <span class="position-absolute top-0 start-0 m-2 badge bg-success text-white px-3 py-2 rounded-pill shadow-sm">
                              <i class="bi bi-patch-check-fill me-1"></i>Verified Genuine (+<?php echo $net; ?>)
                            </span>
                          <?php elseif ($net < 0): ?>
                            <span class="position-absolute top-0 start-0 m-2 badge bg-warning text-dark px-3 py-2 rounded-pill shadow-sm">
                              <i class="bi bi-exclamation-triangle-fill me-1"></i>Flagged Inaccurate
                            </span>
                          <?php else: ?>
                            <span class="position-absolute top-0 start-0 m-2 badge bg-secondary text-white px-3 py-2 rounded-pill shadow-sm">
                              <i class="bi bi-hourglass-split me-1"></i>Pending (+<?php echo $net; ?>)
                            </span>
                          <?php endif; ?>
                        </div>

                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                          <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <span class="small text-secondary fw-semibold"><i class="bi bi-person-circle me-1 text-success"></i><?php echo htmlspecialchars($ph['reporter_email'] ? mask_email($ph['reporter_email']) : 'Citizen'); ?></span>
                              <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill px-3 py-1"><?php echo htmlspecialchars($ph['status']); ?></span>
                            </div>
                            <p class="card-text small text-dark fw-bold mb-2 fs-6" style="line-height: 1.4; color: #1e293b;"><?php echo htmlspecialchars($ph['description']); ?></p>
                            <div class="d-flex justify-content-between align-items-center mb-2 text-muted small">
                              <span><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($ph['created_at'])); ?></span>
                            </div>

                            <!-- Direct Google Maps Navigation Button -->
                            <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="btn btn-sm btn-outline-danger w-100 rounded-pill mt-1 d-flex align-items-center justify-content-center gap-2 fw-semibold py-2">
                              <i class="bi bi-map-fill text-danger"></i>
                              <span>Open Location in Google Maps</span>
                              <i class="bi bi-box-arrow-up-right small"></i>
                            </a>
                          </div>

                          <!-- Thumbs Up / Thumbs Down Voting Poll Form -->
                          <div class="pt-3 border-top mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span class="small fw-bold text-secondary">Is this genuine?</span>
                            
                            <?php if ($is_logged_in): ?>
                              <div class="d-flex gap-2">
                                <form action="" method="POST" class="d-inline">
                                  <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                                  <input type="hidden" name="vote_type" value="upvote">
                                  <button type="submit" name="vote_pothole" class="btn btn-sm <?php echo ($my_v === 'upvote') ? 'btn-success' : 'btn-outline-success'; ?> rounded-pill px-3 py-1.5 d-flex align-items-center gap-1.5 fw-bold">
                                    <i class="bi bi-hand-thumbs-up-fill"></i>
                                    <span>Thumbs Up</span>
                                    <span class="badge <?php echo ($my_v === 'upvote') ? 'bg-white text-success' : 'bg-success text-white'; ?> rounded-pill ms-1 px-2"><?php echo $up; ?></span>
                                  </button>
                                </form>

                                <form action="" method="POST" class="d-inline">
                                  <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                                  <input type="hidden" name="vote_type" value="downvote">
                                  <button type="submit" name="vote_pothole" class="btn btn-sm <?php echo ($my_v === 'downvote') ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill px-3 py-1.5 d-flex align-items-center gap-1.5 fw-bold">
                                    <i class="bi bi-hand-thumbs-down-fill"></i>
                                    <span>Thumbs Down</span>
                                    <span class="badge <?php echo ($my_v === 'downvote') ? 'bg-white text-danger' : 'bg-danger text-white'; ?> rounded-pill ms-1 px-2"><?php echo $down; ?></span>
                                  </button>
                                </form>
                              </div>
                            <?php else: ?>
                              <a href="login.php?redirect=dashboard.php?tab=potholes" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 small font-weight-bold">Sign in to Vote</a>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: Waste -->
        <?php elseif ($active_tab === 'waste'): ?>
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <span><i class="bi bi-trash3 text-success me-2"></i>Waste Collection Schedule</span>
              <?php if ($active_ward_id): ?>
                <span class="badge bg-success"><?php echo htmlspecialchars($active_ward_name); ?></span>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if (!$active_ward_id): ?>
                <p class="text-muted text-center py-4">Please set your ward using the selection banner to view waste schedules.</p>
              <?php elseif (empty($waste_schedule)): ?>
                <div class="text-center py-5">
                  <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                  <p class="text-muted mt-3">No waste collection schedules loaded for your ward.</p>
                </div>
              <?php else: ?>
                <!-- Reminder Alert Card -->
                <div class="alert alert-success d-flex align-items-center mb-4 py-3">
                  <i class="bi bi-bell-fill text-success fs-4 me-3"></i>
                  <div>
                    <h6 class="alert-heading fw-bold mb-1">Garbage Collection Reminder</h6>
                    <p class="mb-0 small text-secondary">Please place your sorted garbage bins outside by the designated schedules listed below.</p>
                  </div>
                </div>
                
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Day of Week</th>
                        <th>Collection Time</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($waste_schedule as $ws): ?>
                        <tr>
                          <td><strong><?php echo $ws['day_of_week']; ?></strong></td>
                          <td>
                            <span class="badge bg-light text-dark font-monospace fs-6 px-3 py-2 border">
                              <i class="bi bi-clock me-1 text-success"></i>
                              <?php echo date('h:i A', strtotime($ws['collection_time'])); ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Report missed pickup / illegal dumping</div>
            <div class="card-body">
              <?php if ($is_logged_in): ?>
                <form action="" method="POST" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label fw-bold">Report Type</label>
                    <select name="complaint_type" class="form-select" required>
                      <option value="" disabled selected>Choose report type...</option>
                      <option value="missed_pickup">Missed Garbage Pickup</option>
                      <option value="illegal_dumping">Illegal Waste Dumping</option>
                    </select>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Details</label>
                    <textarea name="details" rows="3" class="form-control" placeholder="E.g., truck bypassed sector 4, garbage piling up near primary school..."></textarea>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Photo Proof (Optional)</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                  </div>
                  
                  <button type="submit" name="waste_complaint" class="btn btn-primary" <?php echo !$active_ward_id ? 'disabled' : ''; ?>>
                    Submit Waste Complaint
                  </button>
                </form>
              <?php else: ?>
                <div class="text-center py-4 px-3 border rounded-3 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To submit missed pickups or dumping complaints and upload photo proof, please log in.</p>
                  <a href="login.php?redirect=dashboard.php?tab=waste" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: AQI -->
        <?php elseif ($active_tab === 'aqi'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-wind text-success me-2"></i>Air Quality Index & CO2 Level Dashboard</div>
            <div class="card-body">
              <p class="text-muted">Explore real-time air quality metrics and carbon dioxide levels reported across city wards.</p>
              
              <?php if (empty($aqi_readings)): ?>
                <p class="text-muted text-center py-5">No AQI data is logged currently in the database.</p>
              <?php else: ?>
                <!-- Badges Legend -->
                <div class="d-flex flex-wrap gap-2 mb-4 p-3 rounded bg-light border">
                  <span class="badge bg-aqi-good px-3 py-2">Good (0-50)</span>
                  <span class="badge bg-aqi-moderate px-3 py-2">Moderate (51-100)</span>
                  <span class="badge bg-aqi-poor px-3 py-2">Poor (101+)</span>
                </div>
                
                <div class="row">
                  <?php foreach ($aqi_readings as $idx => $r): 
                    $badge_class = 'bg-aqi-good';
                    $status_text = 'Good';
                    if ($r['aqi_value'] > 100) {
                      $badge_class = 'bg-aqi-poor';
                      $status_text = 'Poor';
                    } elseif ($r['aqi_value'] > 50) {
                      $badge_class = 'bg-aqi-moderate';
                      $status_text = 'Moderate';
                    }
                  ?>
                    <div class="col-md-6 mb-4">
                      <div class="border rounded p-3 bg-white h-100 shadow-sm">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                          <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($r['ward_name']); ?></h6>
                          <span class="badge <?php echo $badge_class; ?> px-2 py-1"><?php echo $status_text; ?> (<?php echo $r['aqi_value']; ?> AQI)</span>
                        </div>
                        
                        <div class="chart-container" style="position: relative; height: 180px;">
                          <canvas id="aqiChart-<?php echo $idx; ?>"></canvas>
                        </div>
                        
                        <div class="mt-3 small text-muted d-flex justify-content-between">
                          <span><i class="bi bi-clouds-fill me-1"></i>CO2: <strong><?php echo $r['co2_value'] ?: '--'; ?> ppm</strong></span>
                          <span>Last log: <?php echo date('d M H:i', strtotime($r['recorded_at'])); ?></span>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: News (Authentic Newspaper Gazette Layout) -->
        <?php elseif ($active_tab === 'news'): ?>
          <div class="newspaper-container mb-4">
            
            <!-- Newspaper Masthead Header -->
            <div class="newspaper-masthead">
              <span class="d-block text-uppercase tracking-wider small fw-bold text-muted mb-1 font-monospace">Official Municipal Gazette & Community Press</span>
              <h1 class="newspaper-title">The Nagpur Chronicle 🗞️</h1>
              <p class="newspaper-tagline mb-0">"The Truth of Nagpur Mahanagar • Published Daily for All Citizens"</p>
            </div>

            <!-- Newspaper Meta Bar -->
            <div class="newspaper-meta-bar">
              <div><span>VOL. LXXV NO. 248</span></div>
              <div><span>NAGPUR, <?php echo strtoupper(date('l, d F Y')); ?></span></div>
              <div><span>CITY EDITION • COMPLIMENTARY GAZETTE</span></div>
            </div>

            <!-- Newspaper Press Category Filter Bar -->
            <div class="p-3 mb-4 rounded border" style="background: rgba(231, 229, 228, 0.4); border-color: #d6d3d1 !important;">
              <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="news">
                <div class="col-md-6">
                  <label class="form-label mb-1 small fw-bold font-monospace text-uppercase text-dark"><i class="bi bi-newspaper me-1"></i>Editorial Section</label>
                  <select name="news_category" class="form-select font-monospace rounded-3" onchange="this.form.submit()">
                    <option value="All" <?php echo ($news_cat === 'All') ? 'selected' : ''; ?>>📰 All Press Dispatches</option>
                    <option value="General" <?php echo ($news_cat === 'General') ? 'selected' : ''; ?>>🏛️ General Municipal Bulletins</option>
                    <option value="Outages" <?php echo ($news_cat === 'Outages') ? 'selected' : ''; ?>>⚡ Grid Maintenance & Outages</option>
                    <option value="Alerts" <?php echo ($news_cat === 'Alerts') ? 'selected' : ''; ?>>🚨 Public Safety & Security Alerts</option>
                  </select>
                </div>
                
                <div class="col-md-6">
                  <label class="form-label mb-1 small fw-bold font-monospace text-uppercase text-dark"><i class="bi bi-geo-alt-fill me-1 text-danger"></i>Ward Locality Filter</label>
                  <select name="news_ward" class="form-select font-monospace rounded-3" onchange="this.form.submit()">
                    <option value="All" <?php echo ($news_ward === 'All') ? 'selected' : ''; ?>>🌐 City-wide Front Page</option>
                    <option value="MyWard" <?php echo ($news_ward === 'MyWard') ? 'selected' : ''; ?>>📍 My Ward Zone Dispatch</option>
                  </select>
                </div>
              </form>
            </div>

            <!-- Newspaper Articles Feed -->
            <?php if (empty($news_posts)): ?>
              <div class="text-center py-5 border-top border-bottom border-secondary">
                <i class="bi bi-newspaper display-3 text-muted mb-3 d-block"></i>
                <h5 class="fw-bold font-monospace text-dark mb-1">No Newspaper Articles Found</h5>
                <p class="text-muted small">No editorial dispatches match your selected category filter.</p>
              </div>
            <?php else: ?>
              <div class="newspaper-feed">
                <?php foreach ($news_posts as $idx => $post): ?>
                  <article class="newspaper-article">
                    <div class="newspaper-byline d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <div>
                        <span>BY CIVIC PRESS DESK</span> • 
                        <span><i class="bi bi-clock me-1"></i><?php echo date('F d, Y • h:i A', strtotime($post['created_at'])); ?></span>
                        <?php if ($post['ward_name']): ?>
                          • <span class="text-danger fw-bold"><i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars($post['ward_name']); ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-dark text-white rounded-0 px-2 py-1 font-monospace small"><?php echo strtoupper(htmlspecialchars($post['category'])); ?></span>
                        <?php if ($post['is_emergency']): ?>
                          <span class="badge bg-danger text-white rounded-0 px-2.5 py-1 font-monospace fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>URGENT BULLETIN</span>
                        <?php endif; ?>
                      </div>
                    </div>

                    <h2 class="newspaper-headline"><?php echo htmlspecialchars($post['title']); ?></h2>

                    <?php if ($post['image_path']): ?>
                      <div class="newspaper-img-frame my-3">
                        <img src="<?php echo htmlspecialchars($post['image_path']); ?>" class="w-100" style="max-height: 380px; object-fit: cover;" alt="Press Illustration">
                        <div class="newspaper-caption">FIG <?php echo ($idx + 1); ?>.1 — Official Press Photographic Evidence released by Nagpur Municipal Corporation.</div>
                      </div>
                    <?php endif; ?>

                    <div class="newspaper-columns newspaper-dropcap">
                      <p class="mb-0" style="white-space: pre-line;"><?php echo htmlspecialchars($post['content']); ?></p>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Newspaper Footer Sign-off -->
            <div class="border-top pt-3 mt-4 text-center font-monospace small text-muted">
              *** END OF DAILY GAZETTE EDITION • NAGPUR MAHANAGAR PALIKA PRESS BUREAU ***
            </div>
          </div>

        <!-- Tab: Wallet -->
        <?php elseif ($active_tab === 'wallet'): ?>
          <?php if ($is_logged_in): ?>
            <div class="card wallet-card">
              <div class="card-body p-4 text-center">
                <i class="bi bi-wallet2 fs-1 mb-2"></i>
                <h5 class="fw-bold mb-1">Citizen Reward Wallet</h5>
                <h1 class="display-5 fw-bold font-monospace"><?php echo format_currency($user['wallet_balance']); ?></h1>
                <p class="mb-2 small text-light opacity-75">Submit traffic violations & redeem Eco Points to earn approved cash payouts credited here.</p>
                <a href="redeem.php" class="btn btn-light text-success fw-bold rounded-pill px-4 py-2 mt-2 shadow-sm">
                  <i class="bi bi-gift-fill text-success me-1"></i>Open Dedicated Redeem Marketplace 🎁
                </a>
              </div>
            </div>
            
            <div class="card">
              <div class="card-header"><i class="bi bi-list-stars text-success me-2"></i>Transaction Ledger</div>
              <div class="card-body">
                <?php if (empty($wallet_transactions)): ?>
                  <p class="text-muted text-center py-3">No reward transactions recorded yet.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle">
                      <thead>
                        <tr>
                          <th>Date & Time</th>
                          <th>Description</th>
                          <th>Type</th>
                          <th>Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($wallet_transactions as $t): ?>
                          <tr>
                            <td class="small"><?php echo date('d-M-Y H:i', strtotime($t['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($t['description']); ?></td>
                            <td>
                              <?php if ($t['transaction_type'] === 'credit'): ?>
                                <span class="badge badge-approved">Credit</span>
                              <?php else: ?>
                                <span class="badge badge-info">Debit</span>
                              <?php endif; ?>
                            </td>
                            <td class="font-monospace fw-bold <?php echo ($t['transaction_type'] === 'credit') ? 'text-success' : 'text-dark'; ?>">
                              <?php echo ($t['transaction_type'] === 'credit') ? '+' : '-'; ?><?php echo format_currency($t['amount']); ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="card text-center py-5 px-3">
              <div class="card-body">
                <i class="bi bi-wallet2 text-success" style="font-size: 3rem;"></i>
                <h4 class="fw-bold mt-3">Citizen Reward Wallet</h4>
                <p class="text-muted">Sign in to check your active balance, view rewards ledger, and track pending challan payouts.</p>
                <a href="login.php?redirect=dashboard.php?tab=wallet" class="btn btn-primary px-4 mt-2"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In Now</a>
              </div>
            </div>
          <?php endif; ?>
        
        <!-- Tab: Public Transport (NMC Aapli Bus & Maha Metro Real-Time Tracker) -->
        <?php elseif ($active_tab === 'transport'): ?>
          <div class="p-4 rounded-4 text-white mb-4 shadow-sm" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 50%, #0f172a 100%);">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
              <div>
                <span class="badge bg-white bg-opacity-20 text-white font-monospace mb-2 px-3 py-1"><i class="bi bi-bus-front-fill me-1 text-warning"></i>LIVE TELEMATICS FLEET GRID</span>
                <h3 class="fw-extrabold text-white mb-1">NMC Aapli Bus & Maha Metro Real-Time Grid 🚌🚇</h3>
                <p class="text-white opacity-90 mb-0 small">Live GPS tracking for 180+ city buses, EV shuttles & metro lines.</p>
              </div>
              <a href="transit.php" class="btn btn-warning font-monospace fw-bold px-4 py-2.5 rounded-pill shadow-sm text-dark text-nowrap">
                <i class="bi bi-geo-alt-fill me-1"></i>Open Full GPS Map & Booking 🚀
              </a>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="border rounded-4 p-3.5 bg-white shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-success-subtle text-success font-monospace px-2.5 py-1 rounded-pill fw-bold">⚡ 100% Electric EV</span>
                  <span class="badge bg-light text-primary border font-monospace fs-6 px-3 py-1"><i class="bi bi-clock-fill text-primary me-1"></i>2 mins</span>
                </div>
                <h6 class="fw-bold text-dark mb-1">Route 1A • Sitabuldi ↔ Hingna Depot</h6>
                <span class="small text-muted d-block"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Next Stop: <strong>Dhantoli Zone 4 Stop</strong></span>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded-4 p-3.5 bg-white shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-info-subtle text-info font-monospace px-2.5 py-1 rounded-pill fw-bold">🚇 Metro Orange Line</span>
                  <span class="badge bg-light text-primary border font-monospace fs-6 px-3 py-1"><i class="bi bi-clock-fill text-primary me-1"></i>4 mins</span>
                </div>
                <h6 class="fw-bold text-dark mb-1">Orange Line • Automotive Square ↔ Khapri</h6>
                <span class="small text-muted d-block"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Next Stop: <strong>Sitabuldi Interchange Station</strong></span>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded-4 p-3.5 bg-white shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-warning-subtle text-warning font-monospace px-2.5 py-1 rounded-pill fw-bold">🚌 CNG Green Fleet</span>
                  <span class="badge bg-light text-warning border font-monospace fs-6 px-3 py-1"><i class="bi bi-clock-fill text-warning me-1"></i>7 mins</span>
                </div>
                <h6 class="fw-bold text-dark mb-1">Route 5 • Dharampeth ↔ Kamptee Road</h6>
                <span class="small text-muted d-block"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Next Stop: <strong>Variety Square</strong></span>
              </div>
            </div>

            <div class="col-md-6">
              <div class="border rounded-4 p-3.5 bg-white shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge bg-success-subtle text-success font-monospace px-2.5 py-1 rounded-pill fw-bold">✈️ Airport Express</span>
                  <span class="badge bg-light text-primary border font-monospace fs-6 px-3 py-1"><i class="bi bi-clock-fill text-primary me-1"></i>11 mins</span>
                </div>
                <h6 class="fw-bold text-dark mb-1">Route 12 • Airport Express ↔ Mihan IT Park</h6>
                <span class="small text-muted d-block"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Next Stop: <strong>Airport Terminal 1</strong></span>
              </div>
            </div>
          </div>

        <!-- Tab: Grievances Log -->
        <?php elseif ($active_tab === 'complaints'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-shield-fill-check text-success me-2"></i>File a Municipal Grievance</div>
            <div class="card-body">
              <?php if ($is_logged_in): ?>
                <form action="" method="POST" enctype="multipart/form-data">
                  <div class="mb-3">
                    <label class="form-label fw-bold">Grievance Category</label>
                    <select name="complaint_type" class="form-select" required>
                      <option value="" disabled selected>Select category...</option>
                      <option value="Street Light Outage">Street Light Outage</option>
                      <option value="Sewage Overflow">Sewage Overflow</option>
                      <option value="Public Park Maintenance">Public Park Maintenance</option>
                      <option value="Encroachment">Encroachment / Illegal Construction</option>
                      <option value="General Drainage">Drainage Blockage</option>
                    </select>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Select Nagpur Municipal Zone</label>
                    <select name="ward_id" class="form-select" required>
                      <option value="" disabled selected>Select zone...</option>
                      <?php foreach ($wards as $w): ?>
                        <option value="<?php echo $w['id']; ?>" <?php echo ($active_ward_id == $w['id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($w['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Grievance Details</label>
                    <textarea name="details" rows="3" class="form-control" placeholder="E.g., The street light opposite house 12 has been broken for 3 days..."></textarea>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-bold">Photo Proof (Optional)</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                  </div>
                  
                  <button type="submit" name="submit_grievance" class="btn btn-primary">
                    Submit Grievance Report
                  </button>
                </form>
              <?php else: ?>
                <div class="text-center py-4 px-3 border rounded-3 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To submit formal grievances to Nagpur Mahanagar Palika, please log in.</p>
                  <a href="login.php?redirect=dashboard.php?tab=complaints" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($is_logged_in): ?>
            <div class="card mt-4">
              <div class="card-header"><i class="bi bi-list-task text-success me-2"></i>Your Submitted Grievances</div>
              <div class="card-body">
                <?php if (empty($user_grievances)): ?>
                  <p class="text-muted text-center py-3">No grievances filed by you yet.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle">
                      <thead>
                        <tr>
                          <th>Category</th>
                          <th>Zone</th>
                          <th>Details</th>
                          <th>Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($user_grievances as $g): ?>
                          <tr>
                            <td><strong><?php echo htmlspecialchars($g['complaint_type']); ?></strong></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($g['ward_name']); ?></td>
                            <td class="small"><?php echo htmlspecialchars($g['details']); ?></td>
                            <td>
                              <?php if ($g['status'] === 'Pending'): ?>
                                <span class="badge badge-pending">Pending</span>
                              <?php elseif ($g['status'] === 'Resolved'): ?>
                                <span class="badge badge-approved">Resolved</span>
                              <?php else: ?>
                                <span class="badge badge-info"><?php echo htmlspecialchars($g['status']); ?></span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        <!-- Tab: Tax Pay Status -->
        <?php elseif ($active_tab === 'tax'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-file-earmark-ruled text-success me-2"></i>Nagpur Mahanagar Palika - Property & Water Billing</div>
            <div class="card-body">
              <p class="text-muted">Review outstanding property tax dues and water utility bills directly under NMC authorities.</p>
              
              <?php if ($is_logged_in): ?>
                <!-- Property Tax Card -->
                <div class="border rounded p-3 mb-3 bg-white shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    <h6 class="fw-bold mb-1">NMC Property Tax (FY 2025-26)</h6>
                    <?php if (isset($_SESSION['property_tax_paid'])): ?>
                      <span class="badge badge-approved"><i class="bi bi-check-circle me-1"></i>Fully Paid</span>
                    <?php else: ?>
                      <p class="mb-0 text-muted small">Outstanding Balance: <strong class="text-danger">₹80.00</strong></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!isset($_SESSION['property_tax_paid'])): ?>
                    <form action="" method="POST">
                      <input type="hidden" name="bill_type" value="Property Tax">
                      <input type="hidden" name="amount" value="80.00">
                      <button type="submit" name="pay_tax" class="btn btn-sm btn-success rounded-pill px-3 py-1"><i class="bi bi-credit-card me-1"></i>Pay ₹80.00</button>
                    </form>
                  <?php endif; ?>
                </div>

                <!-- Water Utility Bill Card -->
                <div class="border rounded p-3 bg-white shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    <h6 class="fw-bold mb-1">NMC Water Bill - June 2026</h6>
                    <?php if (isset($_SESSION['water_bill_paid'])): ?>
                      <span class="badge badge-approved"><i class="bi bi-check-circle me-1"></i>Fully Paid</span>
                    <?php else: ?>
                      <p class="mb-0 text-muted small">Outstanding Balance: <strong class="text-danger">₹25.00</strong></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!isset($_SESSION['water_bill_paid'])): ?>
                    <form action="" method="POST">
                      <input type="hidden" name="bill_type" value="Water Utility Bill">
                      <input type="hidden" name="amount" value="25.00">
                      <button type="submit" name="pay_tax" class="btn btn-sm btn-success rounded-pill px-3 py-1"><i class="bi bi-credit-card me-1"></i>Pay ₹25.00</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="text-center py-4 px-3 border rounded-3 bg-light shadow-sm">
                  <i class="bi bi-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To view outstanding property tax or water bills and pay them using your Wallet Balance, please log in.</p>
                  <a href="login.php?redirect=dashboard.php?tab=tax" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In</a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($active_tab === 'eco_tasks'): 
          // 1. Get today's task details based on day of week
          $day_of_week = date('l');
          $daily_tasks_rotation = [
              'Monday' => [
                  'name' => 'Cycle Commute Challenge',
                  'description' => 'Ride a bicycle instead of driving for your local commutes today to help reduce traffic congestion and CO2 emissions.',
                  'points' => 20,
                  'cash' => 5.00,
                  'icon' => '🚲'
              ],
              'Tuesday' => [
                  'name' => 'Plant a Tree Challenge',
                  'description' => 'Plant a green sapling in your garden or local park, or spend time watering and caring for existing street trees.',
                  'points' => 30,
                  'cash' => 10.00,
                  'icon' => '🌱'
              ],
              'Wednesday' => [
                  'name' => 'Say No to Plastic',
                  'description' => 'Carry a reusable canvas or jute bag for shopping today and refuse single-use plastic bags.',
                  'points' => 15,
                  'cash' => 3.00,
                  'icon' => '🛍️'
              ],
              'Thursday' => [
                  'name' => 'Clean Nagpur Campaign',
                  'description' => 'Spend 15 minutes collecting plastic bottles, papers, or litter from your neighborhood street or local public park.',
                  'points' => 25,
                  'cash' => 7.00,
                  'icon' => '🧹'
              ],
              'Friday' => [
                  'name' => 'Conserve Home Energy',
                  'description' => 'Turn off all standby appliances, utilize natural lighting, and unplug chargers when not in use for 3 consecutive hours today.',
                  'points' => 15,
                  'cash' => 3.00,
                  'icon' => '🔌'
              ],
              'Saturday' => [
                  'name' => 'Wet & Dry Segregation',
                  'description' => 'Segregate your organic compostable kitchen waste from dry recyclable cardboard, glass, and metal containers.',
                  'points' => 20,
                  'cash' => 6.00,
                  'icon' => '🗑️'
              ],
              'Sunday' => [
                  'name' => 'Green Awareness Drive',
                  'description' => 'Participate in a local neighborhood park cleaning project or share eco-awareness tips with your neighbors.',
                  'points' => 30,
                  'cash' => 12.00,
                  'icon' => '🌳'
              ]
          ];
          
          $current_task = $daily_tasks_rotation[$day_of_week];
          
          $today_claim = null;
          $user_claims = [];
          
          if ($is_logged_in) {
              // Check if user already submitted today
              $stmt = $conn->prepare("SELECT * FROM eco_task_claims WHERE user_id = ? AND task_name = ? AND DATE(submitted_at) = CURDATE() LIMIT 1");
              $stmt->execute([$user['id'], $current_task['name']]);
              $today_claim = $stmt->fetch();
              
              // Fetch user's claim history
              $stmt = $conn->prepare("SELECT * FROM eco_task_claims WHERE user_id = ? ORDER BY submitted_at DESC");
              $stmt->execute([$user['id']]);
              $user_claims = $stmt->fetchAll();
          }
        ?>
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div>
                <i class="bi bi-calendar-check-fill text-success me-2"></i>Daily Citizen Eco Challenge
              </div>
              <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold small">
                Nagpur Green Initiative
              </span>
            </div>
            
            <div class="card-body">
              <p class="text-muted">Earn experience points (XP) and cash rewards paid by the Nagpur Municipal Corporation for taking simple environmental actions.</p>
              
              <!-- Weekly Challenges Track Slider -->
              <h6 class="fw-bold mb-3 text-secondary text-uppercase tracking-wider" style="font-size: 0.8rem; letter-spacing: 1px;"><i class="bi bi-calendar-week-fill me-2 text-success"></i>Weekly Challenge Calendar</h6>
              <div class="horizontal-slider mb-4">
                <?php 
                  $week_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                  foreach ($week_days as $day):
                    $task_info = $daily_tasks_rotation[$day];
                    $is_today = ($day === $day_of_week);
                    $active_class = $is_today ? 'border border-success bg-success-subtle shadow-sm' : 'border border-light bg-white';
                ?>
                  <div class="slider-card">
                    <div class="card p-3 h-100 <?php echo $active_class; ?>" style="border-radius: 16px; position: relative;">
                      <?php if ($is_today): ?>
                        <span class="badge bg-success text-white position-absolute end-0 top-0 m-2 px-2 py-1 small rounded-pill animate__animated animate__pulse animate__infinite" style="font-size: 0.65rem;">Active Today</span>
                      <?php endif; ?>
                      <div class="fs-2 mb-2" style="filter: drop-shadow(0 4px 6px rgba(0,0,0,0.08));"><?php echo $task_info['icon']; ?></div>
                      <h6 class="fw-bold text-success mb-1 small" style="font-size: 0.75rem;"><?php echo $day; ?></h6>
                      <h6 class="fw-bold text-dark mb-2 text-truncate" style="font-size: 0.85rem;" title="<?php echo htmlspecialchars($task_info['name']); ?>"><?php echo htmlspecialchars($task_info['name']); ?></h6>
                      <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-2">
                        <span class="text-primary small fw-bold" style="font-size: 0.75rem;">+₹<?php echo number_format($task_info['cash'], 2); ?></span>
                        <span class="text-success small fw-bold" style="font-size: 0.75rem;">+<?php echo $task_info['points']; ?> XP</span>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>

              <h6 class="fw-bold mb-3 text-secondary text-uppercase tracking-wider" style="font-size: 0.8rem; letter-spacing: 1px;"><i class="bi bi-star-fill me-2 text-success"></i>Today's Challenge</h6>
              <div class="bg-light p-4 rounded-4 mb-4 border border-light shadow-sm">
                <div class="d-flex align-items-center gap-3 mb-3">
                  <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; font-size: 2.2rem; box-shadow: 0 4px 10px rgba(25, 135, 84, 0.25);">
                    <?php echo $current_task['icon']; ?>
                  </div>
                  <div>
                    <h5 class="fw-bold mb-0 text-success"><?php echo htmlspecialchars($current_task['name']); ?></h5>
                    <span class="text-muted small">Challenge of the day: <strong><?php echo $day_of_week; ?></strong></span>
                  </div>
                </div>
                
                <p class="mb-3 text-secondary" style="font-size: 0.95rem; line-height: 1.6;"><?php echo htmlspecialchars($current_task['description']); ?></p>
                
                <div class="d-flex gap-3">
                  <div class="bg-success-subtle text-success px-3 py-2 rounded-pill fw-bold border border-success-subtle small">
                    <i class="bi bi-star-fill me-1 text-warning"></i> +<?php echo $current_task['points']; ?> Eco XP
                  </div>
                  <div class="bg-primary-subtle text-primary px-3 py-2 rounded-pill fw-bold border border-primary-subtle small">
                    <i class="bi bi-cash-coin me-1"></i> +₹<?php echo number_format($current_task['cash'], 2); ?> Government Payout
                  </div>
                </div>
              </div>
              
              <?php if (!$is_logged_in): ?>
                <div class="text-center py-5 border rounded-4 bg-light shadow-sm">
                  <i class="bi bi-shield-lock-fill text-warning fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Login Required</h5>
                  <p class="text-muted small mb-3">To claim daily eco tasks and earn cash rewards, please sign in to your citizen profile.</p>
                  <a href="login.php?redirect=dashboard.php?tab=eco_tasks" class="btn btn-success rounded-pill px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In Now</a>
                </div>
              <?php else: ?>
                <!-- If already submitted today -->
                <?php if ($today_claim): 
                  $status_class = 'bg-warning';
                  $status_text = 'Pending Review';
                  if ($today_claim['status'] === 'Approved') {
                      $status_class = 'bg-success';
                      $status_text = 'Approved & Paid';
                  } elseif ($today_claim['status'] === 'Rejected') {
                      $status_class = 'bg-danger';
                      $status_text = 'Rejected';
                  }
                ?>
                  <div class="alert alert-info rounded-4 border p-4 shadow-sm mb-4">
                    <div class="d-flex align-items-center mb-3">
                      <i class="bi bi-info-circle-fill text-primary fs-3 me-3"></i>
                      <div>
                        <h6 class="fw-bold mb-0">Proof Submitted for Today</h6>
                        <span class="badge <?php echo $status_class; ?> mt-1 py-2 px-3 rounded-pill fw-bold"><?php echo $status_text; ?></span>
                      </div>
                    </div>
                    
                    <div class="row align-items-center">
                      <div class="col-md-4 mb-3 mb-md-0">
                        <img src="<?php echo htmlspecialchars($today_claim['photo_path']); ?>" alt="Proof photo" class="img-fluid rounded-4 shadow-sm border border-light" style="max-height: 200px; width: 100%; object-fit: cover;">
                      </div>
                      <div class="col-md-8">
                        <h6 class="fw-bold mb-1">Your notes:</h6>
                        <p class="text-secondary small fst-italic mb-0"><?php echo htmlspecialchars($today_claim['description']); ?></p>
                        <p class="text-muted mt-2 small mb-0"><i class="bi bi-clock me-1"></i>Submitted at: <?php echo date('d M Y, H:i', strtotime($today_claim['submitted_at'])); ?></p>
                      </div>
                    </div>
                  </div>
                <?php else: ?>
                  <!-- Task Proof Submission Form -->
                  <form action="" method="POST" enctype="multipart/form-data" class="border rounded-4 p-4 bg-white shadow-sm mb-4">
                    <input type="hidden" name="task_name" value="<?php echo htmlspecialchars($current_task['name']); ?>">
                    <input type="hidden" name="points_reward" value="<?php echo $current_task['points']; ?>">
                    <input type="hidden" name="cash_reward" value="<?php echo $current_task['cash']; ?>">
                    
                    <h5 class="fw-bold text-success border-bottom pb-2 mb-3"><i class="bi bi-upload me-2"></i>Upload Completion Proof</h5>
                    
                    <div class="mb-3">
                      <label for="proof_photo" class="form-label fw-bold small">Upload Proof Photo</label>
                      <input class="form-control" type="file" id="proof_photo" name="proof_photo" accept="image/*" required>
                      <div class="form-text">Choose a photo showing you performing today's environmental action.</div>
                    </div>
                    
                    <div class="mb-4">
                      <label for="description" class="form-label fw-bold small">Descriptive Notes</label>
                      <textarea class="form-control" id="description" name="description" rows="3" placeholder="Briefly describe your action (e.g. where you cycled, what tree you watered, etc.)..." required></textarea>
                    </div>
                    
                    <button type="submit" name="submit_eco_task" class="btn btn-success w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                      <i class="bi bi-send-fill me-1"></i>Submit Action Proof to Government
                    </button>
                  </form>
                <?php endif; ?>
                
                <!-- Citizens Eco Challenge Log -->
                <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-clock-history me-2 text-success"></i>Your Eco Challenges Log</h5>
                <div class="table-responsive">
                  <table class="table border rounded-3 bg-white shadow-sm overflow-hidden align-middle">
                    <thead class="bg-light table-light fw-bold text-secondary">
                      <tr>
                        <th>Date</th>
                        <th>Task Name</th>
                        <th>Proof Photo</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th>Rewards</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($user_claims)): ?>
                        <tr>
                          <td colspan="6" class="text-center py-4 text-muted small">No eco challenges logged yet. Complete today's task to earn your first reward!</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($user_claims as $claim): 
                          $badge_color = 'bg-warning';
                          if ($claim['status'] === 'Approved') $badge_color = 'bg-success';
                          elseif ($claim['status'] === 'Rejected') $badge_color = 'bg-danger';
                        ?>
                          <tr>
                            <td class="small text-nowrap"><?php echo date('d M Y', strtotime($claim['submitted_at'])); ?></td>
                            <td class="fw-bold text-success small"><?php echo htmlspecialchars($claim['task_name']); ?></td>
                            <td>
                              <a href="<?php echo htmlspecialchars($claim['photo_path']); ?>" target="_blank">
                                <img src="<?php echo htmlspecialchars($claim['photo_path']); ?>" alt="Proof" class="rounded border shadow-sm" style="width: 50px; height: 50px; object-fit: cover;">
                              </a>
                            </td>
                            <td class="small text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($claim['description']); ?>"><?php echo htmlspecialchars($claim['description']); ?></td>
                            <td>
                              <span class="badge <?php echo $badge_color; ?> rounded-pill px-2 py-1"><?php echo $claim['status']; ?></span>
                            </td>
                            <td class="text-nowrap small fw-bold">
                              <?php if ($claim['status'] === 'Approved'): ?>
                                <span class="text-success">+<?php echo $claim['points_reward']; ?> XP</span><br>
                                <span class="text-primary">+₹<?php echo number_format($claim['cash_reward'], 2); ?></span>
                              <?php else: ?>
                                <span class="text-muted">+<?php echo $claim['points_reward']; ?> XP</span><br>
                                <span class="text-muted">+₹<?php echo number_format($claim['cash_reward'], 2); ?></span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        
      </div>
      </div>
    </main>
  </div>

  <!-- Leaflet Map JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  
  <script>
    // Browser Geolocation for Traffic & Potholes report forms
    document.addEventListener("DOMContentLoaded", function() {
      // Traffic
      const trafficLat = document.getElementById("traffic-lat");
      const trafficLng = document.getElementById("traffic-lng");
      const trafficBtn = document.getElementById("traffic-submit-btn");
      const trafficStatus = document.getElementById("gps-status-text");
      const gpsSpinner = document.getElementById("gps-spinner");
      
      // Pothole
      const potholeLat = document.getElementById("pothole-lat");
      const potholeLng = document.getElementById("pothole-lng");
      const potholeBtn = document.getElementById("pothole-submit-btn");
      const potholeStatus = document.getElementById("pothole-gps-status");
      
      function locateUser() {
        if (gpsSpinner) gpsSpinner.classList.remove("d-none");
        if (navigator.geolocation) {
          navigator.geolocation.getCurrentPosition(
            function(position) {
              const lat = position.coords.latitude.toFixed(6);
              const lng = position.coords.longitude.toFixed(6);
              
              if (trafficLat) {
                trafficLat.value = lat;
                trafficLng.value = lng;
                trafficStatus.textContent = "GPS location coordinates successfully captured!";
                trafficBtn.removeAttribute("disabled");
              }
              if (potholeLat) {
                potholeLat.value = lat;
                potholeLng.value = lng;
                potholeStatus.className = "alert alert-success py-2 small mb-3";
                potholeStatus.innerHTML = "<i class='bi bi-check-circle-fill me-2'></i>Location geo-tag updated!";
                potholeBtn.removeAttribute("disabled");
              }
              if (gpsSpinner) gpsSpinner.classList.add("d-none");
            },
            function(error) {
              let msg = "Geolocation failed: " + error.message + ". Please permit location permissions and reload.";
              if (trafficStatus) trafficStatus.textContent = msg;
              if (potholeStatus) {
                potholeStatus.className = "alert alert-warning py-2 small mb-3";
                potholeStatus.innerHTML = "<i class='bi bi-exclamation-triangle-fill me-2'></i>" + msg;
              }
              if (gpsSpinner) gpsSpinner.classList.add("d-none");
            },
            { enableHighAccuracy: true, timeout: 10000 }
          );
        } else {
          let msg = "Browser does not support geolocation tracking.";
          if (trafficStatus) trafficStatus.textContent = msg;
          if (potholeStatus) {
            potholeStatus.className = "alert alert-danger py-2 small mb-3";
            potholeStatus.textContent = msg;
          }
        }
      }
      
      // Trigger location if forms are present
      if (trafficLat || potholeLat) {
        locateUser();
      }

      // ==========================================
      // WEBCAM CONTROLLER (Unified)
      // ==========================================
      function setupWebcam(config) {
        const sourceUpload = document.getElementById(config.sourceUploadId);
        const sourceWebcam = document.getElementById(config.sourceWebcamId);
        const uploadContainer = document.getElementById(config.uploadContainerId);
        const webcamContainer = document.getElementById(config.webcamContainerId);
        const filePhotoInput = document.getElementById(config.filePhotoInputId);
        const webcamDataInput = document.getElementById(config.webcamDataInputId);
        
        const webcamVideo = document.getElementById(config.webcamVideoId);
        const webcamCanvas = document.getElementById(config.webcamCanvasId);
        const webcamPreviewImg = document.getElementById(config.webcamPreviewImgId);
        const webcamPlaceholder = document.getElementById(config.webcamPlaceholderId);
        
        const btnStartWebcam = document.getElementById(config.btnStartWebcamId);
        const btnCaptureWebcam = document.getElementById(config.btnCaptureWebcamId);
        const btnResetWebcam = document.getElementById(config.btnResetWebcamId);
        
        let videoStream = null;
        
        function stopWebcam() {
          if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
          }
        }
        
        if (sourceUpload && sourceWebcam) {
          sourceUpload.addEventListener("change", function() {
            uploadContainer.classList.remove("d-none");
            webcamContainer.classList.add("d-none");
            if (filePhotoInput) filePhotoInput.setAttribute("required", "required");
            webcamDataInput.value = "";
            stopWebcam();
          });
          
          sourceWebcam.addEventListener("change", function() {
            uploadContainer.classList.add("d-none");
            webcamContainer.classList.remove("d-none");
            if (filePhotoInput) filePhotoInput.removeAttribute("required");
          });
        }
        
        if (btnStartWebcam) {
          btnStartWebcam.addEventListener("click", async function() {
            try {
              webcamPlaceholder.classList.add("d-none");
              webcamVideo.classList.remove("d-none");
              webcamPreviewImg.classList.add("d-none");
              
              videoStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: false
              });
              
              webcamVideo.srcObject = videoStream;
              btnStartWebcam.classList.add("d-none");
              btnCaptureWebcam.classList.remove("d-none");
              btnResetWebcam.classList.add("d-none");
            } catch (err) {
              alert("Error accessing camera: " + err.message + ". Please verify permissions.");
              webcamPlaceholder.classList.remove("d-none");
            }
          });
        }
        
        if (btnCaptureWebcam) {
          btnCaptureWebcam.addEventListener("click", function() {
            const context = webcamCanvas.getContext("2d");
            context.drawImage(webcamVideo, 0, 0, webcamCanvas.width, webcamCanvas.height);
            
            // Retrieve GPS coords
            let lat = "N/A";
            let lng = "N/A";
            const latInput = document.getElementById(config.gpsLatInputId);
            const lngInput = document.getElementById(config.gpsLngInputId);
            if (latInput && latInput.value) {
              lat = latInput.value;
              lng = lngInput.value;
            }
            
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-GB');
            const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
            const timestampStr = dateStr + " " + timeStr;
            
            // Watermark HUD Overlay
            context.fillStyle = "rgba(15, 23, 42, 0.75)";
            context.fillRect(0, webcamCanvas.height - 75, webcamCanvas.width, 75);
            
            context.fillStyle = "#22c55e";
            context.font = "bold 13px 'Courier New', monospace";
            context.fillText("NMC NAGPUR CIVIC CAMERA", 15, webcamCanvas.height - 52);
            
            context.fillStyle = "#ffffff";
            context.font = "12px 'Courier New', monospace";
            context.fillText("LAT: " + lat + " | LON: " + lng, 15, webcamCanvas.height - 32);
            
            context.fillStyle = "#94a3b8";
            context.font = "11px 'Courier New', monospace";
            context.fillText("TIMESTAMP: " + timestampStr + " (IST)", 15, webcamCanvas.height - 14);
            
            const dataUrl = webcamCanvas.toDataURL("image/jpeg");
            webcamDataInput.value = dataUrl;
            
            webcamPreviewImg.src = dataUrl;
            webcamPreviewImg.classList.remove("d-none");
            webcamVideo.classList.add("d-none");
            
            btnCaptureWebcam.classList.add("d-none");
            btnResetWebcam.classList.remove("d-none");
            
            stopWebcam();
          });
        }
        
        if (btnResetWebcam) {
          btnResetWebcam.addEventListener("click", function() {
            webcamDataInput.value = "";
            webcamPreviewImg.classList.add("d-none");
            webcamVideo.classList.remove("d-none");
            btnStartWebcam.click();
          });
        }
      }

      // Initialize Webcam for Traffic Challan
      setupWebcam({
        sourceUploadId: "source-upload",
        sourceWebcamId: "source-webcam",
        uploadContainerId: "upload-photo-container",
        webcamContainerId: "webcam-photo-container",
        filePhotoInputId: "file-photo-input",
        webcamDataInputId: "webcam-data-input",
        webcamVideoId: "webcam-video",
        webcamCanvasId: "webcam-canvas",
        webcamPreviewImgId: "webcam-preview-img",
        webcamPlaceholderId: "webcam-placeholder",
        btnStartWebcamId: "btn-start-webcam",
        btnCaptureWebcamId: "btn-capture-webcam",
        btnResetWebcamId: "btn-reset-webcam",
        gpsLatInputId: "traffic-lat",
        gpsLngInputId: "traffic-lng"
      });

      // Initialize Webcam for Potholes Report
      setupWebcam({
        sourceUploadId: "pothole-source-upload",
        sourceWebcamId: "pothole-source-webcam",
        uploadContainerId: "pothole-upload-photo-container",
        webcamContainerId: "pothole-webcam-photo-container",
        filePhotoInputId: "pothole-file-photo-input",
        webcamDataInputId: "pothole-webcam-data-input",
        webcamVideoId: "pothole-webcam-video",
        webcamCanvasId: "pothole-webcam-canvas",
        webcamPreviewImgId: "pothole-webcam-preview-img",
        webcamPlaceholderId: "pothole-webcam-placeholder",
        btnStartWebcamId: "pothole-btn-start-webcam",
        btnCaptureWebcamId: "pothole-btn-capture-webcam",
        btnResetWebcamId: "pothole-btn-reset-webcam",
        gpsLatInputId: "pothole-lat",
        gpsLngInputId: "pothole-lng"
      });
    });

    // Pothole Map Setup (Locked to Nagpur)
    <?php if ($active_tab === 'potholes'): ?>
      const nagpurBounds = L.latLngBounds([20.90, 78.85], [21.35, 79.35]);
      const map = L.map('pothole-map', {
        maxBounds: nagpurBounds,
        maxBoundsViscosity: 1.0
      }).setView([21.1458, 79.0882], 12);
      
      // Standard Street View
      const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        minZoom: 11,
        attribution: '© OpenStreetMap'
      });
      
      // 3D Satellite View (Esri World Imagery)
      const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        minZoom: 11,
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
      });
      
      // Load Satellite View by default
      satelliteLayer.addTo(map);
      
      // Overlay layers control (Always expanded for instant visibility)
      const baseLayers = {
        "3D Satellite View": satelliteLayer,
        "Standard Street Map": streetLayer
      };
      L.control.layers(baseLayers, null, { collapsed: false }).addTo(map);
      
      <?php if (!empty($all_open_potholes)): ?>
        <?php foreach ($all_open_potholes as $p): ?>
          // Ensure markers are only added if they fall within Nagpur region
          L.marker([<?php echo $p['latitude']; ?>, <?php echo $p['longitude']; ?>])
            .addTo(map)
            .bindPopup(`
              <div style="min-width: 150px;">
                <img src="<?php echo htmlspecialchars($p['photo_path']); ?>" style="width:100%; height:80px; object-fit:cover; border-radius:6px; margin-bottom:5px;">
                <strong>Status:</strong> <span class="badge bg-secondary text-white"><?php echo $p['status']; ?></span><br>
                <strong>Description:</strong> <?php echo htmlspecialchars(addslashes($p['description'])); ?>
              </div>
            `);
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
    
    // AQI Chart.js Setup
    <?php if ($active_tab === 'aqi' && !empty($aqi_readings)): ?>
      <?php foreach ($aqi_readings as $idx => $r): ?>
        new Chart(document.getElementById('aqiChart-<?php echo $idx; ?>').getContext('2d'), {
          type: 'bar',
          data: {
            labels: ['Air Quality Index (AQI)', 'CO2 Level (ppm)'],
            datasets: [{
              label: 'Ward Metrics',
              data: [<?php echo $r['aqi_value']; ?>, <?php echo $r['co2_value'] ?: 0; ?>],
              backgroundColor: ['rgba(39, 174, 96, 0.7)', 'rgba(52, 152, 219, 0.7)'],
              borderColor: ['#27ae60', '#3498db'],
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false }
            },
            scales: {
              y: { beginAtZero: true }
            }
          }
        });
      <?php endforeach; ?>
    <?php endif; ?>
  </script>

  <!-- Bootstrap Bundle with Popper JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Theme Toggle Handler
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
      });
    }

    // Sidebar Mobile Toggle
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const dashboardSidebar = document.getElementById('dashboard-sidebar');
    if (sidebarToggle && dashboardSidebar) {
      sidebarToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        dashboardSidebar.classList.toggle('show');
      });
      // Click outside to dismiss sidebar on mobile
      document.addEventListener('click', (e) => {
        if (dashboardSidebar.classList.contains('show') && !dashboardSidebar.contains(e.target) && e.target !== sidebarToggle) {
          dashboardSidebar.classList.remove('show');
        }
      });
    }

    // ==========================================
    // PROGRAMMATIC GOOGLE TRANSLATE INTEGRATION
    // ==========================================
    document.addEventListener("DOMContentLoaded", function() {
      const savedLang = localStorage.getItem("app_lang") || "en";
      const labels = { en: "EN", hi: "HI", mr: "MR" };
      const labelEl = document.getElementById("current-lang-label");
      if (labelEl) labelEl.textContent = labels[savedLang];
      
      document.querySelectorAll(".lang-select-btn").forEach(btn => {
        btn.addEventListener("click", function(e) {
          e.preventDefault();
          const lang = this.getAttribute("data-lang");
          localStorage.setItem("app_lang", lang);
          
          // Clear existing cookies
          document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
          document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; domain=" + window.location.hostname + "; path=/;";
          
          const isHttps = window.location.protocol === 'https:';
          const secureFlag = isHttps ? '; Secure' : '';
          
          // Set googtrans cookie (e.g. /en/en or /en/hi)
          document.cookie = "googtrans=/en/" + lang + "; path=/; SameSite=Lax" + secureFlag;
          document.cookie = "googtrans=/en/" + lang + "; domain=" + window.location.hostname + "; path=/; SameSite=Lax" + secureFlag;
          
          window.location.reload();
        });
      });

      // Programmatic force check to trigger the translation widget once it's rendered
      let attempts = 0;
      const maxAttempts = 100; // Try for 10 seconds max
      const checkInterval = setInterval(() => {
        const selectElement = document.querySelector('.goog-te-combo');
        if (selectElement) {
          clearInterval(checkInterval);
          
          let targetValue = savedLang;
          if (savedLang === 'en') {
            // Check if 'en' is an option, otherwise use empty string '' to restore original
            const hasEnOption = Array.from(selectElement.options).some(opt => opt.value === 'en');
            targetValue = hasEnOption ? 'en' : '';
          }
          
          if (selectElement.value !== targetValue) {
            selectElement.value = targetValue;
            selectElement.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
        attempts++;
        if (attempts >= maxAttempts) {
          clearInterval(checkInterval);
        }
      }, 100);

      // Suppress dynamic Google Translate banner shifts
      setInterval(function() {
        const frames = document.querySelectorAll("iframe.skiptranslate, .goog-te-banner-frame");
        frames.forEach(f => {
          f.style.display = 'none';
          f.style.visibility = 'hidden';
        });
        document.body.style.top = "0px";
        document.body.style.marginTop = "0px";
        document.documentElement.style.top = "0px";
        document.documentElement.style.marginTop = "0px";
      }, 300);
    });
  </script>

  <!-- Mobile Dock Bottom Spacer to prevent content overlay -->
  <div class="d-block d-md-none" style="height: 80px; width: 100%;"></div>

  <!-- Quick Camera Upload & Scanner Modal -->
  <div class="modal fade" id="quickCameraModal" tabindex="-1" aria-labelledby="quickCameraModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold" id="quickCameraModalLabel"><i class="bi bi-camera-fill text-success me-2"></i>Quick Camera Upload & Scanner</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body pt-2">
          <p class="text-muted small mb-3">Choose what you want to capture or scan with your camera:</p>
          
          <div class="d-flex flex-column gap-2">
            <!-- Option 1: Pothole Report -->
            <a href="dashboard.php?tab=potholes" class="new-essential-item p-3 text-decoration-none">
              <div class="new-essential-icon" style="background-color: #f3e8ff; color: #7e22ce; border-radius: 12px; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="bi bi-tools"></i>
              </div>
              <div class="new-essential-content flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <h6 class="fw-bold mb-0 text-dark">1. Report Pothole Road Damage</h6>
                  <span class="badge bg-primary">GPS Photo</span>
                </div>
                <p class="text-muted small mb-0">Upload a live photo of broken road or street pothole for quick repair.</p>
              </div>
            </a>
            
            <!-- Option 2: Traffic Challan -->
            <a href="dashboard.php?tab=traffic" class="new-essential-item p-3 text-decoration-none">
              <div class="new-essential-icon" style="background-color: #ffe4e6; color: #e11d48; border-radius: 12px; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="bi bi-camera-fill"></i>
              </div>
              <div class="new-essential-content flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <h6 class="fw-bold mb-0 text-dark">2. Traffic Violation Challan</h6>
                  <span class="badge bg-danger">₹50 Reward</span>
                </div>
                <p class="text-muted small mb-0">Upload photo evidence of traffic violations & earn cash rewards upon approval.</p>
              </div>
            </a>

            <!-- Option 3: CO2 Barcode Scanner -->
            <a href="barcode.php" class="new-essential-item p-3 text-decoration-none">
              <div class="new-essential-icon" style="background-color: #dcfce7; color: #15803d; border-radius: 12px; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="bi bi-qr-code-scan"></i>
              </div>
              <div class="new-essential-content flex-grow-1">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <h6 class="fw-bold mb-0 text-dark">3. Carbon CO2 Barcode Scanner</h6>
                  <span class="badge bg-success">+Eco Cash</span>
                </div>
                <p class="text-muted small mb-0">Scan product barcodes to calculate carbon footprint & earn eco cash points.</p>
              </div>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- App-Style Mobile Bottom Navigation Dock (Fixed Sticky on Mobile Viewports < 768px) -->
  <nav class="mobile-nav-dock">
    <a href="index.php" class="mobile-nav-item">
      <i class="bi bi-house-door-fill"></i>
      <span>Home</span>
    </a>
    <a href="polls.php" class="mobile-nav-item">
      <i class="bi bi-check2-square"></i>
      <span>Polls</span>
    </a>
    <button type="button" class="mobile-nav-item mobile-nav-fab" data-bs-toggle="modal" data-bs-target="#quickCameraModal" title="Quick Camera Upload & Scanner">
      <i class="bi bi-camera-fill"></i>
    </button>
    <a href="dashboard.php?tab=potholes" class="mobile-nav-item active">
      <i class="bi bi-tools"></i>
      <span>Report</span>
    </a>
    <a href="profile.php" class="mobile-nav-item">
      <i class="bi bi-person-circle"></i>
      <span>Profile</span>
    </a>
  </nav>

  <!-- Google Translate Wrapper -->
  <div id="google_translate_element" style="display:none;"></div>
  <script type="text/javascript">
    function googleTranslateElementInit() {
      new google.translate.TranslateElement({
        pageLanguage: 'en',
        includedLanguages: 'en,hi,mr',
        layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
        autoDisplay: false
      }, 'google_translate_element');
    }
  </script>
  <script type="text/javascript" src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</body>
</html>
