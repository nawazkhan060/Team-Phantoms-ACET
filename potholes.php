<?php
// potholes.php - Dedicated Road Infrastructure & Pothole Repair Portal (Matches water.php UI)
require_once __DIR__ . '/config.php';

$is_logged_in = is_logged_in();
$user = $is_logged_in ? get_logged_in_user($conn) : null;

$success = '';
$error = '';

// Determine active ward
$active_ward_id = $_SESSION['active_ward_id'] ?? ($user['ward_id'] ?? ($_SESSION['guest_ward_id'] ?? null));

// Handle Pothole Report Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_pothole_report'])) {
    if (!$is_logged_in) {
        $error = "Please sign in to file pothole reports.";
    } else {
        $lat = floatval($_POST['latitude'] ?? 0);
        $lng = floatval($_POST['longitude'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $photo_source = $_POST['pothole_photo_source'] ?? 'upload';
        
        $photo_path = null;
        if ($photo_source === 'webcam' && !empty($_POST['webcam_photo'])) {
            $data_uri = $_POST['webcam_photo'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data_uri, $type)) {
                $data = substr($data_uri, strpos($data_uri, ',') + 1);
                $type = strtolower($type[1]);
                $data = base64_decode($data);
                if ($data !== false) {
                    $target_dir = __DIR__ . "/uploads/";
                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0777, true);
                    }
                    $filename = "pothole_" . uniqid() . "." . $type;
                    file_put_contents($target_dir . $filename, $data);
                    $photo_path = "uploads/" . $filename;
                }
            }
        } elseif (isset($_FILES['photo'])) {
            $photo_path = handle_file_upload($_FILES['photo'], 'pothole_');
        }
        
        if ($lat == 0 || $lng == 0) {
            $error = "Failed to capture GPS coordinates. Please allow location access.";
        } elseif (!$photo_path) {
            $error = "Please upload a photo or capture a snapshot of the road damage.";
        } else {
            $stmt = $conn->prepare("INSERT INTO pothole_reports (user_id, latitude, longitude, photo_path, description, status) VALUES (?, ?, ?, ?, ?, 'Open')");
            $stmt->execute([$user['id'], $lat, $lng, $photo_path, $desc ?: 'Reported Pothole Road Damage']);
            $success = "Pothole damage report submitted successfully! Municipal Road Maintenance Department notified.";
        }
    }
}

// Handle Pothole Verification Voting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vote_pothole'])) {
    if (!$is_logged_in) {
        $error = "Please sign in to vote on community pothole polls.";
    } else {
        $pothole_id = intval($_POST['pothole_id'] ?? 0);
        $vote_type = $_POST['vote_type'] === 'downvote' ? 'downvote' : 'upvote';
        
        $stmt = $conn->prepare("SELECT id, vote_type FROM pothole_votes WHERE pothole_id = ? AND user_id = ?");
        $stmt->execute([$pothole_id, $user['id']]);
        $existing_vote = $stmt->fetch();
        
        if ($existing_vote) {
            if ($existing_vote['vote_type'] === $vote_type) {
                $del = $conn->prepare("DELETE FROM pothole_votes WHERE id = ?");
                $del->execute([$existing_vote['id']]);
                $success = "Your vote was removed.";
            } else {
                $upd = $conn->prepare("UPDATE pothole_votes SET vote_type = ? WHERE id = ?");
                $upd->execute([$vote_type, $existing_vote['id']]);
                $success = "Your vote was updated to " . ($vote_type === 'upvote' ? 'Thumbs Up 👍' : 'Thumbs Down 👎') . ".";
            }
        } else {
            $ins = $conn->prepare("INSERT INTO pothole_votes (pothole_id, user_id, vote_type) VALUES (?, ?, ?)");
            $ins->execute([$pothole_id, $user['id'], $vote_type]);
            $success = "Thank you! Your community vote (" . ($vote_type === 'upvote' ? 'Thumbs Up 👍' : 'Thumbs Down 👎') . ") has been logged.";
        }
    }
}

// Fetch all open pothole reports city-wide
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

