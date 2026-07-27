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
        body * { visibility: hidden; }
        .print-section, .print-section * { visibility: visible; }
        .print-section { position: absolute; left: 0; top: 0; width: 100%; padding: 0 15px; font-family: 'Segoe UI', Arial, sans-serif; color: #222; }
        .no-print { display: none !important; }
        @page { margin: 12mm 10mm; size: A4; }
    }
    .print-section { max-width: 800px; margin: 0 auto; font-size: 12px; line-height: 1.4; }
    .inv-header { display:flex; align-items:center; justify-content:space-between; padding-bottom:10px; margin-bottom:12px; border-bottom:3px solid #2c3e50; }
    .inv-header-left { display:flex; align-items:center; gap:12px; }
    .inv-header-left img { height:42px; }
    .inv-header-left .co-name { font-size:16px; font-weight:700; color:#2c3e50; }
    .inv-header-left .co-meta { font-size:10px; color:#888; line-height:1.3; }
    .inv-title { text-align:right; }
    .inv-title h2 { font-size:18px; font-weight:700; color:#2c3e50; margin:0; letter-spacing:1px; }
    .inv-title .inv-no { font-size:11px; color:#888; margin-top:2px; }
    .inv-info { display:flex; gap:16px; margin-bottom:14px; }
    .inv-info-box { flex:1; background:#f8f9fa; border-radius:4px; padding:10px 12px; border-left:3px solid #2c3e50; }
    .inv-info-box h6 { font-size:9px; text-transform:uppercase; letter-spacing:.8px; color:#2c3e50; margin:0 0 6px; font-weight:700; }
    .inv-info-box table { width:100%; border-collapse:collapse; }
    .inv-info-box td { padding:1px 0; font-size:11px; vertical-align:top; }
    .inv-info-box .il { font-weight:600; color:#555; width:80px; }
    .inv-info-box .iv { color:#333; }
    .inv-status { display:inline-block; padding:1px 8px; border-radius:3px; font-size:10px; font-weight:600; }
    .inv-status.paid { background:#d4edda; color:#155724; }
    .inv-status.partial { background:#fff3cd; color:#856404; }
    .inv-status.unpaid { background:#f8d7da; color:#721c24; }
    .inv-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
    .inv-table th { background:#2c3e50; color:#fff; font-size:10px; text-transform:uppercase; letter-spacing:.5px; padding:6px 8px; font-weight:600; }
    .inv-table th:last-child, .inv-table td:last-child { text-align:right; }
    .inv-table td { padding:5px 8px; font-size:11px; border-bottom:1px solid #eee; }
    .inv-table tbody tr:nth-child(even) { background:#fafafa; }
    .inv-table .item-name { font-weight:600; color:#222; }
    .inv-table .item-sku { font-size:10px; color:#999; }
    .inv-totals { display:flex; justify-content:flex-end; margin-bottom:14px; }
    .inv-totals table { width:240px; border-collapse:collapse; }
    .inv-totals td { padding:3px 0; font-size:11px; }
    .inv-totals td:first-child { text-align:right; color:#666; padding-right:12px; }
    .inv-totals td:last-child { text-align:right; font-weight:600; }
    .inv-totals .grand td { font-size:13px; font-weight:700; color:#2c3e50; border-top:2px solid #2c3e50; padding-top:5px; }
    .inv-totals .paid td { color:#155724; }
    .inv-totals .due td { color:#c0392b; font-weight:700; }
    .inv-notes { font-size:10px; color:#888; border-top:1px solid #eee; padding-top:8px; margin-bottom:10px; }
    .inv-payments { margin-top:10px; }
    .inv-payments h6 { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#2c3e50; margin:0 0 6px; font-weight:700; }
    .inv-payments table { width:100%; border-collapse:collapse; font-size:10px; }
    .inv-payments th { background:#f0f0f0; padding:4px 6px; text-align:left; font-size:9px; text-transform:uppercase; }
    .inv-payments td { padding:3px 6px; border-bottom:1px solid #f0f0f0; }
    .inv-footer { text-align:center; font-size:9px; color:#aaa; border-top:1px solid #eee; padding-top:6px; margin-top:10px; }
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

            <div class="inv-header">
                <div class="inv-header-left">
                    <?php if (!empty($company['logo']) && file_exists('uploads/logo/' . $company['logo'])): ?>
                        <img src="uploads/logo/<?= sanitize($company['logo']) ?>" alt="Logo">
                    <?php endif; ?>
                    <div>
                        <div class="co-name"><?= sanitize($company['name'] ?? 'Company Name') ?></div>
                        <div class="co-meta">
                            <?= sanitize($company['address'] ?? '') ?>
                            <?php if (!empty($company['city'])): ?>, <?= sanitize($company['city']) ?><?php endif; ?>
                            <?php if (!empty($company['state'])): ?>, <?= sanitize($company['state']) ?><?php endif; ?>
                            <?php if (!empty($company['pincode'])): ?> - <?= sanitize($company['pincode']) ?><?php endif; ?>
                            <?php if (!empty($company['phone'])): ?> | <?= sanitize($company['phone']) ?><?php endif; ?>
                            <?php if (!empty($company['gstin'])): ?> | GSTIN: <?= sanitize($company['gstin']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="inv-title">
                    <h2>PURCHASE BILL</h2>
                    <div class="inv-no">#<?= sanitize($purchase['bill_no']) ?></div>
                </div>
            </div>

            <div class="inv-info">
                <div class="inv-info-box">
                    <h6>Supplier</h6>
                    <table>
                        <tr><td class="il">Name</td><td class="iv"><?= sanitize($purchase['supplier_name'] ?? 'Walk-in') ?></td></tr>
                        <?php if (!empty($purchase['supplier_phone'])): ?><tr><td class="il">Phone</td><td class="iv"><?= sanitize($purchase['supplier_phone']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($purchase['supplier_email'])): ?><tr><td class="il">Email</td><td class="iv"><?= sanitize($purchase['supplier_email']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($purchase['supplier_address'])): ?><tr><td class="il">Address</td><td class="iv"><?= sanitize($purchase['supplier_address']) ?></td></tr><?php endif; ?>
                        <?php if (!empty($purchase['supplier_gstin'])): ?><tr><td class="il">GSTIN</td><td class="iv"><?= sanitize($purchase['supplier_gstin']) ?></td></tr><?php endif; ?>
                    </table>
                </div>
                <div class="inv-info-box">
                    <h6>Bill Details</h6>
                    <table>
                        <tr><td class="il">Date</td><td class="iv"><?= dateFormatted($purchase['date']) ?></td></tr>
                        <?php if (!empty($purchase['supplier_bill_no'])): ?><tr><td class="il">Supplier Bill</td><td class="iv"><?= sanitize($purchase['supplier_bill_no']) ?></td></tr><?php endif; ?>
                        <tr><td class="il">Status</td><td class="iv"><span class="inv-status <?= $purchase['payment_status'] ?>"><?= ucfirst($purchase['payment_status']) ?></span></td></tr>
                        <tr><td class="il">Payment</td><td class="iv"><?= ucfirst($purchase['payment_method'] ?? '-') ?></td></tr>
                    </table>
                </div>
            </div>

            <table class="inv-table">
                <thead>
                    <tr>
                        <th>#</th><th>Item</th><th>SKU</th>
                        <th style="text-align:right">Qty</th><th style="text-align:right">Rate</th>
                        <th style="text-align:right">Disc</th><th style="text-align:right">Tax%</th>
                        <th style="text-align:right">Tax Amt</th><th style="text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $item): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="item-name"><?= sanitize($item['item_name'] ?? '-') ?></td>
                            <td class="item-sku"><?= sanitize($item['sku'] ?? '') ?></td>
                            <td style="text-align:right"><?= $item['qty'] ?> <?= sanitize($item['unit'] ?? '') ?></td>
                            <td style="text-align:right"><?= money($item['rate']) ?></td>
                            <td style="text-align:right"><?= $item['discount'] > 0 ? money($item['discount']) : '-' ?></td>
                            <td style="text-align:right"><?= num($item['tax_rate']) ?>%</td>
                            <td style="text-align:right"><?= money($item['tax_amount']) ?></td>
                            <td style="text-align:right;font-weight:600"><?= money($item['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="inv-totals">
                <table>
                    <tr><td>Subtotal</td><td><?= money($purchase['subtotal']) ?></td></tr>
                    <tr><td>Tax</td><td><?= money($purchase['tax_amount']) ?></td></tr>
                    <?php if ($purchase['discount_amount'] > 0): ?>
                        <tr><td>Discount</td><td style="color:#c0392b">-<?= money($purchase['discount_amount']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="grand"><td>Grand Total</td><td><?= money($purchase['total']) ?></td></tr>
                    <tr class="paid"><td>Paid</td><td><?= money($purchase['paid_amount']) ?></td></tr>
                    <?php if ($purchase['due_amount'] > 0): ?>
                        <tr class="due"><td>Due</td><td><?= money($purchase['due_amount']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <?php if (!empty($purchase['notes'])): ?>
                <div class="inv-notes"><strong>Notes:</strong> <?= nl2br(sanitize($purchase['notes'])) ?></div>
            <?php endif; ?>

            <?php if (!empty($payments)): ?>
                <div class="inv-payments">
                    <h6>Payment History</h6>
                    <table>
                        <thead><tr><th>#</th><th>Payment No</th><th>Date</th><th style="text-align:right">Amount</th><th>Method</th><th>Reference</th></tr></thead>
                        <tbody>
                            <?php foreach ($payments as $idx => $pay): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><strong><?= sanitize($pay['payment_no']) ?></strong></td>
                                    <td><?= dateFormatted($pay['date']) ?></td>
                                    <td style="text-align:right;font-weight:600;color:#155724"><?= money($pay['amount']) ?></td>
                                    <td><?= ucfirst($pay['payment_method']) ?></td>
                                    <td><?= sanitize($pay['reference_no'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="inv-footer">Thank you for your business</div>

        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
