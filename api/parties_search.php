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
                p.state, p.gstin, p.opening_balance, p.balance_type,
                COALESCE((SELECT SUM(s.due_amount) FROM sales s WHERE s.party_id = p.id AND s.payment_status != 'paid'), 0) AS sales_due,
                COALESCE((SELECT SUM(pu.due_amount) FROM purchases pu WHERE pu.party_id = p.id AND pu.payment_status != 'paid'), 0) AS purchases_due,
                COALESCE((SELECT SUM(pi.amount) FROM payments_in pi WHERE pi.party_id = p.id), 0) AS payments_in,
                COALESCE((SELECT SUM(po.amount) FROM payments_out po WHERE po.party_id = p.id), 0) AS payments_out
         FROM parties p
         $where
         ORDER BY p.name ASC
         LIMIT 20",
        $params
    );

    foreach ($parties as &$pt) {
        $opening = (float) $pt['opening_balance'];
        if ($pt['balance_type'] === 'debit') {
            $opening = -$opening;
        }
        $pt['balance'] = round(
            $opening
            + (float) $pt['sales_due']
            - (float) $pt['purchases_due']
            + (float) $pt['payments_in']
            - (float) $pt['payments_out'],
            2
        );
        unset($pt['sales_due'], $pt['purchases_due'], $pt['payments_in'], $pt['payments_out']);
    }

    echo json_encode($parties);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
