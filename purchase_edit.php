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
        position: fixed !important; top: auto; left: auto; right: auto;
        z-index: 1080; background: #fff; border: 1px solid #dee2e6;
        border-radius: 0.375rem; box-shadow: 0 4px 12px rgba(0,0,0,.15);
        max-height: 250px; overflow-y: auto; display: none !important;
    }
    .item-search-dropdown { width: 520px !important; }
    .supplier-search-dropdown { width: 350px !important; }
    .item-search-dropdown .dropdown-item, .supplier-search-dropdown .dropdown-item {
        display: block; padding: 8px 12px; cursor: pointer; font-size: 13px;
        border-bottom: 1px solid #f5f5f5; white-space: normal;
    }
    .item-search-dropdown .dropdown-item:last-child, .supplier-search-dropdown .dropdown-item:last-child { border-bottom: none; }
    .item-search-dropdown .dropdown-item:hover, .supplier-search-dropdown .dropdown-item:hover { background: #f0f4ff; }
    .item-dropdown-header {
        display: grid; grid-template-columns: 1fr 70px 80px 80px; gap: 8px;
        padding: 6px 12px; background: #f8f9fa; border-bottom: 2px solid #dee2e6;
        font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase;
        letter-spacing: 0.5px; position: sticky; top: 0; z-index: 1;
    }
    .item-dropdown-row { display: grid; grid-template-columns: 1fr 70px 80px 80px; gap: 8px; padding: 8px 12px; align-items: center; }
    .item-dropdown-row .id-name { font-weight: 600; color: #1a1a1a; line-height: 1.3; }
    .item-dropdown-row .id-name small { font-weight: 400; color: #888; font-size: 11px; display: block; }
    .item-dropdown-row .id-col { font-size: 12px; color: #666; text-align: right; }
    .item-dropdown-row .id-col.stock-low { color: #dc3545; font-weight: 600; }
    .item-dropdown-row .id-col.stock-ok { color: #28a745; }
    .item-dropdown-row .id-col.price { color: #2962FF; font-weight: 600; }
    .items-table td { vertical-align: middle; }
    .items-table input, .items-table select { font-size: 0.875rem; }
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
(function() {
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
        var total = ((rate * qty) - disc) * (1 + tax / 100);
        tr.innerHTML =
            '<td>' + displayNum + '</td>' +
            '<td class="position-relative">' +
                '<input type="text" class="form-control form-control-sm item-search" data-row="' + index + '" placeholder="Search item..." autocomplete="off" value="' + name.replace(/"/g, '&quot;') + '">' +
                '<input type="hidden" name="item_id[]" class="item-id" value="' + itemId + '">' +
                '<input type="hidden" name="tax_rate[]" class="tax-rate-hidden" value="' + tax + '">' +
                '<div class="item-search-dropdown" id="itemDropdown' + index + '"></div>' +
            '</td>' +
            '<td><input type="number" name="qty[]" class="form-control form-control-sm qty" value="' + qty + '" min="1"></td>' +
            '<td><input type="number" name="rate[]" class="form-control form-control-sm rate" value="' + parseFloat(rate).toFixed(2) + '" step="0.01" min="0"></td>' +
            '<td><input type="number" name="item_discount[]" class="form-control form-control-sm item-discount" value="' + parseFloat(disc).toFixed(2) + '" step="0.01" min="0"></td>' +
            '<td><input type="number" name="tax_rate_display[]" class="form-control form-control-sm tax-rate-display" value="' + tax + '" step="0.01" min="0" max="100" readonly></td>' +
            '<td class="text-end fw-bold row-total">' + total.toFixed(2) + '</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>';
        return tr;
    }

    function positionDropdown(dropdown, inputEl) {
        if (dropdown.parentElement !== document.body) { document.body.appendChild(dropdown); }
        dropdown._ownerInput = inputEl;
        dropdown.style.setProperty('position', 'fixed', 'important');
        dropdown.style.setProperty('display', 'block', 'important');
        dropdown.style.setProperty('width', '520px', 'important');
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
        if (e.target.classList.contains('item-search')) { handleItemSearch(e.target); }
    });
    tbody.addEventListener('focusin', function(e) {
        if (e.target.classList.contains('item-search')) { handleItemSearch(e.target); }
    });

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
                    var html = '<div class="item-dropdown-header"><span>Name</span><span>SKU</span><span class="text-end">Price</span><span class="text-end">Stock</span></div>';
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

    function recalcRow(row) {
        var qty = parseFloat(row.querySelector('.qty').value) || 0;
        var rate = parseFloat(row.querySelector('.rate').value) || 0;
        var disc = parseFloat(row.querySelector('.item-discount').value) || 0;
        var taxRate = parseFloat(row.querySelector('.tax-rate-display').value) || 0;
        var lineTotal = (rate * qty) - disc;
        var lineTax = lineTotal * taxRate / 100;
        lineTotal += lineTax;
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
})();
</script>

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
