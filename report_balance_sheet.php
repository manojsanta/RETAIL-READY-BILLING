<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$fy = currentFY();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $asOf = dateDB($_GET['as_of'] ?? today());

    $data = computeBalanceSheet($asOf);

    $csvData = [
        ['BALANCE SHEET AS AT ' . $fy['name']],
        ['Assets'],
        ['Cash in Hand', $data['cashInHand']],
        ['Bank Balances', $data['bankTotal']],
        ['Sundry Debtors (To Receive)', $data['debtorsTotal']],
        ['Inventory (Stock at Cost)', $data['stockValue']],
        ['Total Assets', $data['totalAssets']],
        [''],
        ['Liabilities'],
        ['Sundry Creditors (To Pay)', $data['creditorsTotal']],
        ['Total Liabilities', $data['totalLiabilities']],
        [''],
        ['Capital & Reserves'],
        ['Opening Capital (balancing figure)', $data['capital']],
        ['Sales (Gross)', $data['salesTotal']],
        ['Less: Sales Returns', -$data['salesReturns']],
        ['Less: Sales Discount', -$data['salesDiscount']],
        ['Other Income', $data['otherIncomeTotal']],
        ['Less: Purchases', -$data['purchaseTotal']],
        ['Add: Purchase Returns', $data['purchaseReturns']],
        ['Add: Purchase Discount', $data['purchaseDiscount']],
        ['Less: Expenses', -$data['expensesTotal']],
        ['Net Profit for the period', $data['netProfit']],
        ['Net Worth', $data['netWorth']],
        ['Total Liabilities & Capital', $data['totalLiabilities'] + $data['netWorth']],
    ];
    exportCSV(['Particulars', 'Amount'], $csvData, 'balance_sheet_report');
}

function computeBalanceSheet($asOf) {
    $fy = currentFY();
    $df = $fy['start'];
    $dt = $asOf;

    // Assets
    $cashInHand = (float) getSetting('cash_balance', '0');
    $bankTotal = (float) (fetch("SELECT COALESCE(SUM(current_balance),0) as t FROM bank_accounts WHERE status = 1")['t'] ?? 0);

    // Stock at cost
    $stockValue = (float) (fetch("SELECT COALESCE(SUM(current_stock * purchase_price),0) as t FROM items WHERE status = 1")['t'] ?? 0);

    // Party balances (net of opening)
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

    $totalAssets = round($cashInHand + $bankTotal + $debtorsTotal + $stockValue, 2);
    $totalLiabilities = $creditorsTotal;

    // Profit & Loss (FY start to as-of date)
    $salesTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $salesReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sale_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $salesDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchase_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $purchaseDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $expensesTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);
    $otherIncomeTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM other_income WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);

    $netSales = $salesTotal - $salesReturns - $salesDiscount;
    $cogs = $purchaseTotal - $purchaseReturns - $purchaseDiscount;
    $netProfit = round(($netSales + $otherIncomeTotal) - $cogs - $expensesTotal, 2);

    // Capital is the balancing figure so the sheet always balances
    $capital = round($totalAssets - $totalLiabilities - $netProfit, 2);
    $netWorth = round($capital + $netProfit, 2);

    return [
        'asOf' => $asOf,
        'cashInHand' => $cashInHand,
        'bankTotal' => $bankTotal,
        'debtorsTotal' => $debtorsTotal,
        'creditorsTotal' => $creditorsTotal,
        'stockValue' => $stockValue,
        'totalAssets' => $totalAssets,
        'totalLiabilities' => $totalLiabilities,
        'netProfit' => $netProfit,
        'capital' => $capital,
        'netWorth' => $netWorth,
        'salesTotal' => $salesTotal,
        'salesReturns' => $salesReturns,
        'salesDiscount' => $salesDiscount,
        'otherIncomeTotal' => $otherIncomeTotal,
        'purchaseTotal' => $purchaseTotal,
        'purchaseReturns' => $purchaseReturns,
        'purchaseDiscount' => $purchaseDiscount,
        'expensesTotal' => $expensesTotal,
    ];
}

$asOf = dateDB($_GET['as_of'] ?? today());
$d = computeBalanceSheet($asOf);

