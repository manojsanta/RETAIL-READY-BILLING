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

    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $barcode = trim($_POST['barcode'] ?? '');
    $categoryId = intval($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $unit = trim($_POST['unit'] ?? 'Pcs');
    $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
    $salePrice = floatval($_POST['sale_price'] ?? 0);
    $taxRateId = intval($_POST['tax_rate_id'] ?? 0);
    $hsnCode = trim($_POST['hsn_code'] ?? '');
    $minStock = max(0, intval($_POST['min_stock'] ?? 10));
    $openingStock = max(0, intval($_POST['opening_stock'] ?? 0));
    $status = isset($_POST['status']) ? 1 : 0;
    $purchaseTaxMode = in_array($_POST['purchase_tax_mode'] ?? '', ['exclusive', 'inclusive']) ? $_POST['purchase_tax_mode'] : 'exclusive';
    $saleTaxMode = in_array($_POST['sale_tax_mode'] ?? '', ['exclusive', 'inclusive']) ? $_POST['sale_tax_mode'] : 'exclusive';

    if ($name === '') {
        echo json_encode(['error' => 'Item name is required.']);
        exit;
    }
    if ($purchasePrice < 0 || $salePrice < 0) {
        echo json_encode(['error' => 'Valid prices are required.']);
        exit;
    }

    if ($sku !== '') {
        $checkSku = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku = ?");
        $checkSku->execute([$sku]);
        if ($checkSku->fetchColumn() > 0) {
            echo json_encode(['error' => 'SKU already exists.']);
            exit;
        }
    } else {
        $countItems = (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
        $sku = 'ITM-' . str_pad($countItems + 1, 5, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku = ?");
        while (true) {
            $check->execute([$sku]);
            if ($check->fetchColumn() == 0) break;
            $sku = 'ITM-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        }
    }

    $imageName = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (in_array($_FILES['image']['type'], $allowed) && $_FILES['image']['size'] <= 2 * 1024 * 1024) {
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $imageName = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = __DIR__ . '/../assets/images/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName);
        } else {
            echo json_encode(['error' => 'Image must be JPG, PNG, GIF or WebP and under 2MB.']);
            exit;
        }
    }

    $taxRate = 0;
    if ($taxRateId > 0) {
        $tStmt = $pdo->prepare("SELECT rate FROM tax_rates WHERE id = ?");
        $tStmt->execute([$taxRateId]);
        $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
        if ($tRow) $taxRate = (float) $tRow['rate'];
    }

    $insert = $pdo->prepare("INSERT INTO items (name, sku, barcode, category_id, description, unit, purchase_price, sale_price, tax_rate_id, purchase_tax_mode, sale_tax_mode, hsn_code, min_stock, current_stock, opening_stock, image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $insert->execute([
        $name, $sku, $barcode ?: null, $categoryId ?: null,
        $description ?: null, $unit, $purchasePrice, $salePrice, $taxRateId ?: null,
        $purchaseTaxMode, $saleTaxMode, $hsnCode ?: null, $minStock,
        $openingStock, $openingStock, $imageName, $status
    ]);
    $itemId = (int) $pdo->lastInsertId();

    echo json_encode([
        'id' => $itemId,
        'name' => $name,
        'sku' => $sku,
        'barcode' => $barcode,
        'purchase_price' => number_format($purchasePrice, 2, '.', ''),
        'sale_price' => number_format($salePrice, 2, '.', ''),
        'current_stock' => $openingStock,
        'tax_rate_id' => $taxRateId,
        'unit' => $unit,
        'hsn_code' => $hsnCode,
        'tax_rate' => number_format($taxRate, 2, '.', ''),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
