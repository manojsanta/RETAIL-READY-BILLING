<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'cashbook_data.php';
require_once 'vendor/autoload.php';
requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;

$fy = currentFY();
$allowedMethods = ['all', 'cash', 'bank', 'upi', 'cheque'];

$from = $_GET['from_date'] ?? ($fy['start'] ?: date('Y-m-01'));
$to = $_GET['to_date'] ?? date('Y-m-d');
$method = $_GET['method'] ?? 'all';
if (!in_array($method, $allowedMethods, true)) $method = 'all';

$from = dateDB($from);
$to = dateDB($to);

$data = getCashbookData($from, $to, $method);
$rows = $data['rows'];

$runningBalance = $data['opening'];
foreach ($rows as &$r) {
    $runningBalance += ($r['dir'] === 'in' ? $r['amount'] : -$r['amount']);
    $r['running_balance'] = round($runningBalance, 2);
}
unset($r);

$modeLabel = $method === 'all' ? 'Cash & Bank' : cashModeLabel($method);

$rowsHtml = '';
foreach ($rows as $r) {
    $balColor = $r['running_balance'] >= 0 ? '#1a1a1a' : '#dc3545';
    $in = $r['dir'] === 'in' ? money($r['amount']) : '-';
    $out = $r['dir'] === 'out' ? money($r['amount']) : '-';
    $rowsHtml .= '<tr>
        <td style="text-align:center">' . date('d M Y', strtotime($r['date'])) . '</td>
        <td style="text-align:center">' . sanitize($r['ref']) . '</td>
        <td>' . sanitize($r['desc']) . '</td>
        <td style="text-align:center">' . cashModeLabel($r['mode']) . '</td>
        <td style="text-align:right;color:#157347;font-weight:bold">' . $in . '</td>
        <td style="text-align:right;color:#dc3545;font-weight:bold">' . $out . '</td>
        <td style="text-align:right;font-weight:bold;color:' . $balColor . '">' . money($r['running_balance']) . '</td>
    </tr>';
}

$company = getCompany();
$footerText = getSetting('invoice_footer_text', 'Thank you for your business!');

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
    !empty($company['gstin']) ? 'GSTIN: ' . sanitize($company['gstin']) : '',
]);
$companyInfoHtml = !empty($companyDetails) ? '<p style="font-size:11px;color:#555;">' . implode(' | ', $companyDetails) . '</p>' : '';

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
    body { font-family: Arial, DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; margin: 0; padding: 0; line-height: 1.5; }
    .header { margin-bottom: 16px; padding-bottom: 14px; border-bottom: 3px solid #e02020; }
    .company-info h2 { font-size: 22px; margin: 0 0 2px; color: #1a1a1a; font-weight: bold; text-transform: uppercase; }
    .company-info p { margin: 1px 0; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
    .meta-center { text-align: center; }
    .meta-center h3 { font-size: 22px; color: #e02020; margin: 0 0 2px; letter-spacing: 2px; font-weight: bold; }
    .meta-details { text-align: center; margin-top: 6px; font-size: 12px; color: #1a1a1a; }
    .meta-details p { margin: 0; }
    .meta-details strong { color: #1a1a1a; }
    .summary { margin-bottom: 12px; }
    .summary table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .summary td { padding: 5px 10px; border: 1px solid #dfe6e9; }
    .summary .label { background: #f5f7fa; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #555; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
    table.items th { background: #f5f7fa; padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #1a1a1a; letter-spacing: 0.5px; border-bottom: 2px solid #dfe6e9; font-weight: bold; }
    table.items td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; color: #1a1a1a; }
    .grand-total { background: #f5f7fa; font-weight: bold; }
    .footer { text-align: center; margin-top: 16px; padding-top: 10px; border-top: 1px solid #dfe6e9; font-size: 11px; color: #1a1a1a; }
</style>
</head><body>
    <div class="header">
        <div class="company-info"><table style="border:none;border-collapse:collapse"><tr><td style="vertical-align:middle;padding:0 14px 0 0;width:1px">' . $logoHtml . '</td><td style="vertical-align:middle;padding:0"><h2>' . $companyName . '</h2>' . $companyInfoHtml . '</td></tr></table></div>
        <div class="meta-center"><h3>CASH &amp; BANK BOOK</h3></div>
        <div class="meta-details">
            <p><strong>Period:</strong> ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)) . ' &nbsp;&nbsp; <strong>Mode:</strong> ' . $modeLabel . ' &nbsp;&nbsp; <strong>Entries:</strong> ' . count($rows) . '</p>
        </div>
    </div>
    <div class="summary">
        <table>
            <tr>
                <td class="label" style="width:25%">Opening Balance</td>
                <td style="width:25%;text-align:right;font-weight:bold">' . money($data['opening']) . '</td>
                <td class="label" style="width:25%">Total In</td>
                <td style="width:25%;text-align:right;color:#157347;font-weight:bold">' . money($data['totalIn']) . '</td>
            </tr>
            <tr>
                <td class="label">Total Out</td>
                <td style="text-align:right;color:#dc3545;font-weight:bold">' . money($data['totalOut']) . '</td>
                <td class="label">Closing Balance</td>
                <td style="text-align:right;font-weight:bold">' . money($data['closing']) . '</td>
            </tr>
        </table>
    </div>
    <table class="items">
        <tr><th style="width:82px">Date</th><th style="width:86px">Ref / No</th><th>Description</th><th style="width:56px;text-align:center">Mode</th><th style="width:80px;text-align:right">In</th><th style="width:80px;text-align:right">Out</th><th style="width:95px;text-align:right">Running Balance</th></tr>
        <tr>
            <td style="text-align:center">-</td>
            <td style="text-align:center">-</td>
            <td>Opening Balance brought forward</td>
            <td style="text-align:center">-</td>
            <td style="text-align:right">-</td>
            <td style="text-align:right">-</td>
            <td style="text-align:right;font-weight:bold">' . money($data['opening']) . '</td>
        </tr>
        ' . $rowsHtml . '
        <tr class="grand-total">
            <td colspan="4" style="text-align:right">Total</td>
            <td style="text-align:right;color:#157347">' . money($data['totalIn']) . '</td>
            <td style="text-align:right;color:#dc3545">' . money($data['totalOut']) . '</td>
            <td style="text-align:right">' . money($data['closing']) . '</td>
        </tr>
        <tr class="grand-total">
            <td colspan="6" style="text-align:right">Closing Balance</td>
            <td style="text-align:right">' . money($data['closing']) . '</td>
        </tr>
    </table>
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('cashbook_' . $from . '_' . $to . '_' . $method . '.pdf', ['Attachment' => false]);
