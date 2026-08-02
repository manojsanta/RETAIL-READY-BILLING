<?php
require_once 'db.php';
require_once 'functions.php';
require_once 'vendor/autoload.php';
requireLogin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$activeTab = $_GET['tab'] ?? 'gstr3b';
$selectedMonth = $_GET['month'] ?? date('Y-m');
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

// GSTR-1 Data (Sales)
$b2bSales = fetchAll("SELECT s.invoice_no, s.date, s.total, s.tax_amount, p.name as party_name, p.gstin,
    s.subtotal, s.discount_amount
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id
    WHERE s.date >= ? AND s.date <= ? AND s.status != 'cancelled' AND p.gstin IS NOT NULL AND p.gstin != ''
    ORDER BY s.date ASC, s.id ASC", [$monthStart, $monthEnd]);

$b2cSales = fetchAll("SELECT s.invoice_no, s.date, s.total, s.tax_amount, s.subtotal, s.discount_amount, p.name as party_name
    FROM sales s LEFT JOIN parties p ON s.party_id = p.id
    WHERE s.date >= ? AND s.date <= ? AND s.status != 'cancelled'
    AND (p.gstin IS NULL OR p.gstin = '')
    ORDER BY s.date ASC, s.id ASC", [$monthStart, $monthEnd]);

// GSTR-1 HSN Summary
$hsnSales = fetchAll("SELECT i.hsn_code, i.name as item_name, i.unit,
    SUM(si.qty) as total_qty, SUM(si.total) as total_value,
    SUM(si.qty * si.rate) as taxable_value,
    SUM(CASE WHEN si.tax_rate <= 18 THEN si.tax_amount * 0.5 ELSE 0 END) as cgst,
    SUM(CASE WHEN si.tax_rate <= 18 THEN si.tax_amount * 0.5 ELSE 0 END) as sgst,
    SUM(CASE WHEN si.tax_rate > 18 THEN si.tax_amount ELSE 0 END) as igst
    FROM sale_items si
    JOIN items i ON si.item_id = i.id
    JOIN sales s ON si.sale_id = s.id
    WHERE s.date >= ? AND s.date <= ? AND s.status != 'cancelled'
    GROUP BY i.hsn_code, i.name, i.unit
    ORDER BY total_value DESC", [$monthStart, $monthEnd]);

// GSTR-2 Data (Purchases)
$b2bPurchases = fetchAll("SELECT pu.bill_no, pu.date, pu.total, pu.tax_amount, p.name as party_name, p.gstin,
    pu.subtotal, pu.discount_amount
    FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
    WHERE pu.date >= ? AND pu.date <= ? AND pu.status != 'cancelled' AND p.gstin IS NOT NULL AND p.gstin != ''
    ORDER BY pu.date ASC, pu.id ASC", [$monthStart, $monthEnd]);

$b2cPurchases = fetchAll("SELECT pu.bill_no, pu.date, pu.total, pu.tax_amount, pu.subtotal, pu.discount_amount, p.name as party_name
    FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
    WHERE pu.date >= ? AND pu.date <= ? AND pu.status != 'cancelled'
    AND (p.gstin IS NULL OR p.gstin = '')
    ORDER BY pu.date ASC, pu.id ASC", [$monthStart, $monthEnd]);

$hsnPurchases = fetchAll("SELECT i.hsn_code, i.name as item_name, i.unit,
    SUM(pi.qty) as total_qty, SUM(pi.total) as total_value,
    SUM(pi.qty * pi.rate) as taxable_value,
    SUM(CASE WHEN pi.tax_rate <= 18 THEN pi.tax_amount * 0.5 ELSE 0 END) as cgst,
    SUM(CASE WHEN pi.tax_rate <= 18 THEN pi.tax_amount * 0.5 ELSE 0 END) as sgst,
    SUM(CASE WHEN pi.tax_rate > 18 THEN pi.tax_amount ELSE 0 END) as igst
    FROM purchase_items pi
    JOIN items i ON pi.item_id = i.id
    JOIN purchases pu ON pi.purchase_id = pu.id
    WHERE pu.date >= ? AND pu.date <= ? AND pu.status != 'cancelled'
    GROUP BY i.hsn_code, i.name, i.unit
    ORDER BY total_value DESC", [$monthStart, $monthEnd]);

// Summary calculations
$b2bSalesTotal = array_sum(array_column($b2bSales, 'total'));
$b2bSalesTax = array_sum(array_column($b2bSales, 'tax_amount'));
$b2cSalesTotal = array_sum(array_column($b2cSales, 'total'));
$b2cSalesTax = array_sum(array_column($b2cSales, 'tax_amount'));
$totalSalesTax = $b2bSalesTax + $b2cSalesTax;

$b2bPurchasesTotal = array_sum(array_column($b2bPurchases, 'total'));
$b2bPurchasesTax = array_sum(array_column($b2bPurchases, 'tax_amount'));
$b2cPurchasesTotal = array_sum(array_column($b2cPurchases, 'total'));
$b2cPurchasesTax = array_sum(array_column($b2cPurchases, 'tax_amount'));
$totalPurchaseTax = $b2bPurchasesTax + $b2cPurchasesTax;

$taxPayable = $totalSalesTax - $totalPurchaseTax;

// Excel Export (multi-sheet XLSX)
function gstSheet($spreadsheet, $title, $headers, $rows) {
    $ws = $spreadsheet->createSheet();
    $ws->setTitle($title);
    $ws->fromArray($headers, null, 'A1');
    if (!empty($rows)) {
        $ws->fromArray($rows, null, 'A2');
    }
    $lastCol = $ws->getHighestColumn();
    $headerStyle = $ws->getStyle('A1:' . $lastCol . '1');
    $headerStyle->getFont()->setBold(true);
    $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');
    foreach (range('A', $lastCol) as $col) {
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'xlsx') {
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    $spreadsheet->getProperties()->setCreator('Retail Ready')->setTitle('GST Report ' . $selectedMonth);

    if ($activeTab === 'gstr1') {
        $b2bRows = [];
        foreach ($b2bSales as $bs) {
            $b2bRows[] = [$bs['invoice_no'], $bs['date'], $bs['party_name'], $bs['gstin'], (float)$bs['total'], (float)$bs['subtotal'] - (float)$bs['discount_amount'], round((float)$bs['tax_amount'] / 2, 2), round((float)$bs['tax_amount'] / 2, 2), 0];
        }
        $b2cRows = [];
        foreach ($b2cSales as $bc) {
            $b2cRows[] = [$bc['invoice_no'], $bc['date'], $bc['party_name'] ?: 'Walk-in', (float)$bc['total'], (float)$bc['subtotal'] - (float)$bc['discount_amount'], round((float)$bc['tax_amount'] / 2, 2), round((float)$bc['tax_amount'] / 2, 2), 0];
        }
        $hsnRows = [];
        foreach ($hsnSales as $hs) {
            $hsnRows[] = [$hs['hsn_code'] ?: 'N/A', $hs['item_name'], $hs['unit'] ?: 'NOS', (float)$hs['total_qty'], (float)$hs['total_value'], (float)$hs['taxable_value'], (float)$hs['cgst'], (float)$hs['sgst'], (float)$hs['igst']];
        }
        gstSheet($spreadsheet, 'B2B Sales', ['Invoice No', 'Date', 'Party', 'GSTIN', 'Invoice Value', 'Taxable Value', 'CGST', 'SGST', 'IGST'], $b2bRows);
        gstSheet($spreadsheet, 'B2C Sales', ['Invoice No', 'Date', 'Party', 'Invoice Value', 'Taxable Value', 'CGST', 'SGST', 'IGST'], $b2cRows);
        gstSheet($spreadsheet, 'HSN Summary', ['HSN Code', 'Description', 'UQC', 'Total Qty', 'Total Value', 'Taxable Value', 'CGST', 'SGST', 'IGST'], $hsnRows);
        $filename = 'gstr1_' . $selectedMonth;
    } elseif ($activeTab === 'gstr2') {
        $b2bRows = [];
        foreach ($b2bPurchases as $bp) {
            $b2bRows[] = [$bp['bill_no'], $bp['date'], $bp['party_name'], $bp['gstin'], (float)$bp['total'], (float)$bp['subtotal'] - (float)$bp['discount_amount'], round((float)$bp['tax_amount'] / 2, 2), round((float)$bp['tax_amount'] / 2, 2), 0, 'Yes'];
        }
        $b2cRows = [];
        foreach ($b2cPurchases as $bp) {
            $b2cRows[] = [$bp['bill_no'], $bp['date'], $bp['party_name'] ?: '-', (float)$bp['total'], (float)$bp['subtotal'] - (float)$bp['discount_amount'], round((float)$bp['tax_amount'] / 2, 2), round((float)$bp['tax_amount'] / 2, 2), 0, 'No'];
        }
        $hsnRows = [];
        foreach ($hsnPurchases as $hp) {
            $hsnRows[] = [$hp['hsn_code'] ?: 'N/A', $hp['item_name'], $hp['unit'] ?: 'NOS', (float)$hp['total_qty'], (float)$hp['total_value'], (float)$hp['taxable_value'], (float)$hp['cgst'], (float)$hp['sgst'], (float)$hp['igst']];
        }
        gstSheet($spreadsheet, 'B2B Purchases', ['Bill No', 'Date', 'Supplier', 'GSTIN', 'Invoice Value', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'ITC Eligible'], $b2bRows);
        gstSheet($spreadsheet, 'B2C Purchases', ['Bill No', 'Date', 'Supplier', 'Invoice Value', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'ITC Eligible'], $b2cRows);
        gstSheet($spreadsheet, 'HSN Summary', ['HSN Code', 'Description', 'UQC', 'Total Qty', 'Total Value', 'Taxable Value', 'CGST', 'SGST', 'IGST'], $hsnRows);
        $filename = 'gstr2_' . $selectedMonth;
    } else {
        $outward = [
            ['(a) Outward taxable supplies', (float)$b2bSalesTotal + (float)$b2cSalesTotal, 0, round($totalSalesTax / 2, 2), round($totalSalesTax / 2, 2), 0],
            ['(b) Outward taxable supplies (zero rated)', 0, 0, 0, 0, 0],
            ['(c) Other outward supplies (Nil rated, exempted)', 0, 0, 0, 0, 0],
            ['Total Outward Supplies', (float)$b2bSalesTotal + (float)$b2cSalesTotal, 0, round($totalSalesTax / 2, 2), round($totalSalesTax / 2, 2), 0],
        ];
        $inward = [
            ['(1) Import of goods', 0, 0, 0, 0, 0],
            ['(2) Import of services', 0, 0, 0, 0, 0],
            ['(3) Inward supplies liable to reverse charge', 0, 0, 0, 0, 0],
            ['(4) Inward supplies from ISD', 0, 0, 0, 0, 0],
            ['(5) All other ITC eligible inward supplies', (float)$b2bPurchasesTotal + (float)$b2cPurchasesTotal, 0, round($totalPurchaseTax / 2, 2), round($totalPurchaseTax / 2, 2), 0],
            ['Total Inward Supplies (Eligible ITC)', (float)$b2bPurchasesTotal + (float)$b2cPurchasesTotal, 0, round($totalPurchaseTax / 2, 2), round($totalPurchaseTax / 2, 2), 0],
        ];
        $taxable = [
            ['Output Tax (Sales)', 0, round($totalSalesTax / 2, 2), round($totalSalesTax / 2, 2), 0],
            ['Less: Input Tax Credit', 0, round($totalPurchaseTax / 2, 2), round($totalPurchaseTax / 2, 2), 0],
            ['Net Tax Payable', 0, round(max(0, ($totalSalesTax - $totalPurchaseTax) / 2), 2), round(max(0, ($totalSalesTax - $totalPurchaseTax) / 2), 2), 0],
        ];
        gstSheet($spreadsheet, '3.1 Outward', ['Description', 'Taxable Value', 'IGST', 'CGST', 'SGST', 'Cess'], $outward);
        gstSheet($spreadsheet, '3.2 Inward', ['Description', 'Taxable Value', 'IGST', 'CGST', 'SGST', 'Cess'], $inward);
        gstSheet($spreadsheet, '6.1 Tax Payable', ['Description', 'IGST', 'CGST', 'SGST', 'Cess'], $taxable);
        $filename = 'gstr3b_' . $selectedMonth;
    }

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

$pageTitle = 'GST Reports';
include 'header.php';
?>

<style>
.gst-tab .nav-link { font-size: 0.9rem; }
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">GST Reports</h5>
    <div class="d-flex gap-2">
        <a href="?tab=<?= sanitize($activeTab) ?>&month=<?= sanitize($selectedMonth) ?>&export=xlsx" class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i>Export Excel</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Tabs & Month Filter -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-end">
            <div class="col-md-4">
                <ul class="nav nav-tabs gst-tab mb-0">
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'gstr3b' ? 'active' : '' ?>" href="?tab=gstr3b&month=<?= $selectedMonth ?>">GSTR-3B</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'gstr1' ? 'active' : '' ?>" href="?tab=gstr1&month=<?= $selectedMonth ?>">GSTR-1</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activeTab === 'gstr2' ? 'active' : '' ?>" href="?tab=gstr2&month=<?= $selectedMonth ?>">GSTR-2</a>
                    </li>
                </ul>
            </div>
            <div class="col-md-4">
                <form method="GET" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="tab" value="<?= sanitize($activeTab) ?>">
                    <label class="form-label small mb-0">Month:</label>
                    <input type="month" name="month" class="form-control form-control-sm" value="<?= sanitize($selectedMonth) ?>" style="width:170px;">
                    <button type="submit" class="btn btn-primary btn-sm">Go</button>
                </form>
            </div>
            <div class="col-md-4 text-end">
                <small class="text-muted">Period: <?= date('d M Y', strtotime($monthStart)) ?> to <?= date('d M Y', strtotime($monthEnd)) ?></small>
            </div>
        </div>
    </div>
</div>

<?php if ($activeTab === 'gstr1'): ?>
<!-- GSTR-1: Sales Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">B2B Sales (With GSTIN)</small>
                <h5 class="mb-0 text-primary"><?= money($b2bSalesTotal) ?></h5>
                <small class="text-muted"><?= count($b2bSales) ?> invoices | Tax: <?= money($b2bSalesTax) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">B2C Sales (Without GSTIN)</small>
                <h5 class="mb-0 text-info"><?= money($b2cSalesTotal) ?></h5>
                <small class="text-muted"><?= count($b2cSales) ?> invoices | Tax: <?= money($b2cSalesTax) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Output Tax</small>
                <h5 class="mb-0 text-warning"><?= money($totalSalesTax) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- B2B Sales -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-building me-1"></i>B2B Sales (Registered Dealers)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Party</th>
                    <th>GSTIN</th>
                    <th class="text-end">Invoice Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($b2bSales)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No B2B sales for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($b2bSales as $bs): ?>
                        <?php
                        $taxableVal = (float)$bs['subtotal'] - (float)$bs['discount_amount'];
                        $cgst = round((float)$bs['tax_amount'] / 2, 2);
                        $sgst = round((float)$bs['tax_amount'] / 2, 2);
                        $igst = 0;
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($bs['invoice_no']) ?></td>
                            <td><?= dateFormatted($bs['date']) ?></td>
                            <td><?= sanitize($bs['party_name']) ?></td>
                            <td><small class="text-muted"><?= sanitize($bs['gstin']) ?></small></td>
                            <td class="text-end"><?= money($bs['total']) ?></td>
                            <td class="text-end"><?= money($taxableVal) ?></td>
                            <td class="text-end"><?= money($cgst) ?></td>
                            <td class="text-end"><?= money($sgst) ?></td>
                            <td class="text-end"><?= money($igst) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($b2bSales)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4">Total B2B</td>
                    <td class="text-end"><?= money($b2bSalesTotal) ?></td>
                    <td class="text-end"><?= money(array_sum(array_map(function($bs) { return (float)$bs['subtotal'] - (float)$bs['discount_amount']; }, $b2bSales))) ?></td>
                    <td class="text-end"><?= money(round($b2bSalesTax / 2, 2)) ?></td>
                    <td class="text-end"><?= money(round($b2bSalesTax / 2, 2)) ?></td>
                    <td class="text-end">₹0.00</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- B2C Sales -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-user me-1"></i>B2C Sales (Unregistered)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th>Party</th>
                    <th class="text-end">Invoice Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($b2cSales)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No B2C sales for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($b2cSales as $bc): ?>
                        <?php
                        $taxableVal = (float)$bc['subtotal'] - (float)$bc['discount_amount'];
                        $cgst = round((float)$bc['tax_amount'] / 2, 2);
                        $sgst = round((float)$bc['tax_amount'] / 2, 2);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($bc['invoice_no']) ?></td>
                            <td><?= dateFormatted($bc['date']) ?></td>
                            <td><?= sanitize($bc['party_name'] ?: 'Walk-in') ?></td>
                            <td class="text-end"><?= money($bc['total']) ?></td>
                            <td class="text-end"><?= money($taxableVal) ?></td>
                            <td class="text-end"><?= money($cgst) ?></td>
                            <td class="text-end"><?= money($sgst) ?></td>
                            <td class="text-end">₹0.00</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($b2cSales)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Total B2C</td>
                    <td class="text-end"><?= money($b2cSalesTotal) ?></td>
                    <td class="text-end"><?= money(array_sum(array_map(function($bc) { return (float)$bc['subtotal'] - (float)$bc['discount_amount']; }, $b2cSales))) ?></td>
                    <td class="text-end"><?= money(round($b2cSalesTax / 2, 2)) ?></td>
                    <td class="text-end"><?= money(round($b2cSalesTax / 2, 2)) ?></td>
                    <td class="text-end">₹0.00</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- HSN-wise Summary (Sales) -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-barcode me-1"></i>HSN-wise Summary (Sales)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>HSN Code</th>
                    <th>Description</th>
                    <th>UQC</th>
                    <th class="text-end">Total Qty</th>
                    <th class="text-end">Total Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($hsnSales)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No HSN data.</td></tr>
                <?php else: ?>
                    <?php foreach ($hsnSales as $hs): ?>
                        <tr>
                            <td><?= sanitize($hs['hsn_code'] ?: 'N/A') ?></td>
                            <td><?= sanitize($hs['item_name']) ?></td>
                            <td><small><?= sanitize($hs['unit'] ?: 'NOS') ?></small></td>
                            <td class="text-end"><?= number_format($hs['total_qty']) ?></td>
                            <td class="text-end"><?= money($hs['total_value']) ?></td>
                            <td class="text-end"><?= money($hs['taxable_value']) ?></td>
                            <td class="text-end"><?= money($hs['cgst']) ?></td>
                            <td class="text-end"><?= money($hs['sgst']) ?></td>
                            <td class="text-end"><?= money($hs['igst']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($hsnSales)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Total</td>
                    <td class="text-end"><?= number_format(array_sum(array_column($hsnSales, 'total_qty'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnSales, 'total_value'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnSales, 'taxable_value'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnSales, 'cgst'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnSales, 'sgst'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnSales, 'igst'))) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php elseif ($activeTab === 'gstr2'): ?>
<!-- GSTR-2: Purchase Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">B2B Purchases (With GSTIN)</small>
                <h5 class="mb-0 text-primary"><?= money($b2bPurchasesTotal) ?></h5>
                <small class="text-muted"><?= count($b2bPurchases) ?> bills | Tax: <?= money($b2bPurchasesTax) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">B2C Purchases (Without GSTIN)</small>
                <h5 class="mb-0 text-info"><?= money($b2cPurchasesTotal) ?></h5>
                <small class="text-muted"><?= count($b2cPurchases) ?> bills | Tax: <?= money($b2cPurchasesTax) ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Input Tax Credit (ITC)</small>
                <h5 class="mb-0 text-success"><?= money($totalPurchaseTax) ?></h5>
            </div>
        </div>
    </div>
</div>

<!-- B2B Purchases -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-building me-1"></i>B2B Purchases (Registered Suppliers)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Bill No</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>GSTIN</th>
                    <th class="text-end">Invoice Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                    <th>ITC Eligible</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($b2bPurchases)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">No B2B purchases for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($b2bPurchases as $bp): ?>
                        <?php
                        $taxableVal = (float)$bp['subtotal'] - (float)$bp['discount_amount'];
                        $cgst = round((float)$bp['tax_amount'] / 2, 2);
                        $sgst = round((float)$bp['tax_amount'] / 2, 2);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($bp['bill_no']) ?></td>
                            <td><?= dateFormatted($bp['date']) ?></td>
                            <td><?= sanitize($bp['party_name']) ?></td>
                            <td><small class="text-muted"><?= sanitize($bp['gstin']) ?></small></td>
                            <td class="text-end"><?= money($bp['total']) ?></td>
                            <td class="text-end"><?= money($taxableVal) ?></td>
                            <td class="text-end"><?= money($cgst) ?></td>
                            <td class="text-end"><?= money($sgst) ?></td>
                            <td class="text-end">₹0.00</td>
                            <td><span class="badge bg-success">Yes</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($b2bPurchases)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4">Total B2B</td>
                    <td class="text-end"><?= money($b2bPurchasesTotal) ?></td>
                    <td class="text-end"><?= money(array_sum(array_map(function($bp) { return (float)$bp['subtotal'] - (float)$bp['discount_amount']; }, $b2bPurchases))) ?></td>
                    <td class="text-end"><?= money(round($b2bPurchasesTax / 2, 2)) ?></td>
                    <td class="text-end"><?= money(round($b2bPurchasesTax / 2, 2)) ?></td>
                    <td class="text-end">₹0.00</td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- B2C Purchases -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-user me-1"></i>B2C Purchases (Unregistered)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Bill No</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th class="text-end">Invoice Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                    <th>ITC Eligible</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($b2cPurchases)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No B2C purchases for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($b2cPurchases as $bp): ?>
                        <?php
                        $taxableVal = (float)$bp['subtotal'] - (float)$bp['discount_amount'];
                        $cgst = round((float)$bp['tax_amount'] / 2, 2);
                        $sgst = round((float)$bp['tax_amount'] / 2, 2);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= sanitize($bp['bill_no']) ?></td>
                            <td><?= dateFormatted($bp['date']) ?></td>
                            <td><?= sanitize($bp['party_name'] ?: '-') ?></td>
                            <td class="text-end"><?= money($bp['total']) ?></td>
                            <td class="text-end"><?= money($taxableVal) ?></td>
                            <td class="text-end"><?= money($cgst) ?></td>
                            <td class="text-end"><?= money($sgst) ?></td>
                            <td class="text-end">₹0.00</td>
                            <td><span class="badge bg-secondary">No</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($b2cPurchases)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Total B2C</td>
                    <td class="text-end"><?= money($b2cPurchasesTotal) ?></td>
                    <td class="text-end"><?= money(array_sum(array_map(function($bp) { return (float)$bp['subtotal'] - (float)$bp['discount_amount']; }, $b2cPurchases))) ?></td>
                    <td class="text-end"><?= money(round($b2cPurchasesTax / 2, 2)) ?></td>
                    <td class="text-end"><?= money(round($b2cPurchasesTax / 2, 2)) ?></td>
                    <td class="text-end">₹0.00</td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- HSN-wise Summary (Purchases) -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-barcode me-1"></i>HSN-wise Summary (Purchases)</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>HSN Code</th>
                    <th>Description</th>
                    <th>UQC</th>
                    <th class="text-end">Total Qty</th>
                    <th class="text-end">Total Value</th>
                    <th class="text-end">Taxable Value</th>
                    <th class="text-end">CGST</th>
                    <th class="text-end">SGST</th>
                    <th class="text-end">IGST</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($hsnPurchases)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No HSN data.</td></tr>
                <?php else: ?>
                    <?php foreach ($hsnPurchases as $hp): ?>
                        <tr>
                            <td><?= sanitize($hp['hsn_code'] ?: 'N/A') ?></td>
                            <td><?= sanitize($hp['item_name']) ?></td>
                            <td><small><?= sanitize($hp['unit'] ?: 'NOS') ?></small></td>
                            <td class="text-end"><?= number_format($hp['total_qty']) ?></td>
                            <td class="text-end"><?= money($hp['total_value']) ?></td>
                            <td class="text-end"><?= money($hp['taxable_value']) ?></td>
                            <td class="text-end"><?= money($hp['cgst']) ?></td>
                            <td class="text-end"><?= money($hp['sgst']) ?></td>
                            <td class="text-end"><?= money($hp['igst']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($hsnPurchases)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Total</td>
                    <td class="text-end"><?= number_format(array_sum(array_column($hsnPurchases, 'total_qty'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnPurchases, 'total_value'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnPurchases, 'taxable_value'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnPurchases, 'cgst'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnPurchases, 'sgst'))) ?></td>
                    <td class="text-end"><?= money(array_sum(array_column($hsnPurchases, 'igst'))) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php else: ?>
<!-- GSTR-3B Summary -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">GSTR-3B Summary for <?= date('M Y', strtotime($monthStart)) ?></h6>
    </div>
    <div class="card-body">

        <!-- 3.1 Outward Supplies -->
        <h6 class="text-primary mb-3"><i class="fas fa-arrow-up me-1"></i>3.1 Outward Supplies (Sales)</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Total Taxable Value</th>
                        <th class="text-end">Integrated Tax (IGST)</th>
                        <th class="text-end">Central Tax (CGST)</th>
                        <th class="text-end">State Tax (SGST)</th>
                        <th class="text-end">Cess</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>(a) Outward taxable supplies (other than zero rated, nil rated and exempted)</strong></td>
                        <td class="text-end"><?= money($b2bSalesTotal + $b2cSalesTotal) ?></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td>(b) Outward taxable supplies (zero rated)</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td>(c) Other outward supplies (Nil rated, exempted)</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr class="table-light fw-bold">
                        <td>Total Outward Supplies</td>
                        <td class="text-end"><?= money($b2bSalesTotal + $b2cSalesTotal) ?></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 3.2 Inward Supplies -->
        <h6 class="text-danger mb-3"><i class="fas fa-arrow-down me-1"></i>3.2 Inward Supplies (Purchases) - Eligible ITC</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Total Taxable Value</th>
                        <th class="text-end">Integrated Tax (IGST)</th>
                        <th class="text-end">Central Tax (CGST)</th>
                        <th class="text-end">State Tax (SGST)</th>
                        <th class="text-end">Cess</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>(1) Import of goods</strong></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td><strong>(2) Import of services</strong></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td><strong>(3) Inward supplies liable to reverse charge</strong></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td><strong>(4) Inward supplies from ISD</strong></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td><strong>(5) All other ITC eligible inward supplies</strong></td>
                        <td class="text-end"><?= money($b2bPurchasesTotal + $b2cPurchasesTotal) ?></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr class="table-light fw-bold">
                        <td>Total Inward Supplies (Eligible ITC)</td>
                        <td class="text-end"><?= money($b2bPurchasesTotal + $b2cPurchasesTotal) ?></td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tax Payable -->
        <h6 class="text-warning mb-3"><i class="fas fa-calculator me-1"></i>6.1 Tax Payable</h6>
        <div class="table-responsive">
            <table class="table table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Integrated Tax</th>
                        <th class="text-end">Central Tax</th>
                        <th class="text-end">State Tax</th>
                        <th class="text-end">Cess</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Output Tax (Sales)</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalSalesTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr>
                        <td>Less: Input Tax Credit</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end"><?= money(round($totalPurchaseTax / 2, 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                    <tr class="table-primary fw-bold">
                        <td>Net Tax Payable</td>
                        <td class="text-end">₹0.00</td>
                        <td class="text-end <?= ($totalSalesTax - $totalPurchaseTax) / 2 >= 0 ? 'text-danger' : 'text-success' ?>"><?= money(round(max(0, ($totalSalesTax - $totalPurchaseTax) / 2), 2)) ?></td>
                        <td class="text-end <?= ($totalSalesTax - $totalPurchaseTax) / 2 >= 0 ? 'text-danger' : 'text-success' ?>"><?= money(round(max(0, ($totalSalesTax - $totalPurchaseTax) / 2), 2)) ?></td>
                        <td class="text-end">₹0.00</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="row g-3 mt-3">
            <div class="col-md-4">
                <div class="card border-0 <?= $totalSalesTax >= 0 ? 'bg-light' : '' ?>">
                    <div class="card-body text-center py-3">
                        <small class="text-muted d-block">Output Tax (Sales)</small>
                        <h5 class="mb-0 text-primary"><?= money($totalSalesTax) ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 bg-light">
                    <div class="card-body text-center py-3">
                        <small class="text-muted d-block">Input Tax Credit (Purchases)</small>
                        <h5 class="mb-0 text-success"><?= money($totalPurchaseTax) ?></h5>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 <?= $taxPayable > 0 ? 'border-danger' : 'border-success' ?>" style="border-width: 2px !important;">
                    <div class="card-body text-center py-3">
                        <small class="text-muted d-block"><?= $taxPayable >= 0 ? 'Tax Payable' : 'Tax Credit Carry Forward' ?></small>
                        <h4 class="mb-0 <?= $taxPayable >= 0 ? 'text-danger' : 'text-success' ?>"><?= money(abs($taxPayable)) ?></h4>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
