<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$pageTitle = 'Dashboard';
$loadChartjs = true;
include 'header.php';

$fy = currentFY();
$fyStart = $fy['start'];
$fyEnd = $fy['end'];
$monthStart = date('Y-m-01');
$today = today();

$monthSales = (float) query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$monthStart, $today])->fetchColumn();

$monthPurchases = (float) query("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$monthStart, $today])->fetchColumn();

$outstandingDues = (float) query("SELECT COALESCE(SUM(due_amount), 0) FROM sales WHERE payment_status != 'paid' AND status != 'cancelled' AND date >= ? AND date <= ?", [$fyStart, $fyEnd])->fetchColumn();

$totalStock = (int) query("SELECT COALESCE(SUM(current_stock), 0) FROM items WHERE status = 1")->fetchColumn();

$monthExpenses = (float) query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE date >= ? AND date <= ?", [$monthStart, $today])->fetchColumn();

$totalParties = (int) query("SELECT COUNT(*) FROM parties WHERE status = 1")->fetchColumn();

$todaysSales = (float) query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE date = ? AND status != 'cancelled'", [$today])->fetchColumn();

$cashReceived = (float) query("SELECT COALESCE(SUM(total), 0) FROM sales WHERE payment_method = 'cash' AND date >= ? AND date <= ? AND status != 'cancelled'", [$fyStart, $today])->fetchColumn();
$cashPaymentsIn = (float) query("SELECT COALESCE(SUM(amount), 0) FROM payments_in WHERE payment_method = 'cash' AND date >= ? AND date <= ?", [$fyStart, $today])->fetchColumn();
$cashPaymentsOut = (float) query("SELECT COALESCE(SUM(amount), 0) FROM payments_out WHERE payment_method = 'cash' AND date >= ? AND date <= ?", [$fyStart, $today])->fetchColumn();
$cashExpenses = (float) query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE payment_method = 'cash' AND date >= ? AND date <= ?", [$fyStart, $today])->fetchColumn();
$cashInHand = $cashReceived + $cashPaymentsIn - $cashPaymentsOut - $cashExpenses;

$salesChartRows = fetchAll(
    "SELECT MONTH(date) AS m, COALESCE(SUM(total), 0) AS total
     FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'
     GROUP BY MONTH(date) ORDER BY m",
    [$fyStart, $fyEnd]
);
$salesData = [];
foreach ($salesChartRows as $r) {
    $salesData[(int)$r['m']] = (float)$r['total'];
}

$purchaseChartRows = fetchAll(
    "SELECT MONTH(date) AS m, COALESCE(SUM(total), 0) AS total
     FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'
     GROUP BY MONTH(date) ORDER BY m",
    [$fyStart, $fyEnd]
);
$purchaseData = [];
foreach ($purchaseChartRows as $r) {
    $purchaseData[(int)$r['m']] = (float)$r['total'];
}

$chartMonths = [];
$chartSales = [];
$chartPurchases = [];
$fyStartMonth = (int)date('m', strtotime($fyStart));
$fyEndMonth = (int)date('m', strtotime($fyEnd));
$currentMonth = (int)date('m');
$monthCount = 0;
for ($m = $fyStartMonth; $m != $currentMonth; $m = ($m % 12) + 1) {
    $monthCount++;
    if ($monthCount > 12) break;
}
$startDisplay = max(1, $currentMonth - max(5, $monthCount - 1));
for ($i = $startDisplay; $i <= $currentMonth; $i++) {
    $chartMonths[] = date('M', mktime(0, 0, 0, $i, 1));
    $chartSales[] = $salesData[$i] ?? 0;
    $chartPurchases[] = $purchaseData[$i] ?? 0;
}

$expenseCatRows = fetchAll(
    "SELECT COALESCE(ec.name, 'Uncategorized') AS cat_name, COALESCE(SUM(e.amount), 0) AS total
     FROM expenses e
     LEFT JOIN expense_categories ec ON e.category_id = ec.id
     WHERE e.date >= ? AND e.date <= ?
     GROUP BY ec.id, ec.name ORDER BY total DESC",
    [$monthStart, $today]
);

$recentSales = fetchAll(
    "SELECT s.invoice_no, s.date, s.total, s.payment_status,
            COALESCE(p.name, 'Walk-in') AS party_name
     FROM sales s
     LEFT JOIN parties p ON s.party_id = p.id
     WHERE s.status != 'cancelled'
     ORDER BY s.id DESC LIMIT 5"
);

$lowStockItems = fetchAll(
    "SELECT name, current_stock, min_stock FROM items
     WHERE status = 1 AND current_stock <= min_stock
     ORDER BY current_stock ASC LIMIT 10"
);

$recentTransactions = fetchAll(
    "(SELECT 'sale' AS t_type, s.date, COALESCE(p.name, 'Walk-in') AS party_name, s.total AS amount, s.payment_method, s.invoice_no AS ref_no
      FROM sales s LEFT JOIN parties p ON s.party_id = p.id WHERE s.status != 'cancelled')
     UNION ALL
     (SELECT 'purchase' AS t_type, p2.date, COALESCE(p3.name, 'Unknown') AS party_name, p2.total AS amount, p2.payment_method, p2.bill_no AS ref_no
      FROM purchases p2 LEFT JOIN parties p3 ON p2.party_id = p3.id WHERE p2.status != 'cancelled')
     UNION ALL
     (SELECT 'payment_in' AS t_type, pi.date, COALESCE(p4.name, 'Unknown') AS party_name, pi.amount, pi.payment_method, pi.receipt_no AS ref_no
      FROM payments_in pi LEFT JOIN parties p4 ON pi.party_id = p4.id)
     UNION ALL
     (SELECT 'payment_out' AS t_type, po.date, COALESCE(p5.name, 'Unknown') AS party_name, po.amount, po.payment_method, po.payment_no AS ref_no
      FROM payments_out po LEFT JOIN parties p5 ON po.party_id = p5.id)
     UNION ALL
     (SELECT 'expense' AS t_type, e.date, COALESCE(ec2.name, 'Expense') AS party_name, e.amount, e.payment_method, e.expense_no AS ref_no
      FROM expenses e LEFT JOIN expense_categories ec2 ON e.category_id = ec2.id)
     ORDER BY date DESC LIMIT 10"
);
?>

