<?php
// navbar.php - Reusable & Ultra-Responsive Top Navigation Bar Component
if (!isset($is_logged_in)) {
    $is_logged_in = function_exists('is_logged_in') ? is_logged_in() : false;
}
if (!isset($user) && $is_logged_in && isset($conn)) {
    $user = get_logged_in_user($conn);
}

$nav_user_name = 'Citizen';
if ($is_logged_in && !empty($user)) {
    $nav_user_name = !empty($user['full_name']) ? $user['full_name'] : explode('@', $user['email'])[0];
}

$nav_active_ward_name = $active_ward_name ?? '';
?>

<!-- Top Navigation Bar (Shorter & 100% Mobile Responsive) -->
<nav class="navbar navbar-expand-lg sticky-top bg-white border-bottom shadow-sm py-1.5" style="min-height: 56px;">
  <div class="container-fluid px-2 px-md-4 d-flex align-items-center justify-content-between flex-nowrap">
    
    <!-- Left: Brand Logo Icon -->
    <a class="navbar-brand d-flex align-items-center me-1 py-0" href="index.php" style="flex-shrink: 0;" title="NMC Smart Portal Home">
      <div class="bg-success text-white rounded-3 p-1 d-flex align-items-center justify-content-center shadow-sm" style="width: 34px; height: 34px;">
        <i class="bi bi-building-fill-gear fs-6"></i>
      </div>
    </a>

    <!-- Ward Switcher Badge (Compact, Truncated on Mobile) -->
    <button type="button" class="btn btn-light btn-sm rounded-pill border shadow-sm d-inline-flex align-items-center gap-1 px-2.5 py-1 font-monospace text-dark mx-1" data-bs-toggle="modal" data-bs-target="#wardSelectModal" title="Change Ward Zone" style="flex-shrink: 1; min-width: 0;">
      <i class="bi bi-geo-alt-fill text-danger small"></i>
      <span class="small fw-bold text-truncate" style="max-width: 90px; font-size: 0.78rem;"><?php echo !empty($nav_active_ward_name) ? htmlspecialchars($nav_active_ward_name) : 'Select Ward'; ?></span>
      <i class="bi bi-chevron-down opacity-50 small" style="font-size: 0.65rem;"></i>
    </button>

    <!-- Right Controls Group (Compact Flex) -->
    <div class="d-flex align-items-center gap-1.5 ms-auto" style="flex-shrink: 0;">
      
      <!-- Navigation Links (Desktop Only) -->
      <div class="d-none d-xl-flex align-items-center gap-1.5 me-1">
        <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 font-monospace fw-bold small">
          <i class="bi bi-house-door-fill me-1"></i>Home 🏠
        </a>
        <a href="polls.php" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 font-monospace fw-bold small">
          <i class="bi bi-check2-square me-1"></i>Polls 🗳️
        </a>
        <a href="potholes.php" class="btn btn-sm btn-outline-info rounded-pill px-3 py-1 font-monospace fw-bold small">
          <i class="bi bi-tools me-1"></i>Potholes 🛠️
        </a>
        <a href="redeem.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 font-monospace fw-bold small">
          <i class="bi bi-gift-fill me-1"></i>Rewards 🎁
        </a>
      </div>

      <!-- Language Switcher -->
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm rounded-pill px-2 py-1 font-monospace dropdown-toggle d-flex align-items-center gap-1" type="button" id="langDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="font-size: 0.78rem;">
          <i class="bi bi-translate"></i> <span id="current-lang-label">EN</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4" aria-labelledby="langDropdown">
          <li><a class="dropdown-item lang-select-btn fw-semibold text-primary small py-1.5" href="#" data-lang="en">English (EN)</a></li>
          <li><a class="dropdown-item lang-select-btn small py-1.5" href="#" data-lang="hi">हिंदी (HI)</a></li>
          <li><a class="dropdown-item lang-select-btn small py-1.5" href="#" data-lang="mr">मराठी (MR)</a></li>
        </ul>
      </div>

      <!-- Dark/Light Theme Toggle Button -->
      <button id="theme-toggle" class="btn btn-outline-secondary btn-sm rounded-circle p-1.5 d-flex align-items-center justify-content-center" type="button" aria-label="Toggle Light/Dark Theme" style="width: 32px; height: 32px;">
        <i class="bi bi-sun-fill d-none-theme-light text-warning small"></i>
        <i class="bi bi-moon-stars-fill d-none-theme-dark text-primary small"></i>
      </button>

      <!-- Profile Dropdown (Shows Name + Profile Icon, Compact) -->
      <?php if ($is_logged_in): ?>
        <div class="dropdown">
          <button class="btn btn-outline-success btn-sm rounded-pill px-2 px-md-3 py-1 dropdown-toggle d-flex align-items-center gap-1 font-monospace fw-bold" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <?php if (!empty($user['profile_pic'])): ?>
              <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Avatar" class="rounded-circle" style="width: 22px; height: 22px; object-fit: cover;">
            <?php else: ?>
              <i class="bi bi-person-circle fs-6"></i>
            <?php endif; ?>
            <span class="d-none d-md-inline text-truncate" style="max-width: 100px; font-size: 0.82rem;"><?php echo htmlspecialchars($nav_user_name); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" aria-labelledby="profileDropdown" style="min-width: 210px;">
            <li class="px-3 py-2 border-bottom mb-1">
              <div class="fw-bold text-dark text-truncate small"><i class="bi bi-person-fill text-success me-1.5"></i><?php echo htmlspecialchars($nav_user_name); ?></div>
            </li>
            <li><a class="dropdown-item rounded-3 py-1.5 small" href="profile.php"><i class="bi bi-person-badge-fill text-success me-2"></i>My Citizen Profile</a></li>
            <li><a class="dropdown-item rounded-3 py-1.5 small" href="potholes.php"><i class="bi bi-tools text-info me-2"></i>Pothole Damage Portal</a></li>
            <li><a class="dropdown-item rounded-3 py-1.5 small" href="dashboard.php"><i class="bi bi-grid-1x2-fill text-primary me-2"></i>Civic Complaints Hub</a></li>
            <li><a class="dropdown-item rounded-3 py-1.5 small" href="water.php"><i class="bi bi-droplet-fill text-info me-2"></i>Water Supply Grid</a></li>
            <li><a class="dropdown-item rounded-3 py-1.5 small" href="polls.php"><i class="bi bi-check2-square text-warning me-2"></i>Community Polls</a></li>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item rounded-3 py-1.5 text-danger fw-bold small" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out Account</a></li>
          </ul>
        </div>
      <?php else: ?>
        <a href="login.php" class="btn btn-success btn-sm rounded-pill px-2.5 py-1 font-monospace fw-bold shadow-sm small">
          <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
        </a>
      <?php endif; ?>
    </div>
  </div>
</nav>
