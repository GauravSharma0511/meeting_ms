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
 * Uses committee_admins.user_id -> users.id.
 */
function getUserAdminCommitteeIds(PDO $pdo, array $user = null): array {
    if ($user === null) {
        $user = currentUser();
    }

    if (!$user || empty($user['id'])) {
        return [];
    }

    // Superadmin: optionally, treat as admin of all committees
    if (isSuperAdmin($user)) {
        $stmt = $pdo->query("SELECT id FROM committees");
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    $uid = (int)$user['id'];

    $stmt = $pdo->prepare("
        SELECT committee_id
        FROM committee_admins
        WHERE user_id = :uid
    ");
    $stmt->execute([':uid' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $rows ?: []);
}

function isCommitteeAdmin(PDO $pdo, array $user = null): bool {
    if ($user === null) {
        $user = currentUser();
    }
    if (!$user) return false;

    // Superadmin is always considered admin
    if (isSuperAdmin($user)) {
        return true;
    }

    return count(getUserAdminCommitteeIds($pdo, $user)) > 0;
}

function requireCommitteeAdminFor(PDO $pdo, int $committeeId, array $user = null) {
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
