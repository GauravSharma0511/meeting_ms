<?php
// mms/committees/list.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = currentUser();

$isSuper         = isSuperAdmin($user);
$adminCommittees = getUserAdminCommitteeIds($pdo, $user);

// ---------- POST: DELETE COMMITTEE (superadmin only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_committee'])) {

    if (!$isSuper) {
        http_response_code(403);
        flash_set('error', 'Only superadmin can delete committees.');
        header('Location: /mms/committees/list.php');
        exit;
    }

    // CSRF check if helper exists
    if (function_exists('csrf_token_is_valid')) {
        if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
            flash_set('error', 'Security token expired. Please try again.');
            header('Location: /mms/committees/list.php');
            exit;
        }
    }

    $committeeId = (int)($_POST['committee_id'] ?? 0);

    if ($committeeId <= 0) {
        flash_set('error', 'Invalid committee selected for deletion.');
        header('Location: /mms/committees/list.php');
        exit;
    }

    // Load to show friendly name in message
    $stmt = $pdo->prepare("SELECT id, name FROM committees WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $committeeId]);
    $committee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$committee) {
        flash_set('error', 'Committee not found or already deleted.');
        header('Location: /mms/committees/list.php');
        exit;
    }

    try {
        $del = $pdo->prepare("DELETE FROM committees WHERE id = :id");
        $del->execute([':id' => $committeeId]);

        flash_set('success', 'Committee deleted successfully: ' . $committee['name']);

    } catch (PDOException $e) {
        // Most likely foreign-key constraints (meetings, etc.)
        flash_set(
            'error',
            'Could not delete committee "' . $committee['name'] .
            '". There might be related meetings or records. (' . $e->getCode() . ')'
        );
    }

    header('Location: /mms/committees/list.php');
    exit;
}

// -------- Flash messages after any redirect --------
$success = flash_get('success');
$error   = flash_get('error');

// ---------- Load all committees ----------
$committees = $pdo->query("
    SELECT c.*
    FROM committees c
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$committeeIds = array_map(fn($c) => (int)$c['id'], $committees);

// ---------- Stats: meetings & members & next meeting ----------
$meetingStats      = []; // [committee_id => ['count'=>.., 'next'=>row]]
$memberCounts      = []; // [committee_id => count]
$upcomingMeetings  = []; // [committee_id => [rows...]]

if ($committeeIds) {
    $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));

    // Total meetings per committee
    $stmt = $pdo->prepare("
        SELECT committee_id, COUNT(*) AS total
        FROM meetings
        WHERE committee_id IN ($placeholders)
        GROUP BY committee_id
    ");
    $stmt->execute($committeeIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meetingStats[(int)$row['committee_id']]['count'] = (int)$row['total'];
    }

    // Total members per committee
    $stmtMem = $pdo->prepare("
        SELECT committee_id, COUNT(*) AS total
        FROM committee_users
        WHERE committee_id IN ($placeholders)
        GROUP BY committee_id
    ");
    $stmtMem->execute($committeeIds);
    foreach ($stmtMem->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $memberCounts[(int)$row['committee_id']] = (int)$row['total'];
    }

    // Upcoming meetings per committee
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

        // First upcoming meeting becomes the "next" meeting in summary
        if (!isset($meetingStats[$cid]['next'])) {
            $meetingStats[$cid]['next'] = $row;
        }
    }
}

include __DIR__ . '/../header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
  <div>
    <h2 class="mb-1">
      <i class="bi bi-diagram-3-fill text-primary me-1"></i>
      Committees
    </h2>
    <p class="text-muted mb-0 small">
      View all committees in the system.
      <?php if ($adminCommittees): ?>
        You are admin for
        <strong><?= count($adminCommittees) ?></strong>
        committee<?= count($adminCommittees) === 1 ? '' : 's' ?>.
      <?php endif; ?>
    </p>
  </div>

  <div class="mt-2 mt-md-0 d-flex gap-2">
    <?php if ($isSuper): ?>
      <a href="/mms/committees/add.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Add Committee
      </a>
      <a href="/mms/committees/add_admin.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person-gear me-1"></i> Manage Committee Heads
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$committees): ?>
  <div class="alert alert-info">
    No committees found.
    <?php if ($isSuper): ?>
      You can create one using the <strong>Add Committee</strong> button.
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($committees as $c):
        $cid = (int)$c['id'];
        $stats         = $meetingStats[$cid] ?? ['count' => 0];
        $countMeetings = $stats['count'] ?? 0;
        $next          = $stats['next'] ?? null;
        $membersCount  = $memberCounts[$cid] ?? 0;
        $isMyCommittee = in_array($cid, $adminCommittees, true);
        $canSchedule   = $isSuper || $isMyCommittee;
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
                <?php if (!empty($c['description'])): ?>
                  <p class="text-muted small mb-0">
                    <?= nl2br(htmlspecialchars(mb_strimwidth($c['description'], 0, 120, '…'))) ?>
                  </p>
                <?php else: ?>
                  <p class="text-muted small mb-0">No description provided.</p>
                <?php endif; ?>
              </div>
              <?php if ($isMyCommittee): ?>
                <span class="badge bg-success-subtle text-success-emphasis small">
                  <i class="bi bi-star-fill me-1"></i> My Committee
                </span>
              <?php endif; ?>
            </div>

            <!-- Stats row -->
            <div class="d-flex flex-wrap gap-3 mt-2 mb-3 small">
              <div>
                <span class="text-muted d-block">Meetings</span>
                <strong><?= $countMeetings ?></strong>
              </div>
              <div>
                <span class="text-muted d-block">Members</span>
                <strong><?= $membersCount ?></strong>
              </div>
              <div>
                <span class="text-muted d-block">Next meeting</span>
                <?php if ($next): ?>
                  <span>
                    <?= htmlspecialchars(date('d M Y H:i', strtotime($next['start_datetime']))) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">None</span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Footer actions -->
            <div class="mt-auto pt-2 border-top">
              <div class="d-flex flex-wrap gap-1 mt-2 ">

                <div class="btn-group btn-group-sm" role="group">
                  <a href="/mms/committees/view.php?id=<?= $cid ?>"
                     class="btn btn-outline-dark">
                    <i class="bi bi-people-fill me-1"></i> Members
                  </a>
                  <a href="/mms/meetings/list.php?committee_id=<?= $cid ?>"
                     class="btn btn-outline-primary">
                    <i class="bi bi-calendar-event me-1"></i> Meetings
                  </a>
                

                <?php if ($canSchedule): ?>
                  <a href="/mms/meetings/add.php?committee_id=<?= $cid ?>"
                     class="btn btn-sm btn-outline-success ms-auto">
                    <i class="bi bi-calendar-plus me-1"></i> Schedule
                  </a>
                      <?php if ($isSuper): ?>
                  <form method="post" class="ms-0 ms-md-2"
                        onsubmit="return confirm('Are you sure you want to delete this committee? This may also affect related meetings and members.');">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="delete_committee" value="1">
                    <input type="hidden" name="committee_id" value="<?= $cid ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                      <i class="bi bi-trash me-1"></i> Delete
                    </button>
                  </form>
                <?php endif; ?>
              </div>

                <?php endif; ?>

               

              </div>
            </div>

          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
