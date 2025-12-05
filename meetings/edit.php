<?php
// mms/meetings/edit.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo   = getPDO();
$user  = currentUser();
$user_id = $user['id'] ?? null;

$isSuper = isSuperAdmin($user);

// Meeting id
$meeting_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($meeting_id <= 0) {
    flash_set('error', 'Invalid meeting ID.');
    header('Location: list.php');
    exit;
}

// Load meeting
$stmt = $pdo->prepare("
    SELECT *
    FROM meetings
    WHERE id = :id
");
$stmt->execute([':id' => $meeting_id]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    flash_set('error', 'Meeting not found.');
    header('Location: list.php');
    exit;
}

// Permission check
if (!$isSuper) {
    $allowedCommittees = getUserAdminCommitteeIds($pdo, $user);
    if (!in_array($meeting['committee_id'], $allowedCommittees)) {
        http_response_code(403);
        echo "Nice try boss, but you can't edit someone else's meeting.";
        exit;
    }
}

// Reuse the committee_id logic from add.php
$committee_id = (int)($_POST['committee_id'] ?? $_GET['committee_id'] ?? $meeting['committee_id']);

// Load lookups
$committees = $pdo->query("SELECT id, name FROM committees ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$venues     = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load committee members
$committeeMembers = [];
if ($committee_id > 0) {
    $stmt = $pdo->prepare("
        SELECT cu.participant_id, p.full_name, p.email
        FROM committee_users cu
        JOIN participants p ON p.id = cu.participant_id
        WHERE cu.committee_id = :cid
        ORDER BY p.full_name
    ");
    $stmt->execute([':cid' => $committee_id]);
    $committeeMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Extra participants
    $stmt = $pdo->prepare("
        SELECT p.id, p.full_name, p.email
        FROM participants p
        WHERE p.id NOT IN (
            SELECT participant_id
            FROM committee_users
            WHERE committee_id = :cid
        )
        ORDER BY p.full_name
    ");
    $stmt->execute([':cid' => $committee_id]);
    $extraParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $committeeMembers = [];
    $extraParticipants = [];
}

// Now load existing selected participants
$stmt = $pdo->prepare("
    SELECT participant_id, is_guest
    FROM meeting_participants
    WHERE meeting_id = :mid
");
$stmt->execute([':mid' => $meeting_id]);
$existingParticipants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$participant_ids = [];
$extra_participant_ids = [];

foreach ($existingParticipants as $ep) {
    if ($ep['is_guest'] == 1) {
        $extra_participant_ids[] = (int)$ep['participant_id'];
    } else {
        $participant_ids[] = (int)$ep['participant_id'];
    }
}

// normalize datetime-local input
function norm_dt($s) {
    return str_replace('T', ' ', trim($s));
}

// Errors
$errors = [];
$venue_conflicts = [];
$participant_conflicts = [];

// POST update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check'])) {

    $committee_id   = (int)$_POST['committee_id'];
    $title          = trim($_POST['title']);
    $description    = trim($_POST['description'] ?? '');
    $start_datetime = norm_dt($_POST['start_datetime']);
    $end_datetime   = norm_dt($_POST['end_datetime']);
    $venue_id       = (int)($_POST['venue_id'] ?? 0);

    $participant_ids        = array_map('intval', $_POST['participant_ids'] ?? []);
    $extra_participant_ids  = array_map('intval', $_POST['extra_participant_ids'] ?? []);

    if (!$committee_id) $errors[] = 'Committee is required.';
    if ($title === '') $errors[] = 'Title is required.';
    if ($start_datetime === '' || $end_datetime === '') $errors[] = 'Start and end times are required.';

    // If user is not superadmin, ensure committee_id is valid
    if (!$isSuper) {
        $allowed = getUserAdminCommitteeIds($pdo, $user);
        if (!in_array($committee_id, $allowed)) {
            $errors[] = "You can't move this meeting to a committee you don't control, pal.";
        }
    }

    // Conflict warnings unless force_save
    $force_save = isset($_POST['force_save']) && $_POST['force_save'] == '1';

    if (!$errors) {

        // Check venue conflicts (excluding this same meeting)
        if ($venue_id > 0) {
            $stmt = $pdo->prepare("
                SELECT id, title, start_datetime, end_datetime
                FROM meetings
                WHERE venue_id = ?
                  AND id <> ?
                  AND start_datetime < ?
                  AND end_datetime > ?
            ");
            $stmt->execute([$venue_id, $meeting_id, $end_datetime, $start_datetime]);
            $venue_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Participant conflicts
        $all_ids = array_values(array_unique(array_merge($participant_ids, $extra_participant_ids)));
        if ($all_ids) {
            $in = implode(',', array_fill(0, count($all_ids), '?'));
            $params = array_merge($all_ids, [$end_datetime, $start_datetime, $meeting_id]);

            $stmt = $pdo->prepare("
                SELECT mp.participant_id, p.full_name,
                       m.id, m.title, m.start_datetime, m.end_datetime
                FROM meeting_participants mp
                JOIN meetings m ON m.id = mp.meeting_id
                JOIN participants p ON p.id = mp.participant_id
                WHERE mp.participant_id IN ($in)
                  AND m.start_datetime < ?
                  AND m.end_datetime > ?
                  AND m.id <> ?
            ");
            $stmt->execute($params);
            $participant_conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (($venue_conflicts || $participant_conflicts) && !$force_save) {
            // Tell frontend to open modal again
        } else {
            // Save update
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    UPDATE meetings
                    SET committee_id = :committee,
                        title = :title,
                        description = :descr,
                        start_datetime = :start_dt,
                        end_datetime = :end_dt,
                        venue_id = :venue
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':committee' => $committee_id,
                    ':title' => $title,
                    ':descr' => $description ?: null,
                    ':start_dt' => $start_datetime,
                    ':end_dt' => $end_datetime,
                    ':venue' => $venue_id ?: null,
                    ':id' => $meeting_id
                ]);

                // Delete old participants
                $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?")->execute([$meeting_id]);

                // Insert new participants
                $ins = $pdo->prepare("
                    INSERT INTO meeting_participants (meeting_id, participant_id, added_by_user_id, is_guest)
                    VALUES (:mid, :pid, :uid, :guest)
                ");

                foreach ($participant_ids as $pid) {
                    $ins->execute([
                        ':mid' => $meeting_id,
                        ':pid' => $pid,
                        ':uid' => $user_id,
                        ':guest' => 0
                    ]);
                }

                $memberSet = array_flip($participant_ids);
                foreach ($extra_participant_ids as $pid) {
                    if (isset($memberSet[$pid])) continue;
                    $ins->execute([
                        ':mid' => $meeting_id,
                        ':pid' => $pid,
                        ':uid' => $user_id,
                        ':guest' => 1
                    ]);
                }

                $pdo->commit();

                flash_set('success', 'Meeting updated successfully.');
                header('Location: list.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }
}

// Fill form if GET
$title          = $title ?? $meeting['title'];
$description    = $description ?? $meeting['description'];
$start_datetime = $start_datetime ?? $meeting['start_datetime'];
$end_datetime   = $end_datetime ?? $meeting['end_datetime'];
$venue_id       = $venue_id ?? $meeting['venue_id'];

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Edit Meeting</h3>
  <a href="list.php" class="btn btn-outline-secondary btn-sm">Back</a>
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

<form id="editForm" method="post" class="card card-body">
  <input type="hidden" name="id" value="<?= $meeting_id ?>">

  <div class="mb-3">
    <label class="form-label">Committee</label>
    <select name="committee_id" class="form-select" onchange="this.form.submit()">
      <?php foreach ($committees as $c): ?>
        <option value="<?= $c['id'] ?>" <?= ($committee_id == $c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Title</label>
    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($title) ?>">
  </div>

  <div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control"><?= htmlspecialchars($description) ?></textarea>
  </div>

  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Start</label>
      <input type="datetime-local" name="start_datetime" class="form-control"
        value="<?= htmlspecialchars(str_replace(' ', 'T', $start_datetime)) ?>">
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">End</label>
      <input type="datetime-local" name="end_datetime" class="form-control"
        value="<?= htmlspecialchars(str_replace(' ', 'T', $end_datetime)) ?>">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label">Venue</label>
    <select name="venue_id" class="form-select">
      <option value="">-- None --</option>
      <?php foreach ($venues as $v): ?>
        <option value="<?= $v['id'] ?>" <?= ($venue_id == $v['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($v['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Committee Members</label>
    <select name="participant_ids[]" class="form-select" multiple size="6">
      <?php foreach ($committeeMembers as $m): ?>
        <option value="<?= $m['participant_id'] ?>"
          <?= in_array($m['participant_id'], $participant_ids) ? 'selected' : '' ?>>
          <?= htmlspecialchars($m['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Guests</label>
    <select name="extra_participant_ids[]" class="form-select" multiple size="6">
      <?php foreach ($extraParticipants as $p): ?>
        <option value="<?= $p['id'] ?>"
          <?= in_array($p['id'], $extra_participant_ids) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['full_name']) ?> (<?= htmlspecialchars($p['email']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <button class="btn btn-primary">Save Changes</button>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
