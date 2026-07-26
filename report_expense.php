<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();
$loadChartjs = true;

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dateFrom = $_GET['from_date'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $categoryId = intval($_GET['category_id'] ?? 0);
    $paymentMethod = $_GET['payment_method'] ?? '';

    $where = [];
    $params = [];
    if ($dateFrom !== '') { $where[] = "e.date >= ?"; $params[] = dateDB($dateFrom); }
    if ($dateTo !== '') { $where[] = "e.date <= ?"; $params[] = dateDB($dateTo); }
    if ($categoryId > 0) { $where[] = "e.category_id = ?"; $params[] = $categoryId; }
    if (in_array($paymentMethod, ['cash','bank','upi','cheque'])) { $where[] = "e.payment_method = ?"; $params[] = $paymentMethod; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = fetchAll("SELECT e.date, e.expense_no, ec.name as category_name, e.amount, e.payment_method, e.reference_no, e.notes
        FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id $whereSql ORDER BY e.date ASC", $params);
    $csvData = [];
    foreach ($rows as $r) {
        $csvData[] = [$r['date'], $r['expense_no'], $r['category_name'] ?: 'Uncategorized', $r['amount'], ucfirst($r['payment_method']), $r['reference_no'], $r['notes']];
    }
    exportCSV(['Date','Expense No','Category','Amount','Method','Reference','Notes'], $csvData, 'expense_report');
}

// Filters
$dateFrom = $_GET['from_date'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$categoryId = intval($_GET['category_id'] ?? 0);
$paymentMethod = $_GET['payment_method'] ?? '';

$where = [];
$params = [];
if ($dateFrom !== '') { $where[] = "e.date >= ?"; $params[] = dateDB($dateFrom); }
if ($dateTo !== '') { $where[] = "e.date <= ?"; $params[] = dateDB($dateTo); }
if ($categoryId > 0) { $where[] = "e.category_id = ?"; $params[] = $categoryId; }
if (in_array($paymentMethod, ['cash','bank','upi','cheque'])) { $where[] = "e.payment_method = ?"; $params[] = $paymentMethod; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Summary
$summary = fetch("SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as count FROM expenses e $whereSql", $params);
$totalExpenses = (float) $summary['total'];
$expenseCount = (int) $summary['count'];

// Date range for averaging
if ($dateFrom !== '' && $dateTo !== '') {
    $d1 = new DateTime(dateDB($dateFrom));
    $d2 = new DateTime(dateDB($dateTo));
    $diff = $d1->diff($d2);
    $daysInRange = max(1, $diff->days + 1);
} else {
    $daysInRange = date('t');
}
$avgDaily = $totalExpenses / $daysInRange;
$avgMonthly = $avgDaily * 30;

// Expense list
$expenses = fetchAll("SELECT e.*, ec.name as category_name
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    $whereSql ORDER BY e.date DESC, e.id DESC", $params);

// Category breakdown
$catBreakdown = fetchAll("SELECT ec.name as category_name, COALESCE(SUM(e.amount), 0) as total
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    $whereSql GROUP BY e.category_id, ec.name ORDER BY total DESC", $params);

// Monthly chart
$monthChart = fetchAll("SELECT DATE_FORMAT(e.date, '%Y-%m') as month_key, SUM(e.amount) as total
    FROM expenses e $whereSql GROUP BY month_key ORDER BY month_key ASC", $params);
$chartLabels = array_map(function($d) { return date('M Y', strtotime($d['month_key'] . '-01')); }, $monthChart);
$chartValues = array_map(function($d) { return (float)$d['total']; }, $monthChart);

// Pie chart data
$pieLabels = array_map(function($d) { return $d['category_name'] ?: 'Uncategorized'; }, $catBreakdown);
$pieValues = array_map(function($d) { return (float)$d['total']; }, $catBreakdown);

$allCategories = fetchAll("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name ASC");

$pageTitle = 'Expense Report';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.chart-container { position: relative; height: 300px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Expense Report</h5>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Expenses</small>
                <h4 class="mb-0 text-danger"><?= money($totalExpenses) ?></h4>
                <small class="text-muted"><?= $expenseCount ?> entries</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Average Monthly</small>
                <h4 class="mb-0 text-warning"><?= money($avgMonthly) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Average Daily</small>
                <h4 class="mb-0 text-info"><?= money($avgDaily) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Category</label>
                <select name="category_id" class="form-select form-select-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($allCategories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <option value="cash" <?= $paymentMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="bank" <?= $paymentMethod === 'bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="upi" <?= $paymentMethod === 'upi' ? 'selected' : '' ?>>UPI</option>
                    <option value="cheque" <?= $paymentMethod === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="report_expense.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
        <div class="mt-2 report-quick-btns">
            <span class="text-muted small me-1">Quick:</span>
            <a href="?from_date=<?= date('Y-m-d') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">Today</a>
            <a href="?from_date=<?= date('Y-m-d', strtotime('monday this week')) ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">This Week</a>
            <a href="?from_date=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">This Month</a>
            <a href="?from_date=<?= date('Y-01-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">This Year</a>
            <a href="?from_date=<?= date('Y-m-01', strtotime('first day of last month')) ?>&date_to=<?= date('Y-m-t', strtotime('last day of last month')) ?>" class="btn btn-outline-primary btn-sm">Last Month</a>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Monthly Expense Trend</h6></div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="expenseTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Expenses by Category</h6></div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="expensePieChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Expense Table + Category Breakdown -->
<div class="row">
    <div class="col-lg-9">
        <div class="card mb-4">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Expense No</th>
                            <th>Category</th>
                            <th class="text-end">Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No expenses found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $exp): ?>
                                <tr>
                                    <td><?= dateFormatted($exp['date']) ?></td>
                                    <td><strong><?= sanitize($exp['expense_no']) ?></strong></td>
                                    <td><?= sanitize($exp['category_name'] ?: 'Uncategorized') ?></td>
                                    <td class="text-end text-danger fw-bold"><?= money($exp['amount']) ?></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst(sanitize($exp['payment_method'])) ?></span></td>
                                    <td><?= sanitize($exp['reference_no'] ?: '-') ?></td>
                                    <td><?= sanitize(mb_strimwidth($exp['notes'] ?? '', 0, 30, '...')) ?: '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Category Breakdown</h6></div>
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
                        <?php if (empty($catBreakdown)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No data.</td></tr>
                        <?php else: ?>
                            <?php foreach ($catBreakdown as $cb): ?>
                                <tr>
                                    <td><?= sanitize($cb['category_name'] ?: 'Uncategorized') ?></td>
                                    <td class="text-end text-danger"><?= money($cb['total']) ?></td>
                                    <td class="text-end"><?= $totalExpenses > 0 ? number_format(((float)$cb['total'] / $totalExpenses) * 100, 1) : '0.0' ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td>Total</td>
                                <td class="text-end text-danger"><?= money($totalExpenses) ?></td>
                                <td class="text-end">100%</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Monthly trend
    var ctx1 = document.getElementById('expenseTrendChart');
    if (ctx1) {
        new Chart(ctx1.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Expenses',
                    data: <?= json_encode($chartValues) ?>,
                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(v) { return '₹' + v.toLocaleString(); } } }
                }
            }
        });
    }

    // Pie chart
    var ctx2 = document.getElementById('expensePieChart');
    if (ctx2 && <?= count($pieLabels) ?> > 0) {
        var pieColors = [
            '#dc3545','#fd7e14','#ffc107','#28a745','#17a2b8','#6f42c1','#e83e8c','#6c757d','#343a40'
        ];
        new Chart(ctx2.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($pieLabels) ?>,
                datasets: [{
                    data: <?= json_encode($pieValues) ?>,
                    backgroundColor: pieColors.slice(0, <?= count($pieLabels) ?>)
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
