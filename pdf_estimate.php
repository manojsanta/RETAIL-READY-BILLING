<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'vendor/autoload.php';
requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;

$id = intval($_GET['id'] ?? 0);
$est = fetch("SELECT e.*, p.name as party_name, p.phone as party_phone, p.email as party_email,
    p.address as party_address, p.city as party_city, p.state as party_state, p.gstin as party_gstin,
    p.pincode as party_pincode
    FROM estimates e LEFT JOIN parties p ON e.party_id = p.id WHERE e.id = ?", [$id]);

if (!$est) { die('Estimate not found.'); }

$items = fetchAll("SELECT ei.*, i.name as item_name, i.sku as item_sku, i.hsn_code as item_hsn
    FROM estimate_items ei LEFT JOIN items i ON ei.item_id = i.id WHERE ei.estimate_id = ?", [$id]);

$company = getCompany();
$footerText = getSetting('invoice_footer_text', 'Thank you for your business!');

// Load tax rates lookup by rate percentage
$allTaxRates = fetchAll("SELECT name, rate, type FROM tax_rates WHERE status = 1 ORDER BY rate, type");
$taxByRate = [];
foreach ($allTaxRates as $tr) {
    $taxByRate[$tr['rate']][] = $tr;
}

function getTaxLabel($rate, &$taxByRate) {
    if ($rate == 0) return '-';
    $label = '';
    $igst = false;
    $cgst = false;
    $sgst = false;
    $cess = false;
    if (isset($taxByRate[$rate])) {
        foreach ($taxByRate[$rate] as $tr) {
            if ($tr['type'] === 'igst') $igst = $tr['name'];
            if ($tr['type'] === 'cgst') $cgst = $tr;
            if ($tr['type'] === 'sgst') $sgst = $tr;
            if ($tr['type'] === 'cess') $cess = $tr;
        }
    }
    if ($igst) {
        $label = $igst;
    } else {
        $parts = [];
        if ($cgst) $parts[] = $cgst['name'];
        if ($sgst) $parts[] = $sgst['name'];
        if (!empty($parts)) {
            $label = implode(' + ', $parts);
        }
    }
    if ($cess) $label .= ($label ? ' + ' : '') . $cess['name'];
    return $label ?: num($rate) . '%';
}

function numToWords($num) {
    if ($num == 0) return 'Zero';
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $num = abs(round($num * 100)); $paise = $num % 100; $num = intval($num / 100);
    if ($num == 0 && $paise == 0) return 'Zero Rupees';
    $convertH = function($n) use ($ones, $tens) { $r = ''; if ($n >= 100) { $r .= $ones[intval($n / 100)] . ' Hundred '; $n %= 100; } if ($n >= 20) { $r .= $tens[intval($n / 10)] . ' '; $n %= 10; } if ($n > 0) { $r .= $ones[$n] . ' '; } return trim($r); };
    $words = ''; $cr = intval($num / 10000000); $num %= 10000000; $lk = intval($num / 100000); $num %= 100000; $th = intval($num / 1000); $num %= 1000;
    if ($cr > 0) $words .= $convertH($cr) . ' Crore '; if ($lk > 0) $words .= $convertH($lk) . ' Lakh '; if ($th > 0) $words .= $convertH($th) . ' Thousand '; if ($num > 0) $words .= $convertH($num);
    return trim($words) . ' Rupees' . ($paise > 0 ? ' and ' . $convertH($paise) . ' Paise' : '') . ' Only';
}

$statusColors = ['accepted' => ['#d4edda','#155724'], 'rejected' => ['#f8d7da','#721c24'], 'converted' => ['#d1ecf1','#0c5460'], 'sent' => ['#fff3cd','#856404'], 'draft' => ['#e2e3e5','#383d41']];
$sc = $statusColors[$est['status']] ?? ['#e2e3e5','#383d41'];

// Round off logic
$grandTotal = (float)$est['total'];
$roundedTotal = round($grandTotal);
$roundOff = $roundedTotal - $grandTotal;

$itemRows = '';
foreach ($items as $idx => $itm) {
    $taxLabel = getTaxLabel($itm['tax_rate'], $taxByRate);
    $itemRows .= '<tr>
        <td>' . ($idx + 1) . '</td>
        <td><strong>' . sanitize($itm['item_name'] ?? 'Deleted Item') . '</strong>' . (!empty($itm['item_sku']) ? '<br><small>' . sanitize($itm['item_sku']) . '</small>' : '') . '</td>
        <td>' . sanitize($itm['item_hsn'] ?? '-') . '</td>
        <td style="text-align:center">' . intval($itm['qty']) . '</td>
        <td style="text-align:right">' . money($itm['rate']) . '</td>
        <td style="text-align:right">' . money($itm['discount']) . '</td>
        <td style="text-align:right">' . money($itm['tax_amount']) . '<br><small>' . $taxLabel . '</small></td>
        <td style="text-align:right;font-weight:bold">' . money($itm['total']) . '</td>
    </tr>';
}

$notesHtml = !empty($est['notes']) ? '<div style="margin-top:15px;padding:12px 15px;background:#fcfcfc;border:1px solid #eee;border-radius:4px;font-size:13px;color:#666;"><strong>Notes:</strong><br>' . nl2br(sanitize($est['notes'])) . '</div>' : '';

$logoPath = !empty($company['logo']) ? __DIR__ . '/uploads/logo/' . $company['logo'] : '';
$logoHtml = ($logoPath && file_exists($logoPath)) ? '<img src="file:///' . str_replace('\\', '/', $logoPath) . '" alt="Logo" style="max-height:50px;margin-bottom:8px;">' : '';
$companyName = sanitize($company['name'] ?? $company['company_name'] ?? 'Your Company');
$companyAddr = !empty($company['address']) ? '<p>' . sanitize($company['address']) . (!empty($company['city']) ? ', ' . sanitize($company['city']) : '') . '</p>' : '';
$companyPhone = !empty($company['phone']) ? '<p>Phone: ' . sanitize($company['phone']) . '</p>' : '';
$companyGstin = !empty($company['gstin']) ? '<p>GSTIN: ' . sanitize($company['gstin']) . '</p>' : '';

$partyName = sanitize($est['party_name'] ?? 'Walk-in Customer');
$partyPhone = !empty($est['party_phone']) ? 'Phone: ' . sanitize($est['party_phone']) . '<br>' : '';
$partyAddrParts = array_filter([$est['party_address'], $est['party_city'], $est['party_state'], $est['party_pincode']]);
$partyAddr = !empty($partyAddrParts) ? sanitize(implode(', ', $partyAddrParts)) . '<br>' : '';
$partyGstin = !empty($est['party_gstin']) ? 'GSTIN: ' . sanitize($est['party_gstin']) : '';

$roundOffSign = $roundOff >= 0 ? '' : '-';
$roundOffClass = $roundOff >= 0 ? '' : 'color:#dc3545;';

$html = '
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #333; margin: 0; padding: 20px; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #eef0f4; }
    .company-info h2 { font-size: 20px; margin: 0 0 4px; color: #1a1a2e; }
    .company-info p { margin: 2px 0; font-size: 12px; color: #555; }
    .meta { text-align: right; }
    .meta h3 { font-size: 16px; color: #2962FF; margin: 0 0 8px; letter-spacing: 1px; }
    .meta p { margin: 2px 0; font-size: 12px; }
    .status-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
    .customer { margin-bottom: 20px; padding: 12px 15px; background: #f8f9fc; border-radius: 6px; font-size: 12px; }
    .customer h4 { font-size: 12px; margin: 0 0 5px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
    .customer p { margin: 2px 0; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 12px; }
    table.items th { background: #f8f9fc; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; color: #666; letter-spacing: 0.3px; border-bottom: 2px solid #e5e7eb; }
    table.items td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    table.items td small { font-size: 10px; color: #888; }
    .totals { margin-left: auto; width: 300px; }
    .totals table { width: 100%; font-size: 13px; }
    .totals td { padding: 4px 0; }
    .grand td { border-top: 2px solid #1a1a2e; padding-top: 8px; font-size: 16px; font-weight: 700; color: #1a1a2e; }
    .signatures { display: flex; justify-content: space-between; margin-top: 35px; padding-top: 10px; }
    .sig-box { width: 200px; }
    .sig-box .line { border-top: 1px solid #333; margin-bottom: 5px; }
    .sig-box p { font-size: 11px; color: #666; margin: 0; }
    .footer { text-align: center; margin-top: 20px; padding-top: 10px; border-top: 1px solid #eee; font-size: 12px; color: #888; }
</style>
</head><body>
    <div class="header">
        <div class="company-info">' . $logoHtml . '<h2>' . $companyName . '</h2>' . $companyAddr . $companyPhone . $companyGstin . '</div>
        <div class="meta">
            <h3>ESTIMATE</h3>
            <p><strong>No:</strong> ' . sanitize($est['estimate_no']) . '</p>
            <p><strong>Date:</strong> ' . dateFormatted($est['date']) . '</p>
            <p><strong>Valid Until:</strong> ' . ($est['valid_until'] ? dateFormatted($est['valid_until']) : '-') . '</p>
            <p><span class="status-badge" style="background:' . $sc[0] . ';color:' . $sc[1] . ';">' . ucfirst($est['status']) . '</span></p>
        </div>
    </div>
    <div class="customer">
        <h4>Bill To:</h4>
        <p><strong>' . $partyName . '</strong></p>
        ' . (!empty($partyAddr) ? '<p>' . $partyAddr . '</p>' : '') . '
        ' . (!empty($partyPhone) ? '<p>' . $partyPhone . '</p>' : '') . '
        ' . (!empty($partyGstin) ? '<p>' . $partyGstin . '</p>' : '') . '
    </div>
    <table class="items">
        <tr><th>#</th><th>Item</th><th>HSN</th><th style="text-align:center">Qty</th><th style="text-align:right">Rate</th><th style="text-align:right">Disc</th><th style="text-align:right">Tax</th><th style="text-align:right">Amount</th></tr>
        ' . $itemRows . '
    </table>
    <div class="totals">
        <table>
            <tr><td style="text-align:right">Subtotal</td><td style="text-align:right;width:100px">' . money($est['subtotal']) . '</td></tr>
            <tr><td style="text-align:right">Total Discount</td><td style="text-align:right;color:#dc3545">-' . money($est['discount_amount']) . '</td></tr>
            <tr><td style="text-align:right">Total Tax</td><td style="text-align:right">' . money($est['tax_amount']) . '</td></tr>
            <tr><td style="text-align:right">Total</td><td style="text-align:right">' . money($grandTotal) . '</td></tr>
            <tr><td style="text-align:right">Round Off</td><td style="text-align:right;' . $roundOffClass . '">' . $roundOffSign . money(abs($roundOff)) . '</td></tr>
            <tr class="grand"><td style="text-align:right">Grand Total</td><td style="text-align:right">' . money($roundedTotal) . '</td></tr>
        </table>
    </div>
    <p style="font-size:12px;color:#555;"><strong>Amount in Words:</strong> ' . numToWords($roundedTotal) . '</p>
    ' . $notesHtml . '
    <div class="signatures">
        <div class="sig-box"><div class="line"></div><p>Authorized Signatory</p></div>
        <div class="sig-box"><div class="line"></div><p>Customer Signature</p></div>
    </div>
    <div class="footer"><p>' . sanitize($footerText) . '</p></div>
</body></html>';

while (ob_get_level()) ob_end_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('logOutputFile', '');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('estimate_' . $est['estimate_no'] . '.pdf', ['Attachment' => false]);