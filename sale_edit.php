<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$saleId = intval($_GET['id'] ?? 0);
if ($saleId <= 0) {
    setFlash('danger', 'Invalid sale ID.');
    redirect('sales.php');
}

$sale = fetch("SELECT * FROM sales WHERE id = ? AND status != 'cancelled'", [$saleId]);
if (!$sale) {
    setFlash('danger', 'Sale invoice not found.');
    redirect('sales.php');
}

$errors = [];
$old = [
    'party_id' => (int) $sale['party_id'],
    'date' => $sale['date'],
    'payment_method' => $sale['payment_method'],
    'paid_amount' => (float) $sale['paid_amount'],
    'notes' => $sale['notes'] ?? '',
    'discount_amount' => (float) $sale['discount_amount'],
    'sale_mode' => $sale['payment_method'] === 'credit' ? 'credit' : 'cash',
];

$existingItems = fetchAll("SELECT si.item_id, si.qty, si.rate, si.discount, si.tax_rate, i.name AS item_name, i.sku AS item_sku, i.current_stock AS item_stock
    FROM sale_items si JOIN items i ON si.item_id = i.id WHERE si.sale_id = ?", [$saleId]);

// Ensure a "Walk In Customer" party exists
$walkIn = fetch("SELECT id, name FROM parties WHERE name = 'Walk In Customer' AND status = 1 ORDER BY id ASC LIMIT 1");
if (!$walkIn) {
    $walkInId = insertId("INSERT INTO parties (type, name, status) VALUES ('customer', 'Walk In Customer', 1)");
    $walkIn = ['id' => $walkInId, 'name' => 'Walk In Customer'];
}

// Fetch dropdown data
$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 AND (type = 'customer' OR type = 'both') ORDER BY name ASC");
$taxRates = $pdo->query("SELECT id, name, rate FROM tax_rates ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$units = fetchAll("SELECT id, name, short_name FROM units WHERE status = 1 ORDER BY name ASC");
$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$countItems = (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$suggestedSku = 'ITM-' . str_pad($countItems + 1, 5, '0', STR_PAD_LEFT);

// Handle POST (update)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: sale_edit.php?id=' . $saleId);
        exit;
    }

    $partyId = intval($_POST['party_id'] ?? 0);
    $saleDate = sanitize($_POST['date'] ?? today());
    $saleMode = sanitize($_POST['sale_mode'] ?? 'cash');
    $old['sale_mode'] = $saleMode;
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $notes = sanitize($_POST['notes'] ?? '');
    $paidAmount = floatval($_POST['paid_amount'] ?? 0);
    if ($saleMode === 'credit') {
        $paymentMethod = 'credit';
    }

    $itemIds = $_POST['item_id'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemRates = $_POST['item_rate'] ?? [];
    $itemDiscounts = $_POST['item_discount'] ?? [];
    $itemTaxes = $_POST['item_tax'] ?? [];

    // Validation
    if ($partyId <= 0) {
        $errors[] = 'Please select a party.';
    }

    $validItems = [];
    for ($i = 0; $i < count($itemIds); $i++) {
        $itemId = intval($itemIds[$i]);
        if ($itemId <= 0) continue;

        $qty = max(1, intval($itemQtys[$i] ?? 1));
        $rate = floatval($itemRates[$i] ?? 0);
        $discVal = floatval($itemDiscounts[$i] ?? 0);
        $taxPct = floatval($itemTaxes[$i] ?? 0);

        $item = fetch("SELECT * FROM items WHERE id = ? AND status = 1", [$itemId]);
        if (!$item) {
            $errors[] = "Item #$itemId not found.";
            continue;
        }

        $unitSubtotal = round($rate, 2);
        $lineSubtotal = round($qty * $unitSubtotal, 2);
        $unitTax = round(($unitSubtotal * $taxPct) / 100, 2);
        $taxFull = round($qty * $unitTax, 2);
        $grossTotal = round($lineSubtotal + $taxFull, 2);
        $lineDiscount = round(min($discVal, $grossTotal), 2);
        $lineTotal = round($grossTotal - $lineDiscount, 2);
        $lineTax = $taxFull;

        if ($rate <= 0) {
            $errors[] = "Rate must be greater than 0 for item: " . $item['name'];
        }

        $validItems[] = [
            'item_id' => $itemId,
            'qty' => $qty,
            'rate' => $rate,
            'discount' => $lineDiscount,
            'tax_rate' => $taxPct,
            'tax_amount' => $lineTax,
            'total' => $lineTotal,
        ];
    }

    if (empty($validItems)) {
        $errors[] = 'Please add at least one item.';
    }

    if ($paidAmount < 0) {
        $errors[] = 'Paid amount cannot be negative.';
    }

    if (empty($errors)) {
        // Check stock availability (old stock will be restored first)
        $itemQtyMap = [];
        foreach ($validItems as $vi) {
            $itemQtyMap[$vi['item_id']] = ($itemQtyMap[$vi['item_id']] ?? 0) + $vi['qty'];
        }
        $oldQtyMap = [];
        foreach ($existingItems as $ei) {
            $oldQtyMap[$ei['item_id']] = ($oldQtyMap[$ei['item_id']] ?? 0) + $ei['qty'];
        }
        foreach ($itemQtyMap as $iid => $qtySum) {
            $stockRow = fetch("SELECT name, current_stock FROM items WHERE id = ?", [$iid]);
            $available = (int) ($stockRow['current_stock'] ?? 0) + (int) ($oldQtyMap[$iid] ?? 0);
            if ($stockRow && $available < $qtySum) {
                $errors[] = "Insufficient stock for '{$stockRow['name']}' (available: $available, required: $qtySum).";
            }
        }
    }

    if (empty($errors)) {
        // Recalculate totals from valid items
        $calcSubtotal = 0;
        $calcTax = 0;
        $calcDiscount = 0;
        $calcGrand = 0;
        foreach ($validItems as $vi) {
            $calcSubtotal += ($vi['qty'] * $vi['rate']);
            $calcTax += $vi['tax_amount'];
            $calcDiscount += $vi['discount'];
            $calcGrand += $vi['total'];
        }
        $calcSubtotal = round($calcSubtotal, 2);
        $calcTax = round($calcTax, 2);
        $calcDiscount = round($calcDiscount, 2);
        $calcGrand = round($calcGrand, 2);
        $dueAmount = max(0, round($calcGrand - $paidAmount, 2));

        if ($saleMode === 'cash' && round($paidAmount, 2) != round($calcGrand, 2)) {
            $errors[] = 'For cash sales, the amount paid must equal the total amount (₹' . number_format($calcGrand, 2) . ').';
        }
        if (!empty($errors)) {
            $paymentStatus = 'unpaid';
            $dueAmount = round($calcGrand - $paidAmount, 2);
        } elseif ($dueAmount <= 0) {
            $paymentStatus = 'paid';
        } elseif ($paidAmount > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'unpaid';
        }

        if (empty($errors)) {
            global $pdo;
            $pdo->beginTransaction();
            try {
                // Reverse old stock
                foreach ($existingItems as $ei) {
                    updateStock($ei['item_id'], $ei['qty'], 'add');
                }

                // Delete old items
                query("DELETE FROM sale_items WHERE sale_id = ?", [$saleId]);

                // Update sale
                query("UPDATE sales SET party_id = ?, date = ?, subtotal = ?, tax_amount = ?, discount_amount = ?, total = ?, paid_amount = ?, due_amount = ?, payment_status = ?, payment_method = ?, notes = ?, status = ? WHERE id = ?",
                    [$partyId ?: null, $saleDate, $calcSubtotal, $calcTax, $calcDiscount, $calcGrand, $paidAmount, $dueAmount, $paymentStatus, $paymentMethod, $notes, $paymentStatus === 'paid' ? 'paid' : 'draft', $saleId]);

                // Insert new items & subtract stock
                foreach ($validItems as $vi) {
                    query(
                        "INSERT INTO sale_items (sale_id, item_id, qty, rate, discount, tax_rate, tax_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                        [$saleId, $vi['item_id'], $vi['qty'], $vi['rate'], $vi['discount'], $vi['tax_rate'], $vi['tax_amount'], $vi['total']]
                    );
                    updateStock($vi['item_id'], $vi['qty'], 'subtract');
                }

                // Update/create the auto payment entry
                $autoPayment = fetch("SELECT id FROM payments_in WHERE sale_id = ? AND notes LIKE 'Auto: Sale%' ORDER BY id DESC LIMIT 1", [$saleId]);
                if ($paidAmount > 0) {
                    if ($autoPayment) {
                        query("UPDATE payments_in SET party_id = ?, date = ?, amount = ?, payment_method = ? WHERE id = ?",
                            [$partyId, $saleDate, $paidAmount, $paymentMethod, $autoPayment['id']]);
                    } else {
                        $receiptNo = generateReceiptNo();
                        query(
                            "INSERT INTO payments_in (receipt_no, party_id, sale_id, date, amount, payment_method, notes, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                            [$receiptNo, $partyId, $saleId, $saleDate, $paidAmount, $paymentMethod, 'Auto: Sale ' . $sale['invoice_no'], $_SESSION['user_id']]
                        );
                    }
                } elseif ($autoPayment) {
                    query("DELETE FROM payments_in WHERE id = ?", [$autoPayment['id']]);
                }

                $pdo->commit();
                setFlash('success', 'Sale invoice updated successfully.');
                header('Location: sale_view.php?id=' . $saleId);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error updating sale: ' . $e->getMessage());
                header('Location: sale_edit.php?id=' . $saleId);
                exit;
            }
        }
    }
}

$invoiceNo = $sale['invoice_no'];
$existingItemsJson = json_encode(array_map(function ($ei) {
    return [
        'id' => $ei['item_id'],
        'name' => $ei['item_name'],
        'sku' => $ei['item_sku'],
        'qty' => $ei['qty'],
        'rate' => $ei['rate'],
        'discount' => $ei['discount'],
        'tax_rate' => $ei['tax_rate'],
        'stock' => $ei['item_stock'],
    ];
}, $existingItems));
$pageTitle = 'Edit Sale Invoice - ' . sanitize($invoiceNo);
include 'header.php';
?>

<style>
.sale-form .item-search-wrapper { position: relative; }
.sale-form .item-search-wrapper .item-search { padding-right: 28px; }
.sale-form .item-search-wrapper .clear-item-btn {
    position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
    width: 18px; height: 18px; border: none; border-radius: 50%;
    background: #e9ecef; color: #6c757d; font-size: 10px; line-height: 1;
    display: none; align-items: center; justify-content: center; cursor: pointer; z-index: 3;
}
.sale-form .item-search-wrapper .clear-item-btn:hover { background: #dc3545; color: #fff; }
.sale-form .item-search-wrapper .clear-item-btn.show { display: flex; }
.sale-form .item-search-dropdown {
    position: fixed; z-index: 1050;
    background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; display: none;
}
.sale-form .item-search-dropdown.show { display: block; }
.sale-form .item-search-dropdown .dd-head,
.sale-form .item-search-dropdown .srch-item {
    display: grid; grid-template-columns: 1fr 92px 100px 90px 85px; gap: 8px; align-items: center;
    padding: 4px 8px; font-size: 12px; border-bottom: 1px solid #f5f5f5;
}
.sale-form .item-search-dropdown .dd-head {
    padding-top: 5px; padding-bottom: 5px; font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .4px; color: #6c757d;
    background: #f8f9fa; border-bottom: 1px solid #dee2e6; position: sticky; top: 0;
}
.sale-form .item-search-dropdown .srch-item { cursor: pointer; }
.sale-form .item-search-dropdown .srch-item:hover { background: #f0f4ff; }
.sale-form .item-search-dropdown .srch-item .si-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sale-form .item-search-dropdown .srch-item .si-stock { color: #6c757d; white-space: nowrap; }
.sale-form .item-search-dropdown .srch-item.no-stock { opacity: .55; cursor: not-allowed; }
.sale-form .item-search-dropdown .srch-item.no-stock:hover { background: #f0f4ff; }
.sale-form .item-search-dropdown .srch-item .si-stock.no-stock { color: #dc3545; font-weight: 600; }
.sale-form .item-search-dropdown .srch-item .si-hsn { color: #6c757d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sale-form .item-search-dropdown .srch-item .si-price { color: #2962FF; font-weight: 600; text-align: right; }
.sale-form .item-search-dropdown .srch-item .si-price.si-purchase { color: #6c757d; font-weight: 500; }
.sale-form .item-search-dropdown .no-results { padding: 12px; text-align: center; color: #6c757d; font-size: 13px; }
.sale-form .item-search-dropdown .qa-link {
    display: block; padding: 7px 10px; font-size: 12px; font-weight: 600; color: #2962FF;
    cursor: pointer; background: #f8f9fa; border-top: 1px solid #dee2e6; text-align: center;
    position: sticky; bottom: 0;
}
.sale-form .item-search-dropdown .qa-link:hover { background: #eef2ff; }
.party-search-wrapper { position: relative; }
.party-search-wrapper .clear-party-btn {
    position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
    width: 22px; height: 22px; border: none; border-radius: 50%;
    background: #e9ecef; color: #6c757d; font-size: 11px; line-height: 1;
    display: none; align-items: center; justify-content: center; cursor: pointer; z-index: 3;
}
.party-search-wrapper .clear-party-btn:hover { background: #dc3545; color: #fff; }
.party-search-wrapper .clear-party-btn.show { display: flex; }
.add-party-link {
    font-size: inherit; line-height: inherit; color: #6c757d; text-decoration: none;
    background: none; border: 0; padding: 0; cursor: pointer;
    display: inline-flex; align-items: center; gap: 4px;
}
.add-party-link:hover {
    color: var(--primary-color);
    text-decoration: none;
}
.party-search-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 999;
    background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; display: none;
}
.party-search-dropdown.show { display: block; }
.party-search-dropdown .srch-item {
    padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f5f5f5;
}
.party-search-dropdown .srch-item:hover { background: #f0f4ff; }
.party-search-dropdown .srch-item .p-bal { font-weight: 600; font-size: 13px; white-space: nowrap; }
.party-search-dropdown .qa-link {
    display: block; padding: 7px 10px; font-size: 12px; font-weight: 600; color: #2962FF;
    cursor: pointer; background: #f8f9fa; border-top: 1px solid #dee2e6; text-align: center;
    position: sticky; bottom: 0;
}
.party-search-dropdown .qa-link:hover { background: #eef2ff; }
.totals-box .total-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
.totals-box .total-row.grand { border-top: 2px solid #dee2e6; padding-top: 10px; margin-top: 6px; font-size: 20px; font-weight: 700; color: #2962FF; }
.stock-warn { font-size: 11px; color: #dc3545; margin-top: 2px; display: none; }

/* ===== Quick Add Item modal (mirrors item_add.php) ===== */
#quickAddModal .modal-dialog { max-width: 900px; }
#quickAddModal .modal-body { overflow-y: auto; max-height: 80vh; }
#quickAddModal .vy-add-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start;
}
@media (max-width: 767px) { #quickAddModal .vy-add-grid { grid-template-columns: 1fr; } }
#quickAddModal .vy-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; }
#quickAddModal .vy-add-left .vy-card + .vy-card,
#quickAddModal .vy-add-right .vy-card + .vy-card { margin-top: 16px; }
#quickAddModal .vy-card-head {
    font-size: 12px; font-weight: 700; color: var(--primary-color); text-transform: uppercase;
    letter-spacing: 0.5px; padding: 12px 18px; background: var(--primary-light);
    border-bottom: 1px solid rgba(237,26,59,0.08); display: flex; align-items: center; gap: 8px;
}
#quickAddModal .vy-card-head i { font-size: 13px; }
#quickAddModal .vy-card-body { padding: 16px 18px; }
#quickAddModal .vy-f { margin-bottom: 12px; }
#quickAddModal .vy-f:last-child { margin-bottom: 0; }
#quickAddModal .vy-f label {
    font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; display: block;
    text-transform: uppercase; letter-spacing: 0.3px;
}
#quickAddModal .vy-f label .text-danger { color: var(--danger-color); }
#quickAddModal .vy-f input, #quickAddModal .vy-f select, #quickAddModal .vy-f textarea {
    width: 100%; height: 38px; border: 1px solid #e0e0e0; border-radius: 8px;
    padding: 0 11px; font-size: 13px; color: #1a1a1a; background: #fafafa; transition: border 0.2s;
}
#quickAddModal .vy-f textarea { height: auto; padding: 9px 11px; resize: vertical; min-height: 56px; }
#quickAddModal .vy-f input:focus, #quickAddModal .vy-f select:focus, #quickAddModal .vy-f textarea:focus {
    outline: none; border-color: var(--primary-color); background: #fff;
    box-shadow: 0 0 0 3px rgba(237, 26, 59, 0.08);
}
#quickAddModal .vy-f input::placeholder { color: #bbb; }
#quickAddModal .vy-f-row { display: flex; gap: 10px; }
#quickAddModal .vy-f-row .vy-f { flex: 1; }
#quickAddModal .vy-input-addon { display: flex; align-items: stretch; }
#quickAddModal .vy-input-addon span {
    display: flex; align-items: center; justify-content: center; width: 36px;
    background: #f0f0f0; border: 1px solid #e0e0e0; border-right: none;
    border-radius: 8px 0 0 8px; font-size: 13px; font-weight: 700; color: #888;
}
#quickAddModal .vy-input-addon input { border-radius: 0 8px 8px 0 !important; flex: 1; }
#quickAddModal .vy-img-upload {
    position: relative; border: 2px dashed #d5d5d5; border-radius: 10px;
    background: #fafafa; overflow: hidden; transition: border 0.2s;
}
#quickAddModal .vy-img-upload:hover { border-color: var(--primary-color); }
#quickAddModal .vy-img-placeholder { padding: 22px; text-align: center; cursor: pointer; }
#quickAddModal .vy-img-placeholder i { font-size: 24px; color: #ccc; margin-bottom: 4px; display: block; }
#quickAddModal .vy-img-placeholder span { font-size: 12px; color: #888; display: block; }
#quickAddModal .vy-img-placeholder small { font-size: 11px; color: #bbb; }
#quickAddModal .vy-img-upload input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
#quickAddModal .vy-img-upload img#qaImagePreview { display: block; max-height: 130px; margin: 10px auto; border-radius: 8px; }
#quickAddModal .vy-track-toggle {
    display: flex; align-items: center; justify-content: space-between; padding: 2px 0; margin-bottom: 12px;
}
#quickAddModal .vy-toggle-label { font-size: 13px; font-weight: 500; color: #333; }
#quickAddModal .vy-toggle-desc { font-size: 11px; color: #999; margin-top: 1px; }
#quickAddModal .vy-switch { position: relative; width: 42px; height: 23px; flex-shrink: 0; }
#quickAddModal .vy-switch input { opacity: 0; width: 0; height: 0; }
#quickAddModal .vy-slider {
    position: absolute; inset: 0; background: #ccc; border-radius: 24px; cursor: pointer; transition: 0.3s;
}
#quickAddModal .vy-slider::before {
    content: ''; position: absolute; height: 17px; width: 17px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: 0.3s;
}
#quickAddModal .vy-switch input:checked + .vy-slider { background: var(--primary-color); }
#quickAddModal .vy-switch input:checked + .vy-slider::before { transform: translateX(19px); }
#quickAddModal .vy-sku-row { display: flex; gap: 6px; align-items: center; }
#quickAddModal .vy-sku-row input { flex: 1; }
#quickAddModal .vy-btn-icon {
    height: 38px; min-width: 38px; border: 1px solid #e0e0e0; border-radius: 8px;
    background: #fafafa; color: var(--primary-color); font-size: 13px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: 0.2s;
}
#quickAddModal .vy-btn-icon:hover { background: var(--primary-light); border-color: var(--primary-color); }
#quickAddModal .vy-profit-box {
    background: #f8fdf8; border: 1px solid #e6f4e6; border-radius: 10px; padding: 11px 13px;
}
#quickAddModal .vy-profit-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: #555; padding: 2px 0;
}
#quickAddModal .vy-profit-divider { border-top: 1px dashed #d4e8d4; margin: 5px 0; }
#quickAddModal .vy-profit-total { font-weight: 700; font-size: 14px; color: #1a1a1a; }
#quickAddModal .vy-tax-toggle { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
#quickAddModal .vy-tax-label {
    font-size: 11px; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: 0.3px;
}
#quickAddModal .vy-tax-pills { display: flex; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
#quickAddModal .vy-pill {
    border: none; background: #fafafa; padding: 4px 13px; font-size: 12px;
    font-weight: 600; color: #888; cursor: pointer; transition: 0.2s;
}
#quickAddModal .vy-pill + .vy-pill { border-left: 1px solid #e0e0e0; }
#quickAddModal .vy-pill.active { background: var(--primary-color); color: #fff; }
#quickAddModal .vy-pill:hover:not(.active) { background: #f0f0f0; }
#quickAddModal .vy-price-breakdown {
    background: #f8f9fa; border: 1px solid #eee; border-radius: 8px; padding: 9px 11px;
}
#quickAddModal .vy-bd-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: #666; padding: 2px 0;
}
#quickAddModal .vy-bd-divider { border-top: 1px dashed #ddd; margin: 3px 0; }
#quickAddModal .vy-bd-total { font-weight: 700; font-size: 13px; color: #1a1a1a; }
#qaProfitValue.text-success { color: #28a745; }

