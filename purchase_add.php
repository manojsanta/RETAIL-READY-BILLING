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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: purchase_add.php');
        exit;
    }

    $partyId = (int)($_POST['party_id'] ?? 0);
    $date = dateDB($_POST['date'] ?? today());
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $discountAmount = (float)($_POST['discount_amount'] ?? 0);
    $paidAmount = (float)($_POST['paid_amount'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? '');
    $supplierBillNo = sanitize($_POST['supplier_bill_no'] ?? '');

    $itemIds = $_POST['item_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $rates = $_POST['rate'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    $taxRates = $_POST['tax_rate'] ?? [];

    if (empty($itemIds) || count($itemIds) === 0) {
        setFlash('danger', 'Please add at least one item.');
        header('Location: purchase_add.php');
        exit;
    }

    $subtotal = 0;
    $taxTotal = 0;
    $lineItems = [];

    for ($i = 0; $i < count($itemIds); $i++) {
        $itemId = (int)$itemIds[$i];
        $qty = max(1, (int)($qtys[$i] ?? 1));
        $rate = (float)($rates[$i] ?? 0);
        $disc = (float)($discounts[$i] ?? 0);
        $taxRate = (float)($taxRates[$i] ?? 0);

        $lineTotal = ($rate * $qty) - $disc;
        $lineTax = round($lineTotal * $taxRate / 100, 2);
        $lineTotal += $lineTax;

        $subtotal += ($rate * $qty);
        $taxTotal += $lineTax;

        $lineItems[] = [
            'item_id' => $itemId,
            'qty' => $qty,
            'rate' => $rate,
            'discount' => $disc,
            'tax_rate' => $taxRate,
            'tax_amount' => $lineTax,
            'total' => $lineTotal,
        ];
    }

    $total = $subtotal - $discountAmount + $taxTotal;
    $dueAmount = max(0, $total - $paidAmount);
    $paymentStatus = 'unpaid';
    if ($paidAmount >= $total && $total > 0) {
        $paymentStatus = 'paid';
    } elseif ($paidAmount > 0) {
        $paymentStatus = 'partial';
    }

    $billNo = generateBillNo();

    $pdo->beginTransaction();
    try {
        $purchaseId = insertId(
            "INSERT INTO purchases (bill_no, party_id, user_id, date, subtotal, tax_amount, discount_amount, total, paid_amount, due_amount, payment_status, payment_method, supplier_bill_no, notes, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received')",
            [$billNo, $partyId > 0 ? $partyId : null, $_SESSION['user_id'], $date, $subtotal, $taxTotal, $discountAmount, $total, $paidAmount, $dueAmount, $paymentStatus, $paymentMethod, $supplierBillNo ?: null, $notes]
        );

        foreach ($lineItems as $li) {
            query(
                "INSERT INTO purchase_items (purchase_id, item_id, qty, rate, discount, tax_rate, tax_amount, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$purchaseId, $li['item_id'], $li['qty'], $li['rate'], $li['discount'], $li['tax_rate'], $li['tax_amount'], $li['total']]
            );
            updateStock($li['item_id'], $li['qty'], 'add');
        }

        if ($paidAmount > 0) {
            $paymentNo = generatePaymentNo();
            query(
                "INSERT INTO payments_out (payment_no, party_id, purchase_id, date, amount, payment_method, user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$paymentNo, $partyId > 0 ? $partyId : null, $purchaseId, $date, $paidAmount, $paymentMethod, $_SESSION['user_id']]
            );
        }

        $pdo->commit();
        setFlash('success', "Purchase bill {$billNo} created successfully.");
        header('Location: purchase_view.php?id=' . $purchaseId);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error creating purchase: ' . $e->getMessage());
        header('Location: purchase_add.php');
        exit;
    }
}

$billNo = generateBillNo();
$suppliers = fetchAll("SELECT id, name, phone FROM parties WHERE (type = 'supplier' OR type = 'both') AND status = 1 ORDER BY name ASC");

$pageTitle = 'New Purchase';
include 'header.php';
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
        max-height: 250px;
        overflow-y: auto;
        display: none !important;
    }
    .item-search-dropdown { width: 520px !important; }
    .supplier-search-dropdown { width: 350px !important; }
    .item-search-dropdown .dropdown-item, .supplier-search-dropdown .dropdown-item {
        display: block;
        padding: 8px 12px;
        cursor: pointer;
        font-size: 13px;
        border-bottom: 1px solid #f5f5f5;
        white-space: normal;
    }
    .item-search-dropdown .dropdown-item:last-child, .supplier-search-dropdown .dropdown-item:last-child {
        border-bottom: none;
    }
    .item-search-dropdown .dropdown-item:hover, .supplier-search-dropdown .dropdown-item:hover { background: #f0f4ff; }
    .item-dropdown-header {
        display: grid;
        grid-template-columns: 1fr 70px 80px 80px;
        gap: 8px;
        padding: 6px 12px;
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        font-size: 10px;
        font-weight: 700;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .item-dropdown-row {
        display: grid;
        grid-template-columns: 1fr 70px 80px 80px;
        gap: 8px;
        padding: 8px 12px;
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
        font-size: 11px;
        display: block;
    }
    .item-dropdown-row .id-col {
        font-size: 12px;
        color: #666;
    }
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
                <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>New Purchase Bill</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="purchaseForm">
                    <?= csrfField() ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Bill No</label>
                            <input type="text" class="form-control" value="<?= sanitize($billNo) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date *</label>
                            <input type="date" name="date" class="form-control" value="<?= today() ?>" required>
                        </div>
                        <div class="col-md-4 position-relative">
                            <label class="form-label fw-semibold">Supplier</label>
                            <div class="input-group">
                                <input type="text" id="supplierSearch" class="form-control" placeholder="Search supplier..." autocomplete="off">
                                <input type="hidden" name="party_id" id="supplierId" value="">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearSupplier()">Clear</button>
                            </div>
                            <div id="supplierName" class="small text-muted mt-1" style="display:none;"></div>
                            <div id="supplierDropdown" class="supplier-search-dropdown"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Supplier Bill No</label>
                            <input type="text" name="supplier_bill_no" class="form-control" placeholder="Supplier's bill #">
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
                            <tbody id="itemsBody">
                                <tr data-row="0">
                                    <td>1</td>
                                    <td class="position-relative">
                                        <input type="text" class="form-control form-control-sm item-search" data-row="0" placeholder="Search item..." autocomplete="off">
                                        <input type="hidden" name="item_id[]" class="item-id" value="">
                                        <input type="hidden" name="tax_rate[]" class="tax-rate-hidden" value="0">
                                        <div class="item-search-dropdown" id="itemDropdown0"></div>
                                    </td>
                                    <td><input type="number" name="qty[]" class="form-control form-control-sm qty" value="1" min="1"></td>
                                    <td><input type="number" name="rate[]" class="form-control form-control-sm rate" value="0" step="0.01" min="0"></td>
                                    <td><input type="number" name="item_discount[]" class="form-control form-control-sm item-discount" value="0" step="0.01" min="0"></td>
                                    <td><input type="number" name="tax_rate_display[]" class="form-control form-control-sm tax-rate-display" value="0" step="0.01" min="0" max="100" readonly></td>
                                    <td class="text-end fw-bold row-total">0.00</td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>
                                </tr>
                            </tbody>
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
                                    <td>
                                        <input type="number" name="discount_amount" id="discountInput" class="form-control form-control-sm text-end" value="0" step="0.01" min="0">
                                    </td>
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
                            <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="0" step="0.01" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="upi">UPI</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Due Amount</label>
                            <input type="text" id="dueAmountDisplay" class="form-control" value="0.00" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="1" placeholder="Purchase notes..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Purchase</button>
                        <a href="purchases.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var rowCount = 1;

    function positionDropdown(dropdown, inputEl) {
        if (dropdown.parentElement !== document.body) {
            document.body.appendChild(dropdown);
        }
        dropdown._ownerInput = inputEl;
        dropdown.style.setProperty('position', 'fixed', 'important');
        dropdown.style.setProperty('display', 'block', 'important');
        dropdown.style.setProperty('width', '520px', 'important');
        dropdown.style.setProperty('z-index', '1080', 'important');
        dropdown.style.top = '0';
        dropdown.style.left = '0';
        dropdown.style.right = 'auto';
        var rect = inputEl.getBoundingClientRect();
        var vw = window.innerWidth;
        var top = rect.bottom + 2;
        var left = rect.left;
        dropdown.style.top = top + 'px';
        dropdown.style.left = left + 'px';
        void dropdown.offsetWidth;
        var ddWidth = dropdown.offsetWidth;
        if (left + ddWidth > vw - 8) {
            left = vw - ddWidth - 8;
        }
        if (left < 8) left = 8;
        dropdown.style.left = left + 'px';
    }

    // Supplier search
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
            fetch('purchase_add.php?ajax=search_suppliers&q=' + encodeURIComponent(q))
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

    // Item search per row
    document.getElementById('itemsBody').addEventListener('input', function(e) {
        if (e.target.classList.contains('item-search')) {
            handleItemSearch(e.target);
        }
    });

    document.addEventListener('click', function(e) {
        var target = e.target.closest('.dropdown-item');
        if (target) {
            var dropdown = target.closest('.item-search-dropdown');
            if (!dropdown) return;
            e.stopPropagation();
            var searchInput = dropdown._ownerInput;
            if (searchInput) {
                var row = searchInput.closest('tr');
                selectItem(row, target.dataset);
            }
        }
    });

    function handleItemSearch(input) {
        clearTimeout(input._timer);
        var q = input.value.trim();
        if (!input._dropdown) {
            input._dropdown = input.closest('tr').querySelector('.item-search-dropdown');
        }
        var dropdown = input._dropdown;
        if (q.length < 1) { dropdown.style.setProperty('display', 'none', 'important'); return; }
        input._timer = setTimeout(function() {
            fetch('purchase_add.php?ajax=search_items&q=' + encodeURIComponent(q))
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

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (e.target.closest('.item-search-dropdown') || e.target.closest('.supplier-search-dropdown')) {
            return;
        }
        if (!e.target.classList.contains('item-search') && !e.target.classList.contains('supplier-search')) {
            document.querySelectorAll('.item-search-dropdown, .supplier-search-dropdown').forEach(function(d) {
                d.style.setProperty('display', 'none', 'important');
            });
        }
    });

    // Row calculations
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
        var subtotal = 0;
        var taxTotal = 0;
        rows.forEach(function(row) {
            var qty = parseFloat(row.querySelector('.qty').value) || 0;
            var rate = parseFloat(row.querySelector('.rate').value) || 0;
            var disc = parseFloat(row.querySelector('.item-discount').value) || 0;
            var taxRate = parseFloat(row.querySelector('.tax-rate-display').value) || 0;
            var lineSub = (rate * qty) - disc;
            var lineTax = lineSub * taxRate / 100;
            subtotal += (rate * qty);
            taxTotal += lineTax;
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

    // Event delegation for row inputs
    document.getElementById('itemsBody').addEventListener('change', function(e) {
        if (e.target.classList.contains('qty') || e.target.classList.contains('rate') ||
            e.target.classList.contains('item-discount') || e.target.classList.contains('tax-rate-display')) {
            recalcRow(e.target.closest('tr'));
            recalcAll();
        }
    });

    document.getElementById('itemsBody').addEventListener('input', function(e) {
        if (e.target.classList.contains('qty') || e.target.classList.contains('rate') ||
            e.target.classList.contains('item-discount')) {
            recalcRow(e.target.closest('tr'));
            recalcAll();
        }
    });

    document.getElementById('discountInput').addEventListener('input', recalcAll);
    document.getElementById('paidAmount').addEventListener('input', recalcAll);

    // Add row
    document.getElementById('addRow').addEventListener('click', function() {
        var tbody = document.getElementById('itemsBody');
        var newRow = document.createElement('tr');
        newRow.dataset.row = rowCount;
        var r = rowCount + 1;
        newRow.innerHTML =
            '<td>' + r + '</td>' +
            '<td class="position-relative">' +
                '<input type="text" class="form-control form-control-sm item-search" data-row="' + rowCount + '" placeholder="Search item..." autocomplete="off">' +
                '<input type="hidden" name="item_id[]" class="item-id" value="">' +
                '<input type="hidden" name="tax_rate[]" class="tax-rate-hidden" value="0">' +
                '<div class="item-search-dropdown" id="itemDropdown' + rowCount + '"></div>' +
            '</td>' +
            '<td><input type="number" name="qty[]" class="form-control form-control-sm qty" value="1" min="1"></td>' +
            '<td><input type="number" name="rate[]" class="form-control form-control-sm rate" value="0" step="0.01" min="0"></td>' +
            '<td><input type="number" name="item_discount[]" class="form-control form-control-sm item-discount" value="0" step="0.01" min="0"></td>' +
            '<td><input type="number" name="tax_rate_display[]" class="form-control form-control-sm tax-rate-display" value="0" step="0.01" min="0" max="100" readonly></td>' +
            '<td class="text-end fw-bold row-total">0.00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>';
        tbody.appendChild(newRow);
        rowCount++;
    });

    // Remove row
    document.getElementById('itemsBody').addEventListener('click', function(e) {
        if (e.target.closest('.remove-row')) {
            var rows = document.querySelectorAll('#itemsBody tr');
            if (rows.length > 1) {
                e.target.closest('tr').remove();
                renumberRows();
                recalcAll();
            }
        }
    });

    function renumberRows() {
        var rows = document.querySelectorAll('#itemsBody tr');
        rows.forEach(function(row, idx) {
            row.querySelector('td:first-child').textContent = idx + 1;
        });
    }
})();
</script>

<?php include 'footer.php'; ?>
