<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$party = fetch("SELECT * FROM parties WHERE id = ?", [$id]);

if (!$party) {
    setFlash('danger', 'Party not found.');
    header('Location: parties.php');
    exit;
}

$balance = getPartyBalance($party['id']);
$tab = $_GET['tab'] ?? 'transactions';

$totalSales = (float)(fetch("SELECT COALESCE(SUM(total),0) as t FROM sales WHERE party_id = ? AND status != 'cancelled'", [$party['id']])['t'] ?? 0);
$totalPurchases = (float)(fetch("SELECT COALESCE(SUM(total),0) as t FROM purchases WHERE party_id = ? AND status != 'cancelled'", [$party['id']])['t'] ?? 0);
$totalPaymentsIn = (float)(fetch("SELECT COALESCE(SUM(amount),0) as t FROM payments_in WHERE party_id = ?", [$party['id']])['t'] ?? 0);
$totalPaymentsOut = (float)(fetch("SELECT COALESCE(SUM(amount),0) as t FROM payments_out WHERE party_id = ?", [$party['id']])['t'] ?? 0);
$openingBalance = (float)($party['opening_balance'] ?? 0);

$salesDueVal = (float)(fetch("SELECT COALESCE(SUM(due_amount),0) as t FROM sales WHERE party_id = ? AND status != 'cancelled'", [$party['id']])['t'] ?? 0);
$purchasesDueVal = (float)(fetch("SELECT COALESCE(SUM(due_amount),0) as t FROM purchases WHERE party_id = ? AND status != 'cancelled'", [$party['id']])['t'] ?? 0);
$receivable = round(max(0, $openingBalance) + $salesDueVal, 2);
$payable = round(max(0, -$openingBalance) + $purchasesDueVal, 2);