/* ===== Quick Add Party modal (mirrors party_add.php) ===== */
#quickAddPartyModal .modal-dialog { max-width: 820px; }
#quickAddPartyModal .modal-body { overflow-y: auto; max-height: 80vh; }
#quickAddPartyModal .vy-add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
@media (max-width: 767px) { #quickAddPartyModal .vy-add-grid { grid-template-columns: 1fr; } }
#quickAddPartyModal .vy-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; }
#quickAddPartyModal .vy-add-left .vy-card + .vy-card,
#quickAddPartyModal .vy-add-right .vy-card + .vy-card { margin-top: 14px; }
#quickAddPartyModal .vy-card-head {
    font-size: 12px; font-weight: 700; color: var(--primary-color); text-transform: uppercase;
    letter-spacing: 0.5px; padding: 12px 18px; background: var(--primary-light);
    border-bottom: 1px solid rgba(237,26,59,0.08); display: flex; align-items: center; gap: 8px;
}
#quickAddPartyModal .vy-card-head i { font-size: 13px; }
#quickAddPartyModal .vy-card-body { padding: 16px 18px; }
#quickAddPartyModal .vy-f { margin-bottom: 12px; }
#quickAddPartyModal .vy-f:last-child { margin-bottom: 0; }
#quickAddPartyModal .vy-f label {
    font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; display: block;
    text-transform: uppercase; letter-spacing: 0.3px;
}
#quickAddPartyModal .vy-f label .text-danger { color: var(--danger-color); }
#quickAddPartyModal .vy-f input, #quickAddPartyModal .vy-f select, #quickAddPartyModal .vy-f textarea {
    width: 100%; height: 38px; border: 1px solid #e0e0e0; border-radius: 8px;
    padding: 0 11px; font-size: 13px; color: #1a1a1a; background: #fafafa; transition: border 0.2s;
}
#quickAddPartyModal .vy-f textarea { height: auto; padding: 9px 11px; resize: vertical; min-height: 56px; }
#quickAddPartyModal .vy-f input:focus, #quickAddPartyModal .vy-f select:focus, #quickAddPartyModal .vy-f textarea:focus {
    outline: none; border-color: var(--primary-color); background: #fff;
    box-shadow: 0 0 0 3px rgba(237, 26, 59, 0.08);
}
#quickAddPartyModal .vy-f input::placeholder { color: #bbb; }
#quickAddPartyModal .vy-f-row { display: flex; gap: 10px; }
#quickAddPartyModal .vy-f-row .vy-f { flex: 1; }
#quickAddPartyModal .vy-input-addon { display: flex; align-items: stretch; }
#quickAddPartyModal .vy-input-addon span {
    display: flex; align-items: center; justify-content: center; width: 36px;
    background: #f0f0f0; border: 1px solid #e0e0e0; border-right: none;
    border-radius: 8px 0 0 8px; font-size: 13px; font-weight: 700; color: #888;
}
#quickAddPartyModal .vy-input-addon input { border-radius: 0 8px 8px 0 !important; flex: 1; }
#quickAddPartyModal .vy-tax-pills { display: flex; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
#quickAddPartyModal .vy-pill {
    border: none; background: #fafafa; padding: 4px 13px; font-size: 12px;
    font-weight: 600; color: #888; cursor: pointer; transition: 0.2s;
}
#quickAddPartyModal .vy-pill + .vy-pill { border-left: 1px solid #e0e0e0; }
#quickAddPartyModal .vy-pill.active { background: var(--primary-color); color: #fff; }
#quickAddPartyModal .vy-pill:hover:not(.active) { background: #f0f0f0; }
#quickAddPartyModal .qp-balance-pills .btn { font-size: 12px; }
#quickAddPartyModal .qp-hint { font-size: 11px; color: #888; }
#qaProfitValue.text-danger { color: #dc3545; }
</style>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" id="saleForm" class="sale-form">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="subtotal" id="hidden_subtotal" value="0">
    <input type="hidden" name="total_discount" id="hidden_discount" value="0">
    <input type="hidden" name="total_tax" id="hidden_tax" value="0">
    <input type="hidden" name="grand_total" id="hidden_grand" value="0">

    <div class="row g-3 mb-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body py-2 d-flex align-items-center gap-3">
                    <span class="fw-semibold" id="modeCashLabel">Cash</span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="saleMode" name="sale_mode" value="credit" <?= $old['sale_mode'] === 'credit' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="saleMode">&nbsp;</label>
                    </div>
                    <span class="fw-semibold" id="modeCreditLabel">Credit</span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Invoice No</label>
            <input type="text" name="invoice_no" class="form-control" value="<?= sanitize($invoiceNo) ?>" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" name="date" class="form-control" value="<?= sanitize($old['date']) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Party *</label>
            <div class="party-search-wrapper">
                <?php
                $selectedPartyName = '';
                if ($old['party_id'] > 0) {
                    foreach ($customers as $c) {
                        if ($c['id'] == $old['party_id']) { $selectedPartyName = $c['name']; break; }
                    }
                }
                ?>
                <input type="text" id="party_search" class="form-control" placeholder="Type to search party..." autocomplete="off" value="<?= sanitize($selectedPartyName) ?>" style="padding-right:34px;">
                <button type="button" class="clear-party-btn" id="clearParty" title="Clear party"><i class="fas fa-times"></i></button>
                <input type="hidden" name="party_id" id="party_id" value="<?= $old['party_id'] ?>">
                <div class="party-search-dropdown" id="partyDropdown"></div>
            </div>
            <small class="text-muted"><button type="button" class="add-party-link" id="addPartyLink"><i class="fas fa-plus me-1"></i> Add New Party</button></small>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0"><i class="fas fa-boxes me-1"></i> Items</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">#</th>
                            <th style="width:28%">Item</th>
                            <th style="width:8%">Qty</th>
                            <th style="width:12%">Rate</th>
                            <th style="width:10%">Disc ₹</th>
                            <th style="width:10%">Tax %</th>
                            <th style="width:10%" class="text-end">Tax Amt</th>
                            <th style="width:12%" class="text-end">Total</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <?php if (count($existingItems) > 0): ?>
                            <?php foreach ($existingItems as $eiIdx => $ei): ?>
                                <tr class="item-row" data-index="<?= $eiIdx ?>" data-stock="<?= (int) $ei['item_stock'] + (int) $ei['qty'] ?>">
                                    <td><?= $eiIdx + 1 ?></td>
                                    <td>
                                        <input type="hidden" name="item_id[]" class="item-id" value="<?= (int) $ei['item_id'] ?>">
                                        <div class="item-search-wrapper">
                                            <input type="text" class="form-control form-control-sm item-search" placeholder="Type to search..." autocomplete="off" value="<?= sanitize($ei['item_name']) ?>" data-selected-name="<?= sanitize($ei['item_name']) ?>">
                                            <button type="button" class="clear-item-btn show" title="Clear item"><i class="fas fa-times"></i></button>
                                            <div class="item-search-dropdown"></div>
                                        </div>
                                    </td>
                                    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="<?= (int) $ei['qty'] ?>"></td>
                                    <td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="<?= sanitize(number_format((float) $ei['rate'], 2, '.', '')) ?>"></td>
                                    <td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" value="<?= sanitize(number_format((float) $ei['discount'], 2, '.', '')) ?>"></td>
                                    <td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="<?= (float) $ei['tax_rate'] ?>"></td>
                                    <td class="text-end fw-bold item-line-tax text-muted">0.00</td>
                                    <td class="text-end fw-bold item-line-total">0.00</td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="item-row" data-index="0">
                                <td>1</td>
                                <td>
                                    <input type="hidden" name="item_id[]" class="item-id" value="">
                                    <div class="item-search-wrapper">
                                        <input type="text" class="form-control form-control-sm item-search" placeholder="Type to search..." autocomplete="off">
                                        <button type="button" class="clear-item-btn" title="Clear item"><i class="fas fa-times"></i></button>
                                        <div class="item-search-dropdown"></div>
                                    </div>
                                </td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="1"></td>
                                <td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="0"></td>
                                <td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" value="0"></td>
                                <td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="0"></td>
                                <td class="text-end fw-bold item-line-tax text-muted">0.00</td>
                                <td class="text-end fw-bold item-line-total">0.00</td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-2">
                <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="fas fa-plus me-1"></i> Add Item</button>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?= sanitize($old['notes']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body totals-box">
                    <div class="total-row"><span>Subtotal</span><span id="disp_subtotal">0.00</span></div>
                    <div class="total-row text-danger"><span>Discount</span><span id="disp_discount">-0.00</span></div>
                    <div class="total-row"><span>Tax</span><span id="disp_tax">0.00</span></div>
                    <div class="total-row grand"><span>Total</span><span id="disp_grand">0.00</span></div>
                    <hr>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Amount Paid</label>
                        <input type="number" name="paid_amount" id="paid_amount" class="form-control" step="0.01" min="0" value="<?= $old['paid_amount'] ?>">
                    </div>
                    <div class="total-row">
                        <span>Due Amount</span>
                        <span id="disp_due" class="text-danger fw-bold">0.00</span>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" id="paymentMethod" class="form-select">
                            <option value="cash" <?= $old['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="bank" <?= $old['payment_method'] === 'bank' ? 'selected' : '' ?>>Bank</option>
                            <option value="upi" <?= $old['payment_method'] === 'upi' ? 'selected' : '' ?>>UPI</option>
                            <option value="cheque" <?= $old['payment_method'] === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            <option value="credit" <?= $old['payment_method'] === 'credit' ? 'selected' : '' ?>>Credit</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save & Print</button>
        <a href="sales.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
    </div>
</form>

<!-- Quick Add Item Modal -->
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="fas fa-bolt me-1 text-primary"></i> Quick Add Item</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickAddForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="modal-body py-3">
                    <div id="quickAddError" class="alert alert-danger py-2 px-3 mb-2 d-none" style="font-size:13px;"></div>
                    <div class="vy-add-grid">

                        <!-- LEFT COLUMN -->
                        <div class="vy-add-left">
                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-camera"></i> Item Photo</div>
                                <div class="vy-card-body">
                                    <div class="vy-img-upload" id="qaImageUpload">
                                        <div class="vy-img-placeholder" id="qaImagePlaceholder">
                                            <i class="fas fa-cloud-arrow-up"></i>
                                            <span>Tap to add photo</span>
                                            <small>JPG, PNG or WebP &middot; Max 2MB</small>
                                        </div>
                                        <img id="qaImagePreview" class="d-none" alt="Preview">
                                        <input type="file" name="image" id="qaImageInput" accept="image/*" onchange="qaPreviewImage(this)">
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-tag"></i> Basic Info</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Item Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" required placeholder="e.g. Dell Laptop Inspiron 15" autocomplete="off">
                                    </div>
                                    <div class="vy-f-row">
                                        <div class="vy-f">
                                            <label>Category</label>
                                            <select name="category_id">
                                                <option value="0">-- Select --</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="vy-f">
                                            <label>Unit</label>
                                            <select name="unit">
                                                <?php foreach ($units as $u): ?>
                                                    <option value="<?= h($u['short_name']) ?>" <?= $u['short_name'] === 'Pcs' ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['short_name']) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Description</label>
                                        <textarea name="description" rows="2" placeholder="Optional description..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-boxes-stacked"></i> Inventory</div>
                                <div class="vy-card-body">
                                    <div class="vy-track-toggle">
                                        <div>
                                            <div class="vy-toggle-label">Track Inventory</div>
                                            <div class="vy-toggle-desc">Enable stock tracking for this item</div>
                                        </div>
                                        <label class="vy-switch">
                                            <input type="checkbox" name="track_stock" checked id="qaTrackStock" onchange="qaToggleStock()">
                                            <span class="vy-slider"></span>
                                        </label>
                                    </div>
                                    <div id="qaStockFields">
                                        <div class="vy-f">
                                            <label>SKU</label>
                                            <div class="vy-sku-row">
                                                <input type="text" name="sku" placeholder="<?= h($suggestedSku) ?>" value="<?= h($suggestedSku) ?>">
                                                <button type="button" class="vy-btn-icon" onclick="qaGenerateSku()" title="Generate SKU"><i class="fas fa-dice"></i></button>
                                            </div>
                                        </div>
                                        <div class="vy-f-row">
                                            <div class="vy-f">
                                                <label>Barcode</label>
                                                <div class="vy-sku-row">
                                                    <input type="text" name="barcode" placeholder="Enter or scan">
                                                    <button type="button" class="vy-btn-icon" title="Scan Barcode"><i class="fas fa-barcode"></i></button>
                                                </div>
                                            </div>
                                            <div class="vy-f">
                                                <label>HSN Code</label>
                                                <input type="text" name="hsn_code" placeholder="GST HSN">
                                            </div>
                                        </div>
                                        <div class="vy-f-row">
                                            <div class="vy-f">
                                                <label>Opening Stock</label>
                                                <input type="number" name="opening_stock" min="0" value="0">
                                            </div>
                                            <div class="vy-f">
                                                <label>Min Stock Alert</label>
                                                <input type="number" name="min_stock" min="0" value="10">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN -->
                        <div class="vy-add-right">
                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-cart-shopping"></i> Purchase Price</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Purchase Price <span class="text-danger">*</span></label>
                                        <div class="vy-input-addon">
                                            <span>₹</span>
                                            <input type="number" name="purchase_price" id="qaPurchasePrice" step="0.01" min="0" required placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Purchase Tax</label>
                                        <select name="purchase_tax_rate_id" id="qaPurchaseTaxRate">
                                            <option value="0">No Tax</option>
                                            <?php foreach ($taxRates as $tr): ?>
                                                <option value="<?= $tr['id'] ?>" data-rate="<?= $tr['rate'] ?>"><?= h($tr['name']) ?> (<?= number_format($tr['rate'], 1) ?>%)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="vy-tax-toggle">
                                        <span class="vy-tax-label">Tax</span>
                                        <div class="vy-tax-pills">
                                            <button type="button" class="vy-pill active" data-target="qaPurchaseTaxMode" data-val="exclusive" onclick="qaSetTaxMode(this)">Excl.</button>
                                            <button type="button" class="vy-pill" data-target="qaPurchaseTaxMode" data-val="inclusive" onclick="qaSetTaxMode(this)">Incl.</button>
                                        </div>
                                        <input type="hidden" name="purchase_tax_mode" id="qaPurchaseTaxMode" value="exclusive">
                                    </div>
                                    <div class="vy-price-breakdown" id="qaPurchaseBreakdown">
                                        <div class="vy-bd-row">
                                            <span>Base Price</span>
                                            <span id="qaPpBase">₹0.00</span>
                                        </div>
                                        <div class="vy-bd-row">
                                            <span>Tax Amount</span>
                                            <span id="qaPpTaxAmt">₹0.00</span>
                                        </div>
                                        <div class="vy-bd-divider"></div>
                                        <div class="vy-bd-row vy-bd-total">
                                            <span>Total (incl. tax)</span>
                                            <span id="qaPpTotal">₹0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-tag"></i> Sale Price</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Sale Price <span class="text-danger">*</span></label>
                                        <div class="vy-input-addon">
                                            <span>₹</span>
                                            <input type="number" name="sale_price" id="qaSalePrice" step="0.01" min="0" required placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Sale Tax</label>
                                        <select name="tax_rate_id" id="qaSaleTaxRate">
                                            <option value="0">No Tax</option>
                                            <?php foreach ($taxRates as $tr): ?>
                                                <option value="<?= $tr['id'] ?>" data-rate="<?= $tr['rate'] ?>"><?= h($tr['name']) ?> (<?= number_format($tr['rate'], 1) ?>%)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="vy-tax-toggle">
                                        <span class="vy-tax-label">Tax</span>
                                        <div class="vy-tax-pills">
                                            <button type="button" class="vy-pill active" data-target="qaSaleTaxMode" data-val="exclusive" onclick="qaSetTaxMode(this)">Excl.</button>
                                            <button type="button" class="vy-pill" data-target="qaSaleTaxMode" data-val="inclusive" onclick="qaSetTaxMode(this)">Incl.</button>
                                        </div>
                                        <input type="hidden" name="sale_tax_mode" id="qaSaleTaxMode" value="exclusive">
                                    </div>
                                    <div class="vy-price-breakdown" id="qaSaleBreakdown">
                                        <div class="vy-bd-row">
                                            <span>Base Price</span>
                                            <span id="qaSpBase">₹0.00</span>
                                        </div>
                                        <div class="vy-bd-row">
                                            <span>Tax Amount</span>
                                            <span id="qaSpTaxAmt">₹0.00</span>
                                        </div>
                                        <div class="vy-bd-divider"></div>
                                        <div class="vy-bd-row vy-bd-total">
                                            <span>Total (incl. tax)</span>
                                            <span id="qaSpTotal">₹0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-calculator"></i> Profit Calculator</div>
                                <div class="vy-card-body">
                                    <div class="vy-profit-box" id="qaProfitPreview">
                                        <div class="vy-profit-row">
                                            <span>Purchase (excl. tax)</span>
                                            <span id="qaPpVal">₹0.00</span>
                                        </div>
                                        <div class="vy-profit-row">
                                            <span>Sale (excl. tax)</span>
                                            <span id="qaSpVal">₹0.00</span>
                                        </div>
                                        <div class="vy-profit-divider"></div>
                                        <div class="vy-profit-row vy-profit-total">
                                            <span>Profit</span>
                                            <span id="qaProfitValue">₹0.00</span>
                                        </div>
                                        <div class="vy-profit-row">
                                            <span>Margin</span>
                                            <span id="qaMarginValue">0%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"><i class="fas fa-xmark me-1"></i> Cancel</button>
                    <div class="d-flex align-items-center gap-2 px-3 py-1 rounded" style="background:#f1f3f5;">
                        <label class="form-check-label fw-semibold" style="font-size:13px;color:#495057;">Active</label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="status" value="1" checked style="cursor:pointer;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" id="quickAddSubmit"><i class="fas fa-check me-1"></i> Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Add Party Modal -->
<div class="modal fade" id="quickAddPartyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="fas fa-user-plus me-1 text-primary"></i> Quick Add Party</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickAddPartyForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="modal-body py-3">
                    <div id="quickAddPartyError" class="alert alert-danger py-2 px-3 mb-2 d-none" style="font-size:13px;"></div>
                    <div class="vy-add-grid">

                        <!-- LEFT COLUMN -->
                        <div class="vy-add-left">
                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-users"></i> Party Type</div>
                                <div class="vy-card-body">
                                    <div class="vy-tax-pills" id="qpTypePills">
                                        <button type="button" class="vy-pill active" data-val="customer" onclick="qpSetType(this)"><i class="fas fa-user me-1"></i> Customer</button>
                                        <button type="button" class="vy-pill" data-val="supplier" onclick="qpSetType(this)"><i class="fas fa-truck me-1"></i> Supplier</button>
                                        <button type="button" class="vy-pill" data-val="both" onclick="qpSetType(this)"><i class="fas fa-arrows-left-right me-1"></i> Both</button>
                                    </div>
                                    <input type="hidden" name="type" id="qpTypeInput" value="customer">
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-id-card"></i> Contact Details</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Party Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" required placeholder="e.g. Rahul Traders">
                                    </div>
                                    <div class="vy-f-row">
                                        <div class="vy-f">
                                            <label>Phone</label>
                                            <input type="text" name="phone" placeholder="9876543210">
                                        </div>
                                        <div class="vy-f">
                                            <label>Email</label>
                                            <input type="email" name="email" placeholder="rahul@email.com">
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Party Group</label>
                                        <input type="text" name="party_group" placeholder="e.g. Wholesalers, Retailers">
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-location-dot"></i> Address</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Address</label>
                                        <textarea name="address" rows="2" placeholder="Full address..."></textarea>
                                    </div>
                                    <div class="vy-f-row">
                                        <div class="vy-f">
                                            <label>City</label>
                                            <input type="text" name="city" placeholder="Mumbai">
                                        </div>
                                        <div class="vy-f">
                                            <label>State</label>
                                            <input type="text" name="state" placeholder="Maharashtra">
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Pincode</label>
                                        <input type="text" name="pincode" maxlength="6" placeholder="400001">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN -->
                        <div class="vy-add-right">
                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-file-invoice"></i> Tax & Compliance</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>GST Registration Type</label>
                                        <select name="gst_reg_type">
                                            <option value="">-- Select --</option>
                                            <?php
                                            $qpGstTypes = [
                                                'unregistered' => 'Unregistered / Consumer',
                                                'regular'      => 'Regular Taxable Person',
                                                'composition'  => 'Composition Taxable Person',
                                                'sez_unit'     => 'SEZ Unit',
                                                'sez_dev'      => 'SEZ Developer',
                                                'non_resident' => 'Non-Resident Taxable Person',
                                                'oidar'        => 'Non-Resident Online (OIDAR)',
                                                'isd'          => 'Input Service Distributor (ISD)',
                                                'tds'          => 'Tax Deductor',
                                                'tcs'          => 'Tax Collector (eTCS)',
                                                'urp'          => 'URP (Unregistered Person)',
                                            ];
                                            foreach ($qpGstTypes as $qpVal => $qpLabel):
                                            ?>
                                                <option value="<?= $qpVal ?>"><?= $qpLabel ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="vy-f">
                                        <label>GSTIN</label>
                                        <input type="text" name="gstin" maxlength="15" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase;">
                                    </div>
                                    <div class="vy-f">
                                        <label>PAN</label>
                                        <input type="text" name="pan" maxlength="10" placeholder="AAAAA0000A" style="text-transform:uppercase;">
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-indian-rupee-sign"></i> Opening Balance</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <label>Amount</label>
                                        <div class="vy-input-addon">
                                            <span>&#8377;</span>
                                            <input type="number" step="0.01" min="0" name="opening_balance" value="0">
                                        </div>
                                    </div>
                                    <div class="vy-f">
                                        <label>Type</label>
                                        <div class="d-flex gap-2 qp-balance-pills" id="qpBalancePills">
                                            <button type="button" class="btn btn-sm rounded-pill fw-semibold btn-success" onclick="qpSetBalance('credit', this)"><i class="fas fa-arrow-down me-1"></i> You'll Receive</button>
                                            <button type="button" class="btn btn-sm rounded-pill fw-semibold btn-outline-secondary" onclick="qpSetBalance('debit', this)"><i class="fas fa-arrow-up me-1"></i> You'll Pay</button>
                                        </div>
                                        <input type="hidden" name="balance_type" id="qpBalanceInput" value="credit">
                                        <div class="mt-2 qp-hint">
                                            <i class="fas fa-info-circle me-1"></i>
                                            <span id="qpBalanceHint">Party owes you money (Receivable)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="vy-card">
                                <div class="vy-card-head"><i class="fas fa-sticky-note"></i> Notes</div>
                                <div class="vy-card-body">
                                    <div class="vy-f">
                                        <textarea name="notes" rows="3" placeholder="Any additional notes about this party..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal"><i class="fas fa-xmark me-1"></i> Cancel</button>
                    <div class="d-flex align-items-center gap-2 px-3 py-1 rounded" style="background:#f1f3f5;">
                        <label class="form-check-label fw-semibold" style="font-size:13px;color:#495057;">Active</label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="status" value="active" checked style="cursor:pointer;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary" id="quickAddPartySubmit"><i class="fas fa-check me-1"></i> Save Party</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Custom Alert Modal -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
        <div class="modal-content">
            <div class="modal-header py-2 border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <div class="mb-2"><i class="fas fa-exclamation-triangle text-danger" style="font-size:42px;"></i></div>
                <h6 class="fw-bold mb-1" id="alertModalTitle">Error</h6>
                <p class="text-muted mb-0" id="alertModalMessage" style="font-size:13px;"></p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-sm btn-primary px-4" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var rowIndex = document.querySelectorAll('.item-row').length + 1;

    function showAlertModal(title, message) {
        document.getElementById('alertModalTitle').textContent = title || 'Error';
        document.getElementById('alertModalMessage').textContent = message || '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('alertModal')).show();
    }

    function getRowQtyMap(excludeRow) {
        var map = {};
        document.querySelectorAll('.item-row').forEach(function(row) {
            if (row === excludeRow) return;
            var id = row.querySelector('.item-id').value;
            if (!id) return;
            map[id] = (map[id] || 0) + (parseFloat(row.querySelector('.item-qty').value) || 0);
        });
        return map;
    }

    function stockAvailableFor(row, itemStock, itemId) {
        var id = itemId || row.querySelector('.item-id').value;
        if (!id) return Infinity;
        var used = getRowQtyMap(row)[id] || 0;
        return (parseFloat(itemStock) || 0) - used;
    }

    function selectItem(row, item) {
        var available = stockAvailableFor(row, item.current_stock, item.id);
        if (available <= 0) {
            showAlertModal('Out of Stock', '"' + item.name + '" has no stock available to sell.');
            return false;
        }
        row.querySelector('.item-search').value = item.name;
        row.querySelector('.item-search').dataset.selectedName = item.name;
        row.querySelector('.item-id').value = item.id;
        row.querySelector('.item-rate').value = parseFloat(item.sale_price).toFixed(2);
        row.querySelector('.item-tax').value = item.tax_rate || 0;
        row.dataset.stock = item.current_stock;
        row.querySelector('.item-qty').value = 1;
        row.querySelector('.clear-item-btn').classList.add('show');
        return true;
    }

    function refreshClearItem(row) {
        row.querySelector('.clear-item-btn').classList.toggle('show', !!row.querySelector('.item-id').value);
    }

    // === Party Search ===
    var partySearch = document.getElementById('party_search');
    var partyIdField = document.getElementById('party_id');
    var partyDropdown = document.getElementById('partyDropdown');
    var clearPartyBtn = document.getElementById('clearParty');
    var partyTimer;

    function refreshClearParty() {
        clearPartyBtn.classList.toggle('show', partySearch.value.trim().length > 0);
    }

    clearPartyBtn.addEventListener('click', function() {
        partySearch.value = '';
        partyIdField.value = '';
        partyDropdown.classList.remove('show');
        refreshClearParty();
        partySearch.focus();
    });

    partySearch.addEventListener('input', function() {
        clearTimeout(partyTimer);
        refreshClearParty();
        var q = this.value.trim();
        if (q.length < 2) { partyDropdown.classList.remove('show'); return; }
        partyTimer = setTimeout(function() {
            fetch('api/parties_search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    partyDropdown.innerHTML = '';
                    if (data.length === 0) {
                        var nr = document.createElement('div');
                        nr.className = 'no-results';
                        nr.style.cssText = 'padding:10px;text-align:center;color:#6c757d;font-size:13px;';
                        nr.textContent = 'No parties found';
                        partyDropdown.appendChild(nr);
                    } else {
                        data.forEach(function(p) {
                            var d = document.createElement('div');
                            d.className = 'srch-item';
                            var bal = parseFloat(p.balance) || 0;
                            var balClass = bal > 0 ? 'text-success' : (bal < 0 ? 'text-danger' : 'text-muted');
                            var balSign = bal < 0 ? '-' : '';
                            d.innerHTML = '<div class="d-flex justify-content-between align-items-center gap-2"><div><strong>' + escapeH(p.name) + '</strong><br><small class="text-muted">' + escapeH(p.phone || '') + '</small></div><span class="p-bal ' + balClass + '">Bal: ' + balSign + '₹' + Math.abs(bal).toFixed(2) + '</span></div>';
                            d.addEventListener('click', function() {
                                partySearch.value = p.name;
                                partyIdField.value = p.id;
                                partyDropdown.classList.remove('show');
                                refreshClearParty();
                            });
                            partyDropdown.appendChild(d);
                        });
                    }
                    var qa = document.createElement('div');
                    qa.className = 'qa-link';
                    qa.innerHTML = '<i class="fas fa-plus me-1"></i> Quick Add Party';
                    qa.addEventListener('click', function(e) {
                        e.stopPropagation();
                        partyDropdown.classList.remove('show');
                        openQuickAddParty();
                    });
                    partyDropdown.appendChild(qa);
                    partyDropdown.classList.add('show');
                });
        }, 300);
    });

    partySearch.addEventListener('focus', function() { if (this.value.trim().length >= 2) partyDropdown.classList.add('show'); });
    document.addEventListener('click', function(e) {
        if (!partySearch.parentElement.contains(e.target)) partyDropdown.classList.remove('show');
    });

    // === Quick Add Party ===
    var quickPartyModal = document.getElementById('quickAddPartyModal');
    var quickPartyForm = document.getElementById('quickAddPartyForm');

    function openQuickAddParty() {
        closeDropdowns();
        quickPartyForm.reset();
        var errBox = document.getElementById('quickAddPartyError');
        if (errBox) errBox.classList.add('d-none');
        var typePills = document.getElementById('qpTypePills');
        if (typePills) {
            var custPill = typePills.querySelector('.vy-pill[data-val="customer"]');
            typePills.querySelectorAll('.vy-pill').forEach(function(p) { p.classList.remove('active'); });
            if (custPill) custPill.classList.add('active');
            document.getElementById('qpTypeInput').value = 'customer';
        }
        qpSetBalance('credit', document.querySelector('#qpBalancePills .btn'));
        bootstrap.Modal.getOrCreateInstance(quickPartyModal).show();
        setTimeout(function() { quickPartyForm.querySelector('input[name="name"]').focus(); }, 350);
    }

    document.getElementById('addPartyLink').addEventListener('click', openQuickAddParty);

    quickPartyForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var errBox = document.getElementById('quickAddPartyError');
        var btn = document.getElementById('quickAddPartySubmit');
        errBox.classList.add('d-none');
        btn.disabled = true;

        fetch('api/party_quick_add.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            btn.disabled = false;
            if (!res.ok || res.data.error) {
                errBox.textContent = res.data.error || 'Failed to add party.';
                errBox.classList.remove('d-none');
                return;
            }
            var party = res.data;
            partySearch.value = party.name;
            partyIdField.value = party.id;
            partyDropdown.classList.remove('show');
            refreshClearParty();
            bootstrap.Modal.getInstance(quickPartyModal).hide();
        })
        .catch(function(err) {
            btn.disabled = false;
            errBox.textContent = 'Network error: ' + err.message;
            errBox.classList.remove('d-none');
        });
    });

    // === Sale Mode (Cash / Credit) ===
    var saleMode = document.getElementById('saleMode');
    var paymentMethodSel = document.getElementById('paymentMethod');
    var paidAmountInput = document.getElementById('paid_amount');
    var walkInName = <?= json_encode($walkIn['name']) ?>;
    var walkInId = <?= (int) $walkIn['id'] ?>;
    var cashMethods = '<option value="cash" selected>Cash</option>' +
        '<option value="bank">Bank</option>' +
        '<option value="upi">UPI</option>' +
        '<option value="cheque">Cheque</option>';

    var modeApplied = false;

    function applySaleMode() {
        var prevMethod = paymentMethodSel.value;
        if (saleMode.checked) {
            paymentMethodSel.innerHTML = '<option value="credit" selected>Credit</option>';
            if (modeApplied) {
                partySearch.value = '';
                partyIdField.value = '';
                paidAmountInput.value = 0;
            }
            paidAmountInput.readOnly = false;
        } else {
            paymentMethodSel.innerHTML = cashMethods;
            if (prevMethod && prevMethod !== 'credit') paymentMethodSel.value = prevMethod;
            if (modeApplied) {
                partySearch.value = walkInName;
                partyIdField.value = walkInId;
            }
            paidAmountInput.readOnly = true;
        }
        partyDropdown.classList.remove('show');
        refreshClearParty();
        calcGrand();
        modeApplied = true;
    }

    saleMode.addEventListener('change', applySaleMode);
    applySaleMode();
    refreshClearParty();

    // === Item Search ===
    function positionDropdown(input, dropdown) {
        var r = input.getBoundingClientRect();
        var w = Math.max(460, Math.min(600, window.innerWidth - r.left - 8));
        dropdown.style.top = (r.bottom + 2) + 'px';
        dropdown.style.left = r.left + 'px';
        dropdown.style.width = w + 'px';
    }

    function closeDropdowns() {
        document.querySelectorAll('.sale-form .item-search-dropdown').forEach(function(d) { d.classList.remove('show'); });
    }

    // === Quick Add Item ===
    var activeRow = null;
    var activeOpenFn = null;
    var quickModal = document.getElementById('quickAddModal');

    function openQuickAdd(row, openFn) {
        activeRow = row;
        activeOpenFn = openFn;
        closeDropdowns();
        bootstrap.Modal.getOrCreateInstance(quickModal).show();
    }

    document.getElementById('quickAddForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var errBox = document.getElementById('quickAddError');
        var btn = document.getElementById('quickAddSubmit');
        errBox.classList.add('d-none');
        btn.disabled = true;

        fetch('api/item_quick_add.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            btn.disabled = false;
            if (!res.ok || res.data.error) {
                errBox.textContent = res.data.error || 'Failed to add item.';
                errBox.classList.remove('d-none');
                return;
            }
            var item = res.data;
            if (activeRow) {
                var ok = selectItem(activeRow, item);
                calcRow(activeRow);
                calcGrand();
                if (!ok) {
                    bootstrap.Modal.getInstance(quickModal).hide();
                    return;
                }
            }
            qaResetForm();
            bootstrap.Modal.getInstance(quickModal).hide();
        })
        .catch(function(err) {
            btn.disabled = false;
            errBox.textContent = 'Network error: ' + err.message;
            errBox.classList.remove('d-none');
        });
    });

    quickModal.addEventListener('hidden.bs.modal', function() {
        if (activeOpenFn) { activeOpenFn(); activeOpenFn = null; }
    });

    function initItemSearch(row) {
        var searchInput = row.querySelector('.item-search');
        var dropdown = row.querySelector('.item-search-dropdown');
        var timer;

        function loadItems(q) {
            fetch('api/items_search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(items) {
                    positionDropdown(searchInput, dropdown);
                    dropdown.innerHTML = '';
                    if (items.length === 0) {
                        var nr = document.createElement('div');
                        nr.className = 'no-results';
                        nr.textContent = 'No items found';
                        dropdown.appendChild(nr);
                    } else {
                        var head = document.createElement('div');
                        head.className = 'dd-head';
                        head.innerHTML = '<span>Item</span><span>Stock</span><span>HSN</span><span class="si-price">Purchase</span><span class="si-price">Sale</span>';
                        dropdown.appendChild(head);
                        items.forEach(function(item) {
                            var d = document.createElement('div');
                            d.className = 'srch-item';
                            var avail = (parseFloat(item.current_stock) || 0) - (getRowQtyMap(row)[item.id] || 0);
                            var stockHtml = avail > 0
                                ? '<div class="si-stock">' + avail + '</div>'
                                : '<div class="si-stock no-stock">Out of stock</div>';
                            d.innerHTML = '<div class="si-name">' + escapeH(item.name) + '</div>' + stockHtml + '<div class="si-hsn">' + escapeH(item.hsn_code || '') + '</div><div class="si-price si-purchase">₹' + parseFloat(item.purchase_price).toFixed(2) + '</div><div class="si-price">₹' + parseFloat(item.sale_price).toFixed(2) + '</div>';
                            if (avail <= 0) d.classList.add('no-stock');
                            d.addEventListener('click', function() {
                                dropdown.classList.remove('show');
                                if (!selectItem(row, item)) return;
                                calcRow(row);
                                calcGrand();
                            });
                            dropdown.appendChild(d);
                        });
                    }
                    var qa = document.createElement('div');
                    qa.className = 'qa-link';
                    qa.innerHTML = '<i class="fas fa-plus me-1"></i> Quick Add Item';
                    qa.addEventListener('click', function(e) {
                        e.stopPropagation();
                        openQuickAdd(row, open);
                    });
                    dropdown.appendChild(qa);
                    dropdown.classList.add('show');
                });
        }

        searchInput.addEventListener('input', function() {
            clearTimeout(timer);
            if (row.querySelector('.item-id').value && this.value !== this.dataset.selectedName) {
                row.querySelector('.item-id').value = '';
                row.querySelector('.clear-item-btn').classList.remove('show');
            }
            var q = this.value.trim();
            if (q.length < 2) { dropdown.classList.remove('show'); return; }
            timer = setTimeout(function() { loadItems(q); }, 300);
        });

        function open() {
            clearTimeout(timer);
            loadItems(searchInput.value.trim());
        }

        searchInput.addEventListener('focus', open);

        document.addEventListener('click', function(e) {
            if (e.target.closest('#addRow')) return;
            if (!searchInput.parentElement.contains(e.target)) dropdown.classList.remove('show');
        });

        return open;
    }

    window.addEventListener('scroll', closeDropdowns, true);
    window.addEventListener('resize', closeDropdowns);

    function r2(v) { return Math.round((v + Number.EPSILON) * 100) / 100; }

    function lineAmounts(row) {
        var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        var rate = parseFloat(row.querySelector('.item-rate').value) || 0;
        var discVal = parseFloat(row.querySelector('.item-disc').value) || 0;
        var taxPct = parseFloat(row.querySelector('.item-tax').value) || 0;

        var sub = r2(qty * rate);
        var tax = r2(qty * r2((rate * taxPct) / 100));
        var grossTotal = r2(sub + tax);
        var disc = r2(Math.min(discVal, grossTotal));
        var total = r2(sub + tax - disc);
        return { sub: sub, disc: disc, tax: tax, total: total };
    }

    function calcRow(row) {
        var a = lineAmounts(row);
        row.querySelector('.item-line-tax').textContent = '₹' + a.tax.toFixed(2);
        row.querySelector('.item-line-total').textContent = '₹' + a.total.toFixed(2);
    }

    function calcGrand() {
        var rows = document.querySelectorAll('.item-row');
        var sub = 0, disc = 0, tax = 0, grand = 0;
        rows.forEach(function(row) {
            var a = lineAmounts(row);
            sub += a.sub; disc += a.disc; tax += a.tax; grand += a.total;
            calcRow(row);
        });
        sub = r2(sub); disc = r2(disc); tax = r2(tax); grand = r2(grand);
        var paidInput = document.getElementById('paid_amount');
        var isCredit = document.getElementById('saleMode').checked;
        if (!isCredit) {
            paidInput.value = grand.toFixed(2);
        }
        var paid = parseFloat(paidInput.value) || 0;
        var due = r2(Math.max(0, grand - paid));

        document.getElementById('disp_subtotal').textContent = '₹' + sub.toFixed(2);
        document.getElementById('disp_discount').textContent = '-₹' + disc.toFixed(2);
        document.getElementById('disp_tax').textContent = '₹' + tax.toFixed(2);
        document.getElementById('disp_grand').textContent = '₹' + grand.toFixed(2);
        document.getElementById('disp_due').textContent = '₹' + due.toFixed(2);

        document.getElementById('hidden_subtotal').value = sub.toFixed(2);
        document.getElementById('hidden_discount').value = disc.toFixed(2);
        document.getElementById('hidden_tax').value = tax.toFixed(2);
        document.getElementById('hidden_grand').value = grand.toFixed(2);
    }

    // Event delegation for row inputs
    document.addEventListener('input', function(e) {
        if (e.target.matches('.item-qty, .item-rate, .item-disc, .item-tax')) {
            var row = e.target.closest('.item-row');
            if (row) { calcRow(row); calcGrand(); }
            if (e.target.matches('.item-qty') && row && row.querySelector('.item-id').value) {
                var qty = parseFloat(e.target.value) || 0;
                var available = stockAvailableFor(row, row.dataset.stock);
                if (qty > available) {
                    e.target.value = Math.max(1, Math.floor(available));
                    showAlertModal('Insufficient Stock', '"' + row.querySelector('.item-search').value + '" only has ' + Math.max(0, Math.floor(available)) + ' unit(s) available.');
                    calcRow(row);
                    calcGrand();
                }
            }
        }
        if (e.target.id === 'paid_amount') { calcGrand(); }
    });

    // Form submit validation
    document.getElementById('saleForm').addEventListener('submit', function(e) {
        if (!document.getElementById('party_id').value) {
            e.preventDefault();
            showAlertModal('Party Required', 'Please select a party.');
            return;
        }
        var hasItem = false;
        document.querySelectorAll('.item-row').forEach(function(row) {
            if (row.querySelector('.item-id').value) hasItem = true;
        });
        if (!hasItem) {
            e.preventDefault();
            showAlertModal('Item Required', 'Please select and add at least one item.');
            return;
        }
        var grand = parseFloat(document.getElementById('hidden_grand').value) || 0;
        var paid = parseFloat(document.getElementById('paid_amount').value) || 0;
        var isCredit = document.getElementById('saleMode').checked;
        if (!isCredit && Math.abs(paid - grand) > 0.009) {
            e.preventDefault();
            showAlertModal('Payment Required', 'For cash sales, the amount paid (₹' + paid.toFixed(2) + ') must equal the total amount (₹' + grand.toFixed(2) + ').');
            return;
        }
        var bad = null;
        document.querySelectorAll('.item-row').forEach(function(row) {
            if (bad) return;
            var id = row.querySelector('.item-id').value;
            if (!id) return;
            var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            var available = stockAvailableFor(row, row.dataset.stock);
            if (qty > available) {
                bad = '"' + row.querySelector('.item-search').value + '" (available: ' + Math.max(0, Math.floor(available)) + ', required: ' + qty + ')';
            }
        });
        if (bad) {
            e.preventDefault();
            showAlertModal('Insufficient Stock', 'Not enough stock for: ' + bad + '.');
            return;
        }
    });

    // Add row
    document.getElementById('addRow').addEventListener('click', function() {
        var container = document.getElementById('itemsContainer');
        var tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.setAttribute('data-index', rowIndex);
        tr.innerHTML = '<td>' + (container.querySelectorAll('.item-row').length + 1) + '</td>' +
            '<td><input type="hidden" name="item_id[]" class="item-id" value=""><div class="item-search-wrapper"><input type="text" class="form-control form-control-sm item-search" placeholder="Type to search..." autocomplete="off"><button type="button" class="clear-item-btn" title="Clear item"><i class="fas fa-times"></i></button><div class="item-search-dropdown"></div></div></td>' +
            '<td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="1"></td>' +
            '<td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="0"></td>' +
            '<td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" value="0"></td>' +
            '<td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="0"></td>' +
            '<td class="text-end fw-bold item-line-tax text-muted">0.00</td>' +
            '<td class="text-end fw-bold item-line-total">0.00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>';
        container.appendChild(tr);
        initItemSearch(tr);
        rowIndex++;
    });

    // Remove row
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.remove-row');
        if (!btn) return;
        var rows = document.querySelectorAll('.item-row');
        if (rows.length <= 1) return;
        btn.closest('.item-row').remove();
        // Re-number
        document.querySelectorAll('.item-row').forEach(function(r, i) { r.querySelector('td').textContent = i + 1; });
        calcGrand();
    });

    // Clear selected item
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.clear-item-btn');
        if (!btn) return;
        var row = btn.closest('.item-row');
        row.querySelector('.item-search').value = '';
        delete row.querySelector('.item-search').dataset.selectedName;
        row.querySelector('.item-id').value = '';
        row.querySelector('.item-qty').value = 1;
        row.querySelector('.item-rate').value = 0;
        row.querySelector('.item-disc').value = 0;
        row.querySelector('.item-tax').value = 0;
        row.querySelector('.item-line-tax').textContent = '₹0.00';
        row.querySelector('.item-line-total').textContent = '₹0.00';
        btn.classList.remove('show');
        row.querySelector('.item-search').focus();
        calcGrand();
    });

    // Init rows
    document.querySelectorAll('.item-row').forEach(function(row) { initItemSearch(row); });
    calcGrand();

    // === Quick Add Item modal: price / profit live calc ===
    var quickModalEl = quickModal;
    document.getElementById('qaPurchasePrice').addEventListener('input', qaCalcProfit);
    document.getElementById('qaSalePrice').addEventListener('input', qaCalcProfit);
    document.getElementById('qaPurchaseTaxRate').addEventListener('change', qaCalcProfit);
    document.getElementById('qaSaleTaxRate').addEventListener('change', qaCalcProfit);
    quickModalEl.addEventListener('show.bs.modal', function() {
        qaResetForm();
        setTimeout(function() { quickModalEl.querySelector('input[name="name"]').focus(); }, 350);
    });
});

