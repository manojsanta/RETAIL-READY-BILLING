<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

function generatePurchaseReturnNo($prefix = 'PR') {
    $last = fetch("SELECT return_no FROM purchase_returns ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['return_no'])) {
        $num = intval(substr($last['return_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    if ($_GET['ajax'] === 'search_purchase') {
        $q = trim($_GET['q'] ?? '');
        $purchases = fetchAll(
            "SELECT p.id, p.bill_no, p.date, p.total, p.paid_amount, p.due_amount, p.party_id, pt.name AS supplier_name
             FROM purchases p
             LEFT JOIN parties pt ON p.party_id = pt.id
             WHERE p.bill_no LIKE ?
             ORDER BY p.date DESC LIMIT 10",
            ["%$q%"]
        );
        echo json_encode($purchases);
    } elseif ($_GET['ajax'] === 'get_purchase_items') {
        $pid = (int)($_GET['purchase_id'] ?? 0);
        $items = fetchAll(
            "SELECT pi.*, i.name AS item_name, i.sku, i.current_stock
             FROM purchase_items pi
             LEFT JOIN items i ON pi.item_id = i.id
             WHERE pi.purchase_id = ?",
            [$pid]
        );
        echo json_encode($items);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: purchase_return.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_return') {
        $retId = (int)($_POST['return_id'] ?? 0);
        if ($retId > 0) {
            $ret = fetch("SELECT * FROM purchase_returns WHERE id = ?", [$retId]);
            if ($ret && $ret['status'] === 'draft') {
                $retItems = fetchAll("SELECT item_id, qty FROM purchase_return_items WHERE return_id = ?", [$retId]);
                $pdo->beginTransaction();
                try {
                    foreach ($retItems as $ri) {
                        updateStock($ri['item_id'], $ri['qty'], 'add');
                    }
                    query("DELETE FROM purchase_return_items WHERE return_id = ?", [$retId]);
                    query("DELETE FROM purchase_returns WHERE id = ?", [$retId]);
                    $pdo->commit();
                    setFlash('success', 'Return deleted successfully.');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('danger', 'Error deleting return: ' . $e->getMessage());
                }
            } else {
                setFlash('danger', 'Only draft returns can be deleted.');
            }
        }
        header('Location: purchase_return.php');
        exit;
    }

    if ($action === 'save_return') {
        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        $date = dateDB($_POST['date'] ?? today());
        $reason = sanitize($_POST['reason'] ?? '');

        $retItemIds = $_POST['ret_item_id'] ?? [];
        $retQtys = $_POST['ret_qty'] ?? [];
        $retRates = $_POST['ret_rate'] ?? [];
        $retTaxRates = $_POST['ret_tax_rate'] ?? [];

        if ($purchaseId <= 0) {
            setFlash('danger', 'Please select a purchase bill.');
            header('Location: purchase_return.php?action=add');
            exit;
        }

        $purchase = fetch("SELECT * FROM purchases WHERE id = ?", [$purchaseId]);
        if (!$purchase) {
            setFlash('danger', 'Purchase bill not found.');
            header('Location: purchase_return.php?action=add');
            exit;
        }

        $subtotal = 0;
        $taxTotal = 0;
        $lineItems = [];

        for ($i = 0; $i < count($retItemIds); $i++) {
            $itemId = (int)$retItemIds[$i];
            $qty = max(1, (int)($retQtys[$i] ?? 0));
            $rate = (float)($retRates[$i] ?? 0);
            $taxRate = (float)($retTaxRates[$i] ?? 0);

            if ($qty <= 0 || $itemId <= 0) continue;

            $lineTotal = $rate * $qty;
            $lineTax = round($lineTotal * $taxRate / 100, 2);
            $lineTotal += $lineTax;

            $subtotal += ($rate * $qty);
            $taxTotal += $lineTax;

            $lineItems[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'rate' => $rate,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'total' => $lineTotal,
            ];
        }

        if (empty($lineItems)) {
            setFlash('danger', 'Please select at least one item to return.');
            header('Location: purchase_return.php?action=add');
            exit;
        }

        $total = $subtotal + $taxTotal;
        $returnNo = generatePurchaseReturnNo();

        $pdo->beginTransaction();
        try {
            $returnId = insertId(
                "INSERT INTO purchase_returns (return_no, purchase_id, party_id, user_id, date, subtotal, tax_amount, total, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')",
                [$returnNo, $purchaseId, $purchase['party_id'], $_SESSION['user_id'], $date, $subtotal, $taxTotal, $total, $reason]
            );

            foreach ($lineItems as $li) {
                query(
                    "INSERT INTO purchase_return_items (return_id, item_id, qty, rate, tax_rate, tax_amount, total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$returnId, $li['item_id'], $li['qty'], $li['rate'], $li['tax_rate'], $li['tax_amount'], $li['total']]
                );
                updateStock($li['item_id'], $li['qty'], 'subtract');
            }

            $pdo->commit();
            setFlash('success', "Return {$returnNo} created successfully.");
            header('Location: purchase_return.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error creating return: ' . $e->getMessage());
            header('Location: purchase_return.php?action=add');
            exit;
        }
    }
}

$showAdd = ($_GET['action'] ?? '') === 'add';

$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

$fy = currentFY();
if (!empty($fy['start'])) { $where[] = "pr.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "pr.date <= ?"; $params[] = $fy['end']; }

if ($search !== '') {
    $where[] = "(pr.return_no LIKE ? OR p.bill_no LIKE ? OR pt.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
$totalItems = (int) query("SELECT COUNT(*) FROM purchase_returns pr LEFT JOIN purchases p ON pr.purchase_id = p.id LEFT JOIN parties pt ON pr.party_id = pt.id $whereClause", $params)->fetchColumn();
$pagination = paginate($totalItems, $perPage, $page);

$returns = fetchAll(
    "SELECT pr.*, p.bill_no, pt.name AS supplier_name
     FROM purchase_returns pr
     LEFT JOIN purchases p ON pr.purchase_id = p.id
     LEFT JOIN parties pt ON pr.party_id = pt.id
     $whereClause
     ORDER BY pr.date DESC, pr.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Purchase Returns';
include 'header.php';
?>

<?php if ($showAdd): ?>
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-undo-alt me-2"></i>New Purchase Return</h5>
                <a href="purchase_return.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
            </div>
            <div class="card-body">
                <form method="POST" id="returnForm">
                    <input type="hidden" name="action" value="save_return">
                    <?= csrfField() ?>

                    <div class="row g-3 mb-4">
                        <div class="col-md-5 position-relative">
                            <label class="form-label fw-semibold">Search Purchase Bill *</label>
                            <input type="text" id="purchaseSearch" class="form-control" placeholder="Search by Bill No..." autocomplete="off" required>
                            <input type="hidden" name="purchase_id" id="purchaseId" value="">
                            <div id="purchaseDropdown" class="supplier-search-dropdown"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Supplier</label>
                            <input type="text" id="returnSupplier" class="form-control" readonly placeholder="Auto-filled">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Date *</label>
                            <input type="date" name="date" class="form-control" value="<?= today() ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">Original Total</label>
                            <input type="text" id="originalTotal" class="form-control" readonly>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table" id="returnItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Item</th>
                                    <th>Purchased Qty</th>
                                    <th>Current Stock</th>
                                    <th>Rate</th>
                                    <th>Tax %</th>
                                    <th style="width:100px">Return Qty</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody id="returnItemsBody">
                                <tr><td colspan="8" class="text-center text-muted py-3">Select a purchase bill to load items.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Reason for Return</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Reason for returning items..."></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Return Subtotal</label>
                            <input type="text" id="retSubtotal" class="form-control" readonly value="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Return Total</label>
                            <input type="text" id="retTotal" class="form-control fw-bold" readonly value="0.00">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Return</button>
                        <a href="purchase_return.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    .supplier-search-dropdown {
        position: absolute;
        z-index: 1050;
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
        max-height: 250px;
        overflow-y: auto;
        width: 100%;
        display: none;
    }
    .supplier-search-dropdown .dropdown-item:hover { background: #f0f0f0; cursor: pointer; }
</style>

<script>
(function() {
    var searchInput = document.getElementById('purchaseSearch');
    var dropdown = document.getElementById('purchaseDropdown');
    var timer = null;

    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 1) { dropdown.style.display = 'none'; return; }
        timer = setTimeout(function() {
            fetch('purchase_return.php?ajax=search_purchase&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.length === 0) { dropdown.style.display = 'none'; return; }
                    var html = '';
                    data.forEach(function(p) {
                        html += '<div class="dropdown-item px-3 py-2" data-id="' + p.id + '" data-name="' + (p.supplier_name || 'Walk-in') + '" data-total="' + p.total + '">';
                        html += '<strong>' + p.bill_no + '</strong> - ' + (p.supplier_name || 'Walk-in');
                        html += '<br><small>Date: ' + p.date + ' | Total: ' + parseFloat(p.total).toFixed(2) + '</small>';
                        html += '</div>';
                    });
                    dropdown.innerHTML = html;
                    dropdown.style.display = 'block';
                    dropdown.querySelectorAll('.dropdown-item').forEach(function(el) {
                        el.addEventListener('click', function() {
                            document.getElementById('purchaseId').value = this.dataset.id;
                            searchInput.value = this.querySelector('strong').textContent;
                            document.getElementById('returnSupplier').value = this.dataset.name;
                            document.getElementById('originalTotal').value = parseFloat(this.dataset.total).toFixed(2);
                            dropdown.style.display = 'none';
                            loadPurchaseItems(this.dataset.id);
                        });
                    });
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    function loadPurchaseItems(purchaseId) {
        fetch('purchase_return.php?ajax=get_purchase_items&purchase_id=' + purchaseId)
            .then(function(r) { return r.json(); })
            .then(function(items) {
                var tbody = document.getElementById('returnItemsBody');
                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No items found for this purchase.</td></tr>';
                    return;
                }
                var html = '';
                items.forEach(function(item, idx) {
                    html += '<tr>';
                    html += '<td>' + (idx + 1) + '</td>';
                    html += '<td>' + item.item_name + ' <small class="text-muted">(' + item.sku + ')</small>';
                    html += '<input type="hidden" name="ret_item_id[]" value="' + item.item_id + '">';
                    html += '<input type="hidden" name="ret_rate[]" value="' + item.rate + '">';
                    html += '<input type="hidden" name="ret_tax_rate[]" value="' + item.tax_rate + '">';
                    html += '</td>';
                    html += '<td>' + item.qty + '</td>';
                    html += '<td>' + item.current_stock + '</td>';
                    html += '<td>' + parseFloat(item.rate).toFixed(2) + '</td>';
                    html += '<td>' + parseFloat(item.tax_rate).toFixed(1) + '%</td>';
                    html += '<td><input type="number" name="ret_qty[]" class="form-control form-control-sm ret-qty" min="0" max="' + item.qty + '" value="0" data-rate="' + item.rate + '" data-tax="' + item.tax_rate + '"></td>';
                    html += '<td class="text-end row-total">0.00</td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
                recalcReturn();

                tbody.querySelectorAll('.ret-qty').forEach(function(inp) {
                    inp.addEventListener('input', recalcReturn);
                });
            });
    }

    function recalcReturn() {
        var subtotal = 0;
        var taxTotal = 0;
        document.querySelectorAll('.ret-qty').forEach(function(inp) {
            var qty = parseInt(inp.value) || 0;
            var rate = parseFloat(inp.dataset.rate) || 0;
            var tax = parseFloat(inp.dataset.tax) || 0;
            var lineSub = rate * qty;
            var lineTax = lineSub * tax / 100;
            subtotal += lineSub;
            taxTotal += lineTax;
            var rowTotal = lineSub + lineTax;
            inp.closest('tr').querySelector('.row-total').textContent = rowTotal.toFixed(2);
        });
        document.getElementById('retSubtotal').value = subtotal.toFixed(2);
        document.getElementById('retTotal').value = (subtotal + taxTotal).toFixed(2);
    }
})();
</script>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <a href="purchase_return.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-1"></i> New Purchase Return</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Return No, Bill No, Supplier..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="purchase_return.php" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                    <th>Return No</th>
                    <th>Original Bill</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No purchase returns found.</td></tr>
                <?php else: ?>
                    <?php foreach ($returns as $idx => $ret): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td><strong><?= sanitize($ret['return_no']) ?></strong></td>
                            <td>
                                <?php if ($ret['purchase_id']): ?>
                                    <a href="purchase_view.php?id=<?= $ret['purchase_id'] ?>" class="text-decoration-none"><?= sanitize($ret['bill_no'] ?? '-') ?></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($ret['supplier_name'] ?? '-') ?></td>
                            <td><?= dateFormatted($ret['date']) ?></td>
                            <td class="text-end fw-bold text-danger"><?= money($ret['total']) ?></td>
                            <td><small><?= sanitize(substr($ret['reason'] ?? '-', 0, 40)) ?></small></td>
                            <td>
                                <?php if ($ret['status'] === 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($ret['status'] === 'cancelled'): ?>
                                    <span class="badge bg-secondary">Cancelled</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Draft</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ret['status'] === 'draft'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this return?');">
                                        <input type="hidden" name="action" value="delete_return">
                                        <input type="hidden" name="return_id" value="<?= $ret['id'] ?>">
                                        <?= csrfField() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$filterParams = [];
if ($search !== '') $filterParams['search'] = $search;
$baseUrl = 'purchase_return.php' . ($filterParams ? '?' . http_build_query($filterParams) : '');
echo paginationLinks($pagination, $baseUrl);
?>

<?php endif; ?>

<?php include 'footer.php'; ?>
