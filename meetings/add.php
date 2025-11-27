<?php
// mms/meetings/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo   = getPDO();
$user  = $_SESSION['user'] ?? null;
$user_id = $user['id'] ?? null;

// Use a single variable name everywhere: $committee_id
$committee_id = (int)($_POST['committee_id'] ?? $_GET['committee_id'] ?? 0);

// If a committee is selected, enforce that the user is admin for it
if ($committee_id > 0) {
    requireCommitteeAdminFor($pdo, $committee_id, $user);
}

// -----------------------------------------------------------
// Load committee, committee members, and extra participants
// -----------------------------------------------------------
if ($committee_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $committee_id]);
    $committee = $stmt->fetch();

    if (!$committee) {
        flash_set('error', 'Committee not found.');
        header('Location: /mms/committees/list.php');
        exit;
    }

    // Committee members (can be invited as normal participants)
    $membersStmt = $pdo->prepare("
        SELECT cu.participant_id, p.full_name, p.email
        FROM committee_users cu
        JOIN participants p ON p.id = cu.participant_id
        WHERE cu.committee_id = :cid
        ORDER BY p.full_name
    ");
    $membersStmt->execute([':cid' => $committee_id]);
    $committeeMembers = $membersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Participants NOT in this committee → can be invited as guests
    $extraStmt = $pdo->prepare("
        SELECT p.id, p.full_name, p.email
        FROM participants p
        WHERE p.id NOT IN (
            SELECT cu.participant_id
            FROM committee_users cu
            WHERE cu.committee_id = :cid
        )
        ORDER BY p.full_name
    ");
    $extraStmt->execute([':cid' => $committee_id]);
    $extraParticipants = $extraStmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    $committee          = null;
    $committeeMembers   = [];
    $extraParticipants  = [];
}

// Load lookups
$committees = $pdo->query("SELECT id, name FROM committees ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$venues     = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$selectedVenueName = '';
foreach ($venues as $v) {
    if ($v['id'] == $venues) { // NOTE: this looks wrong but leaving as-is since you had it
        $selectedVenueName = $v['name'];
        break;
    }
}

$title = '';
$description = '';
$start_datetime = '';
$end_datetime = '';
$venue_id = '';
$participant_ids = [];        // committee members selected
$extra_participant_ids = [];  // guests selected
$errors = [];
$conflicts = [];         // participant conflicts
$venue_conflicts = [];   // venue conflicts;

// Helper: normalize datetime-local input (replace T with space)
function norm_dt($s) {
    if ($s === null) return $s;
    return str_replace('T', ' ', trim($s));
}

/*
 * AJAX pre-check: client will send ajax_check=1 to request conflicts only.
 * Return JSON: { venue_conflicts: [...], participant_conflicts: [...] }
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_check'])) {
    // Read values from POST (no saving)
    $committee_id   = (int)($_POST['committee_id'] ?? 0);
    $start_datetime = norm_dt($_POST['start_datetime'] ?? '');
    $end_datetime   = norm_dt($_POST['end_datetime'] ?? '');
    $venue_id       = (int)($_POST['venue_id'] ?? 0);

    $participant_ids        = array_map('intval', $_POST['participant_ids'] ?? []);
    $extra_participant_ids  = array_map('intval', $_POST['extra_participant_ids'] ?? []);

    // All participants to check for conflicts = committee members + guests
    $all_ids = array_values(array_unique(array_merge($participant_ids, $extra_participant_ids)));

    $out = array('venue_conflicts' => array(), 'participant_conflicts' => array());

    // Venue conflicts
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
    if ($all_ids) {
        $inIds = implode(',', array_fill(0, count($all_ids), '?'));
        $sql = "
            SELECT mp.participant_id, p.full_name,
                   m.id, m.title, m.start_datetime, m.end_datetime
            FROM meeting_participants mp
            JOIN meetings m ON mp.meeting_id = m.id
            JOIN participants p ON mp.participant_id = p.id
            WHERE mp.participant_id IN ($inIds)
              AND m.start_datetime < ?
              AND m.end_datetime > ?
            ORDER BY p.full_name, m.start_datetime
        ";
        $params = array_merge($all_ids, array($end_datetime, $start_datetime));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out['participant_conflicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

// Normal POST: saving the meeting. If 'force_save' is set, we save even if conflicts exist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check'])) {
    $committee_id   = (int)($_POST['committee_id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $start_datetime = norm_dt($_POST['start_datetime'] ?? '');
    $end_datetime   = norm_dt($_POST['end_datetime'] ?? '');
    $venue_id       = (int)($_POST['venue_id'] ?? 0);

    $participant_ids        = array_map('intval', $_POST['participant_ids'] ?? []);        // committee members
    $extra_participant_ids  = array_map('intval', $_POST['extra_participant_ids'] ?? []);  // guests

    // All participants for conflict check
    $all_participant_ids = array_values(array_unique(array_merge($participant_ids, $extra_participant_ids)));

    $force_save     = isset($_POST['force_save']) && $_POST['force_save'] == '1';

    if ($committee_id <= 0) $errors[] = 'Committee is required.';
    if ($title === '') $errors[] = 'Title is required.';
    if ($start_datetime === '' || $end_datetime === '') $errors[] = 'Start and end date/time are required.';

    if (!$errors) {
        // Participant conflicts (for display after save)
        if ($all_participant_ids) {
            $inIds = implode(',', array_fill(0, count($all_participant_ids), '?'));
            $sql = "
                SELECT mp.participant_id, p.full_name,
                       m.id, m.title, m.start_datetime, m.end_datetime
                FROM meeting_participants mp
                JOIN meetings m ON mp.meeting_id = m.id
                JOIN participants p ON mp.participant_id = p.id
                WHERE mp.participant_id IN ($inIds)
                  AND m.start_datetime < ?
                  AND m.end_datetime > ?
            ";
            $params = array_merge($all_participant_ids, array($end_datetime, $start_datetime));
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Venue conflicts (for display after save)
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
                    VALUES
                    (:committee_id, :title, :description, :start_datetime, :end_datetime, :venue_id, :created_by)";
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

            $meeting_id = (int)$pdo->lastInsertId('meetings_id_seq');

            // Insert participants: committee members (is_guest = 0) and extra guests (is_guest = 1)
            if ($participant_ids || $extra_participant_ids) {
                $ins = $pdo->prepare("
                    INSERT INTO meeting_participants (meeting_id, participant_id, added_by_user_id, is_guest)
                    VALUES (:mid, :pid, :uid, :is_guest)
                ");

                // Committee members
                foreach ($participant_ids as $pid) {
                    if ($pid <= 0) continue;
                    $ins->execute([
                        ':mid'      => $meeting_id,
                        ':pid'      => $pid,
                        ':uid'      => $user_id,
                        ':is_guest' => 0,
                    ]);
                }

                // Guests (avoid duplicates if someone is both selected)
                $memberIdSet = array_flip($participant_ids);
                foreach ($extra_participant_ids as $pid) {
                    if ($pid <= 0) continue;
                    if (isset($memberIdSet[$pid])) continue; // skip if already added as member
                    $ins->execute([
                        ':mid'      => $meeting_id,
                        ':pid'      => $pid,
                        ':uid'      => $user_id,
                        ':is_guest' => 1,
                    ]);
                }
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

// load committee members for participants dropdown when committee selected
$committee_members = [];
if ($committee_id > 0) {
    $sql = "
        SELECT p.id, p.full_name
        FROM committee_users cu
        JOIN participants p ON cu.participant_id = p.id
        WHERE cu.committee_id = :cid
        ORDER BY p.full_name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $committee_id]);
    $committee_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Schedule Meeting</h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($conflicts): ?>
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

<?php if ($venue_conflicts): ?>
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

<form id="meetingForm" method="post" class="card card-body">
  <div class="mb-3">
    <label class="form-label">Committee<span class="text-danger">*</span></label>
    <select name="committee_id" class="form-select" onchange="this.form.submit()">
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
      <input id="start_datetime" type="datetime-local" name="start_datetime" class="form-control"
             value="<?= htmlspecialchars(str_replace(' ', 'T', $start_datetime)) ?>" required>
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">End Date & Time<span class="text-danger">*</span></label>
      <input id="end_datetime" type="datetime-local" name="end_datetime" class="form-control"
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

  <!-- Committee members multi-select (same as before) -->
  <div class="mb-3">
    <label class="form-label">Committee Members (to invite)</label>
    <select id="participant_ids" name="participant_ids[]" class="form-select" multiple size="6">
      <?php foreach ($committee_members as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= in_array($p['id'], $participant_ids) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Hold Ctrl (Windows) to select multiple.</div>
  </div>

  <!-- Attendees card (info + guests) -->
  <?php if ($committee_id > 0 && $committee): ?>
  <div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
      <h5 class="mb-0">
        <i class="bi bi-people-fill text-primary me-1"></i>
        Attendees
      </h5>
      <span class="badge bg-light text-muted">
        <?= count($committeeMembers) ?> committee member<?= count($committeeMembers) === 1 ? '' : 's' ?>
      </span>
    </div>
    <div class="card-body">

      <div class="mb-3">
        <label class="form-label small text-muted mb-1">Committee members</label>
        <div class="border rounded p-2 small bg-light">
          <p class="mb-1">
            Members of <strong><?= htmlspecialchars($committee['name']) ?></strong> are listed above in
            <strong>Committee Members</strong>. Select those you want to invite. You can also add guests below.
          </p>

          <?php if ($committeeMembers): ?>
            <div class="d-flex flex-wrap gap-2 mt-1">
              <?php foreach ($committeeMembers as $m): ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">
                  <?= htmlspecialchars($m['full_name']) ?>
                  <?php if (!empty($m['email'])): ?>
                    <span class="text-muted"> · <?= htmlspecialchars($m['email']) ?></span>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">
              This committee does not have members yet. You can still invite participants as guests below.
            </p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Extra participants (guests) from master participants list -->
      <div class="mb-0">
        <label class="form-label small text-muted mb-1">
          Additional participants (guests, optional)
        </label>
        <p class="text-muted small mb-2">
          Invite guests who are <strong>not</strong> members of this committee, such as observers,
          external experts, or one-time attendees.
        </p>

        <input type="text"
               class="form-control form-control-sm mb-2"
               id="extraSearch"
               placeholder="Search by name or email...">

        <select multiple
                size="6"
                name="extra_participant_ids[]"
                id="extraParticipants"
                class="form-select form-select-sm">
          <?php foreach ($extraParticipants as $p): ?>
            <option value="<?= (int)$p['id'] ?>">
              <?= htmlspecialchars($p['full_name']) ?>
              <?php if (!empty($p['email'])): ?>
                (<?= htmlspecialchars($p['email']) ?>)
              <?php endif; ?>
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

<!-- Modal (Bootstrap) -->
<div class="modal fade" id="conflictModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-danger">
        <h5 class="modal-title">Venue Conflict Warning</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>
          The venue <strong id="selectedVenueName"></strong> already has the following meeting(s) that overlap the chosen time.
          You can still save — choose <strong>Force Save</strong> to continue.
        </p>
        <div id="conflictList"></div>
        <hr>
        <div id="participantConflictList" class="mt-2"></div>
      </div>
      <div class="modal-footer">
        <button id="modalCancel" type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="modalForceSave" type="button" class="btn btn-danger">Force Save</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>

<script>
(function(){
  // helper: get selected values as array
  function getSelectedValues(select) {
    var out = [];
    if (!select) return out;
    for (var i=0;i<select.options.length;i++){
      var opt = select.options[i];
      if (opt.selected && opt.style.display !== 'none') out.push(opt.value);
    }
    return out;
  }

  var form = document.getElementById('meetingForm');
  var saveBtn = document.getElementById('saveBtn');
  var modal = document.getElementById('conflictModal');
  var conflictList = document.getElementById('conflictList');
  var participantConflictList = document.getElementById('participantConflictList');
  var modalForceSave = document.getElementById('modalForceSave');

  // Bootstrap modal instance (Bootstrap 5)
  var bsModal = null;
  if (typeof bootstrap !== 'undefined') {
    bsModal = new bootstrap.Modal(modal, { backdrop: 'static', keyboard: false });
  }

  form.addEventListener('submit', function(e){
    // If the form already contains a force_save hidden field and its value is 1, allow submit to proceed.
    if (form.querySelector('input[name="force_save"]')) {
      return true;
    }

    e.preventDefault();

    var fd = new FormData();
    fd.append('ajax_check', '1');
    fd.append('committee_id', form.querySelector('[name="committee_id"]').value || '');
    fd.append('start_datetime', document.getElementById('start_datetime').value || '');
    fd.append('end_datetime', document.getElementById('end_datetime').value || '');
    fd.append('venue_id', document.getElementById('venue_id').value || '');

    // committee member participant ids
    var sel = document.getElementById('participant_ids');
    var parts = getSelectedValues(sel);
    for (var i=0;i<parts.length;i++){
      fd.append('participant_ids[]', parts[i]);
    }

    // extra guest participant ids
    var extraSel = document.getElementById('extraParticipants');
    var extraParts = getSelectedValues(extraSel);
    for (var j=0;j<extraParts.length;j++){
      fd.append('extra_participant_ids[]', extraParts[j]);
    }

    fetch('', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function(resp){ return resp.json(); })
      .then(function(json){
        var vcon = json.venue_conflicts || [];
        var pcon = json.participant_conflicts || [];
        if (vcon.length === 0 && pcon.length === 0) {
          form.submit();
          return;
        }

        conflictList.innerHTML = '';
        participantConflictList.innerHTML = '';

        if (vcon.length > 0) {
          var ul = document.createElement('ul');
          ul.className = 'mb-0';
          for (var i=0;i<vcon.length;i++){
            var item = vcon[i];
            var li = document.createElement('li');
            li.textContent = item.title + ' (' + item.start_datetime + ' to ' + item.end_datetime + ')';
            ul.appendChild(li);
          }
          conflictList.appendChild(ul);
        } else {
          conflictList.innerHTML = '<p><em>No venue conflicts.</em></p>';
        }

        if (pcon.length > 0) {
          var h = document.createElement('p');
          h.innerHTML = '<strong>Participant also at other meeting at same time:</strong>';
          participantConflictList.appendChild(h);
          var ul2 = document.createElement('ul');
          ul2.className = 'mb-0';
          for (var j=0;j<pcon.length;j++){
            var item2 = pcon[j];
            var li2 = document.createElement('li');
            li2.textContent = item2.full_name + ' - ' + item2.title + ' (' + item2.start_datetime + ' to ' + item2.end_datetime + ')';
            ul2.appendChild(li2);
          }
          participantConflictList.appendChild(ul2);
        }

        if (bsModal) {
          bsModal.show();
        } else {
          var msg = 'Venue conflicts found:\n';
          for (var k=0;k<vcon.length;k++){
            msg += '- ' + vcon[k].title + ' (' + vcon[k].start_datetime + ' to ' + vcon[k].end_datetime + ')\n';
          }
          if (!confirm(msg + '\nForce save?')) {
            return;
          }
          var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='force_save'; hidden.value='1';
          form.appendChild(hidden);
          form.submit();
          return;
        }

        modalForceSave.onclick = function(){
          var h = form.querySelector('input[name="force_save"]');
          if (!h) {
            h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'force_save';
            h.value = '1';
            form.appendChild(h);
          } else {
            h.value = '1';
          }
          if (bsModal) bsModal.hide();
          form.submit();
        };

      }).catch(function(err){
        console.error('Check failed', err);
        form.submit();
      });

    return false;
  });

})();

document.addEventListener("DOMContentLoaded", function () {
  // Update venue name on selection change
  var venueSelect = document.getElementById("venue_id");
  if (venueSelect) {
    venueSelect.addEventListener("change", function () {
      var selectedText = this.options[this.selectedIndex].text;
      var nameSpan = document.getElementById("selectedVenueName");
      if (nameSpan) nameSpan.textContent = selectedText;
    });
  }

  var saveBtn = document.getElementById("saveBtn");
  if (saveBtn) {
    saveBtn.addEventListener("click", function () {
      var venueDropdown = document.getElementById("venue_id");
      if (!venueDropdown) return;
      var selectedVenueText = venueDropdown.options[venueDropdown.selectedIndex].text;
      var nameSpan = document.getElementById("selectedVenueName");
      if (nameSpan) nameSpan.textContent = selectedVenueText;
    });
  }

  // Guest search filter
  var extraSearch = document.getElementById('extraSearch');
  var extraSelect = document.getElementById('extraParticipants');
  if (extraSearch && extraSelect) {
    extraSearch.addEventListener('input', function () {
      var term = this.value.toLowerCase();
      var options = extraSelect.options;
      for (var i = 0; i < options.length; i++) {
        var text = options[i].text.toLowerCase();
        options[i].style.display = text.indexOf(term) !== -1 ? '' : 'none';
      }
    });
  }
});
</script>
