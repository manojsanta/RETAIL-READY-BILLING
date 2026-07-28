<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// Handle POST delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: sales.php');
        exit;
    }

    $deleteId = intval($_POST['sale_id'] ?? 0);
    if ($deleteId > 0) {
        $hasPayments = dbCount("SELECT COUNT(*) FROM payments_in WHERE sale_id = ?", [$deleteId]);
        if ($hasPayments > 0) {
            setFlash('danger', 'Cannot delete invoice: payment entries exist.');
        } else {
            global $pdo;
            $pdo->beginTransaction();
            try {
                $items = fetchAll("SELECT item_id, qty FROM sale_items WHERE sale_id = ?", [$deleteId]);
                foreach ($items as $itm) {
                    updateStock($itm['item_id'], $itm['qty'], 'add');
                }
                query("DELETE FROM sale_items WHERE sale_id = ?", [$deleteId]);
                query("DELETE FROM sales WHERE id = ?", [$deleteId]);
                $pdo->commit();
                setFlash('success', 'Invoice deleted successfully.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error deleting invoice.');
            }
        }
    }
    header('Location: sales.php');
    exit;
}

// Filters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

$fy = currentFY();
if (!empty($fy['start'])) { $where[] = "s.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "s.date <= ?"; $params[] = $fy['end']; }

if ($dateFrom !== '') {
    $where[] = "s.date >= ?";
    $params[] = dateDB($dateFrom);
}
if ($dateTo !== '') {
    $where[] = "s.date <= ?";
    $params[] = dateDB($dateTo);
}
if (in_array($paymentStatus, ['paid', 'unpaid', 'partial'])) {
    $where[] = "s.payment_status = ?";
    $params[] = $paymentStatus;
}
if ($search !== '') {
    $where[] = "(s.invoice_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$total = dbCount("SELECT COUNT(*) FROM sales s LEFT JOIN parties p ON s.party_id = p.id $whereSql", $params);
$pagination = paginate($total, $perPage, $page);

// Summary cards
$summaryWhere = $whereSql;
$summaryParams = $params;
$summary = fetch("SELECT
    COALESCE(SUM(s.total), 0) as total_sales,
    COALESCE(SUM(s.paid_amount), 0) as total_received,
    COALESCE(SUM(s.due_amount), 0) as total_outstanding
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id $summaryWhere", $summaryParams);

$todaySales = dbCount("SELECT COUNT(*) FROM sales WHERE date = ?" . (!empty($fy['start']) ? " AND date >= ? AND date <= ?" : ""), array_merge([today()], !empty($fy['start']) ? [$fy['start'], $fy['end']] : []));

// Fetch invoices
$invoices = fetchAll(
    "SELECT s.*, p.name as party_name, p.phone as party_phone,
     (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as items_count
     FROM sales s
     LEFT JOIN parties p ON s.party_id = p.id
     $whereSql
     ORDER BY s.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Sale Invoices';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Sale Invoices</h5>
    <a href="sale_add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Sale</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Sales</small>
                <h5 class="mb-0 text-primary"><?= money($summary['total_sales']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Received</small>
                <h5 class="mb-0 text-success"><?= money($summary['total_received']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Total Outstanding</small>
                <h5 class="mb-0 text-danger"><?= money($summary['total_outstanding']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 text-center">
                <small class="text-muted">Today's Sales</small>
                <h5 class="mb-0"><?= intval($todaySales) ?></h5>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="payment_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="partial" <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Invoice, Party name, Phone..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="sales.php" class="btn btn-sm btn-outline-secondary">Reset</a>
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
                    <th>Invoice No</th>
                    <th>Party</th>
                    <th>Date</th>
                    <th class="text-center">Items</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">Tax</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Due</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">No invoices found.</td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $idx => $inv): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td><a href="sale_view.php?id=<?= $inv['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($inv['invoice_no']) ?></a></td>
                            <td><?= sanitize($inv['party_name'] ?? 'Walk-in') ?></td>
                            <td><?= dateFormatted($inv['date']) ?></td>
                            <td class="text-center"><?= intval($inv['items_count']) ?></td>
                            <td class="text-end"><?= money($inv['subtotal']) ?></td>
                            <td class="text-end"><?= money($inv['tax_amount']) ?></td>
                            <td class="text-end"><?= money($inv['discount_amount']) ?></td>
                            <td class="text-end fw-bold"><?= money($inv['total']) ?></td>
                            <td class="text-end text-success"><?= money($inv['paid_amount']) ?></td>
                            <td class="text-end text-danger"><?= money($inv['due_amount']) ?></td>
                            <td>
                                <?php if ($inv['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($inv['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="sale_view.php?id=<?= $inv['id'] ?>" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                                    <button type="button" class="btn btn-outline-secondary" title="Print" onclick="window.open('sale_view.php?id=<?= $inv['id'] ?>&print=1','_blank','width=800,height=600')"><i class="fas fa-print"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this invoice? Stock will be restored.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="sale_id" value="<?= $inv['id'] ?>">
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
        $baseUrl = 'sales.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<?php include 'footer.php'; ?>
