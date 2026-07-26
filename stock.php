<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// Handle stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        redirect('stock.php');
    }

    $itemId = intval($_POST['item_id'] ?? 0);
    $adjType = trim($_POST['adjustment_type'] ?? '');
    $qty = intval($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $adjDate = trim($_POST['adjustment_date'] ?? date('Y-m-d'));

    if ($itemId <= 0) { setFlash('danger', 'Select an item.'); redirect('stock.php'); }
    if ($qty <= 0) { setFlash('danger', 'Quantity must be positive.'); redirect('stock.php'); }
    if (!in_array($adjType, ['addition', 'subtraction', 'damage', 'expired', 'correction'])) {
        setFlash('danger', 'Invalid adjustment type.'); redirect('stock.php');
    }

    // Get current stock
    $itemStmt = $pdo->prepare("SELECT current_stock FROM items WHERE id = ?");
    $itemStmt->execute([$itemId]);
    $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);
    if (!$itemRow) { setFlash('danger', 'Item not found.'); redirect('stock.php'); }

    $currentStock = intval($itemRow['current_stock']);

    if (in_array($adjType, ['addition'])) {
        $newStock = $currentStock + $qty;
    } else {
        $newStock = max(0, $currentStock - $qty);
    }

    // Update item stock
    $upd = $pdo->prepare("UPDATE items SET current_stock = ?, updated_at = NOW() WHERE id = ?");
    $upd->execute([$newStock, $itemId]);

    // Insert adjustment record
    $ins = $pdo->prepare("INSERT INTO stock_adjustments (item_id, adjustment_type, quantity, reason, adjustment_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $ins->execute([$itemId, $adjType, $qty, $reason, $adjDate, $_SESSION['user_id'] ?? null]);

    setFlash('success', 'Stock adjustment saved.');
    redirect('stock.php');
}

// Filters
$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filterStock = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = [];
$params = [];

if ($filterCategory > 0) {
    $where[] = "i.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterStock === 'in_stock') {
    $where[] = "i.current_stock > i.min_stock";
} elseif ($filterStock === 'low_stock') {
    $where[] = "i.current_stock > 0 AND i.current_stock <= i.min_stock";
} elseif ($filterStock === 'out_of_stock') {
    $where[] = "i.current_stock <= 0";
}
if ($search !== '') {
    $where[] = "(i.name LIKE ? OR i.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Fetch stock data
$sql = "SELECT i.id, i.name, i.sku, i.unit, i.purchase_price, i.min_stock,
        i.current_stock, i.opening_stock,
        c.name as category_name,
        COALESCE((SELECT SUM(pi.quantity) FROM purchase_items pi JOIN purchases p ON pi.purchase_id = p.id WHERE pi.item_id = i.id AND p.status != 'cancelled'), 0) as purchased_qty,
        COALESCE((SELECT SUM(si.quantity) FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE si.item_id = i.id AND s.status != 'cancelled'), 0) as sold_qty,
        COALESCE((SELECT SUM(CASE WHEN sa.adjustment_type = 'addition' THEN sa.quantity WHEN sa.adjustment_type = 'correction' THEN sa.quantity ELSE -sa.quantity END) FROM stock_adjustments sa WHERE sa.item_id = i.id), 0) as adjusted_qty
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        $whereSql
        ORDER BY i.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$stockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary totals
$totalStockValue = 0;
$totalStock = 0;
$totalPurchased = 0;
$totalSold = 0;
foreach ($stockItems as $si) {
    $totalStockValue += $si['current_stock'] * $si['purchase_price'];
    $totalStock += $si['current_stock'];
    $totalPurchased += $si['purchased_qty'];
    $totalSold += $si['sold_qty'];
}

// Dropdowns
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allItems = $pdo->query("SELECT id, name, sku FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Stock Summary';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Stock Summary</h4>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Stock Status</label>
                <select name="stock_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="in_stock" <?= $filterStock === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $filterStock === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $filterStock === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Item name or SKU..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa fa-search"></i> Filter</button>
                <a href="stock.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Item Name</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Opening</th>
                    <th>Purchased</th>
                    <th>Sold</th>
                    <th>Adjusted</th>
                    <th>Current Stock</th>
                    <th>Stock Value</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockItems)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($stockItems as $si): ?>
                        <?php
                        $stock = intval($si['current_stock']);
                        $minStock = intval($si['min_stock']);
                        if ($stock <= 0) { $badgeClass = 'bg-danger'; $statusLabel = 'Out of Stock'; }
                        elseif ($stock <= $minStock) { $badgeClass = 'bg-warning text-dark'; $statusLabel = 'Low Stock'; }
                        else { $badgeClass = 'bg-success'; $statusLabel = 'In Stock'; }
                        $stockValue = $stock * $si['purchase_price'];
                        ?>
                        <tr>
                            <td><strong><?= h($si['name']) ?></strong></td>
                            <td><code><?= h($si['sku']) ?></code></td>
                            <td><?= h($si['category_name'] ?? 'N/A') ?></td>
                            <td><?= intval($si['opening_stock']) ?></td>
                            <td><?= intval($si['purchased_qty']) ?></td>
                            <td><?= intval($si['sold_qty']) ?></td>
                            <td><?= intval($si['adjusted_qty']) ?></td>
                            <td><strong><?= $stock ?></strong> <?= h($si['unit']) ?></td>
                            <td><?= formatMoney($stockValue) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($stockItems)): ?>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="3">Totals</td>
                        <td>-</td>
                        <td><?= number_format($totalPurchased) ?></td>
                        <td><?= number_format($totalSold) ?></td>
                        <td>-</td>
                        <td><?= number_format($totalStock) ?></td>
                        <td><?= formatMoney($totalStockValue) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Stock Adjustment Section -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fa fa-sliders-h"></i> Stock Adjustment</h5>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="adjust">

            <div class="col-md-3">
                <label class="form-label">Select Item <span class="text-danger">*</span></label>
                <select name="item_id" class="form-select" required>
                    <option value="">-- Select Item --</option>
                    <?php foreach ($allItems as $ai): ?>
                        <option value="<?= $ai['id'] ?>"><?= h($ai['name']) ?> (<?= h($ai['sku']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                <select name="adjustment_type" class="form-select" required>
                    <option value="addition">Addition</option>
                    <option value="subtraction">Subtraction</option>
                    <option value="damage">Damage</option>
                    <option value="expired">Expired</option>
                    <option value="correction">Correction</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" name="quantity" class="form-control" min="1" required>
            </div>

            <div class="col-md-2">
                <label class="form-label">Date</label>
                <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-control" placeholder="Optional reason">
            </div>

            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="fa fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
