<?php
// mms/meetings/list.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = currentUser();

$isSuper = isSuperAdmin($user);

// Optional filter by committee_id (from query string)
$committee_id = isset($_GET['committee_id']) ? (int)$_GET['committee_id'] : 0;

// -------- LOAD MEETINGS BASED ON ROLE --------
if ($isSuper) {
    // Superadmin: all meetings, optionally filtered by committee_id
    $sql = "
        SELECT m.id, m.title, m.start_datetime, m.end_datetime,
               m.status,
               c.name AS committee_name,
               v.name AS venue_name
        FROM meetings m
        JOIN committees c ON m.committee_id = c.id
        LEFT JOIN venues v ON m.venue_id = v.id
    ";
    $params = [];

    if ($committee_id > 0) {
        $sql .= " WHERE m.committee_id = :cid";
        $params[':cid'] = $committee_id;
    }

    $sql .= " ORDER BY m.start_datetime DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // Committee admin: restrict to the committees where this user is admin
    $committeeIds = getUserAdminCommitteeIds($pdo, $user);

    if (!$committeeIds) {
        $meetings = [];
    } else {
        $params = [];
        $whereParts = [];

        // If URL filter is given, intersect it with allowed committees
        if ($committee_id > 0) {
            if (!in_array($committee_id, $committeeIds, true)) {
                // User tried to filter for a committee they don't admin -> no results
                $meetings = [];
            } else {
                $committeeIds = [$committee_id];
            }
        }

        if (!isset($meetings)) {
            // Build placeholders for IN (...)
            $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));

            $sql = "
                SELECT m.id, m.title, m.start_datetime, m.end_datetime,
                       m.status,
                       c.name AS committee_name,
                       v.name AS venue_name
                FROM meetings m
                JOIN committees c ON m.committee_id = c.id
                LEFT JOIN venues v ON m.venue_id = v.id
                WHERE m.committee_id IN ($placeholders)
                ORDER BY m.start_datetime DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($committeeIds);
            $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3>Meetings</h3>

  <?php
  $addUrl = 'add.php';
  if ($committee_id > 0) {
      $addUrl .= '?committee_id=' . (int)$committee_id;
  }
?>
<?php if ($isSuper || isCommitteeAdmin($pdo, $user)): ?>
  <a href="<?= htmlspecialchars($addUrl) ?>" class="btn btn-success">
    <i class="bi bi-calendar-plus"></i> Schedule Meeting
  </a>
<?php endif; ?>
</div>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Title</th>
            <th>Committee</th>
            <th>Venue</th>
            <th>Start</th>
            <th>End</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($meetings)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted">
              No meetings scheduled.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($meetings as $m): ?>
            <tr>
              <td><?= (int)$m['id'] ?></td>
              <td><?= htmlspecialchars($m['title']) ?></td>
              <td><?= htmlspecialchars($m['committee_name']) ?></td>
              <td><?= htmlspecialchars($m['venue_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($m['start_datetime']) ?></td>
              <td><?= htmlspecialchars($m['end_datetime']) ?></td>
              <td><?= htmlspecialchars($m['status']) ?></td>
              <td>
                <a href="view.php?id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-secondary">
                  View
                </a>
                <!-- later: add Edit/Delete here with permission checks -->
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
