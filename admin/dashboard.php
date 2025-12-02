<?php
// mms/admin/dashboard.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$user = currentUser();
$pdo  = getPDO();
$userId = (int)($user['id'] ?? 0);

// Superadmin only
if (!isSuperAdmin($user)) {
    http_response_code(403);
    echo "Forbidden – only superadmin can access the admin dashboard.";
    exit;
}

// =========================================================
//  QUICK CREATE HANDLERS (modals POST back here)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -------------- QUICK ADD COMMITTEE (WITH MEMBERS + ADMIN) --------------
    if (isset($_POST['quick_add_committee'])) {

        // Optional CSRF check if you have helpers
        if (function_exists('csrf_token_is_valid')) {
            if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
                flash_set('error', 'Security token expired. Please try again.');
                header('Location: /mms/admin/dashboard.php');
                exit;
            }
        }

        $name        = trim($_POST['committee_name'] ?? '');
        $desc        = trim($_POST['committee_description'] ?? '');
        $memberIds   = array_map('intval', $_POST['member_ids'] ?? []); // participants
        $adminUserId = (int)($_POST['admin_user_id'] ?? 0);             // users.id (nodal officer)

        if ($name === '') {
            flash_set('error', 'Committee name is required.');
            header('Location: /mms/admin/dashboard.php');
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Create committee
            $stmt = $pdo->prepare("
                INSERT INTO committees (name, description, created_by_user_id)
                VALUES (:name, :description, :created_by)
            ");
            $stmt->execute([
                ':name'        => $name,
                ':description' => $desc !== '' ? $desc : null,
                ':created_by'  => $userId ?: null,
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

                // Avoid duplicate admin entries (safe even if fresh committee)
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

                // OPTIONAL: auto-add admin as member if linked via participant_id
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
            flash_set('success', 'Committee created successfully with initial members and nodal officer.');

        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('error', 'Error creating committee: ' . $e->getMessage());
        }

        header('Location: /mms/admin/dashboard.php');
        exit;
    }

    // -------------- QUICK ADD PARTICIPANT --------------
    if (isset($_POST['quick_add_participant'])) {

        if (function_exists('csrf_token_is_valid')) {
            if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
                flash_set('error', 'Security token expired. Please try again.');
                header('Location: /mms/admin/dashboard.php');
                exit;
            }
        }

        $fullName      = trim($_POST['participant_name'] ?? '');
        $email         = trim($_POST['participant_email'] ?? '');
        $phone         = trim($_POST['participant_phone'] ?? '');
        $designationId = !empty($_POST['designation_id']) ? (int)$_POST['designation_id'] : null;

        if ($fullName !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO participants (full_name, email, phone, designation_id)
                VALUES (:full_name, :email, :phone, :designation_id)
            ");
            $stmt->execute([
                ':full_name'      => $fullName,
                ':email'          => $email ?: null,
                ':phone'          => $phone ?: null,
                ':designation_id' => $designationId,
            ]);
            flash_set('success', 'Participant added successfully.');
        } else {
            flash_set('error', 'Participant name is required.');
        }

        header('Location: /mms/admin/dashboard.php');
        exit;
    }

    // -------------- QUICK ADD VENUE --------------
    if (isset($_POST['quick_add_venue'])) {

        if (function_exists('csrf_token_is_valid')) {
            if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
                flash_set('error', 'Security token expired. Please try again.');
                header('Location: /mms/admin/dashboard.php');
                exit;
            }
        }

        $name    = trim($_POST['venue_name'] ?? '');
        $address = trim($_POST['venue_address'] ?? '');
        $cap     = trim($_POST['venue_capacity'] ?? '');
        $link    = trim($_POST['venue_link'] ?? '');
        $capacity = is_numeric($cap) ? (int)$cap : null;

        if ($name !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO venues (name, address, capacity, virtual_link)
                VALUES (:name, :address, :capacity, :virtual_link)
            ");
            $stmt->execute([
                ':name'         => $name,
                ':address'      => $address ?: null,
                ':capacity'     => $capacity,
                ':virtual_link' => $link ?: null,
            ]);
            flash_set('success', 'Venue added successfully.');
        } else {
            flash_set('error', 'Venue name is required.');
        }

        header('Location: /mms/admin/dashboard.php');
        exit;
    }

    // -------------- QUICK SCHEDULE MEETING --------------
    if (isset($_POST['quick_add_meeting'])) {

        if (function_exists('csrf_token_is_valid')) {
            if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
                flash_set('error', 'Security token expired. Please try again.');
                header('Location: /mms/admin/dashboard.php');
                exit;
            }
        }

        $title   = trim($_POST['meeting_title'] ?? '');
        $desc    = trim($_POST['meeting_description'] ?? '');
        $cid     = !empty($_POST['committee_id']) ? (int)$_POST['committee_id'] : null;
        $vid     = !empty($_POST['venue_id']) ? (int)$_POST['venue_id'] : null;
        $start   = trim($_POST['start_datetime'] ?? '');
        $end     = trim($_POST['end_datetime'] ?? '');

        if ($title !== '' && $cid && $start !== '' && $end !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO meetings (
                    committee_id, title, description,
                    start_datetime, end_datetime,
                    venue_id, created_by_user_id, status
                )
                VALUES (
                    :committee_id, :title, :description,
                    :start_datetime, :end_datetime,
                    :venue_id, :created_by_user_id, 'scheduled'
                )
            ");
            $stmt->execute([
                ':committee_id'       => $cid,
                ':title'              => $title,
                ':description'        => $desc ?: null,
                ':start_datetime'     => $start,
                ':end_datetime'       => $end,
                ':venue_id'           => $vid ?: null,
                ':created_by_user_id' => $userId ?: null,
            ]);
            flash_set('success', 'Meeting scheduled successfully.');
        } else {
            flash_set('error', 'Title, Committee, Start and End time are required.');
        }

        header('Location: /mms/admin/dashboard.php');
        exit;
    }
}

