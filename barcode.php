<?php
// barcode.php - Interactive Food CO2 Impact Scanner & Gamification Portal
require_once 'config.php';

$is_logged_in = is_logged_in();
$user = null;
$user_points = 0;
$user_level = 1;
$user_wallet = 0.00;
$user_scans_count = 0;
$user_co2_saved = 0.0;
$user_scans = [];
$today_points = 0;

// Badges list definition
$badges = [
    ['title' => 'Eco Cadet', 'description' => 'Logged your first carbon scan', 'icon' => '🚀', 'earned' => false],
    ['title' => 'Bisleri Recycler', 'description' => 'Scanned a Bisleri bottle', 'icon' => '💧', 'earned' => false],
    ['title' => 'Carbon Crusader', 'description' => 'Successfully logged 5 scans', 'icon' => '🛡️', 'earned' => false]
];

$redemption_success = '';
$redemption_error = '';

// Handle Point Redemption POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_points'])) {
    if ($is_logged_in) {
        $user = get_logged_in_user($conn);
        $current_pts = (int)($user['eco_points'] ?? 0);
        if ($current_pts >= 50) {
            $pts_to_redeem = 50;
            $cash_reward = 10.00; // 50 Points = ₹10.00
            
            // Deduct points and credit wallet balance
            $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points - ?, wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$pts_to_redeem, $cash_reward, $_SESSION['user_id']]);
            
            // Log transaction
            $desc = "Eco Redemption: Converted 50 XP to ₹10.00 Cash";
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
            $stmt->execute([$_SESSION['user_id'], $cash_reward, $desc]);
            
            $redemption_success = "Success! Converted 50 Eco XP into ₹10.00 Wallet Cash!";
        } else {
            $redemption_error = "You need at least 50 Eco Points to redeem ₹10.00 Cash. Keep scanning products!";
        }
    } else {
        $redemption_error = "Please sign in to redeem Eco Points!";
    }
}

