<?php
// mms/admin/dashboard.php
session_start();

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$user   = currentUser();
$pdo    = getPDO();
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
//     if (isset($_POST['quick_add_committee'])) {

//         // Optional CSRF check if you have helpers
//         if (function_exists('csrf_token_is_valid')) {
//             if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
//                 flash_set('error', 'Security token expired. Please try again.');
//                 header('Location: /mms/admin/dashboard.php');
//                 exit;
//             }
//         }

//         $name        = trim($_POST['committee_name'] ?? '');
//         $desc        = trim($_POST['committee_description'] ?? '');
//         $memberIds   = array_map('intval', $_POST['member_ids'] ?? []); // participants
//         $adminUserId = (int)($_POST['admin_user_id'] ?? 0);             // users.id (nodal officer)

//         if ($name === '') {
//             flash_set('error', 'Committee name is required.');
//             header('Location: /mms/admin/dashboard.php');
//             exit;
//         }

//         try {
//             $pdo->beginTransaction();

//             // Create committee
//             $stmt = $pdo->prepare("
//                 INSERT INTO committees (name, description, created_by_user_id)
//                 VALUES (:name, :description, :created_by)
//             ");
//             $stmt->execute([
//                 ':name'        => $name,
//                 ':description' => $desc !== '' ? $desc : null,
//                 ':created_by'  => $userId ?: null,
//             ]);

//             // Get new committee ID (Postgres style; MySQL ignores the argument)
//           // 1️⃣ Insert committee
//             $committeeId = (int)$pdo->lastInsertId('committees_id_seq');

//             // // 2️⃣ Insert nodal officer as ADMIN (NO DESIGNATION)
//             // if (!empty($_POST['admin_user_id'])) {
//             //     $stmt = $pdo->prepare("
//             //         INSERT INTO committee_users
//             //         (committee_id, participant_id, role_in_committee)
//             //         VALUES
//             //         (:cid, :uid, 'admin')
//             //     ");
//             //     $stmt->execute([
//             //         ':cid' => $committeeId,
//             //         ':uid' => (int)$_POST['admin_user_id']
//             //     ]);
//             // }

//           // 3️⃣ Insert members as MEMBER (DESIGNATION-BASED)
//           // 3️⃣ Insert members as MEMBER (via participants table)
// if (!empty($_POST['members_json'])) {
//     $members = json_decode($_POST['members_json'], true);

//     if (is_array($members)) {
// $checkStmt = $pdo->prepare("
//     SELECT id
// FROM participants
// WHERE
//     (CAST(:external_id AS text) IS NOT NULL AND external_id = CAST(:external_id AS text))
//  OR (CAST(:external_id AS text) IS NULL AND CAST(:email AS text) IS NOT NULL AND email = CAST(:email AS text))
//  OR (CAST(:external_id AS text) IS NULL AND CAST(:email AS text) IS NULL AND CAST(:phone AS text) IS NOT NULL AND phone = CAST(:phone AS text))
// LIMIT 1;


// ");
//           // participant insert
//         $pStmt = $pdo->prepare("
//       INSERT INTO participants
//           (full_name, email, phone, external_source, participant_type, external_id, designation_id, created_at)
//       VALUES
//           (:full_name, :email, :phone, :external_source, :participant_type, :external_id, :designation_id, NOW())
//       RETURNING id
//   ");

//         // committee_users link
//         $cuStmt = $pdo->prepare("
//             INSERT INTO committee_users
//             (committee_id, participant_id, role_in_committee)
//             VALUES
//             (:cid, :pid, 'member')
//         ");

//         foreach ($members as $m) {

//             if (empty($m['full_name'])) continue;
//             $checkStmt->execute([
//                 ':external_id' => $m['external_id'] ?? null,
//                 ':email'       => $m['email'] ?? null,
//                 ':phone'       => $m['phone'] ?? null,
//             ]);
//             $participantId = (int)$checkStmt->fetchColumn();
//    if ($participantId <= 0) {

//     $pStmt->execute([
//         ':full_name'        => $m['full_name'],
//         ':email'            => $m['email'] ?? null,
//         ':phone'            => $m['phone'] ?? null,
//         ':external_source'  => $m['external_source'] ?? '',
//         ':participant_type' => $m['participant_type'] ?? null,
//         ':external_id'      => $m['external_id'] ?? null,
//         ':designation_id'   => $m['designation_id'] ?? null,
//     ]);

