<?php
// mms/header.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';

$user        = currentUser();
$isLoggedIn  = isLoggedIn();
$isSuper     = $isLoggedIn && isSuperAdmin($user);

$pdo = null;
$isCommitteeAdmin = false;

if ($isLoggedIn && !$isSuper) {
    $pdo = getPDO();
    $isCommitteeAdmin = isCommitteeAdmin($pdo, $user);
}

$currentUri = $_SERVER['REQUEST_URI'] ?? '';

/**
 * PHP 7 compatible "contains" helper
 */
if (!function_exists('str_contains_compat')) {
    function str_contains_compat($haystack, $needle)
    {
        if ($needle === '') {
            return false;
        }
        return strpos($haystack, $needle) !== false;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MMS – Meeting Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 + Icons via CDN -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"> -->
          <link rel="stylesheet" href="/mms/assets/css/bootstrap.min.css">
          <link rel="stylesheet" href="/mms/assets/css/bootstrap-icons.css">
          


    <style>
        body {
            background-color: #f5f6fa;
        }
        /* ===== MMS Professional Navbar ===== */

/* ===== MMS Professional Light Navbar ===== */

.mms-navbar-light {
  background-color: #ffc107;
  border-bottom: 1px solid #e5e7eb;
}

.mms-navbar-light .navbar-brand {
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #1f2937 !important;
}

.mms-navbar-light .nav-link {
  color: #374151 !important;
  font-weight: 500;
  transition: color 0.2s ease;
}

.mms-navbar-light .nav-link:hover {
  color: #4e54c8 !important;
}

.mms-navbar-light .nav-link.active {
  color: #4e54c8 !important;
  font-weight: 600;
  position: relative;
}

.mms-navbar-light .nav-link.active::after {
  content: '';
  position: absolute;
  left: 15%;
  bottom: 4px;
  width: 70%;
  height: 2px;
  background: linear-gradient(90deg, #4e54c8, #38ef7d);
  border-radius: 2px;
}

/* Dropdown */
.mms-navbar-light .dropdown-menu {
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 10px 25px rgba(0,0,0,0.08);
}

/* User info */
.mms-navbar-light .navbar-text {
  color: #374151;
}

/* Logout button */
.mms-navbar-light .btn-outline-light {
  color: #374151;
  border-color: #d1d5db;
}

.mms-navbar-light .btn-outline-light:hover {
  background-color: #6a7998ff;
}


        .navbar-brand {
            font-weight: 600;
            letter-spacing: 0.03em;
        }
        .nav-link.active {
            font-weight: 600;
        }
        .mms-page-container {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }
        /* ===============================
   FULL WIDTH PROFESSIONAL LAYOUT
   =============================== */

.page-committee-add {
    padding-left: 0;
    padding-right: 0;
}

/* Control inner width */
.page-committee-add .row {
    margin-left: 0;
    margin-right: 0;
}

/* Center card with max width */
.page-committee-add .col-xl-10 {
    max-width: 1400px;   /* professional dashboard width */
}

/* Card polish */
.page-committee-add .card {
    border-radius: 12px;
}

/* Better spacing on large screens */
@media (min-width: 1400px) {
    .page-committee-add .card-body {
        padding: 2.5rem 3rem;
    }
}

    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg mms-navbar-light shadow-sm">


  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo $isSuper ? '/mms/admin/dashboard.php' : '/mms/committee_admin/dashboard.php'; ?>">
      <i class="bi bi-calendar-check-fill me-1 text-info"></i> MMS

    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#mmsNavbar" aria-controls="mmsNavbar" aria-expanded="false"
            aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mmsNavbar">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <?php if ($isLoggedIn && $isSuper): ?>
          <!-- SUPERADMIN MENU -->
          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/admin/dashboard.php') ? 'active' : ''; ?>"
               href="/mms/admin/dashboard.php">
              <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
          </li>

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?php echo str_contains_compat($currentUri, '/committees/') ? 'active' : ''; ?>"
               href="#" id="navCommittees" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-diagram-3 me-1"></i> Committees
            </a>
            <ul class="dropdown-menu" aria-labelledby="navCommittees">
              <li><a class="dropdown-item" href="/mms/committees/list.php">All Committees</a></li>
              <li><a class="dropdown-item" href="/mms/committees/add.php">Add Committee</a></li>
              <li><a class="dropdown-item" href="/mms/committees/add_admin.php">Assign Committee Heads</a></li>
            </ul>
          </li>

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?php echo str_contains_compat($currentUri, '/meetings/') ? 'active' : ''; ?>"
               href="#" id="navMeetings" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-calendar-event me-1"></i> Meetings
            </a>
            <ul class="dropdown-menu" aria-labelledby="navMeetings">
              <li><a class="dropdown-item" href="/mms/meetings/list.php">All Meetings</a></li>
              <li><a class="dropdown-item" href="/mms/meetings/add.php">Schedule Meeting</a></li>
            </ul>
          </li>

          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?php echo str_contains_compat($currentUri, '/participants/') ? 'active' : ''; ?>"
               href="#" id="navParticipants" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-people me-1"></i> Participants
            </a>
            <ul class="dropdown-menu" aria-labelledby="navParticipants">
              <li><a class="dropdown-item" href="/mms/participants/list.php">All Participants</a></li>
              <li><a class="dropdown-item" href="/mms/participants/add.php">Add Participant</a></li>
            </ul>
          </li>

          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/venues/') ? 'active' : ''; ?>"
               href="/mms/venues/list.php">
              <i class="bi bi-geo-alt me-1"></i> Venues
            </a>
          </li>

        <?php elseif ($isLoggedIn && $isCommitteeAdmin): ?>
          <!-- COMMITTEE ADMIN MENU -->
          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/committee_admin/dashboard.php') ? 'active' : ''; ?>"
               href="/mms/committee_admin/dashboard.php">
              <i class="bi bi-speedometer2 me-1"></i> Dashboard
            </a>
          </li>

          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/meetings/') ? 'active' : ''; ?>"
               href="/mms/meetings/list.php">
              <i class="bi bi-calendar-event me-1"></i> My Meetings
            </a>
          </li>

          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/participants/') ? 'active' : ''; ?>"
               href="/mms/participants/list.php">
              <i class="bi bi-people me-1"></i> My Participants
            </a>
          </li>

          <!-- <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/venues/') ? 'active' : ''; ?>"
               href="/mms/venues/list.php">
              <i class="bi bi-geo-alt me-1"></i> My Venues
            </a>
          </li> -->

        <?php else: ?>
          <!-- GUEST OR BASIC USER -->
          <li class="nav-item">
            <a class="nav-link <?php echo str_contains_compat($currentUri, '/auth/login.php') ? 'active' : ''; ?>"
               href="/mms/auth/login.php">
              <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </a>
          </li>
        <?php endif; ?>

      </ul>

      <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
        <?php if ($isLoggedIn): ?>
          <li class="nav-item me-2">
            <span class="navbar-text text-light small">
              <i class="bi bi-person-circle me-1"></i>
              <?= htmlspecialchars($user['full_name'] ?? 'User') ?>
              <span class="text-muted">
                (<?= htmlspecialchars($user['username'] ?? '') ?>)
              </span>
            </span>
          </li>
          <li class="nav-item">
            <a class="btn btn-sm btn-outline-light" href="/mms/auth/logout.php">
              <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container-fluid mms-page-container">
