<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

try {
    $q = trim($_GET['q'] ?? '');

    if ($q === '') {
        $items = fetchAll(
            "SELECT i.id, i.name, i.sku, i.barcode, i.purchase_price, i.purchase_price_with_tax,
                    i.sale_price, i.sale_price_with_tax,
                    i.current_stock, i.tax_rate_id, i.purchase_tax_rate_id, i.unit, i.hsn_code,
                    i.purchase_tax_mode, i.sale_tax_mode,
                    COALESCE(t.rate, 0) AS tax_rate,
                    COALESCE(pt.rate, 0) AS purchase_tax_rate
             FROM items i
             LEFT JOIN tax_rates t ON i.tax_rate_id = t.id
             LEFT JOIN tax_rates pt ON i.purchase_tax_rate_id = pt.id
             WHERE i.status = 1
             ORDER BY i.name ASC
             LIMIT 20"
        );
    } else {
        $items = fetchAll(
            "SELECT i.id, i.name, i.sku, i.barcode, i.purchase_price, i.purchase_price_with_tax,
                    i.sale_price, i.sale_price_with_tax,
                    i.current_stock, i.tax_rate_id, i.purchase_tax_rate_id, i.unit, i.hsn_code,
                    i.purchase_tax_mode, i.sale_tax_mode,
                    COALESCE(t.rate, 0) AS tax_rate,
                    COALESCE(pt.rate, 0) AS purchase_tax_rate
             FROM items i
             LEFT JOIN tax_rates t ON i.tax_rate_id = t.id
             LEFT JOIN tax_rates pt ON i.purchase_tax_rate_id = pt.id
             WHERE i.status = 1
               AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)
             ORDER BY i.name ASC
             LIMIT 10",
            ["%$q%", "%$q%", "%$q%"]
        );
    }

    echo json_encode($items);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
