<?php
// donation.php - Official Nagpur Mahanagar Palika Environmental & Civic Donation Portal
require_once __DIR__ . '/config.php';

$is_logged_in = is_logged_in();
$user = $is_logged_in ? get_logged_in_user($conn) : null;

$success_msg = '';
$error_msg = '';
$receipt_data = null;

// Determine active ward
$active_ward_id = $_SESSION['active_ward_id'] ?? ($user['ward_id'] ?? ($_SESSION['guest_ward_id'] ?? null));

// Ensure donations table exists
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS donations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        donor_name VARCHAR(255) NOT NULL,
        donor_email VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        cause VARCHAR(255) NOT NULL,
        payment_method VARCHAR(100) NOT NULL,
        receipt_no VARCHAR(100) NOT NULL,
        donated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Handle Donation Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_donation'])) {
    $donor_name = trim($_POST['donor_name'] ?? '');
    $donor_email = trim($_POST['donor_email'] ?? '');
    $amount = floatval($_POST['donation_amount'] ?? 0);
    $cause = trim($_POST['cause'] ?? 'General Municipal Environmental Fund');
    $payment_method = trim($_POST['payment_method'] ?? 'UPI / GPay');

    if (empty($donor_name) || empty($donor_email)) {
        $error_msg = "Please enter your full name and valid email address.";
    } elseif ($amount < 10) {
        $error_msg = "Minimum donation amount is ₹10.00.";
    } else {
        $receipt_no = "NMC-DON-" . date('Ymd') . "-" . rand(10000, 99999);
        $user_id = $is_logged_in ? $user['id'] : null;

        // Insert into database
        $stmt = $conn->prepare("INSERT INTO donations (user_id, donor_name, donor_email, amount, cause, payment_method, receipt_no) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $donor_name, $donor_email, $amount, $cause, $payment_method, $receipt_no]);

        // If logged in, award Eco Points XP (+25 XP per ₹100 donated)
        $earned_xp = floor(($amount / 100) * 25);
        if ($is_logged_in && $earned_xp > 0) {
            $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points + ? WHERE id = ?");
            $stmt->execute([$earned_xp, $user['id']]);

            // Log reward transaction
            $desc = "Civic Donation Reward: Awarded +{$earned_xp} XP for ₹" . number_format($amount, 2) . " donation";
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
            $stmt->execute([$user['id'], 0.00, $desc]);
        }

        $receipt_data = [
            'receipt_no' => $receipt_no,
            'name' => $donor_name,
            'email' => $donor_email,
            'amount' => $amount,
            'cause' => $cause,
            'method' => $payment_method,
            'xp' => $earned_xp,
            'date' => date('d M Y, h:i A')
        ];

        $success_msg = "🎉 Thank you for your generous contribution of ₹" . number_format($amount, 2) . "! Receipt #" . $receipt_no . " generated.";
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Civic Environmental & Infrastructure Fund - NMC Smart Portal</title>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="style.css">
  
  <style>
    .donation-hero {
      background: linear-gradient(135deg, #059669 0%, #10b981 50%, #047857 100%);
      color: white;
      border-radius: 24px;
      padding: 2.5rem 1.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 12px 30px rgba(5, 150, 105, 0.25);
    }
    .cause-card {
      border: 2px solid #e2e8f0;
      border-radius: 20px;
      padding: 1.5rem;
      background: white;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .cause-card:hover, .cause-card.selected {
      border-color: #10b981;
      background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
      transform: translateY(-4px);
      box-shadow: 0 12px 25px rgba(16, 185, 129, 0.15);
    }
    .payment-option-btn {
      border: 2px solid #e2e8f0;
      border-radius: 14px;
      padding: 12px 16px;
      background: white;
      cursor: pointer;
      transition: all 0.2s ease;
      width: 100%;
      text-align: left;
    }
    .payment-option-btn.active, .payment-option-btn:hover {
      border-color: #10b981;
      background: #f0fdf4;
    }
  </style>
</head>
<body>

  <!-- Reusable Top Navigation Bar -->
  <?php include __DIR__ . '/navbar.php'; ?>

  <!-- Main Content Container -->
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

    <!-- Donation Hero Banner Card -->
    <div class="donation-hero mb-4 text-white">
      <div class="position-relative z-1">
        <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-20 px-3.5 py-1.5 rounded-pill mb-3 small font-monospace fw-bold">
          <i class="bi bi-heart-fill text-warning fs-6"></i>
          <span>NAGPUR MUNICIPAL CIVIC IMPACT FUND</span>
        </div>
        <h1 class="fw-extrabold text-white mb-2" style="letter-spacing: -0.5px;">Support Our Green City Mission 🌳💧</h1>
        <p class="text-white opacity-90 mb-4" style="max-width: 680px; font-size: 0.98rem;">Your voluntary contribution directly powers urban tree plantation drives, clean drinking water kiosks, solar streetlights, and street pothole repairs across Nagpur wards.</p>

        <div class="d-flex flex-wrap gap-3">
          <div class="bg-white bg-opacity-10 backdrop-blur px-3.5 py-2.5 rounded-3 d-flex align-items-center gap-3">
            <i class="bi bi-shield-check text-warning display-6"></i>
            <div>
              <span class="d-block small text-white opacity-75">TAX DEDUCTION</span>
              <strong class="fs-5 font-monospace">Section 80G Certified</strong>
            </div>
          </div>

          <div class="bg-white bg-opacity-10 backdrop-blur px-3.5 py-2.5 rounded-3 d-flex align-items-center gap-3">
            <i class="bi bi-star-fill text-warning display-6"></i>
            <div>
              <span class="d-block small text-white opacity-75">BONUS ECO REWARD</span>
              <strong class="fs-5 font-monospace">+25 XP per ₹100</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Select Cause & Form Grid -->
    <div class="row g-4 mb-4">
      
      <!-- Left Column: Choose Impact Cause -->
      <div class="col-lg-7">
        <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-grid-fill text-success me-2"></i>Select Impact Cause & Contribution Preset</h5>
        
        <div class="row g-3 mb-4">
          <!-- Cause 1 -->
          <div class="col-md-6">
            <div class="cause-card selected h-100" data-amount="100" data-cause="10 Urban Tree Sapling Plantation Drive 🌳">
              <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; background: #dcfce7; color: #15803d;">
                  <i class="bi bi-tree-fill fs-5"></i>
                </div>
                <div>
                  <h6 class="fw-bold mb-0 text-dark">Plant 10 Trees</h6>
                  <strong class="text-success font-monospace fs-5">₹100.00</strong>
                </div>
              </div>
              <p class="text-muted small mb-0">Funds 10 green saplings planted in local Nagpur parks to absorb CO2.</p>
            </div>
          </div>

          <!-- Cause 2 -->
          <div class="col-md-6">
            <div class="cause-card h-100" data-amount="250" data-cause="Clean Water Filtration Kiosk Installation 💧">
              <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; background: #e0f2fe; color: #0369a1;">
                  <i class="bi bi-droplet-fill fs-5"></i>
                </div>
                <div>
                  <h6 class="fw-bold mb-0 text-dark">Clean Water Kiosk</h6>
                  <strong class="text-primary font-monospace fs-5">₹250.00</strong>
                </div>
              </div>
              <p class="text-muted small mb-0">Provides purified drinking water access at public bus stops.</p>
            </div>
          </div>

          <!-- Cause 3 -->
          <div class="col-md-6">
            <div class="cause-card h-100" data-amount="500" data-cause="Urgent Street Pothole Repair Project 🛠️">
              <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; background: #f3e8ff; color: #7e22ce;">
                  <i class="bi bi-tools fs-5"></i>
                </div>
                <div>
                  <h6 class="fw-bold mb-0 text-dark">Pothole Repair Fund</h6>
                  <strong class="text-purple font-monospace fs-5" style="color: #7e22ce;">₹500.00</strong>
                </div>
              </div>
              <p class="text-muted small mb-0">Supplies cold-mix asphalt patch kits to repair high-traffic street damage.</p>
            </div>
          </div>

          <!-- Cause 4 -->
          <div class="col-md-6">
            <div class="cause-card h-100" data-amount="1000" data-cause="Solar Streetlight Safety Kit ☀️">
              <div class="d-flex align-items-center gap-3 mb-2">
                <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; background: #fef3c7; color: #d97706;">
                  <i class="bi bi-sun-fill fs-5"></i>
                </div>
                <div>
                  <h6 class="fw-bold mb-0 text-dark">Solar Streetlight Kit</h6>
                  <strong class="text-warning font-monospace fs-5" style="color: #d97706;">₹1,000.00</strong>
                </div>
              </div>
              <p class="text-muted small mb-0">Installs solar-powered LED lighting fixtures in neighborhood lanes.</p>
            </div>
          </div>
        </div>

        <!-- Custom Donation Amount Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
          <h6 class="fw-bold text-dark mb-2"><i class="bi bi-pencil-square text-success me-2"></i>Custom Contribution Amount</h6>
          <div class="input-group input-group-lg rounded-pill overflow-hidden border">
            <span class="input-group-text bg-light border-0 px-3.5 font-monospace fw-extrabold text-success">₹</span>
            <input type="number" id="input-custom-amount" class="form-control border-0 font-monospace fw-bold text-dark" placeholder="Enter amount (min ₹10)..." value="100" min="10" step="10">
          </div>
        </div>
      </div>

      <!-- Right Column: Donor Information & Payment Form -->
      <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100">
          <h5 class="fw-bold mb-3 text-dark"><i class="bi bi-credit-card-fill text-success me-2"></i>Donor Information & Payment</h5>

          <form action="" method="POST" id="donation-form">
            <input type="hidden" name="donation_amount" id="final-donation-amount" value="100">
            <input type="hidden" name="cause" id="final-donation-cause" value="10 Urban Tree Sapling Plantation Drive 🌳">

            <div class="mb-3">
              <label class="form-label fw-bold small text-dark">Full Name</label>
              <input type="text" name="donor_name" class="form-control rounded-3 py-2" placeholder="e.g. Ayush Sharma" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold small text-dark">Email Address (for Official 80G Receipt)</label>
              <input type="email" name="donor_email" class="form-control rounded-3 py-2" placeholder="e.g. ayush@gmail.com" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
            </div>

            <div class="mb-4">
              <label class="form-label fw-bold small text-dark mb-2">Payment Method</label>
              <div class="d-flex flex-column gap-2">
                <label class="payment-option-btn active d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center gap-2">
                    <input type="radio" name="payment_method" value="UPI / GPay / PhonePe 📲" checked class="form-check-input mt-0">
                    <span class="fw-bold small text-dark">UPI / GPay / PhonePe / Paytm</span>
                  </div>
                  <i class="bi bi-qr-code-scan text-success fs-5"></i>
                </label>

                <label class="payment-option-btn d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center gap-2">
                    <input type="radio" name="payment_method" value="Credit / Debit Card 💳" class="form-check-input mt-0">
                    <span class="fw-bold small text-dark">Credit / Debit Card</span>
                  </div>
                  <i class="bi bi-credit-card text-primary fs-5"></i>
                </label>

                <label class="payment-option-btn d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center gap-2">
                    <input type="radio" name="payment_method" value="Civic Wallet Cash 💼" class="form-check-input mt-0">
                    <span class="fw-bold small text-dark">Civic Wallet Cash (Balance: ₹<?php echo number_format($user['wallet_balance'] ?? 0, 2); ?>)</span>
                  </div>
                  <i class="bi bi-wallet2 text-warning fs-5"></i>
                </label>
              </div>
            </div>

            <!-- Summary Box -->
            <div class="p-3 rounded-4 bg-light border mb-4">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small text-muted">Contribution:</span>
                <strong class="font-monospace text-dark fs-5" id="summary-display-amount">₹100.00</strong>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="small text-muted">Earned XP:</span>
                <span class="badge bg-success rounded-pill font-monospace" id="summary-display-xp">+25 Eco XP 🍃</span>
              </div>
            </div>

            <button type="submit" name="submit_donation" class="btn btn-success btn-lg w-100 rounded-pill font-monospace fw-extrabold py-3 shadow-lg" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border: none;">
              <i class="bi bi-heart-fill me-2"></i>Complete Contribution & Get Receipt 🚀
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Reusable Mobile Dock Navigation & Modals -->
  <?php include __DIR__ . '/bottom_dock.php'; ?>

  <!-- Official Receipt Pop-up Modal -->
  <?php if ($receipt_data): ?>
    <div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true" style="backdrop-filter: blur(8px);">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
          <div class="modal-header border-0 bg-success text-white py-3.5 px-4" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-patch-check-fill fs-3 text-warning"></i>
              <div>
                <h5 class="modal-title fw-extrabold mb-0" id="receiptModalLabel">Official Municipal Tax Receipt</h5>
                <span class="small opacity-90 font-monospace">SECTION 80G CERTIFIED CIVIC DONATION</span>
              </div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-4 bg-white">
            <div class="text-center mb-4 pb-3 border-bottom">
              <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 rounded-pill font-monospace fw-bold mb-2">RECEIPT #<?php echo htmlspecialchars($receipt_data['receipt_no']); ?></span>
              <h2 class="display-6 fw-extrabold text-success font-monospace mb-1">₹<?php echo number_format($receipt_data['amount'], 2); ?></h2>
              <span class="small text-muted">Paid via <?php echo htmlspecialchars($receipt_data['method']); ?> on <?php echo $receipt_data['date']; ?></span>
            </div>

            <div class="d-flex flex-column gap-2 small text-dark mb-4">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Donor Name:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($receipt_data['name']); ?></strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Donor Email:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($receipt_data['email']); ?></strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Impact Cause:</span>
                <strong class="text-dark text-end" style="max-width: 220px;"><?php echo htmlspecialchars($receipt_data['cause']); ?></strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Eco Points Bonus:</span>
                <strong class="text-success">+<?php echo $receipt_data['xp']; ?> XP Credited</strong>
              </div>
            </div>

            <div class="p-3 rounded-3 bg-light border text-center small text-muted mb-4 font-monospace">
              Nagpur Mahanagar Palika Civic Fund • Tax Exempt Under Sec 80G
            </div>

            <div class="d-flex gap-2">
              <button type="button" onclick="window.print()" class="btn btn-outline-secondary rounded-pill w-100 py-2.5 fw-bold"><i class="bi bi-printer-fill me-1.5"></i>Print Receipt</button>
              <button type="button" class="btn btn-success rounded-pill w-100 py-2.5 fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
      document.addEventListener("DOMContentLoaded", function() {
        const modalEl = document.getElementById("receiptModal");
        if (modalEl) {
          const bsModal = new bootstrap.Modal(modalEl);
          bsModal.show();
        }
      });
    </script>
  <?php endif; ?>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const causeCards = document.querySelectorAll(".cause-card");
      const customInput = document.getElementById("input-custom-amount");
      const finalAmountInput = document.getElementById("final-donation-amount");
      const finalCauseInput = document.getElementById("final-donation-cause");
      const summaryAmount = document.getElementById("summary-display-amount");
      const summaryXp = document.getElementById("summary-display-xp");

      function updateSummary(amount, causeStr) {
        finalAmountInput.value = amount;
        if (causeStr) finalCauseInput.value = causeStr;
        summaryAmount.textContent = "₹" + parseFloat(amount).toFixed(2);
        const xp = Math.floor((parseFloat(amount) / 100) * 25);
        summaryXp.textContent = "+" + xp + " Eco XP 🍃";
      }

      causeCards.forEach(card => {
        card.addEventListener("click", function() {
          causeCards.forEach(c => c.classList.remove("selected"));
          this.classList.add("selected");
          const amount = this.getAttribute("data-amount");
          const cause = this.getAttribute("data-cause");
          customInput.value = amount;
          updateSummary(amount, cause);
        });
      });

      customInput.addEventListener("input", function() {
        causeCards.forEach(c => c.classList.remove("selected"));
        const val = parseFloat(this.value) || 0;
        updateSummary(val, "Custom Environmental & Infrastructure Support");
      });

      // Payment option toggle active class
      document.querySelectorAll('.payment-option-btn').forEach(btn => {
        btn.addEventListener('click', function() {
          document.querySelectorAll('.payment-option-btn').forEach(b => b.classList.remove('active'));
          this.classList.add('active');
          const radio = this.querySelector('input[type="radio"]');
          if (radio) radio.checked = true;
        });
      });
    });
  </script>
</body>
</html>
