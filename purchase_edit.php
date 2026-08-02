<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'search_items') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $items = fetchAll(
            "SELECT i.id, i.name, i.sku, i.purchase_price, i.purchase_price_with_tax, i.sale_price, i.current_stock,
                    COALESCE(pt.rate, 0) AS purchase_tax_rate
             FROM items i
             LEFT JOIN tax_rates pt ON i.purchase_tax_rate_id = pt.id
             WHERE i.status = 1 AND (i.name LIKE ? OR i.sku LIKE ?)
             ORDER BY i.name ASC LIMIT 10",
            ["%$q%", "%$q%"]
        );
        echo json_encode($items);
    } elseif ($_GET['ajax'] === 'search_suppliers') {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $suppliers = fetchAll(
            "SELECT id, name, phone, gstin
             FROM parties
             WHERE (type = 'supplier' OR type = 'both') AND status = 1
               AND (name LIKE ? OR phone LIKE ?)
             ORDER BY name ASC LIMIT 10",
            ["%$q%", "%$q%"]
        );
        echo json_encode($suppliers);
    }
    exit;
}

$purchaseId = intval($_GET['id'] ?? 0);
if ($purchaseId <= 0) { setFlash('danger', 'Invalid purchase ID.'); redirect('purchases.php'); }

$purchase = fetch("SELECT * FROM purchases WHERE id = ? AND status != 'cancelled'", [$purchaseId]);
if (!$purchase) { setFlash('danger', 'Purchase not found.'); redirect('purchases.php'); }

