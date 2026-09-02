<?php
// transit.php - Nagpur Aapli Bus & Maha Metro Real-Time Public Transport Portal
require_once __DIR__ . '/config.php';

$is_logged_in = is_logged_in();
$user = $is_logged_in ? get_logged_in_user($conn) : null;

$success_msg = '';
$error_msg = '';

// Handle Bus Pass Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_transit_pass'])) {
    $pass_type = trim($_POST['pass_type'] ?? 'Daily Unlimited Aapli Bus Pass');
    $price = floatval($_POST['price'] ?? 50.00);

    if ($is_logged_in) {
        if (($user['wallet_balance'] ?? 0) >= $price) {
            // Deduct balance
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmt->execute([$price, $user['id']]);

            // Record transaction
            $desc = "Transit Pass Purchase: " . $pass_type;
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'debit', ?)");
            $stmt->execute([$user['id'], $price, $desc]);

            $success_msg = "🎉 Instant Pass Issued! " . htmlspecialchars($pass_type) . " (₹" . number_format($price, 2) . ") activated on your Citizen ID.";
            $user = get_logged_in_user($conn);
        } else {
            $error_msg = "Insufficient wallet balance. Please add funds or use UPI payment.";
        }
    } else {
        $success_msg = "🎉 Guest Pass Generated! QR Ticket issued for " . htmlspecialchars($pass_type) . " (₹" . number_format($price, 2) . ").";
    }
}

// Fetch Wards
$wards_stmt = $conn->query("SELECT * FROM wards ORDER BY name ASC");
$wards = $wards_stmt ? $wards_stmt->fetchAll() : [];

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

// Real-time Transit Schedule Data
$transit_routes = [
    [
        'id' => 'R1A',
        'type' => 'Aapli Bus (EV)',
        'route_name' => 'Route 1A • Sitabuldi ↔ Hingna Depot',
        'next_stop' => 'Dhantoli Zone 4 Stop',
        'eta' => 2,
        'status' => 'On Time',
        'status_color' => 'success',
        'occupancy' => 'Low (30%)',
        'fare' => 15.00,
        'lat' => 21.1458,
        'lng' => 79.0882,
        'badge' => '⚡ 100% Electric EV'
    ],
    [
        'id' => 'R5',
        'type' => 'Aapli Bus',
        'route_name' => 'Route 5 • Dharampeth ↔ Kamptee Road',
        'next_stop' => 'Variety Square',
        'eta' => 7,
        'status' => 'Delayed 4m',
        'status_color' => 'warning',
        'occupancy' => 'Moderate (65%)',
        'fare' => 20.00,
        'lat' => 21.1490,
        'lng' => 79.0810,
        'badge' => '🚌 CNG Green Fleet'
    ],
    [
        'id' => 'METRO-ORANGE',
        'type' => 'Maha Metro',
        'route_name' => 'Orange Line • Automotive Square ↔ Khapri',
        'next_stop' => 'Sitabuldi Metro Interchange',
        'eta' => 4,
        'status' => 'Express On Time',
        'status_color' => 'info',
        'occupancy' => 'Light (40%)',
        'fare' => 25.00,
        'lat' => 21.1465,
        'lng' => 79.0885,
        'badge' => '🚇 High Speed Metro'
    ],
    [
        'id' => 'R12',
        'type' => 'Aapli Bus (EV)',
        'route_name' => 'Route 12 • Airport Express ↔ Mihan IT Park',
        'next_stop' => 'Nagpur Airport Terminal 1',
        'eta' => 11,
        'status' => 'On Time',
        'status_color' => 'success',
        'occupancy' => 'Low (20%)',
        'fare' => 30.00,
        'lat' => 21.0922,
        'lng' => 79.0472,
        'badge' => '✈️ Airport AC Shuttle'
    ],
    [
        'id' => 'METRO-AQUA',
        'type' => 'Maha Metro',
        'route_name' => 'Aqua Line • Prajapati Nagar ↔ Lokmanya Nagar',
        'next_stop' => 'Subhash Nagar Station',
        'eta' => 6,
        'status' => 'Express On Time',
        'status_color' => 'info',
        'occupancy' => 'Moderate (55%)',
        'fare' => 20.00,
        'lat' => 21.1350,
        'lng' => 79.0620,
        'badge' => '🚇 Solar Powered Metro'
    ]
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Real-Time Public Transport Grid - Aapli Bus & Maha Metro</title>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="style.css">
  
  <style>
    .transit-hero {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 50%, #0f172a 100%);
      color: white;
      border-radius: 24px;
      padding: 2.5rem 1.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 12px 30px rgba(2, 132, 199, 0.25);
    }
    #transit-map {
      height: 380px;
      width: 100%;
      border-radius: 20px;
      z-index: 1;
    }
    .transit-card {
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      background: white;
    }
    .transit-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 25px rgba(2, 132, 199, 0.15);
      border-color: #38bdf8;
    }
    .eta-badge {
      font-size: 1.1rem;
      font-weight: 800;
      padding: 8px 16px;
      border-radius: 50px;
      background: #f0f9ff;
      color: #0369a1;
      border: 1px solid #bae6fd;
    }
  </style>
