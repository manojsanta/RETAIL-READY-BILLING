<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$editId = intval($_GET['edit'] ?? 0);
$editTax = null;
if ($editId > 0) {
    $editTax = fetch("SELECT * FROM tax_rates WHERE id = ?", [$editId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: tax_settings.php');
        exit;
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $delId = intval($_POST['tax_id'] ?? 0);
        if ($delId > 0) {
            $used = dbCount("SELECT COUNT(*) FROM items WHERE tax_rate_id = ?", [$delId]);
            if ($used > 0) {
                setFlash('danger', 'Cannot delete: this tax rate is assigned to one or more items.');
            } else {
                query("DELETE FROM tax_rates WHERE id = ?", [$delId]);
                setFlash('success', 'Tax rate deleted successfully.');
            }
        }
        header('Location: tax_settings.php');
        exit;
    }

    $name = sanitize($_POST['name'] ?? '');
    $rate = floatval($_POST['rate'] ?? 0);
    $type = sanitize($_POST['type'] ?? 'cgst');
    $status = isset($_POST['status']) ? 1 : 0;

    if ($name === '') {
        setFlash('danger', 'Tax name is required.');
        header('Location: tax_settings.php');
        exit;
    }
    if ($rate <= 0) {
        setFlash('danger', 'Tax rate must be greater than 0.');
        header('Location: tax_settings.php');
        exit;
    }

    if (!in_array($type, ['cgst', 'sgst', 'igst', 'cess'])) {
        $type = 'cgst';
    }

    $editIdPost = intval($_POST['edit_id'] ?? 0);
    if ($editIdPost > 0) {
        query("UPDATE tax_rates SET name=?, rate=?, type=?, status=? WHERE id=?", [$name, $rate, $type, $status, $editIdPost]);
        setFlash('success', 'Tax rate updated successfully.');
    } else {
        query("INSERT INTO tax_rates (name, rate, type, status, created_at) VALUES (?, ?, ?, ?, NOW())", [$name, $rate, $type, $status]);
        setFlash('success', 'Tax rate added successfully.');
    }
    header('Location: tax_settings.php');
    exit;
}

$taxRates = fetchAll("SELECT t.*, (SELECT COUNT(*) FROM items WHERE tax_rate_id = t.id) AS item_count FROM tax_rates t ORDER BY t.type ASC, t.rate ASC");

$pageTitle = 'Tax Settings';
include 'header.php';
?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-<?= $editTax ? 'edit' : 'plus' ?> me-1"></i> <?= $editTax ? 'Edit' : 'Add' ?> Tax Rate</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="edit_id" value="<?= $editTax['id'] ?? 0 ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($editTax['name'] ?? '') ?>" required placeholder="e.g., CGST 18%">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rate (%) <span class="text-danger">*</span></label>
                        <input type="number" name="rate" class="form-control" step="0.01" min="0.01" value="<?= $editTax['rate'] ?? '' ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="cgst" <?= ($editTax['type'] ?? '') === 'cgst' ? 'selected' : '' ?>>CGST</option>
                            <option value="sgst" <?= ($editTax['type'] ?? '') === 'sgst' ? 'selected' : '' ?>>SGST</option>
                            <option value="igst" <?= ($editTax['type'] ?? '') === 'igst' ? 'selected' : '' ?>>IGST</option>
                            <option value="cess" <?= ($editTax['type'] ?? '') === 'cess' ? 'selected' : '' ?>>CESS</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="status" id="taxStatus" value="1" <?= ($editTax['status'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="taxStatus">Active</label>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> <?= $editTax ? 'Update' : 'Add' ?></button>
                        <?php if ($editTax): ?>
                            <a href="tax_settings.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times me-1"></i> Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-percent me-1"></i> Tax Rates</h6>
                <span class="badge bg-primary"><?= count($taxRates) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Rate</th>
                            <th>Type</th>
                            <th>Items</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($taxRates)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No tax rates found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($taxRates as $tx): ?>
                                <tr>
                                    <td class="fw-semibold"><?= sanitize($tx['name']) ?></td>
                                    <td><?= number_format($tx['rate'], 2) ?>%</td>
                                    <td>
                                        <span class="badge bg-<?= $tx['type'] === 'cgst' ? 'primary' : ($tx['type'] === 'sgst' ? 'success' : ($tx['type'] === 'igst' ? 'info' : 'warning')) ?>">
                                            <?= strtoupper($tx['type']) ?>
                                        </span>
                                    </td>
                                    <td><?= intval($tx['item_count']) ?></td>
                                    <td>
                                        <span class="badge <?= $tx['status'] ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= $tx['status'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm">
                                            <a href="tax_settings.php?edit=<?= $tx['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this tax rate?');">
                                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="tax_id" value="<?= $tx['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
