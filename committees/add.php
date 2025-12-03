<?php
// mms/committees/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();
$user  = $_SESSION['user'] ?? null;
$role  = $user['role'] ?? '';
$isSuper = ($role === 'superuser');

if (!$isSuper) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$pdo = getPDO();

$name = '';
$description = '';
$errors = [];

// Load data for dropdowns
$allParticipants = [];
$adminCandidates = [];

try {
    $allParticipants = $pdo->query("
        SELECT id, full_name
        FROM participants
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allParticipants = [];
}

try {
    $adminCandidates = $pdo->query("
        SELECT id, username AS name, email
        FROM users
        WHERE role <> 'superuser'
        ORDER BY username ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $adminCandidates = [];
}

// --------------------- POST: CREATE COMMITTEE ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (if helper exists)
    if (function_exists('csrf_token_is_valid')) {
        if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
            $errors[] = 'Security token expired. Please reload the page and try again.';
        }
    }

    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $memberIds   = array_map('intval', $_POST['member_ids'] ?? []); // participants
    $adminUserId = (int)($_POST['admin_user_id'] ?? 0);             // users.id (nodal officer)

    if ($name === '') {
        $errors[] = 'Committee name is required.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Insert committee
            $sql = "INSERT INTO committees (name, description, created_by_user_id)
                    VALUES (:name, :description, :created_by)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':name'        => $name,
                ':description' => $description ?: null,
                ':created_by'  => $user['id'] ?? null,
            ]);

            // Get new committee ID (Postgres style; MySQL ignores the argument)
            $committeeId = (int)$pdo->lastInsertId('committees_id_seq');

            // Insert initial members (participants) as 'member'
            if (!empty($memberIds)) {
                $memStmt = $pdo->prepare("
                    INSERT INTO committee_users (committee_id, participant_id, role_in_committee)
                    VALUES (:cid, :pid, 'member')
                ");
                foreach ($memberIds as $pid) {
                    if ($pid <= 0) continue;
                    $memStmt->execute([
                        ':cid' => $committeeId,
                        ':pid' => $pid,
                    ]);
                }
            }

            // Assign initial committee admin / nodal officer
            if ($adminUserId > 0) {
                // Avoid duplicate admin entries
                $chk = $pdo->prepare("
                    SELECT id FROM committee_admins
                    WHERE committee_id = :cid AND user_id = :uid
                    LIMIT 1
                ");
                $chk->execute([
                    ':cid' => $committeeId,
                    ':uid' => $adminUserId,
                ]);

                if (!$chk->fetch()) {
                    $admStmt = $pdo->prepare("
                        INSERT INTO committee_admins (committee_id, user_id, assigned_at)
                        VALUES (:cid, :uid, NOW())
                    ");
                    $admStmt->execute([
                        ':cid' => $committeeId,
                        ':uid' => $adminUserId,
                    ]);
                }

                // OPTIONAL: if your users table has participant_id and you want
                // the nodal officer also to be added as a member automatically:
                /*
                $uStmt = $pdo->prepare("SELECT participant_id FROM users WHERE id = :id");
                $uStmt->execute([':id' => $adminUserId]);
                $uRow = $uStmt->fetch();
                if (!empty($uRow['participant_id'])) {
                    $pid = (int)$uRow['participant_id'];
                    if ($pid > 0) {
                        $memStmt->execute([':cid' => $committeeId, ':pid' => $pid]);
                    }
                }
                */
            }

            $pdo->commit();
            flash_set('success', 'Committee created successfully.');
            header('Location: list.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error creating committee: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">
    <i class="bi bi-diagram-3-fill text-primary me-1"></i>
    Add Committee
  </h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left-short"></i> Back to list
  </a>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" class="card shadow-sm border-0">
  <div class="card-body">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

    <div class="row g-4">
      <!-- Left: Basic details -->
      <div class="col-lg-6">
        <div class="mb-3">
          <label class="form-label">Committee Name <span class="text-danger">*</span></label>
          <input type="text"
                 name="name"
                 class="form-control"
                 value="<?= htmlspecialchars($name) ?>"
                 required
                 placeholder="e.g. Finance Review Committee">
        </div>

        <div class="mb-3">
          <label class="form-label">Description</label>
          <textarea name="description"
                    class="form-control"
                    rows="4"
                    placeholder="Short description of the committee’s purpose"><?= htmlspecialchars($description) ?></textarea>
        </div>
      </div>

      <!-- Right: Members + Admin -->
      <div class="col-lg-6">
        <!-- Members: searchable, add one-by-one -->
        <div class="mb-3">
          <label class="form-label">
            Add Members (Participants)
          </label>

          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text">Search</span>
            <input type="text"
                   class="form-control"
                   id="memberSearchInput"
                   placeholder="Type to filter participants">
          </div>

          <div class="input-group input-group-sm mb-2">
            <select id="memberSelect" class="form-select">
              <option value="">-- Select participant --</option>
              <?php foreach ($allParticipants as $p): ?>
                <option value="<?= (int)$p['id'] ?>">
                  <?= htmlspecialchars($p['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-primary" id="addMemberBtn">
              Add
            </button>
          </div>

          <div id="selectedMembers" class="small">
            <!-- Selected members badges + hidden inputs will appear here via JS -->
            <?php
            // If the form was posted back with errors, repopulate selected members
            if (!empty($_POST['member_ids']) && is_array($_POST['member_ids'])) {
                // Build map id => name to show labels properly
                $map = [];
                foreach ($allParticipants as $p) {
                    $map[$p['id']] = $p['full_name'];
                }
                $postedMembers = array_map('intval', $_POST['member_ids']);
                $postedMembers = array_unique($postedMembers);
                foreach ($postedMembers as $pid) {
                    if ($pid <= 0 || !isset($map[$pid])) continue;
                    $label = htmlspecialchars($map[$pid]);
                    ?>
                    <div class="badge bg-light text-dark border me-1 mb-1" data-member-id="<?= (int)$pid ?>" style="cursor:default;">
                      <span><?= $label ?> </span>
                      <button type="button"
                              class="btn-close btn-close-sm ms-1"
                              aria-label="Remove"
                              onclick="this.closest('.badge').remove();">
                      </button>
                      <input type="hidden" name="member_ids[]" value="<?= (int)$pid ?>">
                    </div>
                    <?php
                }
            }
            ?>
          </div>

          <div class="form-text small">
            You can add multiple members one by one. These will be saved as committee members.
          </div>
        </div>

        <!-- Admin / Nodal Officer: searchable -->
        <div class="mb-3">
          <label class="form-label">
            Committee Admin / Nodal Officer (User)
          </label>

          <div class="input-group input-group-sm mb-2">
            <span class="input-group-text">Search</span>
            <input type="text"
                   class="form-control"
                   id="adminSearchInput"
                   placeholder="Type to filter users">
          </div>

          <select name="admin_user_id" id="adminSelect" class="form-select form-select-sm">
            <option value="">-- Select user (optional) --</option>
            <?php
            $postedAdmin = isset($_POST['admin_user_id']) ? (int)$_POST['admin_user_id'] : 0;
            foreach ($adminCandidates as $u):
              $uid = (int)$u['id'];
              $selected = $uid === $postedAdmin ? 'selected' : '';
            ?>
              <option value="<?= $uid ?>" <?= $selected ?>>
                <?= htmlspecialchars($u['name']) ?>
                <?php if (!empty($u['email'])): ?>
                  (<?= htmlspecialchars($u['email']) ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="form-text small">
            This user will be the committee head and can manage meetings and members for this committee.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card-footer bg-white border-0 d-flex justify-content-end">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check2-circle me-1"></i> Save Committee
    </button>
  </div>
</form>

<!-- JS: Member search/add + Admin search filter -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // ---- Member search + add one-by-one ----
  const memberSearchInput = document.getElementById('memberSearchInput');
  const memberSelect      = document.getElementById('memberSelect');
  const addMemberBtn      = document.getElementById('addMemberBtn');
  const selectedMembers   = document.getElementById('selectedMembers');

  if (memberSearchInput && memberSelect && addMemberBtn && selectedMembers) {
    // Filter participants in dropdown as user types
    memberSearchInput.addEventListener('input', function () {
      const query = this.value.toLowerCase();
      Array.from(memberSelect.options).forEach(function (opt, idx) {
        if (idx === 0) return; // skip placeholder
        const text = opt.textContent.toLowerCase();
        opt.hidden = query && !text.includes(query);
      });
    });

    // Add selected participant to "Selected Members" list
    addMemberBtn.addEventListener('click', function () {
      const selectedOption = memberSelect.options[memberSelect.selectedIndex];
      if (!selectedOption || !selectedOption.value) return;

      const pid   = selectedOption.value;
      const pname = selectedOption.textContent.trim();

      // Avoid duplicates
      if (selectedMembers.querySelector('[data-member-id="' + pid + '"]')) {
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'badge bg-light text-dark border me-1 mb-1';
      wrapper.dataset.memberId = pid;
      wrapper.style.cursor = 'default';

      const labelSpan = document.createElement('span');
      labelSpan.textContent = pname + ' ';

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn-close btn-close-sm ms-1';
      removeBtn.setAttribute('aria-label', 'Remove');
      removeBtn.style.fontSize = '0.6rem';

      removeBtn.addEventListener('click', function () {
        wrapper.remove();
      });

      const hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'member_ids[]';
      hiddenInput.value = pid;

      wrapper.appendChild(labelSpan);
      wrapper.appendChild(removeBtn);
      wrapper.appendChild(hiddenInput);
      selectedMembers.appendChild(wrapper);
    });
  }

  // ---- Admin search (filter dropdown) ----
  const adminSearchInput = document.getElementById('adminSearchInput');
  const adminSelect      = document.getElementById('adminSelect');

  if (adminSearchInput && adminSelect) {
    adminSearchInput.addEventListener('input', function () {
      const query = adminSearchInput.value.trim();

      // Do nothing for very short queries
      if (query.length < 2) {
        return;
      }

      // Adjust the URL if your searchUsers.php is in a different folder
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
        console.log(response)
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
        defaultOpt.textContent = '-- Select user (optional) --';
        adminSelect.appendChild(defaultOpt);

        if (data.length === 0) {
          const noOpt = document.createElement('option');
          noOpt.disabled = true;
          noOpt.textContent = 'No users found';
          adminSelect.appendChild(noOpt);
          return;
        }

        data.forEach(function (user) {
          // Make sure your API returns: id, rjcode (optional), display_name, email
          const opt = document.createElement('option');
          // opt.value = user.id;
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

          opt.textContent = label || ('ID ' + user.id);
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
