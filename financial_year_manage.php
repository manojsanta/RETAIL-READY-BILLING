<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$pageTitle = 'Manage Financial Years';
$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Invalid security token.</div>';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name      = trim($_POST['name'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate   = trim($_POST['end_date'] ?? '');

            if (empty($name) || empty($startDate) || empty($endDate)) {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>All fields are required.</div>';
            } elseif (strtotime($endDate) <= strtotime($startDate)) {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>End date must be after start date.</div>';
            } else {
                $exists = fetch("SELECT id FROM financial_years WHERE name = ?", [$name]);
                if ($exists) {
                    $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Financial year with this name already exists.</div>';
                } else {
                    $overlap = fetch("SELECT id FROM financial_years WHERE (start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?)", [$endDate, $startDate, $endDate, $startDate]);
                    if ($overlap) {
                        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>This period overlaps with an existing financial year.</div>';
                    } else {
                        query("INSERT INTO financial_years (name, start_date, end_date) VALUES (?, ?, ?)", [$name, $startDate, $endDate]);
                        setFlash('success', 'Financial year "' . h($name) . '" created successfully.');
                        header('Location: financial_year_manage.php');
                        exit;
                    }
                }
            }
        }

        if ($action === 'edit') {
            $fyId      = (int)($_POST['fy_id'] ?? 0);
            $name      = trim($_POST['name'] ?? '');
            $startDate = trim($_POST['start_date'] ?? '');
            $endDate   = trim($_POST['end_date'] ?? '');

            if ($fyId <= 0 || empty($name) || empty($startDate) || empty($endDate)) {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>All fields are required.</div>';
            } elseif (strtotime($endDate) <= strtotime($startDate)) {
                $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>End date must be after start date.</div>';
            } else {
                $exists = fetch("SELECT id FROM financial_years WHERE name = ? AND id != ?", [$name, $fyId]);
                if ($exists) {
                    $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Financial year with this name already exists.</div>';
                } else {
                    $overlap = fetch("SELECT id FROM financial_years WHERE id != ? AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))", [$fyId, $endDate, $startDate, $endDate, $startDate]);
                    if ($overlap) {
                        $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>This period overlaps with an existing financial year.</div>';
                    } else {
                        query("UPDATE financial_years SET name = ?, start_date = ?, end_date = ? WHERE id = ?", [$name, $startDate, $endDate, $fyId]);
                        setFlash('success', 'Financial year updated successfully.');
                        header('Location: financial_year_manage.php');
                        exit;
                    }
                }
            }
        }

        if ($action === 'delete') {
            $fyId = (int)($_POST['fy_id'] ?? 0);
            $fy = getFinancialYearById($fyId);
            if ($fy) {
                if ($fy['is_active']) {
                    $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Cannot delete the active financial year. Set another as active first.</div>';
                } else {
                    query("DELETE FROM financial_years WHERE id = ?", [$fyId]);
                    setFlash('success', 'Financial year deleted successfully.');
                    header('Location: financial_year_manage.php');
                    exit;
                }
            }
        }

        if ($action === 'activate') {
            $fyId = (int)($_POST['fy_id'] ?? 0);
            $fy = getFinancialYearById($fyId);
            if ($fy) {
                query("UPDATE financial_years SET is_active = 0");
                query("UPDATE financial_years SET is_active = 1 WHERE id = ?", [$fyId]);
                setCurrentFY($fy);
                setFlash('success', '"' . h($fy['name']) . '" is now the active financial year.');
                header('Location: financial_year_manage.php');
                exit;
            }
        }
    }
}

$financialYears = getAllFinancialYears();
$activeFY = isFinancialYearSelected() ? currentFY() : null;
$skipFYCheck = true;
include 'header.php';
?>

<style>
    .fy-card-manage {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        transition: all 0.2s;
        background: #fff;
    }
    .fy-card-manage:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    }
    .fy-card-manage.is-active {
        border-color: #28a745;
        background: #f0fff4;
    }
    .fy-icon-lg {
        width: 48px; height: 48px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px; flex-shrink: 0;
    }
    .modal-content { border-radius: 12px; border: none; }
    .modal-header { border-bottom: 1px solid #f0f0f0; }
    .modal-footer { border-top: 1px solid #f0f0f0; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1 fw-bold">Financial Years</h5>
        <p class="text-muted mb-0" style="font-size:13px;">Manage financial year periods for your business</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-2"></i>Add Financial Year
    </button>
</div>

<?= $message ?>

<div class="row g-3">
    <?php if (empty($financialYears)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="fas fa-calendar-times d-block mb-3" style="font-size:48px;color:#ddd;"></i>
                    <h5 class="text-muted">No Financial Years Found</h5>
                    <p class="text-muted mb-3" style="font-size:14px;">Create your first financial year to start tracking transactions.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                        <i class="fas fa-plus me-2"></i>Create Financial Year
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($financialYears as $fy): ?>
            <?php $isActive = $activeFY && ($activeFY['id'] == $fy['id']); ?>
            <div class="col-md-6 col-lg-4">
                <div class="fy-card-manage <?= $isActive ? 'is-active' : '' ?>">
                    <div class="d-flex align-items-start gap-3 mb-3">
                        <div class="fy-icon-lg" style="background:<?= $isActive ? 'rgba(40,167,69,0.1)' : 'rgba(41,98,255,0.1)' ?>;color:<?= $isActive ? '#28a745' : '#2962FF' ?>;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="fw-bold mb-1"><?= h($fy['name']) ?></h6>
                            <small class="text-muted"><?= dateFormatted($fy['start_date']) ?> - <?= dateFormatted($fy['end_date']) ?></small>
                        </div>
                        <?php if ($isActive): ?>
                            <span class="badge bg-success" style="font-size:10px;">ACTIVE</span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if (!$isActive): ?>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="fy_id" value="<?= (int)$fy['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Set as Active">
                                    <i class="fas fa-check-circle me-1"></i>Activate
                                </button>
                            </form>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-primary" title="Edit" onclick="editFY(<?= (int)$fy['id'] ?>, '<?= h($fy['name']) ?>', '<?= $fy['start_date'] ?>', '<?= $fy['end_date'] ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if (!$isActive): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this financial year?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="fy_id" value="<?= (int)$fy['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add FY Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-calendar-plus me-2 text-primary"></i>Add Financial Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="name" placeholder="e.g. FY 2026-27" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Start Date</label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">End Date</label>
                            <input type="date" class="form-control" name="end_date" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit FY Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fas fa-calendar-check me-2 text-primary"></i>Edit Financial Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="fy_id" id="edit_fy_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Start Date</label>
                            <input type="date" class="form-control" name="start_date" id="edit_start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">End Date</label>
                            <input type="date" class="form-control" name="end_date" id="edit_end_date" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editFY(id, name, startDate, endDate) {
    document.getElementById('edit_fy_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_start_date').value = startDate;
    document.getElementById('edit_end_date').value = endDate;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php include 'footer.php'; ?>
