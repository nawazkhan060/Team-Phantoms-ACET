<?php
// index.php - Responsive, Dynamic, Premium Citizen Home Dashboard
require_once 'config.php';

$is_logged_in = is_logged_in();
$user = null;
$active_ward_name = '';
$active_ward_id = null;

// Handle Ward Switcher Post request dynamically
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ward'])) {
    $ward_id = intval($_POST['ward_id']);
    if ($is_logged_in) {
        $stmt = $conn->prepare("UPDATE users SET ward_id = ? WHERE id = ?");
        $stmt->execute([$ward_id, $_SESSION['user_id']]);
        $user = get_logged_in_user($conn);
        $active_ward_id = $user['ward_id'];
        $active_ward_name = $user['ward_name'];
    } else {
        $_SESSION['guest_ward_id'] = $ward_id;
        $active_ward_id = $ward_id;
    }
    $success = "Active ward updated successfully!";
}

// Vehicle Challan Search & Payment Logic
$searched_challan = null;
$challan_search_error = '';
$challan_paid_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Search Vehicle Challan
    if (isset($_POST['check_vehicle_challan'])) {
        $veh_no = strtoupper(str_replace([' ', '-'], '', trim($_POST['vehicle_number'] ?? '')));
        if (empty($veh_no)) {
            $challan_search_error = "Please enter a vehicle registration number (e.g. MH39BA3148).";
        } else {
            $stmt = $conn->prepare("SELECT * FROM vehicle_challans WHERE UPPER(REPLACE(REPLACE(vehicle_number, ' ', ''), '-', '')) = ?");
            $stmt->execute([$veh_no]);
            $searched_challan = $stmt->fetch();
            if (!$searched_challan) {
                $challan_search_error = "No pending traffic challans found for Vehicle No. '{$veh_no}'. Drive Safe!";
            }
        }
    }
    // 2. Pay Vehicle Challan
    elseif (isset($_POST['pay_vehicle_challan'])) {
        $challan_id = intval($_POST['challan_id'] ?? 0);
        if ($challan_id > 0) {
            $stmt = $conn->prepare("UPDATE vehicle_challans SET status = 'Paid' WHERE id = ?");
            $stmt->execute([$challan_id]);
            
            $stmt = $conn->prepare("SELECT * FROM vehicle_challans WHERE id = ?");
            $stmt->execute([$challan_id]);
            $searched_challan = $stmt->fetch();
            $challan_paid_success = "Challan #{$searched_challan['vehicle_number']} of ₹" . number_format($searched_challan['challan_amount'], 2) . " Paid Successfully! Receipt #NMC-CHAL-" . rand(10000, 99999) . " generated.";
        }
    }
    // 3. Meter Bill Instant Payment
    elseif (isset($_POST['pay_meter_bill'])) {
        $meter_no = trim($_POST['meter_number'] ?? '');
        $bill_amount = floatval($_POST['bill_amount'] ?? 80.00);
        $bill_type = trim($_POST['bill_type'] ?? 'Utility Bill');
        
        if (empty($meter_no)) {
            $error = "Please enter your Meter / Consumer Number.";
        } else {
            $success = "Meter #{$meter_no} {$bill_type} of ₹" . number_format($bill_amount, 2) . " Paid Successfully! Payment Receipt #NMC-MTR-" . rand(10000, 99999) . " generated.";
        }
    }
}

if ($is_logged_in) {
    $user = get_logged_in_user($conn);
    if ($user) {
        $active_ward_id = $user['ward_id'];
        $active_ward_name = $user['ward_name'];
    }
} else {
    $active_ward_id = $_SESSION['guest_ward_id'] ?? null;
}

if ($active_ward_id && empty($active_ward_name)) {
    $stmt = $conn->prepare("SELECT name FROM wards WHERE id = ?");
    $stmt->execute([$active_ward_id]);
    $active_ward_name = $stmt->fetchColumn();
}

// Fetch active schedules for today if ward is selected
$today_loadshedding = [];
$today_water = [];
$active_ward_aqi = null;

if ($active_ward_id) {
    // Loadshedding cuts scheduled for today
    $stmt = $conn->prepare("SELECT * FROM loadshedding_schedule WHERE ward_id = ? AND date = CURDATE() ORDER BY start_time ASC");
    $stmt->execute([$active_ward_id]);
    $today_loadshedding = $stmt->fetchAll();
    
    // Water supply timings for today (match current day of week)
    $day_name = date('l');
    $stmt = $conn->prepare("SELECT * FROM water_schedule WHERE ward_id = ? AND day_of_week = ? ORDER BY start_time ASC");
    $stmt->execute([$active_ward_id, $day_name]);
    $today_water = $stmt->fetchAll();
    
    // Latest AQI readings
    $stmt = $conn->prepare("SELECT aqi_value, co2_value, recorded_at FROM aqi_readings WHERE ward_id = ? ORDER BY recorded_at DESC LIMIT 1");
    $stmt->execute([$active_ward_id]);
    $active_ward_aqi = $stmt->fetch();
}

