<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: party_add.php');
        exit;
    }

    $type = sanitize($_POST['type'] ?? 'customer');
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $state = sanitize($_POST['state'] ?? '');
    $pincode = sanitize($_POST['pincode'] ?? '');
    $gstin = sanitize($_POST['gstin'] ?? '');
    $pan = sanitize($_POST['pan'] ?? '');
    $gst_reg_type = sanitize($_POST['gst_reg_type'] ?? '');
    $opening_balance = (float)($_POST['opening_balance'] ?? 0);
    $balance_type = sanitize($_POST['balance_type'] ?? 'credit');
    $party_group = sanitize($_POST['party_group'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');

    if ($name === '') {
        setFlash('danger', 'Party name is required.');
        header('Location: party_add.php');
        exit;
    }

    if (!in_array($type, ['customer', 'supplier', 'both'])) {
        $type = 'customer';
    }

    $statusInt = ($status === 'active') ? 1 : 0;

    if ($balance_type === 'debit' && $opening_balance > 0) {
        $opening_balance = -$opening_balance;
    }

    query("INSERT INTO parties (type, name, phone, email, address, city, state, pincode, gstin, pan, gst_reg_type, opening_balance, party_group, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())", [
        $type, $name, $phone, $email, $address, $city, $state, $pincode, $gstin, $pan, $gst_reg_type, $opening_balance, $party_group, $notes, $statusInt
    ]);

    setFlash('success', 'Party added successfully.');
    header('Location: parties.php');
    exit;
}

$pageTitle = 'Add Party';
include 'header.php';
?>

<?php $old = $_POST; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <div class="vy-add-grid">

        <!-- LEFT COLUMN -->
        <div class="vy-add-left">

            <!-- Party Type -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-users"></i> Party Type</div>
                <div class="vy-card-body">
                    <div class="vy-tax-pills" id="typePills">
                        <button type="button" class="vy-pill active" data-val="customer" onclick="setType(this)"><i class="fas fa-user me-1"></i> Customer</button>
                        <button type="button" class="vy-pill" data-val="supplier" onclick="setType(this)"><i class="fas fa-truck me-1"></i> Supplier</button>
                        <button type="button" class="vy-pill" data-val="both" onclick="setType(this)"><i class="fas fa-arrows-left-right me-1"></i> Both</button>
                    </div>
                    <input type="hidden" name="type" id="typeInput" value="<?= h($old['type'] ?? 'customer') ?>">
                </div>
            </div>

            <!-- Contact Details -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-id-card"></i> Contact Details</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Party Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required placeholder="e.g. Rahul Traders" value="<?= h($old['name'] ?? '') ?>">
                    </div>
                    <div class="vy-f-row">
                        <div class="vy-f">
                            <label>Phone</label>
                            <input type="text" name="phone" placeholder="9876543210" value="<?= h($old['phone'] ?? '') ?>">
                        </div>
                        <div class="vy-f">
                            <label>Email</label>
                            <input type="email" name="email" placeholder="rahul@email.com" value="<?= h($old['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Party Group</label>
                        <input type="text" name="party_group" placeholder="e.g. Wholesalers, Retailers" value="<?= h($old['party_group'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-location-dot"></i> Address</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Address</label>
                        <textarea name="address" rows="2" placeholder="Full address..."><?= h($old['address'] ?? '') ?></textarea>
                    </div>
                    <div class="vy-f-row">
                        <div class="vy-f">
                            <label>City</label>
                            <input type="text" name="city" placeholder="Mumbai" value="<?= h($old['city'] ?? '') ?>">
                        </div>
                        <div class="vy-f">
                            <label>State</label>
                            <input type="text" name="state" placeholder="Maharashtra" value="<?= h($old['state'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Pincode</label>
                        <input type="text" name="pincode" maxlength="6" placeholder="400001" value="<?= h($old['pincode'] ?? '') ?>">
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="vy-add-right">

            <!-- Tax & Compliance -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-file-invoice"></i> Tax & Compliance</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>GST Registration Type</label>
                        <select name="gst_reg_type">
                            <option value="">-- Select --</option>
                            <?php
                            $gstTypes = [
                                'unregistered' => 'Unregistered / Consumer',
                                'regular'      => 'Regular Taxable Person',
                                'composition'  => 'Composition Taxable Person',
                                'sez_unit'     => 'SEZ Unit',
                                'sez_dev'      => 'SEZ Developer',
                                'non_resident' => 'Non-Resident Taxable Person',
                                'oidar'        => 'Non-Resident Online (OIDAR)',
                                'isd'          => 'Input Service Distributor (ISD)',
                                'tds'          => 'Tax Deductor',
                                'tcs'          => 'Tax Collector (eTCS)',
                                'urp'          => 'URP (Unregistered Person)',
                            ];
                            $selGST = $old['gst_reg_type'] ?? '';
                            foreach ($gstTypes as $val => $label):
                            ?>
                                <option value="<?= $val ?>" <?= $selGST === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vy-f">
                        <label>GSTIN</label>
                        <input type="text" name="gstin" maxlength="15" placeholder="22AAAAA0000A1Z5" value="<?= h($old['gstin'] ?? '') ?>" style="text-transform:uppercase;">
                    </div>
                    <div class="vy-f">
                        <label>PAN</label>
                        <input type="text" name="pan" maxlength="10" placeholder="AAAAA0000A" value="<?= h($old['pan'] ?? '') ?>" style="text-transform:uppercase;">
                    </div>
                </div>
            </div>

            <!-- Financial Details -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-indian-rupee-sign"></i> Opening Balance</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Amount</label>
                        <div class="vy-input-addon">
                            <span>&#8377;</span>
                            <input type="number" step="0.01" min="0" name="opening_balance" value="<?= h($old['opening_balance'] ?? '0') ?>">
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Type</label>
                        <div class="d-flex gap-2" id="balanceTypePills">
                            <button type="button" class="btn btn-sm rounded-pill fw-semibold <?= ($old['balance_type'] ?? 'credit') === 'credit' ? 'btn-success' : 'btn-outline-secondary' ?>" onclick="setBalanceType('credit', this)"><i class="fas fa-arrow-down me-1"></i> You'll Receive</button>
                            <button type="button" class="btn btn-sm rounded-pill fw-semibold <?= ($old['balance_type'] ?? 'credit') === 'debit' ? 'btn-danger' : 'btn-outline-secondary' ?>" onclick="setBalanceType('debit', this)"><i class="fas fa-arrow-up me-1"></i> You'll Pay</button>
                        </div>
                        <input type="hidden" name="balance_type" id="balanceTypeInput" value="<?= h($old['balance_type'] ?? 'credit') ?>">
                        <div class="mt-2" style="font-size:11px;color:#888;">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="balanceHint">Party owes you money (Receivable)</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-sticky-note"></i> Notes</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <textarea name="notes" rows="3" placeholder="Any additional notes about this party..."><?= h($old['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- Full Width Actions -->
    <div class="vy-form-actions">
        <div class="d-flex align-items-center gap-3">
            <a href="parties.php" class="btn btn-light btn-lg"><i class="fas fa-xmark me-1"></i> Cancel</a>
            <div class="d-flex align-items-center gap-2 px-3 py-2 rounded" style="background:#f1f3f5;">
                <label class="form-check-label fw-semibold" style="font-size:13px;color:#495057;">Active</label>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="status" value="active" <?= ($old['status'] ?? 'active') === 'active' ? 'checked' : '' ?> onchange="this.value = this.checked ? 'active' : 'inactive'" style="cursor:pointer;">
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-check me-1"></i> Save Party</button>
    </div>
</form>

<style>
/* Grid Layout */
.vy-add-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
}

