<?php
// profile.php - Dedicated Citizen Profile, Ward Settings & Activity History Page
require_once __DIR__ . '/config.php';

$is_logged_in = is_logged_in();
$user = $is_logged_in ? get_logged_in_user($conn) : null;

$success_msg = '';
$error_msg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update Residential Ward Preference
    if (isset($_POST['update_ward'])) {
        $ward_id = intval($_POST['ward_id'] ?? 0);
        if ($ward_id > 0) {
            $_SESSION['active_ward_id'] = $ward_id;
            if ($is_logged_in) {
                $stmt = $conn->prepare("UPDATE users SET ward_id = ? WHERE id = ?");
                $stmt->execute([$ward_id, $user['id']]);
                $user = get_logged_in_user($conn); // Refresh user state
            } else {
                $_SESSION['guest_ward_id'] = $ward_id;
            }
            $success_msg = "Residential Ward updated successfully! Alerts and schedules updated.";
        }
    }
    
    // Update Personal Contact Information
    elseif (isset($_POST['update_profile']) && $is_logged_in) {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        $profile_pic = $user['profile_pic'] ?? null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $profile_pic = handle_file_upload($_FILES['profile_pic'], 'profile_');
        }
        
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, profile_pic = ? WHERE id = ?");
        $stmt->execute([$full_name, $phone, $address, $profile_pic, $user['id']]);
        $user = get_logged_in_user($conn);
        $success_msg = "Profile information and avatar updated successfully!";
    }
}

// Fetch all Wards for selector
$wards_stmt = $conn->query("SELECT * FROM wards ORDER BY name ASC");
$wards = $wards_stmt ? $wards_stmt->fetchAll() : [];

// Determine active ward
$active_ward_id = $_SESSION['active_ward_id'] ?? ($user['ward_id'] ?? ($_SESSION['guest_ward_id'] ?? null));
$active_ward_name = '';
if ($active_ward_id) {
    foreach ($wards as $w) {
        if ($w['id'] == $active_ward_id) {
            $active_ward_name = $w['name'];
            break;
        }
    }
}

// User Activity Statistics & Submissions
$pothole_reports = [];
$traffic_reports = [];
$co2_scans = [];
$reward_tx = [];
$eco_claims = [];

$user_level = 1;
$user_points = $user['eco_points'] ?? 0;
$user_wallet = $user['wallet_balance'] ?? 0.00;
$user_level = floor($user_points / 50) + 1;

