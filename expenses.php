<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// Handle delete
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: expenses.php');
        exit;
    }
    $delId = intval($_GET['delete']);

    $chk = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE type = 'expense' AND reference_id = ?");
    $chk->execute([$delId]);
    if ($chk->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete expense: linked transactions exist.');
        header('Location: expenses.php');
        exit;
    }

    $delStmt = $pdo->prepare("DELETE FROM expenses WHERE id = ?");
    $delStmt->execute([$delId]);
    setFlash('success', 'Expense deleted successfully.');
    header('Location: expenses.php');
    exit;
}

// Filters
$filterFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
$filterTo = isset($_GET['to']) ? trim($_GET['to']) : '';
$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filterPayment = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;

$where = [];
$params = [];

$fy = currentFY();
if (!empty($fy['start'])) { $where[] = "e.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "e.date <= ?"; $params[] = $fy['end']; }

if ($filterFrom !== '') {
    $where[] = "e.date >= ?";
    $params[] = dateDB($filterFrom);
}
if ($filterTo !== '') {
    $where[] = "e.date <= ?";
    $params[] = dateDB($filterTo);
}
if ($filterCategory > 0) {
    $where[] = "e.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterPayment !== '' && in_array($filterPayment, ['cash', 'bank', 'upi', 'cheque'])) {
    $where[] = "e.payment_method = ?";
    $params[] = $filterPayment;
}
if ($search !== '') {
    $where[] = "(e.expense_no LIKE ? OR e.reference_no LIKE ? OR e.notes LIKE ? OR ec.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id $whereSql");
$countStmt->execute($params);
$totalExpenses = $countStmt->fetchColumn();
$p = paginate($totalExpenses, $perPage, $page);

// Fetch expenses
$sql = "SELECT e.*, ec.name as category_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON e.category_id = ec.id
        $whereSql
        ORDER BY e.date DESC, e.id DESC
        LIMIT {$p['per_page']} OFFSET {$p['offset']}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary cards
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$today = date('Y-m-d');

$monthStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= ? AND date <= ?");
$monthStmt->execute([$monthStart, $monthEnd]);
$totalMonth = (float) $monthStmt->fetchColumn();

$todayStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date = ?");
$todayStmt->execute([$today]);
$totalToday = (float) $todayStmt->fetchColumn();

$daysInMonth = (int) date('t');
$avgDaily = $daysInMonth > 0 ? $totalMonth / $daysInMonth : 0;

// Expense summary by category (for filtered range or current month)
$catWhere = "WHERE e.date >= ? AND e.date <= ?";
$catParams = [$monthStart, $monthEnd];
if ($filterFrom !== '') {
    $catWhere = "WHERE e.date >= ?";
    $catParams = [dateDB($filterFrom)];
    if ($filterTo !== '') {
        $catWhere = "WHERE e.date >= ? AND e.date <= ?";
        $catParams = [dateDB($filterFrom), dateDB($filterTo)];
    }
}

$catSummaryStmt = $pdo->prepare(
    "SELECT ec.name as category_name, COALESCE(SUM(e.amount), 0) as total
     FROM expenses e
     LEFT JOIN expense_categories ec ON e.category_id = ec.id
     $catWhere
     GROUP BY e.category_id, ec.name
     ORDER BY total DESC"
);
$catSummaryStmt->execute($catParams);
$catSummary = $catSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

$catGrandTotal = 0;
foreach ($catSummary as $cs) {
    $catGrandTotal += (float) $cs['total'];
}

// Categories for filter dropdown
$allCategories = $pdo->query("SELECT id, name FROM expense_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Expenses';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="expense_add.php" class="btn btn-primary"><i class="fa fa-plus"></i> Add Expense</a>
        <a href="expense_categories.php" class="btn btn-outline-secondary"><i class="fa fa-tags"></i> Categories</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= h($filterFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= h($filterTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <option value="cash" <?= $filterPayment === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="bank" <?= $filterPayment === 'bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="upi" <?= $filterPayment === 'upi' ? 'selected' : '' ?>>UPI</option>
                    <option value="cheque" <?= $filterPayment === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Expense No, Ref, Notes..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa fa-search"></i> Filter</button>
                <a href="expenses.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted">Total Expenses (This Month)</small>
                <h5 class="mb-0 text-danger"><?= money($totalMonth) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted">Today's Expenses</small>
                <h5 class="mb-0 text-warning"><?= money($totalToday) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-2">
                <small class="text-muted">Average Daily Expenses</small>
                <h5 class="mb-0 text-info"><?= money($avgDaily) ?></h5>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-9">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Expense No</th>
                            <th>Category</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">No expenses found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $idx => $exp): ?>
                                <tr>
                                    <td><?= $p['offset'] + $idx + 1 ?></td>
                                    <td><strong><?= h($exp['expense_no']) ?></strong></td>
                                    <td><?= h($exp['category_name'] ?? 'N/A') ?></td>
                                    <td><?= dateFormatted($exp['date']) ?></td>
                                    <td class="text-danger fw-bold"><?= money($exp['amount']) ?></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(h($exp['payment_method'])) ?></span></td>
                                    <td><?= h($exp['reference_no']) ?: '-' ?></td>
                                    <td><?= h($exp['notes']) ? mb_strimwidth(h($exp['notes']), 0, 30, '...') : '-' ?></td>
                                    <td>
                                        <a href="expense_add.php?id=<?= $exp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-edit"></i></a>
                                        <a href="expenses.php?delete=<?= $exp['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this expense?')"><i class="fa fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($p['total_pages'] > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?= $p['current_page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p['current_page'] - 1])) ?>">&laquo; Previous</a>
                    </li>
                    <?php for ($i = max(1, $p['current_page'] - 2); $i <= min($p['total_pages'], $p['current_page'] + 2); $i++): ?>
                        <li class="page-item <?= $i == $p['current_page'] ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $p['current_page'] >= $p['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p['current_page'] + 1])) ?>">Next &raquo;</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <div class="col-lg-3">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Expenses by Category</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($catSummary)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($catSummary as $cs): ?>
                                <tr>
                                    <td><?= h($cs['category_name'] ?: 'Uncategorized') ?></td>
                                    <td class="text-end text-danger"><?= money($cs['total']) ?></td>
                                    <td class="text-end"><?= $catGrandTotal > 0 ? number_format(((float)$cs['total'] / $catGrandTotal) * 100, 1) : '0.0' ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td>Total</td>
                                <td class="text-end text-danger"><?= money($catGrandTotal) ?></td>
                                <td class="text-end">100%</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