if ($is_logged_in) {
    $user = get_logged_in_user($conn);
    if ($user) {
        $user_points = (int)($user['eco_points'] ?? 0);
        $user_wallet = (float)($user['wallet_balance'] ?? 0.00);
        $user_level = floor($user_points / 50) + 1;
        
        // Fetch today's points earned
        $stmt = $conn->prepare("SELECT COALESCE(SUM(points_earned), 0) FROM co2_user_scans WHERE user_id = ? AND DATE(scanned_at) = CURDATE()");
        $stmt->execute([$user['id']]);
        $today_points = intval($stmt->fetchColumn());

        // Fetch total scans and total CO2 saved
        $stmt = $conn->prepare("SELECT COUNT(*) as total_scans, SUM(p.co2_impact) as total_co2_impact 
                                FROM co2_user_scans s 
                                JOIN co2_products p ON s.product_id = p.id 
                                WHERE s.user_id = ?");
        $stmt->execute([$user['id']]);
        $stats = $stmt->fetch();
        $user_scans_count = (int)$stats['total_scans'];
        $user_co2_saved = (float)($stats['total_co2_impact'] ?? 0.0);
        
        // Fetch recent 5 scans
        $stmt = $conn->prepare("SELECT s.scanned_at, s.points_earned, p.name, p.brand, p.weight, p.co2_impact 
                                FROM co2_user_scans s 
                                JOIN co2_products p ON s.product_id = p.id 
                                WHERE s.user_id = ? 
                                ORDER BY s.scanned_at DESC LIMIT 5");
        $stmt->execute([$user['id']]);
        $user_scans = $stmt->fetchAll();
        
        // Evaluate badges
        if ($user_scans_count >= 1) {
            $badges[0]['earned'] = true;
        }
        
        // Check if Bisleri was scanned
        $stmt = $conn->prepare("SELECT COUNT(*) FROM co2_user_scans s 
                                JOIN co2_products p ON s.product_id = p.id 
                                WHERE s.user_id = ? AND p.name LIKE '%Bisleri%'");
        $stmt->execute([$user['id']]);
        $bisleri_scanned = (int)$stmt->fetchColumn() > 0;
        if ($bisleri_scanned) {
            $badges[1]['earned'] = true;
        }
        
        // 5 scans badge
        if ($user_scans_count >= 5) {
            $badges[2]['earned'] = true;
        }
    }
}

// Fetch leaderboard (top 5 users by eco_points)
$stmt = $conn->query("SELECT email, eco_points FROM users ORDER BY eco_points DESC, id ASC LIMIT 5");
$leaderboard = $stmt->fetchAll();

// Mask emails for privacy
if (!function_exists('mask_email')) {
    function mask_email($email) {
        $parts = explode('@', $email);
        if (count($parts) < 2) return $email;
        $name = $parts[0];
        $domain = $parts[1];
        $masked_name = substr($name, 0, 3) . str_repeat('*', max(0, strlen($name) - 3));
        return $masked_name . '@' . $domain;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EcoScan - Gamified CO2 Tracker</title>
  
  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <!-- Custom Styles -->
  <link href="style.css" rel="stylesheet" />
  <!-- QuaggaJS for barcode scanning -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
  <!-- Confetti for badge unlock celebrations -->
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
  <!-- Three.js for 3D Virtual Eco Tree -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

  <style>
    body {
      background-color: var(--bg-app);
      font-family: var(--font-body);
      color: var(--text-main);
    }
    
    .scanner-view-container {
      position: relative;
    }
    
    #interactive.viewport {
      position: relative;
      width: 100%;
      height: 320px;
      overflow: hidden;
      border: 3px dashed var(--primary);
      border-radius: 16px;
      background: #000;
    }
    
    #interactive.viewport > video, #interactive.viewport > canvas {
      max-width: 100%;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    
    canvas.drawing, canvas.drawingBuffer {
      position: absolute;
      left: 0;
      top: 0;
    }
    
    .gamified-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      box-shadow: var(--shadow-soft);
      backdrop-filter: var(--glass-blur);
      transition: all 0.3s ease;
    }
    
    .gamified-card:hover {
      box-shadow: var(--shadow-hover);
      transform: translateY(-2px);
    }
    
    .level-badge {
      font-family: var(--font-headings);
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      border-radius: 50%;
      width: 60px;
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      font-weight: 800;
      box-shadow: 0 4px 10px rgba(34, 123, 73, 0.2);
    }
    
    .badge-icon-lg {
      font-size: 2.5rem;
      transition: transform 0.3s ease;
    }
    
    .badge-box {
      border: 2px solid var(--border-color);
      border-radius: 16px;
      padding: 12px;
      background: rgba(255, 255, 255, 0.5);
      transition: all 0.3s ease;
    }
    
    .badge-box.earned {
      border-color: var(--secondary);
      background: var(--secondary-light);
    }
    
    .badge-box.locked {
      opacity: 0.5;
    }
    
    .leaderboard-row {
      border-radius: 12px;
      padding: 10px 15px;
      margin-bottom: 8px;
      background: var(--input-bg);
      border: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      transition: transform 0.2s ease;
    }
    
    .leaderboard-row.current-user {
      background: var(--primary-light);
      border-color: var(--primary);
      font-weight: 600;
    }
    
    .leaderboard-rank {
      font-weight: 800;
      font-size: 1.1rem;
      width: 30px;
    }
    
    .toast-container-custom {
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 1060;
    }
    
    /* Celebration modal style */
    .badge-popup-content {
      animation: zoomIn 0.5s ease-out;
    }
    @keyframes zoomIn {
      from { transform: scale(0.7); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }
  </style>

  <script>
    // Theme setup
    (function() {
      const savedTheme = localStorage.getItem('theme') || 'light';
      document.documentElement.setAttribute('data-theme', savedTheme);
    })();
  </script>
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

  <div class="container py-4 py-md-5">
    
    <?php if (!empty($redemption_success)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($redemption_success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if (!empty($redemption_error)): ?>
      <div class="alert alert-warning alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($redemption_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <div class="row g-4">
      
      <!-- LEFT COLUMN: Scanner interface -->
      <div class="col-lg-7">
        <div class="card gamified-card mb-4">
          <div class="card-header bg-transparent border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
            <div>
              <h3 class="fw-bold mb-0 text-success"><i class="bi bi-qr-code-scan me-2"></i>CO2 Impact Scanner</h3>
              <p class="text-muted small mb-0">Scan barcodes to analyze environmental footprints and win awards</p>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-semibold">
              <i class="bi bi-cash-coin me-1"></i>₹5.00 Per Scan
            </span>
          </div>
          
          <div class="card-body px-4 pb-4">
            <!-- Tabs -->
            <ul class="nav nav-pills mb-3 bg-light p-1 rounded-pill" id="scannerTab" role="tablist">
              <li class="nav-item flex-grow-1 text-center" role="presentation">
                <button class="nav-link active w-100 rounded-pill py-2" id="camera-tab" data-bs-toggle="tab" data-bs-target="#camera-pane" type="button" role="tab">
                  <i class="bi bi-camera me-1"></i><span class="d-none d-sm-inline">Live </span>Camera
                </button>
              </li>
              <li class="nav-item flex-grow-1 text-center" role="presentation">
                <button class="nav-link w-100 rounded-pill py-2" id="upload-tab" data-bs-toggle="tab" data-bs-target="#upload-pane" type="button" role="tab">
                  <i class="bi bi-upload me-1"></i>Upload<span class="d-none d-sm-inline"> Photo</span>
                </button>
              </li>
            </ul>
            
            <div class="tab-content mb-3" id="scannerTabContent">
              <!-- Live Camera -->
              <div class="tab-pane fade show active" id="camera-pane" role="tabpanel">
                <div class="scanner-view-container">
                  <div id="interactive" class="viewport shadow-sm rounded-4"></div>
                </div>
                <button id="start-button" class="btn btn-success w-100 mt-3 rounded-pill py-2 fw-semibold">
                  <i class="bi bi-play-fill me-1"></i>Start Camera Scanner
                </button>
              </div>
              
              <!-- Image Upload -->
              <div class="tab-pane fade" id="upload-pane" role="tabpanel">
                <div class="mb-3">
                  <label for="image-upload" class="form-label fw-bold">Select product image containing a barcode</label>
                  <input class="form-control" type="file" id="image-upload" accept="image/*">
                </div>
                <div id="upload-preview-container" class="text-center mb-3 d-none bg-light p-3 rounded-4 border">
                  <img id="upload-preview" class="img-fluid rounded shadow-sm" style="max-height: 220px;" alt="Barcode Preview">
                </div>
                <button id="scan-image-button" class="btn btn-success w-100 rounded-pill py-2 fw-semibold">
                  <i class="bi bi-search me-1"></i>Scan Uploaded Photo
                </button>
              </div>
            </div>

            <!-- Loader / Results -->
            <div id="result-container" class="d-none mt-4">
              <div class="d-flex align-items-center justify-content-center py-3 d-none" id="loading-spinner">
                <div class="spinner-border text-success me-2" role="status"></div>
                <span class="fw-semibold">Querying Eco-Database...</span>
              </div>
              
              <div id="scan-success-msg" class="alert alert-success d-none rounded-4 mb-3 border border-success-subtle shadow-sm animate__animated animate__bounceIn">
                <div class="d-flex align-items-center">
                  <i class="bi bi-trophy-fill text-warning fs-3 me-3"></i>
                  <div>
                    <h6 class="alert-heading fw-bold mb-0">Product Logged Successfully!</h6>
                    <p class="mb-0 small text-success-emphasis" id="rewards-text"></p>
                  </div>
                </div>
              </div>
              
              <div class="row g-3">
                <!-- Product Details -->
                <div class="col-md-6">
                  <div class="p-3 border rounded-4 bg-light shadow-sm h-100">
                    <h5 class="fw-bold text-success border-bottom pb-2 mb-2"><i class="bi bi-box-seam me-2"></i>Product Info</h5>
                    <div id="product-info" class="small">
                      <!-- Filled via JS -->
                    </div>
                  </div>
                </div>
                <!-- Carbon Metrics -->
                <div class="col-md-6">
                  <div class="p-3 border rounded-4 bg-light shadow-sm h-100">
                    <h5 class="fw-bold text-danger border-bottom pb-2 mb-2"><i class="bi bi-clouds-fill me-2"></i>CO2 Impact</h5>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <span class="text-muted small">Carbon Footprint:</span>
                      <span id="co2-value" class="fw-bold fs-5">-</span>
                    </div>
                    <div class="progress mt-2 mb-2" style="height: 10px; border-radius: 5px;">
                      <div id="co2-bar" class="progress-bar bg-success" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <p id="co2-comparison" class="text-muted small fst-italic mb-0"></p>
                  </div>
                </div>
              </div>
            </div>
            
            <div id="barcode-result" class="mt-3 text-muted text-center small fw-semibold"></div>
          </div>
        </div>

        <!-- Tester Seeding Drawer -->
        <div class="card gamified-card">
          <div class="card-header bg-transparent border-0 pt-4 px-4">
            <h5 class="fw-bold mb-0 text-secondary"><i class="bi bi-terminal me-2"></i>Demo & Quick Testing</h5>
            <p class="text-muted small mb-0">Don't have a physical bottle or camera? Click below to simulate scanning:</p>
          </div>
          <div class="card-body px-4 pb-4">
            <div class="d-flex flex-nowrap overflow-x-auto gap-2 pb-2" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
              <button class="btn btn-outline-success btn-sm rounded-pill px-3 py-2 fw-semibold text-nowrap" onclick="simulateBarcode('8901152010118')">
                💧<span class="d-none d-sm-inline"> Scan</span> Bisleri<span class="d-none d-sm-inline"> 100ml (Barcode: 8901152010118)</span>
              </button>
              <button class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2 fw-semibold text-nowrap" onclick="simulateBarcode('8901491101850')">
                🥤<span class="d-none d-sm-inline"> Scan</span> Pepsi<span class="d-none d-sm-inline"> 500ml (Barcode: 8901491101850)</span>
              </button>
              <button class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-semibold text-nowrap" onclick="simulateBarcode('8901234567890')">
                🛍️<span class="d-none d-sm-inline"> Scan</span> Jute Bag<span class="d-none d-sm-inline"> (Barcode: 8901234567890)</span>
              </button>
              <button class="btn btn-outline-warning btn-sm rounded-pill px-3 py-2 fw-semibold text-nowrap" onclick="simulateBarcode('8901765432109')">
                🥫<span class="d-none d-sm-inline"> Scan</span> Coca Cola<span class="d-none d-sm-inline"> Can (Barcode: 8901765432109)</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: Gamification Dashboard -->
      <div class="col-lg-5">
        
        <!-- Interactive Gamified Eco Plant & Blooming Flowers ("Tamagotchi Climate Plant") -->
        <div class="card gamified-card mb-4 overflow-hidden position-relative border-0 shadow">
          <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0 d-flex justify-content-between align-items-center">
            <div>
              <h5 class="fw-bold mb-0 text-success"><i class="bi bi-flower1 me-2"></i>Interactive Eco Plant & Flowers 🌸</h5>
              <p class="text-muted small mb-0">Complete tasks & scans to grow lush leaves & blooming flowers!</p>
            </div>
            <span class="badge <?php echo ($today_points > 0) ? 'bg-success' : 'bg-warning text-dark'; ?> px-3 py-2 rounded-pill fw-bold">
              <?php echo ($today_points > 0) ? '🌸 Plant Blooming & Thriving' : '🥀 Plant Needs Water & Care'; ?>
            </span>
          </div>

          <div class="card-body p-3 text-center">
            <!-- 3D Plant Canvas & Vector Layer -->
            <div id="tree-3d-canvas-container" class="position-relative rounded-4 overflow-hidden border shadow-inner mb-3" style="height: 240px; background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%); cursor: grab;">
              <!-- Interactive Canvas Layer -->
              <canvas id="tree-canvas" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 2;"></canvas>

              <!-- Instant Vector 3D Plant & Blooming Flowers Base -->
              <div id="tree-svg-fallback" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="z-index: 1; pointer-events: none;">
                <svg viewBox="0 0 300 240" width="100%" height="100%" style="max-height: 240px;">
                  <defs>
                    <linearGradient id="potGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                      <stop offset="0%" stop-color="#c2410c" />
                      <stop offset="50%" stop-color="#ea580c" />
                      <stop offset="100%" stop-color="#9a3412" />
                    </linearGradient>
                    <linearGradient id="stemGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                      <stop offset="0%" stop-color="<?php echo ($today_points > 0) ? '#34d399' : '#d97706'; ?>" />
                      <stop offset="100%" stop-color="<?php echo ($today_points > 0) ? '#059669' : '#78350f'; ?>" />
                    </linearGradient>
                    <radialGradient id="flowerPetalGrad" cx="50%" cy="50%" r="50%">
                      <stop offset="0%" stop-color="#f472b6" />
                      <stop offset="70%" stop-color="#ec4899" />
                      <stop offset="100%" stop-color="#be185d" />
                    </radialGradient>
                  </defs>

                  <!-- Pot Shadow & Terracotta Pot -->
                  <ellipse cx="150" cy="205" rx="55" ry="12" fill="rgba(0,0,0,0.15)" />
                  <path d="M 115 160 L 185 160 L 175 205 L 125 205 Z" fill="url(#potGrad)" stroke="#7c2d12" stroke-width="2" />
                  <rect x="110" y="152" width="80" height="12" rx="4" fill="#ea580c" stroke="#7c2d12" stroke-width="2" />
                  <!-- Soil Bed -->
                  <ellipse cx="150" cy="154" rx="35" ry="6" fill="#451a03" />

                  <!-- Main Plant Stem -->
                  <path d="M 150 154 Q 145 110 150 70" fill="none" stroke="url(#stemGrad)" stroke-width="8" stroke-linecap="round" />

                  <!-- Branch Stems -->
                  <path d="M 148 120 Q 120 100 110 95" fill="none" stroke="url(#stemGrad)" stroke-width="5" stroke-linecap="round" />
                  <path d="M 150 110 Q 180 90 190 85" fill="none" stroke="url(#stemGrad)" stroke-width="5" stroke-linecap="round" />

                  <!-- Green Leaves -->
                  <path d="M 110 95 Q 90 80 105 75 Q 120 85 110 95 Z" fill="<?php echo ($today_points > 0) ? '#10b981' : '#b45309'; ?>" stroke="#065f46" stroke-width="1.5" />
                  <path d="M 190 85 Q 210 70 195 65 Q 180 75 190 85 Z" fill="<?php echo ($today_points > 0) ? '#10b981' : '#b45309'; ?>" stroke="#065f46" stroke-width="1.5" />
                  <path d="M 150 70 Q 130 50 145 45 Q 160 55 150 70 Z" fill="<?php echo ($today_points > 0) ? '#34d399' : '#d97706'; ?>" stroke="#047857" stroke-width="1.5" />

                  <!-- Blooming Flowers (Level 2+) -->
                  <?php if ($today_points > 0): ?>
                    <!-- Top Lotus Blossom -->
                    <g transform="translate(150, 45)">
                      <circle cx="-12" cy="0" r="10" fill="url(#flowerPetalGrad)" />
                      <circle cx="12" cy="0" r="10" fill="url(#flowerPetalGrad)" />
                      <circle cx="0" cy="-12" r="10" fill="url(#flowerPetalGrad)" />
                      <circle cx="0" cy="12" r="10" fill="url(#flowerPetalGrad)" />
                      <circle cx="0" cy="0" r="8" fill="#fbbf24" stroke="#d97706" stroke-width="1.5" />
                    </g>

                    <!-- Left Flower Blossom -->
                    <g transform="translate(100, 75)">
                      <circle cx="-8" cy="0" r="7" fill="#f472b6" />
                      <circle cx="8" cy="0" r="7" fill="#f472b6" />
                      <circle cx="0" cy="-8" r="7" fill="#f472b6" />
                      <circle cx="0" cy="8" r="7" fill="#f472b6" />
                      <circle cx="0" cy="0" r="5" fill="#fef08a" />
                    </g>

                    <!-- Right Flower Blossom -->
                    <g transform="translate(200, 65)">
                      <circle cx="-8" cy="0" r="7" fill="#f472b6" />
                      <circle cx="8" cy="0" r="7" fill="#f472b6" />
                      <circle cx="0" cy="-8" r="7" fill="#f472b6" />
                      <circle cx="0" cy="8" r="7" fill="#f472b6" />
                      <circle cx="0" cy="0" r="5" fill="#fef08a" />
                    </g>
                  <?php endif; ?>

                  <!-- Sparkles -->
                  <circle cx="110" cy="40" r="3" fill="#34d399" opacity="0.9" />
                  <circle cx="190" cy="45" r="4" fill="#fbbf24" opacity="0.9" />
                  <circle cx="150" cy="20" r="3" fill="#ec4899" opacity="0.8" />
                </svg>
              </div>

              <div class="position-absolute bottom-0 start-0 w-100 p-2 bg-dark bg-opacity-40 backdrop-blur text-white small font-monospace" style="font-size: 0.72rem; z-index: 3;">
                <i class="bi bi-arrows-move me-1"></i>Interactive 3D View • Drag to Rotate Plant
              </div>
            </div>

            <!-- Health Status Banner -->
            <?php if ($today_points > 0): ?>
              <div class="alert alert-success py-2 px-3 mb-2 small rounded-3 border-0 d-flex align-items-center justify-content-between">
                <span><i class="bi bi-patch-check-fill text-success me-1"></i>Tree is <strong>Cherishing & Thriving!</strong></span>
                <span class="badge bg-success font-monospace">+<?php echo $today_points; ?> XP Today</span>
              </div>
            <?php else: ?>
              <div class="alert alert-warning py-2 px-3 mb-2 small rounded-3 border-0 d-flex align-items-center justify-content-between">
                <span><i class="bi bi-exclamation-triangle-fill me-1"></i>Tree is <strong>Wilting (0 XP Today)!</strong></span>
                <span class="badge bg-warning text-dark font-monospace">Log Scan to Nourish</span>
              </div>
            <?php endif; ?>

            <div class="d-flex justify-content-center gap-2">
              <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-semibold" id="btn-water-tree">
                💧 Water Tree
              </button>
              <button type="button" class="btn btn-success btn-sm rounded-pill px-3 fw-semibold" onclick="document.getElementById('scannerTab').scrollIntoView({behavior: 'smooth'});">
                🍃 Scan to Nourish
              </button>
            </div>
          </div>
        </div>

        <!-- User Status & Levels -->
        <div class="card gamified-card mb-4 text-center">
          <div class="card-body p-4">
            <?php if ($is_logged_in): ?>
              <div class="d-flex justify-content-center mb-3">
                <div class="level-badge" id="hud-level"><?php echo $user_level; ?></div>
              </div>
              <h4 class="fw-bold mb-1" id="hud-user-email"><?php echo htmlspecialchars($user['email']); ?></h4>
              <p class="text-success small fw-bold mb-3"><i class="bi bi-shield-fill-check me-1"></i>Eco Rank: Level <?php echo $user_level; ?> Citizen</p>
              
              <!-- Progress to next level -->
              <?php 
                $points_progress = $user_points % 50;
                $points_percentage = ($points_progress / 50) * 100;
              ?>
              <div class="mb-4">
                <div class="d-flex justify-content-between small text-muted mb-1">
                  <span>Level Progress (<?php echo $points_progress; ?> / 50 XP)</span>
                  <span>Next Level: XP <?php echo (50 - $points_progress); ?> more</span>
                </div>
                <div class="progress" style="height: 12px; border-radius: 6px; background-color: var(--border-color);">
                  <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" id="hud-xp-bar" role="progressbar" style="width: <?php echo $points_percentage; ?>%"></div>
                </div>
              </div>
              
              <!-- Core Stats -->
              <div class="row g-2 text-center border-top pt-3 mb-3">
                <div class="col-4 border-end">
                  <h3 class="fw-extrabold text-success mb-0" id="hud-points"><?php echo $user_points; ?></h3>
                  <span class="text-muted small">Eco Points</span>
                </div>
                <div class="col-4 border-end">
                  <h3 class="fw-extrabold text-primary mb-0" id="hud-scans"><?php echo $user_scans_count; ?></h3>
                  <span class="text-muted small">Total Scans</span>
                </div>
                <div class="col-4">
                  <h3 class="fw-extrabold text-danger mb-0" id="hud-co2"><?php echo number_format($user_co2_saved, 2); ?> kg</h3>
                  <span class="text-muted small">CO2 Saved</span>
                </div>
              </div>

              <!-- Redeem Points to Cash Form -->
              <div class="p-3 rounded-4 border border-success-subtle bg-success bg-opacity-10 text-start mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <h6 class="fw-bold text-success mb-0"><i class="bi bi-arrow-repeat me-1"></i>Redeem Points to Wallet Cash</h6>
                  <span class="badge bg-success">50 XP = ₹10.00</span>
                </div>
                <p class="text-muted small mb-2" style="font-size: 0.78rem;">Convert your earned eco points directly into civic wallet cash balance.</p>
                <form action="" method="POST">
                  <button type="submit" name="redeem_points" class="btn btn-success btn-sm w-100 rounded-pill fw-semibold py-2" <?php echo ($user_points < 50) ? 'disabled' : ''; ?>>
                    <i class="bi bi-cash-stack me-1"></i>Redeem 50 XP for ₹10.00 Cash
                  </button>
                </form>
              </div>

              <!-- Refer & Earn Card -->
              <div class="p-3 rounded-4 border border-primary-subtle bg-primary bg-opacity-10 text-start">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <h6 class="fw-bold text-primary mb-0"><i class="bi bi-gift-fill me-1"></i>Refer & Earn Cash</h6>
                  <span class="badge bg-primary">Earn ₹50.00</span>
                </div>
                <p class="text-muted small mb-2" style="font-size: 0.78rem;">Share your code with friends to get ₹50 cash in your wallet per referral!</p>
                <div class="input-group input-group-sm">
                  <input type="text" class="form-control font-monospace bg-white border-primary-subtle" readonly value="NMC-REF-<?php echo $user['id']; ?>" id="barcodeReferralCode">
                  <button class="btn btn-primary fw-semibold" type="button" onclick="navigator.clipboard.writeText(document.getElementById('barcodeReferralCode').value); alert('Referral Code Copied!');">Copy Code</button>
                </div>
              </div>
            <?php else: ?>
              <div class="py-4">
                <i class="bi bi-shield-lock-fill text-muted fs-1 mb-3"></i>
                <h4 class="fw-bold mb-2">Guest Mode Active</h4>
                <p class="text-muted small px-3">Log in to track your carbon footprint, secure Cash Rewards, and compete on the Nagpur leaderboard!</p>
                <a href="login.php?redirect=barcode.php" class="btn btn-success rounded-pill px-4 py-2 mt-2 fw-semibold">
                  <i class="bi bi-box-arrow-in-right me-1"></i>Sign In Now
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Leaderboard -->
        <div class="card gamified-card mb-4">
          <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold mb-1 text-success"><i class="bi bi-trophy me-2"></i>Nagpur Eco-Leaderboard</h5>
            <p class="text-muted small">Top climate champions ranked by Eco Points</p>
          </div>
          <div class="card-body px-4 pb-4 pt-2">
            <div id="leaderboard-container">
              <?php foreach ($leaderboard as $index => $row): 
                $masked = mask_email($row['email']);
                $is_current = $is_logged_in && ($row['email'] === $user['email']);
              ?>
                <div class="leaderboard-row <?php echo $is_current ? 'current-user' : ''; ?>">
                  <div class="d-flex align-items-center">
                    <span class="leaderboard-rank text-success">
                      <?php 
                        if ($index === 0) echo '🥇';
                        elseif ($index === 1) echo '🥈';
                        elseif ($index === 2) echo '🥉';
                        else echo '#'.($index + 1);
                      ?>
                    </span>
                    <span class="small"><?php echo htmlspecialchars($masked); ?></span>
                  </div>
                  <span class="badge bg-success rounded-pill px-3"><?php echo $row['eco_points']; ?> XP</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Badges & Achievements -->
        <div class="card gamified-card mb-4">
          <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold mb-1 text-success"><i class="bi bi-award-fill me-2"></i>Eco Milestones & Badges</h5>
            <p class="text-muted small">Complete carbon challenges to unlock unique collector badges</p>
          </div>
          <div class="card-body px-4 pb-4">
            <div class="horizontal-slider" id="badges-shelf">
              <?php foreach ($badges as $b): ?>
                <div class="slider-card">
                  <div class="badge-box d-flex flex-column align-items-center text-center gap-2 h-100 p-4 <?php echo $b['earned'] ? 'earned' : 'locked'; ?>" style="border-radius: 20px;">
                    <span class="badge-icon-lg" style="font-size: 3.5rem; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));"><?php echo $b['icon']; ?></span>
                    <div class="mt-2">
                      <h6 class="fw-bold mb-1 d-flex align-items-center justify-content-center gap-1">
                        <?php echo $b['title']; ?>
                        <?php if (!$b['earned']): ?>
                          <i class="bi bi-lock-fill text-muted small"></i>
                        <?php else: ?>
                          <i class="bi bi-patch-check-fill text-success small"></i>
                        <?php endif; ?>
                      </h6>
                      <p class="text-muted small mb-0" style="font-size: 0.8rem; line-height: 1.4;"><?php echo $b['description']; ?></p>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Scan History -->
        <div class="card gamified-card">
          <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold mb-1 text-success"><i class="bi bi-clock-history me-2"></i>Recent Scans</h5>
            <p class="text-muted small">Your last 5 scans in the database</p>
          </div>
          <div class="card-body px-4 pb-4">
            <ul class="list-group list-group-flush border-0" id="scan-history">
              <?php if (empty($user_scans)): ?>
                <li class="list-group-item bg-transparent text-muted text-center py-4 border-0 small">No scans logged yet. Simulate or scan a product to start!</li>
              <?php else: ?>
                <?php foreach ($user_scans as $scan): ?>
                  <li class="list-group-item bg-transparent border-0 border-bottom px-0 py-3">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h6 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($scan['name']); ?></h6>
                        <span class="text-muted small d-block"><?php echo htmlspecialchars($scan['brand']); ?> • <?php echo htmlspecialchars($scan['weight']); ?></span>
                      </div>
                      <div class="text-end">
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill mb-1 d-inline-block small"><?php echo $scan['co2_impact']; ?> kg CO2e</span>
                        <span class="text-success small d-block fw-bold">+<?php echo $scan['points_earned']; ?> XP</span>
                      </div>
                    </div>
                    <span class="text-muted small" style="font-size: 0.75rem;"><?php echo date('d M Y, H:i', strtotime($scan['scanned_at'])); ?></span>
                  </li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>
        </div>

      </div>
      
    </div>
  </div>

  <!-- Scanned Product Carbon Impact & Recycling Popup Modal -->
  <div class="modal fade" id="productScanModal" tabindex="-1" aria-labelledby="productScanModalLabel" aria-hidden="true" style="backdrop-filter: blur(4px);">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
        <div class="modal-header border-0 bg-success text-white py-3 px-4">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-box-seam-fill fs-4"></i>
            <div>
              <h5 class="modal-title fw-bold mb-0" id="scanModalTitle">Scanned Product Details</h5>
              <span class="small opacity-75">Nagpur Smart Municipal Eco-Database</span>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body p-4">
          <!-- Rewards Banner -->
          <div id="modalRewardBanner" class="alert alert-success border-0 rounded-4 d-flex align-items-center gap-3 p-3 mb-4 shadow-sm" style="background: linear-gradient(135deg, #dcfce7, #bbf7d0);">
            <div class="bg-success text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 46px; height: 46px; flex-shrink: 0;">
              <i class="bi bi-trophy-fill fs-5"></i>
            </div>
            <div>
              <h6 class="fw-bold text-success-emphasis mb-1" id="modalRewardHeading">Scan Logged & Reward Credited! 🎉</h6>
              <p class="mb-0 small text-success-emphasis" id="modalRewardDesc">Earned +15 Eco Points & ₹5.00 cash reward added to your civic wallet.</p>
            </div>
          </div>

          <div class="row g-4 mb-3">
            <!-- Product Specs -->
            <div class="col-md-6">
              <div class="p-3 rounded-4 bg-light border h-100">
                <span class="badge bg-secondary mb-2" id="modalCategoryBadge">Plastic Bottle</span>
                <h4 class="fw-bold text-dark mb-1" id="modalProductName">Bisleri Water 100ml</h4>
                <p class="text-muted small mb-2"><i class="bi bi-tag-fill me-1 text-secondary"></i>Brand: <strong id="modalProductBrand" class="text-dark">Bisleri International</strong></p>
                <p class="text-muted small mb-2"><i class="bi bi-aspect-ratio me-1 text-secondary"></i>Weight/Volume: <strong id="modalProductWeight" class="text-dark">100 ML</strong></p>
                <p class="text-muted small mb-0"><i class="bi bi-upc-scan me-1 text-secondary"></i>Barcode: <span id="modalProductBarcode" class="font-monospace text-dark">8901152010118</span></p>
              </div>
            </div>

            <!-- CO2 Footprint Score -->
            <div class="col-md-6">
              <div class="p-3 rounded-4 bg-light border h-100 d-flex flex-column justify-content-between">
                <div>
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-danger mb-0"><i class="bi bi-clouds-fill me-1"></i>Carbon Footprint</h6>
                    <span id="modalCo2Value" class="fw-extrabold fs-4 text-success">0.12 kg CO2e</span>
                  </div>
                  <div class="progress mb-2" style="height: 10px; border-radius: 5px;">
                    <div id="modalCo2Bar" class="progress-bar bg-success" role="progressbar" style="width: 12%;"></div>
                  </div>
                </div>
                <p class="text-muted small fst-italic mb-0 border-top pt-2" id="modalCo2Comparison">
                  Scanning this plastic bottle earns points. Make sure to dispose of it in a recycling bin!
                </p>
              </div>
            </div>
          </div>

          <!-- Step-by-Step Recycling Guide -->
          <div class="rounded-4 border border-success-subtle p-3 p-md-4" style="background: rgba(220, 252, 231, 0.3);">
            <h5 class="fw-bold text-success mb-2 d-flex align-items-center gap-2">
              <i class="bi bi-recycle fs-4"></i>How to Recycle This Item Safely & Earn Extra Points
            </h5>
            <p class="text-muted small mb-3">Follow these municipal eco steps to maximize recycling efficiency and keep Nagpur clean:</p>
            
            <div class="row g-2" id="modalRecyclingSteps">
              <div class="col-sm-6 col-md-3">
                <div class="p-3 bg-white rounded-3 border h-100">
                  <div class="fw-bold text-success mb-1">Step 1: Empty & Rinse</div>
                  <p class="text-muted small mb-0">Ensure the container is completely empty and rinsed clean of liquid.</p>
                </div>
              </div>
              <div class="col-sm-6 col-md-3">
                <div class="p-3 bg-white rounded-3 border h-100">
                  <div class="fw-bold text-success mb-1">Step 2: Flatten / Crush</div>
                  <p class="text-muted small mb-0">Crush or flatten bottle/can to reduce storage volume by 70%.</p>
                </div>
              </div>
              <div class="col-sm-6 col-md-3">
                <div class="p-3 bg-white rounded-3 border h-100">
                  <div class="fw-bold text-success mb-1">Step 3: Separate Cap</div>
                  <p class="text-muted small mb-0">Keep bottle cap attached or dispose metal caps in dry waste.</p>
                </div>
              </div>
              <div class="col-sm-6 col-md-3">
                <div class="p-3 bg-white rounded-3 border h-100">
                  <div class="fw-bold text-success mb-1">Step 4: NMC Green Bin</div>
                  <p class="text-muted small mb-0">Deposit in nearest Green Smart Collection Bin for bonus points!</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer border-0 bg-light py-3 px-4 d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
          <a href="dashboard.php?tab=wallet" class="btn btn-success rounded-pill px-4 fw-semibold"><i class="bi bi-wallet2 me-1"></i>View Wallet & Cash</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Badge Celebration Modal (Confetti popup) -->
  <div class="modal fade" id="badgeUnlockModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(5px);">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content badge-popup-content rounded-4 border-0 shadow-lg text-center" style="background: linear-gradient(135deg, #ffffff, #f0fdf4);">
        <div class="modal-body p-5">
          <div class="display-1 mb-3" id="unlock-badge-icon">🏆</div>
          <h2 class="fw-bold text-success mb-2" id="unlock-badge-title">Achievement Unlocked!</h2>
          <h4 class="fw-semibold mb-3 text-dark" id="unlock-badge-name">Eco Cadet</h4>
          <p class="text-muted px-3" id="unlock-badge-desc">You logged your very first carbon footprint scan!</p>
          <button type="button" class="btn btn-success rounded-pill px-5 py-2 mt-3 fw-bold" data-bs-dismiss="modal">AWESOME!</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS Bundle -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.bundle.min.js"></script>
  <script src="scanner.js"></script>

  <!-- Interactive Simulator logic -->
  <script>
    function simulateBarcode(code) {
      console.log("Simulating scan for barcode: " + code);
      const spinner = document.getElementById('loading-spinner');
      const resultContainer = document.getElementById('result-container');
      
      resultContainer.classList.remove('d-none');
      spinner.classList.remove('d-none');
      
      // Call the global function handles in scanner.js
      if (window.fetchProductInfo) {
        window.fetchProductInfo(code);
      } else {
        // fallback if scanner.js hasn't set up the handler yet
        fetch('api_scanner.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ barcode: code })
        })
        .then(res => res.json())
        .then(data => {
          spinner.classList.add('d-none');
          alert("Simulation successful: " + JSON.stringify(data));
        })
        .catch(err => {
          spinner.classList.add('d-none');
          alert("Simulation failed: " + err);
        });
      }
    }
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

  <!-- 3D Virtual Eco Plant & Blooming Flowers Perspective Canvas Engine -->
  <script>
    (function() {
      function init3DTree() {
        const container = document.getElementById("tree-3d-canvas-container");
        const canvas = document.getElementById("tree-canvas");
        if (!container || !canvas) return;

        const ctx = canvas.getContext("2d");
        if (!ctx) return;

        const isThriving = <?php echo (isset($today_points) && $today_points > 0) ? 'true' : 'false'; ?>;
        const userLevel = <?php echo (int)($user_level ?? 1); ?>;

        let width = 0;
        let height = 0;
        let rotationY = 0;
        let isDragging = false;
        let startX = 0;
        let waterBounce = 0;
        let waterDrops = [];

        function resize() {
          width = container.clientWidth || 300;
          height = container.clientHeight || 240;
          const dpr = Math.min(window.devicePixelRatio || 1, 2);
          canvas.width = width * dpr;
          canvas.height = height * dpr;
          ctx.scale(dpr, dpr);
        }
        resize();
        window.addEventListener("resize", resize);

        // Floating particles (Flower Pollen for Thriving, Falling leaves for Wilting)
        const particles = [];
        for (let i = 0; i < 35; i++) {
          particles.push({
            x: (Math.random() - 0.5) * 140,
            y: Math.random() * 120 - 60,
            z: (Math.random() - 0.5) * 140,
            speedY: isThriving ? (0.4 + Math.random() * 0.6) : (-0.3 - Math.random() * 0.4),
            radius: Math.random() * 3 + 1.5,
            color: isThriving ? ['#f472b6', '#34d399', '#fbbf24'][Math.floor(Math.random() * 3)] : '#d97706',
            opacity: Math.random() * 0.8 + 0.2
          });
        }

        // 3D Plant Nodes (Leaves & Flowers) Definition based on User Level
        let plantNodes = [];
        if (userLevel === 1) {
          // Level 1: 2 young green leaves 🌱
          plantNodes = [
            { type: 'leaf', x: -20, y: -45, z: 10, r: 18 },
            { type: 'leaf', x: 22, y: -40, z: -10, r: 18 }
          ];
        } else if (userLevel === 2) {
          // Level 2: 4 leaves + 2 flower buds 🌸
          plantNodes = [
            { type: 'leaf', x: -28, y: -45, z: 15, r: 22 },
            { type: 'leaf', x: 28, y: -40, z: -15, r: 22 },
            { type: 'leaf', x: 0, y: -70, z: 0, r: 20 },
            { type: 'flower', x: -15, y: -80, z: 10, r: 16 },
            { type: 'flower', x: 18, y: -75, z: -10, r: 16 }
          ];
        } else if (userLevel === 3) {
          // Level 3: 8 leaves + 3 blooming Lotus flowers 🌸🌺
          plantNodes = [
            { type: 'leaf', x: -35, y: -40, z: 20, r: 26 },
            { type: 'leaf', x: 38, y: -38, z: -20, r: 26 },
            { type: 'leaf', x: -20, y: -65, z: -15, r: 24 },
            { type: 'leaf', x: 24, y: -60, z: 18, r: 24 },
            { type: 'flower', x: 0, y: -100, z: 0, r: 22 },
            { type: 'flower', x: -25, y: -85, z: 15, r: 18 },
            { type: 'flower', x: 25, y: -82, z: -15, r: 18 }
          ];
        } else {
          // Level 4+ Legendary Blooming Eco Garden 🌸🌺🌻✨
          plantNodes = [
            { type: 'leaf', x: -45, y: -40, z: 25, r: 30 },
            { type: 'leaf', x: 48, y: -38, z: -25, r: 30 },
            { type: 'leaf', x: -30, y: -70, z: -20, r: 26 },
            { type: 'leaf', x: 32, y: -68, z: 22, r: 26 },
            { type: 'leaf', x: 0, y: -50, z: 30, r: 24 },
            { type: 'flower', x: 0, y: -115, z: 0, r: 26 },
            { type: 'flower', x: -32, y: -95, z: 18, r: 22 },
            { type: 'flower', x: 35, y: -92, z: -18, r: 22 },
            { type: 'flower', x: 0, y: -80, z: -25, r: 20 }
          ];
        }

        // 3D Rotation Math & Perspective Projection
        function project3D(x, y, z, angle) {
          const cos = Math.cos(angle);
          const sin = Math.sin(angle);
          const rx = x * cos - z * sin;
          const rz = x * sin + z * cos;
          const focalLength = 320;
          const perspective = focalLength / (focalLength + rz + 180);
          const screenX = width / 2 + rx * perspective;
          const screenY = height / 2 + 55 + (y + Math.sin(waterBounce) * 10) * perspective;
          return { x: screenX, y: screenY, scale: perspective, zDepth: rz };
        }

        function render() {
          ctx.clearRect(0, 0, width, height);

          // 1. Background Gradient
          const bgGrad = ctx.createLinearGradient(0, 0, 0, height);
          if (isThriving) {
            bgGrad.addColorStop(0, '#ecfdf5');
            bgGrad.addColorStop(1, '#a7f3d0');
          } else {
            bgGrad.addColorStop(0, '#fffbeb');
            bgGrad.addColorStop(1, '#fde68a');
          }
          ctx.fillStyle = bgGrad;
          ctx.fillRect(0, 0, width, height);

          // 2. 3D Terracotta Plant Pot Base
          const potBotP = project3D(0, 45, 0, rotationY);
          const potTopP = project3D(0, 10, 0, rotationY);

          // Pot Rim
          ctx.beginPath();
          ctx.ellipse(potTopP.x, potTopP.y, 48 * potTopP.scale, 14 * potTopP.scale, 0, 0, Math.PI * 2);
          ctx.fillStyle = '#ea580c';
          ctx.fill();
          ctx.lineWidth = 2;
          ctx.strokeStyle = '#7c2d12';
          ctx.stroke();

          // Pot Body
          ctx.beginPath();
          ctx.moveTo(potTopP.x - 44 * potTopP.scale, potTopP.y);
          ctx.lineTo(potTopP.x + 44 * potTopP.scale, potTopP.y);
          ctx.lineTo(potBotP.x + 30 * potBotP.scale, potBotP.y);
          ctx.lineTo(potBotP.x - 30 * potBotP.scale, potBotP.y);
          ctx.closePath();

          const potGrad = ctx.createLinearGradient(potTopP.x - 40, 0, potTopP.x + 40, 0);
          potGrad.addColorStop(0, '#c2410c');
          potGrad.addColorStop(0.5, '#ea580c');
          potGrad.addColorStop(1, '#9a3412');
          ctx.fillStyle = potGrad;
          ctx.fill();
          ctx.stroke();

          // Soil Bed inside Pot
          ctx.beginPath();
          ctx.ellipse(potTopP.x, potTopP.y - 2, 42 * potTopP.scale, 11 * potTopP.scale, 0, 0, Math.PI * 2);
          ctx.fillStyle = '#451a03';
          ctx.fill();

          // 3. Main Curved Green Plant Stem
          const stemTopP = project3D(0, -95, 0, rotationY);
          const stemMidP = project3D(5, -40, 0, rotationY);
          const stemBotP = project3D(0, 8, 0, rotationY);

          ctx.beginPath();
          ctx.moveTo(stemBotP.x, stemBotP.y);
          ctx.quadraticCurveTo(stemMidP.x, stemMidP.y, stemTopP.x, stemTopP.y);
          ctx.lineWidth = 7 * stemBotP.scale;
          ctx.strokeStyle = isThriving ? '#059669' : '#d97706';
          ctx.lineCap = 'round';
          ctx.stroke();

          // 4. Project & Z-Sort Leaves & Flowers
          const projectedNodes = plantNodes.map(node => {
            const p = project3D(node.x, node.y, node.z, rotationY);
            return { ...node, px: p.x, py: p.y, scale: p.scale, zDepth: p.zDepth };
          });
          projectedNodes.sort((a, b) => a.zDepth - b.zDepth);

          // Render Leaves & Flowers
          projectedNodes.forEach(node => {
            const r = node.r * node.scale;

            if (node.type === 'leaf') {
              // Draw 3D Emerald Green Leaf
              ctx.beginPath();
              ctx.ellipse(node.px, node.py, r * 1.3, r * 0.7, Math.PI / 4, 0, Math.PI * 2);
              const leafGrad = ctx.createRadialGradient(node.px - r * 0.3, node.py - r * 0.3, 2, node.px, node.py, r * 1.3);
              if (isThriving) {
                leafGrad.addColorStop(0, '#34d399');
                leafGrad.addColorStop(0.7, '#10b981');
                leafGrad.addColorStop(1, '#047857');
              } else {
                leafGrad.addColorStop(0, '#fde047');
                leafGrad.addColorStop(0.7, '#d97706');
                leafGrad.addColorStop(1, '#78350f');
              }
              ctx.fillStyle = leafGrad;
              ctx.fill();
              ctx.lineWidth = 1.5;
              ctx.strokeStyle = isThriving ? '#065f46' : '#451a03';
              ctx.stroke();
            } else if (node.type === 'flower') {
              // Draw Blooming Flower Petals 🌸
              const petalCount = 5;
              for (let i = 0; i < petalCount; i++) {
                const angle = (i * 2 * Math.PI) / petalCount;
                const petX = node.px + Math.cos(angle) * r * 0.75;
                const petY = node.py + Math.sin(angle) * r * 0.75;
                ctx.beginPath();
                ctx.arc(petX, petY, r * 0.55, 0, Math.PI * 2);
                ctx.fillStyle = isThriving ? '#f472b6' : '#d97706';
                ctx.fill();
              }
              // Golden Pollen Center
              ctx.beginPath();
              ctx.arc(node.px, node.py, r * 0.45, 0, Math.PI * 2);
              ctx.fillStyle = '#fbbf24';
              ctx.fill();
              ctx.lineWidth = 1.5;
              ctx.strokeStyle = '#d97706';
              ctx.stroke();
            }
          });

          // 5. Render Floating Sparkle Particles
          particles.forEach(p => {
            p.y -= p.speedY;
            if (isThriving && p.y < -120) p.y = 30;
            if (!isThriving && p.y > 30) p.y = -120;

            const proj = project3D(p.x, p.y, p.z, rotationY);
            ctx.beginPath();
            ctx.arc(proj.x, proj.y, p.radius * proj.scale, 0, Math.PI * 2);
            ctx.fillStyle = p.color;
            ctx.fill();
          });

          // 6. Render Falling Water Drops on Water Action
          for (let i = waterDrops.length - 1; i >= 0; i--) {
            const drop = waterDrops[i];
            drop.y += drop.speed;
            ctx.beginPath();
            ctx.arc(drop.x, drop.y, drop.r, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(56, 189, 248, ${drop.opacity})`;
            ctx.fill();

            if (drop.y > height / 2 + 40) {
              waterDrops.splice(i, 1);
            }
          }

          // Smooth idle rotation
          if (!isDragging) {
            rotationY += 0.008;
          }

          if (waterBounce > 0) {
            waterBounce += 0.15;
            if (waterBounce > Math.PI * 2) waterBounce = 0;
          }

          requestAnimationFrame(render);
        }

        // Drag Events
        container.addEventListener("mousedown", e => { isDragging = true; startX = e.clientX; });
        window.addEventListener("mouseup", () => { isDragging = false; });
        container.addEventListener("mousemove", e => {
          if (!isDragging) return;
          const dx = e.clientX - startX;
          rotationY += dx * 0.015;
          startX = e.clientX;
        });

        container.addEventListener("touchstart", e => {
          if (e.touches.length === 1) { isDragging = true; startX = e.touches[0].clientX; }
        });
        window.addEventListener("touchend", () => { isDragging = false; });
        container.addEventListener("touchmove", e => {
          if (!isDragging || e.touches.length !== 1) return;
          const dx = e.touches[0].clientX - startX;
          rotationY += dx * 0.015;
          startX = e.touches[0].clientX;
        });

        // Water Plant Button Handler
        const waterBtn = document.getElementById("btn-water-tree");
        if (waterBtn) {
          waterBtn.addEventListener("click", function() {
            waterBounce = 0.01;
            waterBtn.innerHTML = "💧 Watering...";
            waterBtn.disabled = true;

            for (let i = 0; i < 20; i++) {
              waterDrops.push({
                x: width / 2 + (Math.random() - 0.5) * 120,
                y: 10 + Math.random() * 40,
                speed: 4 + Math.random() * 4,
                r: 3 + Math.random() * 2,
                opacity: 0.9
              });
            }

            setTimeout(() => {
              waterBtn.innerHTML = "💧 Watered!";
              setTimeout(() => {
                waterBtn.innerHTML = "💧 Water Plant";
                waterBtn.disabled = false;
              }, 1500);
            }, 1200);
          });
        }

        render();
      }

      if (document.readyState === "complete" || document.readyState === "interactive") {
        setTimeout(init3DTree, 50);
      } else {
  <!-- Ward Selection Modal -->
  <div class="modal fade" id="wardSelectModal" tabindex="-1" aria-labelledby="wardSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0 shadow">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold" id="wardSelectModalLabel"><i class="bi bi-geo-alt-fill text-success me-2"></i>Select Active Ward</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Choose your municipal zone to view local water timetables, power cuts, and environmental readings.</p>
          <form action="" method="POST">
            <div class="mb-3">
              <label class="form-label small fw-bold">Select Ward Zone</label>
              <select name="ward_id" class="form-select form-select-lg rounded-3" required>
                <option value="" disabled <?php echo !$active_ward_id ? 'selected' : ''; ?>>Select a Ward...</option>
                <?php foreach ($wards as $w): ?>
                  <option value="<?php echo $w['id']; ?>" <?php echo ($active_ward_id == $w['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($w['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="d-grid">
              <button type="submit" name="update_ward" class="btn btn-primary rounded-pill py-2 fw-semibold">Save Active Ward</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

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
    <a href="dashboard.php?tab=potholes" class="mobile-nav-item">
      <i class="bi bi-tools"></i>
      <span>Report</span>
    </a>
    <a href="profile.php" class="mobile-nav-item">
      <i class="bi bi-person-circle"></i>
      <span>Profile</span>
    </a>
  </nav>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
