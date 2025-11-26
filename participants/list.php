<?php
// mms/participants/list.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo = getPDO();

// Simple role flag (optional: restrict Add to superuser)
$user  = $_SESSION['user'] ?? null;
$role  = $user['role'] ?? '';
$isSuper = ($role === 'superuser');

// Fetch participants with designation
$sql = "
    SELECT p.id, p.full_name, p.email, p.phone,
           d.title AS designation
    FROM participants p
    LEFT JOIN designations d ON p.designation_id = d.id
    ORDER BY p.full_name ASC
";
$stmt = $pdo->query($sql);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Participants</h3>
  <?php if ($isSuper): ?>
    <a href="add.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> Add Participant
    </a>
  <?php endif; ?>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Designation</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$participants): ?>
          <tr><td colspan="5" class="text-center text-muted">No participants yet.</td></tr>
        <?php else: ?>
          <?php foreach ($participants as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= htmlspecialchars($row['full_name']) ?></td>
              <td><?= htmlspecialchars($row['email'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['phone'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['designation'] ?? '-') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
