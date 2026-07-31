<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    if (!verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid request.']);
        exit;
    }

    $type = sanitize($_POST['type'] ?? 'customer');
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');
    $gstin = strtoupper(trim($_POST['gstin'] ?? ''));
    $pan = strtoupper(trim($_POST['pan'] ?? ''));
    $gstRegType = trim($_POST['gst_reg_type'] ?? '');
    $openingBalance = (float) ($_POST['opening_balance'] ?? 0);
    $balanceType = sanitize($_POST['balance_type'] ?? 'credit');
    $partyGroup = trim($_POST['party_group'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = isset($_POST['status']) && $_POST['status'] === 'active' ? 1 : 0;

    if ($name === '') {
        echo json_encode(['error' => 'Party name is required.']);
        exit;
    }
    if (!in_array($type, ['customer', 'supplier', 'both'])) {
        $type = 'customer';
    }
    if (!in_array($balanceType, ['credit', 'debit'])) {
        $balanceType = 'credit';
    }
    if ($balanceType === 'debit' && $openingBalance > 0) {
        $openingBalance = -$openingBalance;
    }

    $insert = $pdo->prepare("INSERT INTO parties (type, name, phone, email, address, city, state, pincode, gstin, pan, gst_reg_type, opening_balance, party_group, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $insert->execute([
        $type, $name, $phone ?: null, $email ?: null, $address ?: null, $city ?: null,
        $state ?: null, $pincode ?: null, $gstin ?: null, $pan ?: null, $gstRegType ?: null,
        $openingBalance, $partyGroup ?: null, $notes ?: null, $status
    ]);
    $partyId = (int) $pdo->lastInsertId();

    echo json_encode([
        'id' => $partyId,
        'name' => $name,
        'phone' => $phone,
        'balance' => round($openingBalance, 2),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
