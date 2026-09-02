<?php
// login.php - OTP-based email login page
require_once 'config.php';

$is_logged_in = is_logged_in();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

$error = '';
$success = '';

if (isset($_GET['redirect'])) {
    $_SESSION['redirect_url'] = $_GET['redirect'];
}

// Check if user is already logged in
if (is_logged_in()) {
    $user = get_logged_in_user($conn);
    if ($user) {
        if ($user['role'] === 'admin') {
            header("Location: admin.php");
        } else {
            header("Location: dashboard.php");
        }
        exit;
    }
}

// Fetch Wards
$wards_stmt = $conn->query("SELECT * FROM wards ORDER BY name ASC");
$wards = $wards_stmt ? $wards_stmt->fetchAll() : [];

// Stage 1: Send OTP
if (isset($_POST['send_otp'])) {
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $ward_id = intval($_POST['ward_id'] ?? 0) ?: null;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Handle profile picture upload
        $profile_pic = null;
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $profile_pic = handle_file_upload($_FILES['profile_pic'], 'profile_');
        }

        // Find or create user
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // New citizen user registration
            $role = ($email === 'admin@civic.gov') ? 'admin' : 'citizen';
            $stmt = $conn->prepare("INSERT INTO users (email, full_name, phone, ward_id, profile_pic, role, wallet_balance) VALUES (?, ?, ?, ?, ?, ?, 0.00)");
            $stmt->execute([$email, $full_name, $phone, $ward_id, $profile_pic, $role]);
            
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
        } else {
            // Update profile info if submitted during sign in
            if (!empty($full_name) || !empty($phone) || $ward_id > 0 || $profile_pic !== null) {
                $final_pic = $profile_pic ?: ($user['profile_pic'] ?? null);
                $stmt = $conn->prepare("UPDATE users SET full_name = COALESCE(NULLIF(?, ''), full_name), phone = COALESCE(NULLIF(?, ''), phone), ward_id = COALESCE(NULLIF(?, 0), ward_id), profile_pic = COALESCE(?, profile_pic) WHERE id = ?");
                $stmt->execute([$full_name, $phone, $ward_id, $final_pic, $user['id']]);
            }
        }
        
        // Generate 6-digit OTP
        $otp = sprintf("%06d", mt_rand(0, 999999));
        $expiry = date('Y-m-d H:i:s', time() + 300); // 5 minutes validity
        
        // Update user OTP
        $stmt = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?");
        $stmt->execute([$otp, $expiry, $user['id']]);
        
        // Send email using PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email);
            
            $mail->isHTML(true);
            $mail->Subject = 'Your OTP for City Civic Portal';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                    <h2 style='color: #27ae60; text-align: center;'>City Civic Portal</h2>
                    <p>Hello,</p>
                    <p>You requested access to the City Civic Portal. Use the following 6-digit One-Time Password (OTP) to log in:</p>
                    <div style='background: #f0fdf4; border: 1px dashed #27ae60; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #166534; margin: 20px 0;'>
                        {$otp}
                    </div>
                    <p style='font-size: 12px; color: #64748b; text-align: center;'>This OTP is valid for 5 minutes. Please do not share it with anyone.</p>
                </div>";
            
            $mail->send();
            
            // Set session variables to proceed to verification stage
            $_SESSION['temp_email'] = $email;
            $_SESSION['otp_cooldown'] = time() + 60; // 60 seconds cooldown
            $success = "OTP has been sent to your email!";
        } catch (Exception $e) {
            $is_localhost = ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' || ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1' || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost:') || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:');
            if ($is_localhost) {
                $_SESSION['temp_email'] = $email;
                $_SESSION['otp_cooldown'] = time() + 60;
                $success = "OTP has been generated (Local Dev Bypass: check display below)!";
            } else {
                $error = "Mail could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        }
    }
}

