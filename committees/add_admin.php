<?php
// mms/committees/add_admin.php
// Superadmin page to assign/remove committee admins (heads)

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();
$pdo  = getPDO();
$user = currentUser();

// Only superadmin can use this page
if (!isSuperAdmin($user)) {
    http_response_code(403);
    echo "Forbidden – only superadmin can manage committee heads.";
    exit;
}

// Helper: CSRF validation if available
function check_csrf_or_fail()
{
    if (function_exists('csrf_token_is_valid')) {
        $token = $_POST['csrf'] ?? '';
        if (!csrf_token_is_valid($token)) {
            flash_set('error', 'Security token expired. Please try again.');
            header('Location: /mms/committees/add_admin.php');
            exit;
        }
    }
}

// Determine selected committee (for dropdown + lists)
$committeeId = (int)($_GET['committee_id'] ?? $_POST['committee_id'] ?? 0);

// Handle actions (add/demote)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_csrf_or_fail();

    $action = $_POST['action'] ?? '';

    // Normalize committee id from POST
    $committeeId = (int)($_POST['committee_id'] ?? 0);
    if ($committeeId <= 0) {
        flash_set('error', 'Invalid committee selected.');
        header('Location: /mms/committees/add_admin.php');
        exit;
    }

    // Make sure committee exists
    $stmt = $pdo->prepare("SELECT id, name FROM committees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $committeeId]);
    $committee = $stmt->fetch();

    if (!$committee) {
        flash_set('error', 'Committee not found.');
        header('Location: /mms/committees/add_admin.php');
        exit;
    }

    if ($action === 'add_admin') {
        // Assign a new admin (head) to this committee

        // From the search-based select: user_id actually contains RJ code / username
        $rjcode = trim($_POST['user_id'] ?? '');
        if ($rjcode === '') {
            flash_set('error', 'Please search and select a user to assign as admin.');
            header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
            exit;
        }

        try {
            // First try to find existing MMS user with this username (RJ code)
            $uStmt = $pdo->prepare("
                SELECT id, username AS name, email, role
                FROM users 
                WHERE username = :uname
                  AND role <> 'superuser'
                LIMIT 1
            ");
            $uStmt->execute([':uname' => $rjcode]);
            $targetUser = $uStmt->fetch();

            if (!$targetUser) {
                // Auto-create MMS user for this RJ code
                $pdo->beginTransaction();

                // Minimal user record; role 'admin' (or rely on default if you prefer)
                $insertUser = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, participant_id)
                    VALUES (:uname, NULL, NULL, 'admin', NULL)
                ");
                $insertUser->execute([':uname' => $rjcode]);

                // Postgres style: specify users_id_seq (created by SERIAL)
                $newUserId = (int)$pdo->lastInsertId('users_id_seq');

                $targetUser = [
                    'id'    => $newUserId,
                    'name'  => $rjcode,
                    'email' => null,
                    'role'  => 'admin',
                ];

                $pdo->commit();
            }

            $userId = (int)$targetUser['id'];

            // Check if already admin of this committee
            $checkStmt = $pdo->prepare("
                SELECT id 
                FROM committee_admins
                WHERE committee_id = :cid AND user_id = :uid
                LIMIT 1
            ");
            $checkStmt->execute([
                ':cid' => $committeeId,
                ':uid' => $userId,
            ]);

            if ($checkStmt->fetch()) {
                flash_set('error', 'This user is already an admin for this committee.');
                header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
                exit;
            }

            // Insert into committee_admins with REAL users.id
            $insertStmt = $pdo->prepare("
                INSERT INTO committee_admins (committee_id, user_id, assigned_at)
                VALUES (:cid, :uid, NOW())
            ");
            $ok = $insertStmt->execute([
                ':cid' => $committeeId,
                ':uid' => $userId,
            ]);

            if ($ok) {
                flash_set('success', 'Admin assigned successfully: ' . ($targetUser['name'] ?? ''));
            } else {
                flash_set('error', 'Failed to assign admin. Please try again.');
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Error while assigning admin: ' . $e->getMessage());
        }

        header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
        exit;
    }

    if ($action === 'remove_admin') {
        // Remove an existing admin mapping row from committee_admins

        $adminRowId = (int)($_POST['admin_row_id'] ?? 0);
        if ($adminRowId <= 0) {
            flash_set('error', 'Invalid admin selection.');
            header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
            exit;
        }

        // Optional: verify this row belongs to this committee
        $cStmt = $pdo->prepare("
            SELECT ca.id, ca.user_id, u.username AS name
            FROM committee_admins ca
            JOIN users u ON u.id = ca.user_id
            WHERE ca.id = :id AND ca.committee_id = :cid
            LIMIT 1
        ");
        $cStmt->execute([
            ':id'  => $adminRowId,
            ':cid' => $committeeId,
        ]);
        $row = $cStmt->fetch();

        if (!$row) {
            flash_set('error', 'Admin record not found.');
            header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
            exit;
        }

        $delStmt = $pdo->prepare("DELETE FROM committee_admins WHERE id = :id");
        $ok = $delStmt->execute([':id' => $adminRowId]);

        if ($ok) {
            flash_set('success', 'Admin removed: ' . ($row['name'] ?? ''));
        } else {
            flash_set('error', 'Failed to remove admin. Please try again.');
        }

        header('Location: /mms/committees/add_admin.php?committee_id=' . $committeeId);
        exit;
    }

    // Fallback for unknown action
    flash_set('error', 'Unknown action.');
    header('Location: /mms/committees/add_admin.php');
    exit;
}

// --------- GET request: show UI ----------

// Load all committees for dropdown
$allCommittees = $pdo->query("
    SELECT id, name 
    FROM committees 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// If a committee is selected, load its current admins
$currentAdmins = [];
if ($committeeId > 0) {
    $adminsStmt = $pdo->prepare("
        SELECT 
            ca.id AS admin_row_id,
            u.id AS user_id,
            u.username AS name,
            u.email,
            u.role,
            ca.assigned_at
        FROM committee_admins ca
        JOIN users u ON u.id = ca.user_id
        WHERE ca.committee_id = :cid
        ORDER BY u.username ASC
    ");
    $adminsStmt->execute([':cid' => $committeeId]);
    $currentAdmins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h2 class="mb-0">
    <i class="bi bi-person-gear me-2 text-primary"></i>
    Manage Committee Heads (Admins)
  </h2>
  <a href="/mms/committees/list.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left-short me-1"></i> Back to Committees
  </a>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label small text-muted mb-1">Select Committee</label>
        <select name="committee_id" class="form-select form-select-sm" required>
          <option value="">-- Choose committee --</option>
          <?php foreach ($allCommittees as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $committeeId === (int)$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100">
          <i class="bi bi-search me-1"></i> Load
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($committeeId > 0): ?>
  <div class="row g-3">
    <!-- Current admins -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="bi bi-person-badge-fill me-1 text-primary"></i>
            Current Committee Heads
          </h5>
          <span class="badge bg-light text-muted small">
            <?= count($currentAdmins) ?> admin<?= count($currentAdmins) === 1 ? '' : 's' ?>
          </span>
        </div>
        <div class="card-body p-0">
          <?php if (!$currentAdmins): ?>
            <div class="p-3">
              <p class="text-muted mb-0 small">
                No admins assigned to this committee yet.
              </p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="small text-muted">
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th style="width: 80px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($currentAdmins as $a): ?>
                    <tr>
                      <td><?= htmlspecialchars($a['name']) ?></td>
                      <td class="small text-muted"><?= htmlspecialchars($a['email'] ?? '') ?></td>
                      <td class="text-end">
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                          <input type="hidden" name="committee_id" value="<?= (int)$committeeId ?>">
                          <input type="hidden" name="action" value="remove_admin">
                          <input type="hidden" name="admin_row_id" value="<?= (int)$a['admin_row_id'] ?>">
                          <button class="btn btn-outline-danger btn-sm"
                                  onclick="return confirm('Remove this admin from the committee?');">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Add new admin -->
    <div class="col-lg-6">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-header bg-white border-0">
          <h5 class="mb-0">
            <i class="bi bi-person-plus-fill me-1 text-success"></i>
            Assign New Head
          </h5>
        </div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="committee_id" value="<?= (int)$committeeId ?>">
            <input type="hidden" name="action" value="add_admin">

            <div class="col-12">
              <label class="form-label small text-muted mb-1">Search User (RJ code / Name)</label>
              <div class="input-group input-group-sm mb-2">
                <span class="input-group-text">Search</span>
                <input type="text"
                       id="adminSearchInput"
                       class="form-control"
                       placeholder="Type to search users">
              </div>
            </div>

            <div class="col-12">
              <label class="form-label small text-muted mb-1">Select User</label>
              <select name="user_id" id="adminSelect" class="form-select form-select-sm" required>
                <option value="">-- Select user (search above) --</option>
              </select>
            </div>

            <div class="col-12 mt-1">
              <button class="btn btn-success btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Assign as Head
              </button>
            </div>
          </form>
          <p class="small text-muted mt-2 mb-0">
            Users are searched from SSO (rjcode / name) and auto-registered in MMS if not present.
          </p>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
// Search users via API and populate adminSelect
document.addEventListener('DOMContentLoaded', function () {
  const adminSearchInput = document.getElementById('adminSearchInput');
  const adminSelect      = document.getElementById('adminSelect');

  if (adminSearchInput && adminSelect) {
    adminSearchInput.addEventListener('input', function () {
      const query = adminSearchInput.value.trim();

      // Do nothing for very short queries
      if (query.length < 2) {
        return;
      }

      // searchUsers.php is in /mms/admin/, this file is in /mms/committees/
      const url = '../admin/searchUsers.php?q=' + encodeURIComponent(query);

      fetch(url, {
        method: 'GET',
        headers: {
          'Accept': 'application/json'
        }
      })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        if (!Array.isArray(data)) {
          console.error('Response is not an array:', data);
          return;
        }

        // Clear existing options and add default one
        adminSelect.innerHTML = '';
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = '-- Select user (search above) --';
        adminSelect.appendChild(defaultOpt);

        if (data.length === 0) {
          const noOpt = document.createElement('option');
          noOpt.disabled = true;
          noOpt.textContent = 'No users found';
          adminSelect.appendChild(noOpt);
          return;
        }

        data.forEach(function (user) {
          // Here API returns rjcode + display_name (+ maybe email)
          const opt = document.createElement('option');
          // Value is RJ code (username in MMS)
          opt.value = user.rjcode;

          const labelParts = [];
          if (user.rjcode) {
            labelParts.push(user.rjcode);
          }
          if (user.display_name) {
            labelParts.push(user.display_name);
          }
          let label = labelParts.join(' - ');
          if (user.email) {
            label += ' (' + user.email + ')';
          }

          opt.textContent = label || (user.rjcode || 'Unknown user');
          adminSelect.appendChild(opt);
        });
      })
      .catch(function (error) {
        console.error('Admin search fetch error:', error);
      });
    });
  }
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
