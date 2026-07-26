<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$mode = $_GET['mode'] ?? 'list';
$editId = intval($_GET['edit'] ?? 0);
$editEstimate = null;
$editItems = [];

if ($editId > 0) {
    $editEstimate = fetch("SELECT * FROM estimates WHERE id = ?", [$editId]);
    if ($editEstimate) {
        $editItems = fetchAll("SELECT ei.*, i.name AS item_name FROM estimate_items ei LEFT JOIN items i ON ei.item_id = i.id WHERE ei.estimate_id = ?", [$editId]);
        $mode = 'form';
    }
}

// Handle Convert to Sale
if (isset($_GET['convert']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: estimates.php');
        exit;
    }
    $convId = intval($_GET['convert']);
    $convEst = fetch("SELECT * FROM estimates WHERE id = ? AND status = 'accepted'", [$convId]);
    if ($convEst) {
        global $pdo;
        $pdo->beginTransaction();
        try {
            $invoiceNo = generateInvoiceNo();
            $saleId = insertId(
                "INSERT INTO sales (invoice_no, party_id, user_id, date, subtotal, tax_amount, discount_amount, total, paid_amount, due_amount, payment_status, payment_method, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'unpaid', NULL, ?, 'draft', NOW())",
                [$invoiceNo, $convEst['party_id'], $_SESSION['user_id'], $convEst['date'], $convEst['subtotal'], $convEst['tax_amount'], $convEst['discount_amount'], $convEst['total'], $convEst['total'], 'Converted from estimate ' . $convEst['estimate_no']]
            );
            $convItems = fetchAll("SELECT * FROM estimate_items WHERE estimate_id = ?", [$convId]);
            foreach ($convItems as $ci) {
                query("INSERT INTO sale_items (sale_id, item_id, qty, rate, discount, tax_rate, tax_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$saleId, $ci['item_id'], $ci['qty'], $ci['rate'], $ci['discount'], $ci['tax_rate'], $ci['tax_amount'], $ci['total']]);
                updateStock($ci['item_id'], $ci['qty'], 'subtract');
            }
            query("UPDATE estimates SET status = 'converted' WHERE id = ?", [$convId]);
            $pdo->commit();
            setFlash('success', 'Estimate converted to sale successfully. Invoice: ' . $invoiceNo);
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error converting estimate: ' . $e->getMessage());
        }
    } else {
        setFlash('danger', 'Estimate not found or not accepted.');
    }
    header('Location: estimates.php');
    exit;
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: estimates.php');
        exit;
    }
    $delId = intval($_GET['delete']);
    query("DELETE FROM estimate_items WHERE estimate_id = ?", [$delId]);
    query("DELETE FROM estimates WHERE id = ?", [$delId]);
    setFlash('success', 'Estimate deleted successfully.');
    header('Location: estimates.php');
    exit;
}

