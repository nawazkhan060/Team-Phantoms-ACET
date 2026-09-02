<?php
// redeem.php - Dedicated Eco Points & Civic Rewards Redemption Marketplace
require_once 'config.php';

$is_logged_in = is_logged_in();
$user = null;
$user_points = 0;
$user_wallet = 0.00;
$user_level = 1;
$transactions = [];

if ($is_logged_in) {
    $user = get_logged_in_user($conn);
    if ($user) {
        $user_points = intval($user['eco_points'] ?? 0);
        $user_wallet = floatval($user['wallet_balance'] ?? 0.00);
        $user_level = floor($user_points / 50) + 1;

        // Fetch user transaction history
        $stmt = $conn->prepare("SELECT * FROM reward_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$user['id']]);
        $transactions = $stmt->fetchAll();
    }
}

$success_msg = '';
$error_msg = '';

// Handle Point Redemption POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_tier'])) {
    if (!$is_logged_in) {
        $error_msg = "Please sign in to redeem your Eco Points for wallet cash & vouchers!";
    } else {
        $tier = intval($_POST['redeem_tier'] ?? 0);
        $pts_needed = 0;
        $cash_reward = 0.00;
        $tier_name = '';

        if ($tier === 1) {
            $pts_needed = 50;
            $cash_reward = 10.00;
            $tier_name = 'Starter Reward (50 XP -> ₹10.00)';
        } elseif ($tier === 2) {
            $pts_needed = 100;
            $cash_reward = 25.00; // Bonus ₹5
            $tier_name = 'Silver Reward (100 XP -> ₹25.00)';
        } elseif ($tier === 3) {
            $pts_needed = 200;
            $cash_reward = 55.00; // Bonus ₹15
            $tier_name = 'Gold Reward (200 XP -> ₹55.00)';
        }

        if ($pts_needed > 0 && $user_points >= $pts_needed) {
            // Deduct points and credit wallet balance
            $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points - ?, wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->execute([$pts_needed, $cash_reward, $user['id']]);

            // Log transaction
            $desc = "Eco Point Redemption: {$tier_name}";
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
            $stmt->execute([$user['id'], $cash_reward, $desc]);

            // Refresh user info
            $user = get_logged_in_user($conn);
            $user_points = intval($user['eco_points'] ?? 0);
            $user_wallet = floatval($user['wallet_balance'] ?? 0.00);

            $success_msg = "🎉 Congratulations! Successfully redeemed {$pts_needed} Eco XP for ₹" . number_format($cash_reward, 2) . " Wallet Cash!";
        } else {
            $error_msg = "Insufficient Eco Points! You need at least {$pts_needed} XP for this tier. Keep scanning carbon products & filing civic complaints!";
        }
    }
}