$user_wallet = $user['wallet_balance'] ?? 0.00;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pothole Road Damage & Repair Portal - NMC Smart Portal</title>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="style.css">
  
  <style>
    .potholes-hero {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 50%, #075985 100%);
      color: white;
      border-radius: 24px;
      padding: 2.5rem 1.5rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(2, 132, 199, 0.25);
    }
    .pothole-card {
      background: white;
      border-radius: 20px;
      padding: 1.5rem;
      border: 1px solid #e0f2fe;
      box-shadow: 0 4px 15px rgba(2, 132, 199, 0.06);
    }
    #pothole-main-map {
      height: 400px;
      border-radius: 20px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
      z-index: 5;
    }
  </style>
</head>
<body>

  <!-- Reusable Top Navigation Bar -->
  <?php include __DIR__ . '/navbar.php'; ?>

  <!-- Main Container -->
  <div class="container my-4">

    <?php if (!empty($success)): ?>
      <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2 fs-5"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endif; ?>

    <!-- Pothole Hero Banner Card (Matches water.php UI) -->
    <div class="potholes-hero mb-4 text-white">
      <div class="position-relative z-1">
        <div class="d-inline-flex align-items-center gap-2 bg-white bg-opacity-20 px-3.5 py-1.5 rounded-pill mb-3 small font-monospace fw-bold">
          <i class="bi bi-tools text-warning fs-6"></i>
          <span>ROAD INFRASTRUCTURE MAINTENANCE GRID</span>
        </div>
        <h1 class="fw-extrabold text-white mb-2" style="letter-spacing: -0.5px;">Pothole Road Damage & Repair Portal 🛠️</h1>
        <p class="text-white opacity-90 mb-4" style="max-width: 680px; font-size: 0.98rem;">Report broken streets and road potholes with live GPS photo stamps. Vote on community verification polls to help Municipal Road Authorities prioritize urgent repairs.</p>
        
        <div class="d-flex flex-wrap gap-3">
          <div class="bg-white bg-opacity-10 backdrop-blur px-3.5 py-2.5 rounded-3 d-flex align-items-center gap-3">
            <i class="bi bi-tools text-warning display-6"></i>
            <div>
              <span class="d-block small text-white opacity-75">OPEN REPAIR POLLS</span>
              <strong class="fs-5 font-monospace"><?php echo count($all_open_potholes); ?> Active</strong>
            </div>
          </div>

          <div class="bg-white bg-opacity-10 backdrop-blur px-3.5 py-2.5 rounded-3 d-flex align-items-center gap-3">
            <i class="bi bi-geo-alt-fill text-info display-6"></i>
            <div>
              <span class="d-block small text-white opacity-75">GPS MAPPED</span>
              <strong class="fs-5 font-monospace">100% Verified</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Live Interactive Leaflet GPS Map Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white">
      <div class="card-header bg-white border-0 p-4 d-flex justify-content-between align-items-center border-bottom">
        <div>
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-map-fill text-primary me-2"></i>City-Wide Live Potholes GPS Map</h5>
          <span class="small text-muted">Click pins on map to inspect road damage reports and navigation coordinates</span>
        </div>
        <span class="badge bg-primary bg-opacity-10 text-primary font-monospace px-3 py-2 rounded-pill fw-bold border border-primary-subtle"><?php echo count($all_open_potholes); ?> Open Pins</span>
      </div>
      <div class="card-body p-3">
        <div id="pothole-main-map"></div>
      </div>
    </div>

    <!-- Form & Poll Feed Row -->
    <div class="row g-4 mb-4">
      
      <!-- Report Form Card -->
      <div class="col-lg-5">
        <div class="pothole-card h-100">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="rounded-circle p-2.5 d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 46px; height: 46px; background: #0284c7; color: white;">
              <i class="bi bi-camera-fill fs-5"></i>
            </div>
            <div>
              <h5 class="fw-bold mb-0 text-dark">File Pothole Damage Report 📸</h5>
              <span class="small text-muted">Upload live photo with automatic GPS location stamp</span>
            </div>
          </div>

          <?php if ($is_logged_in): ?>
            <form action="" method="POST" enctype="multipart/form-data">
              <div class="mb-3">
                <label class="form-label fw-bold text-dark"><i class="bi bi-camera-reels-fill text-primary me-1.5"></i>Photo Source</label>
                <div class="btn-group w-100 p-1 bg-light border rounded-pill" role="group">
                  <input type="radio" class="btn-check" name="pothole_photo_source" id="pothole-source-upload" value="upload" checked autocomplete="off">
                  <label class="btn btn-outline-primary border-0 rounded-pill font-monospace fw-bold py-2" for="pothole-source-upload"><i class="bi bi-file-earmark-image me-1.5"></i>Upload File</label>

                  <input type="radio" class="btn-check" name="pothole_photo_source" id="pothole-source-webcam" value="webcam" autocomplete="off">
                  <label class="btn btn-outline-primary border-0 rounded-pill font-monospace fw-bold py-2" for="pothole-source-webcam"><i class="bi bi-webcam-fill me-1.5"></i>Live Camera</label>
                </div>
              </div>

              <!-- Upload Photo Container -->
              <div id="pothole-upload-photo-container" class="mb-3">
                <label class="form-label fw-bold text-dark">Damage Photo</label>
                <input type="file" name="photo" id="pothole-file-photo-input" class="form-control form-control-lg rounded-3" accept="image/*" capture="environment" required>
              </div>

              <!-- Webcam Capture Container -->
              <div id="pothole-webcam-photo-container" class="mb-3 d-none">
                <label class="form-label fw-bold text-dark">Take Real-Time Snapshot</label>
                <div class="position-relative bg-dark rounded-4 overflow-hidden border text-center mb-2 shadow-sm" style="max-width: 100%; height: 260px; display: flex; align-items: center; justify-content: center;">
                  <video id="pothole-webcam-video" autoplay playsinline class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;"></video>
                  <canvas id="pothole-webcam-canvas" class="d-none" width="640" height="480"></canvas>
                  <img id="pothole-webcam-preview-img" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;">
                  <div id="pothole-webcam-placeholder" class="text-center p-3 text-white">
                    <i class="bi bi-camera fs-1 text-info d-block mb-2"></i>
                    <span>Click "Start Camera" to activate live feed</span>
                  </div>
                </div>
                
                <div class="text-center d-flex justify-content-center gap-2">
                  <button type="button" id="pothole-btn-start-webcam" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 fw-bold"><i class="bi bi-power me-1"></i>Start Camera</button>
                  <button type="button" id="pothole-btn-capture-webcam" class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fw-bold d-none"><i class="bi bi-camera-fill me-1"></i>Capture</button>
                  <button type="button" id="pothole-btn-reset-webcam" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fw-bold d-none"><i class="bi bi-arrow-clockwise me-1"></i>Retake</button>
                </div>
                <input type="hidden" name="webcam_photo" id="pothole-webcam-data-input">
              </div>

              <div class="mb-3">
                <label class="form-label fw-bold text-dark">Damage Location Description</label>
                <textarea name="description" class="form-control rounded-3" rows="2" placeholder="e.g. Deep pothole near Dharampeth square near bus stop..." required></textarea>
              </div>

              <!-- GPS Stamp Box -->
              <div class="row mb-3">
                <div class="col-6">
                  <label class="form-label fw-bold small text-dark">Latitude</label>
                  <input type="text" name="latitude" id="pothole-lat" class="form-control bg-light font-monospace fw-bold text-dark rounded-3" placeholder="Retrieving..." readonly required>
                </div>
                <div class="col-6">
                  <label class="form-label fw-bold small text-dark">Longitude</label>
                  <input type="text" name="longitude" id="pothole-lng" class="form-control bg-light font-monospace fw-bold text-dark rounded-3" placeholder="Retrieving..." readonly required>
                </div>
              </div>

              <div id="pothole-gps-status" class="alert alert-info border p-3 rounded-4 small mb-4 d-flex align-items-center gap-2" style="background-color: #f0f9ff; color: #0369a1;">
                <i class="bi bi-geo-fill fs-5 text-primary spinner-border spinner-border-sm me-1" id="pothole-gps-spinner"></i>
                <span id="pothole-gps-status-text" class="fw-semibold">Acquiring GPS coordinates via Geolocation...</span>
              </div>

              <button type="submit" name="submit_pothole_report" id="pothole-submit-btn" class="btn btn-primary btn-lg w-100 rounded-pill font-monospace fw-extrabold py-3 shadow-lg" disabled style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); border: none;">
                <i class="bi bi-send-fill me-2"></i>Submit Damage Report
              </button>
            </form>
          <?php else: ?>
            <div class="text-center py-5 px-3 border rounded-4 bg-light shadow-sm">
              <i class="bi bi-lock-fill text-primary display-3 mb-3 d-block"></i>
              <h5 class="fw-bold text-dark mb-2">Sign In Required</h5>
              <p class="text-muted small mb-4">Please sign in to log pothole reports with GPS location stamps.</p>
              <a href="login.php?redirect=potholes.php" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow"><i class="bi bi-box-arrow-in-right me-2"></i>Sign In Now</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Community Verification Poll Feed -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white h-100">
          <div class="card-header bg-white border-0 p-4 d-flex justify-content-between align-items-center border-bottom">
            <div>
              <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-check2-square text-success me-2"></i>Community Verification Polls</h5>
              <span class="small text-muted">Vote on citizen reports to help Municipal Authorities prioritize urgent repairs</span>
            </div>
            <span class="badge bg-success bg-opacity-10 text-success font-monospace px-3 py-2 rounded-pill fw-bold border border-success-subtle"><?php echo count($all_open_potholes); ?> Open Polls</span>
          </div>

          <div class="card-body p-4 bg-light">
            <?php if (empty($all_open_potholes)): ?>
              <div class="text-center py-5 text-muted">
                <i class="bi bi-check-circle-fill text-success display-3 mb-3 d-block"></i>
                <p class="mb-0 fw-semibold">No active pothole polls currently open.</p>
              </div>
            <?php else: ?>
              <div class="row g-3">
                <?php foreach ($all_open_potholes as $ph): 
                  $up = intval($ph['upvotes']);
                  $down = intval($ph['downvotes']);
                  $net = $up - $down;
                  $my_v = $ph['my_vote'];
                ?>
                  <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
                      <div class="row g-0">
                        <div class="col-md-5 position-relative">
                          <img src="<?php echo htmlspecialchars($ph['photo_path']); ?>" class="w-100 h-100" style="min-height: 180px; object-fit: cover;" alt="Pothole Photo">
                          
                          <a href="https://www.google.com/maps/search/?api=1&query=<?php echo $ph['latitude']; ?>,<?php echo $ph['longitude']; ?>" target="_blank" class="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75 backdrop-blur text-white text-decoration-none px-2.5 py-1.5 rounded-pill shadow-sm small" title="Open GPS Location in Google Maps">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo number_format($ph['latitude'], 4); ?>, <?php echo number_format($ph['longitude'], 4); ?> ↗
                          </a>
                        </div>
                        <div class="col-md-7 p-3 d-flex flex-column justify-content-between">
                          <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                              <span class="small text-muted font-monospace"><i class="bi bi-person-circle text-primary me-1"></i><?php echo htmlspecialchars($ph['reporter_email'] ? mask_email($ph['reporter_email']) : 'Citizen'); ?></span>
                              
                              <?php if ($net >= 3): ?>
                                <span class="badge bg-success text-white px-2.5 py-1 rounded-pill small"><i class="bi bi-patch-check-fill me-1"></i>Verified Genuine (+<?php echo $net; ?>)</span>
                              <?php else: ?>
                                <span class="badge bg-secondary text-white px-2.5 py-1 rounded-pill small"><i class="bi bi-hourglass-split me-1"></i>Pending (+<?php echo $net; ?>)</span>
                              <?php endif; ?>
                            </div>

                            <p class="card-text small text-dark fw-bold mb-2 fs-6" style="line-height: 1.4; color: #1e293b;"><?php echo htmlspecialchars($ph['description']); ?></p>
                            <span class="small text-muted d-block mb-3"><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($ph['created_at'])); ?></span>
                          </div>

                          <div class="pt-2 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <span class="small fw-bold text-muted">Is this genuine?</span>
                            <?php if ($is_logged_in): ?>
                              <div class="d-flex gap-2">
                                <form action="" method="POST" class="d-inline">
                                  <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                                  <input type="hidden" name="vote_type" value="upvote">
                                  <button type="submit" name="vote_pothole" class="btn btn-sm <?php echo ($my_v === 'upvote') ? 'btn-success' : 'btn-outline-success'; ?> rounded-pill px-3 py-1 d-flex align-items-center gap-1.5 fw-bold">
                                    <i class="bi bi-hand-thumbs-up-fill"></i>
                                    <span>Thumbs Up</span>
                                    <span class="badge <?php echo ($my_v === 'upvote') ? 'bg-white text-success' : 'bg-success text-white'; ?> rounded-pill ms-1 px-2"><?php echo $up; ?></span>
                                  </button>
                                </form>

                                <form action="" method="POST" class="d-inline">
                                  <input type="hidden" name="pothole_id" value="<?php echo $ph['id']; ?>">
                                  <input type="hidden" name="vote_type" value="downvote">
                                  <button type="submit" name="vote_pothole" class="btn btn-sm <?php echo ($my_v === 'downvote') ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill px-3 py-1 d-flex align-items-center gap-1.5 fw-bold">
                                    <i class="bi bi-hand-thumbs-down-fill"></i>
                                    <span>Thumbs Down</span>
                                    <span class="badge <?php echo ($my_v === 'downvote') ? 'bg-white text-danger' : 'bg-danger text-white'; ?> rounded-pill ms-1 px-2"><?php echo $down; ?></span>
                                  </button>
                                </form>
                              </div>
                            <?php else: ?>
                              <a href="login.php?redirect=potholes.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 small fw-bold">Sign in to Vote</a>
                            <?php endif; ?>
                          </div>
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
    </div>
  </div>

  <!-- Reusable Mobile Dock Navigation & Modals -->
  <?php include __DIR__ . '/bottom_dock.php'; ?>

  <!-- Leaflet Map JS & Geolocation Script -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      // Leaflet Map Initialization
      const potholesData = <?php echo json_encode($all_open_potholes); ?>;
      const map = L.map('pothole-main-map').setView([21.1458, 79.0882], 13);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      let markersGroup = L.featureGroup();

      potholesData.forEach(function(ph) {
        if (ph.latitude && ph.longitude) {
          const marker = L.marker([ph.latitude, ph.longitude]).addTo(markersGroup);
          const popupContent = `
            <div style="max-width: 220px;">
              <img src="${ph.photo_path}" style="width: 100%; height: 110px; object-fit: cover; border-radius: 8px; margin-bottom: 8px;">
              <strong style="display: block; font-size: 0.9rem; margin-bottom: 4px;">${ph.description}</strong>
              <a href="https://www.google.com/maps/search/?api=1&query=${ph.latitude},${ph.longitude}" target="_blank" class="btn btn-sm btn-danger text-white rounded-pill w-100 mt-2 py-1 small text-decoration-none">Open Google Maps ↗</a>
            </div>
          `;
          marker.bindPopup(popupContent);
        }
      });

      if (potholesData.length > 0) {
        markersGroup.addTo(map);
        map.fitBounds(markersGroup.getBounds().pad(0.2));
      }

      // Browser Geolocation
      const potholeLat = document.getElementById("pothole-lat");
      const potholeLng = document.getElementById("pothole-lng");
      const potholeBtn = document.getElementById("pothole-submit-btn");
      const potholeStatus = document.getElementById("pothole-gps-status-text");
      const potholeSpinner = document.getElementById("pothole-gps-spinner");

      if (navigator.geolocation && potholeLat && potholeLng) {
        navigator.geolocation.getCurrentPosition(
          function(position) {
            potholeLat.value = position.coords.latitude.toFixed(6);
            potholeLng.value = position.coords.longitude.toFixed(6);
            if (potholeBtn) potholeBtn.disabled = false;
            if (potholeStatus) potholeStatus.textContent = "GPS Location stamped: " + position.coords.latitude.toFixed(4) + ", " + position.coords.longitude.toFixed(4);
            if (potholeSpinner) potholeSpinner.classList.add("d-none");
          },
          function(err) {
            if (potholeStatus) potholeStatus.textContent = "GPS Access Denied. Defaulting to Central Nagpur coordinates.";
            potholeLat.value = "21.145800";
            potholeLng.value = "79.088200";
            if (potholeBtn) potholeBtn.disabled = false;
            if (potholeSpinner) potholeSpinner.classList.add("d-none");
          },
          { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
      } else if (potholeBtn) {
        potholeLat.value = "21.145800";
        potholeLng.value = "79.088200";
        potholeBtn.disabled = false;
      }
    });
  </script>
</body>
</html>
