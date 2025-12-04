<?php
$port = '5432';
$dbip = "10.130.8.95";
$dbname = "sso_intra_19-09-2025";
$dbpassword = 1;
$dbuser = "postgres";
try {
    $dsn = "pgsql:host=$dbip;port=$port;dbname=$dbname;";
    // $pdo = new PDO($dsn, $dbuser, $dbpassword);
    // $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo = new PDO($dsn, $dbuser, $dbpassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,  
    
]);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}