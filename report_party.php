<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $partyType = $_GET['party_type'] ?? 'all';
    $dateFrom = $_GET['from_date'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';

    $where = ["p.status = 1"];
    $params = [];
    if (in_array($partyType, ['customer', 'supplier'])) {
        $where[] = "p.type = ?";
        $params[] = $partyType;
    }

    $parties = fetchAll("SELECT p.*,
        COALESCE((SELECT SUM(total) FROM sales WHERE party_id = p.id AND status != 'cancelled'" .
        ($dateFrom !== '' ? " AND date >= ?" : "") .
        ($dateTo !== '' ? " AND date <= ?" : "") . "), 0) as total_sales,
        COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = p.id AND status != 'cancelled'" .
        ($dateFrom !== '' ? " AND date >= ?" : "") .
        ($dateTo !== '' ? " AND date <= ?" : "") . "), 0) as total_purchases,
        COALESCE((SELECT SUM(amount) FROM payments_in WHERE party_id = p.id" .
        ($dateFrom !== '' ? " AND date >= ?" : "") .
        ($dateTo !== '' ? " AND date <= ?" : "") . "), 0) as total_payments_in,
        COALESCE((SELECT SUM(amount) FROM payments_out WHERE party_id = p.id" .
        ($dateFrom !== '' ? " AND date >= ?" : "") .
        ($dateTo !== '' ? " AND date <= ?" : "") . "), 0) as total_payments_out
        FROM parties p WHERE " . implode(' AND ', $where) . " ORDER BY p.name ASC", $params);

    $csvData = [];
    foreach ($parties as $pr) {
        $balance = getPartyBalance($pr['id']);
        $csvData[] = [$pr['name'], $pr['phone'], ucfirst($pr['type']), $pr['total_sales'], $pr['total_purchases'], $pr['total_payments_in'], $pr['total_payments_out'], $pr['opening_balance'], $balance];
    }
    exportCSV(['Party Name','Phone','Type','Total Sales','Total Purchases','Payments In','Payments Out','Opening Balance','Current Balance'], $csvData, 'party_report');
}

// Filters
$partyType = $_GET['party_type'] ?? 'all';
$dateFrom = $_GET['from_date'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$where = ["p.status = 1"];
$params = [];
if (in_array($partyType, ['customer', 'supplier'])) {
    $where[] = "p.type = ?";
    $params[] = $partyType;
}
$whereSql = implode(' AND ', $where);

$parties = fetchAll("SELECT p.*,
    COALESCE((SELECT SUM(total) FROM sales WHERE party_id = p.id AND status != 'cancelled'" .
    ($dateFrom !== '' ? " AND date >= '" . dateDB($dateFrom) . "'" : "") .
    ($dateTo !== '' ? " AND date <= '" . dateDB($dateTo) . "'" : "") . "), 0) as total_sales,
    COALESCE((SELECT SUM(total) FROM purchases WHERE party_id = p.id AND status != 'cancelled'" .
    ($dateFrom !== '' ? " AND date >= '" . dateDB($dateFrom) . "'" : "") .
    ($dateTo !== '' ? " AND date <= '" . dateDB($dateTo) . "'" : "") . "), 0) as total_purchases,
    COALESCE((SELECT SUM(amount) FROM payments_in WHERE party_id = p.id" .
    ($dateFrom !== '' ? " AND date >= '" . dateDB($dateFrom) . "'" : "") .
    ($dateTo !== '' ? " AND date <= '" . dateDB($dateTo) . "'" : "") . "), 0) as total_payments_in,
    COALESCE((SELECT SUM(amount) FROM payments_out WHERE party_id = p.id" .
    ($dateFrom !== '' ? " AND date >= '" . dateDB($dateFrom) . "'" : "") .
    ($dateTo !== '' ? " AND date <= '" . dateDB($dateTo) . "'" : "") . "), 0) as total_payments_out
    FROM parties p WHERE $whereSql ORDER BY p.name ASC", $params);

// Compute balances
$partyBalances = [];
$totalReceivable = 0;
$totalPayable = 0;
foreach ($parties as &$pr) {
    $pr['current_balance'] = getPartyBalance($pr['id']);
    if ($pr['current_balance'] > 0) {
        $totalReceivable += $pr['current_balance'];
    } else {
        $totalPayable += abs($pr['current_balance']);
    }
}
unset($pr);

// Sort by balance descending
usort($parties, function($a, $b) {
    return abs($b['current_balance']) <=> abs($a['current_balance']);
});

$pageTitle = 'Party Report';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.party-table { font-size: 0.8rem; }
.party-table th, .party-table td { padding: 0.3rem 0.5rem; white-space: nowrap; }
.party-table thead th { font-size: 0.72rem; }
.party-table .party-name { max-width: 220px; white-space: normal; }
.party-table .party-sub { font-size: 0.7rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Party Report</h5>
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
                <small class="text-muted d-block">Total To Receive</small>
                <h4 class="mb-0 text-success"><?= money($totalReceivable) ?></h4>
                <small class="text-muted"><?= count(array_filter($parties, function($p) { return $p['current_balance'] > 0; })) ?> parties</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total To Pay</small>
                <h4 class="mb-0 text-danger"><?= money($totalPayable) ?></h4>
                <small class="text-muted"><?= count(array_filter($parties, function($p) { return $p['current_balance'] < 0; })) ?> parties</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Net Balance</small>
                <h4 class="mb-0 <?= ($totalReceivable - $totalPayable) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalReceivable - $totalPayable) ?></h4>
                <small class="text-muted"><?= count($parties) ?> total parties</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Party Type</label>
                <select name="party_type" class="form-select form-select-sm">
                    <option value="all" <?= $partyType === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="customer" <?= $partyType === 'customer' ? 'selected' : '' ?>>Customers</option>
                    <option value="supplier" <?= $partyType === 'supplier' ? 'selected' : '' ?>>Suppliers</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="report_party.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 party-table">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Party Name</th>
                    <th>Phone</th>
                    <th class="text-end">Total Sales</th>
                    <th class="text-end">Total Purchases</th>
                    <th class="text-end">Payments In</th>
                    <th class="text-end">Payments Out</th>
                    <th class="text-end">Opening Balance</th>
                    <th class="text-end">Current Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($parties)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No parties found.</td></tr>
                <?php else: ?>
                    <?php foreach ($parties as $idx => $pr): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <a href="party_view.php?id=<?= $pr['id'] ?>" class="fw-semibold text-decoration-none party-name"><?= sanitize($pr['name']) ?></a>
                                <br><small class="text-muted party-sub"><?= ucfirst(sanitize($pr['type'])) ?></small>
                            </td>
                            <td><?= sanitize($pr['phone'] ?: '-') ?></td>
                            <td class="text-end"><?= money($pr['total_sales']) ?></td>
                            <td class="text-end"><?= money($pr['total_purchases']) ?></td>
                            <td class="text-end text-success"><?= money($pr['total_payments_in']) ?></td>
                            <td class="text-end text-danger"><?= money($pr['total_payments_out']) ?></td>
                            <td class="text-end"><?= money($pr['opening_balance']) ?>
                                <small class="text-muted"><?= ucfirst($pr['balance_type']) ?></small>
                            </td>
                            <td class="text-end fw-bold <?= $pr['current_balance'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= money(abs($pr['current_balance'])) ?>
                                <small><?= $pr['current_balance'] > 0 ? 'To Receive' : ($pr['current_balance'] < 0 ? 'To Pay' : '') ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($parties)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Totals</td>
                    <td class="text-end"><?= money(array_sum(array_column($parties, 'total_sales'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($parties, 'total_purchases'))) ?></td>
                    <td class="text-end text-success"><?= money(array_sum(array_column($parties, 'total_payments_in'))) ?></td>
                    <td class="text-end text-danger"><?= money(array_sum(array_column($parties, 'total_payments_out'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($parties, 'opening_balance'))) ?></td>
                    <td class="text-end"><?= money($totalReceivable - $totalPayable) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
