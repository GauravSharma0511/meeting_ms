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

// Load participants for this meeting
$partsStmt = $pdo->prepare("
    SELECT mp.*, p.full_name
    FROM meeting_participants mp
    LEFT JOIN participants p ON p.id = mp.participant_id
    WHERE mp.meeting_id = :id
");
$partsStmt->execute([':id' => $meetingId]);
$participants = $partsStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../header.php';
?>

<div class="row mb-3">
  <div class="col-md-8">
    <h3><?= htmlspecialchars($meeting['title']) ?></h3>
    <p class="text-muted mb-1">
      <strong>Committee:</strong>
      <?= htmlspecialchars($meeting['committee_name'] ?? 'N/A') ?>
      &nbsp; | &nbsp;
      <strong>Venue:</strong>
      <?= htmlspecialchars($meeting['venue_name'] ?? 'N/A') ?>
    </p>
    <p class="text-muted">
      <strong>Start:</strong>
      <?= htmlspecialchars($meeting['start_datetime']) ?>
      &nbsp; | &nbsp;
      <strong>End:</strong>
      <?= htmlspecialchars($meeting['end_datetime']) ?>
    </p>
  </div>
  <div class="col-md-4 text-md-end">
    <a href="/mms/meetings/list.php" class="btn btn-sm btn-outline-secondary mb-2">
      &laquo; Back to Meetings
    </a>
    <!-- Later: add Edit/Delete buttons here with proper permission checks -->
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <h5 class="card-title">Description</h5>
    <p class="card-text">
      <?= nl2br(htmlspecialchars($meeting['description'] ?? '')) ?>
    </p>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h5 class="card-title">Participants</h5>
    <?php if (empty($participants)): ?>
      <p class="text-muted mb-0">No participants added yet.</p>
    <?php else: ?>
      <ul class="list-group">
        <?php foreach ($participants as $p): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span><?= htmlspecialchars($p['full_name'] ?? 'Unknown') ?></span>
            <span class="badge bg-secondary">
              status: <?= htmlspecialchars($p['status'] ?? 'invited') ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
