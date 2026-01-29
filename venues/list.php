<?php
// mms/venues/list.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = $_SESSION['user'] ?? null;

// Fetch all venues
$stmt = $pdo->query("
    SELECT id, name
    FROM venues
    ORDER BY name ASC
");
$venues = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Optional flash messages (success / error)
$successMsg = flash_get('success');
$errorMsg   = flash_get('error');

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Venues</h3>
  <a href="add.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> Add Venue
  </a>
</div>

<?php if (!empty($successMsg)): ?>
  <div class="alert alert-success">
    <?= htmlspecialchars($successMsg) ?>
  </div>
<?php endif; ?>

<?php if (!empty($errorMsg)): ?>
  <div class="alert alert-danger">
    <?= htmlspecialchars($errorMsg) ?>
  </div>
<?php endif; ?>

<?php if ($venues): ?>
  <div class="card">
    <div class="card-body p-0">
      <table class="table table-striped table-hover mb-0">
        <thead>
          <tr>
            <th style="width: 70px;">ID</th>
            <th>Name</th>
            <th style="width: 160px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($venues as $v): ?>
            <tr>
              <td><?= (int)$v['id'] ?></td>
              <td><?= htmlspecialchars($v['name']) ?></td>
              <td>
                <a href="edit.php?id=<?= (int)$v['id'] ?>" class="btn btn-sm btn-outline-secondary">
                  Edit
                </a>
                <a href="delete.php?id=<?= (int)$v['id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Are you sure you want to delete this venue?');">
                  Delete
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <p class="text-muted mb-0">No venues have been added yet.</p>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
