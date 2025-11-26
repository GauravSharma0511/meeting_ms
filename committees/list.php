<?php
// mms/committees/list.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo = getPDO();

$user  = $_SESSION['user'] ?? null;
$role  = $user['role'] ?? '';
$isSuper = ($role === 'superuser');

// Fetch committees with count of members & meetings
$sql = "
    SELECT c.id, c.name, c.description, c.created_at,
           u.username AS created_by,
           COUNT(DISTINCT cu.id) AS member_count,
           COUNT(DISTINCT m.id)  AS meeting_count
    FROM committees c
    LEFT JOIN users u ON c.created_by_user_id = u.id
    LEFT JOIN committee_users cu ON cu.committee_id = c.id
    LEFT JOIN meetings m ON m.committee_id = c.id
    GROUP BY c.id, u.username
    ORDER BY c.created_at DESC
";
$committees = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Committees</h3>
  <?php if ($isSuper): ?>
    <a href="add.php" class="btn btn-primary">
      <i class="bi bi-plus-circle"></i> Add Committee
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
            <th>Name</th>
            <th>Members</th>
            <th>Meetings</th>
            <th>Created By</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$committees): ?>
          <tr><td colspan="7" class="text-center text-muted">No committees yet.</td></tr>
        <?php else: ?>
          <?php foreach ($committees as $c): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><?= htmlspecialchars($c['name']) ?></td>
              <td><?= (int)$c['member_count'] ?></td>
              <td><?= (int)$c['meeting_count'] ?></td>
              <td><?= htmlspecialchars($c['created_by'] ?? '-') ?></td>
              <td><?= htmlspecialchars($c['created_at']) ?></td>
              <td>
                <a href="view.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary">
                  View
                </a>
                <a href="add_admin.php?committee_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary">
                  Members
                </a>
                <a href="../meetings/add.php?committee_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-success">
                  Schedule Meeting
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
