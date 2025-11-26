<?php
// mms/auth/login.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

if (isLoggedIn()) {
    $user = currentUser();
    $role = $user['role'] ?? 'user';

    if ($role === 'superuser') {
        header('Location: /mms/admin/dashboard.php');
    } else {
        header('Location: /mms/committee_admin/dashboard.php');
    }
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        $user = currentUser();
        $role = $user['role'] ?? 'user';

        flash_set('success', 'Logged in successfully.');

        if ($role === 'superuser') {
            header('Location: /mms/admin/dashboard.php');
        } else {
            header('Location: /mms/committee_admin/dashboard.php');
        }
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}

// include header AFTER all redirects / header() calls
include __DIR__ . '/../header.php';
?>

<div class="row justify-content-center mt-5">
  <div class="col-md-5">
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <h3 class="mb-3 text-center">Sign in</h3>
        <p class="text-muted small text-center mb-4">
          Use your MMS username and password.
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/mms/auth/login.php">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input name="username" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Password (blank if not set)</label>
            <input name="password" type="password" class="form-control">
          </div>
          <button class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i> Login
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