</head>
<body>

  <!-- Reusable Top Navigation Bar -->
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="container my-4">

    <?php if (!empty($success_msg)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i><?php echo htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
      <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i><?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Transit Hero Banner -->
    <div class="transit-hero mb-4 text-white">
      <div class="position-relative z-1">
        <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-20 px-3.5 py-1.5 rounded-pill mb-3 small font-monospace fw-bold">
          <i class="bi bi-bus-front-fill text-warning fs-6"></i>
          <span>NMC AAPLI BUS & MAHA METRO REAL-TIME TRACKER</span>
        </div>

        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
          <div>
            <h1 class="fw-extrabold text-white mb-2" style="letter-spacing: -0.5px;">Live City Transit & Bus Grid 🚌🚇</h1>
            <p class="text-white opacity-90 mb-3" style="max-width: 650px; font-size: 0.98rem;">Real-time GPS tracking for 180+ Aapli Buses and Maha Metro lines. Check live arrival countdowns, fare calculators, and book instant QR transit passes.</p>
          </div>

          <div>
            <button type="button" class="btn btn-warning btn-lg rounded-pill font-monospace fw-extrabold px-4 py-3 shadow-lg text-dark text-nowrap" data-bs-toggle="modal" data-bs-target="#buyPassModal">
              <i class="bi bi-qr-code-scan me-2"></i>Book Daily Pass (₹50) 🎟️
            </button>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-2">
          <span class="badge bg-white bg-opacity-15 text-white font-monospace px-3 py-2 rounded-pill"><i class="bi bi-wifi text-warning me-1"></i>Live GPS Telematics Connected</span>
          <span class="badge bg-white bg-opacity-15 text-white font-monospace px-3 py-2 rounded-pill"><i class="bi bi-lightning-charge-fill text-success me-1"></i>42 Zero-Emission Electric Buses</span>
          <span class="badge bg-white bg-opacity-15 text-white font-monospace px-3 py-2 rounded-pill"><i class="bi bi-geo-alt-fill text-info me-1"></i>Sitabuldi Metro Interchange Active</span>
        </div>
      </div>
    </div>

    <!-- Live GPS Transit Map Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white">
      <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-map-fill text-primary me-2"></i>Live Transit Fleet GPS Map</h5>
          <span class="small text-muted">Tracking real-time location of active Aapli Buses & Metro Trains across Nagpur</span>
        </div>
        <div class="d-flex gap-2">
          <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill font-monospace"><i class="bi bi-record-fill text-danger me-1 animate-pulse"></i>184 Active Vehicles</span>
        </div>
      </div>
      <div class="card-body p-2">
        <div id="transit-map"></div>
      </div>
    </div>

    <!-- Real-Time Arrival Countdowns Grid -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="fw-bold text-dark mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Real-Time Arrivals & Live Countdowns</h5>
      <span class="small font-monospace text-muted">Auto-refreshes every 15 seconds</span>
    </div>

    <div class="row g-3 mb-4">
      <?php foreach ($transit_routes as $r): ?>
        <div class="col-lg-6">
          <div class="transit-card p-3.5 h-100">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 rounded-pill font-monospace small mb-1 fw-bold"><?php echo htmlspecialchars($r['badge']); ?></span>
                <h6 class="fw-extrabold text-dark mb-0" style="font-size: 1.05rem;"><?php echo htmlspecialchars($r['route_name']); ?></h6>
              </div>
              <div class="eta-badge shadow-sm text-nowrap">
                <i class="bi bi-clock-fill text-primary me-1"></i><?php echo $r['eta']; ?> mins
              </div>
            </div>

            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3 pt-3 border-top text-muted small">
              <div>
                <i class="bi bi-geo-alt-fill text-danger me-1"></i>Next Stop: <strong class="text-dark"><?php echo htmlspecialchars($r['next_stop']); ?></strong>
              </div>
              <div class="d-flex align-items-center gap-2 font-monospace">
                <span class="badge bg-<?php echo $r['status_color']; ?>"><?php echo htmlspecialchars($r['status']); ?></span>
                <span class="text-dark fw-bold">Fare: ₹<?php echo number_format($r['fare'], 2); ?></span>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  </div>

  <!-- Buy Transit Pass Modal -->
  <div class="modal fade" id="buyPassModal" tabindex="-1" aria-labelledby="buyPassModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
        <div class="modal-header border-0 bg-primary text-white py-3 px-4">
          <h5 class="modal-title fw-bold mb-0" id="buyPassModalLabel"><i class="bi bi-qr-code-scan me-2"></i>Instant Aapli Bus & Metro Pass</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <form action="" method="POST">
            <div class="mb-3">
              <label class="form-label fw-bold text-dark">Select Pass Ticket Type</label>
              <select name="pass_type" class="form-select rounded-3 font-monospace fw-bold" id="pass-type-select">
                <option value="Daily Unlimited Aapli Bus Pass (₹50)" data-price="50">Daily Unlimited Aapli Bus Pass — ₹50.00</option>
                <option value="Single Metro + Bus Combo Ticket (₹25)" data-price="25">Single Metro + Bus Combo Ticket — ₹25.00</option>
                <option value="Monthly Student Transit Pass (₹350)" data-price="350">Monthly Student Transit Pass — ₹350.00</option>
              </select>
            </div>

            <input type="hidden" name="price" id="pass-price-input" value="50">

            <div class="p-3 rounded-4 bg-light border mb-4">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small text-muted">Pass Total:</span>
                <strong class="font-monospace text-success fs-4" id="pass-total-display">₹50.00</strong>
              </div>
              <span class="small text-muted">Active Citizen Balance: <strong>₹<?php echo number_format($user['wallet_balance'] ?? 0, 2); ?></strong></span>
            </div>

            <button type="submit" name="buy_transit_pass" class="btn btn-success btn-lg w-100 rounded-pill font-monospace fw-extrabold py-3 shadow-sm">
              <i class="bi bi-credit-card-fill me-2"></i>Issue Instant QR Ticket 🎟️
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Reusable Mobile Navigation Dock & Modals -->
  <?php include __DIR__ . '/bottom_dock.php'; ?>

  <!-- Leaflet Map JS -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      // Initialize Nagpur Transit GPS Map
      const map = L.map('transit-map').setView([21.1458, 79.0882], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      // Bus & Metro Markers
      const transitPoints = [
        { name: "Sitabuldi Metro Interchange Hub", lat: 21.1465, lng: 79.0885, type: "Metro Station", color: "#0284c7" },
        { name: "Aapli EV Bus #R1A (On Route)", lat: 21.1458, lng: 79.0882, type: "EV Bus", color: "#10b981" },
        { name: "Aapli CNG Bus #R5 (Dharampeth)", lat: 21.1490, lng: 79.0810, type: "CNG Bus", color: "#f59e0b" },
        { name: "Airport Metro Station (Orange Line)", lat: 21.0922, lng: 79.0472, type: "Metro Station", color: "#0284c7" },
        { name: "Subhash Nagar Metro Station (Aqua Line)", lat: 21.1350, lng: 79.0620, type: "Metro Station", color: "#0284c7" }
      ];

      transitPoints.forEach(pt => {
        const marker = L.circleMarker([pt.lat, pt.lng], {
          radius: 9,
          fillColor: pt.color,
          color: "#ffffff",
          weight: 2,
          opacity: 1,
          fillOpacity: 0.9
        }).addTo(map);

        marker.bindPopup(`<strong>${pt.name}</strong><br><span class="small text-muted">${pt.type}</span>`);
      });

      // Pass select handler
      const passSelect = document.getElementById("pass-type-select");
      const passPriceInput = document.getElementById("pass-price-input");
      const passTotalDisplay = document.getElementById("pass-total-display");

      if (passSelect) {
        passSelect.addEventListener("change", function() {
          const opt = this.options[this.selectedIndex];
          const price = opt.getAttribute("data-price");
          passPriceInput.value = price;
          passTotalDisplay.textContent = "₹" + parseFloat(price).toFixed(2);
        });
      }
    });
  </script>
</body>
</html>
