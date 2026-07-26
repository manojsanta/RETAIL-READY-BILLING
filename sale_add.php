<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$errors = [];
$old = [
    'party_id' => intval($_GET['party_id'] ?? 0),
    'date' => today(),
    'payment_method' => 'cash',
    'paid_amount' => 0,
    'notes' => '',
    'discount_amount' => 0,
];

// Fetch dropdown data
$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 AND (type = 'customer' OR type = 'both') ORDER BY name ASC");

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: sale_add.php');
        exit;
    }

    $invoiceNo = sanitize($_POST['invoice_no'] ?? '');
    $partyId = intval($_POST['party_id'] ?? 0);
    $saleDate = sanitize($_POST['date'] ?? today());
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $notes = sanitize($_POST['notes'] ?? '');
    $grandTotal = floatval($_POST['grand_total'] ?? 0);
    $totalDiscount = floatval($_POST['total_discount'] ?? 0);
    $totalTax = floatval($_POST['total_tax'] ?? 0);
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $paidAmount = floatval($_POST['paid_amount'] ?? 0);

    $itemIds = $_POST['item_id'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemRates = $_POST['item_rate'] ?? [];
    $itemDiscounts = $_POST['item_discount'] ?? [];
    $itemTaxes = $_POST['item_tax'] ?? [];

    // Validation
    if ($partyId <= 0) {
        $errors[] = 'Please select a party.';
    }
    if (empty($itemIds)) {
        $errors[] = 'Please add at least one item.';
    }

    $validItems = [];
    if (empty($errors)) {
        for ($i = 0; $i < count($itemIds); $i++) {
            $itemId = intval($itemIds[$i]);
            if ($itemId <= 0) continue;

            $qty = max(1, intval($itemQtys[$i] ?? 1));
            $rate = floatval($itemRates[$i] ?? 0);
            $discPct = floatval($itemDiscounts[$i] ?? 0);
            $taxPct = floatval($itemTaxes[$i] ?? 0);

            $item = fetch("SELECT * FROM items WHERE id = ? AND status = 1", [$itemId]);
            if (!$item) {
                $errors[] = "Item #$itemId not found.";
                continue;
            }

            $lineSubtotal = $qty * $rate;
            $lineDiscount = ($lineSubtotal * $discPct) / 100;
            $afterDiscount = $lineSubtotal - $lineDiscount;
            $lineTax = ($afterDiscount * $taxPct) / 100;
            $lineTotal = $afterDiscount + $lineTax;

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
    }

    if ($paidAmount < 0) {
        $errors[] = 'Paid amount cannot be negative.';
    }

    if (empty($errors)) {
        // Recalculate totals from valid items
        $calcSubtotal = 0;
        $calcTax = 0;
        $calcDiscount = 0;
        foreach ($validItems as $vi) {
            $calcSubtotal += ($vi['qty'] * $vi['rate']);
            $calcTax += $vi['tax_amount'];
            $calcDiscount += $vi['discount'];
        }
        $calcGrand = $calcSubtotal - $calcDiscount + $calcTax;
        $dueAmount = max(0, $calcGrand - $paidAmount);

        if ($dueAmount <= 0) {
            $paymentStatus = 'paid';
        } elseif ($paidAmount > 0) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'unpaid';
        }

        global $pdo;
        $pdo->beginTransaction();
        try {
            $saleId = insertId(
                "INSERT INTO sales (invoice_no, party_id, user_id, date, subtotal, tax_amount, discount_amount, total, paid_amount, due_amount, payment_status, payment_method, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$invoiceNo, $partyId ?: null, $_SESSION['user_id'], $saleDate, $calcSubtotal, $calcTax, $calcDiscount, $calcGrand, $paidAmount, $dueAmount, $paymentStatus, $paymentMethod, $notes, $paymentStatus === 'paid' ? 'paid' : 'draft']
            );

            foreach ($validItems as $vi) {
                query(
                    "INSERT INTO sale_items (sale_id, item_id, qty, rate, discount, tax_rate, tax_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$saleId, $vi['item_id'], $vi['qty'], $vi['rate'], $vi['discount'], $vi['tax_rate'], $vi['tax_amount'], $vi['total']]
                );
                updateStock($vi['item_id'], $vi['qty'], 'subtract');
            }

            if ($paidAmount > 0) {
                $receiptNo = generateReceiptNo();
                query(
                    "INSERT INTO payments_in (receipt_no, party_id, sale_id, date, amount, payment_method, notes, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$receiptNo, $partyId, $saleId, $saleDate, $paidAmount, $paymentMethod, 'Auto: Sale ' . $invoiceNo, $_SESSION['user_id']]
                );
            }

            $pdo->commit();
            setFlash('success', 'Sale invoice created successfully.');
            header('Location: sale_view.php?id=' . $saleId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error creating sale: ' . $e->getMessage());
            header('Location: sale_add.php');
            exit;
        }
    }
}

$nextInvoice = generateInvoiceNo();
$pageTitle = 'New Sale Invoice';
include 'header.php';
?>

<style>
.sale-form .item-search-wrapper { position: relative; }
.sale-form .item-search-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1080;
    background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; display: none;
}
.sale-form .item-search-dropdown.show { display: block; }
.sale-form .item-search-dropdown .srch-item {
    padding: 8px 12px; cursor: pointer; display: flex; justify-content: space-between;
    align-items: center; font-size: 13px; border-bottom: 1px solid #f5f5f5;
}
.sale-form .item-search-dropdown .srch-item:hover { background: #f0f4ff; }
.sale-form .item-search-dropdown .srch-item .si-name { font-weight: 500; }
.sale-form .item-search-dropdown .srch-item .si-stock { font-size: 11px; color: #6c757d; }
.sale-form .item-search-dropdown .srch-item .si-price { color: #2962FF; font-weight: 600; font-size: 13px; }
.sale-form .item-search-dropdown .no-results { padding: 12px; text-align: center; color: #6c757d; font-size: 13px; }
.party-search-wrapper { position: relative; }
.party-search-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1080;
    background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; display: none;
}
.party-search-dropdown.show { display: block; }
.party-search-dropdown .srch-item {
    padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f5f5f5;
}
.party-search-dropdown .srch-item:hover { background: #f0f4ff; }
.totals-box .total-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
.totals-box .total-row.grand { border-top: 2px solid #dee2e6; padding-top: 10px; margin-top: 6px; font-size: 20px; font-weight: 700; color: #2962FF; }
.stock-warn { font-size: 11px; color: #dc3545; margin-top: 2px; display: none; }
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
        <div class="col-md-3">
            <label class="form-label fw-semibold">Invoice No</label>
            <input type="text" name="invoice_no" class="form-control" value="<?= sanitize($nextInvoice) ?>" readonly>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" name="date" class="form-control" value="<?= sanitize($old['date']) ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Payment Method</label>
            <select name="payment_method" class="form-select">
                <option value="cash" <?= $old['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                <option value="bank" <?= $old['payment_method'] === 'bank' ? 'selected' : '' ?>>Bank</option>
                <option value="upi" <?= $old['payment_method'] === 'upi' ? 'selected' : '' ?>>UPI</option>
                <option value="cheque" <?= $old['payment_method'] === 'cheque' ? 'selected' : '' ?>>Cheque</option>
            </select>
        </div>
        <div class="col-md-3">
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
                <input type="text" id="party_search" class="form-control" placeholder="Type to search party..." autocomplete="off" value="<?= sanitize($selectedPartyName) ?>">
                <input type="hidden" name="party_id" id="party_id" value="<?= $old['party_id'] ?>">
                <div class="party-search-dropdown" id="partyDropdown"></div>
            </div>
            <small class="text-muted"><a href="party_add.php" target="_blank">+ Add New Party</a></small>
        </div>
            <small class="text-muted"><a href="party_add.php" target="_blank">+ Add New Party</a></small>
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
                            <th style="width:10%">Disc %</th>
                            <th style="width:10%">Tax %</th>
                            <th style="width:12%" class="text-end">Total</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsContainer">
                        <tr class="item-row" data-index="0">
                            <td>1</td>
                            <td>
                                <input type="hidden" name="item_id[]" class="item-id" value="">
                                <div class="item-search-wrapper">
                                    <input type="text" class="form-control form-control-sm item-search" placeholder="Type to search..." autocomplete="off">
                                    <div class="item-search-dropdown"></div>
                                </div>
                            </td>
                            <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="1"></td>
                            <td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="0"></td>
                            <td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" max="100" value="0"></td>
                            <td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="0"></td>
                            <td class="text-end fw-bold item-line-total">0.00</td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>
                        </tr>
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
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save & Print</button>
        <a href="sales.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var rowIndex = 1;

    // === Party Search ===
    var partySearch = document.getElementById('party_search');
    var partyIdField = document.getElementById('party_id');
    var partyDropdown = document.getElementById('partyDropdown');
    var partyTimer;

    partySearch.addEventListener('input', function() {
        clearTimeout(partyTimer);
        var q = this.value.trim();
        if (q.length < 2) { partyDropdown.classList.remove('show'); return; }
        partyTimer = setTimeout(function() {
            fetch('api/parties_search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    partyDropdown.innerHTML = '';
                    if (data.length === 0) { partyDropdown.innerHTML = '<div class="no-results" style="padding:10px;text-align:center;color:#6c757d;font-size:13px;">No parties found</div>'; partyDropdown.classList.add('show'); return; }
                    data.forEach(function(p) {
                        var d = document.createElement('div');
                        d.className = 'srch-item';
                        d.innerHTML = '<div><strong>' + escapeH(p.name) + '</strong><br><small class="text-muted">' + escapeH(p.phone || '') + '</small></div>';
                        d.addEventListener('click', function() {
                            partySearch.value = p.name;
                            partyIdField.value = p.id;
                            partyDropdown.classList.remove('show');
                        });
                        partyDropdown.appendChild(d);
                    });
                    partyDropdown.classList.add('show');
                });
        }, 300);
    });

    partySearch.addEventListener('focus', function() { if (this.value.trim().length >= 2) partyDropdown.classList.add('show'); });
    document.addEventListener('click', function(e) {
        if (!partySearch.parentElement.contains(e.target)) partyDropdown.classList.remove('show');
    });

    // === Item Search ===
    function initItemSearch(row) {
        var searchInput = row.querySelector('.item-search');
        var dropdown = row.querySelector('.item-search-dropdown');
        var timer;

        searchInput.addEventListener('input', function() {
            clearTimeout(timer);
            var q = this.value.trim();
            if (q.length < 2) { dropdown.classList.remove('show'); return; }
            timer = setTimeout(function() {
                fetch('api/items_search.php?q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(items) {
                        dropdown.innerHTML = '';
                        if (items.length === 0) { dropdown.innerHTML = '<div class="no-results">No items found</div>'; dropdown.classList.add('show'); return; }
                        items.forEach(function(item) {
                            var d = document.createElement('div');
                            d.className = 'srch-item';
                            d.innerHTML = '<div><div class="si-name">' + escapeH(item.name) + '</div><div class="si-stock">Stock: ' + item.current_stock + '</div></div><div class="si-price">₹' + parseFloat(item.sale_price).toFixed(2) + '</div>';
                            d.addEventListener('click', function() {
                                searchInput.value = item.name;
                                row.querySelector('.item-id').value = item.id;
                                row.querySelector('.item-rate').value = parseFloat(item.sale_price).toFixed(2);
                                row.querySelector('.item-tax').value = item.tax_rate || 0;
                                dropdown.classList.remove('show');
                                calcRow(row);
                                calcGrand();
                            });
                            dropdown.appendChild(d);
                        });
                        dropdown.classList.add('show');
                    });
            }, 300);
        });

        searchInput.addEventListener('focus', function() { if (this.value.trim().length >= 2) dropdown.classList.add('show'); });
        document.addEventListener('click', function(e) { if (!searchInput.parentElement.contains(e.target)) dropdown.classList.remove('show'); });
    }

    function calcRow(row) {
        var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        var rate = parseFloat(row.querySelector('.item-rate').value) || 0;
        var discPct = parseFloat(row.querySelector('.item-disc').value) || 0;
        var taxPct = parseFloat(row.querySelector('.item-tax').value) || 0;

        var sub = qty * rate;
        var disc = (sub * discPct) / 100;
        var afterDisc = sub - disc;
        var tax = (afterDisc * taxPct) / 100;
        var total = afterDisc + tax;

        row.querySelector('.item-line-total').textContent = '₹' + total.toFixed(2);
    }

    function calcGrand() {
        var rows = document.querySelectorAll('.item-row');
        var sub = 0, disc = 0, tax = 0;
        rows.forEach(function(row) {
            var qty = parseFloat(row.querySelector('.item-qty').value) || 0;
            var rate = parseFloat(row.querySelector('.item-rate').value) || 0;
            var discPct = parseFloat(row.querySelector('.item-disc').value) || 0;
            var taxPct = parseFloat(row.querySelector('.item-tax').value) || 0;
            var lineSub = qty * rate;
            var lineDisc = (lineSub * discPct) / 100;
            var lineTax = ((lineSub - lineDisc) * taxPct) / 100;
            sub += lineSub; disc += lineDisc; tax += lineTax;
            calcRow(row);
        });
        var grand = sub - disc + tax;
        var paid = parseFloat(document.getElementById('paid_amount').value) || 0;
        var due = Math.max(0, grand - paid);

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
        }
        if (e.target.id === 'paid_amount') { calcGrand(); }
    });

    // Add row
    document.getElementById('addRow').addEventListener('click', function() {
        var container = document.getElementById('itemsContainer');
        var tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.setAttribute('data-index', rowIndex);
        tr.innerHTML = '<td>' + (container.querySelectorAll('.item-row').length + 1) + '</td>' +
            '<td><input type="hidden" name="item_id[]" class="item-id" value=""><div class="item-search-wrapper"><input type="text" class="form-control form-control-sm item-search" placeholder="Type to search..." autocomplete="off"><div class="item-search-dropdown"></div></div></td>' +
            '<td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="1"></td>' +
            '<td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="0"></td>' +
            '<td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" max="100" value="0"></td>' +
            '<td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="0"></td>' +
            '<td class="text-end fw-bold item-line-total">0.00</td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row" title="Remove"><i class="fas fa-times"></i></button></td>';
        container.appendChild(tr);
        initItemSearch(tr);
        rowIndex++;
        tr.querySelector('.item-search').focus();
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

    // Init first row
    initItemSearch(document.querySelector('.item-row'));
    calcGrand();
});

function escapeH(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
</script>

<?php include 'footer.php'; ?>
