<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: payment_out.php');
        exit;
    }
    $delId = (int)($_POST['id'] ?? 0);
    if ($delId > 0) {
        $payment = fetch("SELECT * FROM payments_out WHERE id = ?", [$delId]);
        if ($payment) {
            $pdo->beginTransaction();
            try {
                if ($payment['purchase_id']) {
                    query("UPDATE purchases SET paid_amount = GREATEST(0, paid_amount - ?), payment_status = CASE WHEN paid_amount - ? <= 0 THEN 'unpaid' WHEN paid_amount - ? < total THEN 'partial' ELSE 'paid' END WHERE id = ?",
                        [$payment['amount'], $payment['amount'], $payment['amount'], $payment['purchase_id']]);
                }
                query("DELETE FROM payments_out WHERE id = ?", [$delId]);
                $pdo->commit();
                setFlash('success', 'Payment deleted successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error deleting payment: ' . $e->getMessage());
            }
        }
    }
    header('Location: payment_out.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action']) && $_POST['action'] === 'save')) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: payment_out.php');
        exit;
    }

    $partyId = (int)($_POST['party_id'] ?? 0);
    $purchaseId = (int)($_POST['purchase_id'] ?? 0);
    $date = dateDB($_POST['date'] ?? today());
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
    $referenceNo = sanitize($_POST['reference_no'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');

    if ($partyId <= 0) {
        setFlash('danger', 'Please select a supplier.');
        header('Location: payment_out.php?action=add');
        exit;
    }
    if ($amount <= 0) {
        setFlash('danger', 'Amount must be greater than zero.');
        header('Location: payment_out.php?action=add');
        exit;
    }

    $paymentNo = generatePaymentNo();

    $pdo->beginTransaction();
    try {
        query(
            "INSERT INTO payments_out (payment_no, party_id, purchase_id, date, amount, payment_method, reference_no, notes, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$paymentNo, $partyId, $purchaseId > 0 ? $purchaseId : null, $date, $amount, $paymentMethod, $referenceNo, $notes, $_SESSION['user_id']]
        );

        if ($purchaseId > 0) {
            $purchase = fetch("SELECT total, paid_amount FROM purchases WHERE id = ?", [$purchaseId]);
            if ($purchase) {
                $newPaid = (float)$purchase['paid_amount'] + $amount;
                $newStatus = 'unpaid';
                if ($newPaid >= (float)$purchase['total']) {
                    $newStatus = 'paid';
                } elseif ($newPaid > 0) {
                    $newStatus = 'partial';
                }
                query("UPDATE purchases SET paid_amount = ?, payment_status = ?, due_amount = GREATEST(0, total - ?) WHERE id = ?",
                    [$newPaid, $newStatus, $newPaid, $purchaseId]);
            }
        }

        $pdo->commit();
        setFlash('success', "Payment {$paymentNo} recorded successfully.");
        header('Location: payment_out.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Error saving payment: ' . $e->getMessage());
        header('Location: payment_out.php?action=add');
        exit;
    }
}

$showAdd = ($_GET['action'] ?? '') === 'add';
$editing = false;
$editData = null;
$suppliers = fetchAll("SELECT id, name, phone FROM parties WHERE (type = 'supplier' OR type = 'both') AND status = 1 ORDER BY name ASC");
$unpaidPurchases = fetchAll("SELECT id, bill_no, party_id, total, paid_amount, due_amount FROM purchases WHERE payment_status != 'paid' ORDER BY date DESC");

if ($showAdd || isset($_GET['edit'])) {
    if (isset($_GET['edit'])) {
        $editing = true;
        $editData = fetch("SELECT * FROM payments_out WHERE id = ?", [(int)$_GET['edit']]);
    }
}

$search = trim($_GET['search'] ?? '');
$filterDate = $_GET['date'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

$fy = currentFY();
if (!empty($fy['start'])) { $where[] = "po.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "po.date <= ?"; $params[] = $fy['end']; }

if ($search !== '') {
    $where[] = "(po.payment_no LIKE ? OR pt.name LIKE ? OR po.reference_no LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterDate !== '') {
    $where[] = "po.date = ?";
    $params[] = dateDB($filterDate);
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
$totalItems = (int) query("SELECT COUNT(*) FROM payments_out po LEFT JOIN parties pt ON po.party_id = pt.id $whereClause", $params)->fetchColumn();
$pagination = paginate($totalItems, $perPage, $page);

$payments = fetchAll(
    "SELECT po.*, pt.name AS party_name, p.bill_no
     FROM payments_out po
     LEFT JOIN parties pt ON po.party_id = pt.id
     LEFT JOIN purchases p ON po.purchase_id = p.id
     $whereClause
     ORDER BY po.date DESC, po.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$todayPaid = (float) query("SELECT COALESCE(SUM(amount), 0) FROM payments_out WHERE date = ?", [today()])->fetchColumn();
$thisMonth = date('Y-m');
$monthPaid = (float) query("SELECT COALESCE(SUM(amount), 0) FROM payments_out WHERE DATE_FORMAT(date, '%Y-%m') = ?", [$thisMonth])->fetchColumn();

$pageTitle = 'Payment Out';
include 'header.php';
?>

<?php if ($showAdd || $editing): ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i><?= $editing ? 'Edit Payment' : 'Record Payment Out' ?></h5>
                    <a href="payment_out.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save">
                        <?= csrfField() ?>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Supplier *</label>
                                <select name="party_id" id="paySupplierSelect" class="form-select" required onchange="loadUnpaidBills(this.value)">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= ($editing && $editData && $editData['party_id'] == $s['id']) ? 'selected' : '' ?>>
                                            <?= sanitize($s['name']) ?><?= $s['phone'] ? ' (' . sanitize($s['phone']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Date *</label>
                                <input type="date" name="date" class="form-control" value="<?= $editing && $editData ? $editData['date'] : today() ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Amount *</label>
                                <input type="number" name="amount" class="form-control" step="0.01" min="0.01" value="<?= $editing && $editData ? $editData['amount'] : '' ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Payment Method</label>
                                <select name="payment_method" class="form-select">
                                    <?php foreach (['cash' => 'Cash', 'bank' => 'Bank Transfer', 'upi' => 'UPI', 'cheque' => 'Cheque'] as $val => $lbl): ?>
                                        <option value="<?= $val ?>" <?= ($editing && $editData && $editData['payment_method'] === $val) ? 'selected' : '' ?>><?= $lbl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Reference No</label>
                                <input type="text" name="reference_no" class="form-control" value="<?= $editing && $editData ? sanitize($editData['reference_no'] ?? '') : '' ?>" placeholder="Cheque/UPI ref">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Link to Purchase Bill</label>
                                <select name="purchase_id" id="payPurchaseSelect" class="form-select">
                                    <option value="">-- None --</option>
                                    <?php foreach ($unpaidPurchases as $up): ?>
                                        <option value="<?= $up['id'] ?>" data-party="<?= $up['party_id'] ?>"
                                                <?= ($editing && $editData && $editData['purchase_id'] == $up['id']) ? 'selected' : '' ?>>
                                            <?= sanitize($up['bill_no']) ?> - Due: <?= money($up['due_amount']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Notes</label>
                                <input type="text" name="notes" class="form-control" value="<?= $editing && $editData ? sanitize($editData['notes'] ?? '') : '' ?>" placeholder="Optional notes">
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Payment</button>
                            <a href="payment_out.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    function loadUnpaidBills(partyId) {
        var select = document.getElementById('payPurchaseSelect');
        var opts = select.querySelectorAll('option[data-party]');
        opts.forEach(function(opt) {
            if (!partyId || opt.dataset.party === partyId) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
                opt.selected = false;
            }
        });
    }
    </script>
<?php endif; ?>

<?php if (!$showAdd && !$editing): ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div></div>
        <a href="payment_out.php?action=add" class="btn btn-primary"><i class="fas fa-plus me-1"></i> Record Payment</a>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <div class="card border-success">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Paid Today</small>
                    <h5 class="mb-0 text-success"><?= money($todayPaid) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-primary">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Paid This Month</small>
                    <h5 class="mb-0 text-primary"><?= money($monthPaid) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= sanitize($filterDate) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Payment No, Party, Reference..." value="<?= sanitize($search) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="payment_out.php" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                        <th>Payment No</th>
                        <th>Party</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Linked Bill</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No payments found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $idx => $pay): ?>
                            <tr>
                                <td><?= $pagination['offset'] + $idx + 1 ?></td>
                                <td><strong><?= sanitize($pay['payment_no']) ?></strong></td>
                                <td>
                                    <?php if ($pay['party_id']): ?>
                                        <a href="party_view.php?id=<?= $pay['party_id'] ?>" class="text-decoration-none"><?= sanitize($pay['party_name'] ?? '-') ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= dateFormatted($pay['date']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= money($pay['amount']) ?></td>
                                <td><span class="badge bg-light text-dark"><?= ucfirst($pay['payment_method']) ?></span></td>
                                <td><?= sanitize($pay['reference_no'] ?? '-') ?></td>
                                <td>
                                    <?php if ($pay['purchase_id']): ?>
                                        <a href="purchase_view.php?id=<?= $pay['purchase_id'] ?>" class="text-decoration-none"><?= sanitize($pay['bill_no'] ?? '-') ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="payment_out.php?edit=<?= $pay['id'] ?>" class="btn btn-outline-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this payment?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $pay['id'] ?>">
                                            <?= csrfField() ?>
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

    <?php
    $filterParams = [];
    if ($search !== '') $filterParams['search'] = $search;
    if ($filterDate !== '') $filterParams['date'] = $filterDate;
    $baseUrl = 'payment_out.php' . ($filterParams ? '?' . http_build_query($filterParams) : '');
    echo paginationLinks($pagination, $baseUrl);
    ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
