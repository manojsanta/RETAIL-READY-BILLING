<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: invoice_settings.php');
        exit;
    }

    setSetting('invoice_prefix', sanitize($_POST['invoice_prefix'] ?? 'INV-'));
    setSetting('invoice_start_number', intval($_POST['invoice_start_number'] ?? 1));
    setSetting('show_company_logo', isset($_POST['show_company_logo']) ? '1' : '0');
    setSetting('show_company_address', isset($_POST['show_company_address']) ? '1' : '0');
    setSetting('show_customer_details', isset($_POST['show_customer_details']) ? '1' : '0');
    setSetting('show_hsn_code', isset($_POST['show_hsn_code']) ? '1' : '0');
    setSetting('show_tax_details', isset($_POST['show_tax_details']) ? '1' : '0');
    setSetting('show_bank_details', isset($_POST['show_bank_details']) ? '1' : '0');
    setSetting('show_terms_conditions', isset($_POST['show_terms_conditions']) ? '1' : '0');
    setSetting('invoice_footer_text', sanitize($_POST['invoice_footer_text'] ?? ''));
    setSetting('default_payment_terms', intval($_POST['default_payment_terms'] ?? 0));
    setSetting('terms_conditions', $_POST['terms_conditions'] ?? '');

    setFlash('success', 'Invoice settings saved successfully.');
    header('Location: invoice_settings.php');
    exit;
}

$pageTitle = 'Invoice Settings';
include 'header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Invoice Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                    <h6 class="fw-bold text-muted mb-3">Invoice Numbering</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Invoice Prefix</label>
                            <input type="text" name="invoice_prefix" class="form-control" value="<?= sanitize(getSetting('invoice_prefix', 'INV-')) ?>">
                            <small class="text-muted">e.g., INV-, BILL-, TAX-</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Invoice Start Number</label>
                            <input type="number" name="invoice_start_number" class="form-control" value="<?= intval(getSetting('invoice_start_number', 1)) ?>" min="1">
                        </div>
                    </div>

                    <h6 class="fw-bold text-muted mb-3">Invoice Layout</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_company_logo" id="show_company_logo" value="1" <?= getSetting('show_company_logo', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_company_logo">Company Logo</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_company_address" id="show_company_address" value="1" <?= getSetting('show_company_address', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_company_address">Company Address</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_customer_details" id="show_customer_details" value="1" <?= getSetting('show_customer_details', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_customer_details">Customer Details</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_hsn_code" id="show_hsn_code" value="1" <?= getSetting('show_hsn_code', '0') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_hsn_code">HSN Code</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_tax_details" id="show_tax_details" value="1" <?= getSetting('show_tax_details', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_tax_details">Tax Details</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_bank_details" id="show_bank_details" value="1" <?= getSetting('show_bank_details', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_bank_details">Bank Details</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_terms_conditions" id="show_terms_conditions" value="1" <?= getSetting('show_terms_conditions', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="show_terms_conditions">Terms & Conditions</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-muted mb-3">Other Settings</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Invoice Footer Text</label>
                            <input type="text" name="invoice_footer_text" class="form-control" value="<?= sanitize(getSetting('invoice_footer_text', 'Thank you for your business!')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Payment Terms (days)</label>
                            <input type="number" name="default_payment_terms" class="form-control" value="<?= intval(getSetting('default_payment_terms', 0)) ?>" min="0">
                            <small class="text-muted">0 = Due on receipt</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Terms & Conditions</label>
                            <textarea name="terms_conditions" class="form-control" rows="4"><?= sanitize(getSetting('terms_conditions', '')) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Invoice Preview</h5>
            </div>
            <div class="card-body">
                <div class="border rounded p-4" style="max-width:600px;margin:0 auto;background:#fff;">
                    <?php if (getSetting('show_company_logo', '1') === '1'): ?>
                        <div class="text-center mb-3">
                            <strong style="font-size:20px;"><?= sanitize(getSetting('company_name', $company['name'] ?? 'Your Company')) ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (getSetting('show_company_address', '1') === '1'): ?>
                        <div class="text-center text-muted mb-2" style="font-size:12px;">
                            123 Business Street, City, State - 000000<br>
                            GSTIN: 22AAAAA0000A1Z5 | Phone: 9999999999
                        </div>
                    <?php endif; ?>
                    <hr>
                    <div class="d-flex justify-content-between mb-2">
                        <div>
                            <strong>Invoice: <?= sanitize(getSetting('invoice_prefix', 'INV-')) ?>00001</strong><br>
                            <small class="text-muted">Date: <?= date('d-M-Y') ?></small>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">Bill To:</small><br>
                            <strong>Customer Name</strong><br>
                            <small class="text-muted">Customer Address</small>
                        </div>
                    </div>
                    <table class="table table-sm mt-3" style="font-size:12px;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Item</th>
                                <?php if (getSetting('show_hsn_code', '0') === '1'): ?><th>HSN</th><?php endif; ?>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1</td>
                                <td>Sample Item</td>
                                <?php if (getSetting('show_hsn_code', '0') === '1'): ?><td>1234</td><?php endif; ?>
                                <td class="text-center">2</td>
                                <td class="text-end">₹500.00</td>
                                <td class="text-end">₹1,000.00</td>
                            </tr>
                        </tbody>
                    </table>
                    <?php if (getSetting('show_tax_details', '1') === '1'): ?>
                        <div class="text-end" style="font-size:12px;">
                            Subtotal: ₹1,000.00<br>
                            CGST (9%): ₹90.00<br>
                            SGST (9%): ₹90.00<br>
                            <strong style="font-size:14px;">Total: ₹1,180.00</strong>
                        </div>
                    <?php endif; ?>
                    <?php if (getSetting('show_bank_details', '1') === '1'): ?>
                        <div class="mt-3 p-2 rounded" style="background:#f8f9fa;font-size:11px;">
                            <strong>Bank Details:</strong> SBI, A/C: 1234567890, IFSC: SBIN0001234
                        </div>
                    <?php endif; ?>
                    <?php if (getSetting('show_terms_conditions', '1') === '1' && getSetting('terms_conditions', '') !== ''): ?>
                        <div class="mt-2" style="font-size:11px;color:#666;">
                            <strong>Terms:</strong> <?= nl2br(sanitize(getSetting('terms_conditions', ''))) ?>
                        </div>
                    <?php endif; ?>
                        <div class="text-center mt-3" style="font-size:11px;color:#888;">
                            <?= sanitize(getSetting('invoice_footer_text', 'Thank you for your business!')) ?>
                        </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
