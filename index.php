<?php
session_start();
require_once __DIR__ . '/db.php';

if ($pdo === null) {
    header('Location: install.php');
    exit;
}

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() === 0) {
        header('Location: install.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: install.php');
    exit;
}

if (isset($_SESSION['user_id'])) {
    header('Location: financial_year.php');
    exit;
}

header('Location: login.php');
exit;