//     $participantId = (int)$pStmt->fetchColumn();
// }
//           //   // 1️⃣ insert participant
//           //  $pStmt->execute([
//           //       ':full_name'        => $m['full_name'],
//           //       ':email'            => $m['email'] ?? null,
//           //       ':phone'            => $m['phone'] ?? null,
//           //       ':external_source'  => $m['external_source'] ?? '',
//           //       ':participant_type' => $m['participant_type'] ?? null,
//           //       ':external_id'      => $m['external_id'] ?? null,
//           //       ':designation_id'   => $m['designation_id'] ?? null,
//           //   ]);

//           //   $participantId = (int)$pStmt->fetchColumn();

//             if ($participantId <= 0) continue;

//             // 2️⃣ link to committee
//             $cuStmt->execute([
//                 ':cid' => $committeeId,
//                 ':pid' => $participantId
//             ]);
//         }
//     }
// }

// // ===== ENSURE ADMIN USER HAS PARTICIPANT (MUST BE BEFORE ADMIN INSERTS) =====
// if ($adminUserId > 0) {

//     // get user info + participant link
//     $uStmt = $pdo->prepare("
//         SELECT participant_id, full_name, email
//         FROM users
//         WHERE id = :uid
//     ");
//     $uStmt->execute([':uid' => $adminUserId]);
//     $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);

//     $participantId = (int)($uRow['participant_id'] ?? 0);

//     // if participant not linked, create it
//     if ($participantId <= 0) {

//         $pStmt = $pdo->prepare("
//             INSERT INTO participants
//             (full_name, email, participant_type, external_source, external_id)
//             VALUES
//             (:full_name, :email, 'internal', 'users', :external_id)
//             RETURNING id
//         ");
//         $pStmt->execute([
//             ':full_name'   => $uRow['full_name'],
//             ':email'       => $uRow['email'],
//             ':external_id' => 'user:' . $adminUserId
//         ]);

//         $participantId = (int)$pStmt->fetchColumn();

//         // link participant back to user
//         $upd = $pdo->prepare("
//             UPDATE users
//             SET participant_id = :pid
//             WHERE id = :uid
//         ");
//         $upd->execute([
//             ':pid' => $participantId,
//             ':uid' => $adminUserId
//         ]);
//     }
// }



//             // Insert initial members (participants) as 'member'
//             // if (!empty($memberIds)) {
//             //     $memStmt = $pdo->prepare("
//             //         INSERT INTO committee_users (committee_id, participant_id, role_in_committee)
//             //         VALUES (:cid, :pid, 'member')
//             //     ");
//             //     foreach ($memberIds as $pid) {
//             //         if ($pid <= 0) continue;
//             //         $memStmt->execute([
//             //             ':cid' => $committeeId,
//             //             ':pid' => $pid,
//             //         ]);
//             //     }
//             // }

//             // Assign initial committee admin / nodal officer
//             // if ($adminUserId > 0) {

//             //     // Avoid duplicate admin entries (safe even if fresh committee)
//             //     $chk = $pdo->prepare("
//             //         SELECT id FROM committee_admins
//             //         WHERE committee_id = :cid AND user_id = :uid
//             //         LIMIT 1
//             //     ");
//             //     $chk->execute([
//             //         ':cid' => $committeeId,
//             //         ':uid' => $adminUserId,
//             //     ]);

//             //     if (!$chk->fetch()) {
//             //         $admStmt = $pdo->prepare("
//             //             INSERT INTO committee_admins (committee_id, user_id, assigned_at)
//             //             VALUES (:cid, :uid, NOW())
//             //         ");
//             //         $admStmt->execute([
//             //             ':cid' => $committeeId,
//             //             ':uid' => $adminUserId,
//             //         ]);
//             //     }

//             //     // OPTIONAL: auto-add admin as member if linked via participant_id
//             //     /*
//             //     $uStmt = $pdo->prepare("SELECT participant_id FROM users WHERE id = :id");
//             //     $uStmt->execute([':id' => $adminUserId]);
//             //     $uRow = $uStmt->fetch();
//             //     if (!empty($uRow['participant_id'])) {
//             //         $pid = (int)$uRow['participant_id'];
//             //         if ($pid > 0) {
//             //             $memStmt->execute([':cid' => $committeeId, ':pid' => $pid]);
//             //         }
//             //     }
//             //     */
//             // }
//             // 2️⃣ Insert nodal officer as ADMIN (via participant_id)
// if ($adminUserId > 0) {

//     // fetch participant_id linked to user
//     $uStmt = $pdo->prepare("
//         SELECT participant_id 
//         FROM users 
//         WHERE id = :uid
//     ");
//     $uStmt->execute([':uid' => $adminUserId]);
//     $participantId = (int)$uStmt->fetchColumn();

