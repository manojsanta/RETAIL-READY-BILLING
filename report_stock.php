<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $categoryId = intval($_GET['category_id'] ?? 0);
    $stockStatus = $_GET['stock_status'] ?? '';

    $where = ["i.status = 1"];
    $params = [];
    if ($categoryId > 0) { $where[] = "i.category_id = ?"; $params[] = $categoryId; }
    if ($stockStatus === 'out') { $where[] = "i.current_stock <= 0"; }
    elseif ($stockStatus === 'low') { $where[] = "i.current_stock > 0 AND i.current_stock <= i.min_stock"; }
    elseif ($stockStatus === 'in') { $where[] = "i.current_stock > i.min_stock"; }
    $whereSql = implode(' AND ', $where);

    $rows = fetchAll("SELECT i.name, i.sku, c.name as category_name, i.opening_stock,
        COALESCE((SELECT SUM(qty) FROM purchase_items pi JOIN purchases pu ON pi.purchase_id = pu.id WHERE pi.item_id = i.id AND pu.status != 'cancelled'), 0) as purchased_qty,
        COALESCE((SELECT SUM(qty) FROM sale_items si JOIN sales sa ON si.sale_id = sa.id WHERE si.item_id = i.id AND sa.status != 'cancelled'), 0) as sold_qty,
        COALESCE((SELECT SUM(CASE WHEN adjustment_type IN ('addition','correction') THEN qty WHEN adjustment_type IN ('subtraction','damage','expired') THEN -qty ELSE 0 END) FROM stock_adjustments WHERE item_id = i.id), 0) as adjusted_qty,
        i.current_stock, i.purchase_price, i.sale_price
        FROM items i LEFT JOIN categories c ON i.category_id = c.id
        WHERE $whereSql ORDER BY i.name ASC", $params);

    $csvData = [];
    foreach ($rows as $r) {
        $csvData[] = [$r['name'], $r['sku'], $r['category_name'], $r['opening_stock'], $r['purchased_qty'], $r['sold_qty'], $r['adjusted_qty'], $r['current_stock'], $r['current_stock'] * $r['purchase_price'], $r['current_stock'] * $r['sale_price']];
    }
    exportCSV(['Item Name','SKU','Category','Opening Stock','Purchased','Sold','Adjusted','Current Stock','Purchase Value','Sale Value'], $csvData, 'stock_report');
}

// Filters
$categoryId = intval($_GET['category_id'] ?? 0);
$stockStatus = $_GET['stock_status'] ?? '';

$where = ["i.status = 1"];
$params = [];
if ($categoryId > 0) { $where[] = "i.category_id = ?"; $params[] = $categoryId; }
if ($stockStatus === 'out') { $where[] = "i.current_stock <= 0"; }
elseif ($stockStatus === 'low') { $where[] = "i.current_stock > 0 AND i.current_stock <= i.min_stock"; }
elseif ($stockStatus === 'in') { $where[] = "i.current_stock > i.min_stock"; }
$whereSql = implode(' AND ', $where);

