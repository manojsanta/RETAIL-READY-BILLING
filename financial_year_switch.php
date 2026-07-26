<?php
require_once 'db.php';
require_once 'functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: financial_year.php');
    exit;
}

$fyId = (int)$_GET['id'];
$fy = getFinancialYearById($fyId);

if (!$fy) {
    setFlash('danger', 'Financial year not found.');
    header('Location: financial_year.php');
    exit;
}

setCurrentFY($fy);
setFlash('success', 'Switched to <strong>' . h($fy['name']) . '</strong> successfully.');
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;
