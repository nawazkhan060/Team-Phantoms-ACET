<?php
// polls.php - Dedicated Public Community Verification & Complaints Voting Polls Portal
require_once 'config.php';

$is_logged_in = is_logged_in();
$user = null;
$user_id = 0;

if ($is_logged_in) {
    $user = get_logged_in_user($conn);
    if ($user) {
        $user_id = intval($user['id']);
    }
}

$vote_message = '';
$vote_error = '';

// Handle Vote POST Request if logged in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vote_pothole'])) {
    if (!$is_logged_in) {
        $vote_error = "Please sign in to vote and verify civic complaints!";
    } else {
        $pothole_id = intval($_POST['pothole_id'] ?? 0);
        $vote_type = ($_POST['vote_type'] === 'downvote') ? 'downvote' : 'upvote';
        
        if ($pothole_id > 0) {
            $stmt = $conn->prepare("SELECT vote_type FROM pothole_votes WHERE pothole_id = ? AND user_id = ?");
            $stmt->execute([$pothole_id, $user_id]);
            $existing = $stmt->fetchColumn();
            
            if ($existing === $vote_type) {
                // Toggle off
                $stmt = $conn->prepare("DELETE FROM pothole_votes WHERE pothole_id = ? AND user_id = ?");
                $stmt->execute([$pothole_id, $user_id]);
                $vote_message = "Your vote has been removed.";
            } else {
                $stmt = $conn->prepare("INSERT INTO pothole_votes (pothole_id, user_id, vote_type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote_type = VALUES(vote_type)");
                $stmt->execute([$pothole_id, $user_id, $vote_type]);
                $vote_message = ($vote_type === 'upvote') ? "👍 Thumbs Up recorded! Thank you for verifying civic complaints." : "👎 Thumbs Down recorded.";
            }
        }
    }
}

// Category Filter
$category = $_GET['category'] ?? 'all';

// Fetch open pothole reports with votes
$potholes_sql = "
    SELECT p.*, u.email as reporter_email,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'upvote') as upvotes,
      (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'downvote') as downvotes,
      (SELECT vote_type FROM pothole_votes v WHERE v.pothole_id = p.id AND v.user_id = {$user_id}) as my_vote
    FROM pothole_reports p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.status != 'Resolved'
    ORDER BY (SELECT COUNT(*) FROM pothole_votes v WHERE v.pothole_id = p.id AND v.vote_type = 'upvote') DESC, p.created_at DESC";
$all_potholes = $conn->query($potholes_sql)->fetchAll();

// Fetch overall complaint statistics
$total_potholes = count($all_potholes);
$total_traffic = $conn->query("SELECT COUNT(*) FROM traffic_reports WHERE status != 'Approved'")->fetchColumn();
$total_utility = $conn->query("SELECT COUNT(*) FROM utility_complaints WHERE status != 'Resolved'")->fetchColumn();
$total_waste = $conn->query("SELECT COUNT(*) FROM waste_complaints WHERE status != 'Resolved'")->fetchColumn();
$total_complaints_formed = $total_potholes + $total_traffic + $total_utility + $total_waste;

