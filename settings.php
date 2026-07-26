<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$pageTitle = 'Settings';
include 'header.php';
?>

<style>
    .settings-card {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        transition: all 0.2s ease;
        text-decoration: none;
        color: inherit;
        display: block;
    }
    .settings-card:hover {
        border-color: #2962FF;
        box-shadow: 0 4px 15px rgba(41,98,255,0.15);
        transform: translateY(-2px);
        color: inherit;
    }
    .settings-card .card-icon {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        margin-bottom: 16px;
    }
    .settings-card .card-title {
        font-weight: 700;
        font-size: 16px;
        margin-bottom: 6px;
    }
    .settings-card .card-desc {
        color: #6c757d;
        font-size: 13px;
        margin-bottom: 12px;
    }
    .settings-card .card-link {
        color: #2962FF;
        font-weight: 600;
        font-size: 13px;
    }
    .icon-company { background: rgba(41,98,255,0.1); color: #2962FF; }
    .icon-invoice { background: rgba(40,167,69,0.1); color: #28a745; }
    .icon-tax { background: rgba(255,152,0,0.1); color: #ff9800; }
    .icon-users { background: rgba(156,39,176,0.1); color: #9c27b0; }
    .icon-backup { background: rgba(0,150,136,0.1); color: #009688; }
</style>

<div class="row g-4">
    <div class="col-md-6 col-lg-4">
        <a href="company_settings.php" class="card settings-card h-100 p-4">
            <div class="card-icon icon-company"><i class="fas fa-building"></i></div>
            <div class="card-title">Company Profile</div>
            <div class="card-desc">Manage your company name, address, GSTIN, PAN, logo, and bank details.</div>
            <div class="card-link">Configure <i class="fas fa-arrow-right ms-1"></i></div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="invoice_settings.php" class="card settings-card h-100 p-4">
            <div class="card-icon icon-invoice"><i class="fas fa-file-invoice"></i></div>
            <div class="card-title">Invoice Settings</div>
            <div class="card-desc">Configure invoice prefix, number, template layout, and terms & conditions.</div>
            <div class="card-link">Configure <i class="fas fa-arrow-right ms-1"></i></div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="tax_settings.php" class="card settings-card h-100 p-4">
            <div class="card-icon icon-tax"><i class="fas fa-percent"></i></div>
            <div class="card-title">Tax & GST Settings</div>
            <div class="card-desc">Add, edit, or remove tax rates like CGST, SGST, IGST, and CESS.</div>
            <div class="card-link">Configure <i class="fas fa-arrow-right ms-1"></i></div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="user_settings.php" class="card settings-card h-100 p-4">
            <div class="card-icon icon-users"><i class="fas fa-users-cog"></i></div>
            <div class="card-title">User Management</div>
            <div class="card-desc">Add users, assign roles (Admin, Accountant, Sales), and manage access.</div>
            <div class="card-link">Configure <i class="fas fa-arrow-right ms-1"></i></div>
        </a>
    </div>
    <div class="col-md-6 col-lg-4">
        <a href="backup.php" class="card settings-card h-100 p-4">
            <div class="card-icon icon-backup"><i class="fas fa-database"></i></div>
            <div class="card-title">Backup & Restore</div>
            <div class="card-desc">Download database backups or restore from a previous backup file.</div>
            <div class="card-link">Configure <i class="fas fa-arrow-right ms-1"></i></div>
        </a>
    </div>
</div>

<?php include 'footer.php'; ?>
