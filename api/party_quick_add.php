<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid request.');
    }

    $name = trim(sanitize($_POST['name'] ?? ''));
    $phone = trim(sanitize($_POST['phone'] ?? ''));
    $type = trim(sanitize($_POST['type'] ?? 'customer'));
    $gstin = trim(sanitize($_POST['gstin'] ?? ''));
    $address = trim(sanitize($_POST['address'] ?? ''));
    $city = trim(sanitize($_POST['city'] ?? ''));
    $pincode = trim(sanitize($_POST['pincode'] ?? ''));

    if ($name === '') {
        throw new Exception('Party name is required.');
    }
    if (!in_array($type, ['customer', 'supplier', 'both'])) {
        $type = 'customer';
    }

    $partyId = insertId(
        "INSERT INTO parties (type, name, phone, gstin, address, city, pincode, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())",
        [$type, $name, $phone ?: null, $gstin ?: null, $address ?: null, $city ?: null, $pincode ?: null]
    );

    echo json_encode(['success' => true, 'id' => $partyId, 'name' => $name]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