// Fetch general city metrics
$total_potholes = $conn->query("SELECT COUNT(*) FROM pothole_reports WHERE status != 'Resolved'")->fetchColumn();
$latest_news = $conn->query("SELECT title FROM news_posts ORDER BY is_emergency DESC, created_at DESC LIMIT 1")->fetchColumn();
$wards = $conn->query("SELECT * FROM wards ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>City Civic Portal - Smart Public Services</title>
  
  <!-- Google Fonts: Poppins -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  
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
    /* Page Specific Custom Accents */
    .feature-icon-box {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin-bottom: 16px;
      transition: transform 0.25s ease;
    }
    
    .card-link:hover .feature-icon-box {
      transform: scale(1.1);
    }
    
    /* Curated light mode icon colors */
    .color-power   { background: #fffbeb; color: #d97706; }
    .color-water   { background: #f0f9ff; color: #0284c7; }
    .color-waste   { background: #f8fafc; color: #475569; }
    .color-aqi     { background: #ecfdf5; color: #059669; }
    .color-traffic { background: #fef2f2; color: #dc2626; }
    .color-pothole { background: #faf5ff; color: #7c3aed; }
    .color-news    { background: #fdf2f8; color: #db2777; }
    .color-wallet  { background: #f0fdf4; color: #16a34a; }
    
    /* Curated dark mode icon colors */
    [data-theme="dark"] .color-power   { background: rgba(217, 119, 6, 0.15); color: #fbbf24; }
    [data-theme="dark"] .color-water   { background: rgba(2, 132, 199, 0.15); color: #38bdf8; }
    [data-theme="dark"] .color-waste   { background: rgba(71, 85, 105, 0.15); color: #94a3b8; }
    [data-theme="dark"] .color-aqi     { background: rgba(5, 150, 105, 0.15); color: #34d399; }
    [data-theme="dark"] .color-traffic { background: rgba(220, 38, 38, 0.15); color: #fca5a5; }
    [data-theme="dark"] .color-pothole { background: rgba(124, 58, 237, 0.15); color: #c084fc; }
    [data-theme="dark"] .color-news    { background: rgba(219, 39, 119, 0.15); color: #f472b6; }
    [data-theme="dark"] .color-wallet  { background: rgba(22, 163, 74, 0.15); color: #4ade80; }

    .ticker-alert {
      background: var(--color-danger-bg);
      border: 1px solid var(--card-border);
      border-radius: 16px;
      padding: 14px 20px;
      display: flex;
      align-items: center;
      margin-bottom: 30px;
      backdrop-filter: var(--glass-blur);
    }
    
    .ticker-icon {
      font-size: 1.3rem;
      color: var(--color-danger);
      margin-right: 12px;
      animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.1); }
      100% { transform: scale(1); }
    }
    
    .avatar-box {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: var(--primary-light);
      color: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      font-weight: bold;
    }
    
    .active-badge {
      background: var(--primary-light);
      color: var(--primary);
      padding: 4px 12px;
      border-radius: 50px;
      font-size: 0.8rem;
      font-weight: 600;
    }
    
    .schedule-indicator {
      border-left: 4px solid var(--primary);
      padding-left: 12px;
      margin-bottom: 12px;
    }
    
    .schedule-indicator.no-cut {
      border-left-color: var(--text-muted);
    }
    
    .schedule-indicator.alert-cut {
      border-left-color: var(--color-danger);
    }
    
    /* AQI badge styling */
    .aqi-pill {
      font-size: 0.75rem;
      padding: 4px 10px;
      border-radius: 50px;
      font-weight: 600;
    }
    .aqi-good { background: #d1fae5; color: #065f46; }
    .aqi-moderate { background: #fef3c7; color: #92400e; }
    .aqi-poor { background: #fee2e2; color: #991b1b; }
    
    [data-theme="dark"] .aqi-good { background: rgba(6, 95, 70, 0.2); color: #34d399; }
    [data-theme="dark"] .aqi-moderate { background: rgba(146, 64, 14, 0.2); color: #fbbf24; }
    [data-theme="dark"] .aqi-poor { background: rgba(153, 27, 27, 0.2); color: #fca5a5; }

    /* Mockup-specific CSS overrides */
    body {
      padding-bottom: 90px; /* Space for sticky footer */
    }
    
    .mockup-hero {
      background: linear-gradient(135deg, var(--primary) 0%, #115e3b 100%);
      padding: 45px 24px;
      border-radius: 24px;
      color: white;
      text-align: center;
      position: relative;
      margin-bottom: 30px;
      box-shadow: var(--shadow-soft);
    }
    
    .mockup-hero h1 {
      font-family: 'Outfit', sans-serif;
      font-weight: 700;
      font-size: 1.8rem;
      color: #ffffff !important;
    }
    
    .mockup-hero p {
      font-size: 0.95rem;
      opacity: 1;
      color: #e2e8f0 !important;
      font-weight: 600;
    }

    @media (max-width: 576px) {
      .mockup-hero {
        padding: 30px 16px;
        border-radius: 16px;
        margin-bottom: 20px;
      }
      .mockup-hero h1 {
        font-size: 1.35rem;
      }
      .mockup-hero p {
        font-size: 0.82rem;
      }
    }
    
    .search-wrapper {
      max-width: 500px;
      margin: 20px auto 0 auto;
    }
    
    .search-wrapper .form-control {
      border: none;
      border-radius: 50px;
      padding: 12px 20px 12px 45px;
      box-shadow: 0 4px 15px rgba(0,0,0,0.06);
      background-color: var(--card-bg);
      color: var(--text-main);
    }
    
    .search-wrapper .search-icon {
      position: absolute;
      left: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      z-index: 10;
    }
    
    .service-card-mockup {
      background-color: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 20px;
      padding: 20px;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      transition: all 0.25s ease;
      box-shadow: var(--shadow-soft);
      text-decoration: none !important;
      color: inherit;
    }
    
    .service-card-mockup:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-medium);
      border-color: var(--color-primary);
    }
    
    .icon-circle-mockup {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 12px;
      font-size: 1.2rem;
    }
    
    .icon-circle-mockup.power { background-color: #fef3c7; color: #d97706; }
    .icon-circle-mockup.water { background-color: #e0f2fe; color: #0284c7; }
    .icon-circle-mockup.air { background-color: #dcfce7; color: #15803d; }
    .icon-circle-mockup.garbage { background-color: #f1f5f9; color: #475569; }
    .icon-circle-mockup.traffic { background-color: #ffe4e6; color: #e11d48; }
    .icon-circle-mockup.potholes { background-color: #f3e8ff; color: #7e22ce; }
    
    .service-card-mockup h5 {
      font-size: 0.95rem;
      font-weight: 700;
      margin-bottom: 4px;
    }
    
    .service-card-subtext {
      font-size: 0.75rem;
      color: #64748b;
      margin-bottom: 0;
    }
    
    .new-essential-section {
      margin-top: 30px;
    }
    
    .new-essential-title {
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 15px;
      font-family: 'Outfit', sans-serif;
    }
    
    .new-essential-item {
      background-color: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 14px 18px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      transition: all 0.25s ease;
      text-decoration: none !important;
      color: inherit;
    }
    
    .new-essential-item:hover {
      transform: translateX(4px);
      border-color: var(--color-primary);
    }
    
    .new-essential-icon {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 14px;
      font-size: 1rem;
    }
    
    .new-essential-icon.transport { background-color: #dcfce7; color: #15803d; }
    .new-essential-icon.grievance { background-color: #ffe4e6; color: #e11d48; }
    .new-essential-icon.tax { background-color: #ecfeff; color: #0891b2; }
    
    .new-essential-content h6 {
      font-size: 0.9rem;
      font-weight: 700;
      margin-bottom: 2px;
    }
    
    .new-essential-content p {
      font-size: 0.75rem;
      color: #64748b;
      margin-bottom: 0;
    }
    
    /* Sticky Bottom Bar */
    .sticky-bottom-bar {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background-color: var(--card-bg);
      border-top: 1px solid var(--border-color);
      padding: 14px 24px;
      z-index: 1000;
      box-shadow: 0 -4px 15px rgba(0,0,0,0.04);
      backdrop-filter: blur(10px);
      background: rgba(255, 255, 255, 0.85);
    }
    [data-theme="dark"] .sticky-bottom-bar {
      background: rgba(15, 23, 42, 0.85);
    }
  </style>
</head>
<body>

  <!-- Reusable Top Navigation Bar -->
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="container py-3 py-md-4">
    
    <?php if (isset($success)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Emergency Ticker Alert -->
    <?php if (!empty($latest_news)): ?>
      <div class="ticker-alert shadow-sm">
        <i class="bi bi-exclamation-triangle-fill ticker-icon"></i>
        <div class="text-truncate flex-grow-1">
          <strong class="text-danger"><i class="bi bi-exclamation-octagon-fill me-1"></i>NMC Alert:</strong> <?php echo htmlspecialchars($latest_news); ?>
        </div>
        <a href="dashboard.php?tab=news" class="btn btn-sm btn-outline-danger ms-2 text-nowrap rounded-pill px-3">View Bulletin</a>
      </div>
    <?php endif; ?>

    <!-- Header Hero Banner with Dynamic Greeting & Search -->
    <div class="mockup-hero">
      <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-2 shadow-sm">
        <span id="dynamic-greeting-icon">👋</span>
        <span id="dynamic-greeting-text" class="small fw-bold text-white">Welcome, Citizen</span>
      </div>
      <h1 data-translate-key="hero_title" class="mb-2">Smart City Services Portal</h1>
      <p class="mb-3 text-white-50 fs-6 d-none d-sm-block" data-translate-key="hero_subtitle">Instant digital access to 20+ municipal & civic services</p>

      <!-- Dynamic Ward Quick Status Pills (Always Visible High Contrast Pills) -->
      <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-warning rounded-pill px-3.5 py-1 fs-7 d-flex align-items-center gap-1.5 shadow-lg font-monospace fw-extrabold text-dark" data-bs-toggle="modal" data-bs-target="#elevenLabsVoiceModal" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border: none;">
          <i class="bi bi-mic-fill text-danger fs-6"></i>
          <span>Talk to Aanya Voice AI 🎙️</span>
        </button>

        <button type="button" class="btn btn-sm btn-dark bg-opacity-40 text-white border border-white border-opacity-25 rounded-pill px-3 py-1 fs-7 d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#wardSelectModal">
          <i class="bi bi-geo-alt-fill text-warning"></i>
          <span>Ward: <strong><?php echo $active_ward_name ? htmlspecialchars($active_ward_name) : 'Nagpur (Set Ward)'; ?></strong></span>
        </button>
        
        <span class="btn btn-sm btn-dark bg-opacity-40 text-white border border-white border-opacity-25 rounded-pill px-3 py-1 fs-7 d-flex align-items-center gap-1 shadow-sm">
          <i class="bi bi-lightning-charge-fill <?php echo empty($today_loadshedding) ? 'text-success' : 'text-danger'; ?>"></i>
          <span>Power: <strong><?php echo empty($today_loadshedding) ? 'Grid Operational' : count($today_loadshedding).' Cut Today'; ?></strong></span>
        </span>
        
        <span class="btn btn-sm btn-dark bg-opacity-40 text-white border border-white border-opacity-25 rounded-pill px-3 py-1 fs-7 d-flex align-items-center gap-1 shadow-sm">
          <i class="bi bi-wind text-info"></i>
          <span>AQI: <strong><?php echo ($active_ward_aqi && isset($active_ward_aqi['aqi_value'])) ? $active_ward_aqi['aqi_value'] : '42 (Good)'; ?></strong></span>
        </span>
      </div>
      
      <!-- Interactive Search Input Bar -->
      <div class="search-wrapper position-relative">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="service-search" class="form-control" data-translate-key="search_placeholder" placeholder="Search services (e.g. water, potholes, taxes)..." autocomplete="off">
        <button type="button" id="search-clear" class="search-clear-btn" aria-label="Clear Search"><i class="bi bi-x-circle-fill"></i></button>
      </div>
    </div>

    <!-- Community Verification Polls Banner Card -->
    <div class="community-polls-banner mb-4 p-4 rounded-4 shadow-sm text-white position-relative overflow-hidden" style="background: linear-gradient(135deg, #047857 0%, #059669 50%, #10b981 100%) !important;">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 position-relative" style="z-index: 2;">
        <div>
          <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-2 small fw-bold text-white">
            <i class="bi bi-patch-check-fill text-warning"></i>
            <span>Public Citizen Voting</span>
          </div>
          <h4 class="fw-bold mb-1 text-white"><i class="bi bi-hand-thumbs-up-fill me-2 text-warning"></i>Community Complaint Verification Polls 🗳️</h4>
          <p class="mb-0 text-white opacity-90 small">Vote Thumbs Up 👍 or Down 👎 on reported potholes & civic complaints. Verify genuine issues for Municipal action!</p>
        </div>
        <a href="polls.php" class="btn btn-light text-success font-monospace fw-bold px-4 py-2.5 rounded-pill shadow text-nowrap align-self-start align-self-md-center">
          Open Polls Page <i class="bi bi-arrow-right ms-1"></i>
        </a>
      </div>
    </div>

    <!-- Category Filter Chips (Horizontal Scrollable on Mobile - Strict 1 Line) -->
    <div class="filter-chips-container mb-3 mb-md-4" id="category-chips" style="display: flex !important; flex-wrap: nowrap !important; overflow-x: auto !important; -webkit-overflow-scrolling: touch !important; white-space: nowrap !important; gap: 8px !important; width: 100% !important; padding-bottom: 8px !important; scrollbar-width: none !important;">
      <button class="filter-chip active" data-category="all" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-grid-fill text-success"></i> All Services
      </button>
      <a href="polls.php" class="filter-chip text-decoration-none bg-success bg-opacity-10 border-success text-success fw-bold" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-check2-square text-success"></i> Verification Polls 🗳️
      </a>
      <a href="redeem.php" class="filter-chip text-decoration-none bg-primary bg-opacity-10 border-primary text-primary fw-bold" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-gift-fill text-primary"></i> Redeem Points 🎁
      </a>
      <button class="filter-chip" data-category="utilities" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-lightning-charge-fill text-warning"></i> Utilities
      </button>
      <button class="filter-chip" data-category="civic" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-tools text-purple" style="color: #9333ea;"></i> Civic & Roads
      </button>
      <button class="filter-chip" data-category="eco" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-tree-fill text-success"></i> Eco Rewards
      </button>
      <button class="filter-chip" data-category="transit" style="flex: 0 0 auto !important; white-space: nowrap !important;">
        <i class="bi bi-bus-front-fill text-info"></i> Transit & Tax
      </button>
    </div>

    <!-- Main Content Layout -->
    <div class="row">
      <!-- Left Column: Services Grid & Essential Cards -->
      <div class="col-lg-8">
        
        <!-- Empty Search State -->
        <div id="no-services-found" class="card p-4 text-center mb-4 d-none">
          <i class="bi bi-search-heart fs-1 text-muted mb-2"></i>
          <h5 class="fw-bold">No services found</h5>
          <p class="text-muted small mb-3">No matching civic service or report found for your search.</p>
          <div>
            <button id="reset-search-btn" class="btn btn-outline-primary btn-sm rounded-pill px-3">Reset Search</button>
          </div>
        </div>

        <!-- Grid of Services (Even 2-column mobile layout) -->
        <div class="row g-2 g-sm-3 mb-4" id="services-grid">
          <!-- Power Outages -->
          <div class="col-6 col-md-6 service-card-col" data-category="utilities" data-keywords="power outages electricity loadshedding current lights cut">
            <a href="dashboard.php?tab=power" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup power">
                  <i class="bi bi-lightning-charge-fill"></i>
                </div>
                <span class="badge bg-warning text-dark font-monospace" style="font-size: 0.65rem;">Schedule</span>
              </div>
              <h5 data-translate-key="power_outages">Power Outages</h5>
              <p class="service-card-subtext">Check load cuts & report outages</p>
            </a>
          </div>
          
          <!-- Water Supply -->
          <div class="col-6 col-md-6 service-card-col" data-category="utilities" data-keywords="water supply tap bill pipe shortage pipeline timetable">
            <a href="water.php" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup water">
                  <i class="bi bi-droplet-fill"></i>
                </div>
                <span class="badge bg-info text-white font-monospace" style="font-size: 0.65rem;">Daily 24x7</span>
              </div>
              <h5 data-translate-key="water_supply">Water Supply & Quality</h5>
              <p class="service-card-subtext">Timetables, quality & tankers</p>
            </a>
          </div>
 
          <!-- Air Quality (AQI) -->
          <div class="col-6 col-md-6 service-card-col" data-category="utilities" data-keywords="air quality aqi pollution co2 environment breath smog">
            <a href="dashboard.php?tab=aqi" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup air">
                  <i class="bi bi-wind"></i>
                </div>
                <span class="badge bg-success text-white font-monospace" style="font-size: 0.65rem;">Live AQI</span>
              </div>
              <h5 data-translate-key="air_quality">Air Quality (AQI)</h5>
              <p class="service-card-subtext">Real-time local CO2 & air metrics</p>
            </a>
          </div>
          
          <!-- Garbage Pickup -->
          <div class="col-6 col-md-6 service-card-col" data-category="civic" data-keywords="garbage pickup waste trash collection truck sanitation clean sweep">
            <a href="dashboard.php?tab=waste" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup garbage">
                  <i class="bi bi-trash3-fill"></i>
                </div>
                <span class="badge bg-secondary text-white font-monospace" style="font-size: 0.65rem;">Area Truck</span>
              </div>
              <h5 data-translate-key="garbage_pickup">Garbage Pickup</h5>
              <p class="service-card-subtext">Check collection vehicle status</p>
            </a>
          </div>

          <!-- Traffic Challan -->
          <div class="col-6 col-md-6 service-card-col" data-category="transit" data-keywords="traffic challan fine fine reward photo upload violation car bike">
            <a href="dashboard.php?tab=traffic" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup traffic">
                  <i class="bi bi-camera-fill"></i>
                </div>
                <span class="badge bg-danger text-white font-monospace" style="font-size: 0.65rem;">Reward ₹50</span>
              </div>
              <h5 data-translate-key="traffic_challan">Traffic Challan</h5>
              <p class="service-card-subtext">Report violations & earn reward</p>
            </a>
          </div>
          
          <!-- Potholes Map -->
          <div class="col-6 col-md-6 service-card-col" data-category="civic" data-keywords="potholes road damage repair map GPS upload photo roadwork street">
            <a href="dashboard.php?tab=potholes" class="service-card-mockup">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="icon-circle-mockup potholes">
                  <i class="bi bi-tools"></i>
                </div>
                <span class="badge bg-primary text-white font-monospace" style="font-size: 0.65rem;">Fix Roads</span>
              </div>
              <h5 data-translate-key="potholes_map">Potholes Map</h5>
              <p class="service-card-subtext">GPS road damage reports</p>
            </a>
          </div>
        </div>

        <!-- New & Essential Features Section -->
        <div class="new-essential-section mb-4" id="new-essential-wrapper">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="new-essential-title mb-0"><i class="bi bi-stars text-warning me-2"></i>New & Essential Services</h4>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Instant Rewards</span>
          </div>
          
          <a href="barcode.php" class="new-essential-item service-card-col" data-category="eco" data-keywords="co2 scanner barcode games green points cash earn scan eco product">
            <div class="new-essential-icon" style="background-color: var(--color-success-bg); color: var(--color-success); border-radius: 14px; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
              <i class="bi bi-qr-code-scan"></i>
            </div>
            <div class="new-essential-content flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 style="margin-bottom: 2px; font-weight: 700;">CO2 Barcode Scanner & Games</h6>
                <span class="badge bg-success font-monospace">+Cash</span>
              </div>
              <p style="margin-bottom: 0; font-size: 0.8rem; color: var(--text-muted);">Scan eco barcodes & convert points to cash rewards</p>
            </div>
          </a>
          
          <a href="dashboard.php?tab=eco_tasks" class="new-essential-item service-card-col" data-category="eco" data-keywords="daily eco tasks tree planting cycle commute environmental rewards wallet money">
            <div class="new-essential-icon" style="background-color: var(--color-success-bg); color: var(--color-success); border-radius: 14px; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
              <i class="bi bi-calendar-check"></i>
            </div>
            <div class="new-essential-content flex-grow-1">
              <div class="d-flex justify-content-between align-items-center">
                <h6 style="margin-bottom: 2px; font-weight: 700;">Daily Eco Tasks</h6>
                <span class="badge bg-primary font-monospace">Daily</span>
              </div>
              <p style="margin-bottom: 0; font-size: 0.8rem; color: var(--text-muted);">Plant trees, cycle commute & receive payouts</p>
            </div>
          </a>
          
          <div class="row g-2">
            <div class="col-12 col-md-6">
              <a href="dashboard.php?tab=transport" class="new-essential-item service-card-col mb-0" data-category="transit" data-keywords="public transport bus timetable arrivals routes schedule metro transit">
                <div class="new-essential-icon transport">
                  <i class="bi bi-bus-front-fill"></i>
                </div>
                <div class="new-essential-content">
                  <h6 data-translate-key="public_transport" style="margin-bottom: 2px; font-weight: 700;">Public Transport</h6>
                  <p data-translate-key="real_time_arrivals" style="margin-bottom: 0; font-size: 0.78rem;">Real-time bus & transit arrivals</p>
                </div>
              </a>
            </div>
            
            <div class="col-12 col-md-6">
              <a href="dashboard.php?tab=tax" class="new-essential-item service-card-col mb-0" data-category="transit" data-keywords="tax pay status property tax water bill online payment receipts">
                <div class="new-essential-icon tax">
                  <i class="bi bi-file-earmark-ruled-fill"></i>
                </div>
                <div class="new-essential-content">
                  <h6 data-translate-key="tax_pay_status" style="margin-bottom: 2px; font-weight: 700;">Tax & Bills Status</h6>
                  <p data-translate-key="property_tax_bill" style="margin-bottom: 0; font-size: 0.78rem;">Property tax & water bill records</p>
                </div>
              </a>
            </div>
          </div>
        </div>

        <!-- Dynamic schedule preview summary for the active ward -->
        <?php if ($active_ward_id): ?>
          <div class="card p-3 p-md-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="fw-bold mb-0 fs-6 fs-md-5"><i class="bi bi-calendar3 text-success me-2"></i>Today's Ward Updates for <?php echo htmlspecialchars($active_ward_name); ?></h5>
              <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#wardSelectModal">Switch Ward</button>
            </div>
            
            <div class="row g-3">
              <div class="col-md-6">
                <div class="schedule-indicator <?php echo empty($today_loadshedding) ? 'no-cut' : 'alert-cut'; ?>">
                  <h6 class="fw-bold mb-1"><i class="bi bi-lightning-charge text-warning me-1"></i>Power Timetable</h6>
                  <?php if (empty($today_loadshedding)): ?>
                    <span class="text-muted small">No loadshedding cuts scheduled today!</span>
                  <?php else: ?>
                    <?php foreach ($today_loadshedding as $cut): ?>
                      <span class="small text-danger d-block mt-1">
                        <strong>Cut:</strong> <?php echo date('h:i A', strtotime($cut['start_time'])); ?> - <?php echo date('h:i A', strtotime($cut['end_time'])); ?>
                        (<?php echo htmlspecialchars($cut['reason']); ?>)
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
              
              <div class="col-md-6">
                <div class="schedule-indicator">
                  <h6 class="fw-bold mb-1"><i class="bi bi-droplet-half text-info me-1"></i>Water Supply Today</h6>
                  <?php if (empty($today_water)): ?>
                    <span class="text-muted small">No water supply schedule logged for today.</span>
                  <?php else: ?>
                    <?php foreach ($today_water as $supply): ?>
                      <span class="small text-success d-block mt-1">
                        <strong>Supply:</strong> <?php echo date('h:i A', strtotime($supply['start_time'])); ?> - <?php echo date('h:i A', strtotime($supply['end_time'])); ?>
                        (<?php echo htmlspecialchars($supply['area_name']); ?>)
                      </span>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Live Community Complaint Polls Feed Preview -->
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white mt-4">
          <div class="card-header bg-success bg-opacity-10 border-0 p-3 p-md-4 d-flex justify-content-between align-items-center">
            <div>
              <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-hand-thumbs-up-fill text-success me-2"></i>Community Complaint Polls 🗳️</h5>
              <span class="small text-muted">Recent citizen reports pending verification</span>
            </div>
            <a href="polls.php" class="btn btn-sm btn-success rounded-pill px-3 font-monospace fw-bold">View All Polls</a>
          </div>

          <div class="card-body p-3 p-md-4">
            <?php
            // Fetch top 2 open complaint polls for index preview
            $stmt = $conn->query("SELECT p.*, u.email as reporter_email,
                      (SELECT COUNT(*) FROM pothole_votes WHERE pothole_id = p.id AND vote_type = 'upvote') as upvotes,
                      (SELECT COUNT(*) FROM pothole_votes WHERE pothole_id = p.id AND vote_type = 'downvote') as downvotes
                      FROM pothole_reports p
                      LEFT JOIN users u ON p.user_id = u.id
                      ORDER BY p.created_at DESC LIMIT 2");
            $index_polls = $stmt ? $stmt->fetchAll() : [];
            ?>

            <?php if (empty($index_polls)): ?>
              <div class="text-center py-4 text-muted">
                <i class="bi bi-check-circle-fill text-success fs-1 mb-2 d-block"></i>
                <p class="mb-0 small fw-semibold text-dark">No open community complaints currently pending verification.</p>
                <a href="dashboard.php?tab=potholes" class="btn btn-sm btn-outline-success rounded-pill mt-2">Report a Road Pothole</a>
              </div>
            <?php else: ?>
              <div class="row g-3">
                <?php foreach ($index_polls as $ph): 
                  $up = intval($ph['upvotes']);
                  $down = intval($ph['downvotes']);
                  $net = $up - $down;
                ?>
                  <div class="col-md-6">
                    <div class="card h-100 border rounded-4 overflow-hidden shadow-sm bg-white">
                      <div class="position-relative">
                        <img src="<?php echo htmlspecialchars($ph['photo_path']); ?>" style="height: 150px; object-fit: cover;" class="card-img-top w-100" alt="Pothole Damage">
                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 backdrop-blur text-white text-decoration-none px-2 py-1 rounded-pill" title="Open Google Maps">
                          <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo number_format($ph['latitude'], 3); ?>, <?php echo number_format($ph['longitude'], 3); ?>
                        </a>
                      </div>
                      <div class="p-3">
                        <p class="small text-dark fw-bold mb-2 text-truncate"><?php echo htmlspecialchars($ph['description']); ?></p>
                        <div class="d-flex justify-content-between align-items-center">
                          <span class="small text-muted"><i class="bi bi-hand-thumbs-up me-1 text-success"></i>+<?php echo $net; ?> Votes</span>
                          <a href="polls.php" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1">Vote Now</a>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Right Column: Sidebar (Ward details, Profile info, Stats) -->
      <div class="col-lg-4">
        
        <!-- Ward Selector & Status Card -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-geo-alt-fill text-success me-2"></i>Active Ward Zone</span>
            <span class="active-badge"><?php echo $active_ward_name ? htmlspecialchars($active_ward_name) : 'Not Set'; ?></span>
          </div>
          <div class="card-body">
            <form action="" method="POST">
              <label class="form-label small fw-bold">Choose your residential ward:</label>
              <div class="d-flex gap-2">
                <select name="ward_id" class="form-select form-select-sm" required>
                  <option value="" disabled <?php echo !$active_ward_id ? 'selected' : ''; ?>>Choose Ward...</option>
                  <?php foreach ($wards as $w): ?>
                    <option value="<?php echo $w['id']; ?>" <?php echo ($active_ward_id == $w['id']) ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($w['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" name="update_ward" class="btn btn-primary btn-sm px-3 text-nowrap">Update</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Account Profile Details -->
        <div class="card mb-4">
          <div class="card-header"><i class="bi bi-person-circle text-success me-2"></i>Citizen Account Details</div>
          <div class="card-body">
            <div class="d-flex align-items-center mb-3">
              <div class="avatar-box me-3">
                <?php echo $is_logged_in ? strtoupper(substr($user['email'], 0, 1)) : '?'; ?>
              </div>
              <div>
                <h6 class="fw-bold mb-0 text-dark"><?php echo $is_logged_in ? 'Logged In Citizen' : 'Guest Visitor'; ?></h6>
                <span class="small text-muted font-monospace"><?php echo $is_logged_in ? htmlspecialchars($user['email']) : 'Sign in to earn rewards & report issues'; ?></span>
              </div>
            </div>
            
            <?php if ($is_logged_in): ?>
              <div class="border-top pt-3 d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">My Wallet Balance:</span>
                <span class="fw-bold text-success font-monospace fs-5">₹<?php echo number_format($user['wallet_balance'], 2); ?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted small">Eco Points:</span>
                <span class="badge bg-success font-monospace"><?php echo $user['eco_points'] ?? 0; ?> XP</span>
              </div>
            <?php else: ?>
              <div class="border-top pt-3 text-center">
                <a href="login.php" class="btn btn-outline-success btn-sm w-100 rounded-pill"><i class="bi bi-box-arrow-in-right me-1"></i>Sign In / Register</a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Refer & Earn & Points Redemption Card -->
        <div class="card mb-4 border-success bg-success bg-opacity-10">
          <div class="card-header bg-transparent border-0 pb-0 fw-bold text-success">
            <i class="bi bi-gift-fill me-2"></i>Refer & Earn Cash
          </div>
          <div class="card-body pt-2">
            <p class="small text-muted mb-2">Earn <strong>₹50.00 Cash</strong> for every citizen friend who joins using your code!</p>
            <div class="input-group input-group-sm mb-2">
              <input type="text" class="form-control font-monospace bg-white border-success-subtle" readonly value="<?php echo $is_logged_in ? 'NMC-REF-' . $user['id'] : 'NMC-REF-CITIZEN'; ?>" id="referralCodeInput">
              <button class="btn btn-success fw-semibold" type="button" onclick="navigator.clipboard.writeText(document.getElementById('referralCodeInput').value); alert('Referral Code Copied!');">Copy</button>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top border-success-subtle">
              <span class="small fw-semibold text-success">Redeem 50 XP = ₹10 Cash</span>
              <a href="barcode.php" class="btn btn-sm btn-success rounded-pill px-3">Redeem</a>
            </div>
          </div>
        </div>

        <!-- City Live Metrics Card -->
        <div class="card mb-4">
          <div class="card-header"><i class="bi bi-bar-chart-line text-success me-2"></i>City Metrics</div>
          <div class="card-body pt-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="text-secondary small">Open Potholes Reported:</span>
              <span class="badge bg-purple text-white px-3" style="background-color: #7c3aed;"><?php echo $total_potholes; ?> active</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="text-secondary small">Traffic Challan Reward:</span>
              <span class="badge bg-success bg-opacity-10 text-success">₹50.00 / approval</span>
            </div>
            <?php if ($active_ward_id && $active_ward_aqi): ?>
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small">Local Air Quality (AQI):</span>
                <?php 
                  $aqi = $active_ward_aqi['aqi_value'];
                  $badge = 'aqi-good'; $txt = 'Good';
                  if ($aqi > 100) { $badge = 'aqi-poor'; $txt = 'Poor'; }
                  elseif ($aqi > 50) { $badge = 'aqi-moderate'; $txt = 'Moderate'; }
                ?>
                <span class="aqi-pill <?php echo $badge; ?>"><?php echo $aqi; ?> (<?php echo $txt; ?>)</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Admin Control Panel Link -->
        <div class="text-center pb-4">
          <a href="admin.php" class="text-muted small text-decoration-none"><i class="bi bi-shield-lock me-1"></i>Admin Control Panel Portal</a>
        </div>
      </div>
    </div>

    <!-- Instant Civic Action Cards: Vehicle Traffic Challan & Utility Meter Bill Payment (Bottom Section) -->
    <div class="row g-3 mt-3 mb-4" id="challan-section">
      <!-- Card 1: Check Vehicle Traffic Challan (Pop-up Modal) -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 p-4 bg-white position-relative overflow-hidden" style="border: 1px solid #ffe4e6 !important; box-shadow: 0 12px 30px rgba(225, 29, 72, 0.08) !important;">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rounded-circle p-2.5 d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%); color: #e11d48;">
              <i class="bi bi-shield-exclamation fs-4"></i>
            </div>
            <div>
              <h5 class="fw-extrabold mb-0 text-dark" style="font-size: 1.1rem;">Check Vehicle Traffic Challan 🚗</h5>
              <span class="small text-muted">Enter registration no. to view instant details & pay</span>
            </div>
          </div>

          <form action="index.php#challan-section" method="POST" class="mt-1">
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="text" name="vehicle_number" class="form-control rounded-pill font-monospace text-uppercase fw-bold px-3 py-2.5 text-dark border" placeholder="e.g. MH39BA3148" value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ''); ?>" required style="border-color: #fda4af !important; background-color: #fff1f2 !important; font-size: 0.95rem; letter-spacing: 1px;">
              <button type="submit" name="check_vehicle_challan" class="btn btn-danger font-monospace fw-bold px-4 py-2.5 text-nowrap shadow-sm" style="background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); border: none; border-radius: 50px !important;">
                <i class="bi bi-search me-1.5"></i>Check Challan
              </button>
            </div>
          </form>

          <div class="d-flex align-items-center flex-wrap gap-2 pt-1">
            <span class="small text-muted fw-semibold" style="font-size: 0.78rem;">Quick Test:</span>
            <button type="button" class="btn btn-sm text-danger border-0 rounded-pill px-3 py-1 font-monospace fw-bold small d-inline-flex align-items-center gap-1.5 shadow-sm" style="font-size: 0.78rem; background: #ffe4e6; color: #be123c !important;" onclick="document.querySelector('input[name=vehicle_number]').value='MH39BA3148';">
              <i class="bi bi-car-front-fill text-danger"></i>
              <span>MH39BA3148 (₹2000)</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Card 2: Instant Utility Meter Bill Payment -->
      <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 p-4 bg-white position-relative overflow-hidden" style="border: 1px solid #fef3c7 !important; box-shadow: 0 12px 30px rgba(245, 158, 11, 0.08) !important;">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rounded-circle p-2.5 d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #d97706;">
              <i class="bi bi-receipt-cutoff fs-4"></i>
            </div>
            <div>
              <h5 class="fw-extrabold mb-0 text-dark" style="font-size: 1.1rem;">Instant Utility Meter Bill Payment ⚡</h5>
              <span class="small text-muted">Enter consumer meter no. & pay utility bills directly</span>
            </div>
          </div>

          <form action="index.php" method="POST" class="mt-1">
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="text" name="meter_number" class="form-control rounded-pill font-monospace fw-bold px-3 py-2 text-dark border" placeholder="Meter No. (e.g. MTR-9876)" required style="border-color: #fde68a !important; background-color: #fffbeb !important;">
              <select name="bill_type" class="form-select rounded-pill small border px-3" style="border-color: #fde68a !important; background-color: #fffbeb !important;">
                <option value="Electricity Bill (₹80.00)">Electricity (₹80)</option>
                <option value="Water Bill (₹25.00)">Water (₹25)</option>
                <option value="Property Tax (₹80.00)">Property Tax (₹80)</option>
              </select>
            </div>
      </div>
    </div>
  </div>

  <!-- Civic Donation & Environmental Impact Fund Section (Placed Below Traffic & Utility Cards) -->
  <div class="container mb-4">
    <div class="card border-0 shadow-lg rounded-4 overflow-hidden text-white" style="background: linear-gradient(135deg, #022c22 0%, #064e3b 50%, #047857 100%) !important; border: 2px solid #10b981 !important; border-radius: 24px !important; box-shadow: 0 15px 35px rgba(4, 120, 87, 0.35) !important;">
      <div class="card-body p-4 p-md-5 position-relative">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
          <div>
            <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-75 border border-warning px-3 py-1.5 rounded-pill mb-3 font-monospace fw-bold" style="color: #fbbf24 !important;">
              <i class="bi bi-heart-fill text-warning fs-6"></i>
              <span style="letter-spacing: 0.5px;">NAGPUR MUNICIPAL CIVIC IMPACT FUND</span>
            </div>

            <h3 class="fw-extrabold text-white mb-2.5" style="font-size: 1.55rem; letter-spacing: -0.5px; text-shadow: 0 2px 5px rgba(0,0,0,0.5);">Support Our Green City Mission 🌳💧</h3>
            
            <p class="mb-3.5" style="color: #ecfdf5 !important; font-size: 0.96rem; max-width: 680px; line-height: 1.55; font-weight: 500;">
              Contribute directly to urban tree plantation drives, clean drinking water kiosks, solar streetlights, and street pothole repairs across Nagpur. Earn <span class="badge bg-warning text-dark font-monospace fw-extrabold px-2 py-1 ms-1">+25 Eco XP</span> per ₹100 donated!
            </p>
            
            <div class="d-flex flex-wrap gap-2">
              <a href="donation.php" class="btn btn-light btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 text-dark shadow-sm border border-success-subtle">
                🌳 Plant Trees (₹100)
              </a>
              <a href="donation.php" class="btn btn-light btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 text-dark shadow-sm border border-primary-subtle">
                💧 Water Kiosk (₹250)
              </a>
              <a href="donation.php" class="btn btn-light btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 text-dark shadow-sm border border-purple-subtle">
                🛠️ Potholes (₹500)
              </a>
              <a href="donation.php" class="btn btn-light btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 text-dark shadow-sm border border-warning-subtle">
                ☀️ Solar Kit (₹1000)
              </a>
            </div>
          </div>

          <div class="text-lg-end mt-2 mt-lg-0">
            <a href="donation.php" class="btn btn-warning btn-lg rounded-pill font-monospace fw-extrabold px-4 py-3 shadow-lg text-dark text-nowrap w-100 w-lg-auto" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; border: none; font-size: 1.05rem; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.45) !important;">
              <i class="bi bi-heart-fill me-2 text-danger"></i>Contribute to Civic Fund 🚀
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Mobile Dock Bottom Spacer to prevent content overlay -->
  <div class="d-block d-md-none" style="height: 80px; width: 100%;"></div>

  <!-- Reusable Mobile Dock Navigation & Modals -->
  <?php include __DIR__ . '/bottom_dock.php'; ?>

  <!-- Desktop Sticky Bottom Bar -->
  <div class="sticky-bottom-bar d-flex justify-content-between align-items-center">
    <div class="fw-bold fs-6 d-none d-md-block" style="color: var(--text-main);" data-translate-key="explore_all_services">Municipal Public Services Portal</div>
    <div class="bottom-bar-actions d-flex gap-2">
      <?php if ($is_logged_in): ?>
        <a href="logout.php" class="btn btn-outline-danger rounded-pill px-3 py-2 fw-semibold text-nowrap" data-translate-key="logout">Logout</a>
      <?php endif; ?>
      <a href="dashboard.php" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold text-nowrap" data-translate-key="open_dashboard">Open Full Dashboard</a>
    </div>
  </div>

  <!-- Bootstrap Bundle with Popper JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // Contextual Time-based Greeting
    (function() {
      const hour = new Date().getHours();
      const greetingText = document.getElementById('dynamic-greeting-text');
      const greetingIcon = document.getElementById('dynamic-greeting-icon');
      if (greetingText && greetingIcon) {
        if (hour >= 5 && hour < 12) {
          greetingText.textContent = "Good Morning, Citizen 👋";
          greetingIcon.textContent = "🌅";
        } else if (hour >= 12 && hour < 17) {
          greetingText.textContent = "Good Afternoon, Citizen ☀️";
          greetingIcon.textContent = "☀️";
        } else if (hour >= 17 && hour < 22) {
          greetingText.textContent = "Good Evening, Citizen 🌙";
          greetingIcon.textContent = "🌆";
        } else {
          greetingText.textContent = "Good Night, Citizen ✨";
          greetingIcon.textContent = "✨";
        }
      }
    })();

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

    // Dynamic Search & Category Filter System
    const serviceSearch = document.getElementById('service-search');
    const searchClear = document.getElementById('search-clear');
    const resetSearchBtn = document.getElementById('reset-search-btn');
    const serviceCards = document.querySelectorAll('.service-card-col');
    const categoryChips = document.querySelectorAll('.filter-chip');
    const noFoundState = document.getElementById('no-services-found');
    const newEssentialSection = document.getElementById('new-essential-wrapper');

    let activeCategory = 'all';

    function filterServices() {
      const query = serviceSearch ? serviceSearch.value.toLowerCase().trim() : '';
      let visibleCount = 0;

      // Show/hide clear button
      if (searchClear) {
        searchClear.style.display = query.length > 0 ? 'block' : 'none';
      }

      serviceCards.forEach(card => {
        const cardCategory = card.getAttribute('data-category') || 'all';
        const cardKeywords = card.getAttribute('data-keywords') || '';
        const titleText = card.textContent.toLowerCase();

        const matchesCategory = (activeCategory === 'all' || cardCategory === activeCategory);
        const matchesQuery = (query === '' || titleText.includes(query) || cardKeywords.toLowerCase().includes(query));

        if (matchesCategory && matchesQuery) {
          card.style.display = 'block';
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      // Handle empty state display
      if (noFoundState) {
        if (visibleCount === 0) {
          noFoundState.classList.remove('d-none');
        } else {
          noFoundState.classList.add('d-none');
        }
      }

      // Hide essential section if filtering specific non-all category or searching
      if (newEssentialSection) {
        if (query.length > 0 || activeCategory !== 'all') {
          newEssentialSection.style.display = 'none';
        } else {
          newEssentialSection.style.display = 'block';
        }
      }
    }

    if (serviceSearch) {
      serviceSearch.addEventListener('input', filterServices);
    }

    if (searchClear) {
      searchClear.addEventListener('click', () => {
        serviceSearch.value = '';
        filterServices();
        serviceSearch.focus();
      });
    }

    if (resetSearchBtn) {
      resetSearchBtn.addEventListener('click', () => {
        if (serviceSearch) serviceSearch.value = '';
        activeCategory = 'all';
        categoryChips.forEach(c => c.classList.remove('active'));
        const allChip = document.querySelector('.filter-chip[data-category="all"]');
        if (allChip) allChip.classList.add('active');
        filterServices();
      });
    }

    // Category Chip Click Handling
    categoryChips.forEach(chip => {
      chip.addEventListener('click', () => {
        categoryChips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        activeCategory = chip.getAttribute('data-category');
        filterServices();
      });
    });

    // Initialize initial filter check
    filterServices();

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

  <!-- Vehicle Traffic Challan Pop-up Details Modal -->
  <div class="modal fade" id="vehicleChallanModal" tabindex="-1" aria-labelledby="vehicleChallanModalLabel" aria-hidden="true" style="backdrop-filter: blur(6px);">
    <div class="modal-dialog modal-dialog-centered modal-md">
      <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 28px !important; box-shadow: 0 25px 60px rgba(190, 18, 60, 0.35) !important;">
        <?php if ($searched_challan): ?>
          <div class="modal-header text-white border-0 p-3.5 p-md-4 position-relative" style="background: linear-gradient(135deg, #be123c 0%, #9f1239 50%, #881337 100%) !important;">
            <div class="pe-4">
              <div class="d-inline-flex align-items-center gap-2 bg-black bg-opacity-40 border border-white border-opacity-20 px-3 py-1 rounded-pill mb-2 small fw-bold text-warning font-monospace">
                <i class="bi bi-car-front-fill text-warning fs-6"></i>
                <span>VEHICLE: <?php echo htmlspecialchars($searched_challan['vehicle_number']); ?></span>
              </div>
              <h4 class="fw-extrabold mb-0 text-white" id="vehicleChallanModalLabel" style="font-size: 1.25rem; letter-spacing: -0.5px;">Traffic Violation Challan Details</h4>
            </div>
            <button type="button" class="btn-close btn-close-white rounded-circle p-2 bg-black bg-opacity-40 ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-3.5 p-md-4" style="background-color: #fafafa;">
            <?php if ($challan_paid_success): ?>
              <div class="alert alert-success rounded-4 border-0 p-3.5 mb-3 shadow-sm" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); color: #065f46;">
                <i class="bi bi-check-circle-fill fs-4 me-2 align-middle text-success"></i>
                <strong><?php echo htmlspecialchars($challan_paid_success); ?></strong>
              </div>
            <?php endif; ?>

            <!-- Traffic Authority Badge Banner -->
            <div class="d-flex align-items-center gap-2.5 bg-rose bg-opacity-10 border border-rose-subtle p-3 rounded-4 mb-3 text-danger small font-monospace fw-semibold" style="background-color: #fff1f2; border-color: #fecdd3 !important; color: #be123c;">
              <i class="bi bi-shield-check fs-4 flex-shrink-0"></i>
              <span style="font-size: 0.78rem; line-height: 1.35;">Official Traffic Violation Record &bull; Nagpur Traffic Police City Division</span>
            </div>

            <!-- Info Card Grid (100% Mobile Responsive Stack) -->
            <div class="p-3 rounded-4 border mb-3 shadow-sm bg-white" style="border-color: #f1f5f9 !important;">
              <!-- Owner Name -->
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1.5 p-2.5 rounded-3 mb-2" style="background-color: #f8fafc;">
                <span class="text-muted small fw-semibold"><i class="bi bi-person-fill text-secondary me-1.5"></i>Registered Owner:</span>
                <strong class="text-dark fs-6 font-monospace text-sm-end"><?php echo htmlspecialchars($searched_challan['owner_name']); ?></strong>
              </div>

              <!-- Violation Type -->
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1.5 p-2.5 rounded-3 mb-2" style="background-color: #fff1f2;">
                <span class="text-muted small fw-semibold"><i class="bi bi-exclamation-triangle-fill text-danger me-1.5"></i>Violation Offense:</span>
                <span class="badge rounded-pill text-white px-3 py-2 fw-bold text-wrap text-start text-sm-end" style="background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); font-size: 0.82rem; line-height: 1.35;">
                  <?php echo htmlspecialchars($searched_challan['violation_type']); ?>
                </span>
              </div>

              <!-- Location -->
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1.5 p-2.5 rounded-3 mb-2" style="background-color: #f8fafc;">
                <span class="text-muted small fw-semibold"><i class="bi bi-geo-alt-fill text-danger me-1.5"></i>Offense Location:</span>
                <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($searched_challan['location']); ?>" target="_blank" class="fw-bold text-danger text-decoration-none small text-sm-end">
                  <?php echo htmlspecialchars($searched_challan['location']); ?> ↗
                </a>
              </div>

              <!-- Timestamp -->
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1.5 p-2.5 rounded-3 mb-2" style="background-color: #f8fafc;">
                <span class="text-muted small fw-semibold"><i class="bi bi-clock-history text-secondary me-1.5"></i>Issued Timestamp:</span>
                <span class="small font-monospace text-muted text-sm-end"><?php echo date('d M Y, h:i A', strtotime($searched_challan['issued_at'])); ?></span>
              </div>

              <!-- Status -->
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1.5 p-2.5 rounded-3" style="background-color: #f8fafc;">
                <span class="text-muted small fw-semibold"><i class="bi bi-patch-exclamation-fill text-warning me-1.5"></i>Challan Status:</span>
                <?php if ($searched_challan['status'] === 'Paid'): ?>
                  <span class="badge bg-success text-white font-monospace px-3.5 py-1.5 rounded-pill shadow-sm align-self-start align-self-sm-center"><i class="bi bi-check-circle-fill me-1"></i>Paid & Cleared</span>
                <?php else: ?>
                  <span class="badge text-white font-monospace px-3.5 py-1.5 rounded-pill shadow-sm align-self-start align-self-sm-center" style="background: linear-gradient(135deg, #e11d48, #9f1239);"><i class="bi bi-exclamation-triangle-fill me-1"></i>Unpaid Pending</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Fine Amount Box & Glowing Ultra-Curvy Payment Button -->
            <div class="p-3.5 p-md-4 rounded-4 border text-center shadow-sm" style="background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%); border: 2px dashed #fda4af !important;">
              <span class="text-muted small font-monospace fw-bold text-uppercase d-block mb-1" style="letter-spacing: 0.5px;">Total Outstanding Fine Amount:</span>
              <h1 class="fw-extrabold text-danger font-monospace mb-3 display-6" style="letter-spacing: -1px; color: #be123c !important;">₹<?php echo number_format($searched_challan['challan_amount'], 2); ?></h1>

              <?php if ($searched_challan['status'] !== 'Paid'): ?>
                <form action="index.php" method="POST">
                  <input type="hidden" name="challan_id" value="<?php echo $searched_challan['id']; ?>">
                  <button type="submit" name="pay_vehicle_challan" class="btn btn-danger btn-lg w-100 font-monospace fw-extrabold py-3 shadow-lg" style="background: linear-gradient(135deg, #e11d48 0%, #be123c 50%, #881337 100%); border: none; border-radius: 50px !important; box-shadow: 0 12px 30px rgba(190, 18, 60, 0.45) !important; font-size: 1.05rem; letter-spacing: 0.5px;">
                    <i class="bi bi-credit-card-fill me-2"></i>Pay ₹<?php echo number_format($searched_challan['challan_amount'], 0); ?> Challan Now
                  </button>
                </form>
              <?php else: ?>
                <div class="alert alert-success mb-0 py-2.5 rounded-pill small fw-bold shadow-sm" style="background: #10b981; color: white;">
                  <i class="bi bi-check-circle-fill me-1.5 fs-5 align-middle"></i>This traffic challan has been paid in full!
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif ($challan_search_error): ?>
          <div class="modal-header bg-warning text-dark border-0 p-4" style="border-top-left-radius: 30px; border-top-right-radius: 30px;">
            <h5 class="fw-bold mb-0"><i class="bi bi-search me-2"></i>Traffic Challan Lookup</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4 text-center">
            <i class="bi bi-shield-check text-success display-2 mb-3 d-block"></i>
            <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($challan_search_error); ?></h5>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-2 mt-2" data-bs-dismiss="modal">Close Window</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($searched_challan || $challan_search_error): ?>
    <script>
      document.addEventListener("DOMContentLoaded", function() {
        const modalEl = document.getElementById("vehicleChallanModal");
        if (modalEl) {
          const bsModal = new bootstrap.Modal(modalEl);
          bsModal.show();
        }
      });
    </script>
  <?php endif; ?>

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
