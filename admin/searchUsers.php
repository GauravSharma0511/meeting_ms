<?php

require_once __DIR__ . '/../lib/db2.php';

if (!isset($pdo) || !$pdo) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot connect to SSO database']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

try {
   $q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

// Case-insensitive search by username or display_name
$stmt = $pdo->prepare("
    SELECT username AS rjcode, display_name
    FROM intra_users
    WHERE LOWER(username) LIKE LOWER(:search)
       OR LOWER(display_name) LIKE LOWER(:search)

");

$searchTerm = "%$q%";
$stmt->execute(['search' => $searchTerm]);

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        // In production you might want to hide this:
        'message' => $e->getMessage()
    ]);
}