// Handle POST (Save Estimate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: estimates.php');
        exit;
    }

    $partyId = intval($_POST['party_id'] ?? 0);
    $estDate = sanitize($_POST['date'] ?? today());
    $validUntil = sanitize($_POST['valid_until'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $status = sanitize($_POST['status'] ?? 'draft');
    $grandTotal = floatval($_POST['grand_total'] ?? 0);
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $totalTax = floatval($_POST['total_tax'] ?? 0);
    $totalDiscount = floatval($_POST['total_discount'] ?? 0);

    if (!in_array($status, ['draft', 'sent', 'accepted', 'rejected', 'converted'])) $status = 'draft';

    $itemIds = $_POST['item_id'] ?? [];
    $itemQtys = $_POST['item_qty'] ?? [];
    $itemRates = $_POST['item_rate'] ?? [];
    $itemDiscounts = $_POST['item_discount'] ?? [];
    $itemTaxes = $_POST['item_tax'] ?? [];

    $validItems = [];
    for ($i = 0; $i < count($itemIds); $i++) {
        $itemId = intval($itemIds[$i]);
        if ($itemId <= 0) continue;
        $qty = max(1, intval($itemQtys[$i] ?? 1));
        $rate = floatval($itemRates[$i] ?? 0);
        $discPct = floatval($itemDiscounts[$i] ?? 0);
        $taxPct = floatval($itemTaxes[$i] ?? 0);
        $item = fetch("SELECT * FROM items WHERE id = ? AND status = 1", [$itemId]);
        if (!$item) continue;

        $lineSub = $qty * $rate;
        $lineDisc = ($lineSub * $discPct) / 100;
        $afterDisc = $lineSub - $lineDisc;
        $lineTax = ($afterDisc * $taxPct) / 100;
        $lineTotal = $afterDisc + $lineTax;

        $validItems[] = [
            'item_id' => $itemId, 'qty' => $qty, 'rate' => $rate,
            'discount' => $lineDisc, 'tax_rate' => $taxPct, 'tax_amount' => $lineTax, 'total' => $lineTotal,
        ];
    }

    if (empty($validItems)) {
        setFlash('danger', 'Please add at least one item.');
        header('Location: estimates.php?mode=form');
        exit;
    }

    $calcSub = 0; $calcTax = 0; $calcDisc = 0;
    foreach ($validItems as $vi) {
        $calcSub += ($vi['qty'] * $vi['rate']);
        $calcTax += $vi['tax_amount'];
        $calcDisc += $vi['discount'];
    }
    $calcGrand = $calcSub - $calcDisc + $calcTax;

    global $pdo;
    $pdo->beginTransaction();
    try {
        $editIdPost = intval($_POST['edit_id'] ?? 0);
        if ($editIdPost > 0) {
            $estimateNo = fetch("SELECT estimate_no FROM estimates WHERE id = ?", [$editIdPost])['estimate_no'];
            query("UPDATE estimates SET party_id=?, date=?, valid_until=?, subtotal=?, tax_amount=?, discount_amount=?, total=?, notes=?, status=? WHERE id=?",
                [$partyId ?: null, $estDate, $validUntil ?: null, $calcSub, $calcTax, $calcDisc, $calcGrand, $notes, $status, $editIdPost]);
            query("DELETE FROM estimate_items WHERE estimate_id = ?", [$editIdPost]);
            $estId = $editIdPost;
        } else {
            $estimateNo = generateEstimateNo();
            $estId = insertId(
                "INSERT INTO estimates (estimate_no, party_id, user_id, date, subtotal, tax_amount, discount_amount, total, valid_until, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$estimateNo, $partyId ?: null, $_SESSION['user_id'], $estDate, $calcSub, $calcTax, $calcDisc, $calcGrand, $validUntil ?: null, $notes, $status]
            );
        }

        foreach ($validItems as $vi) {
            query("INSERT INTO estimate_items (estimate_id, item_id, qty, rate, discount, tax_rate, tax_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$estId, $vi['item_id'], $vi['qty'], $vi['rate'], $vi['discount'], $vi['tax_rate'], $vi['tax_amount'], $vi['total']]);
        }

        $pdo->commit();
        setFlash('success', 'Estimate saved successfully.');
        header('Location: estimates.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error saving estimate: ' . $e->getMessage());
        header('Location: estimates.php?mode=form');
        exit;
    }
}

$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 AND (type = 'customer' OR type = 'both') ORDER BY name ASC");

// List view
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(e.estimate_no LIKE ? OR p.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter !== '' && in_array($statusFilter, ['draft', 'sent', 'accepted', 'rejected', 'converted'])) {
    $where[] = "e.status = ?";
    $params[] = $statusFilter;
}
$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$totalEstimates = count("SELECT COUNT(*) FROM estimates e LEFT JOIN parties p ON e.party_id = p.id $whereSql", $params);
$pagination = paginate($totalEstimates, $perPage, $page);

$estimates = fetchAll(
    "SELECT e.*, p.name AS party_name FROM estimates e LEFT JOIN parties p ON e.party_id = p.id $whereSql ORDER BY e.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Estimates/Quotations';
include 'header.php';
?>

<?php if ($mode === 'form'): ?>
<style>
    .item-search-wrapper { position: relative; }
    .item-search-dropdown {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 1080;
        background: #fff; border: 1px solid #dee2e6; border-radius: 0 0 8px 8px;
        box-shadow: 0 6px 20px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; display: none;
    }
    .item-search-dropdown.show { display: block; }
    .item-search-dropdown .srch-item {
        padding: 8px 12px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f5f5f5;
    }
    .item-search-dropdown .srch-item:hover { background: #f0f4ff; }
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
</style>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-contract me-1"></i> <?= $editEstimate ? 'Edit Estimate' : 'New Estimate' ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" id="estimateForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="edit_id" value="<?= $editEstimate['id'] ?? 0 ?>">
                    <input type="hidden" name="subtotal" id="hidden_subtotal" value="0">
                    <input type="hidden" name="total_discount" id="hidden_discount" value="0">
                    <input type="hidden" name="total_tax" id="hidden_tax" value="0">
                    <input type="hidden" name="grand_total" id="hidden_grand" value="0">

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Estimate No</label>
                            <input type="text" class="form-control" value="<?= sanitize($editEstimate['estimate_no'] ?? generateEstimateNo()) ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date</label>
                            <input type="date" name="date" class="form-control" value="<?= sanitize($editEstimate['date'] ?? today()) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Valid Until</label>
                            <input type="date" name="valid_until" class="form-control" value="<?= sanitize($editEstimate['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Party</label>
                            <div class="party-search-wrapper">
                                <?php
                                $selPartyName = '';
                                if ($editEstimate && $editEstimate['party_id']) {
                                    foreach ($customers as $c) { if ($c['id'] == $editEstimate['party_id']) { $selPartyName = $c['name']; break; } }
                                }
                                ?>
                                <input type="text" id="party_search" class="form-control" placeholder="Type to search..." autocomplete="off" value="<?= sanitize($selPartyName) ?>">
                                <input type="hidden" name="party_id" id="party_id" value="<?= $editEstimate['party_id'] ?? 0 ?>">
                                <div class="party-search-dropdown" id="partyDropdown"></div>
                            </div>
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
                                        <?php if (!empty($editItems)): ?>
                                            <?php foreach ($editItems as $idx => $ei): ?>
                                                <tr class="item-row" data-index="<?= $idx ?>">
                                                    <td><?= $idx + 1 ?></td>
                                                    <td>
                                                        <input type="hidden" name="item_id[]" class="item-id" value="<?= $ei['item_id'] ?>">
                                                        <div class="item-search-wrapper">
                                                            <input type="text" class="form-control form-control-sm item-search" value="<?= sanitize($ei['item_name']) ?>" autocomplete="off">
                                                            <div class="item-search-dropdown"></div>
                                                        </div>
                                                    </td>
                                                    <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" min="1" value="<?= $ei['qty'] ?>"></td>
                                                    <td><input type="number" name="item_rate[]" class="form-control form-control-sm item-rate" step="0.01" min="0" value="<?= $ei['rate'] ?>"></td>
                                                    <td><input type="number" name="item_discount[]" class="form-control form-control-sm item-disc" step="0.01" min="0" max="100" value="<?= $ei['tax_rate'] > 0 ? round(($ei['discount'] / ($ei['qty'] * $ei['rate'])) * 100, 2) : 0 ?>"></td>
                                                    <td><input type="number" name="item_tax[]" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="<?= $ei['tax_rate'] ?>"></td>
                                                    <td class="text-end fw-bold item-line-total">0.00</td>
                                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
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
                                                <td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>
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
                                    <textarea name="notes" class="form-control" rows="2"><?= sanitize($editEstimate['notes'] ?? '') ?></textarea>
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
                                        <label class="form-label fw-semibold">Status</label>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="draft" <?= ($editEstimate['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                                            <option value="sent" <?= ($editEstimate['status'] ?? '') === 'sent' ? 'selected' : '' ?>>Sent</option>
                                            <option value="accepted" <?= ($editEstimate['status'] ?? '') === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                            <option value="rejected" <?= ($editEstimate['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mb-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Estimate</button>
                        <a href="estimates.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var rowIndex = <?= count($editItems) ?: 1 ?>;

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
                    if (data.length === 0) { partyDropdown.innerHTML = '<div class="srch-item text-muted">No parties found</div>'; partyDropdown.classList.add('show'); return; }
                    data.forEach(function(p) {
                        var d = document.createElement('div');
                        d.className = 'srch-item';
                        d.innerHTML = '<strong>' + esc(p.name) + '</strong><br><small class="text-muted">' + esc(p.phone || '') + '</small>';
                        d.addEventListener('click', function() { partySearch.value = p.name; partyIdField.value = p.id; partyDropdown.classList.remove('show'); });
                        partyDropdown.appendChild(d);
                    });
                    partyDropdown.classList.add('show');
                });
        }, 300);
    });
    partySearch.addEventListener('focus', function() { if (this.value.trim().length >= 2) partyDropdown.classList.add('show'); });
    document.addEventListener('click', function(e) { if (!partySearch.parentElement.contains(e.target)) partyDropdown.classList.remove('show'); });

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
                        if (items.length === 0) { dropdown.innerHTML = '<div class="srch-item text-muted">No items found</div>'; dropdown.classList.add('show'); return; }
                        items.forEach(function(item) {
                            var d = document.createElement('div');
                            d.className = 'srch-item';
                            d.innerHTML = '<div><strong>' + esc(item.name) + '</strong><br><small class="text-muted">Stock: ' + item.current_stock + '</small></div><div class="fw-bold">₹' + parseFloat(item.sale_price).toFixed(2) + '</div>';
                            d.addEventListener('click', function() {
                                searchInput.value = item.name;
                                row.querySelector('.item-id').value = item.id;
                                row.querySelector('.item-rate').value = parseFloat(item.sale_price).toFixed(2);
                                row.querySelector('.item-tax').value = item.tax_rate || 0;
                                dropdown.classList.remove('show');
                                calcRow(row); calcGrand();
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
        var tax = ((sub - disc) * taxPct) / 100;
        var total = sub - disc + tax;
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
            var lsub = qty * rate; var ldisc = (lsub * discPct) / 100; var ltax = ((lsub - ldisc) * taxPct) / 100;
            sub += lsub; disc += ldisc; tax += ltax;
            calcRow(row);
        });
        var grand = sub - disc + tax;
        document.getElementById('disp_subtotal').textContent = '₹' + sub.toFixed(2);
        document.getElementById('disp_discount').textContent = '-₹' + disc.toFixed(2);
        document.getElementById('disp_tax').textContent = '₹' + tax.toFixed(2);
        document.getElementById('disp_grand').textContent = '₹' + grand.toFixed(2);
        document.getElementById('hidden_subtotal').value = sub.toFixed(2);
        document.getElementById('hidden_discount').value = disc.toFixed(2);
        document.getElementById('hidden_tax').value = tax.toFixed(2);
        document.getElementById('hidden_grand').value = grand.toFixed(2);
    }

    document.addEventListener('input', function(e) {
        if (e.target.matches('.item-qty, .item-rate, .item-disc, .item-tax')) {
            var row = e.target.closest('.item-row');
            if (row) { calcRow(row); calcGrand(); }
        }
    });

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
            '<td><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="fas fa-times"></i></button></td>';
        container.appendChild(tr);
        initItemSearch(tr);
        rowIndex++;
        tr.querySelector('.item-search').focus();
    });

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.remove-row');
        if (!btn) return;
        var rows = document.querySelectorAll('.item-row');
        if (rows.length <= 1) return;
        btn.closest('.item-row').remove();
        document.querySelectorAll('.item-row').forEach(function(r, i) { r.querySelector('td').textContent = i + 1; });
        calcGrand();
    });

    document.querySelectorAll('.item-row').forEach(function(row) { initItemSearch(row); });
    calcGrand();
});