// Stage 2: Verify OTP
if (isset($_POST['verify_otp'])) {
    $email = $_SESSION['temp_email'] ?? '';
    $entered_otp = trim($_POST['otp']);
    
    if (empty($email)) {
        $error = "Session expired. Please enter your email again.";
        unset($_SESSION['temp_email']);
    } else if (empty($entered_otp)) {
        $error = "Please enter the OTP.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && $user['otp'] === $entered_otp && strtotime($user['otp_expiry']) > time()) {
            // Clear OTP fields
            $stmt = $conn->prepare("UPDATE users SET otp = NULL, otp_expiry = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            unset($_SESSION['temp_email']);
            
            if ($user['role'] === 'admin') {
                header("Location: admin.php");
            } else {
                $redirect = $_SESSION['redirect_url'] ?? 'dashboard.php';
                unset($_SESSION['redirect_url']);
                header("Location: " . $redirect);
            }
            exit;
        } else {
            $error = "Invalid or expired OTP. Please try again.";
        }
    }
}

// Resend OTP handler
if (isset($_POST['resend_otp'])) {
    $email = $_SESSION['temp_email'] ?? '';
    if (empty($email)) {
        $error = "Session expired. Please enter your email again.";
    } else if (isset($_SESSION['otp_cooldown']) && $_SESSION['otp_cooldown'] > time()) {
        $error = "Please wait before resending OTP.";
    } else {
        // Generate new OTP
        $otp = sprintf("%06d", mt_rand(0, 999999));
        $expiry = date('Y-m-d H:i:s', time() + 300);
        
        $stmt = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE email = ?");
        $stmt->execute([$otp, $expiry, $email]);
        
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($email);
            
            $mail->isHTML(true);
            $mail->Subject = 'Your OTP for City Civic Portal (Resent)';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 10px;'>
                    <h2 style='color: #27ae60; text-align: center;'>City Civic Portal</h2>
                    <p>Hello,</p>
                    <p>Here is your resent One-Time Password (OTP) to log in:</p>
                    <div style='background: #f0fdf4; border: 1px dashed #27ae60; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #166534; margin: 20px 0;'>
                        {$otp}
                    </div>
                    <p style='font-size: 12px; color: #64748b; text-align: center;'>This OTP is valid for 5 minutes. Please do not share it with anyone.</p>
                </div>";
            $mail->send();
            
            $_SESSION['otp_cooldown'] = time() + 60;
            $success = "OTP has been resent successfully!";
        } catch (Exception $e) {
            $is_localhost = ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' || ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1' || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost:') || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:');
            if ($is_localhost) {
                $_SESSION['otp_cooldown'] = time() + 60;
                $success = "OTP has been resent (Local Dev Bypass: check display below)!";
            } else {
                $error = "Mail could not be sent. Mailer Error: {$mail->ErrorInfo}";
            }
        }
    }
}

// Cancel OTP entry and go back
if (isset($_POST['change_email'])) {
    unset($_SESSION['temp_email']);
    header("Location: login.php");
    exit;
}

$is_otp_stage = isset($_SESSION['temp_email']);
$cooldown_time = isset($_SESSION['otp_cooldown']) ? max(0, $_SESSION['otp_cooldown'] - time()) : 0;

