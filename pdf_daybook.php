<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'vendor/autoload.php';
requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$selectedDate = dateDB($selectedDate);

$openingBalance = (float) getSetting('cash_in_hand', '0');

$transactions = [];

$salesRows = fetchAll("SELECT s.id, s.invoice_no, s.total, s.paid_amount, s.payment_method, s.payment_status,
    p.name as party_name, s.created_at
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id
    WHERE s.date = ? AND s.status != 'cancelled' ORDER BY s.created_at ASC", [$selectedDate]);
foreach ($salesRows as $sr) {
    $transactions[] = [
        'time' => $sr['created_at'],
        'type' => 'sale',
        'party' => $sr['party_name'] ?: 'Walk-in',
        'description' => 'Sale Invoice ' . $sr['invoice_no'],
        'debit' => 0,
        'credit' => (float) $sr['total'],
        'method' => $sr['payment_method'] ?: 'cash',
    ];
}

$purchaseRows = fetchAll("SELECT pu.id, pu.bill_no, pu.total, pu.paid_amount, pu.payment_method,
    p.name as party_name, pu.created_at
    FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
    WHERE pu.date = ? AND pu.status != 'cancelled' ORDER BY pu.created_at ASC", [$selectedDate]);
foreach ($purchaseRows as $pr) {
    $transactions[] = [
        'time' => $pr['created_at'],
        'type' => 'purchase',
        'party' => $pr['party_name'] ?: '-',
        'description' => 'Purchase Bill ' . $pr['bill_no'],
        'debit' => (float) $pr['total'],
        'credit' => 0,
        'method' => $pr['payment_method'] ?: 'cash',
    ];
}

$payInRows = fetchAll("SELECT pi.receipt_no, pi.amount, pi.payment_method, pi.reference_no,
    p.name as party_name, pi.created_at
    FROM payments_in pi LEFT JOIN parties p ON pi.party_id = p.id
    WHERE pi.date = ? ORDER BY pi.created_at ASC", [$selectedDate]);
foreach ($payInRows as $pi) {
    $transactions[] = [
        'time' => $pi['created_at'],
        'type' => 'payment_in',
        'party' => $pi['party_name'] ?: '-',
        'description' => 'Payment In ' . $pi['receipt_no'] . ($pi['reference_no'] ? ' (Ref: ' . $pi['reference_no'] . ')' : ''),
        'debit' => 0,
        'credit' => (float) $pi['amount'],
        'method' => $pi['payment_method'],
    ];
}

$payOutRows = fetchAll("SELECT po.payment_no, po.amount, po.payment_method, po.reference_no,
    p.name as party_name, po.created_at
    FROM payments_out po LEFT JOIN parties p ON po.party_id = p.id
    WHERE po.date = ? ORDER BY po.created_at ASC", [$selectedDate]);
foreach ($payOutRows as $po) {
    $transactions[] = [
        'time' => $po['created_at'],
        'type' => 'payment_out',
        'party' => $po['party_name'] ?: '-',
        'description' => 'Payment Out ' . $po['payment_no'] . ($po['reference_no'] ? ' (Ref: ' . $po['reference_no'] . ')' : ''),
        'debit' => (float) $po['amount'],
        'credit' => 0,
        'method' => $po['payment_method'],
    ];
}

$expenseRows = fetchAll("SELECT e.expense_no, e.amount, e.payment_method, e.reference_no, e.notes,
    ec.name as category_name, e.created_at
    FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
    WHERE e.date = ? ORDER BY e.created_at ASC", [$selectedDate]);
foreach ($expenseRows as $er) {
    $transactions[] = [
        'time' => $er['created_at'],
        'type' => 'expense',
        'party' => $er['category_name'] ?: 'Uncategorized',
        'description' => 'Expense ' . $er['expense_no'] . ($er['notes'] ? ' - ' . mb_strimwidth($er['notes'], 0, 30, '...') : ''),
        'debit' => (float) $er['amount'],
        'credit' => 0,
        'method' => $er['payment_method'],
    ];
}

$returnRows = fetchAll("SELECT sr.return_no, sr.total,
    p.name as party_name, sr.created_at
    FROM sale_returns sr LEFT JOIN parties p ON sr.party_id = p.id
    WHERE sr.date = ? AND sr.status = 'approved' ORDER BY sr.created_at ASC", [$selectedDate]);
foreach ($returnRows as $rr) {
    $transactions[] = [
        'time' => $rr['created_at'],
        'type' => 'sale_return',
        'party' => $rr['party_name'] ?: '-',
        'description' => 'Sale Return ' . $rr['return_no'],
        'debit' => (float) $rr['total'],
        'credit' => 0,
        'method' => '-',
    ];
}

$purchaseReturnRows = fetchAll("SELECT pr.return_no, pr.total,
    p.name as party_name, pr.created_at
    FROM purchase_returns pr LEFT JOIN parties p ON pr.party_id = p.id
    WHERE pr.date = ? AND pr.status = 'approved' ORDER BY pr.created_at ASC", [$selectedDate]);
foreach ($purchaseReturnRows as $prr) {
    $transactions[] = [
        'time' => $prr['created_at'],
        'type' => 'purchase_return',
        'party' => $prr['party_name'] ?: '-',
        'description' => 'Purchase Return ' . $prr['return_no'],
        'debit' => 0,
        'credit' => (float) $prr['total'],
        'method' => '-',
    ];
}

usort($transactions, function($a, $b) {
    return strtotime($a['time']) <=> strtotime($b['time']);
});

$runningBalance = $openingBalance;
foreach ($transactions as &$t) {
    $runningBalance = $runningBalance + $t['credit'] - $t['debit'];
    $t['running_balance'] = round($runningBalance, 2);
}
unset($t);

$closingBalance = $runningBalance;
$totalDebits = array_sum(array_column($transactions, 'debit'));
$totalCredits = array_sum(array_column($transactions, 'credit'));

$typeLabels = [
    'sale' => 'Sale',
    'purchase' => 'Purchase',
    'payment_in' => 'Payment In',
    'payment_out' => 'Payment Out',
    'expense' => 'Expense',
    'sale_return' => 'Sale Return',
    'purchase_return' => 'Purchase Return',
];
$typeColors = [
    'sale' => '#0d6efd',
    'purchase' => '#856404',
    'payment_in' => '#157347',
    'payment_out' => '#b02a37',
    'expense' => '#41464b',
    'sale_return' => '#0c5460',
    'purchase_return' => '#0c5460',
];

$rows = '';
foreach ($transactions as $t) {
    $typeLabel = $typeLabels[$t['type']] ?? ucwords(str_replace('_', ' ', $t['type']));
    $typeColor = $typeColors[$t['type']] ?? '#41464b';
    $balColor = $t['running_balance'] >= 0 ? '#1a1a1a' : '#dc3545';
    $debit = $t['debit'] > 0 ? money($t['debit']) : '-';
    $credit = $t['credit'] > 0 ? money($t['credit']) : '-';
    $rows .= '<tr>
        <td style="text-align:center">' . date('h:i A', strtotime($t['time'])) . '</td>
        <td><span style="background:' . $typeColor . ';color:#fff;padding:1px 8px;border-radius:10px;font-size:9px;font-weight:bold;">' . $typeLabel . '</span></td>
        <td>' . sanitize($t['party']) . '</td>
        <td>' . sanitize($t['description']) . '</td>
        <td style="text-align:center">' . ucfirst(sanitize($t['method'])) . '</td>
        <td style="text-align:right;color:#dc3545;font-weight:bold">' . $debit . '</td>
        <td style="text-align:right;color:#157347;font-weight:bold">' . $credit . '</td>
        <td style="text-align:right;font-weight:bold;color:' . $balColor . '">' . money($t['running_balance']) . '</td>
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
        <div class="meta-center"><h3>DAY BOOK</h3></div>
        <div class="meta-details">
            <p><strong>Date:</strong> ' . date('d M Y', strtotime($selectedDate)) . ' &nbsp;&nbsp; <strong>Entries:</strong> ' . count($transactions) . '</p>
        </div>
    </div>
    <div class="summary">
        <table>
            <tr>
                <td class="label" style="width:25%">Opening Balance</td>
                <td style="width:25%;text-align:right;font-weight:bold">' . money($openingBalance) . '</td>
                <td class="label" style="width:25%">Total Debits</td>
                <td style="width:25%;text-align:right;color:#dc3545;font-weight:bold">' . money($totalDebits) . '</td>
            </tr>
            <tr>
                <td class="label">Total Credits</td>
                <td style="text-align:right;color:#157347;font-weight:bold">' . money($totalCredits) . '</td>
                <td class="label">Closing Balance</td>
                <td style="text-align:right;font-weight:bold">' . money($closingBalance) . '</td>
            </tr>
        </table>
    </div>
    <table class="items">
        <tr><th style="width:64px">Time</th><th style="width:88px">Type</th><th>Party / Category</th><th>Description</th><th style="width:60px;text-align:center">Method</th><th style="width:80px;text-align:right">Debit</th><th style="width:80px;text-align:right">Credit</th><th style="width:90px;text-align:right">Running Balance</th></tr>
        ' . $rows . '
        <tr class="grand-total">
            <td colspan="5" style="text-align:right">Total</td>
            <td style="text-align:right;color:#dc3545">' . money($totalDebits) . '</td>
            <td style="text-align:right;color:#157347">' . money($totalCredits) . '</td>
            <td style="text-align:right">' . money($closingBalance) . '</td>
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
$dompdf->stream('daybook_' . $selectedDate . '.pdf', ['Attachment' => false]);
