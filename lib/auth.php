<?php
// src/lib/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';

function currentUser() { return $_SESSION['user'] ?? null; }
function isLoggedIn() { return !empty($_SESSION['user']); }
function requireLogin() { if (!isLoggedIn()) { header('Location: /mms/auth/login.php'); exit; } }

function login($username, $password) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u'=>$username]);
    $user = $stmt->fetch();
    if (!$user) return false;
    if (empty($user['password_hash'])) {
        if ($password === '') {
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            return true;
        }
        return false;
    }
    if (password_verify($password, $user['password_hash'])) {
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        return true;
    }
    return false;
}
// ... your existing auth functions above

function isSuperAdmin(array $user = null): bool {
    if ($user === null) {
        $user = currentUser();
    }
    return isset($user['role']) && $user['role'] === 'superuser';
}

/**
 * Returns array of committee IDs where this user is admin (head).
 * Uses users.participant_id -> committee_users.participant_id.
 */
function getUserAdminCommitteeIds(PDO $pdo, array $user = null): array {
    if ($user === null) {
        $user = currentUser();
    }
    if (empty($user['participant_id'])) {
        return [];
    }
    $pid = (int)$user['participant_id'];

    $stmt = $pdo->prepare("
        SELECT committee_id
        FROM committee_users
        WHERE participant_id = :pid
          AND role_in_committee = 'admin'
    ");
    $stmt->execute([':pid' => $pid]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $rows ?: []);
}

function isCommitteeAdmin(PDO $pdo, array $user = null): bool {
    return count(getUserAdminCommitteeIds($pdo, $user)) > 0;
}

function requireCommitteeAdminFor(PDO $pdo, int $committeeId, array $user = null): void {
    if ($user === null) {
        $user = currentUser();
    }

    // Superadmin is always allowed
    if (isSuperAdmin($user)) {
        return;
    }

    $allowedCommittees = getUserAdminCommitteeIds($pdo, $user);

    if (!in_array($committeeId, $allowedCommittees, true)) {
        http_response_code(403);
        echo "You are not allowed to manage/view meetings for this committee.";
        exit;
    }
}



function logout() { session_unset(); session_destroy(); }