$transactions = fetchAll("
    SELECT 'sale' as source, id, date, invoice_no as ref_no, subtotal, tax_amount, total, paid_amount, due_amount, status, NULL as pay_type, NULL as pay_method, NULL as pay_amount
    FROM sales WHERE party_id = ? AND status != 'cancelled'
    UNION ALL
    SELECT 'purchase' as source, id, date, bill_no as ref_no, subtotal, tax_amount, total, paid_amount, due_amount, status, NULL as pay_type, NULL as pay_method, NULL as pay_amount
    FROM purchases WHERE party_id = ? AND status != 'cancelled'
    UNION ALL
    SELECT 'payment_in' as source, id, date, receipt_no as ref_no, 0 as subtotal, 0 as tax_amount, 0 as total, amount as paid_amount, 0 as due_amount, 'completed' as status, 'received' as pay_type, payment_method as pay_method, amount as pay_amount
    FROM payments_in WHERE party_id = ?
    UNION ALL
    SELECT 'payment_out' as source, id, date, payment_no as ref_no, 0 as subtotal, 0 as tax_amount, 0 as total, amount as paid_amount, 0 as due_amount, 'completed' as status, 'paid' as pay_type, payment_method as pay_method, amount as pay_amount
    FROM payments_out WHERE party_id = ?
    ORDER BY date DESC, id DESC
", [$party['id'], $party['id'], $party['id'], $party['id']]);

$statement = [];
$runningBalance = $openingBalance;
foreach (array_reverse($transactions) as $txn) {
    if ($txn['source'] === 'sale') {
        $runningBalance += (float)$txn['total'] - (float)$txn['paid_amount'];
    } elseif ($txn['source'] === 'purchase') {
        $runningBalance -= (float)$txn['total'] - (float)$txn['paid_amount'];
    } elseif ($txn['source'] === 'payment_in') {
        $runningBalance -= (float)$txn['pay_amount'];
    } elseif ($txn['source'] === 'payment_out') {
        $runningBalance += (float)$txn['pay_amount'];
    }
    $txn['running_balance'] = $runningBalance;
    $statement[] = $txn;
}
$statement = array_reverse($statement);

$outstanding = fetchAll("SELECT 'sale' as source, id, invoice_no as ref_no, date, total, paid_amount, due_amount, status FROM sales WHERE party_id = ? AND due_amount > 0 AND status != 'cancelled' UNION ALL SELECT 'purchase' as source, id, bill_no as ref_no, date, total, paid_amount, due_amount, status FROM purchases WHERE party_id = ? AND due_amount > 0 AND status != 'cancelled' ORDER BY date ASC", [$party['id'], $party['id']]);

$pageTitle = 'Party Details';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1">
            <?php echo htmlspecialchars($party['name']); ?>
            <?php if ($party['type'] === 'customer'): ?>
                <span class="badge bg-info ms-2">Customer</span>
            <?php elseif ($party['type'] === 'supplier'): ?>
                <span class="badge bg-warning text-dark ms-2">Supplier</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-2">Both</span>
            <?php endif; ?>
        </h4>
        <small class="text-muted">
            <?php if (!empty($party['phone'])): ?>
                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($party['phone']); ?>
            <?php endif; ?>
            <?php if (!empty($party['email'])): ?>
                <span class="ms-2"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($party['email']); ?></span>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="party_edit.php?id=<?php echo $party['id']; ?>" class="btn btn-outline-warning btn-sm">
            <i class="fas fa-pencil-alt me-1"></i> Edit
        </a>
        <a href="parties.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?php if (!empty($party['address']) || !empty($party['city']) || !empty($party['state'])): ?>
    <p class="text-muted mb-3">
        <i class="fas fa-map-marker-alt me-1"></i>
        <?php
        $addrParts = array_filter([$party['address'], $party['city'], $party['state'], $party['pincode']]);
        echo htmlspecialchars(implode(', ', $addrParts));
        ?>
    </p>
<?php endif; ?>

<?php if (!empty($party['gstin']) || !empty($party['pan']) || !empty($party['gst_reg_type'])): ?>
    <p class="text-muted mb-3">
        <?php if (!empty($party['gst_reg_type'])): ?>
            <?php
            $gstTypeLabels = [
                'unregistered' => 'Unregistered / Consumer',
                'regular'      => 'Regular Taxable Person',
                'composition'  => 'Composition Taxable Person',
                'sez_unit'     => 'SEZ Unit',
                'sez_dev'      => 'SEZ Developer',
                'non_resident' => 'Non-Resident Taxable Person',
                'oidar'        => 'Non-Resident Online (OIDAR)',
                'isd'          => 'Input Service Distributor (ISD)',
                'tds'          => 'Tax Deductor',
                'tcs'          => 'Tax Collector (eTCS)',
                'urp'          => 'URP (Unregistered Person)',
            ];
            ?>
            <span class="me-3"><strong>GST Type:</strong> <?= htmlspecialchars($gstTypeLabels[$party['gst_reg_type']] ?? $party['gst_reg_type']) ?></span>
        <?php endif; ?>
        <?php if (!empty($party['gstin'])): ?>
            <span class="me-3"><strong>GSTIN:</strong> <?php echo htmlspecialchars($party['gstin']); ?></span>
        <?php endif; ?>
        <?php if (!empty($party['pan'])): ?>
            <span><strong>PAN:</strong> <?php echo htmlspecialchars($party['pan']); ?></span>
        <?php endif; ?>
    </p>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body text-center py-2">
                <small class="text-muted">Opening Balance</small>
                <div class="fw-bold"><?php echo money($openingBalance); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body text-center py-2">
                <small class="text-muted">Total Sales</small>
                <div class="fw-bold text-primary"><?php echo money($totalSales); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center py-2">
                <small class="text-muted">Total Purchases</small>
                <div class="fw-bold text-warning"><?php echo money($totalPurchases); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-<?php echo $balance >= 0 ? 'success' : 'danger'; ?>">
            <div class="card-body text-center py-2">
                <small class="text-muted">Current Balance</small>
                <div class="fw-bold fs-5 <?php echo $balance >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo money(abs($balance)); ?>
                    <small>(<?php echo $balance >= 0 ? 'You\'ll Receive' : 'You\'ll Pay'; ?>)</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-2 mb-4">
    <div class="col-md-3">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-2">
                <small class="text-muted"><i class="fas fa-arrow-down me-1 text-success"></i>You'll Receive (Receivable)</small>
                <div class="fw-bold text-success"><?php echo money($receivable); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-2">
                <small class="text-muted"><i class="fas fa-arrow-up me-1 text-danger"></i>You'll Pay (Payable)</small>
                <div class="fw-bold text-danger"><?php echo money($payable); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-2">
                <small class="text-muted">Payments Received</small>
                <div class="fw-bold text-success"><?php echo money($totalPaymentsIn); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-2">
                <small class="text-muted">Payments Made</small>
                <div class="fw-bold text-danger"><?php echo money($totalPaymentsOut); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <a href="sale_add.php?party_id=<?php echo $party['id']; ?>" class="btn btn-success">
        <i class="fas fa-shopping-cart me-1"></i> Sale
    </a>
    <a href="purchase_add.php?party_id=<?php echo $party['id']; ?>" class="btn btn-warning">
        <i class="fas fa-truck me-1"></i> Purchase
    </a>
    <a href="payment_add.php?party_id=<?php echo $party['id']; ?>" class="btn btn-info">
        <i class="fas fa-money-bill me-1"></i> Payment
    </a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'transactions' ? 'active' : ''; ?>"
           href="?id=<?php echo $party['id']; ?>&tab=transactions">Transaction History</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'statement' ? 'active' : ''; ?>"
           href="?id=<?php echo $party['id']; ?>&tab=statement">Party Statement</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'outstanding' ? 'active' : ''; ?>"
           href="?id=<?php echo $party['id']; ?>&tab=outstanding">
            Outstanding
            <?php if (count($outstanding) > 0): ?>
                <span class="badge bg-danger"><?php echo count($outstanding); ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'transactions'): ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Invoice #</th>
                    <th>Type</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No transactions yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $txn): ?>
                        <tr>
                            <td><?php echo dateFormatted($txn['date']); ?></td>
                            <td><?php echo htmlspecialchars($txn['ref_no'] ?? '-'); ?></td>
                            <td>
                                <?php if ($txn['source'] === 'payment_in'): ?>
                                    <span class="badge bg-success">Payment In</span>
                                <?php elseif ($txn['source'] === 'payment_out'): ?>
                                    <span class="badge bg-danger">Payment Out</span>
                                <?php elseif ($txn['source'] === 'sale'): ?>
                                    <span class="badge bg-primary">Sale</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Purchase</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (in_array($txn['source'], ['payment_in', 'payment_out'])): ?>
                                    <?php echo money($txn['pay_amount']); ?>
                                <?php else: ?>
                                    <?php echo money($txn['total']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (in_array($txn['source'], ['payment_in', 'payment_out'])): ?>
                                    <?php echo money($txn['pay_amount']); ?>
                                <?php else: ?>
                                    <?php echo money($txn['paid_amount']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if (in_array($txn['source'], ['sale', 'purchase']) && $txn['due_amount'] > 0): ?>
                                    <span class="text-danger fw-bold"><?php echo money($txn['due_amount']); ?></span>
                                <?php else: ?>
                                    <span class="text-success">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (in_array($txn['source'], ['payment_in', 'payment_out'])): ?>
                                    <span class="badge bg-success">Completed</span>
                                <?php else: ?>
                                    <?php
                                    $statusClass = 'secondary';
                                    if ($txn['status'] === 'paid') $statusClass = 'success';
                                    elseif ($txn['status'] === 'partial' || $txn['status'] === 'unpaid') $statusClass = 'warning text-dark';
                                    elseif ($txn['status'] === 'cancelled') $statusClass = 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>"><?php echo ucfirst($txn['status']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'statement'): ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                    <th class="text-end">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="table-light">
                    <td>-</td>
                    <td class="fw-semibold">Opening Balance</td>
                    <td class="text-end"><?php echo $openingBalance > 0 ? money($openingBalance) : '-'; ?></td>
                    <td class="text-end"><?php echo $openingBalance < 0 ? money(abs($openingBalance)) : '-'; ?></td>
                    <td class="text-end fw-bold"><?php echo money($openingBalance); ?></td>
                </tr>
                <?php if (empty($statement)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No transactions yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($statement as $row): ?>
                        <tr>
                            <td><?php echo dateFormatted($row['date']); ?></td>
                            <td>
                                <?php if ($row['source'] === 'payment_in'): ?>
                                    Payment Received (<?= htmlspecialchars($row['pay_method'] ?? '') ?>)
                                <?php elseif ($row['source'] === 'payment_out'): ?>
                                    Payment Made (<?= htmlspecialchars($row['pay_method'] ?? '') ?>)
                                <?php elseif ($row['source'] === 'sale'): ?>
                                    Sale - <?= htmlspecialchars($row['ref_no'] ?? '') ?>
                                <?php else: ?>
                                    Purchase - <?= htmlspecialchars($row['ref_no'] ?? '') ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php
                                $debit = 0;
                                if ($row['source'] === 'sale') {
                                    $debit = (float)$row['total'];
                                } elseif ($row['source'] === 'payment_out') {
                                    $debit = (float)$row['pay_amount'];
                                }
                                echo $debit > 0 ? money($debit) : '-';
                                ?>
                            </td>
                            <td class="text-end">
                                <?php
                                $credit = 0;
                                if ($row['source'] === 'purchase') {
                                    $credit = (float)$row['total'];
                                } elseif ($row['source'] === 'payment_in') {
                                    $credit = (float)$row['pay_amount'];
                                }
                                echo $credit > 0 ? money($credit) : '-';
                                ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $row['running_balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo money($row['running_balance']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'outstanding'): ?>
    <?php if (empty($outstanding)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
            <p>No outstanding dues. All clear!</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Invoice #</th>
                        <th>Type</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Due</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outstanding as $inv): ?>
                        <tr>
                            <td><?php echo dateFormatted($inv['date']); ?></td>
                            <td><?php echo htmlspecialchars($inv['ref_no'] ?? '-'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $inv['source'] === 'sale' ? 'primary' : 'warning text-dark'; ?>">
                                    <?php echo ucfirst($inv['source']); ?>
                                </span>
                            </td>
                            <td class="text-end"><?php echo money($inv['total']); ?></td>
                            <td class="text-end"><?php echo money($inv['paid_amount']); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo money($inv['due_amount']); ?></td>
                            <td class="text-center">
                                <a href="payment_add.php?party_id=<?php echo $party['id']; ?>&transaction_id=<?php echo $inv['id']; ?>"
                                   class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-money-bill me-1"></i> Receive
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
