<?php 
// mms/committees/view.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo   = getPDO();
$user  = currentUser();
$isSuper = isSuperAdmin($user);

$id = (int)($_GET['id'] ?? 0);

// Load committee
$stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$committee = $stmt->fetch();

if (!$committee) {
    flash_set('error', 'Committee not found');
    header('Location: /mms/committees/list.php');
    exit;
}

// Load members
$membersStmt = $pdo->prepare("
    SELECT cu.*, p.full_name
    FROM committee_users cu
    LEFT JOIN participants p ON p.id = cu.participant_id
    WHERE cu.committee_id = :id
    ORDER BY 
        CASE cu.role_in_committee
            WHEN 'admin' THEN 0
            ELSE 1
        END,
        p.full_name ASC
");
$membersStmt->execute([':id' => $id]);
$members = $membersStmt->fetchAll();

// Load participants list for the add-member form
$participants = $pdo->query("
    SELECT id, full_name
    FROM participants
    ORDER BY full_name
")->fetchAll();

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h2 class="mb-1">
      <i class="bi bi-diagram-3-fill text-primary me-2"></i>
      <?= htmlspecialchars($committee['name']) ?>
    </h2>
    <?php if (!empty($committee['description'])): ?>
      <p class="text-muted mb-0 small">
        <?= nl2br(htmlspecialchars($committee['description'])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="text-end">
    <a class="btn btn-outline-secondary btn-sm mb-2" href="/mms/committees/list.php">
      <i class="bi bi-arrow-left-short me-1"></i> Back to Committees
    </a>
    <div>
      <a class="btn btn-primary btn-sm" href="/mms/meetings/add.php?committee_id=<?= (int)$committee['id'] ?>">
        <i class="bi bi-calendar-plus me-1"></i> Schedule Meeting
      </a>
    </div>
  </div>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Members list -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-people-fill me-1 text-primary"></i>
          Committee Members
        </h5>
        <span class="badge bg-light text-muted">
          <?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if ($members): ?>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead class="small text-muted">
                <tr>
                  <th style="width: 40px;">#</th>
                  <th>Name</th>
                  <th style="width: 120px;">Role</th>
                </tr>
              </thead>
              <tbody>
                <?php $i = 1; foreach ($members as $m): ?>
                  <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($m['full_name'] ?? 'Unknown') ?></td>
                    <td>
                      <?php
                        $role = $m['role_in_committee'] ?: 'member';
                        $label = ucfirst($role);
                        $badgeClass = $role === 'admin' ? 'bg-danger' : 'bg-secondary';
                      ?>
                      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="p-3">
            <p class="text-muted mb-0">No members added to this committee yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Add member form -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0">
        <h5 class="mb-0">
          <i class="bi bi-person-plus-fill me-1 text-success"></i>
          Add Member
        </h5>
      </div>
      <div class="card-body">
        <form method="post" action="/mms/committees/add_admin.php" class="row g-2">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="committee_id" value="<?= (int)$committee['id'] ?>">

          <div class="col-12">
            <label class="form-label small text-muted mb-1">Select Participant</label>
            <select name="participant_id" class="form-select form-select-sm" required>
              <option value="">-- Select --</option>
              <?php foreach ($participants as $p): ?>
                <option value="<?= (int)$p['id'] ?>">
                  <?= htmlspecialchars($p['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($isSuper): ?>
            <!-- Only superadmin can assign admin role -->
            <div class="col-12">
              <label class="form-label small text-muted mb-1">Role in Committee</label>
              <select name="role_in_committee" class="form-select form-select-sm">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
              </select>
              <div class="form-text small">
                Only superadmin can assign the <strong>Admin</strong> role.
              </div>
            </div>
          <?php else: ?>
            <!-- Committee admins: force role to member, no dropdown -->
            <input type="hidden" name="role_in_committee" value="member">
            <div class="col-12">
              <div class="alert alert-info py-2 small mb-2">
                As a committee admin, you can add <strong>members</strong> only.
                Admin privileges are managed by the system superadmin.
              </div>
            </div>
          <?php endif; ?>

          <div class="col-12 mt-1">
            <button class="btn btn-success btn-sm">
              <i class="bi bi-plus-lg me-1"></i> Add Member
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
