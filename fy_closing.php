<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

function fyClosingSnapshot($fy) {
    $df = $fy['start'];
    $dt = $fy['end'];

    // Profit & Loss for the closing year
    $salesTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $salesReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM sale_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $salesDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseTotal = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $purchaseReturns = (float) (fetch("SELECT COALESCE(SUM(total),0) as t FROM purchase_returns WHERE date >= ? AND date <= ? AND status = 'approved'", [$df, $dt])['t'] ?? 0);
    $purchaseDiscount = (float) (fetch("SELECT COALESCE(SUM(discount_amount),0) as t FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'", [$df, $dt])['t'] ?? 0);
    $expensesTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);
    $otherIncomeTotal = (float) (fetch("SELECT COALESCE(SUM(amount),0) as t FROM other_income WHERE date >= ? AND date <= ?", [$df, $dt])['t'] ?? 0);

    $netProfit = round(($salesTotal - $salesReturns - $salesDiscount + $otherIncomeTotal)
        - ($purchaseTotal - $purchaseReturns - $purchaseDiscount) - $expensesTotal, 2);

    // Closing balances carried forward (current balances)
    $cashBalance = (float) getSetting('cash_balance', '0');
    $bankTotal = (float) (fetch("SELECT COALESCE(SUM(current_balance),0) as t FROM bank_accounts WHERE status = 1")['t'] ?? 0);

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

    $stockValue = (float) (fetch("SELECT COALESCE(SUM(current_stock * purchase_price),0) as t FROM items WHERE status = 1")['t'] ?? 0);

    $netWorth = round($cashBalance + $bankTotal + $debtorsTotal + $stockValue - $creditorsTotal, 2);

    return [
        'salesTotal' => $salesTotal,
        'salesReturns' => $salesReturns,
        'salesDiscount' => $salesDiscount,
        'purchaseTotal' => $purchaseTotal,
        'purchaseReturns' => $purchaseReturns,
        'purchaseDiscount' => $purchaseDiscount,
        'expensesTotal' => $expensesTotal,
        'otherIncomeTotal' => $otherIncomeTotal,
        'netProfit' => $netProfit,
        'cashBalance' => $cashBalance,
        'bankTotal' => $bankTotal,
        'debtorsTotal' => $debtorsTotal,
        'creditorsTotal' => $creditorsTotal,
        'stockValue' => $stockValue,
        'netWorth' => $netWorth,
    ];
}

function fyClosingHistory() {
    $history = [];
    $years = getAllFinancialYears();
    foreach ($years as $fy) {
        $closed = getSetting('fy_closed_' . $fy['id'], '0');
        if ($closed === '1') {
            $summary = json_decode(getSetting('fy_closing_summary_' . $fy['id'], '{}'), true);
            if (!is_array($summary)) $summary = [];
            $summary['fy_name'] = $summary['fy_name'] ?? $fy['name'];
            $summary['fy_period'] = dateFormatted($fy['start_date']) . ' - ' . dateFormatted($fy['end_date']);
            $history[] = $summary;
        }
    }
    usort($history, function ($a, $b) {
        return strcmp($b['closed_at'] ?? '', $a['closed_at'] ?? '');
    });
    return $history;
}

