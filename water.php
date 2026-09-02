<?php
// water.php - Dedicated Municipal Water Supply, Quality & Tanker Booking Portal
require_once __DIR__ . '/config.php';

$is_logged_in = is_logged_in();
$user = $is_logged_in ? get_logged_in_user($conn) : null;

$success_msg = '';
$error_msg = '';

// Determine active ward
$active_ward_id = $_SESSION['active_ward_id'] ?? ($user['ward_id'] ?? ($_SESSION['guest_ward_id'] ?? null));

// Handle Water Bill Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_water_bill'])) {
    if ($is_logged_in) {
        $_SESSION['water_bill_paid'] = true;
        $success_msg = "Water Utility Bill of ₹25.00 paid successfully! Receipt #NMC-WAT-" . rand(10000, 99999) . " generated.";
    } else {
        $error_msg = "Please sign in to process bill payment.";
    }
}

// Handle Water Tanker Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_tanker'])) {
    $area = trim($_POST['area_name'] ?? '');
    $liters = intval($_POST['tanker_capacity'] ?? 1000);
    $contact = trim($_POST['contact_phone'] ?? '');
    
    if (empty($area) || empty($contact)) {
        $error_msg = "Please provide area locality and contact mobile number.";
    } else {
        if ($is_logged_in && $active_ward_id) {
            $stmt = $conn->prepare("INSERT INTO utility_complaints (user_id, ward_id, type, complaint_type, details, status) VALUES (?, ?, 'water', 'emergency_tanker', ?, 'Pending')");
            $stmt->execute([$user['id'], $active_ward_id, "Emergency Water Tanker Request ({$liters}L) at {$area}. Contact: {$contact}"]);
        }
        $success_msg = "Emergency Water Tanker Request logged successfully! NMC Water Department dispatched tanker to {$area}. Tanker Booking ID: #WT-" . rand(100, 999);
    }
}

// Fetch Wards
$wards_stmt = $conn->query("SELECT * FROM wards ORDER BY name ASC");
$wards = $wards_stmt ? $wards_stmt->fetchAll() : [];

$active_ward_name = '';
if ($active_ward_id) {
    foreach ($wards as $w) {
        if ($w['id'] == $active_ward_id) {
            $active_ward_name = $w['name'];
            break;
        }
    }
}

// Fetch Water Schedule for Active Ward
$water_schedules = [];
if ($active_ward_id) {
    $stmt = $conn->prepare("SELECT * FROM water_schedule WHERE ward_id = ? ORDER BY date DESC, start_time ASC");
    $stmt->execute([$active_ward_id]);
    $water_schedules = $stmt->fetchAll();
}

$user_wallet = $user['wallet_balance'] ?? 0.00;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Water Supply & Quality Monitoring - NMC Smart Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="style.css">
  <style>
    .water-hero {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 50%, #075985 100%);
      color: white;
      border-radius: 24px;
      padding: 2.5rem 1.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(2, 132, 199, 0.25);
    }
    .water-quality-card {
      background: white;
      border-radius: 20px;
      padding: 1.5rem;
      border: 1px solid #e0f2fe;
      box-shadow: 0 4px 15px rgba(2, 132, 199, 0.06);
    }
    .schedule-box {
      border-left: 4px solid #0284c7;
      background: #f0f9ff;
      border-radius: 12px;
      padding: 1rem;
    }
  </style>