$items = fetchAll("SELECT i.*, c.name as category_name,
    COALESCE((SELECT SUM(qty) FROM purchase_items pi JOIN purchases pu ON pi.purchase_id = pu.id WHERE pi.item_id = i.id AND pu.status != 'cancelled'), 0) as purchased_qty,
    COALESCE((SELECT SUM(qty) FROM sale_items si JOIN sales sa ON si.sale_id = sa.id WHERE si.item_id = i.id AND sa.status != 'cancelled'), 0) as sold_qty,
    COALESCE((SELECT SUM(CASE WHEN adjustment_type IN ('addition','correction') THEN qty WHEN adjustment_type IN ('subtraction','damage','expired') THEN -qty ELSE 0 END) FROM stock_adjustments WHERE item_id = i.id), 0) as adjusted_qty
    FROM items i LEFT JOIN categories c ON i.category_id = c.id
    WHERE $whereSql ORDER BY i.name ASC", $params);

// Compute derived fields
$totalItems = count($items);
$totalStockValue = 0;
$totalPurchaseValue = 0;
$totalSaleValue = 0;
$lowStockCount = 0;

foreach ($items as &$item) {
    $item['purchase_value'] = $item['current_stock'] * $item['purchase_price'];
    $item['sale_value'] = $item['current_stock'] * $item['sale_price'];
    $totalPurchaseValue += $item['purchase_value'];
    $totalSaleValue += $item['sale_value'];
    $totalStockValue += $item['current_stock'];
    if ($item['current_stock'] <= 0) {
        $item['status_label'] = 'Out of Stock';
        $item['status_class'] = 'danger';
    } elseif ($item['current_stock'] <= $item['min_stock']) {
        $item['status_label'] = 'Low Stock';
        $item['status_class'] = 'warning';
        $lowStockCount++;
    } else {
        $item['status_label'] = 'In Stock';
        $item['status_class'] = 'success';
    }
}
unset($item);

// Category summary
$categorySummary = fetchAll("SELECT c.name as category_name,
    COUNT(i.id) as item_count,
    SUM(i.current_stock) as total_stock,
    SUM(i.current_stock * i.purchase_price) as total_value
    FROM items i LEFT JOIN categories c ON i.category_id = c.id
    WHERE i.status = 1
    GROUP BY i.category_id, c.name
    ORDER BY total_value DESC", []);

// Categories for filter
$categories = fetchAll("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC");

$pageTitle = 'Stock Report';
include 'header.php';
?>

<style>
.report-quick-btns .btn { font-size: 0.8rem; padding: 0.25rem 0.6rem; }
.low-stock-row { background-color: #fff5f5 !important; }
.table-compact th,
.table-compact td { padding: 0.2rem 0.35rem; font-size: 0.8rem; white-space: nowrap; }
.table-compact .badge { font-size: 0.7rem; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Stock / Inventory Report</h5>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Items</small>
                <h4 class="mb-0 text-primary"><?= $totalItems ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Total Stock Qty</small>
                <h4 class="mb-0 text-info"><?= number_format($totalStockValue) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Stock Value at Cost</small>
                <h4 class="mb-0 text-primary"><?= money($totalPurchaseValue) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-3">
                <small class="text-muted d-block">Low Stock Items</small>
                <h4 class="mb-0 text-warning"><?= $lowStockCount ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Category</label>
                <select name="category_id" class="form-select form-select-sm">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>><?= sanitize($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Stock Status</label>
                <select name="stock_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="in" <?= $stockStatus === 'in' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out" <?= $stockStatus === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
                <a href="report_stock.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Stock Table -->
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm table-compact table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Item Name</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end">Purchased</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Adjusted</th>
                    <th class="text-end">Current</th>
                    <th class="text-end">Purchase Val</th>
                    <th class="text-end">Sale Val</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $idx => $item): ?>
                        <tr class="<?= $item['status_class'] === 'danger' || $item['status_class'] === 'warning' ? 'low-stock-row' : '' ?>">
                            <td><?= $idx + 1 ?></td>
                            <td class="fw-semibold"><?= sanitize($item['name']) ?></td>
                            <td><small><?= sanitize($item['sku']) ?></small></td>
                            <td><?= sanitize($item['category_name'] ?: 'N/A') ?></td>
                            <td class="text-end"><?= number_format($item['opening_stock']) ?></td>
                            <td class="text-end text-success"><?= number_format($item['purchased_qty']) ?></td>
                            <td class="text-end text-danger"><?= number_format($item['sold_qty']) ?></td>
                            <td class="text-end"><?= number_format($item['adjusted_qty']) ?></td>
                            <td class="text-end fw-bold"><?= number_format($item['current_stock']) ?></td>
                            <td class="text-end"><?= money($item['purchase_value']) ?></td>
                            <td class="text-end"><?= money($item['sale_value']) ?></td>
                            <td>
                                <span class="badge bg-<?= $item['status_class'] ?>"><?= $item['status_label'] ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4">Totals</td>
                    <td class="text-end"><?= number_format(array_sum(array_column($items, 'opening_stock'))) ?></td>
                    <td class="text-end text-success"><?= number_format(array_sum(array_column($items, 'purchased_qty'))) ?></td>
                    <td class="text-end text-danger"><?= number_format(array_sum(array_column($items, 'sold_qty'))) ?></td>
                    <td class="text-end"><?= number_format(array_sum(array_column($items, 'adjusted_qty'))) ?></td>
                    <td class="text-end"><?= number_format(array_sum(array_column($items, 'current_stock'))) ?></td>
                    <td class="text-end"><?= money($totalPurchaseValue) ?></td>
                    <td class="text-end"><?= money($totalSaleValue) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Category-wise Summary -->
<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0">Category-wise Stock Summary</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-compact mb-0">
            <thead class="table-light">
                <tr>
                    <th>Category</th>
                    <th class="text-end">Items</th>
                    <th class="text-end">Total Stock</th>
                    <th class="text-end">Stock Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categorySummary)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No data.</td></tr>
                <?php else: ?>
                    <?php foreach ($categorySummary as $cs): ?>
                        <tr>
                            <td><?= sanitize($cs['category_name'] ?: 'Uncategorized') ?></td>
                            <td class="text-end"><?= intval($cs['item_count']) ?></td>
                            <td class="text-end"><?= number_format($cs['total_stock'] ?? 0) ?></td>
                            <td class="text-end fw-bold"><?= money($cs['total_value'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
