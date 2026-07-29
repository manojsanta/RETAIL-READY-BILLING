<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
requireLogin();

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

try {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid estimate ID.');
    }

    $est = fetch("SELECT e.*, p.name as party_name, p.phone as party_phone, p.email as party_email,
        p.address as party_address, p.city as party_city, p.state as party_state, p.gstin as party_gstin,
        p.pincode as party_pincode
        FROM estimates e LEFT JOIN parties p ON e.party_id = p.id WHERE e.id = ?", [$id]);

    if (!$est) {
        throw new Exception('Estimate not found.');
    }

    if (empty($est['party_email'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Party does not have an email address.']);
        exit;
    }

    // Load email settings
    $emailSettings = fetch("SELECT * FROM email_settings ORDER BY id DESC LIMIT 1");
    if (!$emailSettings || empty($emailSettings['is_active'])) {
        throw new Exception('Email settings are not configured or inactive. Please configure in Company Settings.');
    }

    $items = fetchAll("SELECT ei.*, i.name as item_name, i.sku as item_sku, i.hsn_code as item_hsn
        FROM estimate_items ei LEFT JOIN items i ON ei.item_id = i.id WHERE ei.estimate_id = ?", [$id]);

    $company = getCompany();
    $footerText = getSetting('invoice_footer_text', 'Thank you for your business!');

    // Tax rates
    $allTaxRates = fetchAll("SELECT name, rate, type FROM tax_rates WHERE status = 1 ORDER BY rate, type");
    $taxByRate = [];
    foreach ($allTaxRates as $tr) {
        $taxByRate[$tr['rate']][] = $tr;
    }

    function getTaxLabelApi($rate, &$taxByRate) {
        if ($rate == 0) return '-';
        $label = '';
        $igst = false; $cgst = false; $sgst = false; $cess = false;
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
            if (!empty($parts)) $label = implode(' + ', $parts);
        }
        if ($cess) $label .= ($label ? ' + ' : '') . $cess['name'];
        return $label ?: num($rate) . '%';
    }

    function numToWordsApi($num) {
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

    $statusColors = ['draft' => ['#e2e3e5','#383d41'], 'sent' => ['#fff3cd','#856404'], 'accepted' => ['#d4edda','#155724'], 'rejected' => ['#f8d7da','#721c24'], 'converted' => ['#d1ecf1','#0c5460']];
    $sc = $statusColors[$est['status']] ?? ['#e2e3e5','#383d41'];
    $grandTotal = (float)$est['total'];
    $roundedTotal = round($grandTotal);
    $roundOff = $roundedTotal - $grandTotal;

    $itemRows = '';
    foreach ($items as $idx => $itm) {
        $taxLabel = getTaxLabelApi($itm['tax_rate'], $taxByRate);
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

    $notesHtml = !empty($est['notes']) ? '<div style="margin-bottom:12px;padding:10px 14px;background:#fafafa;border:1px solid #eee;border-radius:4px;font-size:12px;color:#1a1a1a;"><strong>Notes:</strong><br>' . nl2br(sanitize($est['notes'])) . '</div>' : '';

    $logoHtml = '';
    if (!empty($company['logo'])) {
        $logoPath = __DIR__ . '/../uploads/logo/' . $company['logo'];
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

    $partyName = sanitize($est['party_name'] ?? 'Walk-in Customer');
    $partyPhone = !empty($est['party_phone']) ? 'Phone: ' . sanitize($est['party_phone']) . '<br>' : '';
    $partyAddrParts = array_filter([$est['party_address'], $est['party_city'], $est['party_state'], $est['party_pincode']]);
    $partyAddr = !empty($partyAddrParts) ? sanitize(implode(', ', $partyAddrParts)) . '<br>' : '';
    $partyGstin = !empty($est['party_gstin']) ? 'GSTIN: ' . sanitize($est['party_gstin']) : '';

    $descHtml = '';
    if (!empty($est['purpose']) || !empty($est['service_needed'])) {
        $desc = 'We are pleased to submit our estimate';
        if (!empty($est['purpose'])) $desc .= ' for ' . sanitize($est['purpose']);
        if (!empty($est['service_needed'])) $desc .= ' as per your requirement for ' . sanitize($est['service_needed']);
        $desc .= '. Please find the detailed breakdown below.';
        $descHtml = '<p style="font-size:12px;color:#1a1a1a;margin-bottom:14px;padding:10px 14px;background:#fafafa;border-left:3px solid #e02020;border-radius:4px;">' . $desc . '</p>';
    }

    $termsHtml = '<div style="margin-bottom:12px;padding:10px 14px;background:#fafafa;border:1px solid #eee;border-radius:4px;font-size:11px;color:#1a1a1a;">
        <strong style="font-size:12px;">Terms &amp; Conditions:</strong>
        <ol style="margin:4px 0 0;padding-left:16px;">
            <li>This estimate is valid for ' . (!empty($est['valid_until']) ? dateFormatted($est['valid_until']) : '30 days') . ' from the date of issue.</li>
            <li>Payment terms: 50% advance required to confirm the order. Balance payable upon completion.</li>
            <li>Any additional work not mentioned in this estimate will be charged extra.</li>
            <li>Delivery timeline will be confirmed upon order confirmation and advance payment.</li>
            <li>Warranty/guarantee terms will be as per company policy and manufacturer specifications.</li>
        </ol>
    </div>';

    $roundOffSign = $roundOff >= 0 ? '' : '-';
    $roundOffClass = $roundOff >= 0 ? '' : 'color:#dc3545;';

    $fontCss = '';
    $arialSrc = realpath('C:\Windows\Fonts\ARIAL.TTF');
    if ($arialSrc && is_file($arialSrc)) {
        $fontDir = __DIR__ . '/../vendor/dompdf/dompdf/lib/fonts/';
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
        .header { margin-bottom: 20px; padding-bottom: 18px; border-bottom: 3px solid #e02020; }
        .company-info h2 { font-size: 24px; margin: 0 0 2px; color: #1a1a1a; font-weight: bold; text-transform: uppercase; }
        .company-info p { margin: 1px 0; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
        .meta-center { text-align: center; }
        .meta-center h3 { font-size: 24px; color: #e02020; margin: 0 0 2px; letter-spacing: 2px; font-weight: bold; }
        .meta-details { text-align: center; margin-top: 8px; font-size: 12px; color: #1a1a1a; }
        .meta-details p { margin: 0; }
        .meta-details strong { color: #1a1a1a; }
        .meta { text-align: right; }
        .meta h3 { font-size: 22px; color: #e02020; margin: 0 0 6px; letter-spacing: 2px; font-weight: bold; }
        .meta p { margin: 1px 0; font-size: 12px; color: #1a1a1a; }
        .meta strong { color: #1a1a1a; }
        .status-badge { display: inline-block; padding: 3px 14px; border-radius: 12px; font-size: 11px; font-weight: 700; margin-top: 4px; }
        .customer { margin-bottom: 14px; padding: 10px 14px; background: #f5f7fa; border-radius: 8px; font-size: 12px; border-left: 4px solid #e02020; }
        .customer h4 { font-size: 11px; margin: 0 0 4px; color: #e02020; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
        .customer p { margin: 1px 0; color: #1a1a1a; }
        .customer strong { color: #1a1a1a; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px; }
        table.items th { background: #f5f7fa; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #1a1a1a; letter-spacing: 0.5px; border-bottom: 2px solid #dfe6e9; font-weight: bold; }
        table.items td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; color: #1a1a1a; }
        table.items td small { font-size: 10px; color: #1a1a1a; }
        .totals { margin-left: auto; width: 240px; }
        .totals table { width: 100%; font-size: 12px; }
        .totals td { padding: 3px 0; color: #1a1a1a; }
        .totals tr:last-child td { border-top: 2px solid #1a1a1a; padding-top: 6px; font-size: 15px; font-weight: bold; color: #1a1a1a; }
        .signature { text-align: right; margin-top: 30px; padding-top: 8px; }
        .signature .line { border-top: 2px solid #1a1a1a; margin-bottom: 4px; width: 200px; margin-left: auto; }
        .signature p { font-size: 11px; color: #1a1a1a; margin: 0; }
        .footer { text-align: center; margin-top: 18px; padding-top: 12px; border-top: 1px solid #dfe6e9; font-size: 11px; color: #1a1a1a; }
    </style>
    </head><body>
        <div class="header">
            <div class="company-info"><table style="border:none;border-collapse:collapse"><tr><td style="vertical-align:middle;padding:0 14px 0 0;width:1px">' . $logoHtml . '</td><td style="vertical-align:middle;padding:0"><h2>' . $companyName . '</h2>' . $companyInfoHtml . '</td></tr></table></div>
            <div class="meta-center"><h3>ESTIMATE</h3></div>
            <div class="meta-details">
                <p><strong>No:</strong> ' . sanitize($est['estimate_no']) . ' &nbsp;&nbsp; <strong>Date:</strong> ' . dateFormatted($est['date']) . ' &nbsp;&nbsp; <strong>Valid Until:</strong> ' . ($est['valid_until'] ? dateFormatted($est['valid_until']) : '-') . '
                ' . ($est['status'] !== 'draft' ? ' &nbsp;&nbsp; <span class="status-badge" style="background:' . $sc[0] . ';color:' . $sc[1] . ';">' . ucfirst($est['status']) . '</span>' : '') . '</p>
            </div>
        </div>
        <div class="customer">
            <h4>To:</h4>
            <p><strong>' . $partyName . '</strong></p>
            ' . (!empty($partyAddr) ? '<p>' . $partyAddr . '</p>' : '') . '
            ' . (!empty($partyPhone) ? '<p>' . $partyPhone . '</p>' : '') . '
            ' . (!empty($partyGstin) ? '<p>' . $partyGstin . '</p>' : '') . '
        </div>
        ' . $descHtml . '
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
        <p style="font-size:12px;color:#1a1a1a;padding:8px 10px;background:#fafafa;border-radius:4px;margin-bottom:12px;"><strong>Amount in Words:</strong> ' . numToWordsApi($roundedTotal) . '</p>
        ' . $notesHtml . '
        ' . $termsHtml . '
        <div class="signature">
            <div class="line"></div>
            <p>Authorized Signatory</p>
        </div>
        <div class="footer"><p>' . sanitize($footerText) . '</p></div>
    </body></html>';

    // Generate PDF
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

    $pdfContent = $dompdf->output();
    $pdfFilename = 'estimate_' . $est['estimate_no'] . '.pdf';

    // Send email via PHPMailer
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $emailSettings['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $emailSettings['smtp_username'];
    $mail->Password = $emailSettings['smtp_password'];
    $mail->SMTPSecure = $emailSettings['smtp_encryption'] === 'none' ? '' : $emailSettings['smtp_encryption'];
    $mail->Port = intval($emailSettings['smtp_port']);

    $mail->setFrom($emailSettings['from_email'], $emailSettings['from_name'] ?? '');
    $mail->addAddress($est['party_email'], $est['party_name'] ?? '');
    $mail->addStringAttachment($pdfContent, $pdfFilename);
    $mail->isHTML(true);
    $mail->Subject = 'Estimate ' . $est['estimate_no'] . ' from ' . $companyName;
    $mail->Body = '<p>Dear ' . sanitize($est['party_name'] ?? 'Customer') . ',</p>
        <p>Please find attached our estimate <strong>' . sanitize($est['estimate_no']) . '</strong> for your review.</p>
        <p><strong>Amount:</strong> ' . money($roundedTotal) . '<br>
        <strong>Valid Until:</strong> ' . ($est['valid_until'] ? dateFormatted($est['valid_until']) : '30 days') . '</p>
        <p>If you have any questions, please feel free to contact us.</p>
        <p>Thank you for your business!</p>';

    $mail->send();

    // Update estimate status to 'sent' if it was 'draft'
    if ($est['status'] === 'draft') {
        query("UPDATE estimates SET status = 'sent' WHERE id = ?", [$id]);
    }

    echo json_encode(['success' => true, 'message' => 'Email sent successfully to ' . $est['party_email']]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
