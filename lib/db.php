<?php
// /var/www/html/../lib/db.php (or adjust real path)
function getPDO() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = 'localhost';
    $port = '5432';
    $db   = 'mms_db';
    $user = 'postgres'; // or 'postgres' if that's what you created
    $pass = '1234';         // blank password as you requested

    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo "<h2>DB connection error</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }

    return $pdo;
}
