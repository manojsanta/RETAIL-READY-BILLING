<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: purchases.php');
        exit;
    }
    $delId = (int)($_POST['id'] ?? 0);
    if ($delId > 0) {
        $existing = fetch("SELECT id, paid_amount FROM purchases WHERE id = ?", [$delId]);
        if ($existing) {
            $items = fetchAll("SELECT item_id, qty FROM purchase_items WHERE purchase_id = ?", [$delId]);
            $pdo->beginTransaction();
            try {
                foreach ($items as $itm) {
                    updateStock($itm['item_id'], $itm['qty'], 'subtract');
                }
                query("DELETE FROM purchase_items WHERE purchase_id = ?", [$delId]);
                query("DELETE FROM purchases WHERE id = ?", [$delId]);
                $pdo->commit();
                setFlash('success', 'Purchase bill deleted successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error deleting purchase: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', 'Purchase bill not found.');
        }
    }
    header('Location: purchases.php');
    exit;
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

if ($dateFrom !== '') {
    $where[] = "p.date >= ?";
    $params[] = dateDB($dateFrom);
}
if ($dateTo !== '') {
    $where[] = "p.date <= ?";
    $params[] = dateDB($dateTo);
}
if ($filterStatus !== '' && in_array($filterStatus, ['paid', 'unpaid', 'partial'])) {
    $where[] = "p.payment_status = ?";
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = "(p.bill_no LIKE ? OR pt.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$totalItems = (int) query("SELECT COUNT(*) FROM purchases p LEFT JOIN parties pt ON p.party_id = pt.id $whereClause", $params)->fetchColumn();
$pagination = paginate($totalItems, $perPage, $page);

$purchases = fetchAll(
    "SELECT p.*, pt.name AS supplier_name, pt.phone AS supplier_phone
     FROM purchases p
     LEFT JOIN parties pt ON p.party_id = pt.id
     $whereClause
     ORDER BY p.date DESC, p.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$totalPurchases = (float) query("SELECT COALESCE(SUM(total), 0) FROM purchases")->fetchColumn();
$totalPaid = (float) query("SELECT COALESCE(SUM(paid_amount), 0) FROM purchases")->fetchColumn();
$totalPayable = (float) query("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE payment_status != 'paid'")->fetchColumn();
$todayPurchases = (float) query("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date = ?", [today()])->fetchColumn();

$pageTitle = 'Purchase Bills';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <a href="purchase_add.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i> New Purchase</a>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Purchases</small>
                <h5 class="mb-0 text-primary"><?= money($totalPurchases) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Paid</small>
                <h5 class="mb-0 text-success"><?= money($totalPaid) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Payable</small>
                <h5 class="mb-0 text-danger"><?= money($totalPayable) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Today's Purchases</small>
                <h5 class="mb-0 text-info"><?= money($todayPurchases) ?></h5>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Payment Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $filterStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Bill No, Supplier..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="purchases.php" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                    <th>Bill No</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">Tax</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Due</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No purchase bills found.</td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $idx => $pur): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td>
                                <a href="purchase_view.php?id=<?= $pur['id'] ?>" class="fw-semibold text-decoration-none">
                                    <?= sanitize($pur['bill_no']) ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($pur['party_id']): ?>
                                    <a href="party_view.php?id=<?= $pur['party_id'] ?>" class="text-decoration-none"><?= sanitize($pur['supplier_name'] ?? '-') ?></a>
                                <?php else: ?>
                                    <span class="text-muted">Walk-in</span>
                                <?php endif; ?>
                            </td>
                            <td><?= dateFormatted($pur['date']) ?></td>
                            <td class="text-end"><?= money($pur['subtotal']) ?></td>
                            <td class="text-end"><?= money($pur['tax_amount']) ?></td>
                            <td class="text-end"><?= money($pur['discount_amount']) ?></td>
                            <td class="text-end fw-bold"><?= money($pur['total']) ?></td>
                            <td class="text-end text-success"><?= money($pur['paid_amount']) ?></td>
                            <td class="text-end <?= $pur['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= money($pur['due_amount']) ?></td>
                            <td>
                                <?php if ($pur['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($pur['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="purchase_view.php?id=<?= $pur['id'] ?>" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this purchase bill?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $pur['id'] ?>">
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
if ($dateFrom !== '') $filterParams['date_from'] = $dateFrom;
if ($dateTo !== '') $filterParams['date_to'] = $dateTo;
if ($filterStatus !== '') $filterParams['status'] = $filterStatus;
if ($search !== '') $filterParams['search'] = $search;
$baseUrl = 'purchases.php' . ($filterParams ? '?' . http_build_query($filterParams) : '');
echo paginationLinks($pagination, $baseUrl);

include 'footer.php';
?>