</head>
<body>

  <!-- Reusable Top Navigation Bar -->
  <?php include __DIR__ . '/navbar.php'; ?>

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

    <!-- Water Portal Hero Banner -->
    <div class="water-hero mb-4">
      <div class="row align-items-center g-3">
        <div class="col-md-8">
          <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-3">
            <i class="bi bi-droplet-half text-warning"></i>
            <span class="small fw-bold text-white">Nagpur Municipal 24x7 Water Grid</span>
          </div>
          <h1 class="fw-bold mb-2">Water Supply & Quality Dashboard 💧</h1>
          <p class="mb-3 text-white opacity-90 fs-6">
            Track daily water supply timings, monitor potability water quality metrics, request emergency water tankers, and pay water utility bills seamlessly!
          </p>

          <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-white bg-opacity-25 text-white border border-white border-opacity-25 px-3 py-2 rounded-pill font-monospace">
              <i class="bi bi-geo-alt-fill text-warning me-1"></i>Active Ward: <?php echo $active_ward_name ? htmlspecialchars($active_ward_name) : 'Nagpur (Set Ward)'; ?>
            </span>
            <span class="badge bg-success text-white px-3 py-2 rounded-pill font-monospace">
              <i class="bi bi-check-circle-fill me-1"></i>Supply Grid Active
            </span>
          </div>
        </div>

        <div class="col-md-4 text-md-end">
          <a href="#tanker-section" class="btn btn-light text-primary rounded-pill px-4 py-2.5 font-monospace fw-bold shadow">
            <i class="bi bi-truck me-1"></i>Request Water Tanker
          </a>
        </div>
      </div>
    </div>

    <!-- Live Water Quality Indicators -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="water-quality-card text-center">
          <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-1 mb-2 fw-bold">pH Level</span>
          <h3 class="fw-extrabold text-dark mb-0">7.2 pH</h3>
          <span class="small text-success fw-semibold"><i class="bi bi-shield-check me-1"></i>Optimal Drinking Water</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="water-quality-card text-center">
          <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1 mb-2 fw-bold">Turbidity</span>
          <h3 class="fw-extrabold text-dark mb-0">0.4 NTU</h3>
          <span class="small text-success fw-semibold"><i class="bi bi-check-all me-1"></i>Ultra Clear (< 1 NTU)</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="water-quality-card text-center">
          <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1 mb-2 fw-bold">Chlorine Residual</span>
          <h3 class="fw-extrabold text-dark mb-0">0.5 mg/L</h3>
          <span class="small text-success fw-semibold"><i class="bi bi-virus me-1"></i>100% Disinfected</span>
        </div>
      </div>

      <div class="col-6 col-md-3">
        <div class="water-quality-card text-center">
          <span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-3 py-1 mb-2 fw-bold">Reservoir Level</span>
          <h3 class="fw-extrabold text-dark mb-0">94.8%</h3>
          <span class="small text-primary fw-semibold"><i class="bi bi-water me-1"></i>High Reserve Capacity</span>
        </div>
      </div>
    </div>

    <div class="row g-4 mb-4">
      <!-- Left Column: Daily Water Supply Timelines -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4 h-100">
          <h5 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history text-primary me-2"></i>Daily Water Supply Timelines</h5>

          <?php if (empty($water_schedules)): ?>
            <div class="schedule-box mb-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h6 class="fw-bold text-dark mb-1"><i class="bi bi-sunrise-fill text-warning me-2"></i>Morning Supply Session</h6>
                  <span class="small text-muted font-monospace">06:00 AM - 08:30 AM (2 hrs 30 mins)</span>
                </div>
                <span class="badge bg-success rounded-pill px-3 py-1.5"><i class="bi bi-check-circle me-1"></i>Completed Today</span>
              </div>
            </div>

            <div class="schedule-box">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <h6 class="fw-bold text-dark mb-1"><i class="bi bi-sunset-fill text-primary me-2"></i>Evening Supply Session</h6>
                  <span class="small text-muted font-monospace">05:30 PM - 07:30 PM (2 hrs)</span>
                </div>
                <span class="badge bg-primary rounded-pill px-3 py-1.5"><i class="bi bi-hourglass-split me-1"></i>Scheduled Next</span>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($water_schedules as $sched): ?>
              <div class="schedule-box mb-3">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <h6 class="fw-bold text-dark mb-1"><i class="bi bi-droplet-fill text-info me-2"></i><?php echo htmlspecialchars($sched['area_name']); ?></h6>
                    <span class="small text-muted font-monospace">
                      <?php echo date('h:i A', strtotime($sched['start_time'])); ?> - <?php echo date('h:i A', strtotime($sched['end_time'])); ?>
                    </span>
                  </div>
                  <span class="badge bg-success rounded-pill px-3 py-1.5"><i class="bi bi-check-circle me-1"></i>Active Today</span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- Water Utility Bill Card -->
          <div class="border rounded-4 p-3 bg-light mt-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>NMC Water Utility Bill (June 2026)</h6>
              <?php if (isset($_SESSION['water_bill_paid'])): ?>
                <span class="badge bg-success text-white rounded-pill px-3 py-1"><i class="bi bi-check-circle me-1"></i>Fully Paid (₹25.00)</span>
              <?php else: ?>
                <span class="small text-muted">Outstanding Balance: <strong class="text-danger fs-6">₹25.00</strong></span>
              <?php endif; ?>
            </div>

            <?php if (!isset($_SESSION['water_bill_paid'])): ?>
              <form action="" method="POST">
                <button type="submit" name="pay_water_bill" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                  <i class="bi bi-credit-card me-1"></i>Pay ₹25.00 Bill
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Right Column: Emergency Water Tanker Request Form -->
      <div class="col-lg-5" id="tanker-section">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white p-4 h-100">
          <h5 class="fw-bold text-dark mb-1"><i class="bi bi-truck text-primary me-2"></i>Request Emergency Water Tanker</h5>
          <p class="text-muted small mb-4">Request rapid dispatch of an NMC Municipal Water Tanker directly to your doorstep in Nagpur.</p>

          <form action="" method="POST">
            <div class="mb-3">
              <label for="area_name" class="form-label fw-bold small text-dark">Locality Street Address</label>
              <input type="text" name="area_name" id="area_name" class="form-control rounded-3" placeholder="e.g. Plot 42, Civil Lines, Nagpur" required>
            </div>

            <div class="mb-3">
              <label for="tanker_capacity" class="form-label fw-bold small text-dark">Required Tanker Capacity</label>
              <select name="tanker_capacity" id="tanker_capacity" class="form-select rounded-3 font-monospace">
                <option value="1000">1,000 Liters (Standard Household)</option>
                <option value="3000">3,000 Liters (Apartment Complex)</option>
                <option value="5000">5,000 Liters (Community Supply)</option>
              </select>
            </div>

            <div class="mb-4">
              <label for="contact_phone" class="form-label fw-bold small text-dark">Contact Mobile Number</label>
              <input type="text" name="contact_phone" id="contact_phone" class="form-control rounded-3" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="10-digit mobile number" required>
            </div>

            <button type="submit" name="request_tanker" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow-sm" style="background-color: #0284c7 !important; border-color: #0284c7 !important;">
              <i class="bi bi-send-fill me-1"></i>Dispatch Water Tanker Now
            </button>
          </form>
        </div>
      </div>
    </div>

  </div>

  <!-- Mobile Dock Bottom Spacer -->
  <div class="d-block d-md-none" style="height: 80px; width: 100%;"></div>

  <!-- Reusable Mobile Dock Navigation & Modals -->
  <?php include __DIR__ . '/bottom_dock.php'; ?>

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
