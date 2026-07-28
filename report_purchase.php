<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();
$loadChartjs = true;

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dateFrom = $_GET['from_date'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $partyId = intval($_GET['party_id'] ?? 0);
    $paymentStatus = $_GET['payment_status'] ?? '';

    $where = [];
    $params = [];
    if ($dateFrom !== '') { $where[] = "pu.date >= ?"; $params[] = dateDB($dateFrom); }
    if ($dateTo !== '') { $where[] = "pu.date <= ?"; $params[] = dateDB($dateTo); }
    if ($partyId > 0) { $where[] = "pu.party_id = ?"; $params[] = $partyId; }
    if (in_array($paymentStatus, ['paid','unpaid','partial'])) { $where[] = "pu.payment_status = ?"; $params[] = $paymentStatus; }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = fetchAll("SELECT pu.date, pu.bill_no, p.name as party_name,
        (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = pu.id) as qty,
        pu.subtotal, pu.tax_amount, pu.discount_amount, pu.total, pu.paid_amount, pu.due_amount, pu.payment_status
        FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id $whereSql ORDER BY pu.date ASC", $params);

    $csvData = [];
    foreach ($rows as $r) {
        $csvData[] = [$r['date'], $r['bill_no'], $r['party_name'] ?: '-', $r['qty'], $r['subtotal'], $r['tax_amount'], $r['discount_amount'], $r['total'], $r['paid_amount'], $r['due_amount'], ucfirst($r['payment_status'])];
    }
    exportCSV(['Date','Bill No','Supplier','Qty','Subtotal','Tax','Discount','Total','Paid','Due','Status'], $csvData, 'purchase_report');
}

// Filters
$dateFrom = $_GET['from_date'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$partyId = intval($_GET['party_id'] ?? 0);
$paymentStatus = $_GET['payment_status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;

$where = [];
$params = [];
if ($dateFrom !== '') { $where[] = "pu.date >= ?"; $params[] = dateDB($dateFrom); }
if ($dateTo !== '') { $where[] = "pu.date <= ?"; $params[] = dateDB($dateTo); }
if ($partyId > 0) { $where[] = "pu.party_id = ?"; $params[] = $partyId; }
if (in_array($paymentStatus, ['paid','unpaid','partial'])) { $where[] = "pu.payment_status = ?"; $params[] = $paymentStatus; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Summary
$summary = fetch("SELECT
    COALESCE(SUM(pu.total), 0) as total_purchases,
    COALESCE(SUM(pu.tax_amount), 0) as total_tax,
    COALESCE(SUM(pu.paid_amount), 0) as total_paid,
    COALESCE(SUM(pu.due_amount), 0) as total_payable
    FROM purchases pu $whereSql", $params);

$total = dbCount("SELECT COUNT(*) FROM purchases pu $whereSql", $params);
$pagination = paginate($total, $perPage, $page);

$purchases = fetchAll("SELECT pu.*, p.name as party_name,
    (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = pu.id) as items_count
    FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
    $whereSql ORDER BY pu.date DESC, pu.id DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params);

// Chart data
$chartData = fetchAll("SELECT pu.date, SUM(pu.total) as daily_total
    FROM purchases pu $whereSql GROUP BY pu.date ORDER BY pu.date ASC", $params);
$chartLabels = array_map(function($d) { return date('d M', strtotime($d['date'])); }, $chartData);
$chartValues = array_map(function($d) { return (float)$d['daily_total']; }, $chartData);

$suppliers = fetchAll("SELECT id, name FROM parties WHERE type IN ('supplier','both') AND status = 1 ORDER BY name ASC");

$pageTitle = 'Purchase Report';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.chart-container { position: relative; height: 300px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Purchase Report</h5>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Purchases</small>
                <h5 class="mb-0 text-primary"><?= money($summary['total_purchases']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Tax</small>
                <h5 class="mb-0 text-warning"><?= money($summary['total_tax']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Paid</small>
                <h5 class="mb-0 text-success"><?= money($summary['total_paid']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Payable</small>
                <h5 class="mb-0 text-danger"><?= money($summary['total_payable']) ?></h5>
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
                <label class="form-label small">Supplier</label>
                <select name="party_id" class="form-select form-select-sm">
                    <option value="0">All Suppliers</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $partyId == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Payment Status</label>
                <select name="payment_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="paid" <?= $paymentStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $paymentStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="unpaid" <?= $paymentStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="report_purchase.php" class="btn btn-outline-secondary btn-sm">Reset</a>
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

<!-- Chart -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Purchase Trend</h6></div>
    <div class="card-body">
        <div class="chart-container">
            <canvas id="purchaseTrendChart"></canvas>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Bill No</th>
                    <th>Supplier</th>
                    <th class="text-center">Items</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">Tax</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No purchases found for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $pu): ?>
                        <tr>
                            <td><?= dateFormatted($pu['date']) ?></td>
                            <td><a href="purchase_view.php?id=<?= $pu['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($pu['bill_no']) ?></a></td>
                            <td><?= sanitize($pu['party_name'] ?? '-') ?></td>
                            <td class="text-center"><?= intval($pu['items_count']) ?></td>
                            <td class="text-end"><?= money($pu['subtotal']) ?></td>
                            <td class="text-end"><?= money($pu['tax_amount']) ?></td>
                            <td class="text-end"><?= money($pu['discount_amount']) ?></td>
                            <td class="text-end fw-bold"><?= money($pu['total']) ?></td>
                            <td class="text-end text-success"><?= money($pu['paid_amount']) ?></td>
                            <td class="text-end text-danger"><?= money($pu['due_amount']) ?></td>
                            <td>
                                <?php if ($pu['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">Paid</span>
                                <?php elseif ($pu['payment_status'] === 'partial'): ?>
                                    <span class="badge bg-warning text-dark">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Unpaid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($purchases)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4">Totals</td>
                    <td class="text-end"><?= money($summary['total_purchases'] - $summary['total_tax']) ?></td>
                    <td class="text-end"><?= money($summary['total_tax']) ?></td>
                    <td class="text-end"></td>
                    <td class="text-end"><?= money($summary['total_purchases']) ?></td>
                    <td class="text-end text-success"><?= money($summary['total_paid']) ?></td>
                    <td class="text-end text-danger"><?= money($summary['total_payable']) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
    <nav class="mt-3 mb-4">
        <?php
        $baseUrl = 'report_purchase.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctx = document.getElementById('purchaseTrendChart');
    if (ctx) {
        new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Purchases',
                    data: <?= json_encode($chartValues) ?>,
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
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
});
</script>

<?php include 'footer.php'; ?>
