<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    if (!isset($_GET['csrf']) || !hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: units.php');
        exit;
    }
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        query("DELETE FROM units WHERE id = ?", [$id]);
        setFlash('success', 'Unit deleted successfully.');
    }
    header('Location: units.php');
    exit;
}

$search = sanitize($_GET['q'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE u.name LIKE ? OR u.short_name LIKE ?';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$total = (int)(fetch("SELECT COUNT(*) AS cnt FROM units u $where", $params)['cnt'] ?? 0);
$pagination = paginate($total, 20, (int)($_GET['page'] ?? 1));
$units = fetchAll(
    "SELECT u.*, (SELECT COUNT(*) FROM unit_compounds uc WHERE uc.unit_id = u.id) AS compound_count
     FROM units u $where ORDER BY u.name ASC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Units';
include 'header.php';
?>

<style>
    .unit-badge { font-size:11px; padding:3px 10px; border-radius:6px; font-weight:600; }
    .compound-text { font-size:12px; color:#6c757d; }
</style>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-ruler me-2"></i>Units</h5>
                <a href="unit_add.php" class="btn btn-sm" style="background:#ed1a3b;color:#fff;">
                    <i class="fas fa-plus me-1"></i> Add Unit
                </a>
            </div>
            <div class="card-body pb-0">
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" name="q" class="form-control" placeholder="Search units..." value="<?= h($search) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Search</button>
                    </div>
                    <div class="col-md-3">
                        <a href="units.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Unit Name</th>
                            <th>Short Name</th>
                            <th>Compound Info</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($units)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No units found.</td></tr>
                        <?php else: ?>
                            <?php $cnt = $pagination['offset'] + 1; foreach ($units as $u): ?>
                                <tr>
                                    <td><?= $cnt++ ?></td>
                                    <td class="fw-semibold"><?= h($u['name']) ?></td>
                                    <td><span class="unit-badge" style="background:#f1f3f5;color:#495057;"><?= h($u['short_name']) ?></span></td>
                                    <td class="compound-text">
                                        <?php if ($u['compound_count'] > 0): ?>
                                            <?php
                                            $compounds = fetchAll("SELECT uc.*, bu.name AS base_name, bu.short_name AS base_short FROM unit_compounds uc JOIN units bu ON uc.base_unit_id = bu.id WHERE uc.unit_id = ?", [$u['id']]);
                                            foreach ($compounds as $c):
                                            ?>
                                                <span class="me-2">
                                                    <strong><?= h($u['short_name']) ?></strong> =
                                                    <?= formatNumber($c['conversion_factor']) ?>
                                                    <?= h($c['base_short']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Base unit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($u['status']): ?>
                                            <span class="badge" style="background:rgba(40,167,69,0.1);color:#28a745;">Active</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:rgba(108,117,125,0.1);color:#6c757d;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="unit_edit.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:12px;"><i class="fas fa-pen"></i></a>
                                        <a href="units.php?action=delete&id=<?= $u['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-sm btn-outline-danger" style="font-size:12px;" onclick="return confirm('Delete this unit?');"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total > $pagination['per_page']): ?>
                <div class="card-footer">
                    <?= paginationLinks($pagination, 'units.php?q=' . urlencode($search) . '&') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