// Handle Physical Product Redemption POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_product'])) {
    if (!$is_logged_in) {
        $error_msg = "Please sign in to claim physical merchandise!";
    } else {
        $prod_name = trim($_POST['product_name'] ?? '');
        $pts_needed = intval($_POST['points_cost'] ?? 0);

        if ($pts_needed > 0 && $user_points >= $pts_needed) {
            // Deduct points
            $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points - ? WHERE id = ?");
            $stmt->execute([$pts_needed, $user['id']]);

            // Log transaction
            $desc = "Claimed Physical Merchandise: {$prod_name} (Ready for Ward Pickup)";
            $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, 0.00, 'credit', ?)");
            $stmt->execute([$user['id'], $desc]);

            // Refresh user info
            $user = get_logged_in_user($conn);
            $user_points = intval($user['eco_points'] ?? 0);

            $success_msg = "🎁 Success! Successfully claimed {$prod_name} for {$pts_needed} Eco XP! Visit your local Ward Municipal Office with your ID to collect your item!";
        } else {
            $error_msg = "Insufficient Eco Points! You need {$pts_needed} XP to claim {$prod_name}. Scan carbon barcodes & complete tasks to earn XP!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Eco Points & Rewards Redemption - Nagpur Mahanagar Palika</title>

  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link href="style.css" rel="stylesheet" />

  <!-- Confetti library for celebrations -->
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>

  <style>
    body {
      background-color: var(--bg-app);
      color: var(--text-main);
      font-family: var(--font-body);
    }

    .redeem-hero {
      background: linear-gradient(135deg, #059669 0%, #10b981 50%, #047857 100%);
      color: white;
      border-radius: 24px;
      padding: 36px 24px;
      margin-bottom: 28px;
      box-shadow: 0 12px 30px rgba(5, 150, 105, 0.25);
    }

    .reward-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      box-shadow: var(--shadow-soft);
      transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .reward-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-hover);
    }

    .badge-xp {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      color: white;
    }

    .badge-cash {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
    }
  </style>

  <script>
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

    <?php if (!empty($success_msg)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-gift-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($success_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <script>
        document.addEventListener("DOMContentLoaded", function() {
          if (typeof confetti === "function") {
            confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
          }
        });
      </script>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
      <div class="alert alert-warning alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($error_msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Redeem Hero Banner -->
    <div class="redeem-hero text-center position-relative overflow-hidden">
      <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-3">
        <i class="bi bi-gift-fill text-warning"></i>
        <span class="small fw-bold text-white">Eco XP & Civic Marketplace</span>
      </div>
      <h1 class="fw-bold mb-2">Redeem Eco Points for Real Cash 🎁</h1>
      <p class="text-white-50 max-w-700 mx-auto mb-4 fs-6">
        Convert your earned Eco XP from carbon scans, traffic reports, and civic task claims directly into civic wallet cash & municipal discount vouchers!
      </p>

      <!-- Live Balance HUD Cards -->
      <div class="row g-3 justify-content-center">
        <div class="col-6 col-md-4">
          <div class="bg-white p-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-success"><?php echo number_format($user_points); ?> XP</h3>
            <span class="small text-secondary fw-semibold">Available Eco Points</span>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="bg-white p-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-primary">₹<?php echo number_format($user_wallet, 2); ?></h3>
            <span class="small text-secondary fw-semibold">Civic Wallet Cash</span>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="bg-white p-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-warning">Level <?php echo $user_level; ?></h3>
            <span class="small text-secondary fw-semibold">Citizen Eco Rank</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 1: Instant Point-to-Cash Redemption Tiers -->
    <div class="mb-5">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-arrow-repeat text-success me-2"></i>Instant XP to Wallet Cash Conversion</h4>
        <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-3 py-2 rounded-pill font-monospace">100% Instant Credit</span>
      </div>

      <div class="row g-4">
        <!-- Tier 1: 50 XP -> ₹10.00 -->
        <div class="col-md-4">
          <div class="reward-card p-4 h-100 d-flex flex-column justify-content-between text-center">
            <div>
              <div class="d-inline-flex bg-success bg-opacity-10 text-success rounded-circle p-3 mb-3">
                <i class="bi bi-coin display-5"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Starter Cash Reward</h5>
              <p class="text-muted small mb-3">Convert 50 Eco Points into instant wallet balance</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">50 XP</span>
                <i class="bi bi-arrow-right text-muted fs-5"></i>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹10.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="redeem_tier" value="1">
              <button type="submit" class="btn btn-success w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 50) ? 'disabled' : ''; ?>>
                <i class="bi bi-check-circle-fill me-1"></i>Redeem ₹10.00 Cash
              </button>
              <?php if ($user_points < 50): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (50 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Tier 2: 100 XP -> ₹25.00 (Bonus ₹5) -->
        <div class="col-md-4">
          <div class="reward-card p-4 h-100 d-flex flex-column justify-content-between text-center border-success position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-warning text-dark font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-lightning-fill me-1"></i>+₹5 Bonus
            </span>
            <div>
              <div class="d-inline-flex bg-primary bg-opacity-10 text-primary rounded-circle p-3 mb-3">
                <i class="bi bi-cash-stack display-5"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Silver Cash Booster</h5>
              <p class="text-muted small mb-3">Convert 100 Eco Points with extra ₹5 bonus cash</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">100 XP</span>
                <i class="bi bi-arrow-right text-muted fs-5"></i>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹25.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="redeem_tier" value="2">
              <button type="submit" class="btn btn-primary w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 100) ? 'disabled' : ''; ?>>
                <i class="bi bi-check-circle-fill me-1"></i>Redeem ₹25.00 Cash
              </button>
              <?php if ($user_points < 100): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (100 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Tier 3: 200 XP -> ₹55.00 (Bonus ₹15) -->
        <div class="col-md-4">
          <div class="reward-card p-4 h-100 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-danger text-white font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-fire me-1"></i>+₹15 Mega Bonus
            </span>
            <div>
              <div class="d-inline-flex bg-warning bg-opacity-15 text-warning rounded-circle p-3 mb-3">
                <i class="bi bi-trophy-fill display-5"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Gold Mega Pack</h5>
              <p class="text-muted small mb-3">Convert 200 Eco Points with huge ₹15 bonus cash</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">200 XP</span>
                <i class="bi bi-arrow-right text-muted fs-5"></i>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹55.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="redeem_tier" value="3">
              <button type="submit" class="btn btn-warning text-dark w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 200) ? 'disabled' : ''; ?>>
                <i class="bi bi-check-circle-fill me-1"></i>Redeem ₹55.00 Cash
              </button>
              <?php if ($user_points < 200): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (200 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Section: Physical Merchandise & Electronics Rewards Catalog -->
    <div class="mb-5">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h4 class="fw-bold mb-0 text-dark"><i class="bi bi-shop text-primary me-2"></i>Physical Rewards & Merchandise Store 🛍️</h4>
          <p class="text-muted small mb-0">Redeem your Eco XP for real gadgets, smartphones, organic T-shirts & eco accessories!</p>
        </div>
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-3 py-2 rounded-pill font-monospace">Ward Office Pickup</span>
      </div>

      <div class="row g-4">
        <!-- Product 1: Nagpur Citizen 5G Eco Smartphone -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-danger text-white font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-star-fill me-1 text-warning"></i>5G Smartphone
            </span>
            <div>
              <div class="bg-primary bg-opacity-10 text-primary rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-phone-fill display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Nagpur Citizen 5G Smartphone</h5>
              <p class="text-muted small mb-3">108MP Quad Camera, 5000mAh Battery & 6.7" AMOLED Display</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">1,500 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹300.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="Nagpur Citizen 5G Smartphone">
              <input type="hidden" name="points_cost" value="1500">
              <button type="submit" name="redeem_product" class="btn btn-primary w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 1500) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim 5G Phone (1500 XP)
              </button>
              <?php if ($user_points < 1500): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (1500 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Product 2: Organic Cotton NMC T-Shirt -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-success text-white font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-patch-check-fill me-1"></i>100% Organic Cotton
            </span>
            <div>
              <div class="bg-success bg-opacity-10 text-success rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-universal-access-circle display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">NMC Smart Citizen Eco T-Shirt</h5>
              <p class="text-muted small mb-3">100% Bio-Washed Organic Cotton T-Shirt (Sizes S, M, L, XL, XXL)</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">150 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹30.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="NMC Smart Citizen Organic T-Shirt">
              <input type="hidden" name="points_cost" value="150">
              <button type="submit" name="redeem_product" class="btn btn-success w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 150) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim Eco T-Shirt (150 XP)
              </button>
              <?php if ($user_points < 150): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (150 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Product 3: Citizen Eco Smartwatch -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-warning text-dark font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-activity me-1"></i>Health & Fitness
            </span>
            <div>
              <div class="bg-warning bg-opacity-15 text-warning rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-watch display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Citizen Eco Smartwatch</h5>
              <p class="text-muted small mb-3">AMOLED Touchscreen, Heart Rate, SpO2 & 7-Day Battery Life</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">600 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹120.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="Citizen Eco Smartwatch">
              <input type="hidden" name="points_cost" value="600">
              <button type="submit" name="redeem_product" class="btn btn-warning text-dark w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 600) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim Smartwatch (600 XP)
              </button>
              <?php if ($user_points < 600): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (600 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Product 4: Solar 20,000mAh Power Bank -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-info text-dark font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-sun-fill me-1"></i>Solar Powered
            </span>
            <div>
              <div class="bg-info bg-opacity-10 text-info rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-battery-charging display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Solar 20,000mAh Power Bank</h5>
              <p class="text-muted small mb-3">Dual Solar Panel Emergency Charger with USB-C Fast Charge</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">400 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹80.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="Solar 20,000mAh Power Bank">
              <input type="hidden" name="points_cost" value="400">
              <button type="submit" name="redeem_product" class="btn btn-info text-dark w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 400) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim Power Bank (400 XP)
              </button>
              <?php if ($user_points < 400): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (400 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Product 5: Insulated Steel Eco Water Bottle -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-secondary text-white font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-droplet-fill me-1"></i>BPA Free Steel
            </span>
            <div>
              <div class="bg-secondary bg-opacity-10 text-secondary rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-cup-hot-fill display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Insulated Steel Eco Flask</h5>
              <p class="text-muted small mb-3">750ml Vacuum Thermos Flask (Keeps 24h Cold / 12h Hot)</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">100 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹20.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="Insulated Steel Eco Flask">
              <input type="hidden" name="points_cost" value="100">
              <button type="submit" name="redeem_product" class="btn btn-secondary w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 100) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim Eco Flask (100 XP)
              </button>
              <?php if ($user_points < 100): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (100 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Product 6: Wireless Noise-Cancelling Earbuds -->
        <div class="col-md-6 col-lg-4">
          <div class="reward-card h-100 p-4 d-flex flex-column justify-content-between text-center position-relative overflow-hidden">
            <span class="position-absolute top-0 end-0 m-2 badge bg-primary text-white font-monospace px-2.5 py-1.5 rounded-pill shadow-sm">
              <i class="bi bi-headphones me-1"></i>Bluetooth 5.3
            </span>
            <div>
              <div class="bg-primary bg-opacity-10 text-primary rounded-4 p-4 mb-3 d-inline-flex align-items-center justify-content-center" style="width: 100%; height: 160px;">
                <i class="bi bi-headphones display-1"></i>
              </div>
              <h5 class="fw-bold text-dark mb-1">Wireless Eco Earbuds</h5>
              <p class="text-muted small mb-3">Active Noise-Cancelling, HD Bass & 30-Hour Playtime Charging Case</p>
              
              <div class="d-flex justify-content-center align-items-center gap-2 mb-4">
                <span class="badge badge-xp px-3 py-2 fs-6 rounded-pill">500 XP</span>
                <span class="text-muted small">or</span>
                <span class="badge badge-cash px-3 py-2 fs-6 rounded-pill">₹100.00 Cash</span>
              </div>
            </div>

            <form action="" method="POST">
              <input type="hidden" name="product_name" value="Wireless Eco Earbuds">
              <input type="hidden" name="points_cost" value="500">
              <button type="submit" name="redeem_product" class="btn btn-dark w-100 rounded-pill py-2.5 fw-bold" <?php echo ($user_points < 500) ? 'disabled' : ''; ?>>
                <i class="bi bi-bag-check-fill me-1"></i>Claim Earbuds (500 XP)
              </button>
              <?php if ($user_points < 500): ?>
                <span class="small text-muted d-block mt-2" style="font-size: 0.78rem;">Needs <?php echo (500 - $user_points); ?> more XP to unlock</span>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Refer & Earn Cash Program -->
    <div class="reward-card p-4 mb-5 border-0 shadow-sm rounded-4 text-white" style="background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%);">
      <div class="row align-items-center g-3">
        <div class="col-md-8">
          <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-20 px-3 py-1 rounded-pill mb-2 small fw-bold">
            <i class="bi bi-gift-fill text-warning"></i>
            <span>Citizen Referral Program</span>
          </div>
          <h4 class="fw-bold mb-1">Earn ₹50.00 Cash Per Referred Friend 🤝</h4>
          <p class="mb-0 text-white-50 small">Share your unique referral code with friends & family in Nagpur. When they sign up, you automatically receive ₹50.00 cash directly into your civic wallet!</p>
        </div>
        <div class="col-md-4 text-md-end">
          <?php if ($is_logged_in): ?>
            <div class="input-group input-group-lg">
              <input type="text" class="form-control font-monospace bg-white border-0 text-dark fw-bold text-center" readonly value="NMC-REF-<?php echo $user['id']; ?>" id="referralCodePageInput">
              <button class="btn btn-dark fw-bold px-3" type="button" onclick="navigator.clipboard.writeText(document.getElementById('referralCodePageInput').value); alert('Referral Code Copied to Clipboard!');">Copy</button>
            </div>
          <?php else: ?>
            <a href="login.php?redirect=redeem.php" class="btn btn-light text-primary font-monospace fw-bold px-4 py-2 rounded-pill">Sign In to Get Referral Code</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Section 3: Redemption & Reward History -->
    <?php if ($is_logged_in): ?>
      <div class="reward-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-success me-2"></i>Recent Redemption History</h5>
          <span class="badge bg-secondary font-monospace"><?php echo count($transactions); ?> Transactions</span>
        </div>

        <?php if (empty($transactions)): ?>
          <div class="text-center py-4 text-muted">
            <i class="bi bi-inbox fs-1 mb-2 d-block"></i>
            <p class="mb-0">No redemptions logged yet. Earn Eco XP to start redeeming cash rewards!</p>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>Date & Time</th>
                  <th>Description</th>
                  <th>Type</th>
                  <th>Amount Credited</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($transactions as $tx): ?>
                  <tr>
                    <td data-label="Date & Time" class="small text-muted font-monospace"><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($tx['created_at'])); ?></td>
                    <td data-label="Description" class="fw-semibold text-dark small"><?php echo htmlspecialchars($tx['description']); ?></td>
                    <td data-label="Type"><span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill px-3 py-1"><?php echo htmlspecialchars($tx['transaction_type']); ?></span></td>
                    <td data-label="Amount Credited" class="fw-bold text-success">+₹<?php echo number_format($tx['amount'], 2); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

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
