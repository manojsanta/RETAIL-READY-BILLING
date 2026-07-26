<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) { setFlash('danger', 'Invalid request.'); redirect('stock.php'); }
    $itemId = intval($_POST['item_id'] ?? 0);
    $adjType = trim($_POST['adjustment_type'] ?? '');
    $qty = intval($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $adjDate = trim($_POST['adjustment_date'] ?? date('Y-m-d'));
    if ($itemId <= 0) { setFlash('danger', 'Select an item.'); redirect('stock.php'); }
    if ($qty <= 0) { setFlash('danger', 'Quantity must be positive.'); redirect('stock.php'); }
    if (!in_array($adjType, ['addition','subtraction','damage','expired','correction'])) { setFlash('danger', 'Invalid adjustment type.'); redirect('stock.php'); }
    $itemRow = fetch("SELECT current_stock FROM items WHERE id = ?", [$itemId]);
    if (!$itemRow) { setFlash('danger', 'Item not found.'); redirect('stock.php'); }
    $newStock = $adjType === 'addition' ? intval($itemRow['current_stock']) + $qty : max(0, intval($itemRow['current_stock']) - $qty);
    query("UPDATE items SET current_stock = ?, updated_at = NOW() WHERE id = ?", [$newStock, $itemId]);
    query("INSERT INTO stock_adjustments (item_id, adjustment_type, qty, reason, adjustment_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())", [$itemId, $adjType, $qty, $reason, $adjDate, $_SESSION['user_id'] ?? null]);
    setFlash('success', 'Stock adjustment saved.');
    redirect('stock.php');
}

