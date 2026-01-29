<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../services/CommitteeService.php';

header('Content-Type: application/json');

/* =====================================================
   AUTH
   ===================================================== */
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

$user = currentUser();

if (!$user || !isSuperAdmin($user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

/* =====================================================
   CSRF
   ===================================================== */
if (!verify_csrf($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF']);
    exit;
}

/* =====================================================
   VALIDATION HELPERS
   ===================================================== */
function hasOnlyZeros($v) {
    return preg_match('/^0+$/', $v);
}

function hasRepeatedTrailing($v) {
    return preg_match('/(.)\1{4,}$/', $v);
}

function isValidCommitteeName($name) {
    return preg_match('/^[A-Za-z0-9 ()_-]+$/', $name);
}

function isValidMobile($mobile) {
    if (!preg_match('/^\d{10}$/', $mobile)) return false;
    return !preg_match('/^(\d)\1{9}$/', $mobile);
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/* =====================================================
   INPUT
   ===================================================== */
$payloadRaw = $_POST['payload'] ?? '';
$payload = json_decode($payloadRaw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload format']);
    exit;
}

$committeeName = trim($payload['name'] ?? '');
$description   = trim($payload['description'] ?? '');
$members       = $payload['members'] ?? [];

/* =====================================================
   COMMITTEE VALIDATION
   ===================================================== */
if ($committeeName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Committee name required']);
    exit;
}

if (!isValidCommitteeName($committeeName)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid committee name']);
    exit;
}

if (hasOnlyZeros($committeeName) || hasRepeatedTrailing($committeeName)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid committee name format']);
    exit;
}

if ($description !== '') {
    if (hasOnlyZeros($description) || hasRepeatedTrailing($description)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid description format']);
        exit;
    }
}

if (!is_array($members) || count($members) === 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'At least one member required']);
    exit;
}

/* =====================================================
   MEMBER VALIDATION
   ===================================================== */
$seenMembers = [];
$adminCount  = 0;

foreach ($members as $idx => $m) {

    $name = trim($m['full_name'] ?? '');

    if ($name === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Member name missing at index $idx"]);
        exit;
    }

    if (empty($m['designation_id'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Designation missing for $name"]);
        exit;
    }

    /* 🔁 Duplicate member (case-insensitive) */
    $uniqueKey = '';

if (($m['external_source'] ?? '') === 'API') {
    $uniqueKey = 'API|' . ($m['external_id'] ?? '') . '|' . ($m['designation_id'] ?? '');
} else {
   if (($m['external_source'] ?? '') === 'MANUAL') {
    $uniqueKey = 'MANUAL|' . ($m['phone'] ?? '');
} else {
    $uniqueKey = 'API|' . ($m['external_id'] ?? '');
}
}

if (isset($seenMembers[$uniqueKey])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => "Duplicate member detected: $name"
    ]);
    exit;
}

$seenMembers[$uniqueKey] = true;


    /* 🔐 Admin rules */
    if (($m['add_as'] ?? '') === 'admin') {
        $adminCount++;
        if (($m['participant_type'] ?? '') !== 'registry') {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Only registry user can be admin']);
            exit;
        }
    }

    /* 🧾 Manual member validation */
    if (($m['external_source'] ?? '') === 'MANUAL') {

        if (empty($m['phone']) || !isValidMobile($m['phone'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => "Invalid mobile for $name"]);
            exit;
        }

        if (!empty($m['email']) && !isValidEmail($m['email'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => "Invalid email for $name"]);
            exit;
        }

        if (($m['participant_type'] ?? '') === 'govt') {
            $dept = trim($m['department'] ?? '');
            if ($dept === '' || hasOnlyZeros($dept) || hasRepeatedTrailing($dept)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => "Invalid department for $name"]);
                exit;
            }
        }
    }
}

if ($adminCount !== 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Exactly one admin is required']);
    exit;
}

/* =====================================================
   DUPLICATE COMMITTEE (CASE-INSENSITIVE)
   ===================================================== */
$pdo = getPDO();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM committees WHERE LOWER(name) = LOWER(?)");
$stmt->execute([$committeeName]);

if ($stmt->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Committee with same name already exists']);
    exit;
}

/* =====================================================
   SERVICE
   ===================================================== */
try {

    $service = new CommitteeService($pdo);

    $committeeId = $service->createCommittee(
        $committeeName,
        $description !== '' ? $description : null,
        (int) $user['id'],
        $members
    );

    echo json_encode([
        'success' => true,
        'committee_id' => $committeeId
    ]);

} catch (Throwable $e) {

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