// =========================================================
//  STATS
// =========================================================
$counts = [
    'committees'   => 0,
    'meetings'     => 0,
    'participants' => 0,
    'venues'       => 0,
];

$counts['committees']   = (int)$pdo->query("SELECT COUNT(*) FROM committees")->fetchColumn();
$counts['meetings']     = (int)$pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn();
$counts['participants'] = (int)$pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();

try {
    $counts['venues'] = (int)$pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
} catch (Exception $e) {
    $counts['venues'] = 0;
}

// Dropdown data
$designations    = [];
$commSelect      = [];
$venueSelect     = [];
$allParticipants = [];
$adminCandidates = [];

try {
    $designations = $pdo->query("SELECT id, title FROM designations ORDER BY title ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $designations = [];
}

try {
    $commSelect = $pdo->query("SELECT id, name FROM committees ORDER BY name ASC")
                      ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $commSelect = [];
}

try {
    $venueSelect = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")
                       ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $venueSelect = [];
}

// For New Committee modal: all participants as potential initial members
try {
    $allParticipants = $pdo->query("
        SELECT id, full_name
        FROM participants
        ORDER BY full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allParticipants = [];
}

// For New Committee modal: admin/nodal officer candidates (users)
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

// =========================================================
//  UPCOMING MEETINGS (superadmin sees all)
// =========================================================
$upcoming = [];
try {
    $sql = "
        SELECT m.id, m.title, m.start_datetime, m.end_datetime,
               c.name AS committee_name,
               v.name AS venue_name
        FROM meetings m
        JOIN committees c ON m.committee_id = c.id
        LEFT JOIN venues v ON m.venue_id = v.id
        WHERE m.start_datetime >= NOW()
        ORDER BY m.start_datetime ASC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $upcoming = [];
}

include __DIR__ . '/../header.php';
?>

<div class="mb-4">
  <div class="d-flex flex-wrap align-items-center">
    <div>
      <h2 class="mb-1">Meeting Management Dashboard (Superadmin)</h2>
      <p class="text-muted mb-0">
        Welcome back,
        <strong><?= htmlspecialchars($user['username'] ?? 'User') ?></strong>
        <span class="badge bg-secondary ms-2">
          <?= htmlspecialchars($user['role'] ?? 'user') ?>
        </span>
      </p>
    </div>

    <!-- Buttons block -->
    <div class="mt-3 mt-md-0 d-flex gap-2 ms-auto">
      <button class="btn btn-success btn-rounded"
              data-bs-toggle="modal" data-bs-target="#modalScheduleMeeting">
        <i class="bi bi-calendar-plus me-1"></i> Schedule Meeting
      </button>

      <button class="btn btn-primary btn-rounded"
              data-bs-toggle="modal" data-bs-target="#modalNewCommittee">
        <i class="bi bi-diagram-3-fill me-1"></i> New Committee
      </button>

      <button class="btn btn-outline-dark btn-rounded"
              data-bs-toggle="modal" data-bs-target="#modalNewParticipant">
        <i class="bi bi-person-plus me-1"></i> Add Participant
      </button>

      <button class="btn btn-outline-secondary btn-rounded"
              data-bs-toggle="modal" data-bs-target="#modalNewVenue">
        <i class="bi bi-geo-alt-fill me-1"></i> Add Venue
      </button>
    </div>

  </div>
</div>


<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err = flash_get('error')): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- Stats cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted text-uppercase small">Committees</span>
          <span class="text-primary"><i class="bi bi-diagram-3-fill fs-4"></i></span>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['committees'] ?></h3>
        <a href="/mms/committees/list.php" class="mt-auto small text-decoration-none">
          Manage committees <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted text-uppercase small">Meetings</span>
          <span class="text-success"><i class="bi bi-calendar-check-fill fs-4"></i></span>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['meetings'] ?></h3>
        <a href="/mms/meetings/list.php" class="mt-auto small text-decoration-none">
          View all meetings <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted text-uppercase small">Participants</span>
          <span class="text-info"><i class="bi bi-people-fill fs-4"></i></span>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['participants'] ?></h3>
        <a href="/mms/participants/list.php" class="mt-auto small text-decoration-none">
          Manage participants <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-muted text-uppercase small">Venues</span>
          <span class="text-warning"><i class="bi bi-geo-alt-fill fs-4"></i></span>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['venues'] ?></h3>
        <a href="/mms/venues/list.php" class="mt-auto small text-decoration-none">
          Manage venues <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Upcoming meetings -->
  <div class="col-lg-7">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-clock-history me-1 text-primary"></i>
          Upcoming Meetings
        </h5>
        <a href="/mms/meetings/list.php" class="small text-decoration-none">
          View all
        </a>
      </div>
      <div class="card-body">
        <?php if (!$upcoming): ?>
          <p class="text-muted mb-0">No upcoming meetings scheduled.</p>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($upcoming as $m): ?>
              <a href="/mms/meetings/view.php?id=<?= (int)$m['id'] ?>"
                 class="list-group-item list-group-item-action">
                <div class="d-flex w-100 justify-content-between">
                  <h6 class="mb-1"><?= htmlspecialchars($m['title']) ?></h6>
                  <small class="text-muted">
                    <?= htmlspecialchars(date('d M Y H:i', strtotime($m['start_datetime']))) ?>
                  </small>
                </div>
                <p class="mb-1 text-muted small">
                  Committee: <?= htmlspecialchars($m['committee_name']) ?>
                </p>
                <small class="text-muted">
                  Venue: <?= htmlspecialchars($m['venue_name'] ?? 'TBD') ?>
                </small>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick actions sidebar -->
  <div class="col-lg-5">
    <div class="card shadow-sm border-0 mb-3">
      <div class="card-header bg-white border-0">
        <h5 class="mb-0"><i class="bi bi-lightning-charge-fill me-1 text-warning"></i> Quick Actions</h5>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Use these shortcuts to create new records instantly.
        </p>
        <div class="d-grid gap-2">
          <button class="btn btn-outline-success"
                  data-bs-toggle="modal" data-bs-target="#modalScheduleMeeting">
            <i class="bi bi-calendar-plus me-1"></i> Schedule a new meeting
          </button>
          <button class="btn btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#modalNewCommittee">
            <i class="bi bi-diagram-3-fill me-1"></i> Create a new committee
          </button>
          <button class="btn btn-outline-dark"
                  data-bs-toggle="modal" data-bs-target="#modalNewParticipant">
            <i class="bi bi-person-plus me-1"></i> Register a new participant
          </button>
          <button class="btn btn-outline-secondary"
                  data-bs-toggle="modal" data-bs-target="#modalNewVenue">
            <i class="bi bi-geo-alt-fill me-1"></i> Add a meeting venue
          </button>
        </div>
      </div>
    </div>

    <div class="card shadow-sm border-0">
      <div class="card-header bg-white border-0">
        <h6 class="mb-0 text-muted">System Info</h6>
      </div>
      <div class="card-body small text-muted">
        <div class="d-flex justify-content-between mb-1">
          <span>Logged in as</span>
          <span><?= htmlspecialchars($user['username'] ?? 'User') ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span>Role</span>
          <span><?= htmlspecialchars($user['role'] ?? 'user') ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span>Server time</span>
          <span><?= date('d M Y H:i') ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== MODALS ===================== -->

<!-- New Committee Modal -->
<div class="modal fade" id="modalNewCommittee" tabindex="-1" aria-labelledby="modalNewCommitteeLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNewCommitteeLabel">
          <i class="bi bi-diagram-3-fill me-1 text-primary"></i> New Committee
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="quick_add_committee" value="1">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label">Committee Name <span class="text-danger">*</span></label>
            <input type="text" name="committee_name" class="form-control" required
                   placeholder="e.g. Finance Review Committee">
          </div>

          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="committee_description" class="form-control" rows="3"
                      placeholder="Short description of the committee’s purpose"></textarea>
          </div>

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
            </div>

            <div class="form-text small">
              You can add multiple members one by one. These will be stored as committee members.
            </div>
          </div>

          <!-- Admin / Nodal Officer: searchable dropdown -->
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
              <?php foreach ($adminCandidates as $u): ?>
                <option value="<?= (int)$u['id'] ?>">
                  <?= htmlspecialchars($u['name']) ?>
                  <?php if (!empty($u['email'])): ?>
                    (<?= htmlspecialchars($u['email']) ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div class="form-text small">
              This user will be the committee head and will be able to manage meetings and members.
            </div>
          </div>

          <p class="text-muted small mb-0">
            The new committee will be visible under
            <strong>Committees &raquo; List</strong>. The selected nodal officer will manage this
            committee from the Committee Admin Dashboard.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Create Committee
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- New Participant Modal -->
<div class="modal fade" id="modalNewParticipant" tabindex="-1" aria-labelledby="modalNewParticipantLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNewParticipantLabel">
          <i class="bi bi-person-plus me-1 text-dark"></i> New Participant
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="quick_add_participant" value="1">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="participant_name" class="form-control" required
                   placeholder="e.g. Rohan Sharma">
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="participant_email" class="form-control"
                   placeholder="e.g. rohan@example.com">
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="text" name="participant_phone" class="form-control"
                   placeholder="e.g. +91-9876543210">
          </div>
          <div class="mb-3">
            <label class="form-label">Designation</label>
            <select name="designation_id" class="form-select">
              <option value="">-- Select designation --</option>
              <?php foreach ($designations as $d): ?>
                <option value="<?= (int)$d['id'] ?>">
                  <?= htmlspecialchars($d['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$designations): ?>
              <div class="form-text text-warning">
                No designations yet. You can maintain them later directly in the DB.
              </div>
            <?php endif; ?>
          </div>
          <p class="text-muted small mb-0">
            Participant will be available while adding committee members and meeting attendees.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark">
            <i class="bi bi-check-lg me-1"></i> Save Participant
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- New Venue Modal -->
<div class="modal fade" id="modalNewVenue" tabindex="-1" aria-labelledby="modalNewVenueLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNewVenueLabel">
          <i class="bi bi-geo-alt-fill me-1 text-secondary"></i> New Venue
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="quick_add_venue" value="1">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label">Venue Name <span class="text-danger">*</span></label>
            <input type="text" name="venue_name" class="form-control" required
                   placeholder="e.g. Conference Room A">
          </div>
          <div class="mb-3">
            <label class="form-label">Address / Location</label>
            <textarea name="venue_address" class="form-control" rows="2"
                      placeholder="Building, floor, city, etc."></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Capacity</label>
            <input type="number" name="venue_capacity" min="1" class="form-control"
                   placeholder="Approx no. of seats">
          </div>
          <div class="mb-3">
            <label class="form-label">Virtual Link (optional)</label>
            <input type="url" name="venue_link" class="form-control"
                   placeholder="e.g. Teams / Zoom link">
          </div>
          <p class="text-muted small mb-0">
            Venues can be selected when scheduling meetings.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-outline-secondary">
            <i class="bi bi-check-lg me-1"></i> Save Venue
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Schedule Meeting Modal -->
<div class="modal fade" id="modalScheduleMeeting" tabindex="-1" aria-labelledby="modalScheduleMeetingLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content shadow-lg">
      <div class="modal-header">
        <h5 class="modal-title" id="modalScheduleMeetingLabel">
          <i class="bi bi-calendar-plus me-1 text-success"></i> Schedule Meeting
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="quick_add_meeting" value="1">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Meeting Title <span class="text-danger">*</span></label>
              <input type="text" name="meeting_title" class="form-control" required
                     placeholder="e.g. Quarterly Financial Review">
            </div>
            <div class="col-md-4">
              <label class="form-label">Committee <span class="text-danger">*</span></label>
              <select name="committee_id" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($commSelect as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$commSelect): ?>
                <div class="form-text text-warning small">
                  No committees yet – create one first.
                </div>
              <?php endif; ?>
            </div>

            <div class="col-md-6">
              <label class="form-label">Start Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="start_datetime" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Date & Time <span class="text-danger">*</span></label>
              <input type="datetime-local" name="end_datetime" class="form-control" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Venue</label>
              <select name="venue_id" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($venueSelect as $v): ?>
                  <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Agenda / Description</label>
              <textarea name="meeting_description" class="form-control" rows="3"
                        placeholder="Short summary of discussion topics"></textarea>
            </div>
          </div>
          <p class="text-muted small mt-3 mb-0">
            Participants can be added from the meeting details page after saving.
          </p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i> Save & Schedule
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Simple JS for search + add-one-by-one members/admin -->
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
        // hidden input is inside wrapper, so will be removed too
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
      const query = this.value.toLowerCase();
      Array.from(adminSelect.options).forEach(function (opt, idx) {
        if (idx === 0) return; // skip placeholder
        const text = opt.textContent.toLowerCase();
        opt.hidden = query && !text.includes(query);
      });
    });
  }
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
