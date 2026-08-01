<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'vendor/autoload.php';
requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;

$id = intval($_GET['id'] ?? 0);
$sale = fetch("SELECT s.*, p.name as party_name, p.phone as party_phone, p.email as party_email,
    p.address as party_address, p.city as party_city, p.state as party_state, p.pincode as party_pincode, p.gstin as party_gstin
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id WHERE s.id = ? AND s.status != 'cancelled'", [$id]);

if (!$sale) { die('Invoice not found.'); }

$items = fetchAll("SELECT si.*, i.name as item_name, i.sku as item_sku, i.hsn_code as item_hsn
    FROM sale_items si LEFT JOIN items i ON si.item_id = i.id WHERE si.sale_id = ?", [$id]);

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

$statusColors = ['paid' => ['#d4edda','#155724'], 'partial' => ['#fff3cd','#856404'], 'unpaid' => ['#f8d7da','#721c24']];
$sc = $statusColors[$sale['payment_status']] ?? ['#e2e3e5','#383d41'];

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

$notesHtml = !empty($sale['notes']) ? '<div style="margin-bottom:12px;padding:10px 14px;background:#fafafa;border:1px solid #eee;border-radius:4px;font-size:12px;color:#1a1a1a;"><strong>Notes:</strong><br>' . nl2br(sanitize($sale['notes'])) . '</div>' : '';

$paidBadge = '';
if ($sale['payment_status'] === 'paid') {
    $paidBadge = '<span style="color:#155724;">Fully Paid</span>';
} elseif ($sale['payment_status'] === 'partial') {
    $paidBadge = '<span style="color:#dc3545;">Due: ' . money($sale['due_amount']) . '</span>';
} else {
    $paidBadge = '<span style="color:#dc3545;">Due: ' . money($sale['due_amount']) . '</span>';
}

$logoHtml = '';
if (!empty($company['logo'])) {
    $logoPath = __DIR__ . '/uploads/logo/' . $company['logo'];
    if (is_file($logoPath)) {
        $imgData = base64_encode(file_get_contents($logoPath));
        $mime = mime_content_type($logoPath);
        $logoHtml = '<img src="data:' . $mime . ';base64,' . $imgData . '" alt="Logo" style="max-height:50px;display:block">';
    }
}
$companyName = sanitize($company['name'] ?? $company['company_name'] ?? 'Your Company');
$companyDetails = array_filter([
    !empty($company['address']) ? sanitize($company['address']) . (!empty($company['city']) ? ', ' . sanitize($company['city']) : '') : '',
    !empty($company['phone']) ? 'Phone: ' . sanitize($company['phone']) : '',
    !empty($company['email']) ? 'Email: ' . sanitize($company['email']) : '',
]);
$companyInfoHtml = !empty($companyDetails) ? '<p style="font-size:11px;color:#555;">' . implode(' | ', $companyDetails) . '</p>' : '';
if (!empty($company['gstin'])) {
    $companyInfoHtml .= '<p style="font-size:11px;color:#555;">GSTIN: ' . sanitize($company['gstin']) . '</p>';
}

$partyName = sanitize($sale['party_name'] ?? 'Walk-in Customer');
$partyPhone = !empty($sale['party_phone']) ? 'Phone: ' . sanitize($sale['party_phone']) . '<br>' : '';
$partyAddrParts = array_filter([$sale['party_address'], $sale['party_city'], $sale['party_state'], $sale['party_pincode']]);
$partyAddr = !empty($partyAddrParts) ? sanitize(implode(', ', $partyAddrParts)) . '<br>' : '';
$partyGstin = !empty($sale['party_gstin']) ? 'GSTIN: ' . sanitize($sale['party_gstin']) : '';

$fontCss = '';
$arialSrc = realpath('C:\Windows\Fonts\ARIAL.TTF');
if ($arialSrc && is_file($arialSrc)) {
    $fontDir = __DIR__ . '/vendor/dompdf/dompdf/lib/fonts/';
    $arialDest = $fontDir . 'Arial.ttf';
    if (!is_file($arialDest)) {
        @copy($arialSrc, $arialDest);
    }
    if (is_file($arialDest)) {
        $arialBoldSrc = realpath('C:\Windows\Fonts\arialbd.ttf');
        $arialBoldCss = '';
        if ($arialBoldSrc && is_file($arialBoldSrc)) {
            $arialBoldDest = $fontDir . 'Arial-Bold.ttf';
            if (!is_file($arialBoldDest)) {
                @copy($arialBoldSrc, $arialBoldDest);
            }
            if (is_file($arialBoldDest)) {
                $arialBoldCss = '@font-face { font-family: "Arial"; src: url("file:///' . str_replace('\\', '/', $arialBoldDest) . '") format("truetype"); font-weight: bold; }';
            }
        }
        $fontCss = '@font-face { font-family: "Arial"; src: url("file:///' . str_replace('\\', '/', $arialDest) . '") format("truetype"); }' . $arialBoldCss;
    }
}

$html = '
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
    ' . $fontCss . '
    @page { margin: 25px 30px; }
    body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 13px; color: #1a1a1a; margin: 0; padding: 0; line-height: 1.5; }
    .header { margin-bottom: 18px; padding-bottom: 14px; border-bottom: 3px solid #e02020; }
    .company-info h2 { font-size: 24px; margin: 0 0 2px; color: #1a1a1a; font-weight: bold; text-transform: uppercase; }
    .company-info p { margin: 1px 0; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
    .meta-center { text-align: center; }
    .meta-center h3 { font-size: 22px; color: #e02020; margin: 0 0 2px; letter-spacing: 2px; font-weight: bold; }
    .meta-details { text-align: center; margin-top: 8px; font-size: 12px; color: #1a1a1a; }
    .meta-details p { margin: 0; }
    .meta-details strong { color: #1a1a1a; }
    .status-badge { display: inline-block; padding: 3px 14px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-top: 4px; }
    .customer { margin-bottom: 14px; padding: 10px 14px; background: #f5f7fa; border-radius: 8px; font-size: 12px; border-left: 4px solid #e02020; }
    .customer h4 { font-size: 11px; margin: 0 0 4px; color: #e02020; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
    .customer p { margin: 1px 0; color: #1a1a1a; }
    .customer strong { color: #1a1a1a; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px; }
    table.items th { background: #f5f7fa; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #1a1a1a; letter-spacing: 0.5px; border-bottom: 2px solid #dfe6e9; font-weight: bold; }
    table.items td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; color: #1a1a1a; }
    table.items td small { font-size: 10px; color: #1a1a1a; }
    .totals { margin-left: auto; width: 250px; }
    .totals table { width: 100%; font-size: 12px; }
    .totals td { padding: 3px 0; color: #1a1a1a; }
    .totals tr:last-child td { border-top: 2px solid #1a1a1a; padding-top: 6px; font-size: 15px; font-weight: bold; color: #1a1a1a; }
    .payment { margin-top: 14px; padding: 10px 14px; background: #fafafa; border: 1px solid #eee; border-radius: 4px; font-size: 12px; color: #1a1a1a; }
    .signature { text-align: right; margin-top: 30px; padding-top: 8px; }
    .signature .line { border-top: 2px solid #1a1a1a; margin-bottom: 4px; width: 200px; margin-left: auto; }
    .signature p { font-size: 11px; color: #1a1a1a; margin: 0; }
    .footer { text-align: center; margin-top: 18px; padding-top: 12px; border-top: 1px solid #dfe6e9; font-size: 11px; color: #1a1a1a; }
</style>
</head><body>
    <div class="header">
        <div class="company-info"><table style="border:none;border-collapse:collapse"><tr><td style="vertical-align:middle;padding:0 14px 0 0;width:1px">' . $logoHtml . '</td><td style="vertical-align:middle;padding:0"><h2>' . $companyName . '</h2>' . $companyInfoHtml . '</td></tr></table></div>
        <div class="meta-center"><h3>TAX INVOICE</h3></div>
        <div class="meta-details">
            <p><strong>Invoice No:</strong> ' . sanitize($sale['invoice_no']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . dateFormatted($sale['date']) . ' &nbsp;&nbsp; <strong>Payment:</strong> ' . ucfirst($sale['payment_method'] ?? 'N/A') . '
            ' . ($sale['payment_status'] !== 'paid' ? ' &nbsp;&nbsp; <span class="status-badge" style="background:' . $sc[0] . ';color:' . $sc[1] . ';">' . ucfirst($sale['payment_status']) . '</span>' : '') . '</p>
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
            <tr><td style="text-align:right">Subtotal</td><td style="text-align:right;width:100px">' . money($sale['subtotal']) . '</td></tr>
            <tr><td style="text-align:right">Total Discount</td><td style="text-align:right;color:#dc3545">-' . money($sale['discount_amount']) . '</td></tr>
            <tr><td style="text-align:right">Total Tax</td><td style="text-align:right">' . money($sale['tax_amount']) . '</td></tr>
            <tr><td style="text-align:right">Grand Total</td><td style="text-align:right">' . money($sale['total']) . '</td></tr>
        </table>
    </div>
    <p style="font-size:12px;color:#1a1a1a;padding:8px 10px;background:#fafafa;border-radius:4px;margin-bottom:12px;"><strong>Amount in Words:</strong> ' . numToWords($sale['total']) . '</p>
    <div class="payment">
        <strong>Payment Details:</strong><br>
        Method: ' . ucfirst($sale['payment_method'] ?? 'N/A') . '<br>
        Amount Paid: ' . money($sale['paid_amount']) . '<br>
        ' . $paidBadge . '
    </div>
    ' . $notesHtml . '
    <div class="signature">
        <div class="line"></div>
        <p>Authorized Signatory</p>
    </div>
    <div class="footer"><p>' . sanitize($footerText) . '</p></div>
</body></html>';

while (ob_get_level()) ob_end_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('allowedProtocols', ['file://' => ['rules' => []], 'data://' => ['rules' => []]]);
$options->set('isHtml5ParserEnabled', true);
$options->set('logOutputFile', '');
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultMediaType', 'print');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('invoice_' . str_replace('/', '-', $sale['invoice_no']) . '.pdf', ['Attachment' => false]);