//     if ($participantId > 0) {
//         $stmt = $pdo->prepare("
//             INSERT INTO committee_users
//             (committee_id, participant_id, role_in_committee)
//             VALUES
//             (:cid, :pid, 'admin')
//         ");
//         $stmt->execute([
//             ':cid' => $committeeId,
//             ':pid' => $participantId
//         ]);
//     }
// }
// // 3️⃣ ALSO INSERT INTO committee_admins (SOURCE OF TRUTH FOR ADMINS)
// if ($adminUserId > 0) {

//     $admCheck = $pdo->prepare("
//         SELECT id
//         FROM committee_admins
//         WHERE committee_id = :cid
//           AND user_id = :uid
//         LIMIT 1
//     ");
//     $admCheck->execute([
//         ':cid' => $committeeId,
//         ':uid' => $adminUserId
//     ]);

//     if (!$admCheck->fetch()) {
//         $admStmt = $pdo->prepare("
//             INSERT INTO committee_admins
//             (committee_id, user_id, assigned_at)
//             VALUES
//             (:cid, :uid, NOW())
//         ");
//         $admStmt->execute([
//             ':cid' => $committeeId,
//             ':uid' => $adminUserId
//         ]);
//     }
// }



//             $pdo->commit();
//             flash_set('success', 'Committee created successfully with initial members and nodal officer.');

//         } catch (Exception $e) {
//             $pdo->rollBack();
//             flash_set('error', 'Error creating committee: ' . $e->getMessage());
//         }

