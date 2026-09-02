<?php
// admin.php - Administrator Control Portal
require_once 'config.php';

$admin = require_admin($conn);
$is_logged_in = is_logged_in();
$active_tab = $_GET['tab'] ?? 'power';
$error = '';
$success = '';

// Helper function for news image upload
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

// Process Admin Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Add Loadshedding Schedule
    if (isset($_POST['add_loadshedding'])) {
        $ward_id = intval($_POST['ward_id']);
        $date = $_POST['date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        if (empty($date) || empty($start_time) || empty($end_time)) {
            $error = "Please fill in all schedule fields.";
        } else {
            $stmt = $conn->prepare("INSERT INTO loadshedding_schedule (ward_id, date, start_time, end_time, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$ward_id, $date, $start_time, $end_time, $reason]);
            $success = "Power cut schedule added successfully!";
        }
    }
    
    // 2. Delete Loadshedding Schedule
    elseif (isset($_POST['delete_loadshedding'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM loadshedding_schedule WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Power cut schedule removed.";
    }
    
    // 3. Add Water Supply Schedule
    elseif (isset($_POST['add_water_schedule'])) {
        $ward_id = intval($_POST['ward_id']);
        $area_name = trim($_POST['area_name'] ?? '');
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        
        if (empty($area_name) || empty($day_of_week) || empty($start_time) || empty($end_time)) {
            $error = "Please complete all water supply fields.";
        } else {
            $stmt = $conn->prepare("INSERT INTO water_schedule (ward_id, area_name, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$ward_id, $area_name, $day_of_week, $start_time, $end_time]);
            $success = "Water supply timetable added!";
        }
    }
    
    // 4. Delete Water Supply Schedule
    elseif (isset($_POST['delete_water'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM water_schedule WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Water supply slot removed.";
    }
    
    // 5. Approve Traffic Challan (Reward Citizen)
    elseif (isset($_POST['approve_traffic'])) {
        $report_id = intval($_POST['report_id']);
        
        $stmt = $conn->prepare("SELECT * FROM traffic_reports WHERE id = ? AND status = 'Pending'");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch();
        
        if ($report) {
            $conn->beginTransaction();
            try {
                // Update report
                $stmt = $conn->prepare("UPDATE traffic_reports SET status = 'Approved', reward_credited = 1 WHERE id = ?");
                $stmt->execute([$report_id]);
                
                // Credit Citizen Wallet
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + 50.00 WHERE id = ?");
                $stmt->execute([$report['user_id']]);
                
                // Log Wallet Transaction
                $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, 50.00, 'credit', ?)");
                $stmt->execute([$report['user_id'], "Approved Traffic Challan Reward (Report ID: #{$report_id})"]);
                
                $conn->commit();
                $success = "Challan approved! Reward of ₹50.00 has been credited to the citizen's wallet.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Processing failed: " . $e->getMessage();
            }
        }
    }
    
    // 6. Reject Traffic Challan
    elseif (isset($_POST['reject_traffic'])) {
        $report_id = intval($_POST['report_id']);
        $stmt = $conn->prepare("UPDATE traffic_reports SET status = 'Rejected' WHERE id = ?");
        $stmt->execute([$report_id]);
        $success = "Traffic report rejected.";
    }
    
    // 9. Approve Eco Task (Reward Citizen)
    elseif (isset($_POST['approve_eco_task'])) {
        $claim_id = intval($_POST['claim_id']);
        
        $stmt = $conn->prepare("SELECT * FROM eco_task_claims WHERE id = ? AND status = 'Pending'");
        $stmt->execute([$claim_id]);
        $claim = $stmt->fetch();
        
        if ($claim) {
            $conn->beginTransaction();
            try {
                // Update claim status
                $stmt = $conn->prepare("UPDATE eco_task_claims SET status = 'Approved' WHERE id = ?");
                $stmt->execute([$claim_id]);
                
                // Credit Citizen Eco Points & Wallet Balance
                $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points + ?, wallet_balance = wallet_balance + ? WHERE id = ?");
                $stmt->execute([$claim['points_reward'], $claim['cash_reward'], $claim['user_id']]);
                
                // Log Wallet Transaction
                $desc = "Eco Action Payout (Claim ID: #{$claim_id} - {$claim['task_name']})";
                $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
                $stmt->execute([$claim['user_id'], $claim['cash_reward'], $desc]);
                
                $conn->commit();
                $success = "Citizen Eco Action approved! XP and Cash rewards credited.";
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Processing failed: " . $e->getMessage();
            }
        }
    }
    
    // 10. Reject Eco Task
    elseif (isset($_POST['reject_eco_task'])) {
        $claim_id = intval($_POST['claim_id']);
        $stmt = $conn->prepare("UPDATE eco_task_claims SET status = 'Rejected' WHERE id = ?");
        $stmt->execute([$claim_id]);
        $success = "Citizen Eco Action proof rejected.";
    }
    
    // 7. Update Pothole Report Status
    elseif (isset($_POST['update_pothole'])) {
        $id = intval($_POST['id']);
        $status = $_POST['status'] ?? 'Reported';
        $allowed = ['Reported', 'Acknowledged', 'In Progress', 'Resolved'];
        if (in_array($status, $allowed)) {
            $stmt = $conn->prepare("UPDATE pothole_reports SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $success = "Pothole status updated to: {$status}!";
        }
    }
    
    // 8. Add Waste Schedule
    elseif (isset($_POST['add_waste_schedule'])) {
        $ward_id = intval($_POST['ward_id']);
        $day_of_week = $_POST['day_of_week'] ?? '';
        $collection_time = $_POST['collection_time'] ?? '';
        
        if (empty($day_of_week) || empty($collection_time)) {
            $error = "Please fill in all waste scheduling fields.";
        } else {
            $stmt = $conn->prepare("INSERT INTO waste_schedule (ward_id, day_of_week, collection_time) VALUES (?, ?, ?)");
            $stmt->execute([$ward_id, $day_of_week, $collection_time]);
            $success = "Waste collection schedule added!";
        }
    }
    
    // 9. Delete Waste Schedule
    elseif (isset($_POST['delete_waste'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM waste_schedule WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Waste schedule slot removed.";
    }
    
    // 10. Add AQI Metric Reading
    elseif (isset($_POST['add_aqi'])) {
        $ward_id = intval($_POST['ward_id']);
        $aqi_value = intval($_POST['aqi_value'] ?? 0);
        $co2_value = !empty($_POST['co2_value']) ? intval($_POST['co2_value']) : null;
        
        if ($aqi_value < 0) {
            $error = "Please enter a valid AQI value.";
        } else {
            $stmt = $conn->prepare("INSERT INTO aqi_readings (ward_id, aqi_value, co2_value) VALUES (?, ?, ?)");
            $stmt->execute([$ward_id, $aqi_value, $co2_value]);
            $success = "AQI reading logged successfully!";
        }
    }
    
    // 11. Add News Article
    elseif (isset($_POST['add_news'])) {
        $title = trim($_POST['title'] ?? '');
        $category = $_POST['category'] ?? 'General';
        $content = trim($_POST['content'] ?? '');
        $is_emergency = isset($_POST['is_emergency']) ? 1 : 0;
        $ward_id = !empty($_POST['ward_id']) ? intval($_POST['ward_id']) : null;
        
        if (empty($title) || empty($content)) {
            $error = "News title and content cannot be blank.";
        } else {
            $image_path = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $image_path = handle_file_upload($_FILES['photo'], 'news_');
            }
            
            $stmt = $conn->prepare("INSERT INTO news_posts (title, content, category, image_path, is_emergency, ward_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $content, $category, $image_path, $is_emergency, $ward_id]);
            $success = "News published successfully!";
        }
    }
    
    // 12. Delete News Post
    elseif (isset($_POST['delete_news'])) {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("DELETE FROM news_posts WHERE id = ?");
        $stmt->execute([$id]);
        $success = "News article deleted.";
    }
}

// Fetch lists for editors & logs
$wards = $conn->query("SELECT * FROM wards ORDER BY name ASC")->fetchAll();

// 1. Loadshedding Schedules
$loadshedding_list = $conn->query("SELECT ls.*, w.name as ward_name FROM loadshedding_schedule ls INNER JOIN wards w ON ls.ward_id = w.id ORDER BY ls.date DESC, ls.start_time ASC")->fetchAll();

// 2. Water Timetables
$water_list = $conn->query("SELECT ws.*, w.name as ward_name FROM water_schedule ws INNER JOIN wards w ON ws.ward_id = w.id ORDER BY w.name ASC, FIELD(ws.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), ws.start_time ASC")->fetchAll();

// 3. Traffic Challans
$traffic_list = $conn->query("SELECT tr.*, u.email FROM traffic_reports tr INNER JOIN users u ON tr.user_id = u.id ORDER BY tr.created_at DESC")->fetchAll();

// 4. Potholes (with community verification vote stats)
$pothole_list = $conn->query("
    SELECT pr.*, u.email,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = pr.id AND v.vote_type = 'upvote') as upvotes,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = pr.id AND v.vote_type = 'downvote') as downvotes
    FROM pothole_reports pr 
    INNER JOIN users u ON pr.user_id = u.id 
    ORDER BY (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = pr.id AND v.vote_type = 'upvote') DESC, pr.created_at DESC")->fetchAll();

// 5. Waste Schedules
$waste_list = $conn->query("SELECT ws.*, w.name as ward_name FROM waste_schedule ws INNER JOIN wards w ON ws.ward_id = w.id ORDER BY w.name ASC, FIELD(ws.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")->fetchAll();

// 6. AQI Log history
$aqi_list = $conn->query("SELECT r.*, w.name as ward_name FROM aqi_readings r INNER JOIN wards w ON r.ward_id = w.id ORDER BY r.recorded_at DESC LIMIT 50")->fetchAll();

// 7. News Posts list
$news_list = $conn->query("SELECT n.*, w.name as ward_name FROM news_posts n LEFT JOIN wards w ON n.ward_id = w.id ORDER BY n.created_at DESC")->fetchAll();

// 8. Complaints log (loadshedding/water utility complaints and waste complaints)
$utility_complaints_list = $conn->query("SELECT uc.*, u.email, w.name as ward_name FROM utility_complaints uc INNER JOIN users u ON uc.user_id = u.id INNER JOIN wards w ON uc.ward_id = w.id ORDER BY uc.created_at DESC")->fetchAll();
$waste_complaints_list = $conn->query("SELECT wc.*, u.email, w.name as ward_name FROM waste_complaints wc INNER JOIN users u ON wc.user_id = u.id INNER JOIN wards w ON wc.ward_id = w.id ORDER BY wc.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Portal - City Civic Portal</title>
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />

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
    /* Specific overrides for admin panel */
    body {
      background-color: var(--bg-app);
    }
  </style>
</head>
<body>

  <!-- Top Navigation Bar for Admin -->
  <nav class="navbar navbar-expand-lg navbar-light sticky-top">
    <div class="container-fluid px-md-4">
      <div class="d-flex align-items-center">
        <!-- Hamburger Menu Trigger (Mobile only) -->
        <button class="btn btn-link nav-link p-0 me-3 d-lg-none" type="button" id="sidebar-toggle" aria-label="Toggle Sidebar">
          <i class="bi bi-list fs-3"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center" href="index.php">
          <i class="bi bi-building-fill-gear me-2 text-success"></i>
          <span class="d-none d-sm-inline">Nagpur Mahanagar Palika</span>
          <span class="d-inline d-sm-none">NMC</span>
          <span class="badge bg-danger ms-2 small py-1 px-2 text-white" style="font-size: 0.65rem;">ADMIN</span>
        </a>
      </div>
      
      <div class="d-flex align-items-center gap-2">
        <!-- Language Switcher Dropdown -->
        <div class="dropdown me-1">
          <button class="btn btn-sm btn-link nav-link dropdown-toggle d-flex align-items-center gap-1" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-translate fs-5"></i> <span id="current-lang-label">EN</span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="langDropdown">
            <li><a class="dropdown-item lang-select-btn" href="#" data-lang="en">English (EN)</a></li>
            <li><a class="dropdown-item lang-select-btn" href="#" data-lang="hi">हिंदी (HI)</a></li>
            <li><a class="dropdown-item lang-select-btn" href="#" data-lang="mr">मराठी (MR)</a></li>
          </ul>
        </div>

        <button id="theme-toggle" class="btn btn-link nav-link px-2 text-secondary" type="button" aria-label="Toggle Theme">
          <i class="bi bi-sun-fill d-none-theme-light text-warning fs-5"></i>
          <i class="bi bi-moon-stars-fill d-none-theme-dark text-primary fs-5"></i>
        </button>
        
        <span class="small text-muted me-2 d-none d-md-inline" data-translate-key="system_admin">
          <i class="bi bi-shield-check text-success me-1"></i>System Admin
        </span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 d-none d-sm-inline-block" data-translate-key="logout">Logout</a>
      </div>
    </div>
  </nav>

  <div class="dashboard-container">
    <!-- Left Sidebar docked on Desktop, Offcanvas on Mobile -->
    <aside class="app-sidebar" id="dashboard-sidebar">
      <nav class="nav flex-column">
        <a class="sidebar-nav-link <?php echo ($active_tab === 'power') ? 'active' : ''; ?>" href="?tab=power" data-translate-key="power_outages">
          <i class="bi bi-lightning-charge-fill text-warning"></i>Power Outages
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'water') ? 'active' : ''; ?>" href="?tab=water" data-translate-key="water_supply">
          <i class="bi bi-droplet-fill text-info"></i>Water Supply
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'traffic') ? 'active' : ''; ?>" href="?tab=traffic" data-translate-key="traffic_violations">
          <i class="bi bi-camera-fill text-danger"></i>Traffic Violations
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'potholes') ? 'active' : ''; ?>" href="?tab=potholes" data-translate-key="pothole_reports">
          <i class="bi bi-tools text-primary"></i>Pothole Reports
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'waste') ? 'active' : ''; ?>" href="?tab=waste" data-translate-key="waste_reports">
          <i class="bi bi-trash3-fill text-success"></i>Waste Reports
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'eco_tasks') ? 'active' : ''; ?>" href="?tab=eco_tasks">
          <i class="bi bi-calendar-check-fill text-success"></i>Verify Eco Actions
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'complaints') ? 'active' : ''; ?>" href="?tab=complaints" data-translate-key="utility_complaints">
          <i class="bi bi-shield-fill text-dark"></i>Utility Complaints
        </a>
        <a class="sidebar-nav-link <?php echo ($active_tab === 'news') ? 'active' : ''; ?>" href="?tab=news" data-translate-key="news_bulletin">
          <i class="bi bi-newspaper text-secondary"></i>News Bulletin
        </a>
        
        <div class="border-top mt-4 pt-3 px-3">
          <a href="dashboard.php" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-person-fill me-1"></i>Citizen Portal</a>
        </div>
        
        <div class="border-top mt-3 pt-3 px-3 d-sm-none">
          <a href="logout.php" class="btn btn-outline-danger btn-sm w-100 py-2"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
      </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content-area">
        
        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <!-- Tab: Power Outages -->
        <?php if ($active_tab === 'power'): ?>
          <div class="row">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle-fill text-success me-2"></i>Add Loadshedding Schedule</div>
                <div class="card-body">
                  <form action="" method="POST">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Select Target Ward</label>
                      <select name="ward_id" class="form-select" required>
                        <?php foreach ($wards as $w): ?>
                          <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Date</label>
                      <input type="date" name="date" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                      <div class="col">
                        <label class="form-label fw-bold">Start Time</label>
                        <input type="time" name="start_time" class="form-control" required>
                      </div>
                      <div class="col">
                        <label class="form-label fw-bold">End Time</label>
                        <input type="time" name="end_time" class="form-control" required>
                      </div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Reason (Optional)</label>
                      <input type="text" name="reason" class="form-control" placeholder="E.g., Grid upgrade, maintenance outage">
                    </div>
                    <button type="submit" name="add_loadshedding" class="btn btn-primary w-100">Add Cut Schedule</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><i class="bi bi-calendar-event text-success me-2"></i>Scheduled Power Cuts</div>
                <div class="card-body">
                  <?php if (empty($loadshedding_list)): ?>
                    <p class="text-muted text-center py-3">No outages listed.</p>
                  <?php else: ?>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                      <table class="table table-hover align-middle">
                        <thead>
                          <tr>
                            <th>Ward</th>
                            <th>Date</th>
                            <th>Timings</th>
                            <th>Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($loadshedding_list as $item): ?>
                            <tr>
                              <td data-label="Ward"><?php echo htmlspecialchars($item['ward_name']); ?></td>
                              <td data-label="Date" class="small"><?php echo date('d-M-y', strtotime($item['date'])); ?></td>
                              <td data-label="Timings" class="font-monospace small"><?php echo date('h:i A', strtotime($item['start_time'])); ?>-<?php echo date('h:i A', strtotime($item['end_time'])); ?></td>
                              <td data-label="Action">
                                <form action="" method="POST" onsubmit="return confirm('Remove schedule?');">
                                  <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                  <button type="submit" name="delete_loadshedding" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

        <!-- Tab: Water Supply -->
        <?php elseif ($active_tab === 'water'): ?>
          <div class="row">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle-fill text-success me-2"></i>Add Water Supply timetable</div>
                <div class="card-body">
                  <form action="" method="POST">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Select Ward</label>
                      <select name="ward_id" class="form-select" required>
                        <?php foreach ($wards as $w): ?>
                          <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Area Name / Sector</label>
                      <input type="text" name="area_name" class="form-control" placeholder="E.g. Sector 3, Block B" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Day of Week</label>
                      <select name="day_of_week" class="form-select" required>
                        <option>Monday</option>
                        <option>Tuesday</option>
                        <option>Wednesday</option>
                        <option>Thursday</option>
                        <option>Friday</option>
                        <option>Saturday</option>
                        <option>Sunday</option>
                      </select>
                    </div>
                    <div class="row mb-3">
                      <div class="col">
                        <label class="form-label fw-bold">Start Time</label>
                        <input type="time" name="start_time" class="form-control" required>
                      </div>
                      <div class="col">
                        <label class="form-label fw-bold">End Time</label>
                        <input type="time" name="end_time" class="form-control" required>
                      </div>
                    </div>
                    <button type="submit" name="add_water_schedule" class="btn btn-primary w-100">Add Timetable</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><i class="bi bi-droplet-half text-success me-2"></i>Active Supply Slots</div>
                <div class="card-body">
                  <?php if (empty($water_list)): ?>
                    <p class="text-muted text-center py-3">No water timetables logged.</p>
                  <?php else: ?>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                      <table class="table table-hover align-middle">
                        <thead>
                          <tr>
                            <th>Ward / Area</th>
                            <th>Day</th>
                            <th>Timings</th>
                            <th>Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($water_list as $ws): ?>
                            <tr>
                              <td>
                                <strong><?php echo htmlspecialchars($ws['ward_name']); ?></strong><br>
                                <span class="text-muted small"><?php echo htmlspecialchars($ws['area_name']); ?></span>
                              </td>
                              <td><?php echo $ws['day_of_week']; ?></td>
                              <td class="font-monospace small"><?php echo date('H:i', strtotime($ws['start_time'])); ?>-<?php echo date('H:i', strtotime($ws['end_time'])); ?></td>
                              <td>
                                <form action="" method="POST" onsubmit="return confirm('Remove water timetable?');">
                                  <input type="hidden" name="id" value="<?php echo $ws['id']; ?>">
                                  <button type="submit" name="delete_water" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

        <!-- Tab: Traffic Challan Review -->
        <?php elseif ($active_tab === 'traffic'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-file-earmark-check text-success me-2"></i>Review Traffic Challan Reports</div>
            <div class="card-body">
              <?php if (empty($traffic_list)): ?>
                <p class="text-muted text-center py-4">No citizen traffic violation reports registered.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Report ID</th>
                        <th>Reporter</th>
                        <th>Violation Type</th>
                        <th>Photo Proof</th>
                        <th>Coordinates</th>
                        <th>Filed Date</th>
                        <th>Status</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($traffic_list as $tr): ?>
                        <tr>
                          <td><strong>#<?php echo $tr['id']; ?></strong></td>
                          <td class="small"><?php echo htmlspecialchars($tr['email']); ?></td>
                          <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($tr['violation_type'] ?? 'General Violation'); ?></span></td>
                          <td>
                            <a href="<?php echo htmlspecialchars($tr['photo_path']); ?>" target="_blank">
                              <img src="<?php echo htmlspecialchars($tr['photo_path']); ?>" class="img-thumbnail" style="width: 70px; height: 70px; object-fit: cover;">
                            </a>
                          </td>
                          <td>
                             <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $tr['latitude']; ?>,<?php echo $tr['longitude']; ?>" target="_blank" class="map-coordinates-link">
                               <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo $tr['latitude']; ?>, <?php echo $tr['longitude']; ?>
                             </a>
                           </td>
                          <td class="small"><?php echo date('d-M-y H:i', strtotime($tr['timestamp'])); ?></td>
                          <td>
                            <?php if ($tr['status'] === 'Pending'): ?>
                              <span class="badge badge-pending">Pending</span>
                            <?php elseif ($tr['status'] === 'Approved'): ?>
                              <span class="badge badge-approved">Approved</span>
                            <?php else: ?>
                              <span class="badge badge-rejected">Rejected</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($tr['status'] === 'Pending'): ?>
                              <div class="d-flex gap-1">
                                <form action="" method="POST">
                                  <input type="hidden" name="report_id" value="<?php echo $tr['id']; ?>">
                                  <button type="submit" name="approve_traffic" class="btn btn-sm btn-success px-2 py-1"><i class="bi bi-check-lg"></i> Appr</button>
                                </form>
                                <form action="" method="POST">
                                  <input type="hidden" name="report_id" value="<?php echo $tr['id']; ?>">
                                  <button type="submit" name="reject_traffic" class="btn btn-sm btn-danger px-2 py-1"><i class="bi bi-x-lg"></i> Rej</button>
                                </form>
                              </div>
                            <?php else: ?>
                              <span class="text-muted small">Processed</span>
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

        <!-- Tab: Potholes Status Update -->
        <?php elseif ($active_tab === 'potholes'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-wrench-adjustable-circle text-success me-2"></i>Update Road Damage / Potholes Reports</div>
            <div class="card-body">
              <?php if (empty($pothole_list)): ?>
                <p class="text-muted text-center py-4">No pothole reports filed by citizens.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Photo</th>
                        <th>Reporter / Description</th>
                        <th>Location</th>
                        <th>Community Votes</th>
                        <th>Timeline Status</th>
                        <th>Update Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($pothole_list as $pr): 
                        $up = intval($pr['upvotes']);
                        $down = intval($pr['downvotes']);
                        $net = $up - $down;
                      ?>
                        <tr>
                          <td>
                            <a href="<?php echo htmlspecialchars($pr['photo_path']); ?>" target="_blank">
                              <img src="<?php echo htmlspecialchars($pr['photo_path']); ?>" class="img-thumbnail" style="width: 80px; height: 80px; object-fit: cover;">
                            </a>
                          </td>
                          <td>
                            <span class="small text-muted font-monospace d-block"><?php echo htmlspecialchars($pr['email']); ?></span>
                            <span class="small text-dark fw-semibold"><?php echo htmlspecialchars($pr['description']); ?></span>
                          </td>
                          <td>
                             <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $pr['latitude']; ?>,<?php echo $pr['longitude']; ?>" target="_blank" class="map-coordinates-link">
                               <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo $pr['latitude']; ?>, <?php echo $pr['longitude']; ?>
                             </a>
                           </td>
                          <td>
                            <div class="d-flex align-items-center gap-2 mb-1">
                              <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-hand-thumbs-up-fill me-1"></i><?php echo $up; ?></span>
                              <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="bi bi-hand-thumbs-down-fill me-1"></i><?php echo $down; ?></span>
                            </div>
                            <?php if ($net >= 3): ?>
                              <span class="badge bg-success text-white" style="font-size: 0.68rem;"><i class="bi bi-patch-check-fill me-1"></i>High Priority (Genuine)</span>
                            <?php elseif ($net < 0): ?>
                              <span class="badge bg-warning text-dark" style="font-size: 0.68rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i>Flagged Inaccurate</span>
                            <?php else: ?>
                              <span class="badge bg-secondary text-white" style="font-size: 0.68rem;">Pending Verification</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($pr['status'] === 'Reported'): ?>
                              <span class="badge bg-secondary text-white">Reported</span>
                            <?php elseif ($pr['status'] === 'Acknowledged'): ?>
                              <span class="badge badge-info">Acknowledged</span>
                            <?php elseif ($pr['status'] === 'In Progress'): ?>
                              <span class="badge badge-pending">In Progress</span>
                            <?php else: ?>
                              <span class="badge badge-approved">Resolved</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <form action="" method="POST" class="d-flex gap-1 align-items-center">
                              <input type="hidden" name="id" value="<?php echo $pr['id']; ?>">
                              <select name="status" class="form-select form-select-sm" style="width: 130px;">
                                <option value="Reported" <?php echo ($pr['status'] === 'Reported') ? 'selected' : ''; ?>>Reported</option>
                                <option value="Acknowledged" <?php echo ($pr['status'] === 'Acknowledged') ? 'selected' : ''; ?>>Acknowledged</option>
                                <option value="In Progress" <?php echo ($pr['status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Resolved" <?php echo ($pr['status'] === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                              </select>
                              <button type="submit" name="update_pothole" class="btn btn-sm btn-primary py-1"><i class="bi bi-arrow-right-short"></i></button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

        <!-- Tab: Waste Collection -->
        <?php elseif ($active_tab === 'waste'): ?>
          <div class="row">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle-fill text-success me-2"></i>Add Waste Pickup schedule</div>
                <div class="card-body">
                  <form action="" method="POST">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Select Ward</label>
                      <select name="ward_id" class="form-select" required>
                        <?php foreach ($wards as $w): ?>
                          <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Day of Week</label>
                      <select name="day_of_week" class="form-select" required>
                        <option>Monday</option>
                        <option>Tuesday</option>
                        <option>Wednesday</option>
                        <option>Thursday</option>
                        <option>Friday</option>
                        <option>Saturday</option>
                        <option>Sunday</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Collection Time</label>
                      <input type="time" name="collection_time" class="form-control" required>
                    </div>
                    <button type="submit" name="add_waste_schedule" class="btn btn-primary w-100">Add Collection Time</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><i class="bi bi-calendar-check text-success me-2"></i>Active Collection Times</div>
                <div class="card-body">
                  <?php if (empty($waste_list)): ?>
                    <p class="text-muted text-center py-3">No waste schedules created.</p>
                  <?php else: ?>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                      <table class="table table-hover align-middle">
                        <thead>
                          <tr>
                            <th>Ward</th>
                            <th>Day</th>
                            <th>Time</th>
                            <th>Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($waste_list as $wl): ?>
                            <tr>
                              <td><strong><?php echo htmlspecialchars($wl['ward_name']); ?></strong></td>
                              <td><?php echo $wl['day_of_week']; ?></td>
                              <td class="font-monospace small"><?php echo date('h:i A', strtotime($wl['collection_time'])); ?></td>
                              <td>
                                <form action="" method="POST" onsubmit="return confirm('Remove waste schedule?');">
                                  <input type="hidden" name="id" value="<?php echo $wl['id']; ?>">
                                  <button type="submit" name="delete_waste" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

        <!-- Tab: AQI Readings -->
        <?php elseif ($active_tab === 'aqi'): ?>
          <div class="row">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle-fill text-success me-2"></i>Log Ward AQI & CO2 Metrics</div>
                <div class="card-body">
                  <form action="" method="POST">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Target Ward</label>
                      <select name="ward_id" class="form-select" required>
                        <?php foreach ($wards as $w): ?>
                          <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Air Quality Index (AQI) Value</label>
                      <input type="number" name="aqi_value" class="form-control" placeholder="E.g., 42 (Good), 88 (Mod), 125 (Poor)" min="0" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">CO2 Concentration (ppm)</label>
                      <input type="number" name="co2_value" class="form-control" placeholder="E.g., 410, 450 (Optional)" min="0">
                    </div>
                    <button type="submit" name="add_aqi" class="btn btn-primary w-100">Log Readings</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><i class="bi bi-card-list text-success me-2"></i>AQI Log (Latest 50 Entries)</div>
                <div class="card-body">
                  <?php if (empty($aqi_list)): ?>
                    <p class="text-muted text-center py-3">No metrics logged yet.</p>
                  <?php else: ?>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                      <table class="table table-hover align-middle">
                        <thead>
                          <tr>
                            <th>Ward</th>
                            <th>AQI</th>
                            <th>CO2</th>
                            <th>Logged At</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($aqi_list as $a): ?>
                            <tr>
                              <td><?php echo htmlspecialchars($a['ward_name']); ?></td>
                              <td>
                                <?php if ($a['aqi_value'] <= 50): ?>
                                  <span class="badge bg-success"><?php echo $a['aqi_value']; ?> (Good)</span>
                                <?php elseif ($a['aqi_value'] <= 100): ?>
                                  <span class="badge bg-warning text-dark"><?php echo $a['aqi_value']; ?> (Mod)</span>
                                <?php else: ?>
                                  <span class="badge bg-danger"><?php echo $a['aqi_value']; ?> (Poor)</span>
                                <?php endif; ?>
                              </td>
                              <td class="font-monospace"><?php echo $a['co2_value'] ? $a['co2_value'] . ' ppm' : '--'; ?></td>
                              <td class="small text-muted"><?php echo date('d-M H:i', strtotime($a['recorded_at'])); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

        <!-- Tab: News Portal -->
        <?php elseif ($active_tab === 'news'): ?>
          <div class="row">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header"><i class="bi bi-plus-circle-fill text-success me-2"></i>Publish News / Alert</div>
                <div class="card-body">
                  <form action="" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                      <label class="form-label fw-bold">Title</label>
                      <input type="text" name="title" class="form-control" placeholder="Enter article title" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Category</label>
                      <select name="category" class="form-select" required>
                        <option>General</option>
                        <option>Outages</option>
                        <option>Alerts</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Ward Association (Optional)</label>
                      <select name="ward_id" class="form-select">
                        <option value="">Global (All Wards)</option>
                        <?php foreach ($wards as $w): ?>
                          <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Optional Photo</label>
                      <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <div class="mb-3 form-check form-switch">
                      <input type="checkbox" name="is_emergency" id="is_emergency" class="form-check-input">
                      <label for="is_emergency" class="form-check-label fw-bold text-danger">Highlight as Emergency Alert</label>
                    </div>
                    <div class="mb-3">
                      <label class="form-label fw-bold">Article content</label>
                      <textarea name="content" rows="4" class="form-control" placeholder="Write article content details here..." required></textarea>
                    </div>
                    <button type="submit" name="add_news" class="btn btn-primary w-100">Publish News</button>
                  </form>
                </div>
              </div>
            </div>
            
            <div class="col-lg-7">
              <div class="card">
                <div class="card-header"><i class="bi bi-file-text text-success me-2"></i>Published News Articles</div>
                <div class="card-body">
                  <?php if (empty($news_list)): ?>
                    <p class="text-muted text-center py-3">No news published yet.</p>
                  <?php else: ?>
                    <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                      <table class="table table-hover align-middle">
                        <thead>
                          <tr>
                            <th>Category & Title</th>
                            <th>Ward</th>
                            <th>Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($news_list as $post): ?>
                            <tr class="<?php echo $post['is_emergency'] ? 'table-danger' : ''; ?>">
                              <td>
                                <span class="badge bg-light text-secondary"><?php echo htmlspecialchars($post['category']); ?></span>
                                <?php if ($post['is_emergency']): ?>
                                  <span class="badge bg-danger small font-monospace text-uppercase">Emergency</span>
                                <?php endif; ?><br>
                                <strong><?php echo htmlspecialchars($post['title']); ?></strong>
                              </td>
                              <td class="small"><?php echo $post['ward_name'] ? htmlspecialchars($post['ward_name']) : 'Global'; ?></td>
                              <td>
                                <form action="" method="POST" onsubmit="return confirm('Delete article?');">
                                  <input type="hidden" name="id" value="<?php echo $post['id']; ?>">
                                  <button type="submit" name="delete_news" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

        <!-- Tab: Utility & Waste Complaints Log -->
        <?php elseif ($active_tab === 'complaints'): ?>
          <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-square-fill text-success me-2"></i>Power & Water Complaints</div>
            <div class="card-body">
              <?php if (empty($utility_complaints_list)): ?>
                <p class="text-muted text-center py-3">No power/water complaints reported.</p>
              <?php else: ?>
                <div class="table-responsive mb-4">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Reporter</th>
                        <th>Type</th>
                        <th>Issue Category</th>
                        <th>Ward Details</th>
                        <th>Description / Photo</th>
                        <th>Created At</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($utility_complaints_list as $uc): ?>
                        <tr>
                          <td class="small"><?php echo htmlspecialchars($uc['email']); ?></td>
                          <td>
                            <?php if ($uc['type'] === 'loadshedding'): ?>
                              <span class="badge badge-pending">Power</span>
                            <?php else: ?>
                              <span class="badge badge-info">Water</span>
                            <?php endif; ?>
                          </td>
                          <td><span class="font-monospace small"><?php echo htmlspecialchars($uc['complaint_type']); ?></span></td>
                          <td><?php echo htmlspecialchars($uc['ward_name']); ?></td>
                          <td>
                            <span class="small text-secondary d-block"><?php echo htmlspecialchars($uc['details'] ?: 'No description provided.'); ?></span>
                            <?php if ($uc['photo_path']): ?>
                              <a href="<?php echo htmlspecialchars($uc['photo_path']); ?>" target="_blank" class="small text-success">View Photo Proof</a>
                            <?php endif; ?>
                          </td>
                          <td class="small text-muted"><?php echo date('d-M H:i', strtotime($uc['created_at'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="card">
            <div class="card-header"><i class="bi bi-trash-fill text-success me-2"></i>Waste & Dump Complaints</div>
            <div class="card-body">
              <?php if (empty($waste_complaints_list)): ?>
                <p class="text-muted text-center py-3">No waste complaints reported.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Reporter</th>
                        <th>Issue</th>
                        <th>Ward</th>
                        <th>Details & Photo</th>
                        <th>Created At</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($waste_complaints_list as $wc): ?>
                        <tr>
                          <td class="small"><?php echo htmlspecialchars($wc['email']); ?></td>
                          <td><span class="badge badge-pending"><?php echo htmlspecialchars($wc['complaint_type']); ?></span></td>
                          <td><?php echo htmlspecialchars($wc['ward_name']); ?></td>
                          <td>
                            <span class="small text-secondary d-block"><?php echo htmlspecialchars($wc['details'] ?: 'No details.'); ?></span>
                            <?php if ($wc['photo_path']): ?>
                              <a href="<?php echo htmlspecialchars($wc['photo_path']); ?>" target="_blank" class="small text-success">View Photo Proof</a>
                            <?php endif; ?>
                          </td>
                          <td class="small text-muted"><?php echo date('d-M H:i', strtotime($wc['created_at'])); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($active_tab === 'eco_tasks'): 
          // Fetch pending claims
          $pending_claims = $conn->query("SELECT c.*, u.email FROM eco_task_claims c JOIN users u ON c.user_id = u.id WHERE c.status = 'Pending' ORDER BY c.submitted_at ASC")->fetchAll();
          
          // Fetch recently approved/rejected log
          $recent_claims = $conn->query("SELECT c.*, u.email FROM eco_task_claims c JOIN users u ON c.user_id = u.id WHERE c.status != 'Pending' ORDER BY c.submitted_at DESC LIMIT 15")->fetchAll();
        ?>
          <div class="card mb-4">
            <div class="card-header bg-transparent border-0 pb-0">
              <h5 class="fw-bold text-success mb-1"><i class="bi bi-calendar-check-fill me-2"></i>Citizen Eco-Action Verification Queue</h5>
              <p class="text-muted small">Verify photos uploaded by citizens proving they completed their Daily Eco Challenge. Approving credits their wallet and XP balance.</p>
            </div>
            <div class="card-body">
              <?php if (empty($pending_claims)): ?>
                <div class="text-center py-5 border rounded-4 bg-light shadow-sm">
                  <i class="bi bi-clipboard-check text-success fs-1 mb-2"></i>
                  <h5 class="fw-bold text-dark">Queue Clear!</h5>
                  <p class="text-muted small mb-0">There are no pending citizen eco actions waiting for approval right now.</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle border rounded shadow-sm overflow-hidden">
                    <thead class="table-light text-secondary">
                      <tr>
                        <th>Citizen</th>
                        <th>Challenge Action</th>
                        <th>Proof Photo</th>
                        <th>Citizen Description</th>
                        <th>Rewards Details</th>
                        <th>Submitted At</th>
                        <th class="text-end">Verification Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($pending_claims as $claim): ?>
                        <tr>
                          <td>
                            <strong class="text-dark d-block"><?php echo htmlspecialchars($claim['email']); ?></strong>
                            <span class="text-muted small">User ID: #<?php echo $claim['user_id']; ?></span>
                          </td>
                          <td>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 rounded-pill fw-semibold small">
                              <?php echo htmlspecialchars($claim['task_name']); ?>
                            </span>
                          </td>
                          <td>
                            <a href="<?php echo htmlspecialchars($claim['photo_path']); ?>" target="_blank" title="View Full Size">
                              <img src="<?php echo htmlspecialchars($claim['photo_path']); ?>" alt="Proof" class="rounded border shadow-sm" style="width: 70px; height: 70px; object-fit: cover; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                            </a>
                          </td>
                          <td>
                            <p class="small text-secondary mb-0" style="max-width: 250px; line-height: 1.5; white-space: normal;"><?php echo htmlspecialchars($claim['description']); ?></p>
                          </td>
                          <td class="small fw-semibold text-nowrap">
                            <span class="text-success"><i class="bi bi-star-fill text-warning me-1"></i>+<?php echo $claim['points_reward']; ?> XP</span><br>
                            <span class="text-primary"><i class="bi bi-cash-coin me-1"></i>+₹<?php echo number_format($claim['cash_reward'], 2); ?></span>
                          </td>
                          <td class="small text-muted text-nowrap"><?php echo date('d M Y, H:i', strtotime($claim['submitted_at'])); ?></td>
                          <td class="text-end text-nowrap">
                            <form action="" method="POST" class="d-inline">
                              <input type="hidden" name="claim_id" value="<?php echo $claim['id']; ?>">
                              <button type="submit" name="approve_eco_task" class="btn btn-sm btn-success rounded-pill px-3 me-1 fw-semibold shadow-sm">
                                <i class="bi bi-check-circle-fill me-1"></i>Approve & Pay
                              </button>
                            </form>
                            <form action="" method="POST" class="d-inline">
                              <input type="hidden" name="claim_id" value="<?php echo $claim['id']; ?>">
                              <button type="submit" name="reject_eco_task" class="btn btn-sm btn-danger rounded-pill px-3 fw-semibold shadow-sm">
                                <i class="bi bi-x-circle-fill me-1"></i>Reject
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Recently Processed Logs -->
          <div class="card">
            <div class="card-header bg-transparent border-0 pb-0">
              <h6 class="fw-bold text-secondary mb-1"><i class="bi bi-clock-history me-2"></i>Recently Verified Actions Log</h6>
            </div>
            <div class="card-body">
              <?php if (empty($recent_claims)): ?>
                <p class="text-muted small text-center py-3">No eco actions have been processed yet.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle text-muted small">
                    <thead>
                      <tr>
                        <th>Date</th>
                        <th>Citizen</th>
                        <th>Action Challenge</th>
                        <th>Status</th>
                        <th>Reward</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($recent_claims as $claim): 
                        $status_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill">Approved</span>';
                        if ($claim['status'] === 'Rejected') {
                            $status_badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 rounded-pill">Rejected</span>';
                        }
                      ?>
                        <tr>
                          <td><?php echo date('d M H:i', strtotime($claim['submitted_at'])); ?></td>
                          <td><?php echo htmlspecialchars($claim['email']); ?></td>
                          <td><strong class="text-secondary"><?php echo htmlspecialchars($claim['task_name']); ?></strong></td>
                          <td><?php echo $status_badge; ?></td>
                          <td>+₹<?php echo number_format($claim['cash_reward'], 2); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

    </main>
  </div>

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
    <a href="dashboard.php" class="mobile-nav-item">
      <i class="bi bi-grid-fill"></i>
      <span>Services</span>
    </a>
    <button type="button" class="mobile-nav-item mobile-nav-fab" data-bs-toggle="modal" data-bs-target="#quickCameraModal" title="Quick Camera Upload & Scanner">
      <i class="bi bi-camera-fill"></i>
    </button>
    <a href="dashboard.php?tab=potholes" class="mobile-nav-item">
      <i class="bi bi-tools"></i>
      <span>Report</span>
    </a>
    <a href="<?php echo $is_logged_in ? 'dashboard.php?tab=wallet' : 'login.php'; ?>" class="mobile-nav-item">
      <i class="bi bi-person-circle"></i>
      <span><?php echo $is_logged_in ? 'Wallet' : 'Sign In'; ?></span>
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
