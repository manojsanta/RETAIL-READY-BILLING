<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = intval($_GET['id'] ?? 0);
$est = fetch("SELECT e.*, p.name as party_name, p.phone as party_phone, p.email as party_email,
    p.address as party_address, p.city as party_city, p.state as party_state, p.gstin as party_gstin
    FROM estimates e LEFT JOIN parties p ON e.party_id = p.id WHERE e.id = ?", [$id]);

if (!$est) {
    setFlash('danger', 'Estimate not found.');
    header('Location: estimates.php');
    exit;
}

$items = fetchAll("SELECT ei.*, i.name as item_name, i.sku as item_sku, i.hsn_code as item_hsn
    FROM estimate_items ei
    LEFT JOIN items i ON ei.item_id = i.id
    WHERE ei.estimate_id = ?", [$id]);

$company = getCompany();
$footerText = getSetting('invoice_footer_text', 'Thank you for your business!');

$autoPrint = isset($_GET['print']);

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
    $convertH = function($n) use ($ones, $tens) {
        $r = '';
        if ($n >= 100) { $r .= $ones[intval($n / 100)] . ' Hundred '; $n %= 100; }
        if ($n >= 20) { $r .= $tens[intval($n / 10)] . ' '; $n %= 10; }
        if ($n > 0) { $r .= $ones[$n] . ' '; }
        return trim($r);
    };
    $words = '';
    $cr = intval($num / 10000000); $num %= 10000000;
    $lk = intval($num / 100000); $num %= 100000;
    $th = intval($num / 1000); $num %= 1000;
    if ($cr > 0) $words .= $convertH($cr) . ' Crore ';
    if ($lk > 0) $words .= $convertH($lk) . ' Lakh ';
    if ($th > 0) $words .= $convertH($th) . ' Thousand ';
    if ($num > 0) $words .= $convertH($num);
    $words = trim($words) . ' Rupees';
    if ($paise > 0) $words .= ' and ' . $convertH($paise) . ' Paise';
    $words .= ' Only';
    return $words;
}

$pageTitle = 'Estimate - ' . $est['estimate_no'];
include 'header.php';
?>

<?php if ($autoPrint): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
<?php endif; ?>

<style>
.invoice-print { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px 35px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
.invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #eef0f4; }
.company-info h2 { font-size: 22px; margin: 0 0 4px; color: #1a1a2e; }
.company-info p { margin: 2px 0; font-size: 13px; color: #555; }
.invoice-meta { text-align: right; }
.invoice-meta h3 { font-size: 18px; color: #2962FF; margin: 0 0 10px; letter-spacing: 1px; }
.invoice-meta p { margin: 3px 0; font-size: 13px; }
.customer-info { margin-bottom: 25px; padding: 15px 18px; background: #f8f9fc; border-radius: 8px; font-size: 13px; }
.customer-info h4 { font-size: 14px; margin: 0 0 6px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
.items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px; }
.items-table th { background: #f8f9fc; padding: 10px 12px; text-align: left; font-size: 12px; text-transform: uppercase; color: #666; letter-spacing: 0.3px; border-bottom: 2px solid #e5e7eb; }
.items-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; }
.items-table .text-end { text-align: right; }
.items-table .text-center { text-align: center; }
.invoice-totals { margin-left: auto; width: 320px; margin-bottom: 20px; }
.invoice-totals table { width: 100%; font-size: 14px; }
.invoice-totals td { padding: 5px 0; }
.invoice-totals .grand-total td { border-top: 2px solid #1a1a2e; padding-top: 10px; font-size: 18px; font-weight: 700; color: #1a1a2e; }
.signature-area { display: flex; margin-top: 40px; padding-top: 10px; }
.signature-box { width: 200px; }
.signature-box .line { border-top: 1px solid #333; margin-bottom: 6px; }
.signature-box p { font-size: 12px; color: #666; margin: 0; }
.invoice-footer { text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #eee; font-size: 13px; color: #888; }
.status-badge { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
@media print {
    .no-print, .sidebar, .top-navbar, .footer-bar, .main-content .page-header { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
    .invoice-print { max-width: 100%; padding: 15px; box-shadow: none; border-radius: 0; }
    body { background: #fff; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h5 class="mb-0">Estimate <?= sanitize($est['estimate_no']) ?></h5>
    <div class="d-flex gap-2">
        <a href="estimates.php?mode=form&edit=<?= $est['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a>
        <button type="button" class="btn btn-info btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i> Print</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.location.href='estimate_view.php?id=<?= $est['id'] ?>&print=1'"><i class="fas fa-file-pdf me-1"></i> PDF</button>
        <button type="button" class="btn btn-outline-info btn-sm" onclick="sendEstimateEmail(<?= $est['id'] ?>, '<?= sanitize($est['party_name'] ?? '') ?>')"><i class="fas fa-envelope me-1"></i> Email</button>
        <a href="estimates.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back</a>
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
            <h3>ESTIMATE</h3>
            <p><strong>Estimate No:</strong> <?= sanitize($est['estimate_no']) ?></p>
            <p><strong>Date:</strong> <?= dateFormatted($est['date']) ?></p>
            <p><strong>Valid Until:</strong> <?= $est['valid_until'] ? dateFormatted($est['valid_until']) : '-' ?></p>
            <p>
                <span class="status-badge" style="background:<?= $est['status'] === 'accepted' ? '#d4edda' : ($est['status'] === 'rejected' ? '#f8d7da' : ($est['status'] === 'converted' ? '#d1ecf1' : ($est['status'] === 'sent' ? '#fff3cd' : '#e2e3e5'))) ?>;color:<?= $est['status'] === 'accepted' ? '#155724' : ($est['status'] === 'rejected' ? '#721c24' : ($est['status'] === 'converted' ? '#0c5460' : ($est['status'] === 'sent' ? '#856404' : '#383d41'))) ?>;">
                    <?= ucfirst($est['status']) ?>
                </span>
            </p>
        </div>
    </div>

    <div class="customer-info">
        <h4>To:</h4>
        <strong><?= sanitize($est['party_name'] ?? 'Walk-in Customer') ?></strong><br>
        <?php if (!empty($est['party_phone'])): ?>
            Phone: <?= sanitize($est['party_phone']) ?><br>
        <?php endif; ?>
        <?php
        $addrParts = array_filter([$est['party_address'], $est['party_city'], $est['party_state'], $est['party_pincode']]);
        if (!empty($addrParts)): ?>
            <?= sanitize(implode(', ', $addrParts)) ?><br>
        <?php endif; ?>
        <?php if (!empty($est['party_gstin'])): ?>
            GSTIN: <?= sanitize($est['party_gstin']) ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($est['purpose'])): ?>
        <div style="margin-bottom:10px;"><strong>Purpose:</strong> <?= sanitize($est['purpose']) ?></div>
    <?php endif; ?>
    <?php if (!empty($est['service_needed'])): ?>
        <div style="margin-bottom:8px;"><strong>Service Needed:</strong> <?= nl2br(sanitize($est['service_needed'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($est['purpose']) || !empty($est['service_needed'])): ?>
        <div style="margin-bottom:15px;padding:10px 14px;background:#fcfcfc;border-left:3px solid #2962FF;border-radius:4px;font-size:13px;color:#555;">
            We are pleased to submit our estimate<?= !empty($est['purpose']) ? ' for ' . sanitize($est['purpose']) : '' ?><?= !empty($est['service_needed']) ? ' as per your requirement for ' . sanitize($est['service_needed']) : '' ?>. Please find the detailed breakdown below.
        </div>
    <?php endif; ?>

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
            <tr><td class="text-end">Subtotal</td><td class="text-end"><?= money($est['subtotal']) ?></td></tr>
            <tr><td class="text-end">Total Discount</td><td class="text-end text-danger">-<?= money($est['discount_amount']) ?></td></tr>
            <tr><td class="text-end">Total Tax</td><td class="text-end"><?= money($est['tax_amount']) ?></td></tr>
            <tr class="grand-total"><td class="text-end">Grand Total</td><td class="text-end"><?= money($est['total']) ?></td></tr>
        </table>
    </div>

    <p style="font-size:13px;color:#555;"><strong>Amount in Words:</strong> <?= numToWords($est['total']) ?></p>

    <?php if (!empty($est['notes'])): ?>
        <div style="margin-top:15px;padding:12px 15px;background:#fcfcfc;border-radius:6px;font-size:13px;color:#666;">
            <strong>Notes:</strong><br><?= nl2br(sanitize($est['notes'])) ?>
        </div>
    <?php endif; ?>

    <div style="margin-top:15px;padding:10px 14px;background:#fcfcfc;border:1px solid #eee;border-radius:6px;font-size:12px;color:#666;">
        <strong style="font-size:13px;">Terms &amp; Conditions:</strong>
        <ol style="margin:5px 0 0;padding-left:20px;">
            <li>This estimate is valid for <?= !empty($est['valid_until']) ? dateFormatted($est['valid_until']) : '30 days' ?> from the date of issue.</li>
            <li>Payment terms: 50% advance required to confirm the order. Balance payable upon completion.</li>
            <li>Any additional work not mentioned in this estimate will be charged extra.</li>
            <li>Delivery timeline will be confirmed upon order confirmation and advance payment.</li>
            <li>Warranty/guarantee terms will be as per company policy and manufacturer specifications.</li>
        </ol>
    </div>

    <div class="signature-area" style="justify-content:flex-end;">
        <div class="signature-box">
            <div class="line"></div>
            <p>Authorized Signatory</p>
        </div>
    </div>

    <div class="invoice-footer">
        <p><?= sanitize($footerText) ?></p>
    </div>
</div>

<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-body text-center py-4" id="emailModalBody">
                <div id="emailModalLoading">
                    <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
                    <p class="mb-0 fw-semibold">Sending email...</p>
                </div>
                <div id="emailModalResult" style="display:none;">
                    <div id="emailModalIcon"></div>
                    <h5 class="mt-2" id="emailModalTitle"></h5>
                    <p class="text-muted mb-0" id="emailModalMessage"></p>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center" id="emailModalFooter" style="display:none;">
                <button type="button" class="btn btn-light px-3" style="border-radius:8px;font-size:14px;" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function sendEstimateEmail(id, partyName) {
    var modal = new bootstrap.Modal(document.getElementById('emailModal'));
    document.getElementById('emailModalLoading').style.display = 'block';
    document.getElementById('emailModalResult').style.display = 'none';
    document.getElementById('emailModalFooter').style.display = 'none';
    modal.show();

    var formData = new FormData();
    formData.append('id', id);

    fetch('api/send_estimate_email.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json().then(function(data) { return {status: r.status, data: data}; }); })
    .then(function(result) {
        document.getElementById('emailModalLoading').style.display = 'none';
        document.getElementById('emailModalResult').style.display = 'block';
        document.getElementById('emailModalFooter').style.display = 'flex';
        var icon = document.getElementById('emailModalIcon');
        var title = document.getElementById('emailModalTitle');
        var msg = document.getElementById('emailModalMessage');
        if (result.data.success) {
            icon.innerHTML = '<i class="fas fa-check-circle text-success" style="font-size:3rem;"></i>';
            title.textContent = 'Email Sent!';
            msg.textContent = result.data.message;
        } else {
            icon.innerHTML = '<i class="fas fa-exclamation-circle text-danger" style="font-size:3rem;"></i>';
            title.textContent = 'Failed to Send';
            msg.textContent = result.data.error || 'Could not send email.';
        }
    })
    .catch(function() {
        document.getElementById('emailModalLoading').style.display = 'none';
        document.getElementById('emailModalResult').style.display = 'block';
        document.getElementById('emailModalFooter').style.display = 'flex';
        document.getElementById('emailModalIcon').innerHTML = '<i class="fas fa-exclamation-circle text-danger" style="font-size:3rem;"></i>';
        document.getElementById('emailModalTitle').textContent = 'Network Error';
        document.getElementById('emailModalMessage').textContent = 'Could not connect to server.';
    });
}
</script>

<?php include 'footer.php'; ?>
