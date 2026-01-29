<?php
// mms/meetings/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo     = getPDO();
$user    = $_SESSION['user'] ?? null;
$user_id = $user['id'] ?? null;

// Committee
$committee_id = (int)($_POST['committee_id'] ?? $_GET['committee_id'] ?? 0);
if ($committee_id > 0) {
    requireCommitteeAdminFor($pdo, $committee_id, $user);
}

// -----------------------------------------------------------
// Load committee & members
// -----------------------------------------------------------
$committee           = null;
$committee_members   = [];
$extra_participants  = [];

if ($committee_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $committee_id]);
    $committee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$committee) {
        flash_set('error', 'Committee not found.');
        header('Location: /mms/committees/list.php');
        exit;
    }

    $membersStmt = $pdo->prepare("
        SELECT cu.participant_id AS id, p.full_name, p.email
        FROM committee_users cu
        JOIN participants p ON p.id = cu.participant_id
        WHERE cu.committee_id = :cid
        ORDER BY p.full_name
    ");
    $membersStmt->execute([':cid' => $committee_id]);
    $committee_members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    $extraStmt = $pdo->prepare("
        SELECT p.id, p.full_name, p.email
        FROM participants p
        WHERE p.id NOT IN (
            SELECT cu.participant_id FROM committee_users cu WHERE cu.committee_id = :cid
        )
        ORDER BY p.full_name
    ");
    $extraStmt->execute([':cid' => $committee_id]);
    $extra_participants = $extraStmt->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------------------------------------
// Load committees for dropdown
// -----------------------------------------------------------
$committees = [];
if ($committee_id > 0 && $committee) {
    $committees = [
        ['id' => $committee['id'], 'name' => $committee['name']]
    ];
} else {
    try {
        $adminStmt = $pdo->prepare("
            SELECT c.id, c.name
            FROM committees c
            JOIN committee_admins ca ON ca.committee_id = c.id
            WHERE ca.user_id = :uid
            ORDER BY c.name ASC
        ");
        $adminStmt->execute([':uid' => $user_id]);
        $committees = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $committees = [];
    }

    if (empty($committees)) {
        $committees = $pdo->query("SELECT id, name FROM committees ORDER BY name ASC")
                          ->fetchAll(PDO::FETCH_ASSOC);
    }
}

// -----------------------------------------------------------
// Load venues
// -----------------------------------------------------------
$venues   = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$venue_id = (int)($_POST['venue_id'] ?? $_GET['venue_id'] ?? 0);

// -----------------------------------------------------------
// Form state / helpers
// -----------------------------------------------------------
$title                 = '';
$description           = '';
$start_datetime        = '';
$end_datetime          = '';
$extra_participant_ids = [];
$errors                = [];
$conflicts             = [];
$venue_conflicts       = [];

function norm_dt($s) {
    if ($s === null) return $s;
    return str_replace('T', ' ', trim($s));
}

// -----------------------------------------------------------
// AJAX conflict + available venues check (returns JSON)
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_check'])) {
    $committee_id   = (int)($_POST['committee_id'] ?? 0);
    $start_datetime = norm_dt($_POST['start_datetime'] ?? '');
    $end_datetime   = norm_dt($_POST['end_datetime'] ?? '');
    $venue_id       = (int)($_POST['venue_id'] ?? 0);

    // Auto-load committee members
    $memberStmt = $pdo->prepare("SELECT participant_id FROM committee_users WHERE committee_id = ?");
    $memberStmt->execute([$committee_id]);
    $member_ids = array_map('intval', array_column($memberStmt->fetchAll(PDO::FETCH_ASSOC), 'participant_id'));

    // Extra participants from AJAX POST
    $extra_participant_ids = array_map('intval', $_POST['extra_participant_ids'] ?? []);

    $all_ids = array_values(array_unique(array_merge($member_ids, $extra_participant_ids)));

    $out = [
        'venue_conflicts'       => [],
        'participant_conflicts' => [],
        'available_venues'      => []
    ];

    // Selected venue conflicts
    if ($venue_id > 0 && $start_datetime !== '' && $end_datetime !== '') {
        $sql = "
            SELECT id, title, start_datetime, end_datetime
            FROM meetings
            WHERE venue_id = ?
              AND start_datetime < ?
              AND end_datetime > ?
            ORDER BY start_datetime ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$venue_id, $end_datetime, $start_datetime]);
        $out['venue_conflicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Participant conflicts
    if (!empty($all_ids) && $start_datetime !== '' && $end_datetime !== '') {
        $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
        $sql = "
            SELECT mp.participant_id, p.full_name,
                   m.id AS meeting_id, m.title, m.start_datetime, m.end_datetime
            FROM meeting_participants mp
            JOIN meetings m ON mp.meeting_id = m.id
            JOIN participants p ON mp.participant_id = p.id
            WHERE mp.participant_id IN ($placeholders)
              AND m.start_datetime < ?
              AND m.end_datetime > ?
            ORDER BY p.full_name, m.start_datetime
        ";
        $params = array_merge($all_ids, [$end_datetime, $start_datetime]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out['participant_conflicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Available venues (NOT booked in range)
    if ($start_datetime !== '' && $end_datetime !== '') {
        $sql = "
            SELECT id, name
            FROM venues
            WHERE id NOT IN (
                SELECT venue_id
                FROM meetings
                WHERE start_datetime < ?
                  AND end_datetime > ?
            )
            ORDER BY name ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$end_datetime, $start_datetime]);
        $out['available_venues'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

// -----------------------------------------------------------
// Normal POST save
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check'])) {
    $committee_id   = (int)($_POST['committee_id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $start_datetime = norm_dt($_POST['start_datetime'] ?? '');
    $end_datetime   = norm_dt($_POST['end_datetime'] ?? '');
    $venue_id       = (int)($_POST['venue_id'] ?? 0);

    // Auto-load committee members as participants
    $memberStmt = $pdo->prepare("SELECT participant_id FROM committee_users WHERE committee_id = ?");
    $memberStmt->execute([$committee_id]);
    $participant_ids = array_map('intval', array_column($memberStmt->fetchAll(PDO::FETCH_ASSOC), 'participant_id'));

    // Extra participants (guests)
    $extra_participant_ids = array_map('intval', $_POST['extra_participant_ids'] ?? []);

    // All participants (committee + guests)
    $all_participant_ids = array_values(array_unique(array_merge($participant_ids, $extra_participant_ids)));

    if ($committee_id <= 0) {
        $errors[] = 'Committee is required.';
    }
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($start_datetime === '' || $end_datetime === '') {
        $errors[] = 'Start and end date/time are required.';
    }

    if (empty($errors)) {
        // participant conflicts (for page - not blocking)
        if (!empty($all_participant_ids)) {
            $placeholders = implode(',', array_fill(0, count($all_participant_ids), '?'));
            $sql = "
                SELECT mp.participant_id, p.full_name,
                       m.id AS meeting_id, m.title, m.start_datetime, m.end_datetime
                FROM meeting_participants mp
                JOIN meetings m ON mp.meeting_id = m.id
                JOIN participants p ON mp.participant_id = p.id
                WHERE mp.participant_id IN ($placeholders)
                  AND m.start_datetime < ?
                  AND m.end_datetime > ?
            ";
            $params = array_merge($all_participant_ids, [$end_datetime, $start_datetime]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // venue conflicts (for page - not blocking)
        if ($venue_id > 0) {
            $sql = "
                SELECT id, title, start_datetime, end_datetime
                FROM meetings
                WHERE venue_id = ?
                  AND start_datetime < ?
                  AND end_datetime > ?
                ORDER BY start_datetime ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$venue_id, $end_datetime, $start_datetime]);
            $venue_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        try {
            $pdo->beginTransaction();

            $sql = "INSERT INTO meetings
                    (committee_id, title, description, start_datetime, end_datetime, venue_id, created_by_user_id)
                    VALUES (:committee_id, :title, :description, :start_datetime, :end_datetime, :venue_id, :created_by)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':committee_id'   => $committee_id,
                ':title'          => $title,
                ':description'    => $description ?: null,
                ':start_datetime' => $start_datetime,
                ':end_datetime'   => $end_datetime,
                ':venue_id'       => $venue_id ?: null,
                ':created_by'     => $user_id,
            ]);

            $meeting_id = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("
                INSERT INTO meeting_participants (meeting_id, participant_id, added_by_user_id, is_guest)
                VALUES (:mid, :pid, :uid, :is_guest)
            ");

            // Insert committee members as non-guest participants
            foreach ($participant_ids as $pid) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;
                $ins->execute([
                    ':mid'      => $meeting_id,
                    ':pid'      => $pid,
                    ':uid'      => $user_id,
                    ':is_guest' => 0
                ]);
            }

            // Insert extra participants as guests (avoid duplicates)
            $memberIdSet = array_flip($participant_ids);
            foreach ($extra_participant_ids as $pid) {
                $pid = (int)$pid;
                if ($pid <= 0) continue;
                if (isset($memberIdSet[$pid])) continue;
                $ins->execute([
                    ':mid'      => $meeting_id,
                    ':pid'      => $pid,
                    ':uid'      => $user_id,
                    ':is_guest' => 1
                ]);
            }

            $pdo->commit();

            if ($conflicts || $venue_conflicts) {
                flash_set('success', 'Meeting scheduled with conflict warnings.');
            } else {
                flash_set('success', 'Meeting scheduled successfully.');
            }

            header('Location: list.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Error saving meeting: ' . $e->getMessage();
        }
    }
}

// reload members if needed (e.g. after validation failure)
if ($committee_id > 0 && empty($committee_members)) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.full_name, p.email
        FROM committee_users cu
        JOIN participants p ON cu.participant_id = p.id
        WHERE cu.committee_id = :cid
        ORDER BY p.full_name ASC
    ");
    $stmt->execute([':cid' => $committee_id]);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../header.php';
?>
<!-- =================== HTML / Form =================== -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Schedule Meeting</h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!empty($conflicts)): ?>
  <div class="alert alert-warning">
    <strong>Warning:</strong> Some participants have other meetings in this time range:
    <ul class="mb-0">
      <?php foreach ($conflicts as $c): ?>
        <li>
          <?= htmlspecialchars($c['full_name']) ?> - already in
          "<?= htmlspecialchars($c['title']) ?>" (<?= htmlspecialchars($c['start_datetime']) ?> to <?= htmlspecialchars($c['end_datetime']) ?>)
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!empty($venue_conflicts)): ?>
  <div class="alert alert-warning">
    <strong>Warning:</strong> This venue already has other meeting(s) at this time:
    <ul class="mb-0">
      <?php foreach ($venue_conflicts as $vc): ?>
        <li>
          "<?= htmlspecialchars($vc['title']) ?>"
          (<?= htmlspecialchars($vc['start_datetime']) ?> to <?= htmlspecialchars($vc['end_datetime']) ?>)
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form id="meetingForm" method="post" class="card card-body" novalidate>
  <div class="mb-3">
    <label class="form-label">Committee<span class="text-danger">*</span></label>
    <select name="committee_id" class="form-select" onchange="this.form.submit()" required>
      <option value="">-- Select Committee --</option>
      <?php foreach ($committees as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($committee_id == $c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Changing committee will reload page to update member list.</div>
  </div>

  <div class="mb-3">
    <label class="form-label">Title<span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($title) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description) ?></textarea>
  </div>

  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Start Date & Time<span class="text-danger">*</span></label>
      <input id="start_datetime" type="datetime-local" name="start_datetime"
       class="form-control"
       min="<?= date('Y-m-d\TH:i') ?>"
       value="<?= htmlspecialchars(str_replace(' ', 'T', $start_datetime)) ?>" required>

<
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">End Date & Time<span class="text-danger">*</span></label>
      <input id="end_datetime" type="datetime-local" name="end_datetime"
       class="form-control"
       min="<?= date('Y-m-d\TH:i') ?>"
       value="<?= htmlspecialchars(str_replace(' ', 'T', $end_datetime)) ?>" required>

    </div>
  </div>

  <div class="mb-3">
    <label class="form-label">Venue</label>
    <select id="venue_id" name="venue_id" class="form-select">
      <option value="">-- Select Venue --</option>
      <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>" <?= ($venue_id == $v['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($v['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($committee_id > 0 && $committee): ?>
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-people-fill text-primary me-1"></i> Attendees</h5>
      <span class="badge bg-light text-muted">
        <?= count($committee_members) ?> committee member<?= count($committee_members) === 1 ? '' : 's' ?>
      </span>
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label small text-muted mb-1">Committee members</label>
        <div class="border rounded p-2 small bg-light">
          <p class="mb-1">
            Members of <strong><?= htmlspecialchars($committee['name']) ?></strong> are listed below.
            All committee members will be <strong>automatically invited</strong> to this meeting.
          </p>
          <?php if ($committee_members): ?>
            <div class="d-flex flex-wrap gap-2 mt-1">
              <?php foreach ($committee_members as $m): ?>
                <span class="badge bg-light border text-dark p-2 fs-6">
                  <?= htmlspecialchars($m['full_name']) ?>
                  <!-- <?php if (!empty($m['email'])): ?>
                    <span class="text-muted"> · <?= htmlspecialchars($m['email']) ?></span>
                  <?php endif; ?> -->
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">This committee does not have members yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="mb-0">
        <label class="form-label small text-muted mb-1">Additional participants (guests, optional)</label>
        <p class="text-muted small mb-2">
          Invite guests who are <strong>not</strong> members of this committee.
        </p>

        <input type="text" class="form-control form-control-sm mb-2" id="extraSearch"
               placeholder="Search by name or email...">

        <select multiple size="6" name="extra_participant_ids[]" id="extraParticipants"
                class="form-select form-select-sm">
          <?php foreach ($extra_participants as $p): ?>
            <option value="<?= (int)$p['id'] ?>">
              <?= htmlspecialchars($p['full_name']) ?><?php if (!empty($p['email'])): ?> (<?= htmlspecialchars($p['email']) ?>)<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text small text-muted">
          Hold <strong>Ctrl</strong> (Windows) or <strong>Cmd</strong> (Mac) to select multiple guests.
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <button id="saveBtn" type="submit" class="btn btn-success">
    <i class="bi bi-calendar-plus"></i> Save Meeting
  </button>
</form>

<!-- Modal -->
<div class="modal fade" id="conflictModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i> Meeting Conflicts / Alternatives</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div id="conflictSummary" class="mb-3"></div>

        <div id="venueConfSection" class="mb-3" style="display:none;">
          <h6 class="mb-2">📍 Selected Venue Conflicts</h6>
          <div id="conflictList" class="border rounded p-3 bg-light"></div>
        </div>

        <div id="participantConfSection" class="mb-3" style="display:none;">
          <h6 class="mb-2">👥 Participant Conflicts</h6>
          <div id="participantConflictInfo" class="alert alert-info py-2 px-3 mb-2" style="display:none;"></div>
          <div id="participantConflictList" class="border rounded p-3 bg-light"></div>
        </div>

        <div id="availableVenuesSection" class="mb-0" style="display:none;">
          <h6 class="mb-2">🏷️ Available Venues</h6>
          <p class="text-muted small mb-2">
            These venues are free during the selected time. Click a card to choose it for this meeting.
          </p>
          <div id="availableVenuesContainer" class="d-flex flex-wrap gap-2"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button id="chooseVenueBtn" class="btn btn-primary" type="button" style="display:none;">Use highlighted venue</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>

<style>
/* venue cards */
.venue-card {
  min-width: 130px;
  max-width: 220px;
  height: 56px;
  padding: 8px 12px;
  border-radius: 10px;
  border: 1px solid #e6e6e6;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  cursor: pointer;
  transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
  box-shadow: 0 2px 8px rgba(10,10,10,0.03);
  text-align: center;
}
.venue-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 30px rgba(10,10,10,0.08);
}
.venue-card.selected {
  border-color: #0d6efd;
  background: linear-gradient(180deg, rgba(13,110,253,0.06), #ffffff);
  box-shadow: 0 10px 30px rgba(13,110,253,0.12);
  color: #0d47a1;
}
.venue-card.empty {
  opacity: 0.95;
  background: #fff8e6;
  border-color: #ffd880;
  font-weight: 600;
}
.modal .modal-body {
  max-height: 60vh;
  overflow-y: auto;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  function $(id) { return document.getElementById(id); }

  function getSelectedValues(select) {
    if (!select) return [];
    var out = [];
    for (var i = 0; i < select.options.length; i++) {
      var o = select.options[i];
      if (o.selected && o.style.display !== 'none') out.push(o.value);
    }
    return out;
  }

  var form    = $('meetingForm');
  var saveBtn = $('saveBtn');
  var modalEl = $('conflictModal');

  var conflictSummary          = $('conflictSummary');
  var venueConfSection         = $('venueConfSection');
  var participantConfSection   = $('participantConfSection');
  var availableVenuesSection   = $('availableVenuesSection');
  var conflictList             = $('conflictList');
  var participantConflictList  = $('participantConflictList');
  var participantConflictInfo  = $('participantConflictInfo');
  var availableVenuesContainer = $('availableVenuesContainer');
  var chooseBtn                = $('chooseVenueBtn');

  var bsModal = (typeof bootstrap !== 'undefined' && modalEl)
      ? new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: false })
      : null;

  var chosenVenueId = null;

  function clearModal() {
    if (conflictSummary) conflictSummary.innerHTML = '';
    if (conflictList) conflictList.innerHTML = '';
    if (participantConflictList) participantConflictList.innerHTML = '';
    if (participantConflictInfo) {
      participantConflictInfo.innerHTML = '';
      participantConflictInfo.style.display = 'none';
    }
    if (availableVenuesContainer) availableVenuesContainer.innerHTML = '';

    if (venueConfSection)       venueConfSection.style.display = 'none';
    if (participantConfSection) participantConfSection.style.display = 'none';
    if (availableVenuesSection) availableVenuesSection.style.display = 'none';
    if (chooseBtn)              chooseBtn.style.display = 'none';

    chosenVenueId = null;
  }

  function renderVenueConflicts(vcon) {
    if (!conflictList || !venueConfSection) return;
    conflictList.innerHTML = '';

    if (!vcon || vcon.length === 0) {
      venueConfSection.style.display = 'none';
      return;
    }
    venueConfSection.style.display = '';

    var ul = document.createElement('ul');
    ul.className = 'mb-0';
    vcon.forEach(function (m) {
      var li = document.createElement('li');
      li.textContent = m.title + ' (' + m.start_datetime + ' → ' + m.end_datetime + ')';
      ul.appendChild(li);
    });
    conflictList.appendChild(ul);
  }

  function renderParticipantConflicts(pcon) {
    if (!participantConflictList || !participantConfSection) return;
    participantConflictList.innerHTML = '';

    if (!pcon || pcon.length === 0) {
      participantConfSection.style.display = 'none';
      if (participantConflictInfo) participantConflictInfo.style.display = 'none';
      return;
    }

    participantConfSection.style.display = '';

    if (participantConflictInfo) {
      participantConflictInfo.style.display = 'block';
      participantConflictInfo.innerHTML =
        'Some participants already have meetings at this time. Please reschedule the meeting time.';
    }

    var ul = document.createElement('ul');
    ul.className = 'mb-0';
    pcon.forEach(function (it) {
      var s = it.start_datetime ? it.start_datetime.replace(' ', 'T') : null;
      var e = it.end_datetime   ? it.end_datetime.replace(' ', 'T') : null;
      var info = '';
      if (s && e) {
        var diffMin   = Math.round((new Date(e) - new Date(s)) / 60000);
        var startOnly = new Date(s).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        info = ' at ' + startOnly + (diffMin ? (' for ' + diffMin + ' min') : '');
      }
      var li = document.createElement('li');
      li.textContent = it.full_name + info + ' — ' + it.title;
      ul.appendChild(li);
    });
    participantConflictList.appendChild(ul);
  }

  function renderAvailableVenues(list, hasVenueConflict) {
    if (!availableVenuesContainer || !availableVenuesSection) return;
    availableVenuesContainer.innerHTML = '';

    if (!hasVenueConflict) {
      availableVenuesSection.style.display = 'none';
      if (chooseBtn) chooseBtn.style.display = 'none';
      chosenVenueId = null;
      return;
    }

    if (!list || list.length === 0) {
      availableVenuesSection.style.display = 'none';
      if (chooseBtn) chooseBtn.style.display = 'none';
      chosenVenueId = null;
      return;
    }

    availableVenuesSection.style.display = '';

    list.forEach(function (v) {
      var card = document.createElement('div');
      card.className = 'venue-card';
      card.setAttribute('data-venue-id', v.id);
      card.textContent = v.name;
      card.addEventListener('click', function () {
        var prev = availableVenuesContainer.querySelector('.venue-card.selected');
        if (prev) prev.classList.remove('selected');
        card.classList.add('selected');
        chosenVenueId = String(v.id);
      });
      availableVenuesContainer.appendChild(card);
    });

    var first = availableVenuesContainer.querySelector('.venue-card');
    if (first) {
      first.classList.add('selected');
      chosenVenueId = first.getAttribute('data-venue-id');
    }

    if (chooseBtn) {
      chooseBtn.style.display = 'inline-block';
    }
  }

  if (chooseBtn) {
    chooseBtn.addEventListener('click', function () {
      if (!chosenVenueId) {
        chooseBtn.classList.add('btn-warning');
        setTimeout(function () { chooseBtn.classList.remove('btn-warning'); }, 700);
        return;
      }
      var sel = $('venue_id');
      if (sel) sel.value = chosenVenueId;
      if (bsModal) bsModal.hide();
    });
  }

  // Guest search filter
  (function setupGuestSearch() {
    var extraSearch = $('extraSearch');
    var extraSelect = $('extraParticipants');
    if (!extraSearch || !extraSelect) return;
    extraSearch.addEventListener('input', function () {
      var term = this.value.toLowerCase();
      var options = extraSelect.options;
      for (var i = 0; i < options.length; i++) {
        var text = options[i].text.toLowerCase();
        options[i].style.display = text.indexOf(term) !== -1 ? '' : 'none';
      }
    });
  })();

  // main submit handler
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      clearModal();

      var startVal = $('start_datetime') ? $('start_datetime').value : '';
      var endVal   = $('end_datetime')   ? $('end_datetime').value   : '';

      if (!startVal || !endVal) {
        if (conflictSummary) {
          conflictSummary.innerHTML =
            '<div class="alert alert-info mb-2">Please select both start and end date/time to check conflicts.</div>';
        }
        if (bsModal) bsModal.show();
        return;
      }

      var fd = new FormData();
      fd.append('ajax_check', '1');
      fd.append('committee_id', (form.querySelector('[name="committee_id"]') ? form.querySelector('[name="committee_id"]').value : ''));
      fd.append('start_datetime', startVal || '');
      fd.append('end_datetime', endVal || '');
      fd.append('venue_id', ($('venue_id') ? $('venue_id').value : '') || '');

      // ONLY extra participants (committee members are auto-loaded in PHP)
      var extras = getSelectedValues($('extraParticipants'));
      extras.forEach(function (p) { fd.append('extra_participant_ids[]', p); });

      fetch('', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      }).then(function (resp) {
        return resp.json();
      }).then(function (json) {
        console.log('ajax_check response', json);

        var vcon = json.venue_conflicts       || [];
        var pcon = json.participant_conflicts || [];
        var avs  = json.available_venues      || [];

        var hasVenueConflict       = vcon.length > 0;
        var hasParticipantConflict = pcon.length > 0;

        var summaryParts = [];

        if (hasVenueConflict && !hasParticipantConflict) {
          summaryParts.push('Selected venue is busy. You can change to another available venue.');
        }
        if (!hasVenueConflict && hasParticipantConflict) {
          summaryParts.push('Some participants have other meetings. Please reschedule the meeting time.');
        }
        if (hasVenueConflict && hasParticipantConflict) {
          summaryParts.push('Selected venue is busy and some participants have other meetings.');
          summaryParts.push('Please reschedule the meeting and/or change the venue using the options below.');
        }
        if (!hasVenueConflict && !hasParticipantConflict) {
          summaryParts.push('No conflicts found. You can proceed to save the meeting.');
        }

        if (conflictSummary) {
          var cls = (hasVenueConflict || hasParticipantConflict) ? 'warning' : 'info';
          conflictSummary.innerHTML =
            '<div class="alert alert-' + cls + ' mb-2">' + summaryParts.join(' ') + '</div>';
        }

        renderVenueConflicts(vcon);
        renderAvailableVenues(avs, hasVenueConflict);
        renderParticipantConflicts(pcon);

        var shouldShowModal = hasVenueConflict || hasParticipantConflict;
        if (shouldShowModal && bsModal) {
          bsModal.show();
        } else if (!shouldShowModal) {
          if (saveBtn) saveBtn.disabled = true;
          form.submit();
        }
      }).catch(function (err) {
        console.error('Conflict/available check failed:', err);
        if (saveBtn) saveBtn.disabled = true;
        form.submit();
      });
    });
  }

});
</script>
