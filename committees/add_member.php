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
    if (!csrf_token_is_valid(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        flash_set('error', 'Security token expired. Please try again.');
        header('Location: /mms/committees/list.php');
        exit;
    }
}

// Get committee id
$committeeId = (int)(isset($_POST['committee_id']) ? $_POST['committee_id'] : 0);

// Old flow: participant_id directly
$participantId = (int)(isset($_POST['participant_id']) ? $_POST['participant_id'] : 0);

// New flow: type + API/manual data
$participantType  = isset($_POST['participant_type']) ? trim($_POST['participant_type']) : ''; // judge / registry_officer / advocate / gov_officer
$externalSource   = isset($_POST['external_source']) ? trim($_POST['external_source']) : '';   // JUDGES_API / REGISTRY_API / ADVOCATES_API / MANUAL
$externalId       = isset($_POST['external_id']) ? trim($_POST['external_id']) : '';           // id from API (may be empty for MANUAL)
$fullName         = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$email            = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone            = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$designationTitle = isset($_POST['designation_title']) ? trim($_POST['designation_title']) : '';
$designationDescription = isset($_POST['designation_description']) ? trim($_POST['designation_description']) : ''; // NEW: for "add new designation" flow

// New rule: from this endpoint we always add as MEMBER
$role = 'member';

if ($committeeId <= 0) {
    flash_set('error', 'Missing committee information.');
    header('Location: /mms/committees/list.php');
    exit;
}

// Load committee to verify existence
$stmt = $pdo->prepare("SELECT * FROM committees WHERE id = :id LIMIT 1");
$stmt->execute(array(':id' => $committeeId));
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

/**
 * Helper: find or create designation_id by title (and optional description)
 * - If title exists, returns its id.
 * - If not, inserts new designation (with description if provided) and returns new id.
 */
function find_or_create_designation_id(PDO $pdo, $title, $description = '')
{
    if ($title === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM designations WHERE title = :title LIMIT 1");
    $stmt->execute(array(':title' => $title));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return (int)$row['id'];
    }

    $insert = $pdo->prepare("
        INSERT INTO designations (title, description)
        VALUES (:title, :description)
        RETURNING id
    ");
    $insert->execute(array(
        ':title'       => $title,
        ':description' => ($description !== '' ? $description : null),
    ));
    $new = $insert->fetch(PDO::FETCH_ASSOC);

    return $new ? (int)$new['id'] : null;
}

/**
 * Helper: generate a unique username from full name
 */
function generate_unique_username(PDO $pdo, $fullName)
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $fullName));
    $base = trim($base, '.');

    if ($base === '') {
        $base = 'user';
    }

    $username = $base;
    $counter  = 1;

    while (true) {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $check->execute(array(':u' => $username));
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            return $username;
        }

        $counter++;
        $username = $base . $counter;
    }
}

/**
 * Helper: find or create participant based on type/source/external_id or manual
 */
function find_or_create_participant(PDO $pdo, $fullName, $email, $phone, $designationId, $participantType, $externalSource, $externalId)
{
    // If we have externalId + source + type => try to reuse participant
    if ($participantType !== '' && $externalSource !== '' && $externalId !== '') {
        $stmt = $pdo->prepare("
            SELECT id 
            FROM participants
            WHERE participant_type = :ptype
              AND external_source = :src
              AND external_id = :eid
            LIMIT 1
        ");
        $stmt->execute(array(
            ':ptype' => $participantType,
            ':src'   => $externalSource,
            ':eid'   => $externalId,
        ));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return (int)$row['id'];
        }
    }

    // Insert new participant
    $insert = $pdo->prepare("
        INSERT INTO participants 
            (full_name, email, phone, designation_id, participant_type, external_source, external_id)
        VALUES 
            (:full_name, :email, :phone, :designation_id, :participant_type, :external_source, :external_id)
        RETURNING id
    ");

    $insert->execute(array(
        ':full_name'        => $fullName,
        ':email'            => $email !== '' ? $email : null,
        ':phone'            => $phone !== '' ? $phone : null,
        ':designation_id'   => $designationId,
        ':participant_type' => $participantType !== '' ? $participantType : null,
        ':external_source'  => $externalSource !== '' ? $externalSource : null,
        ':external_id'      => $externalId !== '' ? $externalId : null,
    ));

    $row = $insert->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return 0;
    }

    return (int)$row['id'];
}

/**
 * Helper: ensure there is a users row linked to this participant
 */
