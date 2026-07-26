<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$mode = $_GET['mode'] ?? 'list';
$editId = intval($_GET['edit'] ?? 0);
$editChallan = null;

if ($editId > 0) {
    $editChallan = fetch("SELECT * FROM delivery_challans WHERE id = ?", [$editId]);
    if ($editChallan) $mode = 'form';
}

// Handle Mark as Delivered
if (isset($_GET['deliver']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: delivery_challans.php');
        exit;
    }
    $delId = intval($_GET['deliver']);
    query("UPDATE delivery_challans SET status = 'delivered' WHERE id = ? AND status = 'pending'", [$delId]);
    setFlash('success', 'Challan marked as delivered.');
    header('Location: delivery_challans.php');
    exit;
}

// Handle Delete
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: delivery_challans.php');
        exit;
    }
    $delId = intval($_GET['delete']);
    query("DELETE FROM delivery_challans WHERE id = ?", [$delId]);
    setFlash('success', 'Challan deleted successfully.');
    header('Location: delivery_challans.php');
    exit;
}

// Handle POST (Save Challan)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: delivery_challans.php');
        exit;
    }

    $partyId = intval($_POST['party_id'] ?? 0);
    $challanDate = sanitize($_POST['date'] ?? today());
    $itemsDesc = sanitize($_POST['items_description'] ?? '');
    $vehicleNo = sanitize($_POST['vehicle_no'] ?? '');
    $driverName = sanitize($_POST['driver_name'] ?? '');
    $destination = sanitize($_POST['destination'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    $status = sanitize($_POST['status'] ?? 'pending');

    if (!in_array($status, ['pending', 'delivered', 'cancelled'])) $status = 'pending';

    $editIdPost = intval($_POST['edit_id'] ?? 0);
    if ($editIdPost > 0) {
        query("UPDATE delivery_challans SET party_id=?, date=?, items_description=?, vehicle_no=?, driver_name=?, destination=?, notes=?, status=? WHERE id=?",
            [$partyId ?: null, $challanDate, $itemsDesc, $vehicleNo, $driverName, $destination, $notes, $status, $editIdPost]);
        setFlash('success', 'Challan updated successfully.');
    } else {
        $challanNo = generateChallanNo();
        insertId(
            "INSERT INTO delivery_challans (challan_no, party_id, user_id, date, items_description, vehicle_no, driver_name, destination, notes, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$challanNo, $partyId ?: null, $_SESSION['user_id'], $challanDate, $itemsDesc, $vehicleNo, $driverName, $destination, $notes, $status]
        );
        setFlash('success', 'Delivery challan created successfully.');
    }
    header('Location: delivery_challans.php');
    exit;
}

$customers = fetchAll("SELECT id, name, phone FROM parties WHERE status = 1 ORDER BY name ASC");

// List view
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(dc.challan_no LIKE ? OR p.name LIKE ? OR dc.vehicle_no LIKE ? OR dc.destination LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'delivered', 'cancelled'])) {
    $where[] = "dc.status = ?";
    $params[] = $statusFilter;
}
$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$totalChallans = count("SELECT COUNT(*) FROM delivery_challans dc LEFT JOIN parties p ON dc.party_id = p.id $whereSql", $params);
$pagination = paginate($totalChallans, $perPage, $page);

$challans = fetchAll(
    "SELECT dc.*, p.name AS party_name FROM delivery_challans dc LEFT JOIN parties p ON dc.party_id = p.id $whereSql ORDER BY dc.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Delivery Challans';
include 'header.php';
?>

<?php if ($mode === 'form'): ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-truck me-1"></i> <?= $editChallan ? 'Edit Delivery Challan' : 'New Delivery Challan' ?></h5>
                <a href="delivery_challans.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="edit_id" value="<?= $editChallan['id'] ?? 0 ?>">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Challan No</label>
                            <input type="text" class="form-control" value="<?= sanitize($editChallan['challan_no'] ?? generateChallanNo()) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Date</label>
                            <input type="date" name="date" class="form-control" value="<?= sanitize($editChallan['date'] ?? today()) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="pending" <?= ($editChallan['status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="delivered" <?= ($editChallan['status'] ?? '') === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                <option value="cancelled" <?= ($editChallan['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Party / Customer</label>
                            <select name="party_id" class="form-select">
                                <option value="">-- Select Party --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($editChallan['party_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?><?= !empty($c['phone']) ? ' (' . sanitize($c['phone']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Vehicle No</label>
                            <input type="text" name="vehicle_no" class="form-control" value="<?= sanitize($editChallan['vehicle_no'] ?? '') ?>" placeholder="e.g., MH-12-AB-1234">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Driver Name</label>
                            <input type="text" name="driver_name" class="form-control" value="<?= sanitize($editChallan['driver_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Destination</label>
                            <input type="text" name="destination" class="form-control" value="<?= sanitize($editChallan['destination'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Items Description</label>
                            <textarea name="items_description" class="form-control" rows="3" placeholder="Describe the items being delivered..."><?= sanitize($editChallan['items_description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."><?= sanitize($editChallan['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> <?= $editChallan ? 'Update Challan' : 'Save Challan' ?></button>
                        <a href="delivery_challans.php" class="btn btn-outline-secondary"><i class="fas fa-times me-1"></i> Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="delivery_challans.php?mode=form" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Challan</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Challan No, Party, Vehicle, Destination..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status_filter" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="delivery_challans.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Challan No</th>
                    <th>Party</th>
                    <th>Date</th>
                    <th>Vehicle No</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($challans)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No delivery challans found.</td></tr>
                <?php else: ?>
                    <?php foreach ($challans as $idx => $ch): ?>
                        <tr>
                            <td><?= $pagination['offset'] + $idx + 1 ?></td>
                            <td class="fw-bold"><?= sanitize($ch['challan_no']) ?></td>
                            <td><?= sanitize($ch['party_name'] ?? 'N/A') ?></td>
                            <td><?= dateFormatted($ch['date']) ?></td>
                            <td><?= sanitize($ch['vehicle_no'] ?? '-') ?></td>
                            <td><?= sanitize($ch['destination'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $ch['status'] === 'delivered' ? 'success' : ($ch['status'] === 'cancelled' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst($ch['status']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="delivery_challans.php?mode=form&edit=<?= $ch['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if ($ch['status'] === 'pending'): ?>
                                        <a href="delivery_challans.php?deliver=<?= $ch['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-outline-success" title="Mark as Delivered" onclick="return confirm('Mark this challan as delivered?')"><i class="fas fa-check"></i></a>
                                    <?php endif; ?>
                                    <a href="delivery_challans.php?delete=<?= $ch['id'] ?>&csrf=<?= csrfToken() ?>" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this challan?')"><i class="fas fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($pagination['total_pages'] > 1): ?>
    <nav class="mt-3">
        <?php
        $baseUrl = 'delivery_challans.php?' . http_build_query(array_diff_key($_GET, ['page' => '']));
        echo paginationLinks($pagination, $baseUrl);
        ?>
    </nav>
<?php endif; ?>

<?php endif; ?>

<?php include 'footer.php'; ?>
