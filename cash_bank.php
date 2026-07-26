<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$fyInit = currentFY();
$fyId = $fyInit['id'];
$fyStart = $fyInit['start'];
$fyEnd = $fyInit['end'];
$openingCash = floatval(getSetting('opening_cash_' . $fyId, '0'));

// Handle Add Bank Account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: cash_bank.php');
        exit;
    }

    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'add_bank') {
        $bankName = sanitize($_POST['bank_name'] ?? '');
        $accountName = sanitize($_POST['account_name'] ?? '');
        $accountNo = sanitize($_POST['account_no'] ?? '');
        $ifsc = sanitize($_POST['ifsc_code'] ?? '');
        $openingBal = floatval($_POST['opening_balance'] ?? 0);

        if ($bankName === '' || $accountName === '' || $accountNo === '') {
            setFlash('danger', 'Bank name, account name, and account number are required.');
        } else {
            query("INSERT INTO bank_accounts (bank_name, account_name, account_no, ifsc_code, opening_balance, current_balance, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())",
                [$bankName, $accountName, $accountNo, $ifsc, $openingBal, $openingBal]);
            setFlash('success', 'Bank account added successfully.');
        }
        header('Location: cash_bank.php');
        exit;
    }

    if ($formAction === 'set_opening_cash') {
        $newOpening = floatval($_POST['opening_cash'] ?? 0);
        $fyCurrent = currentFY();
        $fyId = $fyCurrent['id'];
        $fyS = $fyCurrent['start'];
        $fyE = $fyCurrent['end'];
        setSetting('opening_cash_' . $fyId, number_format($newOpening, 2, '.', ''));
        $cashRow = query("SELECT
            COALESCE(SUM(CASE WHEN src = 'sale' THEN amt END), 0) AS cash_sales,
            COALESCE(SUM(CASE WHEN src = 'pi' THEN amt END), 0) AS cash_in,
            COALESCE(SUM(CASE WHEN src = 'po' THEN amt END), 0) AS cash_out,
            COALESCE(SUM(CASE WHEN src = 'exp' THEN amt END), 0) AS cash_expenses
        FROM (
            SELECT 'sale' AS src, paid_amount AS amt FROM sales WHERE payment_method = 'cash' AND status != 'cancelled' AND date >= ? AND date <= ?
            UNION ALL SELECT 'pi', amount FROM payments_in WHERE payment_method = 'cash' AND date >= ? AND date <= ?
            UNION ALL SELECT 'po', amount FROM payments_out WHERE payment_method = 'cash' AND date >= ? AND date <= ?
            UNION ALL SELECT 'exp', amount FROM expenses WHERE payment_method = 'cash' AND date >= ? AND date <= ?
        ) t", [$fyS, $fyE, $fyS, $fyE, $fyS, $fyE, $fyS, $fyE])->fetch();
        $newBalance = $newOpening + (float)$cashRow['cash_sales'] + (float)$cashRow['cash_in'] - (float)$cashRow['cash_out'] - (float)$cashRow['cash_expenses'];
        setSetting('cash_balance', number_format($newBalance, 2, '.', ''));
        setFlash('success', 'Opening cash balance updated successfully.');
        header('Location: cash_bank.php');
        exit;
    }

    if ($formAction === 'cash_adjustment') {
        $adjustType = sanitize($_POST['adjust_type'] ?? '');
        $adjustAmount = floatval($_POST['adjust_amount'] ?? 0);
        $adjustReason = sanitize($_POST['adjust_reason'] ?? '');

        if ($adjustAmount <= 0) {
            setFlash('danger', 'Amount must be greater than 0.');
            header('Location: cash_bank.php');
            exit;
        }

        $cashBal = floatval(getSetting('cash_balance', '0'));

        if ($adjustType === 'in') {
            $newBal = $cashBal + $adjustAmount;
            $desc = 'Cash received: ' . $adjustReason;
        } else {
            if ($cashBal < $adjustAmount) {
                setFlash('danger', 'Insufficient cash balance.');
                header('Location: cash_bank.php');
                exit;
            }
            $newBal = $cashBal - $adjustAmount;
            $desc = 'Cash paid: ' . $adjustReason;
        }

        setSetting('cash_balance', number_format($newBal, 2, '.', ''));
        query("INSERT INTO transactions (type, reference_id, amount, payment_method, date, description, created_at) VALUES ('adjustment', NULL, ?, 'cash', ?, ?, NOW())",
            [$adjustAmount, today(), $desc]);
        setFlash('success', 'Cash adjustment recorded successfully.');
        header('Location: cash_bank.php');
        exit;
    }

    if ($formAction === 'transfer') {
        $fromType = sanitize($_POST['from_type'] ?? '');
        $fromId = intval($_POST['from_id'] ?? 0);
        $toType = sanitize($_POST['to_type'] ?? '');
        $toId = intval($_POST['to_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $transferNotes = sanitize($_POST['transfer_notes'] ?? '');

        if ($amount <= 0) {
            setFlash('danger', 'Transfer amount must be greater than 0.');
            header('Location: cash_bank.php');
            exit;
        }
        if ($fromType === $toType && $fromId === $toId) {
            setFlash('danger', 'Cannot transfer to the same account.');
            header('Location: cash_bank.php');
            exit;
        }

        global $pdo;
        $pdo->beginTransaction();
        try {
            if ($fromType === 'cash') {
                $cashBal = floatval(getSetting('cash_balance', '0'));
                if ($cashBal < $amount) throw new Exception('Insufficient cash balance.');
                setSetting('cash_balance', number_format($cashBal - $amount, 2, '.', ''));
            } else {
                $acc = fetch("SELECT * FROM bank_accounts WHERE id = ?", [$fromId]);
                if (!$acc || $acc['current_balance'] < $amount) throw new Exception('Insufficient bank balance.');
                query("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?", [$amount, $fromId]);
            }

            if ($toType === 'cash') {
                $cashBal = floatval(getSetting('cash_balance', '0'));
                setSetting('cash_balance', number_format($cashBal + $amount, 2, '.', ''));
            } else {
                query("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?", [$amount, $toId]);
            }

            query("INSERT INTO transactions (type, reference_id, amount, payment_method, date, description, created_at) VALUES ('adjustment', NULL, ?, 'bank', ?, ?, NOW())",
                [$amount, today(), "Transfer from $fromType to $toType: " . $transferNotes]);

            $pdo->commit();
            setFlash('success', 'Transfer completed successfully.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', $e->getMessage());
        }
        header('Location: cash_bank.php');
        exit;
    }
}

// Calculate cash in hand
$cashBalanceRaw = getSetting('cash_balance', '');
$cashBalance = floatval($cashBalanceRaw);
if ($cashBalance === 0.0 && $cashBalanceRaw === '') {
    $cashRow = query("SELECT
        COALESCE(SUM(CASE WHEN src='sale' THEN amt END),0) AS cash_sales,
        COALESCE(SUM(CASE WHEN src='pi' THEN amt END),0) AS cash_in,
        COALESCE(SUM(CASE WHEN src='po' THEN amt END),0) AS cash_out,
        COALESCE(SUM(CASE WHEN src='exp' THEN amt END),0) AS cash_expenses
    FROM (
        SELECT 'sale' AS src, paid_amount AS amt FROM sales WHERE payment_method='cash' AND status!='cancelled' AND date>=? AND date<=?
        UNION ALL SELECT 'pi', amount FROM payments_in WHERE payment_method='cash' AND date>=? AND date<=?
        UNION ALL SELECT 'po', amount FROM payments_out WHERE payment_method='cash' AND date>=? AND date<=?
        UNION ALL SELECT 'exp', amount FROM expenses WHERE payment_method='cash' AND date>=? AND date<=?
    ) t", [$fyStart,$fyEnd,$fyStart,$fyEnd,$fyStart,$fyEnd,$fyStart,$fyEnd])->fetch();
    $cashBalance = $openingCash + (float)$cashRow['cash_sales'] + (float)$cashRow['cash_in'] - (float)$cashRow['cash_out'] - (float)$cashRow['cash_expenses'];
    setSetting('cash_balance', number_format($cashBalance, 2, '.', ''));
}

$pageTitle = 'Cash & Bank';
include 'header.php';

$bankAccounts = fetchAll("SELECT * FROM bank_accounts WHERE status = 1 ORDER BY bank_name ASC");
$totalBankBalance = 0;
foreach ($bankAccounts as $ba) {
    $totalBankBalance += (float) $ba['current_balance'];
}

$recentCashTxns = fetchAll(
    "(SELECT 'sale' AS t_type, s.date, s.invoice_no AS ref, COALESCE(p.name,'Walk-in') AS party, s.paid_amount AS amount, 'in' AS direction
      FROM sales s LEFT JOIN parties p ON s.party_id = p.id
      WHERE s.payment_method = 'cash' AND s.status != 'cancelled' AND s.date >= ? AND s.date <= ?)
     UNION ALL
     (SELECT 'payment_in' AS t_type, pi.date, pi.receipt_no AS ref, p2.name AS party, pi.amount, 'in' AS direction
      FROM payments_in pi LEFT JOIN parties p2 ON pi.party_id = p2.id WHERE pi.payment_method = 'cash' AND pi.date >= ? AND pi.date <= ?)
     UNION ALL
     (SELECT 'payment_out' AS t_type, po.date, po.payment_no AS ref, p3.name AS party, po.amount, 'out' AS direction
      FROM payments_out po LEFT JOIN parties p3 ON po.party_id = p3.id WHERE po.payment_method = 'cash' AND po.date >= ? AND po.date <= ?)
     UNION ALL
     (SELECT 'expense' AS t_type, e.date, e.expense_no AS ref, ec.name AS party, e.amount, 'out' AS direction
      FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id WHERE e.payment_method = 'cash' AND e.date >= ? AND e.date <= ?)
     ORDER BY date DESC LIMIT 15",
    [$fyStart, $fyEnd, $fyStart, $fyEnd, $fyStart, $fyEnd, $fyStart, $fyEnd]
);

$transfers = fetchAll("SELECT * FROM transactions WHERE type = 'adjustment' ORDER BY id DESC LIMIT 10");
?>

<style>
    .summary-card { border-left: 4px solid; border-radius: 8px; }
    .summary-cash { border-color: #ff9800; }
    .summary-bank { border-color: #2962FF; }
    .summary-upi { border-color: #9c27b0; }
    .direction-in { color: #28a745; }
    .direction-out { color: #dc3545; }
</style>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card summary-card summary-cash">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(255,152,0,0.1);">
                        <i class="fas fa-wallet fa-lg" style="color:#ff9800;"></i>
                    </div>
                    <div>
                        <small class="text-muted">Cash in Hand</small>
                        <h4 class="mb-0 fw-bold"><?= money($cashBalance) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card summary-card summary-bank">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(41,98,255,0.1);">
                        <i class="fas fa-university fa-lg" style="color:#2962FF;"></i>
                    </div>
                    <div>
                        <small class="text-muted">Total Bank Balance</small>
                        <h4 class="mb-0 fw-bold"><?= money($totalBankBalance) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card summary-card summary-upi">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width:48px;height:48px;background:rgba(156,39,176,0.1);">
                        <i class="fas fa-mobile-alt fa-lg" style="color:#9c27b0;"></i>
                    </div>
                    <div>
                        <small class="text-muted">Total UPI Balance</small>
                        <h4 class="mb-0 fw-bold"><?= money((float) getSetting('upi_balance', '0')) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-1"></i> Cash in Hand</h6>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#openingCashModal">
                        <i class="fas fa-edit me-1"></i>Set Opening
                    </button>
                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#cashAdjustModal">
                        <i class="fas fa-plus-circle me-1"></i>Add Entry
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Party</th>
                            <th class="text-center">Type</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentCashTxns)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No cash transactions yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentCashTxns as $txn): ?>
                                <tr>
                                    <td><?= dateFormatted($txn['date']) ?></td>
                                    <td class="fw-semibold" style="font-size:12px;"><?= sanitize($txn['ref']) ?></td>
                                    <td style="font-size:12px;"><?= sanitize($txn['party']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $txn['direction'] === 'in' ? 'bg-success' : 'bg-danger' ?>">
                                            <?= $txn['direction'] === 'in' ? 'IN' : 'OUT' ?>
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold direction-<?= $txn['direction'] ?>">
                                        <?= $txn['direction'] === 'in' ? '+' : '-' ?><?= money($txn['amount']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-university me-1"></i> Bank Accounts</h6>
            </div>
            <div class="card-body">
                <?php if (empty($bankAccounts)): ?>
                    <p class="text-muted text-center py-3">No bank accounts added yet.</p>
                <?php else: ?>
                    <?php foreach ($bankAccounts as $ba): ?>
                        <div class="d-flex justify-content-between align-items-center p-2 mb-2 rounded" style="background:#f8f9fa;">
                            <div>
                                <strong style="font-size:13px;"><?= sanitize($ba['bank_name']) ?></strong><br>
                                <small class="text-muted">A/C: <?= sanitize($ba['account_no']) ?> | <?= sanitize($ba['ifsc_code'] ?? '') ?></small>
                            </div>
                            <div class="text-end">
                                <strong style="font-size:14px;"><?= money($ba['current_balance']) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <hr>
                <h6 class="fw-bold mb-3" style="font-size:13px;">Add Bank Account</h6>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="add_bank">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" name="bank_name" class="form-control form-control-sm" placeholder="Bank Name" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="account_name" class="form-control form-control-sm" placeholder="Account Holder Name" required>
                        </div>
                        <div class="col-md-5">
                            <input type="text" name="account_no" class="form-control form-control-sm" placeholder="Account Number" required>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="ifsc_code" class="form-control form-control-sm" placeholder="IFSC">
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="opening_balance" class="form-control form-control-sm" placeholder="Opening" step="0.01" value="0">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-exchange-alt me-1"></i> Quick Transfer</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="transfer">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">From</label>
                            <select name="from_type" id="from_type" class="form-select form-select-sm" required>
                                <option value="cash">Cash in Hand</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="bank_<?= $ba['id'] ?>">Bank: <?= sanitize($ba['bank_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-center justify-content-center">
                            <i class="fas fa-arrow-right text-muted fa-lg mt-3"></i>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">To</label>
                            <select name="to_type" id="to_type" class="form-select form-select-sm" required>
                                <option value="cash">Cash in Hand</option>
                                <?php foreach ($bankAccounts as $ba): ?>
                                    <option value="bank_<?= $ba['id'] ?>">Bank: <?= sanitize($ba['bank_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="from_id" id="from_id" value="0">
                        <input type="hidden" name="to_id" id="to_id" value="0">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount</label>
                            <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="transfer_notes" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-exchange-alt me-1"></i> Transfer</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-history me-1"></i> Transfer History</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No transfers yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $tr): ?>
                                <tr>
                                    <td><?= dateFormatted($tr['date']) ?></td>
                                    <td style="font-size:12px;"><?= sanitize($tr['description'] ?? '-') ?></td>
                                    <td class="text-end fw-bold"><?= money($tr['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Set Opening Cash Modal -->
<div class="modal fade" id="openingCashModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-coins me-2 text-warning"></i>Set Opening Cash Balance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="set_opening_cash">
                    <p class="text-muted mb-3" style="font-size:13px;">Set your starting cash balance. This will recalculate your current cash in hand.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Opening Cash Balance</label>
                        <input type="number" name="opening_cash" class="form-control" step="0.01" min="0" value="<?= num($openingCash) ?>" required>
                    </div>
                    <div class="alert alert-info py-2 mb-0" style="font-size:12px;">
                        <i class="fas fa-info-circle me-1"></i>Current opening balance: <strong><?= money($openingCash) ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-save me-2"></i>Update Balance</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cash Adjustment Modal -->
<div class="modal fade" id="cashAdjustModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-exchange-alt me-2 text-success"></i>Cash Adjustment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="cash_adjustment">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="adjust_type" class="form-select" required>
                            <option value="in">Cash In (Received)</option>
                            <option value="out">Cash Out (Paid)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount</label>
                        <input type="number" name="adjust_amount" class="form-control" step="0.01" min="0.01" required placeholder="Enter amount">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason / Description</label>
                        <input type="text" name="adjust_reason" class="form-control" placeholder="e.g. Petty cash, counter adjustment" required>
                    </div>
                    <div class="alert alert-info py-2 mb-0" style="font-size:12px;">
                        <i class="fas fa-info-circle me-1"></i>Current cash balance: <strong><?= money($cashBalance) ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Record Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function parseAccount(val) {
        if (val === 'cash') return { type: 'cash', id: 0 };
        var parts = val.replace('bank_', '');
        return { type: 'bank', id: parseInt(parts) };
    }
    document.getElementById('from_type').addEventListener('change', function() {
        var a = parseAccount(this.value);
        document.getElementById('from_id').value = a.id;
    });
    document.getElementById('to_type').addEventListener('change', function() {
        var a = parseAccount(this.value);
        document.getElementById('to_id').value = a.id;
    });
    document.getElementById('from_type').dispatchEvent(new Event('change'));
    document.getElementById('to_type').dispatchEvent(new Event('change'));
});
</script>

<?php include 'footer.php'; ?>
