<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$mode = $_GET['mode'] ?? 'list';
$editId = intval($_GET['edit'] ?? 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: payment_in.php');
        exit;
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $delId = intval($_POST['payment_id'] ?? 0);
        if ($delId > 0) {
            $pmt = fetch("SELECT * FROM payments_in WHERE id = ?", [$delId]);
            if ($pmt) {
                global $pdo;
                $pdo->beginTransaction();
                try {
                    if ($pmt['sale_id']) {
                        $sale = fetch("SELECT * FROM sales WHERE id = ?", [$pmt['sale_id']]);
                        if ($sale) {
                            $newPaid = max(0, (float)$sale['paid_amount'] - (float)$pmt['amount']);
                            $newDue = max(0, (float)$sale['total'] - $newPaid);
                            $newStatus = $newDue <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
                            query("UPDATE sales SET paid_amount = ?, due_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?",
                                [$newPaid, $newDue, $newStatus, $sale['id']]);
                        }
                    }
                    query("DELETE FROM payments_in WHERE id = ?", [$delId]);
                    $pdo->commit();
                    setFlash('success', 'Payment deleted.');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    setFlash('danger', 'Error deleting payment.');
                }
            }
        }
        header('Location: payment_in.php');
        exit;
    }

    if ($action === 'save') {
        $partyId = intval($_POST['party_id'] ?? 0);
        $pmtDate = sanitize($_POST['date'] ?? today());
        $amount = floatval($_POST['amount'] ?? 0);
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $referenceNo = sanitize($_POST['reference_no'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        $saleId = intval($_POST['sale_id'] ?? 0);

        if ($partyId <= 0) {
            setFlash('danger', 'Please select a party.');
            header('Location: payment_in.php?mode=add');
            exit;
        }
        if ($amount <= 0) {
            setFlash('danger', 'Amount must be greater than 0.');
            header('Location: payment_in.php?mode=add');
            exit;
        }

        $receiptNo = generateReceiptNo();

        global $pdo;
        $pdo->beginTransaction();
        try {
            query(
                "INSERT INTO payments_in (receipt_no, party_id, sale_id, date, amount, payment_method, reference_no, notes, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$receiptNo, $partyId, $saleId ?: null, $pmtDate, $amount, $paymentMethod, $referenceNo, $notes, $_SESSION['user_id']]
            );

            if ($saleId > 0) {
                $sale = fetch("SELECT * FROM sales WHERE id = ?", [$saleId]);
                if ($sale) {
                    $newPaid = (float)$sale['paid_amount'] + $amount;
                    $newDue = max(0, (float)$sale['total'] - $newPaid);
                    $newStatus = $newDue <= 0 ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
                    query("UPDATE sales SET paid_amount = ?, due_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?",
                        [$newPaid, $newDue, $newStatus, $saleId]);
                }
            }

            $pdo->commit();
            setFlash('success', 'Payment recorded successfully.');
            header('Location: payment_in.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error recording payment.');
            header('Location: payment_in.php?mode=add');
            exit;
        }
    }
}

// Edit mode: load existing payment
$editPayment = null;
if ($editId > 0) {
    $editPayment = fetch("SELECT * FROM payments_in WHERE id = ?", [$editId]);
    if ($editPayment) $mode = 'add';
}

// Fetch data for form
$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 AND (type = 'customer' OR type = 'both') ORDER BY name ASC");
$preselectParty = intval($_GET['party_id'] ?? $editPayment['party_id'] ?? 0);
$preselectSale = intval($_GET['sale_id'] ?? $editPayment['sale_id'] ?? 0);

$unpaidSales = [];
if ($preselectParty > 0) {
    $unpaidSales = fetchAll("SELECT id, invoice_no, total, paid_amount, due_amount FROM sales WHERE party_id = ? AND payment_status != 'paid' AND due_amount > 0 ORDER BY date ASC", [$preselectParty]);
}

// Summary
$todayReceived = (float)(fetch("SELECT COALESCE(SUM(amount),0) FROM payments_in WHERE date = ?", [today()]) ?? 0);
$monthStart = date('Y-m-01');
$monthReceived = (float)(fetch("SELECT COALESCE(SUM(amount),0) FROM payments_in WHERE date >= ?", [$monthStart]) ?? 0);

// List view data
$paymentFilter = $_GET['filter'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

$fy = currentFY();
if (!empty($fy['start'])) { $where[] = "pi.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "pi.date <= ?"; $params[] = $fy['end']; }

if ($paymentFilter !== '' && in_array($paymentFilter, ['cash', 'bank', 'upi', 'cheque'])) {
    $where[] = "pi.payment_method = ?";
    $params[] = $paymentFilter;
}
if ($search !== '') {
    $where[] = "(pi.receipt_no LIKE ? OR p.name LIKE ? OR pi.reference_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
$totalPayments = dbCount("SELECT COUNT(*) FROM payments_in pi LEFT JOIN parties p ON pi.party_id = p.id $whereSql", $params);
$pagination = paginate($totalPayments, $perPage, $page);

$payments = fetchAll(
    "SELECT pi.*, p.name as party_name, s.invoice_no as sale_invoice
     FROM payments_in pi
     LEFT JOIN parties p ON pi.party_id = p.id
     LEFT JOIN sales s ON pi.sale_id = s.id
     $whereSql
     ORDER BY pi.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = $mode === 'add' ? 'Record Payment In' : 'Payment In';
include 'header.php';
?>

<?php if ($mode === 'add'): ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-hand-holding-usd me-1"></i> <?= $editPayment ? 'Edit Payment' : 'Record Payment Received' ?></h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Receipt No</label>
                            <input type="text" class="form-control" value="<?= sanitize(generateReceiptNo()) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Party *</label>
                            <select name="party_id" id="party_select" class="form-select" required>
                                <option value="">-- Select Party --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $preselectParty == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?><?= !empty($c['phone']) ? ' (' . sanitize($c['phone']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Date *</label>
                            <input type="date" name="date" class="form-control" value="<?= sanitize($editPayment['date'] ?? today()) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount *</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" value="<?= $editPayment ? num($editPayment['amount']) : '' ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash" <?= ($editPayment['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="bank" <?= ($editPayment['payment_method'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                                <option value="upi" <?= ($editPayment['payment_method'] ?? '') === 'upi' ? 'selected' : '' ?>>UPI</option>
                                <option value="cheque" <?= ($editPayment['payment_method'] ?? '') === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Reference No</label>
                            <input type="text" name="reference_no" class="form-control" value="<?= sanitize($editPayment['reference_no'] ?? '') ?>" placeholder="UTR, Cheque No...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Link to Invoice (Optional)</label>
                            <select name="sale_id" id="sale_select" class="form-select">
                                <option value="">-- General Payment --</option>
                                <?php foreach ($unpaidSales as $us): ?>
                                    <option value="<?= $us['id'] ?>" <?= $preselectSale == $us['id'] ? 'selected' : '' ?>><?= sanitize($us['invoice_no']) ?> - Due: <?= money($us['due_amount']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="notes" class="form-control" value="<?= sanitize($editPayment['notes'] ?? '') ?>" placeholder="Optional notes">
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Payment</button>
                        <a href="payment_in.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var partySelect = document.getElementById('party_select');
    var saleSelect = document.getElementById('sale_select');

    partySelect.addEventListener('change', function() {
        var partyId = this.value;
        if (!partyId) { saleSelect.innerHTML = '<option value="">-- General Payment --</option>'; return; }
        fetch('api/unpaid_sales.php?party_id=' + partyId)
            .then(function(r) { return r.json(); })
            .then(function(sales) {
                saleSelect.innerHTML = '<option value="">-- General Payment --</option>';
                sales.forEach(function(s) {
                    var opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.invoice_no + ' - Due: ₹' + parseFloat(s.due_amount).toFixed(2);
                    saleSelect.appendChild(opt);
                });
            });
    });
});
</script>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Payments Received</h5>
    <a href="payment_in.php?mode=add" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> Record Payment</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Received Today</small>
                <h5 class="mb-0 text-success"><?= money($todayReceived) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Received This Month</small>
                <h5 class="mb-0 text-success"><?= money($monthReceived) ?></h5>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Method</label>
                <select name="filter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="cash" <?= $paymentFilter === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="bank" <?= $paymentFilter === 'bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="upi" <?= $paymentFilter === 'upi' ? 'selected' : '' ?>>UPI</option>
                    <option value="cheque" <?= $paymentFilter === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Receipt No, Party, Reference..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="payment_in.php" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                    <th>Receipt No</th>
                    <th>Party</th>
                    <th>Date</th>
                    <th class="text-end">Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Invoice</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No payments found.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $idx => $pmt): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td class="fw-semibold"><?= sanitize($pmt['receipt_no']) ?></td>
                            <td><a href="party_view.php?id=<?= $pmt['party_id'] ?>" class="text-decoration-none"><?= sanitize($pmt['party_name'] ?? 'N/A') ?></a></td>
                            <td><?= dateFormatted($pmt['date']) ?></td>
                            <td class="text-end fw-bold text-success"><?= money($pmt['amount']) ?></td>
                            <td>
                                <span class="badge bg-secondary"><?= ucfirst($pmt['payment_method']) ?></span>
                            </td>
                            <td><?= sanitize($pmt['reference_no'] ?? '-') ?></td>
                            <td>
                                <?php if ($pmt['sale_id']): ?>
                                    <a href="sale_view.php?id=<?= $pmt['sale_id'] ?>" class="text-decoration-none"><?= sanitize($pmt['sale_invoice'] ?? '') ?></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="payment_in.php?mode=add&edit=<?= $pmt['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this payment? The linked invoice balance will be adjusted.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="payment_id" value="<?= $pmt['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
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
        $baseUrl = 'payment_in.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<?php endif; ?>

<?php include 'footer.php'; ?>