if ($is_logged_in) {
    // Potholes
    $stmt = $conn->prepare("SELECT * FROM pothole_reports WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    $pothole_reports = $stmt->fetchAll();

    // Traffic Reports
    $stmt = $conn->prepare("SELECT * FROM traffic_reports WHERE user_id = ? ORDER BY timestamp DESC");
    $stmt->execute([$user['id']]);
    $traffic_reports = $stmt->fetchAll();

    // CO2 Scans
    $stmt = $conn->prepare("SELECT s.*, p.name as product_name, p.brand, p.co2_impact FROM co2_user_scans s JOIN co2_products p ON s.product_id = p.id WHERE s.user_id = ? ORDER BY s.scanned_at DESC");
    $stmt->execute([$user['id']]);
    $co2_scans = $stmt->fetchAll();

    // Reward Transactions
    $stmt = $conn->prepare("SELECT * FROM reward_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user['id']]);
    $reward_tx = $stmt->fetchAll();

    // Eco Task Claims
    $stmt = $conn->prepare("SELECT * FROM eco_task_claims WHERE user_id = ? ORDER BY submitted_at DESC");
    $stmt->execute([$user['id']]);
    $eco_claims = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Citizen Profile & Ward Settings - Smart Municipal Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="style.css">
  <style>
    .profile-hero {
      background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%);
      color: white;
      border-radius: 24px;
      padding: 2.5rem 1.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(5, 150, 105, 0.2);
    }
    .profile-avatar-circle {
      width: 90px;
      height: 90px;
      background: white;
      color: #047857;
      font-size: 2.5rem;
      font-weight: 800;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 8px 20px rgba(0,0,0,0.15);
      border: 4px solid rgba(255,255,255,0.3);
    }
    .stat-box-card {
      background: white;
      border-radius: 16px;
      padding: 1.25rem;
      border: 1px solid #e2e8f0;
      box-shadow: 0 4px 12px rgba(0,0,0,0.03);
      transition: transform 0.2s ease;
    }
    .stat-box-card:hover {
      transform: translateY(-2px);
    }
    .nav-pills-custom .nav-link {
      color: #475569;
      font-weight: 600;
      border-radius: 50px;
      padding: 0.6rem 1.25rem;
      transition: all 0.2s ease;
    }
    .nav-pills-custom .nav-link.active {
      background-color: #059669;
      color: white;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
    }
  </style>
</head>
<body>

  <!-- Top Navigation Bar -->
  <nav class="navbar navbar-expand-lg sticky-top bg-white border-bottom shadow-sm py-2">
    <div class="container">
      <!-- Left: Brand Logo & Title -->
      <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-success me-2" href="index.php">
        <div class="bg-success text-white rounded-3 p-1.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
          <i class="bi bi-building-fill-gear fs-5"></i>
        </div>
        <span class="fw-extrabold text-success" style="font-size: 1.15rem; letter-spacing: -0.5px;">NMC Portal</span>
      </a>

      <!-- Navigation Links (Desktop) -->
      <div class="d-none d-md-flex align-items-center gap-2 me-auto ms-2">
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

      <!-- Right Controls Group -->
      <div class="d-flex align-items-center gap-2">
        
        <!-- Wallet Balance Pill -->
        <a href="redeem.php" class="btn btn-success btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 shadow-sm d-flex align-items-center gap-1.5 text-nowrap">
          <i class="bi bi-wallet2"></i>
          <span>₹<?php echo number_format($user_wallet, 2); ?></span>
        </a>

        <!-- Dark/Light Theme Toggle Button -->
        <button id="theme-toggle" class="btn btn-outline-secondary btn-sm rounded-circle p-2" type="button" aria-label="Toggle Light/Dark Theme">
          <i class="bi bi-sun-fill d-none-theme-light text-warning fs-6"></i>
          <i class="bi bi-moon-stars-fill d-none-theme-dark text-primary fs-6"></i>
        </button>

        <!-- Profile Dropdown (If Logged In) / Sign In Button (If Guest) -->
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
          <a href="login.php" class="btn btn-success btn-sm rounded-pill px-3 py-1.5 font-monospace fw-bold shadow-sm">
            <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
          </a>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- Main Container -->
  <div class="container my-4">

    <?php if ($success_msg): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
      <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Citizen Profile Hero Banner Card -->
    <div class="profile-hero mb-4">
      <div class="row align-items-center g-3">
        <div class="col-auto">
          <?php if ($is_logged_in && !empty($user['profile_pic'])): ?>
            <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" class="profile-avatar-circle" style="object-fit: cover;" alt="Avatar">
          <?php else: ?>
            <div class="profile-avatar-circle">
              <?php echo $is_logged_in ? strtoupper(substr($user['email'], 0, 1)) : '?'; ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="col">
          <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-2">
            <i class="bi bi-shield-check text-warning"></i>
            <span class="small fw-bold text-white"><?php echo $is_logged_in ? 'Level ' . $user_level . ' Eco Citizen' : 'Guest Visitor'; ?></span>
          </div>
          <h2 class="fw-bold mb-1 text-white"><?php echo $is_logged_in ? htmlspecialchars(!empty($user['full_name']) ? $user['full_name'] : 'Nagpur Citizen') : 'Welcome, Guest Citizen'; ?></h2>
          <p class="mb-2 text-white opacity-90 small font-monospace"><i class="bi bi-envelope me-1"></i><?php echo $is_logged_in ? htmlspecialchars($user['email']) : 'Not signed in'; ?></p>
          
          <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-white bg-opacity-20 text-white border border-white border-opacity-25 px-3 py-1.5 rounded-pill font-monospace">
              <i class="bi bi-geo-alt-fill text-danger me-1"></i>Active Ward: <?php echo $active_ward_name ? htmlspecialchars($active_ward_name) : 'Not Set'; ?>
            </span>
            <span class="badge bg-warning text-dark px-3 py-1.5 rounded-pill font-monospace">
              <i class="bi bi-star-fill me-1"></i><?php echo $user_points; ?> Eco XP
            </span>
          </div>
        </div>
        <div class="col-12 col-md-auto text-md-end">
          <?php if ($is_logged_in): ?>
            <a href="logout.php" class="btn btn-outline-light rounded-pill px-4 py-2 font-monospace fw-bold">
              <i class="bi bi-box-arrow-right me-1"></i>Sign Out
            </a>
          <?php else: ?>
            <a href="login.php?redirect=profile.php" class="btn btn-light text-success rounded-pill px-4 py-2 font-monospace fw-bold shadow">
              <i class="bi bi-box-arrow-in-right me-1"></i>Sign In / Register
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Quick Activity & Stats Grid -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="stat-box-card text-center">
          <div class="bg-success bg-opacity-10 text-success rounded-circle p-2.5 d-inline-flex mb-2">
            <i class="bi bi-tools fs-4"></i>
          </div>
          <h3 class="fw-bold text-dark mb-0"><?php echo count($pothole_reports); ?></h3>
          <span class="small text-muted fw-semibold">Potholes Reported</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="stat-box-card text-center">
          <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-2.5 d-inline-flex mb-2">
            <i class="bi bi-camera-fill fs-4"></i>
          </div>
          <h3 class="fw-bold text-dark mb-0"><?php echo count($traffic_reports); ?></h3>
          <span class="small text-muted fw-semibold">Traffic Challans</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="stat-box-card text-center">
          <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-2.5 d-inline-flex mb-2">
            <i class="bi bi-qr-code-scan fs-4"></i>
          </div>
          <h3 class="fw-bold text-dark mb-0"><?php echo count($co2_scans); ?></h3>
          <span class="small text-muted fw-semibold">CO2 Scans</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="stat-box-card text-center">
          <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-2.5 d-inline-flex mb-2">
            <i class="bi bi-wallet2 fs-4"></i>
          </div>
          <h3 class="fw-bold text-success mb-0">₹<?php echo number_format($user_wallet, 2); ?></h3>
          <span class="small text-muted fw-semibold">Wallet Cash</span>
        </div>
      </div>
    </div>

    <!-- Navigation Pills Tabs -->
    <ul class="nav nav-pills nav-pills-custom gap-2 mb-4 overflow-x-auto pb-2" id="profileTabs" role="tablist" style="scrollbar-width: none;">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="ward-tab" data-bs-toggle="pill" data-bs-target="#ward-pane" type="button" role="tab"><i class="bi bi-geo-alt-fill me-1"></i>Ward Settings</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="personal-tab" data-bs-toggle="pill" data-bs-target="#personal-pane" type="button" role="tab"><i class="bi bi-person-fill me-1"></i>Personal Info</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="history-tab" data-bs-toggle="pill" data-bs-target="#history-pane" type="button" role="tab"><i class="bi bi-clock-history me-1"></i>Submissions Log</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="refer-tab" data-bs-toggle="pill" data-bs-target="#refer-pane" type="button" role="tab"><i class="bi bi-gift-fill me-1"></i>Refer & Earn</button>
      </li>
    </ul>

    <!-- Tab Contents -->
    <div class="tab-content" id="profileTabsContent">

      <!-- Tab 1: Ward Settings -->
      <div class="tab-pane fade show active" id="ward-pane" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4">
          <h5 class="fw-bold text-dark mb-1"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Active Residential Ward Zone</h5>
          <p class="text-muted small mb-4">Set or update your primary residential ward. Utility outage schedules, water supply timelines, and local civic announcements across all pages will automatically adapt to your chosen ward zone.</p>

          <form action="" method="POST" class="max-w-600">
            <div class="mb-3">
              <label for="ward_id" class="form-label fw-bold text-dark">Select Your Nagpur Residential Ward</label>
              <select name="ward_id" id="ward_id" class="form-select form-select-lg rounded-4 font-monospace fs-6" required>
                <option value="" disabled <?php echo !$active_ward_id ? 'selected' : ''; ?>>-- Choose Ward Zone --</option>
                <?php foreach ($wards as $w): ?>
                  <option value="<?php echo $w['id']; ?>" <?php echo ($active_ward_id == $w['id']) ? 'selected' : ''; ?>>
                    📍 <?php echo htmlspecialchars($w['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" name="update_ward" class="btn btn-success btn-lg rounded-pill px-4 fw-bold shadow-sm">
              <i class="bi bi-check-circle-fill me-1"></i>Save Active Ward Preference
            </button>
          </form>

          <!-- Portal Language Preference Card -->
          <div class="border-top pt-4 mt-4">
            <h5 class="fw-bold text-dark mb-1"><i class="bi bi-translate text-primary me-2"></i>Portal Language Preference (भाषा पसंद)</h5>
            <p class="text-muted small mb-3">Select your preferred portal language. Your chosen language will stay active across all pages, services, and mobile views.</p>

            <div class="d-flex flex-wrap gap-2.5" id="profile-lang-selector">
              <button type="button" class="btn btn-outline-primary rounded-pill px-4 py-2 font-monospace fw-bold lang-select-btn" data-lang="en">
                🇬🇧 English (EN)
              </button>
              <button type="button" class="btn btn-outline-success rounded-pill px-4 py-2 font-monospace fw-bold lang-select-btn" data-lang="hi">
                🇮🇳 हिंदी (HI)
              </button>
              <button type="button" class="btn btn-outline-warning text-dark rounded-pill px-4 py-2 font-monospace fw-bold lang-select-btn" data-lang="mr">
                🚩 मराठी (MR)
              </button>
            </div>
            <small class="text-muted mt-2 d-block"><i class="bi bi-shield-check text-success me-1"></i>Language preference automatically syncs across all pages & sessions.</small>
          </div>
        </div>
      </div>

      <!-- Tab 2: Personal Profile Details -->
      <div class="tab-pane fade" id="personal-pane" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4">
          <h5 class="fw-bold text-dark mb-1"><i class="bi bi-person-lines-fill text-success me-2"></i>Personal Profile & Contact Information</h5>
          <p class="text-muted small mb-4">Update your profile details to receive citizen reward notifications and ward updates.</p>

          <?php if (!$is_logged_in): ?>
            <div class="text-center py-4 text-muted">
              <i class="bi bi-shield-lock-fill text-warning fs-1 mb-2 d-block"></i>
              <p class="mb-2 fw-semibold">Sign in to manage your personal profile settings.</p>
              <a href="login.php?redirect=profile.php" class="btn btn-sm btn-success rounded-pill px-4 py-2">Sign In Now</a>
            </div>
          <?php else: ?>
            <form action="" method="POST" enctype="multipart/form-data" class="max-w-600">
              <div class="mb-3">
                <label class="form-label fw-bold text-dark">Email Address (Account ID)</label>
                <input type="email" class="form-control rounded-3 bg-light" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                <span class="small text-muted">Email address cannot be changed.</span>
              </div>

              <div class="mb-3">
                <label for="full_name" class="form-label fw-bold text-dark">Full Name (Citizen Name)</label>
                <input type="text" name="full_name" id="full_name" class="form-control rounded-3" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" placeholder="Enter your full name">
              </div>

              <div class="mb-3">
                <label for="phone" class="form-label fw-bold text-dark">Phone / Mobile Number</label>
                <input type="text" name="phone" id="phone" class="form-control rounded-3" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Enter 10-digit mobile number">
              </div>

              <div class="mb-3">
                <label for="profile_pic" class="form-label fw-bold text-dark">Profile Picture Avatar</label>
                <input type="file" name="profile_pic" id="profile_pic" class="form-control rounded-3" accept="image/*">
                <span class="small text-muted" style="font-size: 0.75rem;">Upload a custom photo to display on your Citizen ID badge.</span>
              </div>

              <div class="mb-4">
                <label for="address" class="form-label fw-bold text-dark">Residential Address</label>
                <textarea name="address" id="address" rows="3" class="form-control rounded-3" placeholder="Enter house no, street, locality in Nagpur..."><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
              </div>

              <button type="submit" name="update_profile" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                <i class="bi bi-save-fill me-1"></i>Update Profile Details
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tab 3: Activity Submissions Log -->
      <div class="tab-pane fade" id="history-pane" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4">
          <h5 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history text-primary me-2"></i>My Reported Civic Submissions</h5>

          <?php if (!$is_logged_in): ?>
            <div class="text-center py-4 text-muted">
              <i class="bi bi-box-arrow-in-right text-success fs-1 mb-2 d-block"></i>
              <p class="mb-2 fw-semibold">Sign in to view your submission history.</p>
              <a href="login.php?redirect=profile.php" class="btn btn-sm btn-success rounded-pill px-4 py-2">Sign In Now</a>
            </div>
          <?php else: ?>
            <!-- Pothole Reports -->
            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-tools text-purple me-1"></i>Pothole Reports (<?php echo count($pothole_reports); ?>)</h6>
            <?php if (empty($pothole_reports)): ?>
              <p class="text-muted small fst-italic mb-4">No pothole road damage reported yet.</p>
            <?php else: ?>
              <div class="row g-3 mb-4">
                <?php foreach ($pothole_reports as $ph): ?>
                  <div class="col-md-6">
                    <div class="card h-100 border rounded-4 overflow-hidden shadow-sm">
                      <div class="position-relative">
                        <img src="<?php echo htmlspecialchars($ph['photo_path']); ?>" style="height: 140px; object-fit: cover;" class="card-img-top w-100" alt="Pothole">
                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 text-white text-decoration-none px-2 py-1 rounded-pill">
                          <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo number_format($ph['latitude'], 3); ?>, <?php echo number_format($ph['longitude'], 3); ?>
                        </a>
                      </div>
                      <div class="p-3">
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill mb-1"><?php echo htmlspecialchars($ph['status']); ?></span>
                        <p class="small text-dark fw-bold mb-1 text-truncate"><?php echo htmlspecialchars($ph['description']); ?></p>
                        <span class="small text-muted d-block"><?php echo date('d M Y, h:i A', strtotime($ph['created_at'])); ?></span>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Traffic Reports -->
            <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-camera-fill text-danger me-1"></i>Traffic Violations Filed (<?php echo count($traffic_reports); ?>)</h6>
            <?php if (empty($traffic_reports)): ?>
              <p class="text-muted small fst-italic mb-4">No traffic violation challans filed yet.</p>
            <?php else: ?>
              <div class="table-responsive mb-4">
                <table class="table table-sm align-middle small border rounded-3">
                  <thead class="table-light fw-bold">
                    <tr>
                      <th>Violation</th>
                      <th>GPS Coordinates</th>
                      <th>Status</th>
                      <th>Reward</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($traffic_reports as $tr): ?>
                      <tr>
                        <td data-label="Violation"><strong><?php echo htmlspecialchars($tr['violation_type']); ?></strong></td>
                        <td data-label="GPS Location">
                          <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $tr['latitude']; ?>,<?php echo $tr['longitude']; ?>" target="_blank" class="text-danger text-decoration-none fw-semibold">
                            <i class="bi bi-geo-alt-fill me-1"></i><?php echo number_format($tr['latitude'], 3); ?>, <?php echo number_format($tr['longitude'], 3); ?> ↗
                          </a>
                        </td>
                        <td data-label="Status"><span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($tr['status']); ?></span></td>
                        <td data-label="Reward" class="fw-bold text-success"><?php echo $tr['reward_credited'] ? '+₹50.00' : '--'; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tab 4: Refer & Earn Program -->
      <div class="tab-pane fade" id="refer-pane" role="tabpanel">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden p-4 text-white" style="background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);">
          <div class="row align-items-center g-4">
            <div class="col-md-8">
              <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-20 px-3 py-1 rounded-pill mb-2 small fw-bold">
                <i class="bi bi-gift-fill text-warning"></i>
                <span>Citizen Referral Program</span>
              </div>
              <h3 class="fw-bold mb-2">Earn ₹50.00 Cash Per Friend 🤝</h3>
              <p class="mb-3 text-white-50 small">Invite friends & family in Nagpur to join the Smart Citizen Portal. When they register using your unique referral code, you automatically earn ₹50.00 cash directly into your citizen wallet!</p>
              
              <div class="input-group max-w-400">
                <input type="text" class="form-control font-monospace bg-white text-dark fw-bold border-0" readonly value="<?php echo $is_logged_in ? 'NMC-REF-' . $user['id'] : 'NMC-REF-CITIZEN'; ?>" id="profileRefCode">
                <button class="btn btn-warning fw-bold px-3" type="button" onclick="navigator.clipboard.writeText(document.getElementById('profileRefCode').value); alert('Referral Code Copied!');">
                  <i class="bi bi-copy me-1"></i>Copy Code
                </button>
              </div>
            </div>
            <div class="col-md-4 text-center">
              <div class="bg-white bg-opacity-10 p-4 rounded-4 border border-white border-opacity-20">
                <i class="bi bi-wallet2 display-3 text-warning mb-2 d-block"></i>
                <h5 class="fw-bold mb-1">Your Wallet Balance</h5>
                <h2 class="fw-extrabold text-white font-monospace mb-0">₹<?php echo number_format($user_wallet, 2); ?></h2>
                <a href="redeem.php" class="btn btn-light text-primary rounded-pill px-3 py-1.5 mt-3 fw-bold small">Redeem Cash / Products ↗</a>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>

  </div>

  <!-- Mobile Dock Bottom Spacer -->
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

  <!-- App-Style Mobile Bottom Navigation Dock -->
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
    <a href="redeem.php" class="mobile-nav-item">
      <i class="bi bi-gift-fill"></i>
      <span>Redeem</span>
    </a>
    <a href="profile.php" class="mobile-nav-item active">
      <i class="bi bi-person-circle"></i>
      <span>Profile</span>
    </a>
  </nav>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
      themeToggle.addEventListener('click', () => {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', newTheme);
        localStorage.setItem('theme', newTheme);
      });
    }
  </script>
</body>
</html>