$total_votes_cast = $conn->query("SELECT COUNT(*) FROM pothole_votes")->fetchColumn();
$verified_count = 0;
foreach ($all_potholes as $ph) {
    if ((intval($ph['upvotes']) - intval($ph['downvotes'])) >= 3) {
        $verified_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Community Verification Polls - Nagpur Mahanagar Palika</title>
  
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
  <link href="style.css" rel="stylesheet" />

  <style>
    body {
      background-color: var(--bg-app);
      color: var(--text-main);
      font-family: var(--font-body);
    }

    .poll-hero {
      background: linear-gradient(135deg, #059669 0%, #10b981 50%, #047857 100%);
      color: white;
      border-radius: 24px;
      padding: 32px 24px;
      margin-bottom: 24px;
      box-shadow: 0 12px 30px rgba(5, 150, 105, 0.25);
    }

    .poll-card {
      background: var(--card-bg);
      border: 1px solid var(--card-border);
      border-radius: 20px;
      box-shadow: var(--shadow-soft);
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      overflow: hidden;
    }

    .poll-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-hover);
    }

    .poll-img {
      height: 200px;
      object-fit: cover;
      width: 100%;
    }

    .vote-btn {
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .vote-btn:active {
      transform: scale(0.94);
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
        <a href="redeem.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 font-monospace fw-bold">
          <i class="bi bi-gift-fill me-1"></i>Rewards 🎁
        </a>
      </div>

      <!-- Right Controls Group -->
      <div class="d-flex align-items-center gap-2">
        
        <!-- Wallet Balance Pill -->
        <a href="redeem.php" class="btn btn-success btn-sm rounded-pill font-monospace fw-bold px-3 py-1.5 shadow-sm d-flex align-items-center gap-1.5 text-nowrap">
          <i class="bi bi-wallet2"></i>
          <span>₹<?php echo number_format($user['wallet_balance'] ?? 0, 2); ?></span>
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

    <?php if (!empty($vote_message)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($vote_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if (!empty($vote_error)): ?>
      <div class="alert alert-warning alert-dismissible fade show rounded-4 mb-4 shadow-sm border-0" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 align-middle"></i><?php echo htmlspecialchars($vote_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Poll Hero Banner -->
    <div class="poll-hero text-center position-relative overflow-hidden">
      <div class="d-inline-flex align-items-center gap-2 bg-dark bg-opacity-35 border border-white border-opacity-25 px-3 py-1 rounded-pill mb-3">
        <i class="bi bi-check2-square text-warning"></i>
        <span class="small fw-bold text-white">Public Civic Verification Portal</span>
      </div>
      <h1 class="fw-bold mb-2">Community Pothole & Complaint Polls 🗳️</h1>
      <p class="text-white-50 max-w-700 mx-auto mb-4 fs-6">
        Anyone can view active complaint polls. Citizens vote to verify reported road damage and civic issues so Municipal Authorities prioritize genuine repairs!
      </p>

      <!-- Stats Counters (3-column mobile grid) -->
      <div class="row g-2 g-md-3 justify-content-center">
        <div class="col-4 col-md-3">
          <div class="bg-white p-2.5 p-md-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-dark fs-5 fs-md-3"><?php echo $total_complaints_formed; ?></h3>
            <span class="small text-secondary fw-semibold" style="font-size: 0.72rem;">Total Polls</span>
          </div>
        </div>
        <div class="col-4 col-md-3">
          <div class="bg-white p-2.5 p-md-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-success fs-5 fs-md-3"><?php echo $verified_count; ?></h3>
            <span class="small text-secondary fw-semibold" style="font-size: 0.72rem;">Verified</span>
          </div>
        </div>
        <div class="col-4 col-md-3">
          <div class="bg-white p-2.5 p-md-3 rounded-4 border border-light shadow-sm text-center">
            <h3 class="fw-extrabold mb-0 text-primary fs-5 fs-md-3"><?php echo $total_votes_cast; ?></h3>
            <span class="small text-secondary fw-semibold" style="font-size: 0.72rem;">Total Votes</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Category Filter Chips (Horizontal Inertia Scrollable on Mobile) -->
    <div class="d-flex overflow-x-auto gap-2 mb-4 pb-2 text-nowrap" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
      <a href="polls.php" class="btn btn-sm rounded-pill px-3.5 py-2 fw-semibold <?php echo ($category === 'all') ? 'btn-success' : 'btn-outline-secondary'; ?>">
        All Polls (<?php echo $total_complaints_formed; ?>)
      </a>
      <a href="polls.php?category=potholes" class="btn btn-sm rounded-pill px-3.5 py-2 fw-semibold <?php echo ($category === 'potholes') ? 'btn-success' : 'btn-outline-secondary'; ?>">
        🛣️ Potholes (<?php echo $total_potholes; ?>)
      </a>
      <a href="polls.php?category=traffic" class="btn btn-sm rounded-pill px-3.5 py-2 fw-semibold <?php echo ($category === 'traffic') ? 'btn-success' : 'btn-outline-secondary'; ?>">
        📸 Traffic (<?php echo $total_traffic; ?>)
      </a>
      <a href="polls.php?category=utility" class="btn btn-sm rounded-pill px-3.5 py-2 fw-semibold <?php echo ($category === 'utility') ? 'btn-success' : 'btn-outline-secondary'; ?>">
        ⚡ Utilities (<?php echo $total_utility; ?>)
      </a>
      <a href="polls.php?category=waste" class="btn btn-sm rounded-pill px-3.5 py-2 fw-semibold <?php echo ($category === 'waste') ? 'btn-success' : 'btn-outline-secondary'; ?>">
        🗑️ Waste (<?php echo $total_waste; ?>)
      </a>
    </div>

    <!-- Active Poll Cards Feed -->
    <div class="row g-3 g-md-4">
      <?php if (empty($all_potholes)): ?>
        <div class="col-12 text-center py-5">
          <i class="bi bi-check-circle text-success display-1 mb-3"></i>
          <h4 class="fw-bold">No Active Pothole Polls</h4>
          <p class="text-muted">No open road damage complaints currently pending verification.</p>
        </div>
      <?php else: ?>
        <?php foreach ($all_potholes as $ph): 
          $up = intval($ph['upvotes']);
          $down = intval($ph['downvotes']);
          $net = $up - $down;
          $my_v = $ph['my_vote'];
        ?>
          <div class="col-md-6 col-lg-4">
            <div class="poll-card h-100 border-0 shadow-sm rounded-4 overflow-hidden d-flex flex-column justify-content-between bg-white">
              <div>
                <!-- Card Header Image & Overlay Badges -->
                <div class="position-relative">
                  <img src="<?php echo htmlspecialchars($ph['photo_path']); ?>" class="poll-img w-100" style="height: 190px; object-fit: cover;" alt="Pothole Damage Photo">
                  
                  <!-- Top-Right Google Maps GPS Link Badge -->
                  <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 backdrop-blur text-white text-decoration-none px-2.5 py-1.5 rounded-pill shadow-sm" title="Open GPS Location in Google Maps">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i><span class="d-none d-sm-inline"><?php echo number_format($ph['latitude'], 3); ?>, <?php echo number_format($ph['longitude'], 3); ?></span><span class="d-inline d-sm-none">GPS</span>
                    <i class="bi bi-box-arrow-up-right ms-1 small text-warning"></i>
                  </a>

                  <!-- Top-Left Verification Status Badge -->
                  <?php if ($net >= 3): ?>
                    <span class="position-absolute top-0 start-0 m-2 badge bg-success text-white px-2.5 py-1.5 rounded-pill shadow-sm">
                      <i class="bi bi-patch-check-fill me-1"></i>Verified (+<?php echo $net; ?>)
                    </span>
                  <?php elseif ($net < 0): ?>
                    <span class="position-absolute top-0 start-0 m-2 badge bg-warning text-dark px-2.5 py-1.5 rounded-pill shadow-sm">
                      <i class="bi bi-exclamation-triangle-fill me-1"></i>Inaccurate
                    </span>
                  <?php else: ?>
                    <span class="position-absolute top-0 start-0 m-2 badge bg-secondary text-white px-2.5 py-1.5 rounded-pill shadow-sm">
                      <i class="bi bi-hourglass-split me-1"></i>Pending (+<?php echo $net; ?>)
                    </span>
                  <?php endif; ?>
                </div>

                <!-- Card Body -->
                <div class="p-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-secondary fw-semibold"><i class="bi bi-person-circle me-1 text-success"></i><?php echo htmlspecialchars($ph['reporter_email'] ? mask_email($ph['reporter_email']) : 'Citizen'); ?></span>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle rounded-pill px-2.5 py-1"><?php echo htmlspecialchars($ph['status']); ?></span>
                  </div>

                  <p class="small text-dark fw-bold mb-2 fs-6" style="line-height: 1.4; color: #1e293b;"><?php echo htmlspecialchars($ph['description']); ?></p>

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
              </div>

              <!-- Interactive Mobile Touch Voting Dock Footer -->
              <div class="p-3 border-top bg-light">
                <div class="d-flex flex-column gap-2">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-secondary">Is this genuine road damage?</span>
                    <span class="badge bg-secondary bg-opacity-10 text-secondary border font-monospace"><?php echo ($up + $down); ?> Votes</span>
                  </div>

                  <?php if ($is_logged_in): ?>
                    <div class="d-flex w-100 gap-2">
                      <form action="" method="POST" class="flex-grow-1">
                        <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                        <input type="hidden" name="vote_type" value="upvote">
                        <button type="submit" name="vote_pothole" class="btn btn-sm w-100 <?php echo ($my_v === 'upvote') ? 'btn-success' : 'btn-outline-success'; ?> vote-btn rounded-pill py-2 d-flex align-items-center justify-content-center gap-1.5 fw-bold">
                          <i class="bi bi-hand-thumbs-up-fill"></i>
                          <span>Thumbs Up</span>
                          <span class="badge <?php echo ($my_v === 'upvote') ? 'bg-white text-success' : 'bg-success text-white'; ?> rounded-pill ms-1 px-2"><?php echo $up; ?></span>
                        </button>
                      </form>

                      <form action="" method="POST" class="flex-grow-1">
                        <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                        <input type="hidden" name="vote_type" value="downvote">
                        <button type="submit" name="vote_pothole" class="btn btn-sm w-100 <?php echo ($my_v === 'downvote') ? 'btn-danger' : 'btn-outline-danger'; ?> vote-btn rounded-pill py-2 d-flex align-items-center justify-content-center gap-1.5 fw-bold">
                          <i class="bi bi-hand-thumbs-down-fill"></i>
                          <span>Thumbs Down</span>
                          <span class="badge <?php echo ($my_v === 'downvote') ? 'bg-white text-danger' : 'bg-danger text-white'; ?> rounded-pill ms-1 px-2"><?php echo $down; ?></span>
                        </button>
                      </form>
                    </div>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-primary w-100 rounded-pill py-2 fw-semibold d-flex align-items-center justify-content-center gap-1" data-bs-toggle="modal" data-bs-target="#loginPromptModal">
                      <i class="bi bi-hand-thumbs-up-fill me-1"></i>Sign In to Cast Vote
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Guest Sign In Prompt Modal -->
  <div class="modal fade" id="loginPromptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0 shadow-lg text-center">
        <div class="modal-body p-4 p-md-5">
          <div class="bg-success bg-opacity-10 text-success rounded-circle p-3 d-inline-flex mb-3">
            <i class="bi bi-shield-lock-fill display-4"></i>
          </div>
          <h4 class="fw-bold mb-2 text-dark">Sign In Required to Vote</h4>
          <p class="text-muted small px-3 mb-4">You can view all complaints and vote results freely! To cast your vote and help verify road damage, please log in with your email.</p>
          <div class="d-grid gap-2">
            <a href="login.php?redirect=polls.php" class="btn btn-primary rounded-pill py-2 fw-semibold">
              <i class="bi bi-box-arrow-in-right me-1"></i>Sign In Now
            </a>
            <button type="button" class="btn btn-link text-secondary text-decoration-none small" data-bs-dismiss="modal">Continue Browsing</button>
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
    <a href="polls.php" class="mobile-nav-item active">
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
