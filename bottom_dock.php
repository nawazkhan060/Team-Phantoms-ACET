<?php
// bottom_dock.php - Reusable Mobile Dock Navigation & Direct Submission Modals
if (!isset($wards) && isset($conn)) {
    $wards_stmt = $conn->query("SELECT * FROM wards ORDER BY name ASC");
    $wards = $wards_stmt ? $wards_stmt->fetchAll() : [];
}
if (!isset($active_ward_id)) {
    $active_ward_id = $_SESSION['active_ward_id'] ?? null;
}
?>

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
              <?php if (!empty($wards)): foreach ($wards as $w): ?>
                <option value="<?php echo $w['id']; ?>" <?php echo ($active_ward_id == $w['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($w['name']); ?>
                </option>
              <?php endforeach; endif; ?>
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

<!-- Quick Camera Selector Launcher Modal -->
<div class="modal fade" id="quickCameraModal" tabindex="-1" aria-labelledby="quickCameraModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-dark" id="quickCameraModalLabel"><i class="bi bi-camera-fill text-success me-2"></i>Quick Camera & Scanner Hub</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2 pb-4">
        <p class="text-muted small mb-3">Select an action to capture evidence or scan directly in a pop-up window:</p>
        
        <div class="d-flex flex-column gap-2.5">
          <!-- Option 1: Pothole Report -->
          <button type="button" class="new-essential-item p-3 text-start text-decoration-none border-0 w-100 bg-white" data-bs-toggle="modal" data-bs-target="#quickPotholeModal">
            <div class="new-essential-icon" style="background-color: #f3e8ff; color: #7e22ce; border-radius: 14px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
              <i class="bi bi-tools"></i>
            </div>
            <div class="new-essential-content flex-grow-1 ms-2">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold mb-0 text-dark">1. Report Pothole Road Damage</h6>
                <span class="badge bg-primary rounded-pill px-2.5 py-1">Direct Pop-up</span>
              </div>
              <p class="text-muted small mb-0">Upload/capture live photo of road damage with auto GPS stamp.</p>
            </div>
          </button>
          
          <!-- Option 2: Traffic Challan -->
          <button type="button" class="new-essential-item p-3 text-start text-decoration-none border-0 w-100 bg-white" data-bs-toggle="modal" data-bs-target="#quickTrafficModal">
            <div class="new-essential-icon" style="background-color: #ffe4e6; color: #e11d48; border-radius: 14px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
              <i class="bi bi-camera-fill"></i>
            </div>
            <div class="new-essential-content flex-grow-1 ms-2">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold mb-0 text-dark">2. Traffic Violation Challan</h6>
                <span class="badge bg-danger rounded-pill px-2.5 py-1">Earn ₹50 Cash</span>
              </div>
              <p class="text-muted small mb-0">Submit photo evidence of traffic violations & earn cash rewards.</p>
            </div>
          </button>

          <!-- Option 3: CO2 Barcode Scanner -->
          <button type="button" class="new-essential-item p-3 text-start text-decoration-none border-0 w-100 bg-white" data-bs-toggle="modal" data-bs-target="#quickBarcodeModal">
            <div class="new-essential-icon" style="background-color: #dcfce7; color: #15803d; border-radius: 14px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
              <i class="bi bi-qr-code-scan"></i>
            </div>
            <div class="new-essential-content flex-grow-1 ms-2">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <h6 class="fw-bold mb-0 text-dark">3. Carbon CO2 Barcode Scanner</h6>
                <span class="badge bg-success rounded-pill px-2.5 py-1">+Eco Points</span>
              </div>
              <p class="text-muted small mb-0">Scan product barcodes directly & calculate carbon impact instant result.</p>
            </div>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 1. Direct Pop-up Modal: Quick Pothole Report -->
<div class="modal fade" id="quickPotholeModal" tabindex="-1" aria-labelledby="quickPotholeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
      <div class="modal-header border-0 bg-primary text-white py-3 px-4">
        <h5 class="modal-title fw-bold mb-0" id="quickPotholeModalLabel"><i class="bi bi-tools me-2"></i>Direct Pothole Damage Report</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form action="potholes.php" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label fw-bold text-dark"><i class="bi bi-camera-fill me-1 text-primary"></i>Photo Source</label>
            <div class="btn-group w-100 p-1 bg-light border rounded-pill mb-2.5" role="group">
              <input type="radio" class="btn-check" name="pothole_photo_source" id="modal-pothole-src-upload" value="upload" checked>
              <label class="btn btn-outline-primary border-0 rounded-pill font-monospace fw-bold py-1.5 small" for="modal-pothole-src-upload"><i class="bi bi-file-earmark-image me-1"></i>Upload File</label>

              <input type="radio" class="btn-check" name="pothole_photo_source" id="modal-pothole-src-webcam" value="webcam">
              <label class="btn btn-outline-primary border-0 rounded-pill font-monospace fw-bold py-1.5 small" for="modal-pothole-src-webcam"><i class="bi bi-camera-video-fill me-1"></i>Live Camera</label>
            </div>

            <!-- Upload File Input -->
            <div id="modal-pothole-upload-box">
              <input type="file" name="photo" class="form-control form-control-lg rounded-3" accept="image/*" capture="environment">
            </div>

            <!-- Live Camera Feed Box -->
            <div id="modal-pothole-webcam-box" class="d-none">
              <div class="position-relative bg-dark rounded-4 overflow-hidden border text-center mb-2 shadow-sm" style="height: 220px; display: flex; align-items: center; justify-content: center;">
                <video id="modal-pothole-video" autoplay playsinline class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;"></video>
                <canvas id="modal-pothole-canvas" class="d-none" width="640" height="480"></canvas>
                <img id="modal-pothole-preview" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;">
                <div id="modal-pothole-placeholder" class="text-center p-3 text-white">
                  <i class="bi bi-camera fs-1 text-info d-block mb-1"></i>
                  <span class="small">Click "Start Camera" to activate live feed</span>
                </div>
              </div>
              <div class="text-center d-flex justify-content-center gap-2">
                <button type="button" id="btn-modal-pothole-start" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold"><i class="bi bi-power me-1"></i>Start Camera</button>
                <button type="button" id="btn-modal-pothole-capture" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold d-none"><i class="bi bi-camera-fill me-1"></i>Capture</button>
              </div>
              <input type="hidden" name="webcam_photo" id="modal-pothole-webcam-data">
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-bold text-dark">Road Damage Description</label>
            <textarea name="description" class="form-control rounded-3" rows="2" placeholder="e.g. Large broken asphalt pothole on main road..." required></textarea>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <label class="form-label fw-bold small text-dark">Latitude</label>
              <input type="text" name="latitude" class="form-control bg-light font-monospace fw-bold modal-gps-lat" placeholder="21.145800" readonly required>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold small text-dark">Longitude</label>
              <input type="text" name="longitude" class="form-control bg-light font-monospace fw-bold modal-gps-lng" placeholder="79.088200" readonly required>
            </div>
          </div>

          <div class="alert alert-info border p-2.5 rounded-3 small mb-4 font-monospace">
            <i class="bi bi-geo-alt-fill text-danger me-1"></i><span class="modal-gps-status">Auto-stamping live location...</span>
          </div>

          <button type="submit" name="submit_pothole_report" class="btn btn-primary btn-lg w-100 rounded-pill font-monospace fw-extrabold py-2.5 shadow-sm">
            <i class="bi bi-send-fill me-1.5"></i>Submit Pothole Report Directly
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- 2. Direct Pop-up Modal: Quick Traffic Violation Report -->
<div class="modal fade" id="quickTrafficModal" tabindex="-1" aria-labelledby="quickTrafficModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
      <div class="modal-header border-0 py-3 px-4 text-white" style="background: linear-gradient(135deg, #be123c 0%, #e11d48 100%);">
        <h5 class="modal-title fw-bold mb-0" id="quickTrafficModalLabel"><i class="bi bi-shield-exclamation me-2"></i>Traffic Enforcement (Earn ₹50 Cash)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form action="dashboard.php?tab=traffic" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Violation Type</label>
            <select name="violation_type" class="form-select rounded-3 fw-bold" required>
              <option value="Traffic Signal Break 🚦">Traffic Signal Break 🚦</option>
              <option value="Riding Without Helmet 🪖">Riding Without Helmet 🪖</option>
              <option value="Triple Riding on Two-Wheeler 🏍️">Triple Riding on Two-Wheeler 🏍️</option>
              <option value="Driving on Sidewalk / Footpath 🛣️">Driving on Sidewalk / Footpath 🛣️</option>
              <option value="Illegal No-Parking Zone 🅿️">Illegal No-Parking Zone 🅿️</option>
              <option value="Over-speeding & Reckless Driving 🏎️">Over-speeding & Reckless Driving 🏎️</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold text-dark">Vehicle Plate Number (Optional)</label>
            <input type="text" name="vehicle_number" class="form-control font-monospace text-uppercase fw-bold rounded-3" placeholder="e.g. MH39BA3148">
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold text-dark"><i class="bi bi-camera-fill me-1 text-danger"></i>Photo Evidence</label>
            <div class="btn-group w-100 p-1 bg-light border rounded-pill mb-2.5" role="group">
              <input type="radio" class="btn-check" name="traffic_photo_source" id="modal-traffic-src-upload" value="upload" checked>
              <label class="btn btn-outline-danger border-0 rounded-pill font-monospace fw-bold py-1.5 small" for="modal-traffic-src-upload"><i class="bi bi-file-earmark-image me-1"></i>Upload File</label>

              <input type="radio" class="btn-check" name="traffic_photo_source" id="modal-traffic-src-webcam" value="webcam">
              <label class="btn btn-outline-danger border-0 rounded-pill font-monospace fw-bold py-1.5 small" for="modal-traffic-src-webcam"><i class="bi bi-camera-video-fill me-1"></i>Live Camera</label>
            </div>

            <!-- Upload File Input -->
            <div id="modal-traffic-upload-box">
              <input type="file" name="violation_photo" class="form-control form-control-lg rounded-3" accept="image/*" capture="environment">
            </div>

            <!-- Live Camera Feed Box -->
            <div id="modal-traffic-webcam-box" class="d-none">
              <div class="position-relative bg-dark rounded-4 overflow-hidden border text-center mb-2 shadow-sm" style="height: 220px; display: flex; align-items: center; justify-content: center;">
                <video id="modal-traffic-video" autoplay playsinline class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;"></video>
                <canvas id="modal-traffic-canvas" class="d-none" width="640" height="480"></canvas>
                <img id="modal-traffic-preview" class="position-absolute top-0 start-0 w-100 h-100 d-none" style="object-fit: cover;">
                <div id="modal-traffic-placeholder" class="text-center p-3 text-white">
                  <i class="bi bi-camera fs-1 text-danger d-block mb-1"></i>
                  <span class="small">Click "Start Camera" to activate live feed</span>
                </div>
              </div>
              <div class="text-center d-flex justify-content-center gap-2">
                <button type="button" id="btn-modal-traffic-start" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold"><i class="bi bi-power me-1"></i>Start Camera</button>
                <button type="button" id="btn-modal-traffic-capture" class="btn btn-sm btn-danger rounded-pill px-3 py-1 fw-bold d-none"><i class="bi bi-camera-fill me-1"></i>Capture</button>
              </div>
              <input type="hidden" name="webcam_photo" id="modal-traffic-webcam-data">
            </div>
          </div>

          <div class="row mb-3">
            <div class="col-6">
              <label class="form-label fw-bold small text-dark">Latitude</label>
              <input type="text" name="latitude" class="form-control bg-light font-monospace fw-bold modal-gps-lat" placeholder="21.145800" readonly required>
            </div>
            <div class="col-6">
              <label class="form-label fw-bold small text-dark">Longitude</label>
              <input type="text" name="longitude" class="form-control bg-light font-monospace fw-bold modal-gps-lng" placeholder="79.088200" readonly required>
            </div>
          </div>

          <div class="alert alert-danger border p-2.5 rounded-3 small mb-4 font-monospace" style="background-color: #fff1f2; color: #be123c;">
            <i class="bi bi-geo-alt-fill text-danger me-1"></i><span class="modal-gps-status">Auto-stamping live location...</span>
          </div>

          <button type="submit" name="submit_traffic_report" class="btn btn-danger btn-lg w-100 rounded-pill font-monospace fw-extrabold py-2.5 shadow-sm" style="background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); border: none;">
            <i class="bi bi-send-fill me-1.5"></i>Submit Violation & Earn ₹50 Cash 🚀
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- 3. Direct Pop-up Modal: Quick Carbon CO2 Scanner -->
<div class="modal fade" id="quickBarcodeModal" tabindex="-1" aria-labelledby="quickBarcodeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
      <div class="modal-header border-0 py-3 px-4 text-white" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
        <h5 class="modal-title fw-bold mb-0" id="quickBarcodeModalLabel"><i class="bi bi-qr-code-scan me-2"></i>Carbon CO2 Product Scanner</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4 text-center">
        <p class="text-muted small mb-3">Scan product barcode or tap a quick test item to pop up instant carbon calculations:</p>
        
        <div class="mb-4">
          <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden border">
            <span class="input-group-text bg-white border-0 ps-3"><i class="bi bi-upc-scan text-success fs-4"></i></span>
            <input type="text" id="quick-barcode-input" class="form-control border-0 font-monospace fw-bold text-dark px-2" placeholder="Enter barcode (e.g. 8901152010118)...">
            <button type="button" id="btn-trigger-quick-scan" class="btn btn-success px-4 font-monospace fw-bold" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border: none;">
              Scan 🚀
            </button>
          </div>
        </div>

        <div class="border-top pt-3 text-start">
          <span class="small text-muted fw-bold d-block mb-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>1-Click Test Products:</span>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-success rounded-pill font-monospace fw-bold quick-scan-pill" data-barcode="8901152010118">
              🥤 Bisleri Water 100ml
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill font-monospace fw-bold quick-scan-pill" data-barcode="8901262010012">
              🥛 Amul Taaza Milk 500ml
            </button>
            <button type="button" class="btn btn-sm btn-outline-warning rounded-pill font-monospace fw-bold text-dark quick-scan-pill" data-barcode="8901491001012">
              🥔 Lay's Magic Masala
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill font-monospace fw-bold quick-scan-pill" data-barcode="8901764012015">
              🍾 Coca-Cola 750ml
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 4. Ultra-Beautiful Scanned Product Result Pop-up Modal -->
<div class="modal fade" id="productScanModal" tabindex="-1" aria-labelledby="productScanModalLabel" aria-hidden="true" style="backdrop-filter: blur(8px);">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 30px !important; box-shadow: 0 25px 60px rgba(5, 150, 105, 0.35) !important;">
      
      <!-- Gradient Emerald Header -->
      <div class="modal-header border-0 text-white py-3.5 px-4 position-relative" style="background: linear-gradient(135deg, #059669 0%, #10b981 50%, #047857 100%);">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle bg-white text-success d-flex align-items-center justify-content-center shadow" style="width: 48px; height: 48px;">
            <i class="bi bi-box-seam-fill fs-4"></i>
          </div>
          <div>
            <h4 class="modal-title fw-extrabold text-white mb-0" id="scanModalTitle" style="letter-spacing: -0.5px;">Product Carbon Analysis</h4>
            <span class="small text-white opacity-90 font-monospace">NAGPUR MUNICIPAL SMART ECO-DATABASE</span>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4 p-md-5 bg-white">
        <!-- Rewards Banner -->
        <div id="modalRewardBanner" class="alert border-0 rounded-4 d-flex align-items-center gap-3 p-3.5 mb-4 shadow-sm" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border: 1px solid #86efac !important;">
          <div class="bg-success text-white rounded-circle p-2.5 d-flex align-items-center justify-content-center shadow" style="width: 52px; height: 52px; flex-shrink: 0;">
            <i class="bi bi-trophy-fill fs-4"></i>
          </div>
          <div class="flex-grow-1">
            <h5 class="fw-extrabold text-success-emphasis mb-1" id="modalRewardHeading">Scan Verified & Eco Reward Added! 🎉</h5>
            <p class="mb-0 small fw-bold text-success-emphasis" id="modalRewardDesc">Earned +15 Eco Points & ₹5.00 cash reward added to your civic wallet.</p>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <!-- Product Specs -->
          <div class="col-md-6">
            <div class="p-4 rounded-4 bg-light border h-100 shadow-sm" style="border-color: #e2e8f0 !important;">
              <span class="badge bg-success bg-opacity-15 text-success font-monospace px-3 py-1.5 rounded-pill mb-2 fw-bold" id="modalCategoryBadge">Plastic Bottle</span>
              <h3 class="fw-extrabold text-dark mb-2" id="modalProductName" style="letter-spacing: -0.5px;">Bisleri Water 100ml</h3>
              
              <div class="d-flex flex-column gap-2 text-muted small mt-3">
                <div><i class="bi bi-tag-fill me-2 text-success fs-6"></i>Brand: <strong id="modalProductBrand" class="text-dark fs-6">Bisleri International</strong></div>
                <div><i class="bi bi-aspect-ratio me-2 text-primary fs-6"></i>Weight/Volume: <strong id="modalProductWeight" class="text-dark fs-6">100 ML</strong></div>
                <div><i class="bi bi-upc-scan me-2 text-secondary fs-6"></i>Barcode: <span id="modalProductBarcode" class="font-monospace text-dark fw-bold">8901152010118</span></div>
              </div>
            </div>
          </div>

          <!-- CO2 Footprint Score -->
          <div class="col-md-6">
            <div class="p-4 rounded-4 bg-light border h-100 d-flex flex-column justify-content-between shadow-sm" style="border-color: #e2e8f0 !important;">
              <div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="fw-extrabold text-danger mb-0"><i class="bi bi-clouds-fill me-1"></i>Carbon Impact Rating</h6>
                  <span id="modalCo2Value" class="fw-extrabold fs-3 text-success font-monospace">0.12 kg CO2e</span>
                </div>
                <div class="progress mb-3" style="height: 12px; border-radius: 6px; background-color: #e2e8f0;">
                  <div id="modalCo2Bar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: 25%;"></div>
                </div>
              </div>
              <div class="p-3 rounded-3 bg-white border border-success-subtle">
                <p class="text-dark small mb-0 fw-semibold" id="modalCo2Comparison">
                  🍃 Low Carbon Impact! Recycle this container at your nearest NMC bin to earn extra eco points.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Recycling Action Guide -->
        <div class="rounded-4 p-4 border border-success-subtle shadow-sm" style="background: linear-gradient(135deg, rgba(220, 252, 231, 0.5) 0%, rgba(240, 253, 244, 0.8) 100%);">
          <h5 class="fw-extrabold text-success mb-2 d-flex align-items-center gap-2">
            <i class="bi bi-recycle fs-4"></i>NMC Green Disposal Instructions
          </h5>
          <p class="text-dark small mb-0 fw-semibold" id="modalRecyclingTip">
            Rinse out plastic bottle, flatten container to preserve bin space, and discard into Blue Dry-Waste Receptacle.
          </p>
        </div>

        <div class="mt-4 text-end">
          <button type="button" class="btn btn-success btn-lg rounded-pill px-5 font-monospace fw-extrabold shadow-lg" data-bs-dismiss="modal" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border: none;">
            <i class="bi bi-check-circle-fill me-2"></i>Close & Claim Reward 🎉
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 5. In-Page Pop-Up Modal: ElevenLabs Voice AI Assistant (Aanya) -->
<div class="modal fade" id="elevenLabsVoiceModal" tabindex="-1" aria-labelledby="elevenLabsVoiceModalLabel" aria-hidden="true" style="backdrop-filter: blur(8px);">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
      <div class="modal-header border-0 text-white py-3.5 px-4" style="background: linear-gradient(135deg, #059669 0%, #0d9488 50%, #0f172a 100%);">
        <div class="d-flex align-items-center gap-2.5">
          <div class="bg-white text-success rounded-circle p-2 d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
            <i class="bi bi-mic-fill fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-extrabold mb-0 text-white" id="elevenLabsVoiceModalLabel">Aanya — NMC Voice AI Assistant 🎙️</h5>
            <span class="small opacity-90 font-monospace">OFFICIAL NAGPUR MAHANAGAR PALIKA CONVERSATIONAL AI</span>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-0 bg-dark position-relative" style="min-height: 520px;">
        <!-- Embedded ElevenLabs Talk-To Agent Frame -->
        <iframe src="https://elevenlabs.io/app/talk-to?agent_id=agent_9001m1gjrgkfemf9dw6xd5v350yv&branch_id=agtbrch_3101m1gjrhrceph9jvnda5npt5wv" allow="microphone; camera; autoplay" class="w-100 border-0" style="height: 540px; width: 100%; border: none;"></iframe>
      </div>

      <div class="modal-footer border-0 bg-light py-2.5 px-4 d-flex justify-content-between align-items-center">
        <span class="small font-monospace text-muted"><i class="bi bi-shield-check text-success me-1"></i>Powered by ElevenLabs & NMC AI Engine</span>
        <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close Assistant</button>
      </div>
    </div>
  </div>
</div>

<!-- Official ElevenLabs Convai Custom Element Script Tag -->
<elevenlabs-convai agent-id="agent_9001m1gjrgkfemf9dw6xd5v350yv"></elevenlabs-convai>
<script src="https://elevenlabs.io/convai-widget/index.js" async type="text/javascript"></script>

<!-- App-Style Mobile Bottom Navigation Dock (Fixed Sticky on Mobile Viewports < 768px) -->
<nav class="mobile-nav-dock">
  <a href="index.php" class="mobile-nav-item <?php echo (basename($_SERVER['PHP_SELF']) === 'index.php') ? 'active' : ''; ?>">
    <i class="bi bi-house-door-fill"></i>
    <span>Home</span>
  </a>
  <a href="polls.php" class="mobile-nav-item <?php echo (basename($_SERVER['PHP_SELF']) === 'polls.php') ? 'active' : ''; ?>">
    <i class="bi bi-check2-square"></i>
    <span>Polls</span>
  </a>
  <button type="button" class="mobile-nav-item mobile-nav-fab" data-bs-toggle="modal" data-bs-target="#quickCameraModal" title="Quick Camera Upload & Scanner">
    <i class="bi bi-camera-fill"></i>
  </button>
  <a href="potholes.php" class="mobile-nav-item <?php echo (basename($_SERVER['PHP_SELF']) === 'potholes.php') ? 'active' : ''; ?>">
    <i class="bi bi-tools"></i>
    <span>Report</span>
  </a>
  <a href="profile.php" class="mobile-nav-item <?php echo (basename($_SERVER['PHP_SELF']) === 'profile.php') ? 'active' : ''; ?>">
    <i class="bi bi-person-circle"></i>
    <span>Profile</span>
  </a>
</nav>

<!-- Global Client-side Script for Quick Modals, Geolocation, and Product Scans -->
<script>
  document.addEventListener("DOMContentLoaded", function() {
    // 1. Auto GPS Location Stamping for Modals
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(function(pos) {
        document.querySelectorAll(".modal-gps-lat").forEach(el => el.value = pos.coords.latitude.toFixed(6));
        document.querySelectorAll(".modal-gps-lng").forEach(el => el.value = pos.coords.longitude.toFixed(6));
        document.querySelectorAll(".modal-gps-status").forEach(el => el.textContent = "GPS Stamped: " + pos.coords.latitude.toFixed(4) + ", " + pos.coords.longitude.toFixed(4));
      }, function(err) {
        document.querySelectorAll(".modal-gps-lat").forEach(el => el.value = "21.145800");
        document.querySelectorAll(".modal-gps-lng").forEach(el => el.value = "79.088200");
        document.querySelectorAll(".modal-gps-status").forEach(el => el.textContent = "GPS Default: Central Nagpur (21.1458, 79.0882)");
      }, { timeout: 8000 });
    }

    // 2. Sample Products Database for Instant Pop-up Modal
    const sampleProducts = {
      '8901152010118': { name: 'Bisleri Mineral Water 100ml', brand: 'Bisleri International', weight: '100 ML', category: 'Plastic Bottle', co2: 0.12, tip: 'Rinse, crush bottle, and drop into Blue Municipal Dry-Waste Recycler Bin.' },
      '8901262010012': { name: 'Amul Taaza Toned Milk 500ml', brand: 'GCMMF Amul', weight: '500 ML', category: 'Dairy Pouch', co2: 0.85, tip: 'Rinse milk pouch thoroughly, dry, and place with soft plastic recycling collection.' },
      '8901491001012': { name: 'Lay\'s Magic Masala Potato Chips', brand: 'PepsiCo India', weight: '52 G', category: 'Snack Wrapper', co2: 0.35, tip: 'Multi-layer plastic packet. Dispose into Dry Waste for Eco-brick energy generation.' },
      '8901764012015': { name: 'Coca-Cola Soft Drink 750ml', brand: 'Coca-Cola Company', weight: '750 ML', category: 'PET Bottle', co2: 0.45, tip: 'Remove cap, crush PET bottle, and hand over to municipal rag-picker or recycling kiosk.' }
    };

    function triggerProductModal(code) {
      const p = sampleProducts[code] || {
        name: 'Municipal Certified Consumer Item (' + code + ')',
        brand: 'NMC Partnered Brand',
        weight: 'Standard Pack',
        category: 'Eco Item',
        co2: 0.25,
        tip: 'Dispose into designated municipal recycling bin to preserve urban cleanliness.'
      };

      const titleEl = document.getElementById("modalProductName");
      const categoryEl = document.getElementById("modalCategoryBadge");
      const brandEl = document.getElementById("modalProductBrand");
      const weightEl = document.getElementById("modalProductWeight");
      const barcodeEl = document.getElementById("modalProductBarcode");
      const co2ValEl = document.getElementById("modalCo2Value");
      const co2BarEl = document.getElementById("modalCo2Bar");
      const tipEl = document.getElementById("modalRecyclingTip");

      if (titleEl) titleEl.textContent = p.name;
      if (categoryEl) categoryEl.textContent = p.category;
      if (brandEl) brandEl.textContent = p.brand;
      if (weightEl) weightEl.textContent = p.weight;
      if (barcodeEl) barcodeEl.textContent = code;
      if (co2ValEl) co2ValEl.textContent = p.co2 + " kg CO2e";
      if (co2BarEl) co2BarEl.style.width = Math.min(100, (p.co2 * 100)) + "%";
      if (tipEl) tipEl.textContent = p.tip;

      // Close quickBarcodeModal if open
      const quickModal = bootstrap.Modal.getInstance(document.getElementById('quickBarcodeModal'));
      if (quickModal) quickModal.hide();

      // Show productScanModal
      const scanModal = new bootstrap.Modal(document.getElementById('productScanModal'));
      scanModal.show();
    }

    // Trigger button
    const btnScan = document.getElementById("btn-trigger-quick-scan");
    const inputScan = document.getElementById("quick-barcode-input");
    if (btnScan && inputScan) {
      btnScan.addEventListener("click", function() {
        const code = inputScan.value.trim() || '8901152010118';
        triggerProductModal(code);
      });
    }

    // Pills
    document.querySelectorAll(".quick-scan-pill").forEach(pill => {
      pill.addEventListener("click", function() {
        const code = this.getAttribute("data-barcode");
        triggerProductModal(code);
      });
    });

    // Live Camera Handler for Quick Pop-up Modals
    function setupModalCamera(prefix) {
      const srcUpload = document.getElementById(`modal-${prefix}-src-upload`);
      const srcWebcam = document.getElementById(`modal-${prefix}-src-webcam`);
      const uploadBox = document.getElementById(`modal-${prefix}-upload-box`);
      const webcamBox = document.getElementById(`modal-${prefix}-webcam-box`);
      const video = document.getElementById(`modal-${prefix}-video`);
      const canvas = document.getElementById(`modal-${prefix}-canvas`);
      const preview = document.getElementById(`modal-${prefix}-preview`);
      const placeholder = document.getElementById(`modal-${prefix}-placeholder`);
      const btnStart = document.getElementById(`btn-modal-${prefix}-start`);
      const btnCapture = document.getElementById(`btn-modal-${prefix}-capture`);
      const webcamData = document.getElementById(`modal-${prefix}-webcam-data`);
      
      let stream = null;

      if (!srcUpload || !srcWebcam) return;

      srcUpload.addEventListener('change', function() {
        if (this.checked) {
          uploadBox.classList.remove('d-none');
          webcamBox.classList.add('d-none');
          stopCamera();
        }
      });

      srcWebcam.addEventListener('change', function() {
        if (this.checked) {
          uploadBox.classList.add('d-none');
          webcamBox.classList.remove('d-none');
          startCamera();
        }
      });

      async function startCamera() {
        try {
          stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
          video.srcObject = stream;
          video.classList.remove('d-none');
          placeholder.classList.add('d-none');
          btnStart.classList.add('d-none');
          btnCapture.classList.remove('d-none');
        } catch (err) {
          alert('Could not access camera. Please check camera permissions or select Upload File.');
        }
      }

      function stopCamera() {
        if (stream) {
          stream.getTracks().forEach(track => track.stop());
          stream = null;
        }
        video.classList.add('d-none');
        placeholder.classList.remove('d-none');
        btnStart.classList.remove('d-none');
        btnCapture.classList.add('d-none');
      }

      if (btnStart) btnStart.addEventListener('click', startCamera);

      if (btnCapture) {
        btnCapture.addEventListener('click', function() {
          const context = canvas.getContext('2d');
          canvas.width = video.videoWidth || 640;
          canvas.height = video.videoHeight || 480;
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          const dataUrl = canvas.toDataURL('image/jpeg');
          preview.src = dataUrl;
          preview.classList.remove('d-none');
          webcamData.value = dataUrl;
          stopCamera();
          btnStart.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Retake Photo';
          btnStart.classList.remove('d-none');
        });
      }
    }

    setupModalCamera('pothole');
    setupModalCamera('traffic');
  });
</script>

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

<!-- Bulletproof Programmatic Language Switcher (Devtunnels, Localhost & Mobile) -->
<script type="text/javascript">
  document.addEventListener("DOMContentLoaded", function() {
    const savedLang = localStorage.getItem("app_lang") || "en";
    const labels = { en: "EN", hi: "HI", mr: "MR" };
    const labelEl = document.getElementById("current-lang-label");
    if (labelEl) labelEl.textContent = labels[savedLang] || "EN";

    function setTransCookies(lang) {
      const isHttps = window.location.protocol === 'https:';
      const secureFlag = isHttps ? '; Secure' : '';
      const host = window.location.hostname;
      
      const hostParts = host.split('.');
      const parentDomain = hostParts.length > 1 ? hostParts.slice(-2).join('.') : host;
      
      const domains = ['', host, '.' + host, parentDomain, '.' + parentDomain];
      domains.forEach(d => {
        const domStr = d ? '; domain=' + d : '';
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/" + domStr;
        document.cookie = "googtrans=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=; domain=" + d;
      });

      domains.forEach(d => {
        const domStr = d ? '; domain=' + d : '';
        document.cookie = "googtrans=/en/" + lang + "; path=/; SameSite=Lax" + secureFlag + domStr;
      });
    }

    document.querySelectorAll(".lang-select-btn").forEach(btn => {
      btn.addEventListener("click", function(e) {
        e.preventDefault();
        const lang = this.getAttribute("data-lang");
        localStorage.setItem("app_lang", lang);
        
        setTransCookies(lang);
        
        const selectElement = document.querySelector('.goog-te-combo');
        if (selectElement) {
          selectElement.value = lang;
          selectElement.dispatchEvent(new Event('change'));
        }
        
        setTimeout(() => {
          window.location.reload();
        }, 100);
      });
    });

    let checkAttempts = 0;
    const syncInterval = setInterval(() => {
      const selectElement = document.querySelector('.goog-te-combo');
      checkAttempts++;
      if (selectElement) {
        clearInterval(syncInterval);
        if (savedLang && selectElement.value !== savedLang) {
          selectElement.value = savedLang;
          selectElement.dispatchEvent(new Event('change'));
        }
      } else if (checkAttempts > 50) {
        clearInterval(syncInterval);
      }
    }, 100);
  });
</script>