/* Cards */
.vy-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    overflow: hidden;
    margin-bottom: 0;
}
.vy-card + .vy-card { margin-top: 16px; }
.vy-add-left .vy-card + .vy-card,
.vy-add-right .vy-card + .vy-card { margin-top: 16px; }

.vy-card-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary-color);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 20px;
    background: var(--primary-light);
    border-bottom: 1px solid rgba(237,26,59,0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}
.vy-card-head i { font-size: 13px; }
.vy-card-body { padding: 18px 20px; }

/* Fields */
.vy-f { margin-bottom: 14px; }
.vy-f:last-child { margin-bottom: 0; }
.vy-f label {
    font-size: 12px; font-weight: 600; color: #555;
    margin-bottom: 5px; display: block;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.vy-f label .text-danger { color: var(--danger-color); }
.vy-f input, .vy-f select, .vy-f textarea {
    width: 100%; height: 40px; border: 1px solid #e0e0e0; border-radius: 8px;
    padding: 0 12px; font-size: 14px; color: #1a1a1a; background: #fafafa;
    transition: border 0.2s;
}
.vy-f textarea { height: auto; padding: 10px 12px; resize: vertical; min-height: 64px; }
.vy-f input:focus, .vy-f select:focus, .vy-f textarea:focus {
    outline: none; border-color: var(--primary-color); background: #fff;
    box-shadow: 0 0 0 3px rgba(237, 26, 59, 0.08);
}
.vy-f input::placeholder, .vy-f textarea::placeholder { color: #bbb; }

.vy-f-row { display: flex; gap: 12px; }
.vy-f-row .vy-f { flex: 1; }

/* Price Input */
.vy-input-addon { display: flex; align-items: stretch; }
.vy-input-addon span {
    display: flex; align-items: center; justify-content: center;
    width: 38px; background: #f0f0f0; border: 1px solid #e0e0e0;
    border-right: none; border-radius: 8px 0 0 8px;
    font-size: 13px; font-weight: 700; color: #888;
}
.vy-input-addon input {
    border-radius: 0 8px 8px 0 !important; flex: 1;
}

/* Tax Toggle Pills */
.vy-tax-pills {
    display: flex; gap: 6px;
}
.vy-pill {
    border: 1px solid #e0e0e0; background: #fafafa;
    padding: 8px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 600; color: #888; cursor: pointer; transition: 0.2s;
}
.vy-pill.active { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
.vy-pill:hover:not(.active) { background: #f0f0f0; }

/* Actions */
.vy-form-actions {
    display: flex; justify-content: flex-end; gap: 12px;
    padding: 16px 0 20px;
}
.vy-form-actions .btn { border-radius: 10px; font-weight: 600; min-width: 140px; height: 46px; }

/* Responsive */
@media (max-width: 991px) {
    .vy-add-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
function setType(btn) {
    document.getElementById('typeInput').value = btn.dataset.val;
    document.querySelectorAll('#typePills .vy-pill').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
}

function setBalanceType(val, btn) {
    document.getElementById('balanceTypeInput').value = val;
    var hint = document.getElementById('balanceHint');
    var pills = document.querySelectorAll('#balanceTypePills .btn');
    pills.forEach(function(b) {
        b.classList.remove('btn-success', 'btn-danger');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    if (val === 'credit') {
        btn.classList.add('btn-success');
        hint.textContent = 'Party owes you money (Receivable)';
    } else {
        btn.classList.add('btn-danger');
        hint.textContent = 'You owe party money (Payable)';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    setType(document.querySelector('#typePills .vy-pill[data-val="<?= h($old['type'] ?? 'customer') ?>"]'));
});
</script>

<?php include 'footer.php'; ?>