function fyCloseYear($fy, $force) {
    $fyId = (int) $fy['id'];
    if (getSetting('fy_closed_' . $fyId) === '1') {
        throw new Exception('This financial year is already closed.');
    }
    if (!$force && $fy['end'] > today()) {
        throw new Exception('The financial year "' . $fy['name'] . '" has not ended yet. You can force close only after the year end.');
    }

    global $pdo;
    $pdo->beginTransaction();
    try {
        $snap = fyClosingSnapshot($fy);

        // Carry forward stock opening
        query("UPDATE items SET opening_stock = current_stock");

        // Carry forward party balances (net closing balance becomes next year opening)
        $parties = fetchAll("SELECT id FROM parties WHERE status = 1");
        foreach ($parties as $p) {
            $bal = getPartyBalance($p['id']);
            if ($bal >= 0) {
                query("UPDATE parties SET opening_balance = ?, balance_type = 'credit' WHERE id = ?", [round($bal, 2), $p['id']]);
            } else {
                query("UPDATE parties SET opening_balance = ?, balance_type = 'debit' WHERE id = ?", [round(abs($bal), 2), $p['id']]);
            }
        }

        // Carry forward bank account opening balances
        query("UPDATE bank_accounts SET opening_balance = current_balance");

        // Create or fetch the next financial year
        $nextStart = date('Y-m-d', strtotime($fy['end'] . ' +1 day'));
        $nextEnd = date('Y-m-d', strtotime($nextStart . ' +1 year -1 day'));
        $sY = date('Y', strtotime($nextStart));
        $eY = date('Y', strtotime($nextEnd));
        $next = fetch("SELECT * FROM financial_years WHERE start_date = ?", [$nextStart]);
        if (!$next) {
            $newId = insertId(
                "INSERT INTO financial_years (name, start_date, end_date, is_active) VALUES (?, ?, ?, 0)",
                ['FY ' . $sY . '-' . substr($eY, 2), $nextStart, $nextEnd]
            );
            $next = getFinancialYearById($newId);
        }

        // Record opening cash for the next year = closing cash
        setSetting('opening_cash_' . $next['id'], number_format($snap['cashBalance'], 2, '.', ''));

        // Settle the year's profit/loss into carried capital (recorded for audit)
        setSetting('fy_closed_' . $fyId, '1');
        setSetting('fy_closing_summary_' . $fyId, json_encode([
            'fy_name' => $fy['name'],
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => $_SESSION['user']['username'] ?? 'admin',
            'net_profit' => $snap['netProfit'],
            'cash_carried' => $snap['cashBalance'],
            'bank_carried' => $snap['bankTotal'],
            'debtors_carried' => $snap['debtorsTotal'],
            'creditors_carried' => $snap['creditorsTotal'],
            'stock_carried' => $snap['stockValue'],
            'capital_carried' => $snap['netWorth'],
            'next_fy' => $next['name'],
        ]));

        // Activate the next year
        query("UPDATE financial_years SET is_active = 0");
        query("UPDATE financial_years SET is_active = 1 WHERE id = ?", [$next['id']]);

        $pdo->commit();

        setCurrentFY($next);
        return ['snap' => $snap, 'next' => $next];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$fy = currentFY();

// Handle POST close
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid security token.');
        header('Location: fy_closing.php');
        exit;
    }
    if (($_POST['action'] ?? '') === 'close') {
        $force = (($_POST['force_close'] ?? '') === '1');
        try {
            $result = fyCloseYear($fy, $force);
            setFlash('success', 'Financial year "' . $fy['name'] . '" closed successfully. Opening balances carried forward to "' . $result['next']['name'] . '".');
        } catch (Exception $e) {
            setFlash('danger', 'Error closing financial year: ' . $e->getMessage());
        }
        header('Location: fy_closing.php');
        exit;
    }
}

$fyId = (int) ($fy['id'] ?? 0);
$isClosed = $fyId > 0 && getSetting('fy_closed_' . $fyId) === '1';
$snapshot = $fyId > 0 ? fyClosingSnapshot($fy) : null;
$history = fyClosingHistory();
$yearEnded = $fyId > 0 && $fy['end'] <= today();

$pageTitle = 'Financial Year Closing';
include 'header.php';
?>