function escapeH(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

// === Quick Add Item modal helpers (mirrors item_add.php) ===
function qaPreviewImage(input) {
    var preview = document.getElementById('qaImagePreview');
    var placeholder = document.getElementById('qaImagePlaceholder');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function qpSetType(btn) {
    document.getElementById('qpTypeInput').value = btn.dataset.val;
    document.querySelectorAll('#qpTypePills .vy-pill').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
}

function qpSetBalance(val, btn) {
    document.getElementById('qpBalanceInput').value = val;
    var hint = document.getElementById('qpBalanceHint');
    var pills = document.querySelectorAll('#qpBalancePills .btn');
    pills.forEach(function(b) {
        b.classList.remove('btn-success', 'btn-danger');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    if (val === 'credit') {
        btn.classList.add('btn-success');
        hint.textContent = 'Party owes you money (Receivable)';
    } else {
        btn.classList.add('btn-danger');
        hint.textContent = 'You owe party money (Payable)';
    }
}

function qaGenerateSku() {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    var sku = 'ITM-';
    for (var i = 0; i < 6; i++) sku += chars.charAt(Math.floor(Math.random() * chars.length));
    document.querySelector('#quickAddForm input[name="sku"]').value = sku;
}

function qaToggleStock() {
    var fields = document.getElementById('qaStockFields');
    var on = document.getElementById('qaTrackStock').checked;
    fields.style.display = on ? 'block' : 'none';
    fields.querySelectorAll('input, select').forEach(function(i) { i.disabled = !on; });
}

function qaSetTaxMode(btn) {
    var target = btn.dataset.target;
    var val = btn.dataset.val;
    document.getElementById(target).value = val;
    btn.parentElement.querySelectorAll('.vy-pill').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    qaCalcProfit();
}

function qaGetTaxRate(selectEl) {
    var opt = selectEl.options[selectEl.selectedIndex];
    return parseFloat(opt.dataset.rate) || 0;
}

function qaCalcProfit() {
    var ppInput = parseFloat(document.getElementById('qaPurchasePrice').value) || 0;
    var spInput = parseFloat(document.getElementById('qaSalePrice').value) || 0;
    var ppMode = document.getElementById('qaPurchaseTaxMode').value;
    var spMode = document.getElementById('qaSaleTaxMode').value;
    var ppTaxPct = qaGetTaxRate(document.getElementById('qaPurchaseTaxRate'));
    var spTaxPct = qaGetTaxRate(document.getElementById('qaSaleTaxRate'));

    var ppBase, ppTaxAmt, ppTotal;
    if (ppMode === 'inclusive') {
        ppTotal = ppInput;
        ppBase = ppTaxPct > 0 ? ppInput / (1 + ppTaxPct / 100) : ppInput;
        ppTaxAmt = ppTotal - ppBase;
    } else {
        ppBase = ppInput;
        ppTaxAmt = ppInput * ppTaxPct / 100;
        ppTotal = ppBase + ppTaxAmt;
    }

    var spBase, spTaxAmt, spTotal;
    if (spMode === 'inclusive') {
        spTotal = spInput;
        spBase = spTaxPct > 0 ? spInput / (1 + spTaxPct / 100) : spInput;
        spTaxAmt = spTotal - spBase;
    } else {
        spBase = spInput;
        spTaxAmt = spInput * spTaxPct / 100;
        spTotal = spBase + spTaxAmt;
    }

    var profit = spBase - ppBase;
    var margin = spBase > 0 ? ((profit / spBase) * 100).toFixed(1) : 0;

    document.getElementById('qaPpBase').textContent = '₹' + ppBase.toFixed(2);
    document.getElementById('qaPpTaxAmt').textContent = '₹' + ppTaxAmt.toFixed(2);
    document.getElementById('qaPpTotal').textContent = '₹' + ppTotal.toFixed(2);
    document.getElementById('qaSpBase').textContent = '₹' + spBase.toFixed(2);
    document.getElementById('qaSpTaxAmt').textContent = '₹' + spTaxAmt.toFixed(2);
    document.getElementById('qaSpTotal').textContent = '₹' + spTotal.toFixed(2);
    document.getElementById('qaPpVal').textContent = '₹' + ppBase.toFixed(2);
    document.getElementById('qaSpVal').textContent = '₹' + spBase.toFixed(2);
    var pv = document.getElementById('qaProfitValue');
    pv.textContent = '₹' + profit.toFixed(2);
    pv.className = profit >= 0 ? 'text-success' : 'text-danger';
    var mv = document.getElementById('qaMarginValue');
    mv.textContent = margin + '%';
    mv.className = profit >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold';
}

function qaResetForm() {
    var form = document.getElementById('quickAddForm');
    form.reset();
    var qa = document.getElementById('quickAddForm');
    var prev = qa.querySelector('input[name="sku"]');
    prev.value = prev.placeholder;
    qa.querySelector('input[name="opening_stock"]').value = 0;
    qa.querySelector('input[name="min_stock"]').value = 10;
    qa.querySelector('select[name="tax_rate_id"]').value = 0;
    qa.querySelector('select[name="purchase_tax_rate_id"]').value = 0;
    document.getElementById('qaPurchaseTaxMode').value = 'exclusive';
    document.getElementById('qaSaleTaxMode').value = 'exclusive';
    qa.querySelectorAll('.vy-pill').forEach(function(p) { p.classList.remove('active'); });
    qa.querySelectorAll('.vy-pill[data-val="exclusive"]').forEach(function(p) { p.classList.add('active'); });
    var preview = document.getElementById('qaImagePreview');
    preview.classList.add('d-none');
    preview.removeAttribute('src');
    document.getElementById('qaImagePlaceholder').style.display = '';
    document.getElementById('qaTrackStock').checked = true;
    document.getElementById('qaStockFields').style.display = 'block';
    document.getElementById('qaStockFields').querySelectorAll('input, select').forEach(function(i) { i.disabled = false; });
    document.getElementById('quickAddError').classList.add('d-none');
    qaCalcProfit();
}
</script>

<?php include 'footer.php'; ?>