//         header('Location: /mms/admin/dashboard.php');
//         exit;
//     }

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
// Load all designations for dropdown
$designationStmt = $pdo->prepare("
    SELECT id, title 
    FROM designations
    ORDER BY title
");
$designationStmt->execute();
$designations = $designationStmt->fetchAll(PDO::FETCH_ASSOC);

// Classification of designations
$judgeTitles = array(
    "Hon'ble the Chief Justice",
    "Hon'ble the Acting Chief Justice",
    "Hon'ble Mr./Ms. Justice",
    "Hon'ble Administrative Judge"
);

$advocateTitles = array(
    "Advocate",
    "Senior Advocate",
    "Government Advocate, Rajasthan High Court",
    "Additional Advocate General, Rajasthan",
    "Deputy Government Advocate",
    "Public Prosecutor, High Court",
    "Additional Public Prosecutor, High Court"
);

// Only this one will go to manual Gov Officer form
$govOfficerTitles = array(
    "Government Officer"
);


include __DIR__ . '/../header.php';
?>
<style>
/* Gradient stat cards */
.stat-card {
  color: #fff;
  border-radius: 14px;
  position: relative;
  overflow: hidden;
}

.stat-card .text-muted {
  color: rgba(255,255,255,0.8) !important;
}

.stat-card a {
  color: rgba(255,255,255,0.9);
}

.stat-card a:hover {
  color: #fff;
  text-decoration: underline;
}

.stat-card::after {
  content: '';
  position: absolute;
  right: -20px;
  bottom: -20px;
  width: 120px;
  height: 120px;
  background: rgba(255,255,255,0.12);
  border-radius: 50%;
}

/* Individual themes */
.card-committees {
  background: linear-gradient(135deg, #4e54c8, #8f94fb);
}

.card-meetings {
  background: linear-gradient(135deg, #11998e, #38ef7d);
}

.card-participants {
  background: linear-gradient(135deg, #2193b0, #6dd5ed);
}

.card-venues {
  background: linear-gradient(135deg, #f7971e, #ffd200);
}
</style>


<body>
 <script src="/mms/assets/js/committee-core.js"></script>
 <script>
document.addEventListener('DOMContentLoaded', function () {
    initCommitteeModule({
        formId: 'committeeForm',
        addAsId: 'add_as',
        memberTypeId: 'member_type',
        designationId: 'designation_id',
        searchInputId: 'search_query',
        searchResultId: 'search_results',
        addBtnId: 'addPersonBtn',
        previewListId: 'previewList'
    });
});
</script>
<div class="mb-4">
  <div class="d-flex flex-wrap align-items-center">
    <div>
      <h2 class="mb-1">Meeting Management Dashboard (Superadmin)</h2>
      <p class="text-muted mb-0">
        Welcome back,
        <strong><?= htmlspecialchars($user['username'] ?? 'User') ?></strong>
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
    <div class="card stat-card card-committees shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-uppercase small">Committees</span>
          <i class="bi bi-diagram-3-fill fs-3"></i>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['committees'] ?></h3>
        <a href="/mms/committees/list.php" class="mt-auto small text-decoration-none">
          Manage committees <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card stat-card card-meetings shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-uppercase small">Meetings</span>
          <i class="bi bi-calendar-check-fill fs-3"></i>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['meetings'] ?></h3>
        <a href="/mms/meetings/list.php" class="mt-auto small text-decoration-none">
          View all meetings <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card stat-card card-participants shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-uppercase small">Participants</span>
          <i class="bi bi-people-fill fs-3"></i>
        </div>
        <h3 class="fw-bold mb-3"><?= $counts['participants'] ?></h3>
        <a href="/mms/participants/list.php" class="mt-auto small text-decoration-none">
          Manage participants <i class="bi bi-arrow-right-short"></i>
        </a>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-lg-3">
    <div class="card stat-card card-venues shadow-sm border-0 h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-uppercase small">Venues</span>
          <i class="bi bi-geo-alt-fill fs-3"></i>
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


 <!-- New Committee Modal-->
<div class="modal fade" id="modalNewCommittee" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form id="committeeForm" method="post" action="javascript:void(0);" >


        <div class="modal-header">
          <h5 class="modal-title">Create Committee</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <!-- CSRF -->
          <input type="hidden" name="csrf" id="csrf" value="<?= csrf_token() ?>">

          <!-- Committee Name -->
          <div class="mb-3">
            <label class="form-label">Committee Name *</label>
            <input type="text" id="committee_name"  class="form-control" required>
          </div>

          <!-- Description -->
          <div class="mb-3">
            <label class="form-label">Description *</label>
            <textarea id="committee_description" name="committee_description" class="form-control" required></textarea>
          </div>

          <hr>
          <!-- 🔥 COMMITTEE ADMIN (NEW – ADD THIS HERE) -->
          <!-- <div class="mb-3">
            <label class="form-label">Committee Admin *</label>
            <select id="adminUserSelect" class="form-select" required>
              <option value="">-- Select Admin --</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>">
                  <?= htmlspecialchars($u['full_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div> -->

          <hr>

          <!-- Add As -->
          <div class="mb-3">
            <label class="form-label">Add As *</label>
            <select id="add_as" class="form-select">
              <option value="">-- Select --</option>
              <option value="member">Member</option>
              <option value="admin">Nodal Officer</option>
              <option value="admin">Chairperson</option>
            </select>
          </div>

          <!-- Member Type -->
          <div class="mb-3 d-none" id="memberTypeBox">
            <label class="form-label">Member Type *</label>
            <select id="member_type" class="form-select">
              <option value="">-- Select --</option>
              <option value="judge">Judge</option>
              <option value="registry">Registry Officer</option>
              <option value="advocate">Advocate</option>
              <option value="govt">Government Officer</option>
            </select>
          </div>

          <!-- Search -->
          <div class="mb-3 d-none" id="searchBox">
            <label class="form-label">Search Person</label>
            <input type="text" id="search_query" class="form-control">
            <select id="search_results" class="form-select mt-2"></select>
          </div>

          <!-- Manual -->
          <div id="manualBox" class="d-none">
            <div class="mb-2">
              <label>Full Name *</label>
              <input type="text" id="manual_name" class="form-control">
            </div>
            <div class="mb-2">
              <label>Phone *</label>
              <input type="text" id="manual_phone" class="form-control">
            </div>
            <div class="mb-2">
              <label>Email</label>
              <input type="email" id="manual_email" class="form-control">
            </div>
            <div class="mb-2 d-none" id="deptBox">
              <label>Department *</label>
              <input type="text" id="manual_department" class="form-control">
            </div>
          </div>

          <!-- Designation -->
          <div class="mb-3">
            <label class="form-label">Designation *</label>
            <select id="designation_id" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($designations as $d): ?>
                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
                <div id="memberError" class="text-danger mt-2" style="display:none;"></div>

          <button type="button" id="addPersonBtn" class="btn btn-success btn-sm">
            Add to Committee
          </button>

          <ul class="list-group mt-3" id="previewList"></ul>

        </div>

        <div class="modal-footer">
          <button type ="submit" class="btn btn-primary">
            Create Committee
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
// document.addEventListener('DOMContentLoaded', function () {

//     var committeeMembers = [];
//     // ===================== ADMIN / NODAL OFFICER SEARCH =====================
//     const adminSearchInput = document.getElementById('adminSearchInput');
//     const adminSelect      = document.getElementById('adminSelect');

//     if (adminSearchInput && adminSelect) {
//         adminSearchInput.addEventListener('input', function () {
//             const query = adminSearchInput.value.trim();

//             if (query.length < 2) {
//                 return;
//             }

//             const url = 'searchUsers.php?q=' + encodeURIComponent(query);

//             fetch(url, {
//                 method: 'GET',
//                 headers: {
//                     'Accept': 'application/json'
//                 }
//             })
//             .then(function (response) {
//                 if (!response.ok) {
//                     throw new Error('Network response was not ok: ' + response.status);
//                 }
//                 return response.json();
//             })
//             .then(function (data) {
//                 if (!Array.isArray(data)) {
//                     console.error('Response is not an array:', data);
//                     return;
//                 }

//                 adminSelect.innerHTML = '';
//                 const defaultOpt = document.createElement('option');
//                 defaultOpt.value = '';
//                 defaultOpt.textContent = '-- Select user (optional) --';
//                 adminSelect.appendChild(defaultOpt);

//                 if (data.length === 0) {
//                     const noOpt = document.createElement('option');
//                     noOpt.disabled = true;
//                     noOpt.textContent = 'No users found';
//                     adminSelect.appendChild(noOpt);
//                     return;
//                 }

//                 data.forEach(function (user) {
//                     const opt = document.createElement('option');
//                     opt.value = user.id; // admin is stored by internal user id

//                     const labelParts = [];
//                     if (user.display_name) {
//                         labelParts.push(user.display_name);
//                     }
//                     if (user.email) {
//                         labelParts.push('(' + user.email + ')');
//                     }

//                     opt.textContent = labelParts.join(' ') || ('ID ' + user.id);
//                     adminSelect.appendChild(opt);
//                 });
//             })
//             .catch(function (error) {
//                 console.error('Admin search fetch error:', error);
//             });
//         });
//     }

//     // ===================== MEMBER MODULE FROM view.php =====================

//     var designationSelect       = document.getElementById('designation_select');

//     var apiSection              = document.getElementById('api-member-section');
//     var advSection              = document.getElementById('advocate-section');
//     var govSection              = document.getElementById('gov-officer-section');

//     var govFormCaption          = document.getElementById('gov_form_caption');

//     var participantTypeInput    = document.getElementById('participant_type');
//     var designationTitleInput   = document.getElementById('designation_title');
//     var designationDescInput    = document.getElementById('designation_description');
//     var externalSourceInput     = document.getElementById('external_source');
//     var externalIdInput         = document.getElementById('external_id');
//     var hiddenFullName          = document.getElementById('hidden_full_name');
//     var hiddenEmail             = document.getElementById('hidden_email');
//     var hiddenPhone             = document.getElementById('hidden_phone');

//     var apiSearchButton         = document.getElementById('api_search_button');
//     var apiSearchQuery          = document.getElementById('api_search_query');
//     var apiResultSelect         = document.getElementById('api_result_select');

//     var advFullName             = document.getElementById('adv_full_name');
//     var advDesignation          = document.getElementById('adv_designation');
//     var advMobile               = document.getElementById('adv_mobile');
//     var advEmail                = document.getElementById('adv_email');

//     var govFullName             = document.getElementById('gov_full_name');
//     var govDesignation          = document.getElementById('gov_designation');
//     var govDepartment           = document.getElementById('gov_department');
//     var govMobile               = document.getElementById('gov_mobile');
//     var govEmail                = document.getElementById('gov_email');

//     // This is your committee modal form
//     var addMemberForm           = document.getElementById('new-committee-form');
//     var modalAddMemberBtn       = document.getElementById('modalAddMemberBtn');
//     var addedMembersList = document.getElementById('addedMembersList');


//     var currentApiUrl = null;

//     function resetHiddenFields() {
//     externalIdInput.value       = '';
//     hiddenFullName.value        = '';
//     hiddenEmail.value           = '';
//     hiddenPhone.value           = '';
//     designationTitleInput.value = '';
//     designationDescInput.value  = '';
//     } 

  

//     function hideAllSections() {
//         if (apiSection) apiSection.style.display = 'none';
//         if (advSection) advSection.style.display = 'none';
//         if (govSection) govSection.style.display = 'none';
//     }

//     // When designation changes, decide which section to show
//     if (designationSelect) {
//      designationSelect.addEventListener('change', function() {

//             var value     = designationSelect.value;
//             var option    = designationSelect.options[designationSelect.selectedIndex];
//             var title     = option ? (option.getAttribute('data-title') || option.textContent || '') : '';
//             var category  = option ? (option.getAttribute('data-category') || '') : '';

//             resetHiddenFields();
//             hideAllSections();
          
//             if (!value) {
//                 participantTypeInput.value = '';
//                 externalSourceInput.value  = '';
//                 currentApiUrl = null;
//                 if (modalAddMemberBtn) modalAddMemberBtn.style.display = 'none';
//                 return;
//             }

//             // Add new designation path
//             if (value === 'ADD_NEW') {
//                 participantTypeInput.value = 'gov_officer';
//                 externalSourceInput.value  = 'MANUAL';
//                 if (govSection) govSection.style.display = 'block';
//                 if (govFormCaption) govFormCaption.textContent = 'Add new designation and person details.';
//                 if (govDesignation) govDesignation.value = '';
//                 if (govDepartment)  govDepartment.value  = '';
//                 if (govFullName)    govFullName.value    = '';
//                 if (govMobile)      govMobile.value      = '';
//                 if (govEmail)       govEmail.value       = '';
//                 return;
//             }
            

//             // Existing designations
//             if (!category) {
//                 participantTypeInput.value = '';
//                 externalSourceInput.value  = '';
//                 currentApiUrl = null;
//                 return;
//             }

//             if (category === 'judge') {
//                 participantTypeInput.value = 'judge';
//                 externalSourceInput.value  = 'JUDGES_API';
//                 currentApiUrl              = '../admin/judges_Search.php';
//                 if (apiSection) apiSection.style.display = 'block';
//                 if (modalAddMemberBtn) modalAddMemberBtn.style.display = 'inline-block';
//                 designationTitleInput.value = title;
//             } else if (category === 'registry_officer') {
//                 participantTypeInput.value = 'registry_officer';
//                 externalSourceInput.value  = 'REGISTRY_API';
//                 // same SSO user search used elsewhere
//                 currentApiUrl              = '../admin/searchUsers.php';
//                 if (apiSection) apiSection.style.display = 'block';
//                 if (modalAddMemberBtn) modalAddMemberBtn.style.display = 'inline-block';
//                 designationTitleInput.value = title;
//             } else if (category === 'advocate') {
//                if (modalAddMemberBtn) modalAddMemberBtn.style.display = 'inline-block';
//                 participantTypeInput.value = 'advocate';
//                 externalSourceInput.value  = 'MANUAL';
//                 if (advSection) advSection.style.display = 'block';
//                 if (advDesignation) advDesignation.value = title;
//                 designationTitleInput.value = title;
//             } else if (category === 'gov_officer') {
//                if (modalAddMemberBtn) modalAddMemberBtn.style.display = 'inline-block';
//                 participantTypeInput.value = 'gov_officer';
//                 externalSourceInput.value  = 'MANUAL';
//                 if (govSection) govSection.style.display = 'block';
//                 if (govFormCaption) govFormCaption.textContent = 'Enter details of the Government Officer.';
//                 if (govDesignation) govDesignation.value = '';
//                 if (govDepartment)  govDepartment.value  = '';
//                 if (govFullName)    govFullName.value    = '';
//                 if (govMobile)      govMobile.value      = '';
//                 if (govEmail)       govEmail.value       = '';
//             } else {
//                 // default safety: treat as registry_officer
//                 participantTypeInput.value = 'registry_officer';
//                 externalSourceInput.value  = 'REGISTRY_API';
//                 currentApiUrl              = '../admin/searchUsers.php';
//                 if (apiSection) apiSection.style.display = 'block';
//                 designationTitleInput.value = title;
//             }
//           })

    
    

//     // --- API search click (Judges / Registry via AJAX) ---
//     if (apiSearchButton && apiSearchQuery && apiResultSelect) {
//         apiSearchButton.addEventListener('click', function() {
//             if (!currentApiUrl) {
//                 alert('No API configured for this designation.');
//                 return;
//             }
//             var q = apiSearchQuery.value.trim();
//             if (!q || q.length < 2) {
//                 alert('Please enter at least 2 characters to search.');
//                 return;
//             }

//             var url = currentApiUrl + '?q=' + encodeURIComponent(q);

//             fetch(url, {
//                 method: 'GET',
//                 headers: { 'Accept': 'application/json' }
//             })
//             .then(function (response) {
//                 if (!response.ok) {
//                     throw new Error('Network response was not ok: ' + response.status);
//                 }
//                 return response.json();
//             })
//             .then(function (data) {
//                 var normalized;

//                 if (!Array.isArray(data)) {
//                     console.error('Response is not an array:', data);
//                     return;
//                 }

//                 if (currentApiUrl.indexOf('searchUsers.php') !== -1) {
//                     // Normalize SSO user search to generic structure
//                     normalized = [];
//                     for (var i = 0; i < data.length; i++) {
//                         var u = data[i];
//                         normalized.push({
//                             id:          u.rjcode,
//                             name:        u.display_name || '',
//                             designation: designationTitleInput.value || '',
//                             email:       u.email || '',
//                             phone:       '',
//                             department:  ''
//                         });
//                     }
//                 } else if (currentApiUrl.indexOf('judges_Search.php') !== -1) {
//                           normalized = [];
//                           for (var i = 0; i < data.length; i++) {
//                               var u = data[i];

//                               // Build full name with salutation in front
//                               var fullName = '';
//                               if (u.salute) {
//                                   fullName += u.salute.trim() + ' ';
//                               }
//                               fullName += (u.judge_name || '').trim();

//                               normalized.push({
//                                   id:          u.jocode,        // external_id
//                                   name:        fullName,        // e.g. "MR. SANJEEV PRAKASH SHARMA"
//                                   designation: '',              // DON'T put salute here
//                                   email:       '',              // stop abusing email for judge_code
//                                   phone:       '',
//                                   department:  ''
//                             });
//         }}else {
//                     // For future judges API that already returns generic objects
//                     normalized = data;
//                 }

//                 fillApiResults(normalized);
//             })
//             .catch(function (error) {
//                 console.error('API search error:', error);
//                 alert('Error while searching. Please try again.');
//             });
//         });
//     }
//   }
//     function fillApiResults(data) {
//         apiResultSelect.innerHTML = '';

//         var defaultOpt = document.createElement('option');
//         defaultOpt.value = '';
//         defaultOpt.text  = '-- Select a person --';
//         apiResultSelect.appendChild(defaultOpt);

//         for (var i = 0; i < data.length; i++) {
//             var item = data[i];
//             var opt  = document.createElement('option');
//             opt.value = item.id; // external_id from API

//             var label = item.name || 'Unknown';
//             if (item.designation) {
//                 // label += ' (' + item.designation + ')';
//             }
//             opt.text  = label;

//             if (item.name)       opt.setAttribute('data-name', item.name);
//             if (item.email)      opt.setAttribute('data-email', item.email);
//             if (item.phone)      opt.setAttribute('data-phone', item.phone);
//             if (item.designation)opt.setAttribute('data-designation', item.designation);
//             if (item.department) opt.setAttribute('data-department', item.department);

//             apiResultSelect.appendChild(opt);
//         }
//     }

//     // export in case you want it later
//     window.mmsFillApiResults = fillApiResults;

//     // When user selects an entry from API results, fill hidden fields
//     if (apiResultSelect) {
//         apiResultSelect.addEventListener('change', function() {
// //           if (hiddenFullName.value) {
// //             showAddMemberBtn();
// // }


//             var selectedValue = apiResultSelect.value;
//             if (!selectedValue) {
//                 externalIdInput.value      = '';
//                 hiddenFullName.value       = '';
//                 hiddenEmail.value          = '';
//                 hiddenPhone.value          = '';
//                 designationDescInput.value = '';
//                 return;
//             }

//             var selectedOption = apiResultSelect.options[apiResultSelect.selectedIndex];

//             var name        = selectedOption.getAttribute('data-name') || '';
//             var email       = selectedOption.getAttribute('data-email') || '';
//             var phone       = selectedOption.getAttribute('data-phone') || '';
//             var desig       = selectedOption.getAttribute('data-designation') || '';
//             var department  = selectedOption.getAttribute('data-department') || '';

//             externalIdInput.value    = selectedValue;
//             hiddenFullName.value     = name;
//             hiddenEmail.value        = email;
//             hiddenPhone.value        = phone;

//             if (desig !== '') {
//                 designationTitleInput.value = desig;
//             }

//             if (department !== '') {
//                 designationDescInput.value = 'Department: ' + department;
//             } else {
//                 designationDescInput.value = '';
//             }
//         });
//     }
//     // ===================== ADD MEMBER (NEW COMMITTEE) =====================
// if (modalAddMemberBtn) {
//     modalAddMemberBtn.addEventListener('click', function () {

//         var ptype = participantTypeInput.value;
//         if (!ptype) {
//             alert('Please select a designation first.');
//             return;
//         }

//         // Judges / Registry via API
//         if (ptype === 'judge' || ptype === 'registry_officer') {
//             if (!externalIdInput.value || !hiddenFullName.value) {
//                 alert('Please search and select a person from the API.');
//                 return;
//             }
//         }

//         // Advocate
//         if (ptype === 'advocate') {
//             if (!advFullName.value.trim() || !advMobile.value.trim()) {
//                 alert('Please fill Name and Mobile for Advocate.');
//                 return;
//             }

//             hiddenFullName.value = advFullName.value.trim();
//             hiddenPhone.value    = advMobile.value.trim();
//             hiddenEmail.value    = advEmail.value.trim();
//             designationTitleInput.value =
//                 advDesignation.value.trim() || 'Advocate';
//         }

//         // Government Officer
//         if (ptype === 'gov_officer') {
//             if (
//                 !govFullName.value.trim() ||
//                 !govDesignation.value.trim() ||
//                 !govMobile.value.trim()
//             ) {
//                 alert('Please fill Name, Designation and Mobile.');
//                 return;
//             }

//             hiddenFullName.value = govFullName.value.trim();
//             hiddenPhone.value    = govMobile.value.trim();
//             hiddenEmail.value    = govEmail.value.trim();
//             designationTitleInput.value = govDesignation.value.trim();
//             designationDescInput.value =
//                 govDepartment.value.trim()
//                     ? ('Department: ' + govDepartment.value.trim())
//                     : '';
//         }

//         // ✅ STORE MEMBER IN ARRAY
//         committeeMembers.push({
//             participant_type: participantTypeInput.value,
//             designation_id: designationSelect.value,
//             designation_title: designationTitleInput.value,
//             designation_description: designationDescInput.value,
//             full_name: hiddenFullName.value,
//             email: hiddenEmail.value,
//             phone: hiddenPhone.value,
//             external_source: externalSourceInput.value,
//             external_id: externalIdInput.value
//         });
//         // ===== SHOW ADDED MEMBER ON SCREEN =====
//           if (addedMembersList) {
//               var last = committeeMembers[committeeMembers.length - 1];

//               var li = document.createElement('li');
//               li.className = 'list-group-item d-flex justify-content-between align-items-center';

//               li.innerHTML =
//                   '<span>' +
//                   last.full_name +
//                   ' <small class="text-muted">(' +
//                   last.designation_title +
//                   ')</small></span>';

//                 addedMembersList.appendChild(li);
//           }

//         // sync array to hidden input
//         document.getElementById('members_json').value =
//             JSON.stringify(committeeMembers);

//         // reset UI
//         resetHiddenFields();
//         hideAllSections();
//         designationSelect.value = '';

//         alert('Member added.');
//     });
// }

    

//     // Submit validation for the committee create form
//     if (addMemberForm) {
      
//             addMemberForm.addEventListener('submit', function (e) {
//               if (committeeMembers.length === 0) {
//                   alert('Please add at least one committee member.');
//                   e.preventDefault();
//                   return;
//               }
     

//             // Judges / Registry via API
//             if (ptype === 'judge' || ptype === 'registry_officer') {
//                 if (!externalIdInput.value) {
//                     alert('Please search and select a person from the API list.');
//                     e.preventDefault();
//                     return;
//                 }
//                 if (!hiddenFullName.value) {
//                     alert('Full name from API is missing. Integrate the API mapping first.');
//                     e.preventDefault();
//                     return;
//                 }
//                 if (!designationTitleInput.value) {
//                     designationTitleInput.value = (ptype === 'judge')
//                         ? "Hon'ble Mr./Ms. Justice"
//                         : 'Registrar';
//                 }
//                 return;
//             }

//             // Advocate manual
//             if (ptype === 'advocate') {
//                 var name   = advFullName.value.trim();
//                 var desig  = advDesignation.value.trim();
//                 var mobile = advMobile.value.trim();
//                 var email  = advEmail.value.trim();

//                 if (!name || !mobile) {
//                     alert('Please fill Name and Mobile for Advocate.');
//                     e.preventDefault();
//                     return;
//                 }

//                 hiddenFullName.value        = name;
//                 hiddenPhone.value           = mobile;
//                 hiddenEmail.value           = email;
//                 designationTitleInput.value = desig !== '' ? desig : 'Advocate';
//                 designationDescInput.value  = '';
//                 externalIdInput.value       = '';
//                 externalSourceInput.value   = 'MANUAL';
//                 return;
//             }

//             // Government Officer or Add New Designation (both use gov form)
//             if (ptype === 'gov_officer') {
//                 var gname   = govFullName.value.trim();
//                 var gdesig  = govDesignation.value.trim();
//                 var gdept   = govDepartment.value.trim();
//                 var gmobile = govMobile.value.trim();
//                 var gemail  = govEmail.value.trim();

//                 if (!gname || !gdesig || !gmobile) {
//                     alert('Please fill Name, Designation and Mobile.');
//                     e.preventDefault();
//                     return;
//                 }

//                 hiddenFullName.value        = gname;
//                 hiddenPhone.value           = gmobile;
//                 hiddenEmail.value           = gemail;
//                 designationTitleInput.value = gdesig;
//                 designationDescInput.value  = gdept !== '' ? ('Department: ' + gdept) : '';
//                 externalIdInput.value       = '';
//                 externalSourceInput.value   = 'MANUAL';
//                 return;
//             }
//             }  )

//         }
//    });

</script>
          


<?php include __DIR__ . '/../footer.php'; ?>