$pageTitle = 'Balance Sheet';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.bs-row { display: flex; justify-content: space-between; padding: 0.35rem 0; border-bottom: 1px dotted #eee; }
.bs-row.indent { padding-left: 1rem; }
.bs-row.subtotal { font-weight: 600; border-bottom: 2px solid #333; padding-top: 0.5rem; }
.bs-row.final { font-size: 1.05rem; font-weight: 700; padding: 0.6rem 0; border-bottom: 3px double #333; }
.bs-side { border-left: 4px solid; padding-left: 1rem; }
.bs-side.assets { border-color: #28a745; }
.bs-side.liab { border-color: #dc3545; }
.bs-side.equity { border-color: #007bff; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Balance Sheet <small class="text-muted">(as at <?= dateFormatted($d['asOf']) ?>)</small></h5>
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
                <a href="report_balance_sheet.php" class="btn btn-outline-secondary btn-sm">Reset</a>
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
                <small class="text-muted d-block">Total Assets</small>
                <h5 class="mb-0 text-success"><?= money($d['totalAssets']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Liabilities</small>
                <h5 class="mb-0 text-danger"><?= money($d['totalLiabilities']) ?></h5>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Net Worth</small>
                <h5 class="mb-0 <?= $d['netWorth'] >= 0 ? 'text-primary' : 'text-danger' ?>"><?= money($d['netWorth']) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- Statement -->
<div class="row">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-box me-1"></i> Assets</h6></div>
            <div class="card-body">
                <div class="bs-side assets">
                    <h6 class="text-success mb-3">Current Assets</h6>
                    <div class="bs-row"><span>Cash in Hand</span><span><?= money($d['cashInHand']) ?></span></div>
                    <div class="bs-row"><span>Bank Balances</span><span><?= money($d['bankTotal']) ?></span></div>
                    <div class="bs-row"><span>Sundry Debtors (To Receive)</span><span><?= money($d['debtorsTotal']) ?></span></div>
                    <div class="bs-row"><span>Inventory (Stock at Cost)</span><span><?= money($d['stockValue']) ?></span></div>
                    <div class="bs-row subtotal"><span>Total Assets</span><span><?= money($d['totalAssets']) ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-balance-scale me-1"></i> Liabilities &amp; Capital</h6></div>
            <div class="card-body">
                <div class="bs-side liab">
                    <h6 class="text-danger mb-3">Liabilities</h6>
                    <div class="bs-row"><span>Sundry Creditors (To Pay)</span><span><?= money($d['creditorsTotal']) ?></span></div>
                    <div class="bs-row subtotal"><span>Total Liabilities</span><span><?= money($d['totalLiabilities']) ?></span></div>
                </div>

                <div class="bs-side equity mt-4">
                    <h6 class="text-primary mb-3">Capital &amp; Reserves</h6>
                    <div class="bs-row"><span>Opening Capital (balancing figure)</span><span><?= money($d['capital']) ?></span></div>
                    <div class="bs-row"><span>Sales (Gross)</span><span><?= money($d['salesTotal']) ?></span></div>
                    <div class="bs-row"><span>Less: Sales Returns</span><span class="text-danger">- <?= money($d['salesReturns']) ?></span></div>
                    <div class="bs-row"><span>Less: Sales Discount</span><span class="text-danger">- <?= money($d['salesDiscount']) ?></span></div>
                    <div class="bs-row"><span>Other Income</span><span><?= money($d['otherIncomeTotal']) ?></span></div>
                    <div class="bs-row"><span>Less: Purchases</span><span class="text-danger">- <?= money($d['purchaseTotal']) ?></span></div>
                    <div class="bs-row"><span>Add: Purchase Returns</span><span><?= money($d['purchaseReturns']) ?></span></div>
                    <div class="bs-row"><span>Add: Purchase Discount</span><span><?= money($d['purchaseDiscount']) ?></span></div>
                    <div class="bs-row"><span>Less: Expenses</span><span class="text-danger">- <?= money($d['expensesTotal']) ?></span></div>
                    <div class="bs-row subtotal"><span>Net Profit for the period</span><span class="<?= $d['netProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($d['netProfit']) ?></span></div>
                    <div class="bs-row final"><span>Net Worth</span><span><?= money($d['netWorth']) ?></span></div>
                    <div class="bs-row final"><span>Total Liabilities &amp; Capital</span><span><?= money($d['totalLiabilities'] + $d['netWorth']) ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info py-2 small mb-4">
    <i class="fas fa-info-circle me-1"></i> Net Profit is computed from <strong><?= $fy['name'] ?></strong> start (<?= dateFormatted($fy['start']) ?>) to the report date. Opening Capital is derived as the balancing figure so the balance sheet always balances.
</div>

<?php include 'footer.php'; ?>