$dev_otp = '';
$is_localhost = ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' || ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1' || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost:') || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:');
if ($is_otp_stage && $is_localhost) {
    $stmt = $conn->prepare("SELECT otp FROM users WHERE email = ?");
    $stmt->execute([$_SESSION['temp_email']]);
    $dev_otp = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - City Civic Portal</title>
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet" />
  
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
    /* Specific overrides for login portal */
    body {
      background-color: var(--bg-app);
      overflow-y: auto;
    }
  </style>
</head>
<body>

  <div class="login-split-container">
    <!-- Left Panel: Brand Showcase -->
    <div class="login-hero-panel">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-building-fill-gear text-success fs-3"></i>
        <span class="fs-4 fw-bold text-white tracking-wider">CIVIC PORTAL</span>
      </div>
      
      <div class="my-auto py-5">
        <h1 class="display-5 fw-bold text-white mb-4" style="line-height: 1.2;">
          Smart Public Services<br>
          <span style="background: linear-gradient(135deg, #10b981 0%, #34d399 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">For a Sustainable Future</span>
        </h1>
        <p class="lead text-white-50 mb-5">
          Submit utility outage reports, review weekly schedules, track air quality, file traffic challans, and earn verified wallet rewards instantly.
        </p>
        
        <div class="d-flex flex-column gap-3 mb-5">
          <div class="d-flex align-items-center gap-3">
            <div class="bg-success bg-opacity-25 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
              <i class="bi bi-camera-fill text-success fs-5"></i>
            </div>
            <span class="text-white opacity-85">Earn ₹50 rewards for reporting traffic violations.</span>
          </div>
          <div class="d-flex align-items-center gap-3">
            <div class="bg-success bg-opacity-25 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
              <i class="bi bi-lightning-charge-fill text-warning fs-5"></i>
            </div>
            <span class="text-white opacity-85">Stay informed with real-time ward schedules.</span>
          </div>
          <div class="d-flex align-items-center gap-3">
            <div class="bg-success bg-opacity-25 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
              <i class="bi bi-map-fill text-info fs-5"></i>
            </div>
            <span class="text-white opacity-85">Interactive map tracking for pothole repairs.</span>
          </div>
        </div>

        <div class="row g-4 border-top border-white border-opacity-10 pt-4">
          <div class="col-6">
            <h4 class="fw-bold text-white mb-0">5 Wards</h4>
            <span class="text-white-50 small">Fully Integrated</span>
          </div>
          <div class="col-6">
            <h4 class="fw-bold text-white mb-0">100%</h4>
            <span class="text-white-50 small">Online Submissions</span>
          </div>
        </div>
      </div>
      
      <div class="text-white-50 small">
        © 2026 City Municipal Corporation. All Rights Reserved.
      </div>
    </div>

    <!-- Right Panel: Login Card -->
    <div class="login-form-panel">
      <!-- Dark Mode Toggle Button on top right of form area -->
      <div class="position-absolute top-0 end-0 p-3 m-2 d-flex align-items-center gap-2">
        <button id="theme-toggle" class="btn btn-link nav-link px-2 text-secondary" type="button" aria-label="Toggle Theme">
          <i class="bi bi-sun-fill d-none-theme-light text-warning fs-5"></i>
          <i class="bi bi-moon-stars-fill d-none-theme-dark text-primary fs-5"></i>
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="bi bi-arrow-left me-1"></i>Back to Home</a>
      </div>

      <div class="card p-4 p-md-5 border-0 shadow-lg w-100" style="max-width: 480px;">
        <div class="text-center mb-4">
          <div class="avatar-box mx-auto mb-3" style="width: 64px; height: 64px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; background-color: var(--primary-light); color: var(--primary); font-size: 1.8rem;">
            <i class="bi bi-shield-lock-fill"></i>
          </div>
          <h3 class="fw-bold mb-1">Citizen Portal Log In</h3>
          <p class="text-muted small">One-time password authentication</p>
        </div>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger alert-dismissible fade show rounded-3 small py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show rounded-3 small py-2 mb-3" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!$is_otp_stage): ?>
          <!-- Stage 1: Registration / Login Screen -->
          <form action="login.php" method="POST" enctype="multipart/form-data" autocomplete="off">
            <div class="mb-3">
              <label for="email" class="form-label fw-bold small text-dark"><i class="bi bi-envelope-fill text-success me-1"></i>Email Address</label>
              <input type="email" name="email" id="email" class="form-control rounded-3" placeholder="name@domain.com" required>
            </div>

            <div class="mb-3">
              <label for="full_name" class="form-label fw-bold small text-dark"><i class="bi bi-person-fill text-success me-1"></i>Full Name (Citizen Name)</label>
              <input type="text" name="full_name" id="full_name" class="form-control rounded-3" placeholder="e.g. Rahul Sharma">
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label for="phone" class="form-label fw-bold small text-dark"><i class="bi bi-telephone-fill text-success me-1"></i>Mobile Number</label>
                <input type="text" name="phone" id="phone" class="form-control rounded-3" placeholder="9876543210">
              </div>
              <div class="col-6">
                <label for="ward_id" class="form-label fw-bold small text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i>Nagpur Ward</label>
                <select name="ward_id" id="ward_id" class="form-select rounded-3 small">
                  <option value="">Select Ward...</option>
                  <?php foreach ($wards as $w): ?>
                    <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mb-4">
              <label for="profile_pic" class="form-label fw-bold small text-dark"><i class="bi bi-camera-fill text-success me-1"></i>Upload Profile Picture</label>
              <input type="file" name="profile_pic" id="profile_pic" class="form-control rounded-3" accept="image/*">
              <span class="small text-muted" style="font-size: 0.75rem;">Optional avatar photo for your Citizen Profile.</span>
            </div>

            <button type="submit" name="send_otp" class="btn btn-success w-100 py-3 mb-3 fw-bold rounded-pill shadow-sm">
              <i class="bi bi-send-fill me-1"></i>Send 6-Digit OTP
            </button>
          </form>
          <div class="text-muted text-center mt-2 small">
            Enter your details to sign in / register. For admin access, use <b>admin@civic.gov</b>.
          </div>
        <?php else: ?>
          <!-- Stage 2: OTP Input Screen -->
          <?php if ($is_localhost && !empty($dev_otp)): ?>
            <div class="alert alert-info py-2 small mb-3 text-start">
              <i class="bi bi-info-circle-fill me-1"></i> Local Dev Mode: OTP is <strong><?php echo htmlspecialchars($dev_otp); ?></strong>
            </div>
          <?php endif; ?>
          <form action="login.php" method="POST" autocomplete="off">
            <div class="mb-4">
              <p class="mb-3 text-secondary text-center small">We sent an OTP to <strong><?php echo htmlspecialchars($_SESSION['temp_email']); ?></strong></p>
              <input type="text" name="otp" id="otp" class="form-control text-center font-monospace fs-3 fw-bold" placeholder="0 0 0 0 0 0" maxlength="6" pattern="\d{6}" required autofocus style="letter-spacing: 0.5rem; padding-left: 1.5rem;">
            </div>
            
            <button type="submit" name="verify_otp" class="btn btn-primary w-100 py-3 mb-3">
              Verify & Log In
            </button>
          </form>
          
          <div class="d-flex justify-content-between align-items-center mt-3 small">
            <!-- Resend OTP Form -->
            <form action="login.php" method="POST" class="d-inline">
              <button type="submit" name="resend_otp" id="resend-btn" class="btn btn-link text-decoration-none p-0 fw-semibold text-success" <?php echo ($cooldown_time > 0) ? 'disabled' : ''; ?>>
                Resend OTP
              </button>
              <span id="cooldown-timer" class="cooldown-text ms-1 text-muted <?php echo ($cooldown_time > 0) ? '' : 'd-none'; ?>">
                (in <span id="seconds"><?php echo $cooldown_time; ?></span>s)
              </span>
            </form>
            
            <!-- Change Email Form -->
            <form action="login.php" method="POST" class="d-inline">
              <button type="submit" name="change_email" class="btn btn-link text-decoration-none text-secondary p-0">
                Change Email
              </button>
            </form>
          </div>
          
          <script>
            document.addEventListener("DOMContentLoaded", function() {
              let cooldown = <?php echo $cooldown_time; ?>;
              if (cooldown > 0) {
                const timerSpan = document.getElementById("seconds");
                const timerContainer = document.getElementById("cooldown-timer");
                const resendBtn = document.getElementById("resend-btn");
                
                const interval = setInterval(function() {
                  cooldown--;
                  timerSpan.textContent = cooldown;
                  if (cooldown <= 0) {
                    clearInterval(interval);
                    timerContainer.classList.add("d-none");
                    resendBtn.removeAttribute("disabled");
                  }
                }, 1000);
              }
            });
          </script>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Bootstrap Bundle with Popper JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

  <script>
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
  </script>

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
    <a href="login.php" class="mobile-nav-item active">
      <i class="bi bi-person-circle"></i>
      <span>Sign In</span>
    </a>
  </nav>
</body>
</html>