function ensure_user_for_participant(PDO $pdo, $participantId, $fullName, $email)
{
    if ($participantId <= 0) {
        return null;
    }

    // Check if already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE participant_id = :pid LIMIT 1");
    $stmt->execute(array(':pid' => $participantId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        return (int)$row['id'];
    }

    $username = generate_unique_username($pdo, $fullName);

    $insert = $pdo->prepare("
        INSERT INTO users (username, email, role, participant_id, full_name)
        VALUES (:username, :email, :role, :participant_id, :full_name)
        RETURNING id
    ");

    $insert->execute(array(
        ':username'       => $username,
        ':email'          => $email !== '' ? $email : null,
        ':role'           => 'member',     // you can change if you have a better role name
        ':participant_id' => $participantId,
        ':full_name'      => $fullName,
    ));

    $new = $insert->fetch(PDO::FETCH_ASSOC);

    return $new ? (int)$new['id'] : null;
}

// -----------------------------------------------
// Decide which flow we use: old or new
// -----------------------------------------------

// CASE 1: OLD FLOW (BACKWARD COMPATIBLE)
// If participant_id is provided and no participant_type -> behave exactly like before
if ($participantId > 0 && $participantType === '') {

    // Make sure participant exists
    $pstmt = $pdo->prepare("SELECT id, full_name FROM participants WHERE id = :id LIMIT 1");
    $pstmt->execute(array(':id' => $participantId));
    $participant = $pstmt->fetch(PDO::FETCH_ASSOC);

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
    $checkStmt->execute(array(
        ':cid' => $committeeId,
        ':pid' => $participantId,
    ));

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

    $ok = $insertStmt->execute(array(
        ':cid'  => $committeeId,
        ':pid'  => $participantId,
        ':role' => $role,
    ));

    if ($ok) {
        flash_set('success', 'Member added successfully: ' . (isset($participant['full_name']) ? $participant['full_name'] : ''));
    } else {
        flash_set('error', 'Failed to add member. Please try again.');
    }

    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

// CASE 2: NEW FLOW (API / MANUAL + TYPE)
$validTypes = array('judge', 'registry_officer', 'advocate', 'gov_officer');

if (!in_array($participantType, $validTypes, true)) {
    flash_set('error', 'Invalid participant type selected.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

if ($fullName === '') {
    flash_set('error', 'Full name is required.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

if ($designationTitle === '') {
    flash_set('error', 'Designation is required.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}

// For government officer, if source not provided, default to MANUAL
if ($participantType === 'gov_officer' && $externalSource === '') {
    $externalSource = 'MANUAL';
}

try {
    $pdo->beginTransaction();

    // 1. Find or create designation
    //    This now also supports "add new designation" with description
    $designationId = find_or_create_designation_id($pdo, $designationTitle, $designationDescription);

    // 2. Find or create participant
    $newParticipantId = find_or_create_participant(
        $pdo,
        $fullName,
        $email,
        $phone,
        $designationId,
        $participantType,
        $externalSource,
        $externalId
    );

    if ($newParticipantId <= 0) {
        $pdo->rollBack();
        flash_set('error', 'Failed to create participant.');
        header('Location: /mms/committees/view.php?id=' . $committeeId);
        exit;
    }

    // 3. Ensure user exists for this participant (for your global user list)
    ensure_user_for_participant($pdo, $newParticipantId, $fullName, $email);

    // 4. Check if this participant is already member of this committee
    $checkStmt = $pdo->prepare("
        SELECT id 
        FROM committee_users 
        WHERE committee_id = :cid AND participant_id = :pid
        LIMIT 1
    ");
    $checkStmt->execute(array(
        ':cid' => $committeeId,
        ':pid' => $newParticipantId,
    ));

    if ($checkStmt->fetch()) {
        // Already a member; no need to insert again
        $pdo->commit();
        flash_set('success', 'This participant was already a member of this committee.');
        header('Location: /mms/committees/view.php?id=' . $committeeId);
        exit;
    }

    // 5. Insert into committee_users
    $insertStmt = $pdo->prepare("
        INSERT INTO committee_users (committee_id, participant_id, role_in_committee)
        VALUES (:cid, :pid, :role)
    ");

    $ok = $insertStmt->execute(array(
        ':cid'  => $committeeId,
        ':pid'  => $newParticipantId,
        ':role' => $role,
    ));

    if (!$ok) {
        $pdo->rollBack();
        flash_set('error', 'Failed to add member to committee.');
        header('Location: /mms/committees/view.php?id=' . $committeeId);
        exit;
    }

    $pdo->commit();

    flash_set('success', 'Member added successfully: ' . $fullName);
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log $e->getMessage() somewhere if you have logger
    flash_set('error', 'Unexpected error while adding member.');
    header('Location: /mms/committees/view.php?id=' . $committeeId);
    exit;
}