$existingItems = fetchAll("SELECT pi.*, i.name AS item_name, i.sku AS item_sku
    FROM purchase_items pi
    JOIN items i ON pi.item_id = i.id
    WHERE pi.purchase_id = ?", [$purchaseId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        redirect('purchase_edit.php?id=' . $purchaseId);
    }

    $partyId = (int)($_POST['party_id'] ?? 0);
    $date = dateDB($_POST['date'] ?? today());
    $paymentMethod = sanitize($_POST['payment_method'] ?? '');
    $discountAmount = (float)($_POST['discount_amount'] ?? 0);
    $paidAmount = (float)($_POST['paid_amount'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    $supplierBillNo = trim($_POST['supplier_bill_no'] ?? '');

    if ($partyId <= 0) { setFlash('danger', 'Please select a supplier.'); redirect('purchase_edit.php?id=' . $purchaseId); }
    if ($supplierBillNo === '') { setFlash('danger', 'Please enter Supplier Bill No.'); redirect('purchase_edit.php?id=' . $purchaseId); }
    if ($paidAmount > 0 && !in_array($paymentMethod, ['cash','bank','upi','cheque'])) { setFlash('danger', 'Please select a valid payment method (Cash, Bank, UPI, or Cheque) when amount paid is greater than 0.'); redirect('purchase_edit.php?id=' . $purchaseId); }
    if ($paidAmount == 0) { $paymentMethod = 'credit'; }
    if ($paymentMethod === 'cash' && $paidAmount > 0) {
        $cashBal = floatval(getSetting('cash_balance', '0'));
        if ($paidAmount > $cashBal) {
            $_SESSION['form_payment_method'] = $paymentMethod;
            $_SESSION['form_paid_amount'] = $paidAmount;
            setFlash('danger', 'Insufficient cash balance. Available: ' . number_format($cashBal, 2) . ' | Required: ' . number_format($paidAmount, 2));
            redirect('purchase_edit.php?id=' . $purchaseId);
        }
    }
    $supplierBillNo = sanitize($supplierBillNo);

    $itemIds = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    $taxRates = $_POST['tax_rate'] ?? [];

    if (empty($itemIds) || count($itemIds) === 0) {
        setFlash('danger', 'Please add at least one item.');
        redirect('purchase_edit.php?id=' . $purchaseId);
    }

    $subtotal = 0; $taxTotal = 0; $lineItems = [];
    for ($i = 0; $i < count($itemIds); $i++) {
        $itemId = (int)$itemIds[$i];
        if ($itemId <= 0) continue;
        $qty = max(1, (int)($qtys[$i] ?? 1));
        $rate = (float)($rates[$i] ?? 0);
        $disc = (float)($discounts[$i] ?? 0);
        $taxRate = (float)($taxRates[$i] ?? 0);
        $lineTotal = ($rate * $qty) - $disc;
        $lineTax = round($lineTotal * $taxRate / 100, 2);
        $lineTotal += $lineTax;
        $subtotal += ($rate * $qty);
        $taxTotal += $lineTax;
        $lineItems[] = ['item_id' => $itemId, 'qty' => $qty, 'rate' => $rate, 'discount' => $disc, 'tax_rate' => $taxRate, 'tax_amount' => $lineTax, 'total' => $lineTotal];
    }

    $total = $subtotal - $discountAmount + $taxTotal;
    $dueAmount = max(0, $total - $paidAmount);
    $paymentStatus = 'unpaid';
    if ($paidAmount >= $total && $total > 0) { $paymentStatus = 'paid'; }
    elseif ($paidAmount > 0) { $paymentStatus = 'partial'; }

    $pdo->beginTransaction();
    try {
        // Reverse old stock changes
        foreach ($existingItems as $ei) {
            updateStock($ei['item_id'], $ei['qty'], 'subtract');
        }

        // Delete old items
        query("DELETE FROM purchase_items WHERE purchase_id = ?", [$purchaseId]);

        // Update purchase
        query("UPDATE purchases SET party_id = ?, date = ?, subtotal = ?, tax_amount = ?, discount_amount = ?, total = ?, paid_amount = ?, due_amount = ?, payment_status = ?, payment_method = ?, supplier_bill_no = ?, notes = ? WHERE id = ?",
            [$partyId > 0 ? $partyId : null, $date, $subtotal, $taxTotal, $discountAmount, $total, $paidAmount, $dueAmount, $paymentStatus, $paymentMethod, $supplierBillNo ?: null, $notes, $purchaseId]);

        // Insert new items & apply stock
        foreach ($lineItems as $li) {
            query("INSERT INTO purchase_items (purchase_id, item_id, qty, rate, discount, tax_rate, tax_amount, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$purchaseId, $li['item_id'], $li['qty'], $li['rate'], $li['discount'], $li['tax_rate'], $li['tax_amount'], $li['total']]);
            updateStock($li['item_id'], $li['qty'], 'add');
        }

        // Update or create payment record
        $existingPayment = fetch("SELECT id FROM payments_out WHERE purchase_id = ?", [$purchaseId]);
        if ($paidAmount > 0) {
            if ($existingPayment) {
                query("UPDATE payments_out SET party_id = ?, date = ?, amount = ?, payment_method = ? WHERE purchase_id = ?",
                    [$partyId > 0 ? $partyId : null, $date, $paidAmount, $paymentMethod, $purchaseId]);
            } else {
                $paymentNo = generatePaymentNo();
                query("INSERT INTO payments_out (payment_no, party_id, purchase_id, date, amount, payment_method, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$paymentNo, $partyId > 0 ? $partyId : null, $purchaseId, $date, $paidAmount, $paymentMethod, $_SESSION['user_id']]);
            }
        } elseif ($existingPayment) {
            query("DELETE FROM payments_out WHERE purchase_id = ?", [$purchaseId]);
        }

        $pdo->commit();
        setFlash('success', "Purchase bill {$purchase['bill_no']} updated successfully.");
        header('Location: purchase_view.php?id=' . $purchaseId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error updating purchase: ' . $e->getMessage());
        header('Location: purchase_edit.php?id=' . $purchaseId);
        exit;
    }
}

$pageTitle = 'Edit Purchase - ' . sanitize($purchase['bill_no']);
$cashBalance = floatval(getSetting('cash_balance', '0'));

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$units = fetchAll("SELECT id, name, short_name FROM units WHERE status = 1 ORDER BY name ASC");
$taxRates = $pdo->query("SELECT id, name, rate FROM tax_rates ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$countItems = (int) $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$suggestedSku = 'ITM-' . str_pad($countItems + 1, 5, '0', STR_PAD_LEFT);
$formPaymentMethod = $_SESSION['form_payment_method'] ?? ($_POST['payment_method'] ?? $purchase['payment_method']);
$formPaidAmount = $_SESSION['form_paid_amount'] ?? ($_POST['paid_amount'] ?? $purchase['paid_amount']);
unset($_SESSION['form_payment_method'], $_SESSION['form_paid_amount']);
include 'header.php';

$supplier = $purchase['party_id'] ? fetch("SELECT id, name FROM parties WHERE id = ?", [$purchase['party_id']]) : null;
$existingItemsJson = json_encode(array_map(function($ei) {
    return ['id' => $ei['item_id'], 'name' => $ei['item_name'], 'sku' => $ei['item_sku'], 'qty' => $ei['qty'], 'rate' => $ei['rate'], 'discount' => $ei['discount'], 'tax_rate' => $ei['tax_rate']];
}, $existingItems));
?>

<style>
    .item-search-dropdown, .supplier-search-dropdown {
        position: fixed !important;
        top: auto;
        left: auto;
        right: auto;
        z-index: 1080;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
        max-height: 220px;
        overflow-y: auto;
        scrollbar-width: thin;
        display: none !important;
    }
    .item-search-dropdown { width: 720px !important; }
    .supplier-search-dropdown { width: 350px !important; }
    .item-search-dropdown .dropdown-item, .supplier-search-dropdown .dropdown-item {
        display: block;
        padding: 4px 12px;
        cursor: pointer;
        font-size: 12px;
        line-height: 1.25;
        border-bottom: 1px solid #f5f5f5;
        white-space: normal;
    }
    .item-search-dropdown .dropdown-item:last-child, .supplier-search-dropdown .dropdown-item:last-child {
        border-bottom: none;
    }
    .item-search-dropdown .dropdown-item:hover, .supplier-search-dropdown .dropdown-item:hover { background: #f0f4ff; }
    .item-dropdown-sticky {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #fff;
    }
    .item-dropdown-header {
        display: grid;
        grid-template-columns: 1fr 140px 92px 72px;
        gap: 6px;
        padding: 3px 12px;
        background: #f8f9fa;
        border-bottom: 1px solid #dee2e6;
        font-size: 9px;
        font-weight: 700;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .item-search-wrapper { position: relative; }
    .item-search-wrapper .item-search { padding-right: 30px; }
    .item-search-wrapper .item-search-clear {
        position: absolute;
        top: 50%;
        right: 6px;
        transform: translateY(-50%);
        width: 20px;
        height: 20px;
        border: none;
        border-radius: 50%;
        background: #e9ecef;
        color: #6c757d;
        font-size: 10px;
        line-height: 1;
        display: none;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 3;
    }
    .item-search-wrapper .item-search-clear:hover { background: #dc3545; color: #fff; }
    .item-dropdown-row {
        display: grid;
        grid-template-columns: 1fr 140px 92px 72px;
        gap: 6px;
        padding: 3px 12px;
        align-items: center;
    }
    .item-dropdown-row .id-name {
        font-weight: 600;
        color: #1a1a1a;
        line-height: 1.3;
    }
    .item-dropdown-row .id-name small {
        font-weight: 400;
        color: #888;
        font-size: 10px;
        display: block;
    }
    .item-dropdown-row .id-col {
        font-size: 11px;
        color: #666;
        text-align: right;
    }
    .item-dropdown-row .id-col.stock-low { color: #dc3545; font-weight: 600; }
    .item-dropdown-row .id-col.stock-ok { color: #28a745; }
    .item-dropdown-row .id-col.price { color: #2962FF; font-weight: 600; }
    .items-table td { vertical-align: middle; }
    .items-table input, .items-table select { font-size: 0.875rem; }
    .item-search-dropdown .qa-link {
        display: block; padding: 7px 10px; font-size: 12px; font-weight: 600; color: #2962FF;
        cursor: pointer; background: #f8f9fa; border-bottom: 1px solid #dee2e6; text-align: center;
    }
    .item-search-dropdown .qa-link:hover { background: #eef2ff; }
    /* ===== Quick Add Item modal (mirrors item_add.php) ===== */
    #quickAddModal .modal-dialog { max-width: 900px; }
    #quickAddModal .modal-body { overflow-y: auto; max-height: 80vh; }
    #quickAddModal .vy-add-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
    @media (max-width: 767px) { #quickAddModal .vy-add-grid { grid-template-columns: 1fr; } }
    #quickAddModal .vy-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; }
    #quickAddModal .vy-add-left .vy-card + .vy-card,
    #quickAddModal .vy-add-right .vy-card + .vy-card { margin-top: 16px; }
    #quickAddModal .vy-card-head { padding: 10px 16px; font-size: 12px; font-weight: 700; color: #1a1a1a; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 8px; }
    #quickAddModal .vy-card-head i { font-size: 13px; }
    #quickAddModal .vy-card-body { padding: 16px 18px; }
    #quickAddModal .vy-f { margin-bottom: 12px; }
    #quickAddModal .vy-f:last-child { margin-bottom: 0; }
    #quickAddModal .vy-f label { display: block; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 5px; }
    #quickAddModal .vy-f label .text-danger { color: var(--danger-color); }
    #quickAddModal .vy-f input, #quickAddModal .vy-f select, #quickAddModal .vy-f textarea { width: 100%; padding: 8px 11px; border: 1px solid #d9d9d9; border-radius: 8px; font-size: 13px; color: #1a1a1a; background: #fff; }
    #quickAddModal .vy-f textarea { height: auto; padding: 9px 11px; resize: vertical; min-height: 56px; }
    #quickAddModal .vy-f input:focus, #quickAddModal .vy-f select:focus, #quickAddModal .vy-f textarea:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(237,26,59,0.08); }
    #quickAddModal .vy-f input::placeholder { color: #bbb; }
    #quickAddModal .vy-f-row { display: flex; gap: 10px; }
    #quickAddModal .vy-f-row .vy-f { flex: 1; }
    #quickAddModal .vy-input-addon { display: flex; align-items: stretch; }
    #quickAddModal .vy-input-addon span { background: #f1f3f5; border: 1px solid #d9d9d9; border-right: 0; border-radius: 8px 0 0 8px; display: flex; align-items: center; padding: 0 10px; font-size: 13px; color: #666; font-weight: 600; }
    #quickAddModal .vy-input-addon input { border-radius: 0 8px 8px 0 !important; flex: 1; }
    #quickAddModal .vy-img-upload { border: 2px dashed #e2e2e2; border-radius: 12px; background: #fafafa; position: relative; }
    #quickAddModal .vy-img-upload:hover { border-color: var(--primary-color); }
    #quickAddModal .vy-img-placeholder { padding: 22px; text-align: center; cursor: pointer; }
    #quickAddModal .vy-img-placeholder i { font-size: 24px; color: #ccc; margin-bottom: 4px; display: block; }
    #quickAddModal .vy-img-placeholder span { font-size: 12px; color: #888; display: block; }
    #quickAddModal .vy-img-placeholder small { font-size: 11px; color: #bbb; }
    #quickAddModal .vy-img-upload input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    #quickAddModal .vy-img-upload img#qaImagePreview { display: block; max-height: 130px; margin: 10px auto; border-radius: 8px; }
    #quickAddModal .vy-track-toggle { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
    #quickAddModal .vy-toggle-label { font-size: 13px; font-weight: 500; color: #333; }
    #quickAddModal .vy-toggle-desc { font-size: 11px; color: #999; margin-top: 1px; }
    #quickAddModal .vy-switch { position: relative; width: 42px; height: 23px; flex-shrink: 0; }
    #quickAddModal .vy-switch input { opacity: 0; width: 0; height: 0; }
    #quickAddModal .vy-slider { position: absolute; inset: 0; background: #ccc; border-radius: 23px; transition: .3s; cursor: pointer; }
    #quickAddModal .vy-slider::before { content: ''; position: absolute; height: 17px; width: 17px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .3s; }
    #quickAddModal .vy-switch input:checked + .vy-slider { background: var(--primary-color); }
    #quickAddModal .vy-switch input:checked + .vy-slider::before { transform: translateX(19px); }
    #quickAddModal .vy-sku-row { display: flex; gap: 6px; align-items: center; }
    #quickAddModal .vy-sku-row input { flex: 1; }
    #quickAddModal .vy-btn-icon { width: 34px; height: 34px; flex-shrink: 0; border: 1px solid #d9d9d9; background: #fff; color: #666; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; transition: all .15s; }
    #quickAddModal .vy-btn-icon:hover { background: var(--primary-light); border-color: var(--primary-color); }
    #quickAddModal .vy-profit-box { background: #f5f9f5; border: 1px solid #e2efe2; border-radius: 10px; padding: 14px 16px; }
    #quickAddModal .vy-profit-row { display: flex; justify-content: space-between; font-size: 12px; color: #555; padding: 2px 0; }
    #quickAddModal .vy-profit-divider { border-top: 1px dashed #d4e8d4; margin: 5px 0; }
    #quickAddModal .vy-profit-total { font-weight: 700; font-size: 14px; color: #1a1a1a; }
    #quickAddModal .vy-tax-toggle { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    #quickAddModal .vy-tax-label { font-size: 12px; font-weight: 600; color: #666; width: 36px; }
    #quickAddModal .vy-tax-pills { display: flex; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
    #quickAddModal .vy-pill { padding: 5px 12px; font-size: 12px; background: #fff; color: #666; border: none; cursor: pointer; transition: all .15s; }
    #quickAddModal .vy-pill + .vy-pill { border-left: 1px solid #e0e0e0; }
    #quickAddModal .vy-pill.active { background: var(--primary-color); color: #fff; }
    #quickAddModal .vy-pill:hover:not(.active) { background: #f0f0f0; }
    #quickAddModal .vy-price-breakdown { background: #f8f9fa; border-radius: 8px; padding: 10px 12px; font-size: 12px; }
    #quickAddModal .vy-bd-row { display: flex; justify-content: space-between; color: #555; padding: 2px 0; }
    #quickAddModal .vy-bd-divider { border-top: 1px dashed #ddd; margin: 3px 0; }
    #quickAddModal .vy-bd-total { font-weight: 700; font-size: 13px; color: #1a1a1a; }
</style>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-pen me-2"></i>Edit Purchase - <?= sanitize($purchase['bill_no']) ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" id="purchaseForm">
                    <?= csrfField() ?>
                    <input type="hidden" id="cashBalance" value="<?= $cashBalance ?>">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Bill No</label>
                            <input type="text" class="form-control" value="<?= sanitize($purchase['bill_no']) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date *</label>
                            <input type="date" name="date" class="form-control" value="<?= $purchase['date'] ?>">
                        </div>
                        <div class="col-md-4 position-relative">
                            <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" id="supplierSearch" class="form-control" placeholder="Search supplier..." autocomplete="off" value="<?= h($supplier['name'] ?? '') ?>">
                                <input type="hidden" name="party_id" id="supplierId" value="<?= $purchase['party_id'] ?>">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearSupplier()">Clear</button>
                            </div>
                            <div id="supplierName" class="small text-muted mt-1" style="display:<?= $supplier ? 'block' : 'none' ?>;"><?= h($supplier['name'] ?? '') ?></div>
                            <div id="supplierDropdown" class="supplier-search-dropdown"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Supplier Bill No <span class="text-danger">*</span></label>
                            <input type="text" name="supplier_bill_no" class="form-control" placeholder="Supplier's bill #" value="<?= h($purchase['supplier_bill_no']) ?>">
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table items-table" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th style="min-width:250px">Item</th>
                                    <th style="width:80px">Qty</th>
                                    <th style="width:110px">Rate</th>
                                    <th style="width:100px">Discount</th>
                                    <th style="width:90px">Tax %</th>
                                    <th style="width:100px" class="text-end">Tax Amt</th>
                                    <th style="width:120px" class="text-end">Total</th>
                                    <th style="width:50px"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody"></tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-success mb-4" id="addRow"><i class="fas fa-plus me-1"></i> Add Row</button>

                    <div class="row justify-content-end mb-4">
                        <div class="col-md-4">
                            <table class="table table-sm mb-0">
                                <tr>
                                    <td class="text-end">Subtotal:</td>
                                    <td class="text-end fw-bold" id="subtotalDisplay">0.00</td>
                                </tr>
                                <tr>
                                    <td class="text-end">Tax:</td>
                                    <td class="text-end fw-bold" id="taxDisplay">0.00</td>
                                </tr>
                                <tr>
                                    <td class="text-end">Discount:</td>
                                    <td><input type="number" name="discount_amount" id="discountInput" class="form-control form-control-sm text-end" value="<?= $purchase['discount_amount'] ?>" step="0.01" min="0"></td>
                                </tr>
                                <tr class="table-active">
                                    <td class="text-end fw-bold">Grand Total:</td>
                                    <td class="text-end fw-bold fs-5" id="grandTotalDisplay">0.00</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Amount Paid</label>
                            <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="<?= h($formPaidAmount) ?>" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="paymentMethod" class="form-select">
                                    <option value="">Select Payment Method</option>
                                    <option value="cash" data-live="1">Cash</option>
                                    <option value="bank" data-live="1">Bank Transfer</option>
                                    <option value="upi" data-live="1">UPI</option>
                                    <option value="cheque" data-live="1">Cheque</option>
                                    <option value="credit" data-credit="1">Credit</option>
                                </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Due Amount</label>
                            <input type="text" id="dueAmountDisplay" class="form-control" value="0.00" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" placeholder="Purchase notes..."><?= h($purchase['notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Update Purchase</button>
                        <a href="purchase_view.php?id=<?= $purchaseId ?>" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var existingItems = <?= $existingItemsJson ?>;
    var rowCount = 0;

    function createItemRow(index, item) {
        var tr = document.createElement('tr');
        tr.dataset.row = index;
        var displayNum = index + 1;
        var name = item ? item.name : '';
        var itemId = item ? item.id : '';
        var qty = item ? item.qty : 1;
        var rate = item ? item.rate : 0;
        var disc = item ? item.discount : 0;
        var tax = item ? item.tax_rate : 0;
        var lineSub = (rate * qty) - disc;
        var lineTax = lineSub * tax / 100;
        var total = lineSub * (1 + tax / 100);
        tr.innerHTML =
            '<td>' + displayNum + '</td>' +
            '<td class="position-relative">' +
                '<div class="item-search-wrapper">' +
                    '<input type="text" class="form-control form-control-sm item-search" data-row="' + index + '" placeholder="Search item..." autocomplete="off" value="' + name.replace(/"/g, '&quot;') + '">' +
                    '<button type="button" class="item-search-clear" title="Reset &amp; show all items"><i class="fas fa-times"></i></button>' +
                    '<input type="hidden" name="item_id[]" class="item-id" value="' + itemId + '">' +
                    '<input type="hidden" name="tax_rate[]" class="tax-rate-hidden" value="' + tax + '">' +
                    '<div class="item-search-dropdown" id="itemDropdown' + index + '"></div>' +
                '</div>' +
            '</td>' +
            '<td><input type="number" name="qty[]" class="form-control form-control-sm qty" value="' + qty + '" min="1"></td>' +
            '<td><input type="number" name="rate[]" class="form-control form-control-sm rate" value="' + parseFloat(rate).toFixed(2) + '" step="0.01" min="0"></td>' +
            '<td><input type="number" name="item_discount[]" class="form-control form-control-sm item-discount" value="' + parseFloat(disc).toFixed(2) + '" step="0.01" min="0"></td>' +
            '<td><input type="number" name="tax_rate_display[]" class="form-control form-control-sm tax-rate-display" value="' + tax + '" step="0.01" min="0" max="100" readonly></td>' +
            '<td class="text-end fw-bold row-tax">' + lineTax.toFixed(2) + '</td>' +
            '<td class="text-end fw-bold row-total">' + total.toFixed(2) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>';
        return tr;
    }

    function positionDropdown(dropdown, inputEl) {
        if (dropdown.parentElement !== document.body) { document.body.appendChild(dropdown); }
        dropdown._ownerInput = inputEl;
        dropdown.style.setProperty('position', 'fixed', 'important');
        dropdown.style.setProperty('display', 'block', 'important');
        dropdown.style.setProperty('width', '720px', 'important');
        dropdown.style.setProperty('z-index', '1080', 'important');
        dropdown.style.top = '0'; dropdown.style.left = '0'; dropdown.style.right = 'auto';
        var rect = inputEl.getBoundingClientRect();
        var vw = window.innerWidth;
        var top = rect.bottom + 2; var left = rect.left;
        dropdown.style.top = top + 'px'; dropdown.style.left = left + 'px';
        void dropdown.offsetWidth;
        var ddWidth = dropdown.offsetWidth;
        if (left + ddWidth > vw - 8) { left = vw - ddWidth - 8; }
        if (left < 8) left = 8;
        dropdown.style.left = left + 'px';
    }

    var supplierSearch = document.getElementById('supplierSearch');
    var supplierDropdown = document.getElementById('supplierDropdown');
    var supplierIdInput = document.getElementById('supplierId');
    var supplierNameDisplay = document.getElementById('supplierName');
    var supplierTimer = null;

    supplierSearch.addEventListener('input', function() {
        clearTimeout(supplierTimer);
        var q = this.value.trim();
        if (q.length < 1) { supplierDropdown.style.setProperty('display', 'none', 'important'); return; }
        supplierTimer = setTimeout(function() {
            fetch('purchase_edit.php?ajax=search_suppliers&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.length === 0) { supplierDropdown.style.setProperty('display', 'none', 'important'); return; }
                    var html = '';
                    data.forEach(function(s) {
                        html += '<div class="dropdown-item px-3 py-2" data-id="' + s.id + '" data-name="' + s.name.replace(/"/g, '&quot;') + '">';
                        html += '<strong>' + s.name + '</strong>';
                        if (s.phone) html += ' <small class="text-muted">' + s.phone + '</small>';
                        html += '</div>';
                    });
                    supplierDropdown.innerHTML = html;
                    positionDropdown(supplierDropdown, supplierSearch);
                    supplierDropdown.querySelectorAll('.dropdown-item').forEach(function(el) {
                        el.addEventListener('click', function(e) {
                            e.stopPropagation();
                            supplierIdInput.value = this.dataset.id;
                            supplierSearch.value = this.dataset.name;
                            supplierNameDisplay.textContent = this.dataset.name;
                            supplierNameDisplay.style.display = 'block';
                            supplierDropdown.style.setProperty('display', 'none', 'important');
                        });
                    });
                });
        }, 300);
    });

    window.clearSupplier = function() {
        supplierIdInput.value = '';
        supplierSearch.value = '';
        supplierNameDisplay.style.display = 'none';
    };

    var tbody = document.getElementById('itemsBody');

    // Load existing items
    existingItems.forEach(function(item, idx) {
        tbody.appendChild(createItemRow(idx, item));
        rowCount = idx + 1;
    });
    recalcAll();

    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('item-search')) {
            updateClearBtn(e.target);
            handleItemSearch(e.target);
        }
    });
    tbody.addEventListener('focusin', function(e) {
        if (e.target.classList.contains('item-search')) {
            updateClearBtn(e.target);
            handleItemSearch(e.target);
        }
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.item-search-clear');
        if (!btn) return;
        e.stopPropagation();
        var wrapper = btn.closest('.item-search-wrapper');
        var input = wrapper.querySelector('.item-search');
        input.value = '';
        var idInput = wrapper.querySelector('.item-id');
        if (idInput) idInput.value = '';
        var taxHidden = wrapper.querySelector('.tax-rate-hidden');
        if (taxHidden) taxHidden.value = '0';
        var taxDisp = wrapper.querySelector('.tax-rate-display');
        if (taxDisp) taxDisp.value = '0';
        updateClearBtn(input);
        handleItemSearch(input);
    });

    function updateClearBtn(input) {
        var wrapper = input.closest('.item-search-wrapper');
        var btn = wrapper ? wrapper.querySelector('.item-search-clear') : null;
        if (!btn) return;
        var hasVal = input.value.trim() !== '';
        btn.style.display = hasVal ? 'flex' : 'none';
    }

    // === Quick Add Item ===
    var activeRow = null;
    var activeOpenFn = null;
    var quickModal = document.getElementById('quickAddModal');

    document.addEventListener('click', function(e) {
        var qa = e.target.closest('.qa-link');
        if (!qa) return;
        e.stopPropagation();
        var dropdown = qa.closest('.item-search-dropdown');
        var input = dropdown ? dropdown._ownerInput : null;
        var row = input ? input.closest('tr') : null;
        activeRow = row;
        activeOpenFn = function() { if (input) handleItemSearch(input); };
        document.querySelectorAll('.item-search-dropdown, .supplier-search-dropdown').forEach(function(d) { d.style.setProperty('display', 'none', 'important'); });
        bootstrap.Modal.getOrCreateInstance(quickModal).show();
    });

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
                selectItem(activeRow, item);
                recalcRow(activeRow);
                recalcAll();
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

    quickModal.addEventListener('show.bs.modal', function() {
        qaResetForm();
        setTimeout(function() { quickModal.querySelector('input[name="name"]').focus(); }, 350);
    });

    document.getElementById('qaPurchasePrice').addEventListener('input', qaCalcProfit);
    document.getElementById('qaSalePrice').addEventListener('input', qaCalcProfit);
    document.getElementById('qaPurchaseTaxRate').addEventListener('change', qaCalcProfit);
    document.getElementById('qaSaleTaxRate').addEventListener('change', qaCalcProfit);

    document.addEventListener('click', function(e) {
        var target = e.target.closest('.dropdown-item');
        if (target) {
            var dropdown = target.closest('.item-search-dropdown');
            if (!dropdown) return;
            e.stopPropagation();
            var searchInput = dropdown._ownerInput;
            if (searchInput) { selectItem(searchInput.closest('tr'), target.dataset); }
        }
    });

    function handleItemSearch(input) {
        clearTimeout(input._timer);
        var q = input.value.trim();
        if (!input._dropdown) { input._dropdown = input.closest('tr').querySelector('.item-search-dropdown'); }
        var dropdown = input._dropdown;
        input._timer = setTimeout(function() {
            fetch('purchase_edit.php?ajax=search_items&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.length === 0) { dropdown.style.setProperty('display', 'none', 'important'); return; }
                    var html = '<div class="item-dropdown-sticky">' +
                        '<div class="item-dropdown-header"><span>Name</span><span>SKU</span><span class="text-end">Price</span><span class="text-end">Stock</span></div>' +
                        '<div class="qa-link"><i class="fas fa-plus me-1"></i> Quick Add Item</div>' +
                        '</div>';
                    data.forEach(function(item) {
                        var stock = item.current_stock || 0;
                        var stockClass = stock <= 0 ? 'stock-low' : (stock <= 10 ? 'stock-low' : 'stock-ok');
                        html += '<div class="dropdown-item" data-id="' + item.id + '" data-name="' + item.name.replace(/"/g, '&quot;') + '" data-purchase_price="' + item.purchase_price + '" data-purchase_tax_rate="' + item.purchase_tax_rate + '" data-stock="' + stock + '">';
                        html += '<div class="item-dropdown-row">';
                        html += '<span class="id-name">' + item.name + '</span>';
                        html += '<span class="id-col">' + (item.sku || '-') + '</span>';
                        html += '<span class="id-col price">&#8377;' + parseFloat(item.purchase_price).toFixed(2) + '</span>';
                        html += '<span class="id-col ' + stockClass + '">' + stock + '</span>';
                        html += '</div></div>';
                    });
                    dropdown.innerHTML = html;
                    dropdown.style.setProperty('display', 'block', 'important');
                    positionDropdown(dropdown, input);
                });
        }, 300);
    }

    function selectItem(row, data) {
        row.querySelector('.item-id').value = data.id;
        row.querySelector('.item-search').value = data.name;
        row.querySelector('.rate').value = parseFloat(data.purchase_price).toFixed(2);
        row.querySelector('.tax-rate-hidden').value = parseFloat(data.purchase_tax_rate) || 0;
        row.querySelector('.tax-rate-display').value = parseFloat(data.purchase_tax_rate) || 0;
        updateClearBtn(row.querySelector('.item-search'));
        document.querySelectorAll('.item-search-dropdown').forEach(function(d) { d.style.setProperty('display', 'none', 'important'); });
        recalcRow(row);
        recalcAll();
    }

    document.addEventListener('click', function(e) {
        if (e.target.closest('.item-search-dropdown') || e.target.closest('.supplier-search-dropdown')) return;
        if (!e.target.classList.contains('item-search') && !e.target.classList.contains('supplier-search')) {
            document.querySelectorAll('.item-search-dropdown, .supplier-search-dropdown').forEach(function(d) { d.style.setProperty('display', 'none', 'important'); });
        }
    });

    window.addEventListener('scroll', function(e) {
        if (e.target && e.target.closest && e.target.closest('.item-search-dropdown, .supplier-search-dropdown')) return;
        document.querySelectorAll('.item-search-dropdown, .supplier-search-dropdown').forEach(function(d) { d.style.setProperty('display', 'none', 'important'); });
        var ae = document.activeElement;
        if (ae && ae.blur && ae.classList && (ae.classList.contains('item-search') || ae.classList.contains('supplier-search'))) ae.blur();
    }, true);
    window.addEventListener('resize', function() {
        document.querySelectorAll('.item-search-dropdown, .supplier-search-dropdown').forEach(function(d) { d.style.setProperty('display', 'none', 'important'); });
    });

    function recalcRow(row) {
        var qty = parseFloat(row.querySelector('.qty').value) || 0;
        var rate = parseFloat(row.querySelector('.rate').value) || 0;
        var disc = parseFloat(row.querySelector('.item-discount').value) || 0;
        var taxRate = parseFloat(row.querySelector('.tax-rate-display').value) || 0;
        var lineTotal = (rate * qty) - disc;
        var lineTax = lineTotal * taxRate / 100;
        lineTotal += lineTax;
        row.querySelector('.row-tax').textContent = lineTax.toFixed(2);
        row.querySelector('.row-total').textContent = lineTotal.toFixed(2);
    }

    function recalcAll() {
        var rows = document.querySelectorAll('#itemsBody tr');
        var subtotal = 0, taxTotal = 0;
        rows.forEach(function(row) {
            var qty = parseFloat(row.querySelector('.qty').value) || 0;
            var rate = parseFloat(row.querySelector('.rate').value) || 0;
            var disc = parseFloat(row.querySelector('.item-discount').value) || 0;
            var taxRate = parseFloat(row.querySelector('.tax-rate-display').value) || 0;
            var lineSub = (rate * qty) - disc;
            var lineTax = lineSub * taxRate / 100;
            subtotal += (rate * qty); taxTotal += lineTax;
        });
        var discountAmount = parseFloat(document.getElementById('discountInput').value) || 0;
        var grandTotal = subtotal + taxTotal - discountAmount;
        var paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
        var dueAmount = Math.max(0, grandTotal - paidAmount);
        document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
        document.getElementById('taxDisplay').textContent = taxTotal.toFixed(2);
        document.getElementById('grandTotalDisplay').textContent = grandTotal.toFixed(2);
        document.getElementById('dueAmountDisplay').value = dueAmount.toFixed(2);
    }

    tbody.addEventListener('change', function(e) {
        if (e.target.classList.contains('qty') || e.target.classList.contains('rate') || e.target.classList.contains('item-discount') || e.target.classList.contains('tax-rate-display')) {
            recalcRow(e.target.closest('tr')); recalcAll();
        }
    });
    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('qty') || e.target.classList.contains('rate') || e.target.classList.contains('item-discount')) {
            recalcRow(e.target.closest('tr')); recalcAll();
        }
    });

    document.getElementById('discountInput').addEventListener('input', recalcAll);
    document.getElementById('paidAmount').addEventListener('input', recalcAll);

    var paymentSel = document.getElementById('paymentMethod');
    var creditOpt = paymentSel.querySelector('option[data-credit]');
    var liveOpts = paymentSel.querySelectorAll('option[data-live]');

    function syncPaymentMethod() {
        var paid = parseFloat(document.getElementById('paidAmount').value) || 0;
        if (paid > 0) {
            creditOpt.disabled = true; creditOpt.hidden = true;
            liveOpts.forEach(function(o) { o.disabled = false; o.hidden = false; });
            if (paymentSel.value === 'credit') paymentSel.value = '';
        } else {
            creditOpt.disabled = false; creditOpt.hidden = false;
            liveOpts.forEach(function(o) { o.disabled = true; o.hidden = true; });
            paymentSel.value = 'credit';
        }
    }
    syncPaymentMethod();
    document.getElementById('paidAmount').addEventListener('input', syncPaymentMethod);

    document.getElementById('addRow').addEventListener('click', function() {
        tbody.appendChild(createItemRow(rowCount, null));
        rowCount++;
    });

    tbody.addEventListener('click', function(e) {
        if (e.target.closest('.remove-row')) {
            var rows = document.querySelectorAll('#itemsBody tr');
            if (rows.length > 1) { e.target.closest('tr').remove(); renumberRows(); recalcAll(); }
        }
    });

    function renumberRows() {
        document.querySelectorAll('#itemsBody tr').forEach(function(row, idx) {
            row.querySelector('td:first-child').textContent = idx + 1;
        });
    }

    document.getElementById('purchaseForm').addEventListener('submit', function(e) {
        var errors = [];
        if (!document.querySelector('input[name="date"]').value) errors.push('Please select a date.');
        if (!document.getElementById('supplierId').value) errors.push('Please select a supplier.');
        if (!document.querySelector('input[name="supplier_bill_no"]').value.trim()) errors.push('Please enter Supplier Bill No.');
        var hasItem = false;
        document.querySelectorAll('.item-id').forEach(function(input) { if (input.value) hasItem = true; });
        if (!hasItem) errors.push('Please add at least one item.');
        var paidAmt = parseFloat(document.getElementById('paidAmount').value) || 0;
        if (paidAmt > 0 && !document.querySelector('select[name="payment_method"]').value) errors.push('Please select a payment method.');
        if (errors.length > 0) {
            e.preventDefault();
            document.getElementById('alertModalMsg').innerHTML = errors.map(function(er) { return '<p>' + er + '</p>'; }).join('');
            document.getElementById('alertModal').style.display = 'flex';
            return;
        }
        var payMethod = document.querySelector('select[name="payment_method"]').value;
        if (payMethod === 'cash' && paidAmt > 0) {
            var cashBal = parseFloat(document.getElementById('cashBalance').value) || 0;
            if (paidAmt > cashBal) {
                e.preventDefault();
                document.getElementById('alertModalMsg').innerHTML = '<p>Insufficient cash balance.</p><p>Available: ' + cashBal.toFixed(2) + ' | Required: ' + paidAmt.toFixed(2) + '</p>';
                document.getElementById('alertModal').style.display = 'flex';
                return;
            }
        }
    });
});

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

<div id="alertModal" class="alert-modal" style="display:none;">
    <div class="alert-modal-backdrop"></div>
    <div class="alert-modal-box">
        <div class="alert-modal-icon"><i class="fas fa-exclamation-circle"></i></div>
        <div class="alert-modal-body" id="alertModalMsg"></div>
        <div class="alert-modal-footer"><button class="btn btn-sm btn-primary px-4" onclick="document.getElementById('alertModal').style.display='none'">OK</button></div>
    </div>
</div>

<style>
    .alert-modal { position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; }
    .alert-modal-backdrop { position:absolute; inset:0; background:rgba(0,0,0,.45); }
    .alert-modal-box { position:relative; background:#fff; border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,.2); padding:24px 28px 18px; max-width:400px; width:90%; text-align:center; }
    .alert-modal-icon { font-size:32px; color:#e74c3c; margin-bottom:10px; }
    .alert-modal-body { font-size:13px; color:#444; line-height:1.5; margin-bottom:16px; }
    .alert-modal-body p { margin:4px 0; }
</style>

<?php include 'footer.php'; ?>
