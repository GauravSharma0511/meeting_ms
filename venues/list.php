<?php
// mms/meetings/add.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo = getPDO();
$user = $_SESSION['user'] ?? null;
$user_id = $user['id'] ?? null;

// Lookup committees
$committees = $pdo->query("SELECT id, name FROM committees ORDER BY name ASC")
                  ->fetchAll(PDO::FETCH_ASSOC);

// Lookup venues
$venues = $pdo->query("SELECT id, name FROM venues ORDER BY name ASC")
              ->fetchAll(PDO::FETCH_ASSOC);

$committee_id = isset($_GET['committee_id']) ? (int)$_GET['committee_id'] : 0;

$title = '';
$description = '';
$start_datetime = '';
$end_datetime = '';
$venue_id = '';
$participant_ids = [];
$errors = [];
$conflicts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $committee_id   = (int)($_POST['committee_id'] ?? 0);
    $title          = trim($_POST['title'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $start_datetime = trim($_POST['start_datetime'] ?? '');
    $end_datetime   = trim($_POST['end_datetime'] ?? '');
    $venue_id       = (int)($_POST['venue_id'] ?? 0);
    $participant_ids = array_map('intval', $_POST['participant_ids'] ?? []);

    if ($committee_id <= 0) $errors[] = 'Committee is required.';
    if ($title === '') $errors[] = 'Title is required.';
    if ($start_datetime === '' || $end_datetime === '') {
        $errors[] = 'Start and end date/time are required.';
    }

    if (!$errors) {
        // basic conflict check for each participant at same time
        if ($participant_ids) {
            $inIds = implode(',', array_fill(0, count($participant_ids), '?'));
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
            $params = $participant_ids;
            $params[] = $end_datetime;
            $params[] = $start_datetime;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // For now we only warn; still allow scheduling
        if (!$errors) {
            $pdo->beginTransaction();
            try {
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

                if ($participant_ids) {
                    $ins = $pdo->prepare("
                        INSERT INTO meeting_participants (meeting_id, participant_id, added_by_user_id)
                        VALUES (:mid, :pid, :uid)
                    ");
                    foreach ($participant_ids as $pid) {
                        $ins->execute([
                            ':mid' => $meeting_id,
                            ':pid' => $pid,
                            ':uid' => $user_id,
                        ]);
                    }
                }

                $pdo->commit();

                if ($conflicts) {
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

<form method="post" class="card card-body">
  <div class="mb-3">
    <label class="form-label">Committee<span class="text-danger">*</span></label>
    <select name="committee_id" class="form-select" onchange="this.form.submit()">
      <option value="">-- Select Committee --</option>
      <?php foreach ($committees as $c): ?>
        <option value="<?= (int)$c['id'] ?>"
          <?= ($committee_id == $c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Changing committee will reload page to update member list.</div>
  </div>

  <div class="mb-3">
    <label class="form-label">Title<span class="text-danger">*</span></label>
    <input type="text" name="title" class="form-control"
           value="<?= htmlspecialchars($title) ?>" required>
  </div>

  <div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description) ?></textarea>
  </div>

  <div class="row">
    <div class="col-md-6 mb-3">
      <label class="form-label">Start Date & Time<span class="text-danger">*</span></label>
      <input type="datetime-local" name="start_datetime" class="form-control"
             value="<?= htmlspecialchars(str_replace(' ', 'T', $start_datetime)) ?>">
    </div>
    <div class="col-md-6 mb-3">
      <label class="form-label">End Date & Time<span class="text-danger">*</span></label>
      <input type="datetime-local" name="end_datetime" class="form-control"
             value="<?= htmlspecialchars(str_replace(' ', 'T', $end_datetime)) ?>">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label">Venue</label>
    <select name="venue_id" class="form-select">
      <option value="">-- Select Venue --</option>
      <?php foreach ($venues as $v): ?>
        <option value="<?= (int)$v['id'] ?>"
          <?= ($venue_id == $v['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($v['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-3">
    <label class="form-label">Participants</label>
    <select name="participant_ids[]" class="form-select" multiple size="6">
      <?php foreach ($committee_members as $p): ?>
        <option value="<?= (int)$p['id'] ?>"
          <?= in_array($p['id'], $participant_ids, true) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['full_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Hold Ctrl (Windows) to select multiple.</div>
  </div>

  <button type="submit" class="btn btn-success">
    <i class="bi bi-calendar-plus"></i> Save Meeting
  </button>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