<style>
    .stat-icon-blue { background: rgba(41,98,255,0.12); color: #2962FF; }
    .stat-icon-green { background: rgba(40,167,69,0.12); color: #28a745; }
    .stat-icon-orange { background: rgba(255,152,0,0.12); color: #ff9800; }
    .stat-icon-red { background: rgba(220,53,69,0.12); color: #dc3545; }
    .stat-icon-purple { background: rgba(156,39,176,0.12); color: #9c27b0; }
    .stat-icon-pink { background: rgba(233,30,99,0.12); color: #e91e63; }
    .stat-icon-teal { background: rgba(0,150,136,0.12); color: #009688; }
    .stat-icon-indigo { background: rgba(63,81,181,0.12); color: #3f51b5; }
    .stat-card-blue { border-left-color: #2962FF; }
    .stat-card-green { border-left-color: #28a745; }
    .stat-card-orange { border-left-color: #ff9800; }
    .stat-card-red { border-left-color: #dc3545; }
    .stat-card-purple { border-left-color: #9c27b0; }
    .stat-card-pink { border-left-color: #e91e63; }
    .stat-card-teal { border-left-color: #009688; }
    .stat-card-indigo { border-left-color: #3f51b5; }
    .chart-container { position: relative; height: 320px; }
    .txn-type-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: capitalize;
    }
    .txn-sale { background: rgba(41,98,255,0.1); color: #2962FF; }
    .txn-purchase { background: rgba(40,167,69,0.1); color: #28a745; }
    .txn-payment_in { background: rgba(0,150,136,0.1); color: #009688; }
    .txn-payment_out { background: rgba(255,152,0,0.1); color: #ff9800; }
    .txn-expense { background: rgba(220,53,69,0.1); color: #dc3545; }
    .fy-banner {
        background: linear-gradient(135deg, #eef3ff, #f0f7ff);
        border: 1px solid #c5d5f5;
        border-radius: 10px;
        padding: 10px 16px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 500;
        color: #0d47a1;
    }
    .fy-banner i { font-size: 14px; }
</style>

<div class="fy-banner mb-3">
    <i class="fas fa-calendar-alt"></i>
    <span>Active Financial Year: <strong><?= h($fy['name']) ?></strong> (<?= date('d M Y', strtotime($fyStart)) ?> - <?= date('d M Y', strtotime($fyEnd)) ?>)</span>
</div>

<!-- Row 1 - Quick Stats -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-blue">
            <div class="stat-icon stat-icon-blue"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="stat-value"><?= money($monthSales) ?></div>
                <div class="stat-label">Total Sales (This Month)</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-green">
            <div class="stat-icon stat-icon-green"><i class="fas fa-shopping-cart"></i></div>
            <div>
                <div class="stat-value"><?= money($monthPurchases) ?></div>
                <div class="stat-label">Total Purchases (This Month)</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-orange">
            <div class="stat-icon stat-icon-orange"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="stat-value"><?= money($cashInHand) ?></div>
                <div class="stat-label">Cash in Hand</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-red">
            <div class="stat-icon stat-icon-red"><i class="fas fa-hand-holding-usd"></i></div>
            <div>
                <div class="stat-value"><?= money($outstandingDues) ?></div>
                <div class="stat-label">Outstanding Dues</div>
            </div>
        </div>
    </div>
</div>

<!-- Row 2 - More Stats -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-purple">
            <div class="stat-icon stat-icon-purple"><i class="fas fa-boxes"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalStock) ?></div>
                <div class="stat-label">Items in Stock</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-pink">
            <div class="stat-icon stat-icon-pink"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value"><?= money($monthExpenses) ?></div>
                <div class="stat-label">Total Expenses (This Month)</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-teal">
            <div class="stat-icon stat-icon-teal"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value"><?= number_format($totalParties) ?></div>
                <div class="stat-label">Total Parties</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="stat-card stat-card-indigo">
            <div class="stat-icon stat-icon-indigo"><i class="fas fa-calendar-day"></i></div>
            <div>
                <div class="stat-value"><?= money($todaysSales) ?></div>
                <div class="stat-label">Today's Sales</div>
            </div>
        </div>
    </div>
</div>

<!-- Row 3 - Charts -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Sales vs Purchases (Last 6 Months)</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="salesPurchaseChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Expense Categories</span>
            </div>
            <div class="card-body">
                <?php if (!empty($expenseCatRows)): ?>
                    <div class="chart-container" style="height: 280px;">
                        <canvas id="expensePieChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-receipt d-block"></i>
                        <p>No expenses recorded this month</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Row 4 - Tables -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Sales</span>
                <a href="sales.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentSales)): ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Party</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td class="fw-bold"><?= sanitize($sale['invoice_no']) ?></td>
                                        <td><?= sanitize($sale['party_name']) ?></td>
                                        <td><?= dateFormatted($sale['date']) ?></td>
                                        <td class="text-end fw-bold"><?= money($sale['total']) ?></td>
                                        <td>
                                            <?php if ($sale['payment_status'] === 'paid'): ?>
                                                <span class="badge badge-success">Paid</span>
                                            <?php elseif ($sale['payment_status'] === 'partial'): ?>
                                                <span class="badge badge-warning">Partial</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-file-invoice-dollar d-block"></i>
                        <p>No sales recorded yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Low Stock Items</span>
                <a href="items.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($lowStockItems)): ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Item Name</th>
                                    <th class="text-center">Current Stock</th>
                                    <th class="text-center">Min Stock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td class="fw-bold"><?= sanitize($item['name']) ?></td>
                                        <td class="text-center"><?= $item['current_stock'] ?></td>
                                        <td class="text-center"><?= $item['min_stock'] ?></td>
                                        <td>
                                            <?php if ($item['current_stock'] <= 0): ?>
                                                <span class="badge badge-danger">Out of Stock</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Low Stock</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-boxes d-block"></i>
                        <p>All items are well stocked</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Row 5 - Recent Transactions -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span class="card-title">Recent Transactions</span>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentTransactions)): ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Party / Category</th>
                                    <th>Reference</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $txn): ?>
                                    <tr>
                                        <td><?= dateFormatted($txn['date']) ?></td>
                                        <td>
                                            <span class="txn-type-badge txn-<?= $txn['t_type'] ?>"><?= str_replace('_', ' ', $txn['t_type']) ?></span>
                                        </td>
                                        <td><?= sanitize($txn['party_name']) ?></td>
                                        <td class="fw-bold"><?= sanitize($txn['ref_no']) ?></td>
                                        <td class="text-capitalize"><?= $txn['payment_method'] ? str_replace('_', ' ', $txn['payment_method']) : '-' ?></td>
                                        <td class="text-end fw-bold"><?= money($txn['amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state py-4">
                        <i class="fas fa-exchange-alt d-block"></i>
                        <p>No transactions recorded yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var salesCtx = document.getElementById('salesPurchaseChart');
    if (salesCtx) {
        new Chart(salesCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartMonths) ?>,
                datasets: [
                    {
                        label: 'Sales',
                        data: <?= json_encode($chartSales) ?>,
                        backgroundColor: 'rgba(41, 98, 255, 0.75)',
                        borderColor: '#2962FF',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Purchases',
                        data: <?= json_encode($chartPurchases) ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.75)',
                        borderColor: '#28a745',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': \u20B9' + ctx.parsed.y.toLocaleString('en-IN', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '\u20B9' + value.toLocaleString('en-IN');
                            }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }

    <?php if (!empty($expenseCatRows)): ?>
    var pieCtx = document.getElementById('expensePieChart');
    if (pieCtx) {
        var expLabels = <?= json_encode(array_column($expenseCatRows, 'cat_name')) ?>;
        var expData = <?= json_encode(array_map('floatval', array_column($expenseCatRows, 'total'))) ?>;
        var pieColors = [
            '#2962FF', '#28a745', '#ff9800', '#dc3545', '#9c27b0',
            '#e91e63', '#009688', '#3f51b5', '#ff5722', '#607d8b',
            '#795548', '#00bcd4'
        ];
        new Chart(pieCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: expLabels,
                datasets: [{
                    data: expData,
                    backgroundColor: pieColors.slice(0, expLabels.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 15, usePointStyle: true, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': \u20B9' + ctx.parsed.toLocaleString('en-IN', {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

<?php include 'footer.php'; ?>
