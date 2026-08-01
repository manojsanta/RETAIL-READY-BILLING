<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// Date filter (single date, default today)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDate = dateDB($selectedDate);

$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Opening balance from settings
$openingBalance = (float) getSetting('cash_in_hand', '0');

// Gather all transactions for the day
$transactions = [];

// Sales (credit)
$salesRows = fetchAll("SELECT s.id, s.invoice_no, s.total, s.paid_amount, s.payment_method, s.payment_status,
    p.name as party_name, s.created_at
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id
    WHERE s.date = ? AND s.status != 'cancelled' ORDER BY s.created_at ASC", [$selectedDate]);
foreach ($salesRows as $sr) {
    $transactions[] = [
        'time' => $sr['created_at'],
        'type' => 'sale',
        'party' => $sr['party_name'] ?: 'Walk-in',
        'description' => 'Sale Invoice ' . $sr['invoice_no'],
        'debit' => 0,
        'credit' => (float) $sr['total'],
        'method' => $sr['payment_method'] ?: 'cash',
    ];
}

// Purchases
$purchaseRows = fetchAll("SELECT pu.id, pu.bill_no, pu.total, pu.paid_amount, pu.payment_method,
    p.name as party_name, pu.created_at
    FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
    WHERE pu.date = ? AND pu.status != 'cancelled' ORDER BY pu.created_at ASC", [$selectedDate]);
foreach ($purchaseRows as $pr) {
    $transactions[] = [
        'time' => $pr['created_at'],
        'type' => 'purchase',
        'party' => $pr['party_name'] ?: '-',
        'description' => 'Purchase Bill ' . $pr['bill_no'],
        'debit' => (float) $pr['total'],
        'credit' => 0,
        'method' => $pr['payment_method'] ?: 'cash',
    ];
}

// Payments In
$payInRows = fetchAll("SELECT pi.receipt_no, pi.amount, pi.payment_method, pi.reference_no,
    p.name as party_name, pi.created_at
    FROM payments_in pi LEFT JOIN parties p ON pi.party_id = p.id
    WHERE pi.date = ? ORDER BY pi.created_at ASC", [$selectedDate]);
foreach ($payInRows as $pi) {
    $transactions[] = [
        'time' => $pi['created_at'],
        'type' => 'payment_in',
        'party' => $pi['party_name'] ?: '-',
        'description' => 'Payment In ' . $pi['receipt_no'] . ($pi['reference_no'] ? ' (Ref: ' . $pi['reference_no'] . ')' : ''),
        'debit' => 0,
        'credit' => (float) $pi['amount'],
        'method' => $pi['payment_method'],
    ];
}

// Payments Out
$payOutRows = fetchAll("SELECT po.payment_no, po.amount, po.payment_method, po.reference_no,
    p.name as party_name, po.created_at
    FROM payments_out po LEFT JOIN parties p ON po.party_id = p.id
    WHERE po.date = ? ORDER BY po.created_at ASC", [$selectedDate]);
foreach ($payOutRows as $po) {
    $transactions[] = [
        'time' => $po['created_at'],
        'type' => 'payment_out',
        'party' => $po['party_name'] ?: '-',
        'description' => 'Payment Out ' . $po['payment_no'] . ($po['reference_no'] ? ' (Ref: ' . $po['reference_no'] . ')' : ''),
        'debit' => (float) $po['amount'],
        'credit' => 0,
        'method' => $po['payment_method'],
    ];
}

// Expenses
$expenseRows = fetchAll("SELECT e.expense_no, e.amount, e.payment_method, e.reference_no, e.notes,
    ec.name as category_name, e.created_at
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.date = ? ORDER BY e.created_at ASC", [$selectedDate]);
foreach ($expenseRows as $er) {
    $transactions[] = [
        'time' => $er['created_at'],
        'type' => 'expense',
        'party' => $er['category_name'] ?: 'Uncategorized',
        'description' => 'Expense ' . $er['expense_no'] . ($er['notes'] ? ' - ' . mb_strimwidth($er['notes'], 0, 30, '...') : ''),
        'debit' => (float) $er['amount'],
        'credit' => 0,
        'method' => $er['payment_method'],
    ];
}

// Sale Returns
$returnRows = fetchAll("SELECT sr.return_no, sr.total,
    p.name as party_name, sr.created_at
    FROM sale_returns sr LEFT JOIN parties p ON sr.party_id = p.id
    WHERE sr.date = ? AND sr.status = 'approved' ORDER BY sr.created_at ASC", [$selectedDate]);
foreach ($returnRows as $rr) {
    $transactions[] = [
        'time' => $rr['created_at'],
        'type' => 'sale_return',
        'party' => $rr['party_name'] ?: '-',
        'description' => 'Sale Return ' . $rr['return_no'],
        'debit' => (float) $rr['total'],
        'credit' => 0,
        'method' => '-',
    ];
}

// Purchase Returns
$purchaseReturnRows = fetchAll("SELECT pr.return_no, pr.total,
    p.name as party_name, pr.created_at
    FROM purchase_returns pr LEFT JOIN parties p ON pr.party_id = p.id
    WHERE pr.date = ? AND pr.status = 'approved' ORDER BY pr.created_at ASC", [$selectedDate]);
foreach ($purchaseReturnRows as $prr) {
    $transactions[] = [
        'time' => $prr['created_at'],
        'type' => 'purchase_return',
        'party' => $prr['party_name'] ?: '-',
        'description' => 'Purchase Return ' . $prr['return_no'],
        'debit' => 0,
        'credit' => (float) $prr['total'],
        'method' => '-',
    ];
}

// Sort by time
usort($transactions, function($a, $b) {
    return strtotime($a['time']) <=> strtotime($b['time']);
});

// Compute running balance
$runningBalance = $openingBalance;
foreach ($transactions as &$t) {
    $runningBalance = $runningBalance + $t['credit'] - $t['debit'];
    $t['running_balance'] = round($runningBalance, 2);
}
unset($t);

$closingBalance = $runningBalance;
$totalDebits = array_sum(array_column($transactions, 'debit'));
$totalCredits = array_sum(array_column($transactions, 'credit'));

// Badge colors
$badgeColors = [
    'sale' => 'primary',
    'purchase' => 'warning',
    'payment_in' => 'success',
    'payment_out' => 'danger',
    'expense' => 'secondary',
    'sale_return' => 'info',
    'purchase_return' => 'info',
];

$pageTitle = 'Day Book';
include 'header.php';
?>

<style>
.daybook-nav .btn { font-size: 0.85rem; }
.running-balance-positive { color: #28a745; }
.running-balance-negative { color: #dc3545; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Day Book</h5>
    <div class="d-flex gap-2">
        <a href="pdf_daybook.php?date=<?= urlencode($selectedDate) ?>" target="_blank" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Date Navigation -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-center align-items-center gap-3">
            <a href="?date=<?= $prevDate ?>" class="btn btn-outline-primary btn-sm daybook-nav"><i class="fas fa-chevron-left me-1"></i>Previous Day</a>
            <form method="GET" class="d-inline-flex align-items-center gap-2">
                <input type="date" name="date" class="form-control form-control-sm" value="<?= sanitize($selectedDate) ?>" style="width: 180px;">
                <button type="submit" class="btn btn-primary btn-sm">Go</button>
            </form>
            <a href="?date=<?= $nextDate ?>" class="btn btn-outline-primary btn-sm daybook-nav">Next Day<i class="fas fa-chevron-right ms-1"></i></a>
            <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">Today</a>
        </div>
    </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Opening Balance</small>
                <h5 class="mb-0 <?= $openingBalance >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($openingBalance) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Debits</small>
                <h5 class="mb-0 text-danger"><?= money($totalDebits) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Credits</small>
                <h5 class="mb-0 text-success"><?= money($totalCredits) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Closing Balance</small>
                <h5 class="mb-0 <?= $closingBalance >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($closingBalance) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Table -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Transactions for <?= date('d M Y', strtotime($selectedDate)) ?> (<?= count($transactions) ?> entries)</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:80px">Time</th>
                    <th style="width:120px">Type</th>
                    <th>Party / Category</th>
                    <th>Description</th>
                    <th>Method</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                    <th class="text-end">Running Balance</th>
                </tr>
            </thead>
            <tbody>
                <!-- Opening Balance Row -->
                <tr class="table-light fw-bold">
                    <td>-</td>
                    <td><span class="badge bg-dark">Opening</span></td>
                    <td>-</td>
                    <td>Opening Balance brought forward</td>
                    <td>-</td>
                    <td class="text-end">-</td>
                    <td class="text-end">-</td>
                    <td class="text-end <?= $openingBalance >= 0 ? 'running-balance-positive' : 'running-balance-negative' ?>"><?= money($openingBalance) ?></td>
                </tr>

                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No transactions for this date.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><small class="text-muted"><?= date('h:i A', strtotime($t['time'])) ?></small></td>
                            <td><span class="badge bg-<?= $badgeColors[$t['type']] ?? 'secondary' ?>"><?= ucwords(str_replace('_', ' ', $t['type'])) ?></span></td>
                            <td><?= sanitize($t['party']) ?></td>
                            <td><?= sanitize($t['description']) ?></td>
                            <td><small class="text-muted"><?= ucfirst(sanitize($t['method'])) ?></small></td>
                            <td class="text-end text-danger fw-bold"><?= $t['debit'] > 0 ? money($t['debit']) : '-' ?></td>
                            <td class="text-end text-success fw-bold"><?= $t['credit'] > 0 ? money($t['credit']) : '-' ?></td>
                            <td class="text-end fw-bold <?= $t['running_balance'] >= 0 ? 'running-balance-positive' : 'running-balance-negative' ?>"><?= money($t['running_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Closing Balance Row -->
                <tr class="table-dark fw-bold">
                    <td colspan="7" class="text-end text-white">Closing Balance</td>
                    <td class="text-end text-white"><?= money($closingBalance) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Net Change Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Net Change</small>
                <h5 class="mb-0 <?= ($totalCredits - $totalDebits) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($totalCredits - $totalDebits) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Transactions</small>
                <h5 class="mb-0"><?= count($transactions) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Opening to Closing</small>
                <h5 class="mb-0 <?= $closingBalance >= $openingBalance ? 'text-success' : 'text-danger' ?>">
                    <?= $closingBalance >= $openingBalance ? '↑' : '↓' ?> <?= money(abs($closingBalance - $openingBalance)) ?>
                </h5>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
