<?php
// mms/committees/add_member.php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = currentUser();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash_set('error', 'Invalid request method.');
    header('Location: /mms/committees/list.php');
    exit;
}

// CSRF - only if you already have some helper, otherwise skip
if (function_exists('csrf_token_is_valid')) {
    if (!csrf_token_is_valid($_POST['csrf'] ?? '')) {
        flash_set('error', 'Security token expired. Please try again.');
        header('Location: /mms/committees/list.php');
        exit;
    }
}

// Get committee id (from POST or fallback to GET)
$committeeId = (int)($_POST['committee_id'] ?? $_GET['committee_id'] ?? 0);
$participantId = (int)($_POST['participant_id'] ?? 0);

// New rule: from this endpoint we always add as MEMBER
$role = 'member';

if ($committeeId <= 0 || $participantId <= 0) {
    flash_set('error', 'Missing committee or participant information.');
    header('Location: /mms/committees/list.php');
    exit;
}

// Load committee to verify existence
$stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $committeeId]);
$committee = $stmt->fetch();

if (!$committee) {
    flash_set('error', 'Committee not found.');
    header('Location: /mms/committees/list.php');
    exit;
}

// Check permissions: superadmin OR committee admin for this committee
$isSuper = isSuperAdmin($user);
$adminCommittees = getUserAdminCommitteeIds($pdo, $user);
$canManageMembers = $isSuper || in_array($committeeId, $adminCommittees, true);

if (!$canManageMembers) {
    flash_set('error', 'You do not have permission to add members to this committee.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

// Make sure participant exists
$pstmt = $pdo->prepare("SELECT id, full_name FROM participants WHERE id = :id LIMIT 1");
$pstmt->execute([':id' => $participantId]);
$participant = $pstmt->fetch();

if (!$participant) {
    flash_set('error', 'Selected participant not found.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

// Check if this participant is already a member of this committee
$checkStmt = $pdo->prepare("
    SELECT id 
    FROM committee_users 
    WHERE committee_id = :cid AND participant_id = :pid
    LIMIT 1
");
$checkStmt->execute([
    ':cid' => $committeeId,
    ':pid' => $participantId,
]);

if ($checkStmt->fetch()) {
    flash_set('error', 'This participant is already a member of this committee.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

// Insert new member
$insertStmt = $pdo->prepare("
    INSERT INTO committee_users (committee_id, participant_id, role_in_committee)
    VALUES (:cid, :pid, :role)
");

$ok = $insertStmt->execute([
    ':cid'  => $committeeId,
    ':pid'  => $participantId,
    ':role' => $role,
]);

if ($ok) {
    flash_set('success', 'Member added successfully: ' . ($participant['full_name'] ?? ''));
} else {
    flash_set('error', 'Failed to add member. Please try again.');
}

// Always go back to the committee view page
header('Location: /mms/committees/view.php?id=' . $committeeId);
exit;
