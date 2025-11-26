<?php
// mms/committee_admin/dashboard.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = currentUser();

// If a superadmin opens this URL, send them to global dashboard
if (isSuperAdmin($user)) {
    header('Location: /mms/admin/dashboard.php');
    exit;
}

// Committees where this user is an admin
$committeeIds = getUserAdminCommitteeIds($pdo, $user);

/**
 * QUICK / SCHEDULE MEETING WITH PARTICIPANTS (popup submit handler)
 * ----------------------------------------------------------------
 * Triggered when the schedule meeting modal form is submitted.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_meeting_committee'])) {

    $title   = trim($_POST['meeting_title'] ?? '');
    $desc    = trim($_POST['meeting_description'] ?? '');
    $cid     = !empty($_POST['committee_id']) ? (int)$_POST['committee_id'] : 0;
    $vid     = !empty($_POST['venue_id']) ? (int)$_POST['venue_id'] : null;
    $start   = trim($_POST['start_datetime'] ?? '');
    $end     = trim($_POST['end_datetime'] ?? '');
    $participantIds = array_map('intval', $_POST['participant_ids'] ?? []);

    // Ensure user is allowed to act for this committee
    if (!$committeeIds || !$cid || !in_array($cid, $committeeIds, true)) {
        flash_set('error', 'You are not allowed to schedule meetings for this committee.');
        header('Location: /mms/committee_admin/dashboard.php');
        exit;
    }

    if ($title !== '' && $cid > 0 && $start !== '' && $end !== '') {
        try {
            $pdo->beginTransaction();

            $userId = $user['id'] ?? null;

            // Insert meeting
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

            // Last insert id (Postgres sequence name; MySQL ignores the argument)
            $meetingId = (int)$pdo->lastInsertId('meetings_id_seq');

            // Insert participants (if any)
            if (!empty($participantIds)) {
                $ins = $pdo->prepare("
                    INSERT INTO meeting_participants (meeting_id, participant_id, added_by_user_id)
                    VALUES (:mid, :pid, :uid)
                ");
                foreach ($participantIds as $pid) {
                    $ins->execute([
                        ':mid' => $meetingId,
                        ':pid' => $pid,
                        ':uid' => $userId,
                    ]);
                }
            }

            $pdo->commit();
            flash_set('success', 'Meeting scheduled successfully.');

        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('error', 'Error scheduling meeting: ' . $e->getMessage());
        }

    } else {
        flash_set('error', 'Title, Committee, Start and End time are required.');
    }

    // Redirect back to the same dashboard page
    header('Location: /mms/committee_admin/dashboard.php');
    exit;
}

// If user is not admin of any committee, show info message
if (!$committeeIds) {
    include __DIR__ . '/../header.php';
    ?>
    <div class="row">
      <div class="col-lg-8 offset-lg-2">
        <div class="card shadow-sm border-0 mt-4">
          <div class="card-body text-center">
            <h3 class="mb-3">No committees assigned</h3>
            <p class="text-muted">
              You are currently not assigned as an admin of any committee.<br>
              Please contact the system administrator to assign you as a committee head.
            </p>
          </div>
        </div>
      </div>
    </div>
    <?php
    include __DIR__ . '/../footer.php';
    exit;
}

// Build IN (...) safely
$placeholders = implode(',', array_fill(0, count($committeeIds), '?'));

// Fetch committees this user leads
$stmt = $pdo->prepare("
    SELECT c.*
    FROM committees c
    WHERE c.id IN ($placeholders)
    ORDER BY c.name ASC
");
$stmt->execute($committeeIds);
$committees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Meeting stats per committee
$meetingStats = [];        // [committee_id => ['count'=>.., 'next'=>row]]
$upcomingMeetings = [];    // [committee_id => [rows...]]

if ($committees) {
    // Total meetings per committee
    $stmtCount = $pdo->prepare("
        SELECT committee_id, COUNT(*) AS total
        FROM meetings
        WHERE committee_id IN ($placeholders)
        GROUP BY committee_id
    ");
    $stmtCount->execute($committeeIds);
    foreach ($stmtCount->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meetingStats[(int)$row['committee_id']]['count'] = (int)$row['total'];
    }

    // All upcoming meetings per committee
    $stmtNext = $pdo->prepare("
        SELECT m.*
        FROM meetings m
        WHERE m.committee_id IN ($placeholders)
          AND m.start_datetime >= NOW()
        ORDER BY m.committee_id ASC, m.start_datetime ASC
    ");
    $stmtNext->execute($committeeIds);
    foreach ($stmtNext->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)$row['committee_id'];

        if (!isset($upcomingMeetings[$cid])) {
            $upcomingMeetings[$cid] = [];
        }
        $upcomingMeetings[$cid][] = $row;

        // The first upcoming meeting becomes the "next" meeting summary
        if (!isset($meetingStats[$cid]['next'])) {
            $meetingStats[$cid]['next'] = $row;
        }
    }
}

// Load venues (for all committees)
$venues = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load committee members for ALL committees this admin manages, grouped by committee_id
$committeeMembers = [];
if ($committees) {
    $stmtMem = $pdo->prepare("
        SELECT cu.committee_id, p.id, p.full_name
        FROM committee_users cu
        JOIN participants p ON cu.participant_id = p.id
        WHERE cu.committee_id IN ($placeholders)
        ORDER BY cu.committee_id, p.full_name ASC
    ");
    $stmtMem->execute($committeeIds);

    foreach ($stmtMem->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)$row['committee_id'];
        if (!isset($committeeMembers[$cid])) {
            $committeeMembers[$cid] = [];
        }
        $committeeMembers[$cid][] = [
            'id'        => (int)$row['id'],
            'full_name' => $row['full_name'],
        ];
    }
}

include __DIR__ . '/../header.php';
?>

<div class="mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center">
    <div>
      <h2 class="mb-1">Committee Admin Dashboard</h2>
      <p class="text-muted mb-0">
        Welcome,
        <strong><?= htmlspecialchars($user['username'] ?? 'User') ?></strong>
        – you are managing
        <strong><?= count($committees) ?></strong>
        committee<?= count($committees) === 1 ? '' : 's' ?>.
      </p>
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
  <?php foreach ($committees as $c):
      $cid = (int)$c['id'];
      $stats = $meetingStats[$cid] ?? ['count' => 0];
      $countMeetings = $stats['count'] ?? 0;
      $next = $stats['next'] ?? null;
      $members = $committeeMembers[$cid] ?? [];
      $upcoming = $upcomingMeetings[$cid] ?? [];
  ?>
    <div class="col-md-6 col-xl-4">
      <div class="card shadow-sm border-0 h-100">
        <div class="card-body d-flex flex-column">

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
              <h5 class="card-title mb-1">
                <i class="bi bi-diagram-3-fill text-primary me-1"></i>
                <?= htmlspecialchars($c['name']) ?>
              </h5>
              <p class="text-muted small mb-0">
                <?= $countMeetings ?> meeting<?= $countMeetings === 1 ? '' : 's' ?> scheduled
              </p>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs small mt-2" id="committeeTabs-<?= $cid ?>" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active"
                      id="overview-tab-<?= $cid ?>"
                      data-bs-toggle="tab"
                      data-bs-target="#overview-<?= $cid ?>"
                      type="button"
                      role="tab">
                Overview
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link"
                      id="upcoming-tab-<?= $cid ?>"
                      data-bs-toggle="tab"
                      data-bs-target="#upcoming-<?= $cid ?>"
                      type="button"
                      role="tab">
                Upcoming Meetings
              </button>
            </li>
          </ul>

          <div class="tab-content pt-3 flex-grow-1">
            <!-- Overview tab -->
            <div class="tab-pane fade show active" id="overview-<?= $cid ?>" role="tabpanel">

              <?php if (!empty($c['description'])): ?>
                <p class="mt-1 small">
                  <?= nl2br(htmlspecialchars($c['description'])) ?>
                </p>
              <?php endif; ?>

              <div class="mt-2 mb-3">
                <span class="text-muted small d-block mb-1">Next meeting</span>
                <?php if ($next): ?>
                  <div class="d-flex justify-content-between small">
                    <span><?= htmlspecialchars($next['title']) ?></span>
                    <span class="text-muted">
                      <?= htmlspecialchars(date('d M Y H:i', strtotime($next['start_datetime']))) ?>
                    </span>
                  </div>
                <?php else: ?>
                  <p class="text-muted small mb-0">No upcoming meetings.</p>
                <?php endif; ?>
              </div>

              <div class="mt-2">
                <a href="/mms/meetings/add.php?committee_id=<?= $cid ?>"
                   class="btn btn-sm btn-outline-success me-2">
                  <i class="bi bi-calendar-plus me-1"></i> Full Meeting
                </a>
                <a href="/mms/meetings/list.php?committee_id=<?= $cid ?>"
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-calendar-event me-1"></i> All Meetings
                </a>
              </div>
            </div>

            <!-- Upcoming tab -->
            <div class="tab-pane fade" id="upcoming-<?= $cid ?>" role="tabpanel">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">Upcoming meetings</span>

                <!-- SCHEDULE MEETING (popup) -->
                <button type="button"
                        class="btn btn-sm btn-success"
                        data-bs-toggle="modal"
                        data-bs-target="#scheduleMeetingModal-<?= $cid ?>">
                  <i class="bi bi-calendar-plus me-1"></i> Schedule Meeting
                </button>
              </div>

              <?php if (empty($upcoming)): ?>
                <p class="text-muted small mb-0">
                  No upcoming meetings scheduled.
                </p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="small">
                      <tr>
                        <th>Title</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody class="small">
                    <?php foreach ($upcoming as $m): ?>
                      <tr>
                        <td><?= htmlspecialchars($m['title']) ?></td>
                        <td><?= htmlspecialchars(date('d M Y H:i', strtotime($m['start_datetime']))) ?></td>
                        <td><?= htmlspecialchars(date('d M Y H:i', strtotime($m['end_datetime']))) ?></td>
                        <td><?= htmlspecialchars($m['status'] ?? '') ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Footer buttons (Members / Participants) -->
          <div class="mt-3 pt-2 border-top">
            <div class="btn-group w-100">
              <a href="/mms/committees/view.php?id=<?= $cid ?>"
                 class="btn btn-sm btn-outline-dark">
                <i class="bi bi-people-fill me-1"></i> Members
              </a>
              <a href="/mms/participants/list.php?committee_id=<?= $cid ?>"
                 class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-person-lines-fill me-1"></i> Participants
              </a>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Schedule Meeting Modal for this committee -->
    <div class="modal fade" id="scheduleMeetingModal-<?= $cid ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">
              Schedule Meeting – <?= htmlspecialchars($c['name']) ?>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <form method="post">
            <div class="modal-body">

              <!-- hidden flag for PHP handler -->
              <input type="hidden" name="quick_add_meeting_committee" value="1">
              <!-- committee id for which we are scheduling -->
              <input type="hidden" name="committee_id" value="<?= $cid ?>">

              <div class="mb-3">
                <label class="form-label">Title<span class="text-danger">*</span></label>
                <input type="text" name="meeting_title" class="form-control" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="meeting_description" class="form-control" rows="3"></textarea>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Start Date & Time<span class="text-danger">*</span></label>
                  <input type="datetime-local" name="start_datetime" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">End Date & Time<span class="text-danger">*</span></label>
                  <input type="datetime-local" name="end_datetime" class="form-control" required>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">Venue</label>
                <select name="venue_id" class="form-select">
                  <option value="">-- Select Venue --</option>
                  <?php foreach ($venues as $v): ?>
                    <option value="<?= (int)$v['id'] ?>">
                      <?= htmlspecialchars($v['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Participants</label>
                <select name="participant_ids[]" class="form-select" multiple size="6">
                  <?php foreach ($members as $p): ?>
                    <option value="<?= (int)$p['id'] ?>">
                      <?= htmlspecialchars($p['full_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (Windows) to select multiple.</div>
              </div>

            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1"></i> Save Meeting
              </button>
            </div>
          </form>

        </div>
      </div>
    </div>

  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
