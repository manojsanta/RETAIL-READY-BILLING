<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();
$loadChartjs = true;

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dateFrom = $_GET['from_date'] ?? date('Y-01-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $df = dateDB($dateFrom);
    $dt = dateDB($dateTo);

    $salesTotal = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['COALESCE(SUM(total), 0)'] ?? 0;
    $salesReturns = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM sale_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['COALESCE(SUM(total), 0)'] ?? 0;
    $purchaseTotal = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['COALESCE(SUM(total), 0)'] ?? 0;
    $purchaseReturns = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM purchase_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['COALESCE(SUM(total), 0)'] ?? 0;
    $expensesTotal = (float) fetch("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= ? AND date <= ?", [$df, $dt])['COALESCE(SUM(amount), 0)'] ?? 0;

    $netSales = $salesTotal - $salesReturns;
    $cogs = $purchaseTotal - $purchaseReturns;
    $grossProfit = $netSales - $cogs;
    $netProfit = $grossProfit - $expensesTotal;

    $csvData = [
        ['INCOME'],
        ['Sales (Gross)', $salesTotal],
        ['Less: Sales Returns', -$salesReturns],
        ['Net Sales', $netSales],
        [''],
        ['COST OF GOODS SOLD'],
        ['Purchases (Gross)', $purchaseTotal],
        ['Less: Purchase Returns', -$purchaseReturns],
        ['Cost of Goods Sold', $cogs],
        [''],
        ['Gross Profit', $grossProfit],
        [''],
        ['OPERATING EXPENSES'],
        ['Total Operating Expenses', $expensesTotal],
        [''],
        ['NET PROFIT / (LOSS)', $netProfit],
    ];
    exportCSV(['Particulars', 'Amount'], $csvData, 'profit_loss_report');
}

