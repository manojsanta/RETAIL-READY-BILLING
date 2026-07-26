<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$mode = $_GET['mode'] ?? 'list';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: sale_return.php');
        exit;
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $delId = intval($_POST['return_id'] ?? 0);
        if ($delId > 0) {
            $ret = fetch("SELECT * FROM sale_returns WHERE id = ?", [$delId]);
            if ($ret) {
                global $pdo;
                $pdo->beginTransaction();
                try {
                    $retItems = fetchAll("SELECT item_id, qty FROM sale_return_items WHERE return_id = ?", [$delId]);
                    foreach ($retItems as $ri) {
                        updateStock($ri['item_id'], $ri['qty'], 'subtract');
                    }
                    if ($ret['sale_id']) {
                        $saleTotal = (float)(fetch("SELECT COALESCE(SUM(total),0) FROM sale_return_items WHERE return_id = ?", [$delId])['total'] ?? 0);
                        $sale = fetch("SELECT * FROM sales WHERE id = ?", [$ret['sale_id']]);
                        if ($sale) {
                            $newPaid = max(0, (float)$sale['paid_amount'] - $saleTotal);
                            $newDue = max(0, (float)$sale['total'] - $newPaid);
                            $newStatus = $newDue <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
                            query("UPDATE sales SET paid_amount = ?, due_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?",
                                [$newPaid, $newDue, $newStatus, $sale['id']]);
                        }
                    }
                    query("DELETE FROM sale_return_items WHERE return_id = ?", [$delId]);
                    query("DELETE FROM sale_returns WHERE id = ?", [$delId]);
                    $pdo->commit();
                    setFlash('success', 'Return deleted.');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('danger', 'Error deleting return.');
                }
            }
        }
        header('Location: sale_return.php');
        exit;
    }

    if ($action === 'save') {
        $saleId = intval($_POST['sale_id'] ?? 0);
        $returnDate = sanitize($_POST['date'] ?? today());
        $reason = sanitize($_POST['reason'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');

        $returnItemIds = $_POST['return_item_id'] ?? [];
        $returnQtys = $_POST['return_qty'] ?? [];

        if ($saleId <= 0) {
            setFlash('danger', 'Please select an original sale invoice.');
            header('Location: sale_return.php?mode=add');
            exit;
        }
        if ($reason === '') {
            setFlash('danger', 'Reason is required.');
            header('Location: sale_return.php?mode=add&sale_id=' . $saleId);
            exit;
        }

        $sale = fetch("SELECT * FROM sales WHERE id = ?", [$saleId]);
        if (!$sale) {
            setFlash('danger', 'Sale invoice not found.');
            header('Location: sale_return.php?mode=add');
            exit;
        }

        $validItems = [];
        $calcSubtotal = 0;
        $calcTax = 0;

        for ($i = 0; $i < count($returnItemIds); $i++) {
            $itemId = intval($returnItemIds[$i]);
            $qty = intval($returnQtys[$i] ?? 0);
            if ($itemId <= 0 || $qty <= 0) continue;

            $origItem = fetch("SELECT si.*, i.name as item_name FROM sale_items si LEFT JOIN items i ON si.item_id = i.id WHERE si.sale_id = ? AND si.item_id = ?", [$saleId, $itemId]);
            if (!$origItem) continue;

            $maxQty = intval($origItem['qty']);
            if ($qty > $maxQty) $qty = $maxQty;

            $rate = (float)$origItem['rate'];
            $taxPct = (float)$origItem['tax_rate'];
            $lineTotal = $qty * $rate;
            $lineTax = ($lineTotal * $taxPct) / 100;

            $validItems[] = [
                'item_id' => $itemId,
                'qty' => $qty,
                'rate' => $rate,
                'tax_rate' => $taxPct,
                'tax_amount' => $lineTax,
                'total' => $lineTotal + $lineTax,
            ];

            $calcSubtotal += $lineTotal;
            $calcTax += $lineTax;
        }

        if (empty($validItems)) {
            setFlash('danger', 'Please select at least one item to return.');
            header('Location: sale_return.php?mode=add&sale_id=' . $saleId);
            exit;
        }

        $returnNo = generateReturnNo();
        $calcTotal = $calcSubtotal + $calcTax;

        global $pdo;
        $pdo->beginTransaction();
        try {
            $returnId = insertId(
                "INSERT INTO sale_returns (return_no, sale_id, party_id, user_id, date, subtotal, tax_amount, total, reason, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())",
                [$returnNo, $saleId, $sale['party_id'], $_SESSION['user_id'], $returnDate, $calcSubtotal, $calcTax, $calcTotal, $reason, $notes]
            );

            foreach ($validItems as $vi) {
                query(
                    "INSERT INTO sale_return_items (return_id, item_id, qty, rate, tax_rate, tax_amount, total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$returnId, $vi['item_id'], $vi['qty'], $vi['rate'], $vi['tax_rate'], $vi['tax_amount'], $vi['total']]
                );
                updateStock($vi['item_id'], $vi['qty'], 'add');
            }

            // Adjust the original sale
            $newPaid = max(0, (float)$sale['paid_amount'] - $calcTotal);
            $newDue = max(0, (float)$sale['total'] - $newPaid);
            $newStatus = $newDue <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            query("UPDATE sales SET paid_amount = ?, due_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?",
                [$newPaid, $newDue, $newStatus, $saleId]);

            // Create payment_out for refund if paid > total after return
            if ($newPaid > (float)$sale['total'] - $calcTotal) {
                $refundAmount = $newPaid - ((float)$sale['total'] - $calcTotal);
                if ($refundAmount > 0) {
                    $payNo = generatePaymentNo();
                    query(
                        "INSERT INTO payments_out (payment_no, party_id, purchase_id, date, amount, payment_method, notes, user_id, created_at) VALUES (?, ?, NULL, ?, ?, 'cash', ?, ?, NOW())",
                        [$payNo, $sale['party_id'], $returnDate, $refundAmount, 'Refund for return ' . $returnNo, $_SESSION['user_id']]
                    );
                }
            }

            $pdo->commit();
            setFlash('success', 'Sale return created successfully.');
            header('Location: sale_return.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error creating return: ' . $e->getMessage());
            header('Location: sale_return.php?mode=add');
            exit;
        }
    }
}

// Fetch sale items for AJAX
if (isset($_GET['ajax_sale_items']) && $_GET['ajax_sale_items'] === '1') {
    header('Content-Type: application/json');
    $saleId = intval($_GET['sale_id'] ?? 0);
    $items = fetchAll("SELECT si.*, i.name as item_name, i.current_stock FROM sale_items si LEFT JOIN items i ON si.item_id = i.id WHERE si.sale_id = ?", [$saleId]);
    echo json_encode($items);
    exit;
}

// List data
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$totalReturns = count("SELECT COUNT(*) FROM sale_returns");
$pagination = paginate($totalReturns, $perPage, $page);

$returns = fetchAll(
    "SELECT sr.*, p.name as party_name, s.invoice_no as sale_invoice
     FROM sale_returns sr
     LEFT JOIN parties p ON sr.party_id = p.id
     LEFT JOIN sales s ON sr.sale_id = s.id
     ORDER BY sr.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"
);

$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 AND (type = 'customer' OR type = 'both') ORDER BY name ASC");

$pageTitle = 'Sale Returns';
include 'header.php';
?>

<?php if ($mode === 'add'): ?>
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-undo me-1"></i> Create Sale Return / Credit Note</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="returnForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Original Sale Invoice *</label>
                            <select name="sale_id" id="sale_select" class="form-select" required>
                                <option value="">-- Select Invoice --</option>
                                <?php
                                $allSales = fetchAll("SELECT s.id, s.invoice_no, s.date, s.total, s.due_amount, p.name as party_name
                                    FROM sales s LEFT JOIN parties p ON s.party_id = p.id
                                    WHERE s.payment_status != 'cancelled' ORDER BY s.date DESC");
                                foreach ($allSales as $sl): ?>
                                    <option value="<?= $sl['id'] ?>"><?= sanitize($sl['invoice_no']) ?> - <?= sanitize($sl['party_name'] ?? 'Walk-in') ?> (<?= dateFormatted($sl['date']) ?>) - <?= money($sl['total']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date *</label>
                            <input type="date" name="date" class="form-select" value="<?= today() ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Reason *</label>
                            <input type="text" name="reason" class="form-control" placeholder="e.g. Defective item, Wrong delivery..." required>
                        </div>
                    </div>

                    <div id="itemsArea" style="display:none;">
                        <h6 class="mb-2 fw-semibold">Select Items to Return</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px"><input type="checkbox" id="selectAll"></th>
                                        <th>Item</th>
                                        <th class="text-center">Sold Qty</th>
                                        <th class="text-center">Return Qty</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Tax</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsBody"></tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="6" class="text-end fw-bold">Total Return Value:</td>
                                        <td class="text-end fw-bold" id="returnTotal">0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="row g-3 mt-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Notes (Optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Return</button>
                        <a href="sale_return.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var saleSelect = document.getElementById('sale_select');
    var itemsArea = document.getElementById('itemsArea');
    var itemsBody = document.getElementById('itemsBody');
    var returnTotal = document.getElementById('returnTotal');
    var selectAll = document.getElementById('selectAll');

    saleSelect.addEventListener('change', function() {
        var saleId = this.value;
        if (!saleId) { itemsArea.style.display = 'none'; return; }

        fetch('sale_return.php?ajax_sale_items=1&sale_id=' + saleId)
            .then(function(r) { return r.json(); })
            .then(function(items) {
                itemsBody.innerHTML = '';
                if (items.length === 0) { itemsArea.style.display = 'none'; return; }
                itemsArea.style.display = 'block';

                items.forEach(function(item) {
                    var tr = document.createElement('tr');
                    var lineTotal = parseFloat(item.total);
                    tr.innerHTML =
                        '<td><input type="checkbox" class="ret-check" data-idx="' + item.item_id + '"></td>' +
                        '<td>' + escapeH(item.item_name || 'Deleted Item') +
                            '<input type="hidden" name="return_item_id[]" value="' + item.item_id + '" class="ret-item-id" disabled></td>' +
                        '<td class="text-center">' + item.qty + '</td>' +
                        '<td class="text-center"><input type="number" name="return_qty[]" class="form-control form-control-sm text-center ret-qty" min="1" max="' + item.qty + '" value="0" disabled style="width:70px;"></td>' +
                        '<td class="text-end">₹' + parseFloat(item.rate).toFixed(2) + '</td>' +
                        '<td class="text-end">' + parseFloat(item.tax_rate).toFixed(1) + '%</td>' +
                        '<td class="text-end ret-amount">₹' + parseFloat(item.total).toFixed(2) + '</td>';
                    itemsBody.appendChild(tr);
                });

                // Auto-select all and set max qty
                document.querySelectorAll('.ret-check').forEach(function(cb) {
                    cb.checked = true;
                    var tr = cb.closest('tr');
                    var qtyInput = tr.querySelector('.ret-qty');
                    var itemIdInput = tr.querySelector('.ret-item-id');
                    qtyInput.disabled = false;
                    itemIdInput.disabled = false;
                    qtyInput.value = qtyInput.getAttribute('max');
                });
                calcReturn();
            });
    });

    itemsBody.addEventListener('change', function(e) {
        if (e.target.classList.contains('ret-check')) {
            var tr = e.target.closest('tr');
            var qtyInput = tr.querySelector('.ret-qty');
            var itemIdInput = tr.querySelector('.ret-item-id');
            if (e.target.checked) {
                qtyInput.disabled = false;
                itemIdInput.disabled = false;
                qtyInput.value = qtyInput.getAttribute('max');
            } else {
                qtyInput.disabled = true;
                itemIdInput.disabled = true;
                qtyInput.value = 0;
            }
            calcReturn();
        }
        if (e.target.classList.contains('ret-qty')) {
            calcReturn();
        }
    });

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.ret-check').forEach(function(cb) {
                cb.checked = checked;
                cb.dispatchEvent(new Event('change'));
            });
        });
    }

    function calcReturn() {
        var total = 0;
        document.querySelectorAll('.ret-check:checked').forEach(function(cb) {
            var tr = cb.closest('tr');
            var soldQty = parseInt(tr.children[2].textContent) || 0;
            var returnQty = parseInt(tr.querySelector('.ret-qty').value) || 0;
            var rate = parseFloat(tr.children[4].textContent.replace('₹','')) || 0;
            var taxPct = parseFloat(tr.children[5].textContent) || 0;
            var lineTotal = returnQty * rate;
            var lineTax = (lineTotal * taxPct) / 100;
            total += lineTotal + lineTax;
            tr.querySelector('.ret-amount').textContent = '₹' + (lineTotal + lineTax).toFixed(2);
        });
        returnTotal.textContent = '₹' + total.toFixed(2);
    }
});

