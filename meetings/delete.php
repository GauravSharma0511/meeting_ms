<?php
// mms/meetings/delete.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';

requireLogin();

$pdo  = getPDO();
$user = currentUser();

$isSuper = isSuperAdmin($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed";
    exit;
}

$meetingId = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($meetingId <= 0) {
    flash_set('success', 'Invalid meeting id.');
    header('Location: list.php');
    exit;
}

// Fetch meeting with its committee_id
$stmt = $pdo->prepare("
    SELECT id, committee_id
    FROM meetings
    WHERE id = :id
");
$stmt->execute([':id' => $meetingId]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    flash_set('success', 'Meeting not found.');
    header('Location: list.php');
    exit;
}

// Permission check
if (!$isSuper) {
    // Get committees where this user is admin
    $committeeIds = getUserAdminCommitteeIds($pdo, $user);

    if (!$committeeIds || !in_array((int)$meeting['committee_id'], $committeeIds, true)) {
        http_response_code(403);
        echo "You are not allowed to delete this meeting.";
        exit;
    }
}

// At this point: either superadmin or committee admin for that meeting's committee

// If you have related tables (participants, invites, etc.) and no ON DELETE CASCADE,
// you should delete from those tables first.

$stmt = $pdo->prepare("DELETE FROM meetings WHERE id = :id");
$stmt->execute([':id' => $meetingId]);

flash_set('success', 'Meeting deleted successfully.');
header('Location: list.php');
exit;
