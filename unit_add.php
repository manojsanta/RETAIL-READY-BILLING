<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: unit_add.php');
        exit;
    }

    $name = sanitize($_POST['name'] ?? '');
    $shortName = sanitize($_POST['short_name'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;

    if ($name === '') $errors[] = 'Unit name is required.';
    if ($shortName === '') $errors[] = 'Short name is required.';

    $existing = fetch("SELECT id FROM units WHERE name = ?", [$name]);
    if ($existing) $errors[] = 'A unit with this name already exists.';

    if (empty($errors)) {
        global $pdo;
        query("INSERT INTO units (name, short_name, status) VALUES (?, ?, ?)", [$name, $shortName, $status]);
        $unitId = (int)$pdo->lastInsertId();

        $compoundBaseUnits = $_POST['compound_base_unit'] ?? [];
        $compoundFactors = $_POST['compound_factor'] ?? [];
        for ($i = 0; $i < count($compoundBaseUnits); $i++) {
            $baseId = (int)$compoundBaseUnits[$i];
            $factor = (float)($compoundFactors[$i] ?? 0);
            if ($baseId > 0 && $factor > 0) {
                query("INSERT INTO unit_compounds (unit_id, base_unit_id, conversion_factor) VALUES (?, ?, ?)", [$unitId, $baseId, $factor]);
            }
        }

        setFlash('success', 'Unit created successfully.');
        header('Location: units.php');
        exit;
    }
}

$allUnits = fetchAll("SELECT id, name, short_name FROM units WHERE status = 1 ORDER BY name ASC");
$pageTitle = 'Add Unit';
include 'header.php';
?>

<style>
    .compound-row { background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px; padding:14px; margin-bottom:10px; }
    .compound-row .form-control, .compound-row .form-select { font-size:13px; }
</style>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Unit</h5>
                <a href="units.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="POST" id="unitForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-info-circle me-1"></i> Basic Information</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unit Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= h($_POST['name'] ?? '') ?>" placeholder="e.g. Kilogram" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Short Name <span class="text-danger">*</span></label>
                            <input type="text" name="short_name" class="form-control" value="<?= h($_POST['short_name'] ?? '') ?>" placeholder="e.g. Kg" required maxlength="20">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" value="1" checked>
                                <label class="form-check-label fw-semibold" style="font-size:13px;">Active</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-link me-1"></i> Compound Unit <small class="fw-normal text-muted">(optional)</small></h6>
                    <p class="text-muted mb-3" style="font-size:12px;">Define how this unit relates to other base units. E.g. <strong>1 Box = 12 Pieces</strong>, <strong>1 Carton = 6 Boxes</strong></p>

                    <div id="compoundContainer">
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-success mb-4" onclick="addCompound()">
                        <i class="fas fa-plus me-1"></i> Add Compound Relation
                    </button>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn" style="background:#ed1a3b;color:#fff;height:42px;padding:0 24px;"><i class="fas fa-save me-1"></i> Save Unit</button>
                        <a href="units.php" class="btn btn-outline-secondary" style="height:42px;padding:0 24px;"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var allUnits = <?= json_encode($allUnits) ?>;
var compoundIndex = 0;

function addCompound() {
    var container = document.getElementById('compoundContainer');
    var html = '<div class="compound-row" id="compound_' + compoundIndex + '">';
    html += '<div class="row g-2 align-items-end">';
    html += '<div class="col-md-1 text-center fw-bold" style="font-size:20px;padding-top:10px;">1</div>';
    html += '<div class="col-md-1 text-center fw-bold" style="font-size:14px;padding-top:10px;">' + document.querySelector('input[name="short_name"]').value + ' =</div>';
    html += '<div class="col-md-3">';
    html += '<label class="form-label fw-semibold" style="font-size:12px;">Conversion Factor</label>';
    html += '<input type="number" name="compound_factor[]" class="form-control form-control-sm" step="0.0001" min="0.0001" placeholder="e.g. 12" required>';
    html += '</div>';
    html += '<div class="col-md-1 text-center fw-bold" style="font-size:14px;padding-top:10px;">x</div>';
    html += '<div class="col-md-4">';
    html += '<label class="form-label fw-semibold" style="font-size:12px;">Base Unit</label>';
    html += '<select name="compound_base_unit[]" class="form-select form-select-sm" required>';
    html += '<option value="">Select base unit</option>';
    for (var i = 0; i < allUnits.length; i++) {
        html += '<option value="' + allUnits[i].id + '">' + allUnits[i].name + ' (' + allUnits[i].short_name + ')</option>';
    }
    html += '</select></div>';
    html += '<div class="col-md-2">';
    html += '<button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeCompound(' + compoundIndex + ')"><i class="fas fa-trash"></i> Remove</button>';
    html += '</div>';
    html += '</div></div>';
    container.insertAdjacentHTML('beforeend', html);
    compoundIndex++;
}

function removeCompound(idx) {
    var el = document.getElementById('compound_' + idx);
    if (el) el.remove();
}
</script>

<?php include 'footer.php'; ?>