$filterCategory = intval($_GET['category'] ?? 0);
$filterStock = $_GET['stock_status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = []; $params = [];
if ($filterCategory > 0) { $where[] = "i.category_id = ?"; $params[] = $filterCategory; }
if ($filterStock === 'in_stock') { $where[] = "i.current_stock > i.min_stock"; }
elseif ($filterStock === 'low_stock') { $where[] = "i.current_stock > 0 AND i.current_stock <= i.min_stock"; }
elseif ($filterStock === 'out_of_stock') { $where[] = "i.current_stock <= 0"; }
if ($search !== '') { $where[] = "(i.name LIKE ? OR i.sku LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stockItems = fetchAll("SELECT i.id, i.name, i.sku, i.unit, i.purchase_price, i.min_stock, i.current_stock, i.opening_stock,
    c.name AS category_name,
    COALESCE((SELECT SUM(pi.qty) FROM purchase_items pi JOIN purchases p ON pi.purchase_id = p.id WHERE pi.item_id = i.id AND p.status != 'cancelled'), 0) AS purchased_qty,
    COALESCE((SELECT SUM(si.qty) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.item_id = i.id AND s.status != 'cancelled'), 0) AS sold_qty,
    COALESCE((SELECT SUM(CASE WHEN sa.adjustment_type = 'addition' THEN sa.qty WHEN sa.adjustment_type = 'correction' THEN sa.qty ELSE -sa.qty END) FROM stock_adjustments sa WHERE sa.item_id = i.id), 0) AS adjusted_qty
    FROM items i LEFT JOIN categories c ON i.category_id = c.id $whereSql ORDER BY i.name ASC", $params);

$totalStockValue = $totalStock = $totalPurchased = $totalSold = 0;
foreach ($stockItems as $si) {
    $totalStockValue += $si['current_stock'] * $si['purchase_price'];
    $totalStock += $si['current_stock'];
    $totalPurchased += $si['purchased_qty'];
    $totalSold += $si['sold_qty'];
}

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allItems = $pdo->query("SELECT id, name, sku FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Stock Summary';
include 'header.php';
?>

<style>
.stock-tbl th { font-size:11px; text-transform:uppercase; letter-spacing:.3px; padding:6px 8px; white-space:nowrap; }
.stock-tbl td { font-size:13px; padding:5px 8px; vertical-align:middle; }
.stock-tbl .badge { font-size:10px; padding:2px 7px; }
.stock-adj label { font-size:11px; text-transform:uppercase; letter-spacing:.3px; color:#666; margin-bottom:2px; }
.stock-adj .form-control, .stock-adj .form-select { font-size:13px; padding:5px 8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0"><i class="fa fa-boxes-stacked me-1"></i> Stock Summary</h5>
</div>

<div class="row g-2 mb-2">
    <div class="col-md-3">
        <div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Total Items</small><strong style="font-size:18px"><?= count($stockItems) ?></strong></div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Total Stock Qty</small><strong style="font-size:18px"><?= number_format($totalStock) ?></strong></div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Stock Value</small><strong style="font-size:18px"><?= formatMoney($totalStockValue) ?></strong></div></div>
    </div>
    <div class="col-md-3">
        <div class="card"><div class="card-body py-2 text-center"><small class="text-muted d-block" style="font-size:11px">Purchased / Sold</small><strong style="font-size:18px"><?= number_format($totalPurchased) ?> / <?= number_format($totalSold) ?></strong></div></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <select name="category" class="form-select form-select-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="stock_status" class="form-select form-select-sm">
                    <option value="">All Stock</option>
                    <option value="in_stock" <?= $filterStock === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $filterStock === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $filterStock === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name or SKU..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fa fa-search me-1"></i> Filter</button>
                    <a href="stock.php" class="btn btn-sm btn-outline-secondary flex-fill"><i class="fa fa-rotate-left me-1"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 stock-tbl">
            <thead class="table-light">
                <tr>
                    <th>Item</th><th>SKU</th><th>Category</th><th class="text-end">Opening</th>
                    <th class="text-end">Purchased</th><th class="text-end">Sold</th><th class="text-end">Adjusted</th>
                    <th class="text-end">Current</th><th class="text-end">Value</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockItems)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">No items found.</td></tr>
                <?php else: foreach ($stockItems as $si):
                    $stock = intval($si['current_stock']); $min = intval($si['min_stock']);
                    if ($stock <= 0) { $bc = 'bg-danger'; $sl = 'Out of Stock'; }
                    elseif ($stock <= $min) { $bc = 'bg-warning text-dark'; $sl = 'Low Stock'; }
                    else { $bc = 'bg-success'; $sl = 'In Stock'; }
                ?>
                    <tr>
                        <td><strong><?= h($si['name']) ?></strong></td>
                        <td><code><?= h($si['sku']) ?></code></td>
                        <td><?= h($si['category_name'] ?? 'N/A') ?></td>
                        <td class="text-end"><?= $si['opening_stock'] ?></td>
                        <td class="text-end"><?= $si['purchased_qty'] ?></td>
                        <td class="text-end"><?= $si['sold_qty'] ?></td>
                        <td class="text-end"><?= $si['adjusted_qty'] ?></td>
                        <td class="text-end"><strong><?= $stock ?></strong> <?= h($si['unit']) ?></td>
                        <td class="text-end"><?= formatMoney($stock * $si['purchase_price']) ?></td>
                        <td><span class="badge <?= $bc ?>"><?= $sl ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card stock-adj">
    <div class="card-header py-2">
        <h6 class="mb-0"><i class="fa fa-sliders-h me-1"></i> Stock Adjustment</h6>
    </div>
    <div class="card-body py-2">
        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="adjust">
            <div class="col-md-3">
                <label>Item <span class="text-danger">*</span></label>
                <select name="item_id" class="form-select form-select-sm" required>
                    <option value="">-- Select --</option>
                    <?php foreach ($allItems as $ai): ?>
                        <option value="<?= $ai['id'] ?>"><?= h($ai['name']) ?> (<?= h($ai['sku']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Type <span class="text-danger">*</span></label>
                <select name="adjustment_type" class="form-select form-select-sm" required>
                    <option value="addition">Addition</option>
                    <option value="subtraction">Subtraction</option>
                    <option value="damage">Damage</option>
                    <option value="expired">Expired</option>
                    <option value="correction">Correction</option>
                </select>
            </div>
            <div class="col-md-1">
                <label>Qty <span class="text-danger">*</span></label>
                <input type="number" name="quantity" class="form-control form-control-sm" min="1" required>
            </div>
            <div class="col-md-2">
                <label>Date</label>
                <input type="date" name="adjustment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label>Reason</label>
                <input type="text" name="reason" class="form-control form-control-sm" placeholder="Optional reason">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
