<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

try {
    $partyId = intval($_GET['party_id'] ?? 0);
    if ($partyId <= 0) {
        echo json_encode([]);
        exit;
    }

    $purchases = fetchAll(
        "SELECT id, bill_no, date, total, paid_amount, due_amount, payment_status
         FROM purchases
         WHERE party_id = ? AND status != 'cancelled' AND payment_status != 'paid' AND due_amount > 0
         ORDER BY date ASC",
        [$partyId]
    );

    echo json_encode($purchases);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
