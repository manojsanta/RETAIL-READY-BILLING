<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: units.php'); exit; }

$unit = fetch("SELECT * FROM units WHERE id = ?", [$id]);
if (!$unit) { setFlash('danger', 'Unit not found.'); header('Location: units.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: unit_edit.php?id=' . $id);
        exit;
    }

    $name = sanitize($_POST['name'] ?? '');
    $shortName = sanitize($_POST['short_name'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;

    if ($name === '') $errors[] = 'Unit name is required.';
    if ($shortName === '') $errors[] = 'Short name is required.';

    $existing = fetch("SELECT id FROM units WHERE name = ? AND id != ?", [$name, $id]);
    if ($existing) $errors[] = 'A unit with this name already exists.';

    if (empty($errors)) {
        query("UPDATE units SET name=?, short_name=?, status=? WHERE id=?", [$name, $shortName, $status, $id]);

        query("DELETE FROM unit_compounds WHERE unit_id = ?", [$id]);
        $compoundBaseUnits = $_POST['compound_base_unit'] ?? [];
        $compoundFactors = $_POST['compound_factor'] ?? [];
        for ($i = 0; $i < count($compoundBaseUnits); $i++) {
            $baseId = (int)$compoundBaseUnits[$i];
            $factor = (float)($compoundFactors[$i] ?? 0);
            if ($baseId > 0 && $factor > 0) {
                query("INSERT INTO unit_compounds (unit_id, base_unit_id, conversion_factor) VALUES (?, ?, ?)", [$id, $baseId, $factor]);
            }
        }

        setFlash('success', 'Unit updated successfully.');
        header('Location: units.php');
        exit;
    }
}

$compounds = fetchAll("SELECT uc.*, bu.name AS base_name, bu.short_name AS base_short FROM unit_compounds uc JOIN units bu ON uc.base_unit_id = bu.id WHERE uc.unit_id = ?", [$id]);
$allUnits = fetchAll("SELECT id, name, short_name FROM units WHERE status = 1 AND id != ? ORDER BY name ASC", [$id]);
$pageTitle = 'Edit Unit';
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
                <h5 class="mb-0"><i class="fas fa-pen me-2"></i>Edit Unit</h5>
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
                            <input type="text" name="name" class="form-control" value="<?= h($unit['name']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Short Name <span class="text-danger">*</span></label>
                            <input type="text" name="short_name" class="form-control" value="<?= h($unit['short_name']) ?>" required maxlength="20">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" value="1" <?= $unit['status'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" style="font-size:13px;">Active</label>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-link me-1"></i> Compound Unit <small class="fw-normal text-muted">(optional)</small></h6>
                    <p class="text-muted mb-3" style="font-size:12px;">Define how this unit relates to other base units. E.g. <strong>1 Box = 12 Pieces</strong>, <strong>1 Carton = 6 Boxes</strong></p>

                    <div id="compoundContainer">
                        <?php foreach ($compounds as $idx => $c): ?>
                            <div class="compound-row" id="compound_<?= $idx ?>">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-1 text-center fw-bold" style="font-size:20px;padding-top:10px;">1</div>
                                    <div class="col-md-1 text-center fw-bold" style="font-size:14px;padding-top:10px;"><?= h($unit['short_name']) ?> =</div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold" style="font-size:12px;">Conversion Factor</label>
                                        <input type="number" name="compound_factor[]" class="form-control form-control-sm" step="0.0001" min="0.0001" value="<?= $c['conversion_factor'] ?>" required>
                                    </div>
                                    <div class="col-md-1 text-center fw-bold" style="font-size:14px;padding-top:10px;">x</div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold" style="font-size:12px;">Base Unit</label>
                                        <select name="compound_base_unit[]" class="form-select form-select-sm" required>
                                            <option value="">Select base unit</option>
                                            <option value="<?= $c['base_unit_id'] ?>" selected><?= h($c['base_name']) ?> (<?= h($c['base_short']) ?>)</option>
                                            <?php foreach ($allUnits as $u): ?>
                                                <?php if ($u['id'] != $c['base_unit_id']): ?>
                                                    <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['short_name']) ?>)</option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removeCompound(<?= $idx ?>)"><i class="fas fa-trash"></i> Remove</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-success mb-4" onclick="addCompound()">
                        <i class="fas fa-plus me-1"></i> Add Compound Relation
                    </button>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn" style="background:#ed1a3b;color:#fff;height:42px;padding:0 24px;"><i class="fas fa-save me-1"></i> Update Unit</button>
                        <a href="units.php" class="btn btn-outline-secondary" style="height:42px;padding:0 24px;"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
var allUnits = <?= json_encode($allUnits) ?>;
var currentShort = <?= json_encode($unit['short_name']) ?>;
var compoundIndex = <?= count($compounds) ?>;

function addCompound() {
    var container = document.getElementById('compoundContainer');
    var shortVal = document.querySelector('input[name="short_name"]').value || currentShort;
    var html = '<div class="compound-row" id="compound_' + compoundIndex + '">';
    html += '<div class="row g-2 align-items-end">';
    html += '<div class="col-md-1 text-center fw-bold" style="font-size:20px;padding-top:10px;">1</div>';
    html += '<div class="col-md-1 text-center fw-bold" style="font-size:14px;padding-top:10px;">' + shortVal + ' =</div>';
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
