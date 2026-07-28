<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger', 'Invalid request.'); redirect('purchases.php'); }
    $delId = intval($_POST['id'] ?? 0);
    if ($delId > 0) {
        $existing = fetch("SELECT id FROM purchases WHERE id = ?", [$delId]);
        if ($existing) {
            $items = fetchAll("SELECT item_id, qty FROM purchase_items WHERE purchase_id = ?", [$delId]);
            $pdo->beginTransaction();
            try {
                foreach ($items as $itm) { updateStock($itm['item_id'], $itm['qty'], 'subtract'); }
                query("DELETE FROM purchase_items WHERE purchase_id = ?", [$delId]);
                query("DELETE FROM purchases WHERE id = ?", [$delId]);
                $pdo->commit();
                setFlash('success', 'Purchase bill deleted successfully.');
            } catch (Exception $e) { $pdo->rollBack(); setFlash('danger', 'Error deleting: ' . $e->getMessage()); }
        } else { setFlash('danger', 'Purchase not found.'); }
    }
    redirect('purchases.php');
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$fy = currentFY();
$where = []; $params = [];
if (!empty($fy['start'])) { $where[] = "p.date >= ?"; $params[] = $fy['start']; }
if (!empty($fy['end'])) { $where[] = "p.date <= ?"; $params[] = $fy['end']; }
if ($dateFrom !== '') { $where[] = "p.date >= ?"; $params[] = dateDB($dateFrom); }
if ($dateTo !== '') { $where[] = "p.date <= ?"; $params[] = dateDB($dateTo); }
if ($filterStatus !== '' && in_array($filterStatus, ['paid','unpaid','partial'])) { $where[] = "p.payment_status = ?"; $params[] = $filterStatus; }
if ($search !== '') { $where[] = "(p.bill_no LIKE ? OR pt.name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalItems = (int) query("SELECT COUNT(*) FROM purchases p LEFT JOIN parties pt ON p.party_id = pt.id $whereClause", $params)->fetchColumn();
$pagination = paginate($totalItems, $perPage, $page);

$purchases = fetchAll("SELECT p.*, pt.name AS supplier_name FROM purchases p LEFT JOIN parties pt ON p.party_id = pt.id $whereClause ORDER BY p.date DESC, p.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params);

$fyWhere = []; $fyParams = [];
if (!empty($fy['start'])) { $fyWhere[] = "date >= ?"; $fyParams[] = $fy['start']; }
if (!empty($fy['end'])) { $fyWhere[] = "date <= ?"; $fyParams[] = $fy['end']; }
$fyClause = $fyWhere ? 'WHERE ' . implode(' AND ', $fyWhere) : '';

$totalPurchases = (float) query("SELECT COALESCE(SUM(total), 0) FROM purchases $fyClause", $fyParams)->fetchColumn();
$totalPaid = (float) query("SELECT COALESCE(SUM(paid_amount), 0) FROM purchases $fyClause", $fyParams)->fetchColumn();
$totalPayable = (float) query("SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE payment_status != 'paid'" . ($fyClause ? ' AND ' . substr($fyClause, 6) : ''), $fyParams)->fetchColumn();
$todayPurchases = (float) query("SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date = ?" . ($fyClause ? ' AND ' . substr($fyClause, 6) : ''), array_merge([today()], $fyParams))->fetchColumn();

$pageTitle = 'Purchase Bills';
include 'header.php';
?>

<style>
.pur-tbl th { font-size:11px; text-transform:uppercase; letter-spacing:.3px; padding:6px 8px; white-space:nowrap; }
.pur-tbl td { font-size:13px; padding:5px 8px; vertical-align:middle; }
.pur-tbl .badge { font-size:10px; padding:2px 7px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0"><i class="fas fa-receipt me-1"></i> Purchase Bills</h5>
    <a href="purchase_add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> New Purchase</a>
</div>

<div class="row g-2 mb-2">
    <div class="col-md-3"><div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Total Purchases</small><strong class="text-primary" style="font-size:18px"><?= money($totalPurchases) ?></strong></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Total Paid</small><strong class="text-success" style="font-size:18px"><?= money($totalPaid) ?></strong></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Total Payable</small><strong class="text-danger" style="font-size:18px"><?= money($totalPayable) ?></strong></div></div></div>
    <div class="col-md-3"><div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Today</small><strong class="text-info" style="font-size:18px"><?= money($todayPurchases) ?></strong></div></div></div>
</div>

<div class="card mb-2">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2"><input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>" placeholder="From"></div>
            <div class="col-md-2"><input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>"></div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $filterStatus === 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            <div class="col-md-3"><input type="text" name="search" class="form-control form-control-sm" placeholder="Bill No, Supplier..." value="<?= sanitize($search) ?>"></div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fas fa-search me-1"></i> Filter</button>
                    <a href="purchases.php" class="btn btn-sm btn-outline-secondary flex-fill"><i class="fas fa-rotate-left me-1"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mb-2">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 pur-tbl">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Bill No</th><th>Supplier</th><th>Date</th>
                    <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th>
                    <th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No purchase bills found.</td></tr>
                <?php else: foreach ($purchases as $idx => $pur): ?>
                    <tr>
                        <td><?= $pagination['offset'] + $idx + 1 ?></td>
                        <td><a href="purchase_view.php?id=<?= $pur['id'] ?>" class="fw-semibold text-decoration-none"><?= sanitize($pur['bill_no']) ?></a></td>
                        <td><?= $pur['party_id'] ? '<a href="party_view.php?id='.$pur['party_id'].'" class="text-decoration-none">'.h($pur['supplier_name'] ?? '-').'</a>' : '<span class="text-muted">Walk-in</span>' ?></td>
                        <td><?= dateFormatted($pur['date']) ?></td>
                        <td class="text-end fw-bold"><?= money($pur['total']) ?></td>
                        <td class="text-end text-success"><?= money($pur['paid_amount']) ?></td>
                        <td class="text-end <?= $pur['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= money($pur['due_amount']) ?></td>
                        <td><span class="badge <?= $pur['payment_status'] === 'paid' ? 'bg-success' : ($pur['payment_status'] === 'partial' ? 'bg-warning text-dark' : 'bg-danger') ?>"><?= ucfirst($pur['payment_status']) ?></span></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="purchase_view.php?id=<?= $pur['id'] ?>" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                                <a href="purchase_edit.php?id=<?= $pur['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this purchase bill?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $pur['id'] ?>">
                                    <?= csrfField() ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$filterParams = [];
if ($dateFrom !== '') $filterParams['date_from'] = $dateFrom;
if ($dateTo !== '') $filterParams['date_to'] = $dateTo;
if ($filterStatus !== '') $filterParams['status'] = $filterStatus;
if ($search !== '') $filterParams['search'] = $search;
echo paginationLinks($pagination, 'purchases.php' . ($filterParams ? '?' . http_build_query($filterParams) : ''));
include 'footer.php';
