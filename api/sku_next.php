<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

$prefix = strtoupper(trim($_GET['prefix'] ?? ''));
if ($prefix === '') $prefix = 'ITM';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku LIKE ?");
$stmt->execute([$prefix . '-%']);
$count = (int)$stmt->fetchColumn();

$next = str_pad($count + 1, 5, '0', STR_PAD_LEFT);
echo json_encode(['sku' => $prefix . '-' . $next]);