function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
</script>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="estimates.php?mode=form" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Estimate</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Estimate No, Party..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_filter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="accepted" <?= $statusFilter === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="converted" <?= $statusFilter === 'converted' ? 'selected' : '' ?>>Converted</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="estimates.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Estimate No</th>
                    <th>Party</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th>Valid Until</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($estimates)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No estimates found.</td></tr>
                <?php else: ?>
                    <?php foreach ($estimates as $idx => $est): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td class="fw-bold"><?= sanitize($est['estimate_no']) ?></td>
                            <td><?= sanitize($est['party_name'] ?? 'Walk-in') ?></td>
                            <td><?= dateFormatted($est['date']) ?></td>
                            <td class="text-end fw-bold"><?= money($est['total']) ?></td>
                            <td><?= $est['valid_until'] ? dateFormatted($est['valid_until']) : '-' ?></td>
                            <td>
                                <span class="badge bg-<?= $est['status'] === 'accepted' ? 'success' : ($est['status'] === 'rejected' ? 'danger' : ($est['status'] === 'converted' ? 'info' : ($est['status'] === 'sent' ? 'warning' : 'secondary'))) ?>">
                                    <?= ucfirst($est['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="estimates.php?mode=form&edit=<?= $est['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if ($est['status'] === 'accepted'): ?>
                                        <a href="estimates.php?convert=<?= $est['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-outline-success" title="Convert to Sale" onclick="return confirm('Convert this estimate to a sale invoice?')"><i class="fas fa-exchange-alt"></i></a>
                                    <?php endif; ?>
                                    <a href="estimates.php?delete=<?= $est['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this estimate?')"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
    <nav class="mt-3">
        <?php
        $baseUrl = 'estimates.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<?php endif; ?>

<?php include 'footer.php'; ?>
