<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

try {
    $q = trim($_GET['q'] ?? '');
    if (empty($q)) {
        echo json_encode([]);
        exit;
    }

    $type = $_GET['type'] ?? '';
    $where = "WHERE p.status = 1 AND (p.name LIKE ? OR p.phone LIKE ?)";
    $params = ["%$q%", "%$q%"];

    if ($type === 'customer') {
        $where .= " AND (p.type = 'customer' OR p.type = 'both')";
    } elseif ($type === 'supplier') {
        $where .= " AND (p.type = 'supplier' OR p.type = 'both')";
    }

    $parties = fetchAll(
        "SELECT p.id, p.name, p.phone, p.email, p.address, p.city,
                p.state, p.gstin, p.opening_balance, p.balance_type
         FROM parties p
         $where
         ORDER BY p.name ASC
         LIMIT 20",
        $params
    );

    echo json_encode($parties);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
