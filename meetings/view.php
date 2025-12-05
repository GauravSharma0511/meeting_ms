<?php
// mms/meetings/view.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();
$pdo  = getPDO();
$user = currentUser();

// Get meeting id from query string
$meetingId = (int)($_GET['id'] ?? 0);
if ($meetingId <= 0) {
    header('Location: /mms/meetings/list.php');
    exit;
}

// Load meeting with committee & venue details
$stmt = $pdo->prepare("
    SELECT 
        m.*,
        c.name AS committee_name,
        v.name AS venue_name
    FROM meetings m
    LEFT JOIN committees c ON c.id = m.committee_id
    LEFT JOIN venues v ON v.id = m.venue_id
    WHERE m.id = :id
");
$stmt->execute([':id' => $meetingId]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    header('Location: /mms/meetings/list.php');
    exit;
}

// Enforce permissions: superadmin or committee-admin for this committee
$committeeId = (int)$meeting['committee_id'];
requireCommitteeAdminFor($pdo, $committeeId, $user);

// Permission flags (for buttons)
$isSuper = isSuperAdmin($user);
$adminCommitteeIds = getUserAdminCommitteeIds($pdo, $user);
$canEditDelete = $isSuper || in_array($committeeId, $adminCommitteeIds, true);

// Load participants for this meeting
$partsStmt = $pdo->prepare("
    SELECT mp.*, p.full_name, p.email
    FROM meeting_participants mp
    LEFT JOIN participants p ON p.id = mp.participant_id
    WHERE mp.meeting_id = :id
    ORDER BY p.full_name
");
$partsStmt->execute([':id' => $meetingId]);
$participants = $partsStmt->fetchAll(PDO::FETCH_ASSOC);

// Status badge helper
$status = strtolower(trim($meeting['status'] ?? ''));
$statusClass = 'bg-secondary';

if ($status === 'scheduled' || $status === 'upcoming') {
    $statusClass = 'bg-primary';
} elseif ($status === 'completed') {
    $statusClass = 'bg-success';
} elseif ($status === 'cancelled' || $status === 'canceled') {
    $statusClass = 'bg-danger';
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h3 class="mb-1">
      <i class="bi bi-calendar-event me-1 text-primary"></i>
      <?= htmlspecialchars($meeting['title']) ?>
    </h3>
    <div class="text-muted small">
      <span class="me-3">
        <i class="bi bi-people-fill me-1"></i>
        Committee:
        <strong><?= htmlspecialchars($meeting['committee_name'] ?? 'N/A') ?></strong>
      </span>

      <span class="me-3">
        <i class="bi bi-geo-alt-fill me-1"></i>
        Venue:
        <strong><?= htmlspecialchars($meeting['venue_name'] ?? 'N/A') ?></strong>
      </span>

      <span class="badge <?= $statusClass ?> ms-2">
        <?= htmlspecialchars($meeting['status'] ?? 'Scheduled') ?>
      </span>
    </div>
  </div>

  <div class="text-md-end mt-3 mt-md-0">
    <a href="/mms/meetings/list.php" class="btn btn-sm btn-outline-secondary mb-1">
      &laquo; Back to Meetings
    </a>

    <?php if ($canEditDelete): ?>
      <a href="/mms/meetings/edit.php?id=<?= (int)$meetingId ?>"
         class="btn btn-sm btn-outline-primary mb-1">
        <i class="bi bi-pencil-square"></i> Edit
      </a>

      <form action="/mms/meetings/delete.php" method="post" class="d-inline"
            onsubmit="return confirm('Are you sure you want to delete this meeting?');">
        <input type="hidden" name="id" value="<?= (int)$meetingId ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger mb-1">
          <i class="bi bi-trash"></i> Delete
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-lg-8 mb-3">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-info-circle me-1 text-primary"></i>
          Meeting Details
        </h5>
      </div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">Start</dt>
          <dd class="col-sm-9">
            <?= htmlspecialchars($meeting['start_datetime']) ?>
          </dd>

          <dt class="col-sm-3">End</dt>
          <dd class="col-sm-9">
            <?= htmlspecialchars($meeting['end_datetime']) ?>
          </dd>

          <?php if (!empty($meeting['venue_name'])): ?>
            <dt class="col-sm-3">Venue</dt>
            <dd class="col-sm-9">
              <?= htmlspecialchars($meeting['venue_name']) ?>
            </dd>
          <?php endif; ?>

          <?php if (!empty($meeting['description'])): ?>
            <dt class="col-sm-3">Description</dt>
            <dd class="col-sm-9">
              <div class="text-muted">
                <?= nl2br(htmlspecialchars($meeting['description'])) ?>
              </div>
            </dd>
          <?php endif; ?>
        </dl>

        <?php if (empty($meeting['description'])): ?>
          <p class="text-muted mb-0">
            <em>No description provided for this meeting.</em>
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4 mb-3">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
          <i class="bi bi-people me-1 text-primary"></i>
          Participants
        </h5>
        <span class="badge bg-light text-muted">
          <?= count($participants) ?> participant<?= count($participants) === 1 ? '' : 's' ?>
        </span>
      </div>
      <div class="card-body">
        <?php if (empty($participants)): ?>
          <p class="text-muted mb-0">
            No participants added yet.
          </p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($participants as $p): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                <div>
                  <div class="fw-semibold">
                    <?= htmlspecialchars($p['full_name'] ?? 'Unknown') ?>
                  </div>
                  <?php if (!empty($p['email'])): ?>
                    <div class="small text-muted">
                      <?= htmlspecialchars($p['email']) ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="text-end">
                  <?php
                    $pStatus = trim($p['status'] ?? 'invited');
                    $pBadgeClass = 'bg-secondary';
                    if ($pStatus === 'confirmed') $pBadgeClass = 'bg-success';
                    elseif ($pStatus === 'declined') $pBadgeClass = 'bg-danger';
                  ?>
                  <span class="badge <?= $pBadgeClass ?> mb-1">
                    <?= htmlspecialchars($pStatus) ?>
                  </span>
                  <?php if (!empty($p['is_guest'])): ?>
                    <div class="small text-muted">
                      Guest
                    </div>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
