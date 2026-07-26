<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    setFlash('danger', 'Invalid purchase ID.');
    header('Location: purchases.php');
    exit;
}

$purchase = fetch(
    "SELECT p.*, pt.name AS supplier_name, pt.phone AS supplier_phone, pt.email AS supplier_email,
            pt.address AS supplier_address, pt.city AS supplier_city, pt.state AS supplier_state,
            pt.gstin AS supplier_gstin, pt.pan AS supplier_pan
     FROM purchases p
     LEFT JOIN parties pt ON p.party_id = pt.id
     WHERE p.id = ?",
    [$id]
);

if (!$purchase) {
    setFlash('danger', 'Purchase bill not found.');
    header('Location: purchases.php');
    exit;
}

$items = fetchAll(
    "SELECT pi.*, i.name AS item_name, i.sku, i.unit
     FROM purchase_items pi
     LEFT JOIN items i ON pi.item_id = i.id
     WHERE pi.purchase_id = ?
     ORDER BY pi.id ASC",
    [$id]
);

$payments = fetchAll(
    "SELECT * FROM payments_out WHERE purchase_id = ? ORDER BY date DESC, id DESC",
    [$id]
);

$company = getCompany();

$pageTitle = 'Purchase Bill Details';
include 'header.php';
?>

<style>
    @media print {
        .no-print { display: none !important; }
        .main-content { margin-left: 0 !important; }
        .sidebar { display: none !important; }
        .footer-bar { display: none !important; }
        .navbar { display: none !important; }
        body { padding: 0; margin: 0; }
    }
    .print-section { max-width: 800px; margin: 0 auto; }
    .bill-header { border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
    .bill-table th, .bill-table td { padding: 8px 10px; font-size: 0.9rem; }
    .info-label { font-weight: 600; color: #555; font-size: 0.85rem; }
    .info-value { font-size: 0.9rem; }
</style>

<div class="no-print mb-3 d-flex justify-content-between">
    <a href="purchases.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
    <div class="d-flex gap-2">
        <?php if ($purchase['due_amount'] > 0 && $purchase['payment_status'] !== 'paid'): ?>
            <a href="payment_out.php?purchase_id=<?= $id ?>" class="btn btn-success"><i class="fas fa-money-bill-wave me-1"></i> Add Payment</a>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="print-section">

            <div class="bill-header text-center">
                <?php if (!empty($company['logo']) && file_exists('uploads/logo/' . $company['logo'])): ?>
                    <img src="uploads/logo/<?= sanitize($company['logo']) ?>" alt="Logo" style="max-height:60px; margin-bottom:10px;">
                <?php endif; ?>
                <h4 class="mb-1"><?= sanitize($company['name'] ?? 'Company Name') ?></h4>
                <small class="text-muted">
                    <?= sanitize($company['address'] ?? '') ?>
                    <?php if (!empty($company['city'])): ?>, <?= sanitize($company['city']) ?><?php endif; ?>
                    <?php if (!empty($company['state'])): ?>, <?= sanitize($company['state']) ?><?php endif; ?>
                    <?php if (!empty($company['pincode'])): ?> - <?= sanitize($company['pincode']) ?><?php endif; ?>
                </small>
                <?php if (!empty($company['phone'])): ?>
                    <br><small class="text-muted">Phone: <?= sanitize($company['phone']) ?></small>
                <?php endif; ?>
                <?php if (!empty($company['gstin'])): ?>
                    <br><small class="text-muted">GSTIN: <?= sanitize($company['gstin']) ?></small>
                <?php endif; ?>
                <h3 class="mt-3 mb-0">PURCHASE BILL</h3>
            </div>

            <div class="row mb-4">
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Supplier Details</h6>
                    <table>
                        <tr><td class="info-label pe-3">Name:</td><td class="info-value"><?= sanitize($purchase['supplier_name'] ?? 'Walk-in') ?></td></tr>
                        <?php if (!empty($purchase['supplier_phone'])): ?>
                            <tr><td class="info-label pe-3">Phone:</td><td class="info-value"><?= sanitize($purchase['supplier_phone']) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($purchase['supplier_email'])): ?>
                            <tr><td class="info-label pe-3">Email:</td><td class="info-value"><?= sanitize($purchase['supplier_email']) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($purchase['supplier_address'])): ?>
                            <tr><td class="info-label pe-3">Address:</td><td class="info-value"><?= sanitize($purchase['supplier_address']) ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($purchase['supplier_gstin'])): ?>
                            <tr><td class="info-label pe-3">GSTIN:</td><td class="info-value"><?= sanitize($purchase['supplier_gstin']) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6 text-md-end">
                    <h6 class="text-muted mb-2">Bill Details</h6>
                    <table class="ms-auto">
                        <tr><td class="info-label pe-3">Bill No:</td><td class="info-value fw-bold"><?= sanitize($purchase['bill_no']) ?></td></tr>
                        <?php if (!empty($purchase['supplier_bill_no'])): ?>
                            <tr><td class="info-label pe-3">Supplier Bill:</td><td class="info-value"><?= sanitize($purchase['supplier_bill_no']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="info-label pe-3">Date:</td><td class="info-value"><?= dateFormatted($purchase['date']) ?></td></tr>
                        <tr><td class="info-label pe-3">Status:</td><td class="info-value">
                            <?php if ($purchase['payment_status'] === 'paid'): ?>
                                <span class="badge bg-success">Paid</span>
                            <?php elseif ($purchase['payment_status'] === 'partial'): ?>
                                <span class="badge bg-warning text-dark">Partial</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Unpaid</span>
                            <?php endif; ?>
                        </td></tr>
                        <tr><td class="info-label pe-3">Payment:</td><td class="info-value"><?= ucfirst($purchase['payment_method'] ?? '-') ?></td></tr>
                    </table>
                </div>
            </div>

            <div class="table-responsive mb-4">
                <table class="table bill-table">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Item</th>
                            <th>SKU</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Discount</th>
                            <th class="text-end">Tax %</th>
                            <th class="text-end">Tax Amt</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $idx => $item): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td><?= sanitize($item['item_name'] ?? '-') ?></td>
                                <td><small><?= sanitize($item['sku'] ?? '') ?></small></td>
                                <td class="text-end"><?= $item['qty'] ?> <?= sanitize($item['unit'] ?? '') ?></td>
                                <td class="text-end"><?= money($item['rate']) ?></td>
                                <td class="text-end"><?= money($item['discount']) ?></td>
                                <td class="text-end"><?= num($item['tax_rate']) ?>%</td>
                                <td class="text-end"><?= money($item['tax_amount']) ?></td>
                                <td class="text-end fw-bold"><?= money($item['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="row justify-content-end mb-4">
                <div class="col-md-4">
                    <table class="table table-sm">
                        <tr>
                            <td class="text-end">Subtotal:</td>
                            <td class="text-end"><?= money($purchase['subtotal']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-end">Tax:</td>
                            <td class="text-end"><?= money($purchase['tax_amount']) ?></td>
                        </tr>
                        <?php if ($purchase['discount_amount'] > 0): ?>
                            <tr>
                                <td class="text-end">Discount:</td>
                                <td class="text-end text-danger">-<?= money($purchase['discount_amount']) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr class="table-active">
                            <td class="text-end fw-bold">Grand Total:</td>
                            <td class="text-end fw-bold fs-5"><?= money($purchase['total']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-end text-success">Paid:</td>
                            <td class="text-end text-success fw-bold"><?= money($purchase['paid_amount']) ?></td>
                        </tr>
                        <?php if ($purchase['due_amount'] > 0): ?>
                            <tr>
                                <td class="text-end text-danger">Due:</td>
                                <td class="text-end text-danger fw-bold"><?= money($purchase['due_amount']) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if (!empty($purchase['notes'])): ?>
                <div class="mb-4">
                    <h6 class="text-muted">Notes</h6>
                    <p class="mb-0"><?= nl2br(sanitize($purchase['notes'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($payments)): ?>
                <div class="mt-4">
                    <h5 class="mb-3"><i class="fas fa-money-bill-wave me-2"></i>Payment History</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Payment No</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $idx => $pay): ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><strong><?= sanitize($pay['payment_no']) ?></strong></td>
                                        <td><?= dateFormatted($pay['date']) ?></td>
                                        <td class="text-end text-success fw-bold"><?= money($pay['amount']) ?></td>
                                        <td><span class="badge bg-light text-dark"><?= ucfirst($pay['payment_method']) ?></span></td>
                                        <td><?= sanitize($pay['reference_no'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