function escapeH(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
</script>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Sale Returns / Credit Notes</h5>
    <a href="sale_return.php?mode=add" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Return</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Return No</th>
                    <th>Original Invoice</th>
                    <th>Party</th>
                    <th>Date</th>
                    <th class="text-end">Total</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No returns found.</td></tr>
                <?php else: ?>
                    <?php foreach ($returns as $idx => $ret): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td class="fw-semibold"><?= sanitize($ret['return_no']) ?></td>
                            <td>
                                <?php if ($ret['sale_id']): ?>
                                    <a href="sale_view.php?id=<?= $ret['sale_id'] ?>" class="text-decoration-none"><?= sanitize($ret['sale_invoice'] ?? '') ?></a>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?= sanitize($ret['party_name'] ?? 'N/A') ?></td>
                            <td><?= dateFormatted($ret['date']) ?></td>
                            <td class="text-end fw-bold text-danger"><?= money($ret['total']) ?></td>
                            <td><small><?= sanitize(mb_strimwidth($ret['reason'] ?? '', 0, 40, '...')) ?></small></td>
                            <td>
                                <?php if ($ret['status'] === 'approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                <?php elseif ($ret['status'] === 'draft'): ?>
                                    <span class="badge bg-secondary">Draft</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Cancelled</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this return? Stock will be reversed.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="return_id" value="<?= $ret['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
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
        $baseUrl = 'sale_return.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<?php endif; ?>

<?php include 'footer.php'; ?>
