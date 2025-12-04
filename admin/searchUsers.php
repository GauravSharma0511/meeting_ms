<?php
// mms/admin/searchUsers.php
// Returns JSON: [{ rjcode, display_name, email }, ...]
// Used by add_admin.php and admin dashboard user search

require_once __DIR__ . '/../lib/db2.php'; // SSO DB connection (intra_users)

header('Content-Type: application/json; charset=utf-8');

try {
    // Make sure connection from db2.php exists
    if (!isset($pdo) || !$pdo) {
        echo json_encode([]);
        exit;
    }

    $q = isset($_GET['q']) ? trim($_GET['q']) : '';

    // Too short -> no results
    if (strlen($q) < 2) {
        echo json_encode([]);
        exit;
    }

    // Case-insensitive search by username (RJ code) or display_name
    $stmt = $pdo->prepare("
        SELECT 
            username AS rjcode,
            display_name
        FROM intra_users
        WHERE LOWER(username) LIKE LOWER(:search)
           OR LOWER(display_name) LIKE LOWER(:search)
        ORDER BY display_name ASC
        LIMIT 20
    ");

    $searchTerm = '%' . $q . '%';
    $stmt->execute([':search' => $searchTerm]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure every row has email key (JS checks user.email)
    foreach ($results as &$row) {
        if (!isset($row['email'])) {
            $row['email'] = null;
        }
    }

    echo json_encode($results);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage()
    ]);
}