// Filters
$dateFrom = $_GET['from_date'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$df = dateDB($dateFrom);
$dt = dateDB($dateTo);

// Income
$salesTotal = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['COALESCE(SUM(total), 0)'];
$salesReturns = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM sale_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['COALESCE(SUM(total), 0)'];
$salesTax = (float) fetch("SELECT COALESCE(SUM(tax_amount), 0) FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['COALESCE(SUM(tax_amount), 0)'];
$netSales = $salesTotal - $salesReturns;

// COGS
$purchaseTotal = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['COALESCE(SUM(total), 0)'];
$purchaseReturns = (float) fetch("SELECT COALESCE(SUM(total), 0) FROM purchase_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['COALESCE(SUM(total), 0)'];
$cogs = $purchaseTotal - $purchaseReturns;

$grossProfit = $netSales - $cogs;

// Expenses breakdown
$expenseCategories = fetchAll("SELECT ec.name, COALESCE(SUM(e.amount), 0) as total
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.date >= ? AND e.date <= ?
    GROUP BY e.category_id, ec.name ORDER BY total DESC", [$df, $dt]);
$expensesTotal = 0;
foreach ($expenseCategories as $ec) { $expensesTotal += (float) $ec['total']; }

$netProfit = $grossProfit - $expensesTotal;

// Monthly chart data
$monthChart = fetchAll("SELECT DATE_FORMAT(date, '%Y-%m') as month_key,
    (SELECT COALESCE(SUM(total), 0) FROM sales WHERE DATE_FORMAT(date, '%Y-%m') = month_key AND status != 'cancelled') -
    (SELECT COALESCE(SUM(total), 0) FROM purchases WHERE DATE_FORMAT(date, '%Y-%m') = month_key AND status != 'cancelled') -
    (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE_FORMAT(date, '%Y-%m') = month_key) as profit
    FROM (SELECT date FROM sales WHERE date >= ? AND date <= ?
        UNION SELECT date FROM purchases WHERE date >= ? AND date <= ?
        UNION SELECT date FROM expenses WHERE date >= ? AND date <= ?) all_dates
    GROUP BY month_key ORDER BY month_key ASC", [$df, $dt, $df, $dt, $df, $dt]);
$chartLabels = array_map(function($d) {
    return date('M Y', strtotime($d['month_key'] . '-01'));
}, $monthChart);
$chartValues = array_map(function($d) { return (float) $d['profit']; }, $monthChart);

$pageTitle = 'Profit & Loss Report';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.pl-section { border-left: 4px solid; padding-left: 1rem; margin-bottom: 1.5rem; }
.pl-section.income { border-color: #28a745; }
.pl-section.expense { border-color: #dc3545; }
.pl-section.profit { border-color: #007bff; }
.pl-row { display: flex; justify-content: space-between; padding: 0.35rem 0; border-bottom: 1px dotted #eee; }
.pl-row.subtotal { font-weight: 600; border-bottom: 2px solid #333; padding-top: 0.5rem; }
.pl-row.final { font-size: 1.15rem; font-weight: 700; padding: 0.6rem 0; border-bottom: 3px double #333; }
.chart-container { position: relative; height: 300px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Profit & Loss Report</h5>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-6 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Apply</button>
                <a href="report_profit_loss.php" class="btn btn-outline-secondary btn-sm">Reset</a>
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

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Net Sales</small>
                <h5 class="mb-0 text-primary"><?= money($netSales) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Cost of Goods Sold</small>
                <h5 class="mb-0 text-warning"><?= money($cogs) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Operating Expenses</small>
                <h5 class="mb-0 text-danger"><?= money($expensesTotal) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block"><?= $netProfit >= 0 ? 'Net Profit' : 'Net Loss' ?></small>
                <h5 class="mb-0 <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($netProfit) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- P&L Statement -->
<div class="row">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Profit & Loss Statement (<?= dateFormatted($df) ?> to <?= dateFormatted($dt) ?>)</h6></div>
            <div class="card-body">

                <!-- Income -->
                <div class="pl-section income">
                    <h6 class="text-success mb-3"><i class="fas fa-arrow-up me-1"></i>Income</h6>
                    <div class="pl-row"><span>Sales (Gross)</span><span><?= money($salesTotal) ?></span></div>
                    <div class="pl-row"><span>Less: Sales Returns</span><span class="text-danger">- <?= money($salesReturns) ?></span></div>
                    <div class="pl-row subtotal"><span>Net Sales</span><span><?= money($netSales) ?></span></div>
                </div>

                <!-- COGS -->
                <div class="pl-section expense">
                    <h6 class="text-danger mb-3"><i class="fas fa-arrow-down me-1"></i>Cost of Goods Sold</h6>
                    <div class="pl-row"><span>Purchases (Gross)</span><span><?= money($purchaseTotal) ?></span></div>
                    <div class="pl-row"><span>Less: Purchase Returns</span><span class="text-danger">- <?= money($purchaseReturns) ?></span></div>
                    <div class="pl-row subtotal"><span>Cost of Goods Sold</span><span><?= money($cogs) ?></span></div>
                </div>

                <!-- Gross Profit -->
                <div class="pl-section profit">
                    <h6 class="text-primary mb-3"><i class="fas fa-calculator me-1"></i>Gross Profit</h6>
                    <div class="pl-row final"><span>Gross Profit = Net Sales - COGS</span><span class="<?= $grossProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($grossProfit) ?></span></div>
                </div>

                <!-- Operating Expenses -->
                <div class="pl-section expense">
                    <h6 class="text-danger mb-3"><i class="fas fa-receipt me-1"></i>Operating Expenses</h6>
                    <?php if (empty($expenseCategories)): ?>
                        <div class="pl-row"><span>No expenses recorded</span><span>₹0.00</span></div>
                    <?php else: ?>
                        <?php foreach ($expenseCategories as $ec): ?>
                            <div class="pl-row"><span><?= sanitize($ec['name'] ?: 'Uncategorized') ?></span><span><?= money($ec['total']) ?></span></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="pl-row subtotal"><span>Total Operating Expenses</span><span><?= money($expensesTotal) ?></span></div>
                </div>

                <!-- Net Profit -->
                <div class="pl-section profit" style="margin-top: 2rem;">
                    <div class="pl-row final">
                        <span><?= $netProfit >= 0 ? 'NET PROFIT' : 'NET LOSS' ?></span>
                        <span class="<?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($netProfit) ?></span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Monthly Profit Chart -->
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Monthly Profit Trend</h6></div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="profitTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('profitTrendChart');
    if (ctx) {
        var values = <?= json_encode($chartValues) ?>;
        var colors = values.map(function(v) {
            return v >= 0 ? 'rgba(40, 167, 69, 0.7)' : 'rgba(220, 53, 69, 0.7)';
        });
        var borderColors = values.map(function(v) {
            return v >= 0 ? 'rgba(40, 167, 69, 1)' : 'rgba(220, 53, 69, 1)';
        });
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Profit / (Loss)',
                    data: values,
                    backgroundColor: colors,
                    borderColor: borderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { ticks: { callback: function(v) { return '₹' + v.toLocaleString(); } } }
                }
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