<style>
    .fy-card { border: 1px solid #e9ecef; border-radius: 12px; padding: 20px; background: #fff; }
    .fy-card.is-closed { border-color: #dc3545; background: #fff5f5; }
    .cf-row { display: flex; justify-content: space-between; padding: 0.35rem 0; border-bottom: 1px dotted #eee; }
    .cf-row.subtotal { font-weight: 600; border-bottom: 2px solid #333; padding-top: 0.5rem; }
    .cf-row.final { font-size: 1.05rem; font-weight: 700; padding: 0.6rem 0; border-bottom: 3px double #333; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1 fw-bold"><i class="fas fa-calendar-check me-2 text-primary"></i>Financial Year Closing</h5>
        <p class="text-muted mb-0" style="font-size:13px;">Close the active financial year and settle all business accounting</p>
    </div>
    <a href="financial_year_manage.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-calendar-alt me-1"></i>Manage Years</a>
</div>

<?php if ($fyId <= 0): ?>
    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>No financial year is currently selected. Please select an active financial year first.</div>
<?php else: ?>

<div class="row g-4">
    <!-- Current FY card -->
    <div class="col-lg-4">
        <div class="fy-card <?= $isClosed ? 'is-closed' : '' ?> mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold mb-0"><?= h($fy['name']) ?></h6>
                <?php if ($isClosed): ?>
                    <span class="badge bg-danger">CLOSED</span>
                <?php else: ?>
                    <span class="badge bg-success">ACTIVE</span>
                <?php endif; ?>
            </div>
            <table class="table table-sm table-borderless mb-0" style="font-size:13px;">
                <tr><td class="text-muted">Period</td><td class="text-end"><?= dateFormatted($fy['start']) ?> - <?= dateFormatted($fy['end']) ?></td></tr>
                <tr><td class="text-muted">Status</td><td class="text-end"><?= $isClosed ? '<span class="text-danger fw-bold">Closed</span>' : '<span class="text-success fw-bold">Open</span>' ?></td></tr>
                <?php if (!$isClosed): ?>
                    <tr>
                        <td class="text-muted">Year End</td>
                        <td class="text-end">
                            <?php if ($yearEnded): ?>
                                <span class="text-success fw-bold">Completed</span>
                            <?php else: ?>
                                <span class="text-warning fw-bold">Not yet ended</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php if ($isClosed): ?>
                <div class="alert alert-danger py-2 mb-0 mt-2" style="font-size:12px;"><i class="fas fa-lock me-1"></i>This year is locked. Its closing entry is preserved for audit.</div>
            <?php endif; ?>
        </div>

        <?php if (!$isClosed): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-file-invoice me-1 text-primary"></i>Profit &amp; Loss (Year)</h6></div>
            <div class="card-body">
                <div class="cf-row"><span>Sales (Gross)</span><span><?= money($snapshot['salesTotal']) ?></span></div>
                <div class="cf-row"><span>Less: Sales Returns</span><span class="text-danger">- <?= money($snapshot['salesReturns']) ?></span></div>
                <div class="cf-row"><span>Less: Sales Discount</span><span class="text-danger">- <?= money($snapshot['salesDiscount']) ?></span></div>
                <div class="cf-row"><span>Other Income</span><span><?= money($snapshot['otherIncomeTotal']) ?></span></div>
                <div class="cf-row"><span>Less: Purchases</span><span class="text-danger">- <?= money($snapshot['purchaseTotal']) ?></span></div>
                <div class="cf-row"><span>Add: Purchase Returns</span><span><?= money($snapshot['purchaseReturns']) ?></span></div>
                <div class="cf-row"><span>Add: Purchase Discount</span><span><?= money($snapshot['purchaseDiscount']) ?></span></div>
                <div class="cf-row"><span>Less: Expenses</span><span class="text-danger">- <?= money($snapshot['expensesTotal']) ?></span></div>
                <div class="cf-row final"><span><?= $snapshot['netProfit'] >= 0 ? 'Net Profit' : 'Net Loss' ?></span><span class="<?= $snapshot['netProfit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($snapshot['netProfit']) ?></span></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <?php if ($isClosed): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-lock me-1 text-danger"></i>This Financial Year Is Already Closed</h6></div>
                <div class="card-body">
                    <?php $sum = json_decode(getSetting('fy_closing_summary_' . $fyId, '{}'), true); ?>
                    <?php if (is_array($sum) && !empty($sum)): ?>
                        <table class="table table-sm mb-0" style="max-width:560px;">
                            <tr><td class="text-muted">Closed on</td><td class="text-end fw-semibold"><?= h($sum['closed_at'] ?? '-') ?></td></tr>
                            <tr><td class="text-muted">Closed by</td><td class="text-end fw-semibold"><?= h($sum['closed_by'] ?? '-') ?></td></tr>
                            <tr><td class="text-muted">Net Profit / (Loss)</td><td class="text-end fw-semibold <?= (float)($sum['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($sum['net_profit'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Cash carried forward</td><td class="text-end fw-semibold"><?= money($sum['cash_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Bank carried forward</td><td class="text-end fw-semibold"><?= money($sum['bank_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Debtors carried forward</td><td class="text-end fw-semibold"><?= money($sum['debtors_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Creditors carried forward</td><td class="text-end fw-semibold"><?= money($sum['creditors_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Stock carried forward</td><td class="text-end fw-semibold"><?= money($sum['stock_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Capital / Net Worth carried</td><td class="text-end fw-semibold"><?= money($sum['capital_carried'] ?? 0) ?></td></tr>
                            <tr><td class="text-muted">Next Financial Year</td><td class="text-end fw-semibold"><?= h($sum['next_fy'] ?? '-') ?></td></tr>
                        </table>
                    <?php else: ?>
                        <p class="text-muted mb-0">Closing details not found for this year.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-arrow-right me-1 text-primary"></i>Balances to Carry Forward</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="cf-row"><span>Cash in Hand</span><span><?= money($snapshot['cashBalance']) ?></span></div>
                            <div class="cf-row"><span>Bank Balances</span><span><?= money($snapshot['bankTotal']) ?></span></div>
                            <div class="cf-row"><span>Sundry Debtors (To Receive)</span><span><?= money($snapshot['debtorsTotal']) ?></span></div>
                            <div class="cf-row"><span>Inventory (Stock at Cost)</span><span><?= money($snapshot['stockValue']) ?></span></div>
                            <div class="cf-row"><span>Less: Sundry Creditors (To Pay)</span><span class="text-danger">- <?= money($snapshot['creditorsTotal']) ?></span></div>
                            <div class="cf-row subtotal"><span>Net Worth / Capital</span><span class="fw-bold"><?= money($snapshot['netWorth']) ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled mb-0" style="font-size:13px;">
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Stock opening updated to closing stock</li>
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Party opening balances updated to closing balances</li>
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Bank opening balances updated to closing balances</li>
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Opening cash for next year set to closing cash</li>
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Year's profit/loss settled into carried capital</li>
                                <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i>Next financial year activated automatically</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-lock me-1 text-danger"></i>Close Financial Year</h6></div>
                <div class="card-body">
                    <?php if (!$yearEnded): ?>
                        <div class="alert alert-warning py-2" style="font-size:13px;">
                            <i class="fas fa-exclamation-triangle me-1"></i> The year <?= h($fy['name']) ?> ends on <strong><?= dateFormatted($fy['end']) ?></strong>. Closing before the year end will lock this year and start the next one early. Only recommended for testing.
                        </div>
                    <?php endif; ?>
                    <form method="POST" id="closeFyForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="close">
                        <?php if (!$yearEnded): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="force_close" value="1" id="forceClose">
                                <label class="form-check-label" for="forceClose">Force close (year has not ended yet)</label>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#closeFyConfirmModal"><i class="fas fa-calendar-check me-1"></i> Close <?= h($fy['name']) ?> &amp; Start Next Year</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($history)): ?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-history me-1 text-muted"></i>Closing History</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Financial Year</th>
                    <th>Period</th>
                    <th class="text-end">Net Profit / (Loss)</th>
                    <th class="text-end">Cash</th>
                    <th class="text-end">Bank</th>
                    <th class="text-end">Debtors</th>
                    <th class="text-end">Creditors</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Capital</th>
                    <th>Next Year</th>
                    <th>Closed On</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($h['fy_name']) ?></td>
                        <td class="text-muted" style="font-size:12px;"><?= h($h['fy_period'] ?? '') ?></td>
                        <td class="text-end <?= (float)($h['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($h['net_profit'] ?? 0) ?></td>
                        <td class="text-end"><?= money($h['cash_carried'] ?? 0) ?></td>
                        <td class="text-end"><?= money($h['bank_carried'] ?? 0) ?></td>
                        <td class="text-end"><?= money($h['debtors_carried'] ?? 0) ?></td>
                        <td class="text-end"><?= money($h['creditors_carried'] ?? 0) ?></td>
                        <td class="text-end"><?= money($h['stock_carried'] ?? 0) ?></td>
                        <td class="text-end fw-bold"><?= money($h['capital_carried'] ?? 0) ?></td>
                        <td><?= h($h['next_fy'] ?? '') ?></td>
                        <td class="text-muted" style="font-size:12px;"><?= h($h['closed_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<div class="modal fade" id="closeFyConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-body text-center py-4 px-4">
                <div style="width:56px;height:56px;border-radius:50%;background:#fff0f0;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                    <i class="fas fa-exclamation-triangle" style="font-size:26px;color:#e74c3c;"></i>
                </div>
                <h5 class="modal-title fw-semibold mb-2">Close <?= h($fy['name']) ?>?</h5>
                <p class="text-muted mb-0" style="font-size:14px;">All balances will be carried forward to the next financial year. This action cannot be undone.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4 pt-0 gap-2">
                <button type="button" class="btn btn-light px-3" style="border-radius:8px;font-size:14px;" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="closeFyConfirmBtn" class="btn px-3" style="border-radius:8px;font-size:14px;background:#e74c3c;color:#fff;"><i class="fas fa-calendar-check me-1"></i> Yes, Close Year</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('closeFyConfirmBtn').addEventListener('click', function() {
    document.getElementById('closeFyForm').submit();
});
</script>

<?php include 'footer.php'; ?>
