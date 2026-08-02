<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'cashbook_data.php';
requireLogin();

$fy = currentFY();
$allowedMethods = ['all', 'cash', 'bank', 'upi', 'cheque'];

$from = $_GET['from_date'] ?? ($fy['start'] ?: date('Y-m-01'));
$to = $_GET['to_date'] ?? date('Y-m-d');
$method = $_GET['method'] ?? 'all';
if (!in_array($method, $allowedMethods, true)) $method = 'all';

$from = dateDB($from);
$to = dateDB($to);

$data = getCashbookData($from, $to, $method);
$rows = $data['rows'];

// Running balance
$runningBalance = $data['opening'];
foreach ($rows as &$r) {
    $runningBalance += ($r['dir'] === 'in' ? $r['amount'] : -$r['amount']);
    $r['running_balance'] = round($runningBalance, 2);
}
unset($r);

$modeLabel = $method === 'all' ? 'Cash & Bank' : cashModeLabel($method);

$pageTitle = 'Cash & Bank Book';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Cash & Bank Book <small class="text-muted">(<?= $modeLabel ?>)</small></h5>
    <div class="d-flex gap-2">
        <a href="pdf_cashbook.php?from_date=<?= urlencode($from) ?>&to_date=<?= urlencode($to) ?>&method=<?= urlencode($method) ?>" target="_blank" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?= sanitize($from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="<?= sanitize($to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Mode</label>
                <select name="method" class="form-select form-select-sm">
                    <option value="all" <?= $method === 'all' ? 'selected' : '' ?>>All Modes</option>
                    <option value="cash" <?= $method === 'cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="bank" <?= $method === 'bank' ? 'selected' : '' ?>>Bank</option>
                    <option value="upi" <?= $method === 'upi' ? 'selected' : '' ?>>UPI</option>
                    <option value="cheque" <?= $method === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="report_cashbook.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
            <div class="col-md-4 report-quick-btns">
                <span class="text-muted small me-1">Quick:</span>
                <a href="?from_date=<?= date('Y-m-d') ?>&to_date=<?= date('Y-m-d') ?>&method=<?= $method ?>" class="btn btn-outline-primary btn-sm">Today</a>
                <a href="?from_date=<?= date('Y-m-01') ?>&to_date=<?= date('Y-m-d') ?>&method=<?= $method ?>" class="btn btn-outline-primary btn-sm">This Month</a>
                <a href="?from_date=<?= $fy['start'] ?>&to_date=<?= date('Y-m-d') ?>&method=<?= $method ?>" class="btn btn-outline-primary btn-sm">This FY</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Opening Balance</small>
                <h5 class="mb-0 <?= $data['opening'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($data['opening']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total In</small>
                <h5 class="mb-0 text-success"><?= money($data['totalIn']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Out</small>
                <h5 class="mb-0 text-danger"><?= money($data['totalOut']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Closing Balance</small>
                <h5 class="mb-0 <?= $data['closing'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($data['closing']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block"><?= $method === 'cash' ? 'Cash Now' : ($method === 'bank' ? 'Bank Now' : 'Cash + Bank Now') ?></small>
                <h5 class="mb-0 text-primary"><?= money($method === 'cash' ? $data['cashNow'] : ($method === 'bank' ? $data['bankNow'] : $data['cashNow'] + $data['bankNow'])) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Table -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Cash &amp; Bank Book from <?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?> (<?= count($rows) ?> entries)</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:100px">Date</th>
                    <th style="width:110px">Ref / No</th>
                    <th>Description</th>
                    <th style="width:90px">Mode</th>
                    <th class="text-end">In</th>
                    <th class="text-end">Out</th>
                    <th class="text-end">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr class="table-light fw-bold">
                    <td>-</td>
                    <td>-</td>
                    <td>Opening Balance brought forward</td>
                    <td>-</td>
                    <td class="text-end">-</td>
                    <td class="text-end">-</td>
                    <td class="text-end <?= $data['opening'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($data['opening']) ?></td>
                </tr>

                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No transactions in the selected period.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><small class="text-muted"><?= date('d M Y', strtotime($r['date'])) ?></small></td>
                            <td><?= sanitize($r['ref']) ?></td>
                            <td><?= sanitize($r['desc']) ?></td>
                            <td><span class="badge bg-<?= $r['mode'] === 'cash' ? 'success' : ($r['mode'] === 'bank' ? 'primary' : 'secondary') ?>"><?= cashModeLabel($r['mode']) ?></span></td>
                            <td class="text-end text-success fw-bold"><?= $r['dir'] === 'in' ? money($r['amount']) : '-' ?></td>
                            <td class="text-end text-danger fw-bold"><?= $r['dir'] === 'out' ? money($r['amount']) : '-' ?></td>
                            <td class="text-end fw-bold <?= $r['running_balance'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($r['running_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Totals + Closing Row -->
                <tr class="table-light fw-bold">
                    <td colspan="4" class="text-end">Total</td>
                    <td class="text-end text-success"><?= money($data['totalIn']) ?></td>
                    <td class="text-end text-danger"><?= money($data['totalOut']) ?></td>
                    <td class="text-end"><?= money($data['opening'] + $data['totalIn'] - $data['totalOut']) ?></td>
                </tr>
                <tr class="table-dark fw-bold">
                    <td colspan="6" class="text-end text-white">Closing Balance</td>
                    <td class="text-end text-white"><?= money($data['closing']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
