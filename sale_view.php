<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
$sale = fetch("SELECT s.*, p.name as party_name, p.phone as party_phone, p.email as party_email,
    p.address as party_address, p.city as party_city, p.state as party_state, p.gstin as party_gstin
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id WHERE s.id = ?", [$id]);

if (!$sale) {
    setFlash('danger', 'Invoice not found.');
    header('Location: sales.php');
    exit;
}

$items = fetchAll("SELECT si.*, i.name as item_name, i.sku as item_sku, i.hsn_code as item_hsn
    FROM sale_items si
    LEFT JOIN items i ON si.item_id = i.id
    WHERE si.sale_id = ?", [$id]);

$payments = fetchAll("SELECT * FROM payments_in WHERE sale_id = ? ORDER BY date ASC", [$id]);

$company = getCompany();
$footerText = getSetting('invoice_footer_text', 'Thank you for your business!');

// Auto-print on ?print=1
$autoPrint = isset($_GET['print']);

// Number to words
function numToWords($num) {
    if ($num == 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $num = abs(round($num * 100));
    $paise = $num % 100;
    $num = intval($num / 100);
    if ($num == 0 && $paise == 0) return 'Zero Rupees';
    function convertH($n) {
        global $ones, $tens;
        $r = '';
        if ($n >= 100) { $r .= $ones[intval($n / 100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20) { $r .= $tens[intval($n / 10)] . ' '; $n %= 10; }
        if ($n > 0) { $r .= $ones[$n] . ' '; }
        return trim($r);
    }
    $words = '';
    $cr = intval($num / 10000000); $num %= 10000000;
    $lk = intval($num / 100000); $num %= 100000;
    $th = intval($num / 1000); $num %= 1000;
    if ($cr > 0) $words .= convertH($cr) . ' Crore ';
    if ($lk > 0) $words .= convertH($lk) . ' Lakh ';
    if ($th > 0) $words .= convertH($th) . ' Thousand ';
    if ($num > 0) $words .= convertH($num);
    $words = trim($words) . ' Rupees';
    if ($paise > 0) $words .= ' and ' . convertH($paise) . ' Paise';
    $words .= ' Only';
    return $words;
}

$pageTitle = 'Sale Invoice - ' . $sale['invoice_no'];
include 'header.php';
?>

<?php if ($autoPrint): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
<?php endif; ?>

<div class="d-none d-print-block text-center mb-3" style="font-size:12px;color:#666;">Tax Invoice</div>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h5 class="mb-0">Invoice <?= sanitize($sale['invoice_no']) ?></h5>
    <div class="d-flex gap-2">
        <?php if ($sale['payment_status'] !== 'paid'): ?>
            <a href="payment_in.php?sale_id=<?= $sale['id'] ?>&party_id=<?= $sale['party_id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-money-bill me-1"></i> Add Payment</a>
        <?php endif; ?>
        <button type="button" class="btn btn-info btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button>
        <a href="pdf_sale.php?id=<?= $sale['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="fas fa-file-pdf me-1"></i> PDF</a>
        <a href="sales.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<div class="invoice-print">
    <div class="invoice-header">
        <div class="company-info">
            <?php if (!empty($company['logo'])): ?>
                <img src="uploads/logo/<?= sanitize($company['logo']) ?>" alt="Logo" style="max-height:50px;margin-bottom:8px;">
            <?php endif; ?>
            <h2><?= sanitize($company['name'] ?? $company['company_name'] ?? 'Your Company') ?></h2>
            <?php if (!empty($company['address'])): ?>
                <p><?= sanitize($company['address']) ?><?= !empty($company['city']) ? ', ' . sanitize($company['city']) : '' ?></p>
            <?php endif; ?>
            <?php if (!empty($company['phone'])): ?>
                <p>Phone: <?= sanitize($company['phone']) ?></p>
            <?php endif; ?>
            <?php if (!empty($company['gstin'])): ?>
                <p>GSTIN: <?= sanitize($company['gstin']) ?></p>
            <?php endif; ?>
        </div>
        <div class="invoice-meta">
            <h3>TAX INVOICE</h3>
            <p><strong>Invoice:</strong> <?= sanitize($sale['invoice_no']) ?></p>
            <p><strong>Date:</strong> <?= dateFormatted($sale['date']) ?></p>
            <p><strong>Payment:</strong> <?= ucfirst($sale['payment_method'] ?? 'N/A') ?></p>
        </div>
    </div>

    <div class="customer-info">
        <h4>Bill To:</h4>
        <strong><?= sanitize($sale['party_name'] ?? 'Walk-in Customer') ?></strong><br>
        <?php if (!empty($sale['party_phone'])): ?>
            Phone: <?= sanitize($sale['party_phone']) ?><br>
        <?php endif; ?>
        <?php if (!empty($sale['party_address'])): ?>
            <?= sanitize($sale['party_address']) ?><?= !empty($sale['party_city']) ? ', ' . sanitize($sale['party_city']) : '' ?><br>
        <?php endif; ?>
        <?php if (!empty($sale['party_gstin'])): ?>
            GSTIN: <?= sanitize($sale['party_gstin']) ?>
        <?php endif; ?>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th>HSN</th>
                <th class="text-center">Qty</th>
                <th class="text-end">Rate</th>
                <th class="text-end">Disc</th>
                <th class="text-end">Tax</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $itm): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td>
                        <strong><?= sanitize($itm['item_name'] ?? 'Deleted Item') ?></strong>
                        <?php if (!empty($itm['item_sku'])): ?>
                            <br><small class="text-muted"><?= sanitize($itm['item_sku']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($itm['item_hsn'] ?? '-') ?></td>
                    <td class="text-center"><?= intval($itm['qty']) ?></td>
                    <td class="text-end"><?= money($itm['rate']) ?></td>
                    <td class="text-end"><?= money($itm['discount']) ?></td>
                    <td class="text-end"><?= num($itm['tax_amount']) ?> (<?= num($itm['tax_rate']) ?>%)</td>
                    <td class="text-end fw-bold"><?= money($itm['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="invoice-totals">
        <table>
            <tr><td class="text-end">Subtotal</td><td class="text-end"><?= money($sale['subtotal']) ?></td></tr>
            <tr><td class="text-end">Total Discount</td><td class="text-end text-danger">-<?= money($sale['discount_amount']) ?></td></tr>
            <tr><td class="text-end">Total Tax</td><td class="text-end"><?= money($sale['tax_amount']) ?></td></tr>
            <tr class="grand-total"><td class="text-end">Grand Total</td><td class="text-end"><?= money($sale['total']) ?></td></tr>
        </table>
    </div>

    <p style="font-size:13px;color:#555;"><strong>Amount in Words:</strong> <?= numToWords($sale['total']) ?></p>

    <div class="row mb-3" style="font-size:13px;">
        <div class="col-md-6">
            <strong>Payment Details:</strong><br>
            Method: <?= ucfirst($sale['payment_method'] ?? 'N/A') ?><br>
            Amount Paid: <?= money($sale['paid_amount']) ?><br>
            <?php if ($sale['due_amount'] > 0): ?>
                <span class="text-danger fw-bold">Due: <?= money($sale['due_amount']) ?></span>
            <?php else: ?>
                <span class="text-success fw-bold">Fully Paid</span>
            <?php endif; ?>
        </div>
        <div class="col-md-6 text-end">
            <strong>Status:</strong>
            <?php if ($sale['payment_status'] === 'paid'): ?>
                <span class="badge bg-success">Paid</span>
            <?php elseif ($sale['payment_status'] === 'partial'): ?>
                <span class="badge bg-warning text-dark">Partial</span>
            <?php else: ?>
                <span class="badge bg-danger">Unpaid</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="signature-area">
        <div class="signature-box">
            <div class="line"></div>
            <p>Authorized Signatory</p>
        </div>
        <div class="signature-box">
            <div class="line"></div>
            <p>Customer Signature</p>
        </div>
    </div>

    <div class="invoice-footer">
        <p><?= sanitize($footerText) ?></p>
    </div>
</div>

<?php if (!empty($payments)): ?>
<div class="card mt-3 no-print">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-money-bill me-1"></i> Payment History</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Receipt No</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pmt): ?>
                    <tr>
                        <td><?= sanitize($pmt['receipt_no']) ?></td>
                        <td><?= dateFormatted($pmt['date']) ?></td>
                        <td class="text-success fw-bold"><?= money($pmt['amount']) ?></td>
                        <td><?= ucfirst($pmt['payment_method']) ?></td>
                        <td><?= sanitize($pmt['reference_no'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .no-print, .sidebar, .navbar, .footer-bar { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
    .invoice-print { max-width: 100%; padding: 15px; box-shadow: none; }
    body { background: #fff; }
}
</style>

<?php include 'footer.php'; ?>
