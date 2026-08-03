<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$fy = currentFY();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $asOf = dateDB($_GET['as_of'] ?? today());
    $t = computeTrialBalance($asOf);

    $csvData = [];
    foreach ($t['rows'] as $r) {
        $csvData[] = [$r['label'], $r['debit'] > 0 ? $r['debit'] : '', $r['credit'] > 0 ? $r['credit'] : ''];
    }
    $csvData[] = ['Total', $t['totalDebit'], $t['totalCredit']];
    exportCSV(['Particulars', 'Debit', 'Credit'], $csvData, 'trial_balance_report');
}

function computeTrialBalance($asOf) {
    $fy = currentFY();
    $df = $fy['start'];
    $dt = $asOf;

    // Assets (Debit)
    $cashInHand = (float) getSetting('cash_balance', '0');
    $bankTotal = (float) (fetch("SELECT COALESCE(SUM(current_balance),0) as t FROM bank_accounts WHERE status = 1")['t'] ?? 0);
    $stockValue = (float) (fetch("SELECT COALESCE(SUM(current_stock * purchase_price),0) as t FROM items WHERE status = 1")['t'] ?? 0);

    // Party balances
    $debtorsTotal = 0;
    $creditorsTotal = 0;
    $parties = fetchAll("SELECT id FROM parties WHERE status = 1");
    foreach ($parties as $p) {
        $bal = getPartyBalance($p['id']);
        if ($bal > 0) $debtorsTotal += $bal;
        else $creditorsTotal += abs($bal);
    }
    $debtorsTotal = round($debtorsTotal, 2);
    $creditorsTotal = round($creditorsTotal, 2);

    // Income / Expense (FY start to as-of date)
    $salesTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $salesReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sale_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $salesDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchase_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $purchaseDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $expensesTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);
    $otherIncomeTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM other_income WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);

    $rows = [];

    // Debit side
    $addDr = function ($label, $amount) use (&$rows) {
        $rows[] = ['label' => $label, 'debit' => round($amount, 2), 'credit' => 0];
    };
    // Credit side
    $addCr = function ($label, $amount) use (&$rows) {
        $rows[] = ['label' => $label, 'debit' => 0, 'credit' => round($amount, 2)];
    };

    $addDr('Cash in Hand', $cashInHand);
    $addDr('Bank Balances', $bankTotal);
    $addDr('Sundry Debtors (To Receive)', $debtorsTotal);
    $addDr('Inventory (Stock at Cost)', $stockValue);
    $addDr('Purchases', $purchaseTotal);
    $addDr('Operating Expenses', $expensesTotal);
    $addDr('Sales Returns', $salesReturns);
    $addDr('Sales Discount', $salesDiscount);

    $addCr('Sundry Creditors (To Pay)', $creditorsTotal);
    $addCr('Sales', $salesTotal);
    $addCr('Purchase Returns', $purchaseReturns);
    $addCr('Purchase Discount', $purchaseDiscount);
    $addCr('Other Income', $otherIncomeTotal);

    // Capital is the balancing figure so Debit = Credit
    $drBase = array_sum(array_column($rows, 'debit'));
    $crBase = array_sum(array_column($rows, 'credit'));
    $capital = round($drBase - $crBase, 2);
    if ($capital >= 0) {
        $addCr('Opening Capital (balancing figure)', $capital);
    } else {
        $addDr('Capital Deficit (balancing figure)', abs($capital));
    }

    $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
    $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

    $netProfit = round(($salesTotal - $salesReturns - $salesDiscount + $otherIncomeTotal) - ($purchaseTotal - $purchaseReturns - $purchaseDiscount) - $expensesTotal, 2);

    return [
        'asOf' => $asOf,
        'rows' => $rows,
        'totalDebit' => $totalDebit,
        'totalCredit' => $totalCredit,
        'netProfit' => $netProfit,
        'capital' => $capital,
    ];
}

$asOf = dateDB($_GET['as_of'] ?? today());
$t = computeTrialBalance($asOf);

$pageTitle = 'Trial Balance';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Trial Balance <small class="text-muted">(as at <?= dateFormatted($t['asOf']) ?>)</small></h5>
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
                <label class="form-label small">As on Date</label>
                <input type="date" name="as_of" class="form-control form-control-sm" value="<?= sanitize($asOf) ?>">
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Apply</button>
                <a href="report_trial_balance.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
            <div class="col-md-6 report-quick-btns">
                <span class="text-muted small me-1">Quick:</span>
                <a href="?as_of=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">Today</a>
                <a href="?as_of=<?= date('Y-m-t') ?>" class="btn btn-outline-primary btn-sm">Month End</a>
                <a href="?as_of=<?= $fy['end'] ?>" class="btn btn-outline-primary btn-sm">FY End</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Debits</small>
                <h5 class="mb-0 text-primary"><?= money($t['totalDebit']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Credits</small>
                <h5 class="mb-0 text-primary"><?= money($t['totalCredit']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Difference</small>
                <h5 class="mb-0 <?= ($t['totalDebit'] - $t['totalCredit']) == 0 ? 'text-success' : 'text-danger' ?>"><?= money($t['totalDebit'] - $t['totalCredit']) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Trial Balance Table -->
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Trial Balance for <?= $fy['name'] ?> (<?= dateFormatted($fy['start']) ?> to <?= dateFormatted($t['asOf']) ?>)</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:55px">#</th>
                    <th>Particulars</th>
                    <th class="text-end" style="width:180px">Debit</th>
                    <th class="text-end" style="width:180px">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($t['rows'] as $i => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><?= sanitize($r['label']) ?></td>
                        <td class="text-end"><?= $r['debit'] > 0 ? money($r['debit']) : '-' ?></td>
                        <td class="text-end"><?= $r['credit'] > 0 ? money($r['credit']) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="2" class="text-end">Total</td>
                    <td class="text-end text-primary"><?= money($t['totalDebit']) ?></td>
                    <td class="text-end text-primary"><?= money($t['totalCredit']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="alert alert-info py-2 small mb-4">
    <i class="fas fa-info-circle me-1"></i> Net Profit for the period: <strong class="<?= $t['netProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($t['netProfit']) ?></strong> (Sales/Other Income minus Purchases/Expenses for <?= $fy['name'] ?> to the report date). Opening Capital is the balancing figure so Debits always equal Credits.
</div>

<?php include 'footer.php'; ?>
