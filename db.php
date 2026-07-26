<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'retail_ready');
define('DB_USER', 'root');
define('DB_PASS', '');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET time_zone = '+05:30'");
} catch (PDOException $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Database Error</title>';
    echo '<style>body{font-family:Arial,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}';
    echo '.card{background:#fff;padding:2rem;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);text-align:center;max-width:500px;}';
    echo 'h1{color:#dc3545;}p{color:#666;}</style></head><body>';
    echo '<div class="card"><h1>Database Error</h1>';
    echo '<p>Unable to connect to the database. Please check your configuration.</p>';
    echo '</div></body></html>';
    exit;
}