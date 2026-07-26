<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$errors = [];
$company = getCompany();
$emailSettings = query("SELECT * FROM email_settings ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: company_settings.php');
        exit;
    }

    $section = $_POST['section'] ?? 'company';

    if ($section === 'company') {
        $name = sanitize($_POST['company_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city = sanitize($_POST['city'] ?? '');
        $state = sanitize($_POST['state'] ?? '');
        $pincode = sanitize($_POST['pincode'] ?? '');
        $gstin = sanitize($_POST['gstin'] ?? '');
        $pan = sanitize($_POST['pan'] ?? '');
        $bankName = sanitize($_POST['bank_name'] ?? '');
        $bankAccount = sanitize($_POST['bank_account'] ?? '');
        $bankIfsc = sanitize($_POST['bank_ifsc'] ?? '');
        $upiId = sanitize($_POST['upi_id'] ?? '');

        if ($name === '') $errors[] = 'Company name is required.';
        if ($phone === '') $errors[] = 'Phone number is required.';

        $logo = $company['logo'] ?? null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (in_array($_FILES['logo']['type'], $allowed) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                $logoFile = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads/logo/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                if ($company && !empty($company['logo']) && file_exists($uploadDir . $company['logo'])) {
                    unlink($uploadDir . $company['logo']);
                }
                move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $logoFile);
                $logo = $logoFile;
            } else {
                $errors[] = 'Logo must be JPG, PNG, GIF or WebP and under 2MB.';
            }
        }

        $signature = $company['signature'] ?? null;
        if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (in_array($_FILES['signature']['type'], $allowed) && $_FILES['signature']['size'] <= 2 * 1024 * 1024) {
                $ext = pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION);
                $sigFile = 'sig_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads/signature/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                if ($company && !empty($company['signature']) && file_exists($uploadDir . $company['signature'])) {
                    unlink($uploadDir . $company['signature']);
                }
                move_uploaded_file($_FILES['signature']['tmp_name'], $uploadDir . $sigFile);
                $signature = $sigFile;
            } else {
                $errors[] = 'Signature must be JPG, PNG, GIF or WebP and under 2MB.';
            }
        }

        if (empty($errors)) {
            if ($company) {
                query(
                    "UPDATE company SET name=?, email=?, phone=?, address=?, city=?, state=?, pincode=?, gstin=?, pan=?, logo=?, signature=?, bank_name=?, bank_account=?, bank_ifsc=?, upi_id=? WHERE id=?",
                    [$name, $email, $phone, $address, $city, $state, $pincode, $gstin, $pan, $logo, $signature, $bankName, $bankAccount, $bankIfsc, $upiId, $company['id']]
                );
            } else {
                query(
                    "INSERT INTO company (name, email, phone, address, city, state, pincode, gstin, pan, logo, signature, bank_name, bank_account, bank_ifsc, upi_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$name, $email, $phone, $address, $city, $state, $pincode, $gstin, $pan, $logo, $signature, $bankName, $bankAccount, $bankIfsc, $upiId]
                );
            }
            setSetting('company_name', $name);
            setFlash('success', 'Company profile updated successfully.');
            header('Location: company_settings.php');
            exit;
        }
    }

    if ($section === 'email') {
        $smtpHost = sanitize($_POST['smtp_host'] ?? '');
        $smtpPort = sanitize($_POST['smtp_port'] ?? '587');
        $smtpUsername = sanitize($_POST['smtp_username'] ?? '');
        $smtpPassword = $_POST['smtp_password'] ?? '';
        $smtpEncryption = sanitize($_POST['smtp_encryption'] ?? 'tls');
        $fromName = sanitize($_POST['from_name'] ?? '');
        $fromEmail = sanitize($_POST['from_email'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($smtpHost === '') $errors[] = 'SMTP host is required.';
        if ($smtpPort === '') $errors[] = 'SMTP port is required.';
        if ($fromEmail === '' && $isActive) $errors[] = 'From email is required when email is enabled.';

        if (empty($errors)) {
            if (!empty($emailSettings)) {
                if ($smtpPassword === '') {
                    $smtpPassword = $emailSettings['smtp_password'] ?? '';
                }
                query(
                    "UPDATE email_settings SET smtp_host=?, smtp_port=?, smtp_username=?, smtp_password=?, smtp_encryption=?, from_name=?, from_email=?, is_active=? WHERE id=?",
                    [$smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncryption, $fromName, $fromEmail, $isActive, $emailSettings['id']]
                );
            } else {
                query(
                    "INSERT INTO email_settings (smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_name, from_email, is_active) VALUES (?,?,?,?,?,?,?,?)",
                    [$smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpEncryption, $fromName, $fromEmail, $isActive]
                );
            }
            setFlash('success', 'Email settings updated successfully.');
            header('Location: company_settings.php#email-section');
            exit;
        }
    }
}

$pageTitle = 'Company Profile';
include 'header.php';
?>

<style>
    .upload-zone {
        border: 2px dashed #dee2e6;
        border-radius: 12px;
        padding: 30px 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: #fafbfc;
        position: relative;
    }
    .upload-zone:hover, .upload-zone.dragover {
        border-color: #ed1a3b;
        background: #fff5f7;
    }
    .upload-zone input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }
    .upload-zone .upload-icon {
        font-size: 42px;
        color: #dee2e6;
        margin-bottom: 12px;
        transition: color 0.3s;
    }
    .upload-zone:hover .upload-icon,
    .upload-zone.dragover .upload-icon {
        color: #ed1a3b;
    }
    .upload-zone .upload-text {
        font-size: 14px;
        color: #6c757d;
    }
    .upload-zone .upload-text strong {
        color: #ed1a3b;
    }
    .upload-zone .upload-hint {
        font-size: 12px;
        color: #adb5bd;
        margin-top: 4px;
    }
    .preview-box {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f8f9fa;
        border-radius: 10px;
        border: 1px solid #e9ecef;
    }
    .preview-box img {
        border-radius: 8px;
        object-fit: contain;
        border: 1px solid #e9ecef;
    }
    .section-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f3f5;
        margin-bottom: 20px;
    }
    .section-header .section-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }
    .section-header .section-title {
        font-weight: 700;
        font-size: 16px;
        color: #212529;
    }
    .section-header .section-desc {
        font-size: 12px;
        color: #6c757d;
        margin: 0;
    }
    .icon-company-section { background: rgba(41,98,255,0.1); color: #2962FF; }
    .icon-logo-section { background: rgba(237,26,59,0.1); color: #ed1a3b; }
    .icon-address-section { background: rgba(40,167,69,0.1); color: #28a745; }
    .icon-tax-section { background: rgba(255,152,0,0.1); color: #ff9800; }
    .icon-bank-section { background: rgba(156,39,176,0.1); color: #9c27b0; }
    .icon-email-section { background: rgba(0,150,136,0.1); color: #009688; }
    .card-section {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        background: #fff;
    }
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #ced4da;
        border-radius: 24px;
        transition: 0.3s;
    }
    .toggle-slider::before {
        content: "";
        position: absolute;
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        border-radius: 50%;
        transition: 0.3s;
    }
    .toggle-switch input:checked + .toggle-slider {
        background: #ed1a3b;
    }
    .toggle-switch input:checked + .toggle-slider::before {
        transform: translateX(20px);
    }
    .test-email-badge {
        font-size: 12px;
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 600;
    }
</style>

<div class="row justify-content-center">
    <div class="col-lg-10">

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" style="border-radius:10px;">
                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="companyForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="section" value="company">

            <!-- Company Basic Info -->
            <div class="card-section">
                <div class="section-header">
                    <div class="section-icon icon-company-section"><i class="fas fa-building"></i></div>
                    <div>
                        <div class="section-title">Company Information</div>
                        <p class="section-desc">Your business identity and contact details</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" class="form-control form-control-sm" value="<?= h($company['name'] ?? '') ?>" placeholder="Enter company name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= h($company['email'] ?? '') ?>" placeholder="company@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($company['phone'] ?? '') ?>" placeholder="+91 98765 43210" required>
                    </div>
                </div>
            </div>

            <!-- Logo & Signature -->
            <div class="card-section">
                <div class="section-header">
                    <div class="section-icon icon-logo-section"><i class="fas fa-image"></i></div>
                    <div>
                        <div class="section-title">Logo & Signature</div>
                        <p class="section-desc">Upload your company logo and authorized signature for invoices</p>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Company Logo</label>
                        <?php if (!empty($company['logo']) && file_exists(__DIR__ . '/uploads/logo/' . $company['logo'])): ?>
                            <div class="preview-box mb-3">
                                <img src="uploads/logo/<?= h($company['logo']) ?>" alt="Logo" style="height:60px;max-width:180px;">
                                <div>
                                    <div class="fw-semibold" style="font-size:13px;">Current Logo</div>
                                    <div class="text-muted" style="font-size:11px;">Click upload area to replace</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="upload-zone" id="logoZone">
                            <input type="file" name="logo" accept="image/*" id="logoInput">
                            <div class="upload-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                            <div class="upload-text"><strong>Click to upload</strong> or drag & drop</div>
                            <div class="upload-hint">JPG, PNG, GIF, WebP (Max 2MB)</div>
                            <div id="logoFileName" class="mt-2 fw-semibold" style="font-size:13px;color:#ed1a3b;"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Authorized Signature</label>
                        <?php if (!empty($company['signature']) && file_exists(__DIR__ . '/uploads/signature/' . $company['signature'])): ?>
                            <div class="preview-box mb-3">
                                <img src="uploads/signature/<?= h($company['signature']) ?>" alt="Signature" style="height:60px;max-width:180px;">
                                <div>
                                    <div class="fw-semibold" style="font-size:13px;">Current Signature</div>
                                    <div class="text-muted" style="font-size:11px;">Click upload area to replace</div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="upload-zone" id="sigZone">
                            <input type="file" name="signature" accept="image/*" id="sigInput">
                            <div class="upload-icon"><i class="fas fa-pen-nib"></i></div>
                            <div class="upload-text"><strong>Click to upload</strong> or drag & drop</div>
                            <div class="upload-hint">JPG, PNG, GIF, WebP (Max 2MB)</div>
                            <div id="sigFileName" class="mt-2 fw-semibold" style="font-size:13px;color:#ed1a3b;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="card-section">
                <div class="section-header">
                    <div class="section-icon icon-address-section"><i class="fas fa-map-marker-alt"></i></div>
                    <div>
                        <div class="section-title">Address</div>
                        <p class="section-desc">Registered business address</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Address</label>
                        <textarea name="address" class="form-control form-control-sm" rows="2" placeholder="Street address, building, area"><?= h($company['address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">City</label>
                        <input type="text" name="city" class="form-control form-control-sm" value="<?= h($company['city'] ?? '') ?>" placeholder="City">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">State</label>
                        <input type="text" name="state" class="form-control form-control-sm" value="<?= h($company['state'] ?? '') ?>" placeholder="State">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Pincode</label>
                        <input type="text" name="pincode" class="form-control form-control-sm" value="<?= h($company['pincode'] ?? '') ?>" placeholder="Pincode">
                    </div>
                </div>
            </div>

            <!-- Tax Details -->
            <div class="card-section">
                <div class="section-header">
                    <div class="section-icon icon-tax-section"><i class="fas fa-file-alt"></i></div>
                    <div>
                        <div class="section-title">Tax Details</div>
                        <p class="section-desc">GST and PAN information for invoicing</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">GSTIN</label>
                        <input type="text" name="gstin" class="form-control form-control-sm" value="<?= h($company['gstin'] ?? '') ?>" maxlength="15" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">PAN</label>
                        <input type="text" name="pan" class="form-control form-control-sm" value="<?= h($company['pan'] ?? '') ?>" maxlength="10" placeholder="AAAAA0000A" style="text-transform:uppercase;">
                    </div>
                </div>
            </div>

            <!-- Bank Details -->
            <div class="card-section">
                <div class="section-header">
                    <div class="section-icon icon-bank-section"><i class="fas fa-university"></i></div>
                    <div>
                        <div class="section-title">Bank Details</div>
                        <p class="section-desc">Bank account information for payment receipts</p>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= h($company['bank_name'] ?? '') ?>" placeholder="HDFC Bank">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Account Number</label>
                        <input type="text" name="bank_account" class="form-control form-control-sm" value="<?= h($company['bank_account'] ?? '') ?>" placeholder="Account number">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">IFSC Code</label>
                        <input type="text" name="bank_ifsc" class="form-control form-control-sm" value="<?= h($company['bank_ifsc'] ?? '') ?>" placeholder="HDFC0001234" style="text-transform:uppercase;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">UPI ID</label>
                        <input type="text" name="upi_id" class="form-control form-control-sm" value="<?= h($company['upi_id'] ?? '') ?>" placeholder="company@upi">
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="submit" class="btn" style="background:#ed1a3b;color:#fff;height:42px;padding:0 24px;"><i class="fas fa-save me-1"></i> Save Profile</button>
                <a href="settings.php" class="btn btn-outline-secondary" style="height:42px;padding:0 24px;"><i class="fas fa-times me-1"></i> Cancel</a>
            </div>
        </form>

        <!-- Email Settings -->
        <div class="card-section" id="email-section">
            <form method="POST" id="emailForm">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="section" value="email">

                <div class="section-header">
                    <div class="section-icon icon-email-section"><i class="fas fa-envelope"></i></div>
                    <div>
                        <div class="section-title">Email Settings (SMTP)</div>
                        <p class="section-desc">Configure outgoing email for invoices, receipts, and notifications</p>
                    </div>
                    <div class="ms-auto">
                        <?php if (!empty($emailSettings['is_active'])): ?>
                            <span class="test-email-badge" style="background:rgba(40,167,69,0.1);color:#28a745;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;"></i> Active</span>
                        <?php else: ?>
                            <span class="test-email-badge" style="background:rgba(108,117,125,0.1);color:#6c757d;"><i class="fas fa-circle" style="font-size:6px;vertical-align:middle;"></i> Inactive</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Enable Email</label>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_active" value="1" <?= !empty($emailSettings['is_active']) ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <span class="fw-semibold" style="font-size:13px;" id="emailToggleLabel"><?= !empty($emailSettings['is_active']) ? 'On' : 'Off' ?></span>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">SMTP Host <span class="text-danger">*</span></label>
                        <input type="text" name="smtp_host" class="form-control form-control-sm" value="<?= h($emailSettings['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">SMTP Port <span class="text-danger">*</span></label>
                        <input type="text" name="smtp_port" class="form-control form-control-sm" value="<?= h($emailSettings['smtp_port'] ?? '587') ?>" placeholder="587">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Encryption</label>
                        <select name="smtp_encryption" class="form-select form-select-sm">
                            <option value="tls" <?= ($emailSettings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= ($emailSettings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= ($emailSettings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">SMTP Username</label>
                        <input type="text" name="smtp_username" class="form-control form-control-sm" value="<?= h($emailSettings['smtp_username'] ?? '') ?>" placeholder="your@email.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">SMTP Password</label>
                        <input type="password" name="smtp_password" class="form-control form-control-sm" value="" placeholder="<?= !empty($emailSettings['smtp_password']) ? '********' : 'App password' ?>">
                        <?php if (!empty($emailSettings['smtp_password'])): ?>
                            <small class="text-muted">Leave blank to keep current password</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">From Name</label>
                        <input type="text" name="from_name" class="form-control form-control-sm" value="<?= h($emailSettings['from_name'] ?? ($company['name'] ?? '')) ?>" placeholder="Your Business Name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">From Email <span class="text-danger">*</span></label>
                        <input type="email" name="from_email" class="form-control form-control-sm" value="<?= h($emailSettings['from_email'] ?? ($company['email'] ?? '')) ?>" placeholder="noreply@yourbusiness.com">
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn" style="background:#ed1a3b;color:#fff;height:42px;padding:0 24px;"><i class="fas fa-save me-1"></i> Save Email Settings</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // File name display for logo
    var logoInput = document.getElementById('logoInput');
    if (logoInput) {
        logoInput.addEventListener('change', function() {
            var name = this.files[0] ? this.files[0].name : '';
            document.getElementById('logoFileName').textContent = name;
        });
    }

    // File name display for signature
    var sigInput = document.getElementById('sigInput');
    if (sigInput) {
        sigInput.addEventListener('change', function() {
            var name = this.files[0] ? this.files[0].name : '';
            document.getElementById('sigFileName').textContent = name;
        });
    }

    // Drag & drop visual feedback
    document.querySelectorAll('.upload-zone').forEach(function(zone) {
        zone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('dragover'); });
        zone.addEventListener('dragleave', function() { this.classList.remove('dragover'); });
        zone.addEventListener('drop', function() { this.classList.remove('dragover'); });
    });

    // Email toggle label
    var emailToggle = document.querySelector('input[name="is_active"]');
    if (emailToggle) {
        emailToggle.addEventListener('change', function() {
            document.getElementById('emailToggleLabel').textContent = this.checked ? 'On' : 'Off';
        });
    }
});
</script>

<?php include 'footer.php'; ?>
