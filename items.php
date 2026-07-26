<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (!verifyCsrf($_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        redirect('items.php');
    }
    $id = intval($_GET['delete']);

    $check = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE item_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete item: it is used in sales.');
        redirect('items.php');
    }

    $check2 = $pdo->prepare("SELECT COUNT(*) FROM purchase_items WHERE item_id = ?");
    $check2->execute([$id]);
    if ($check2->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete item: it is used in purchases.');
        redirect('items.php');
    }

    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$id]);
    setFlash('success', 'Item deleted successfully.');
    redirect('items.php');
}

$filterCategory = isset($_GET['category']) ? intval($_GET['category']) : 0;
$filterStock = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

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
    $where[] = "(i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM items i $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalItems = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalItems / $perPage));

$sumSql = "SELECT COUNT(*) as total, COALESCE(SUM(i.current_stock * i.purchase_price), 0) as stock_value,
           SUM(CASE WHEN i.current_stock > 0 AND i.current_stock <= i.min_stock THEN 1 ELSE 0 END) as low_stock
           FROM items i $whereSql";
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($params);
$summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

$sql = "SELECT i.*, c.name as category_name, COALESCE(tr.rate, 0) as tax_rate,
               COALESCE(pt.rate, 0) as purchase_tax_rate
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        LEFT JOIN tax_rates tr ON i.tax_rate_id = tr.id
        LEFT JOIN tax_rates pt ON i.purchase_tax_rate_id = pt.id
        $whereSql
        ORDER BY i.name ASC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Items';
include 'header.php';

$itemsJson = json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP);
?>

<style>
.items-split { display:flex; gap:0; height:calc(100vh - 200px); min-height:400px; }
.items-list { flex:0 0 380px; max-width:380px; display:flex; flex-direction:column; border:1px solid var(--border-color); border-radius:var(--border-radius); overflow:hidden; background:var(--white); }
.items-list-head { padding:12px 16px; border-bottom:1px solid var(--border-color); background:#fafafa; flex-shrink:0; }
.items-list-body { flex:1; overflow-y:auto; }
.items-detail { flex:1; display:flex; align-items:center; justify-content:center; }

.item-row { padding:7px 12px; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background 0.15s; display:flex; align-items:center; gap:10px; }
.item-row:hover { background:var(--primary-light); }
.item-row.active { background:var(--primary-light); border-left:3px solid var(--primary-color); }
.item-row .item-avatar { width:30px; height:30px; border-radius:6px; background:var(--primary-light); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--primary-color); font-weight:700; font-size:11px; }
.item-row .item-info { flex:1; min-width:0; }
.item-row .item-info .name { font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; line-height:1.2; }
.item-row .item-info .meta { font-size:11px; color:var(--text-muted); line-height:1.2; }
.item-row .item-stock-badge { font-size:11px; font-weight:600; padding:1px 6px; border-radius:20px; white-space:nowrap; }
.stock-ok { background:#e6f4ea; color:#1e7e34; }
.stock-low { background:#fff8e1; color:#b8860b; }
.stock-out { background:#fde8e8; color:#c62828; }

.detail-placeholder { text-align:center; color:var(--text-muted); }
.detail-placeholder i { font-size:48px; opacity:0.2; margin-bottom:12px; }

.detail-card { width:100%; max-width:600px; background:var(--white); border-radius:var(--border-radius); box-shadow:var(--card-shadow); overflow:hidden; }
.detail-header { padding:24px 28px 16px; border-bottom:1px solid #f0f0f0; }
.detail-header h4 { margin:0 0 4px; font-size:20px; }
.detail-header .detail-sku { color:var(--text-muted); font-size:13px; }
.detail-header .detail-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.detail-body { padding:20px 28px; }
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.detail-section-label { grid-column:1/-1; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); padding-bottom:4px; border-bottom:1px solid #f0f0f0; margin-top:4px; }
.detail-field label { font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:2px; display:block; }
.detail-field .val { font-size:15px; font-weight:600; }
.detail-actions { padding:16px 28px; border-top:1px solid #f0f0f0; display:flex; gap:8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Items</h4>
    <div>
        <a href="item_add.php" class="btn btn-primary"><i class="fa fa-plus"></i> Add Item</a>
        <a href="items_export.php?<?= http_build_query($_GET) ?>" class="btn btn-success"><i class="fa fa-file-csv"></i> Export</a>
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
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, SKU, barcode..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="fa fa-search me-1"></i> Filter</button>
                    <a href="items.php" class="btn btn-sm btn-outline-secondary flex-fill"><i class="fa fa-rotate-left me-1"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="card text-center"><div class="card-body py-2">
            <small class="text-muted">Total Items</small>
            <h5 class="mb-0"><?= intval($summary['total']) ?></h5>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card text-center"><div class="card-body py-2">
            <small class="text-muted">Stock Value</small>
            <h5 class="mb-0"><?= formatMoney($summary['stock_value']) ?></h5>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card text-center"><div class="card-body py-2">
            <small class="text-muted">Low Stock</small>
            <h5 class="mb-0 text-warning"><?= intval($summary['low_stock']) ?></h5>
        </div></div>
    </div>
</div>

<div class="items-split">
    <div class="items-list">
        <div class="items-list-head">
            <span style="font-size:13px;font-weight:600;"><?= $totalItems ?> items</span>
        </div>
        <div class="items-list-body" id="itemsList">
            <?php if (empty($items)): ?>
                <div class="text-center text-muted py-5">No items found.</div>
            <?php else: ?>
                <?php foreach ($items as $idx => $item):
                    $stock = intval($item['current_stock']);
                    $minStock = intval($item['min_stock']);
                    if ($stock <= 0) { $stockClass = 'stock-out'; } elseif ($stock <= $minStock) { $stockClass = 'stock-low'; } else { $stockClass = 'stock-ok'; }
                    $initials = strtoupper(substr($item['name'], 0, 2));
                ?>
                    <div class="item-row" data-idx="<?= $idx ?>" onclick="selectItem(<?= $idx ?>)">
                        <div class="item-avatar"><?= $initials ?></div>
                        <div class="item-info">
                            <div class="name"><?= h($item['name']) ?></div>
                            <div class="meta"><?= h($item['sku']) ?> &middot; <?= h($item['category_name'] ?? 'Uncategorized') ?></div>
                        </div>
                        <span class="item-stock-badge <?= $stockClass ?>"><?= $stock ?> <?= h($item['unit']) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top" style="font-size:12px;">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" class="btn btn-sm btn-outline-secondary py-0 <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fa fa-chevron-left"></i></a>
            <div class="d-flex gap-1 align-items-center">
                <?php
                $range = 2;
                $start = max(1, $page - $range);
                $end = min($totalPages, $page + $range);
                if ($start > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="btn btn-sm btn-outline-secondary py-0 px-1">1</a>
                    <?php if ($start > 2): ?><span class="text-muted">...</span><?php endif; ?>
                <?php endif;
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline-secondary' ?> py-0 px-1"><?= $i ?></a>
                <?php endfor;
                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="text-muted">...</span><?php endif; ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="btn btn-sm btn-outline-secondary py-0 px-1"><?= $totalPages ?></a>
                <?php endif; ?>
            </div>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>" class="btn btn-sm btn-outline-secondary py-0 <?= $page >= $totalPages ? 'disabled' : '' ?>"><i class="fa fa-chevron-right"></i></a>
        </div>
    </div>

    <div class="items-detail" id="itemsDetail">
        <div class="detail-placeholder" id="detailPlaceholder">
            <i class="fas fa-hand-pointer d-block"></i>
            <div>Select an item to view details</div>
        </div>
        <div class="detail-card" id="detailCard" style="display:none;"></div>
    </div>
</div>

<script>
var itemsData = <?= $itemsJson ?>;
var csrfToken = '<?= csrfToken() ?>';

function selectItem(idx) {
    document.querySelectorAll('.item-row').forEach(function(r) { r.classList.remove('active'); });
    document.querySelector('.item-row[data-idx="' + idx + '"]').classList.add('active');

    var item = itemsData[idx];
    document.getElementById('detailPlaceholder').style.display = 'none';
    var card = document.getElementById('detailCard');
    card.style.display = 'block';

    var stock = parseInt(item.current_stock) || 0;
    var minStock = parseInt(item.min_stock) || 0;
    if (stock <= 0) { var stockBadge = '<span class="detail-status" style="background:#fde8e8;color:#c62828;">Out of Stock</span>'; }
    else if (stock <= minStock) { var stockBadge = '<span class="detail-status" style="background:#fff8e1;color:#b8860b;">Low Stock</span>'; }
    else { var stockBadge = '<span class="detail-status" style="background:#e6f4ea;color:#1e7e34;">In Stock</span>'; }
    var statusBadge = item.status == 1
        ? '<span class="detail-status" style="background:#e6f4ea;color:#1e7e34;">Active</span>'
        : '<span class="detail-status" style="background:#eee;color:#666;">Inactive</span>';

    var imgHtml = item.image
        ? '<img src="assets/images/' + escapeHtml(item.image) + '" style="width:64px;height:64px;border-radius:10px;object-fit:cover;">'
        : '<div style="width:64px;height:64px;border-radius:10px;background:var(--primary-light);display:flex;align-items:center;justify-content:center;color:var(--primary-color);font-weight:700;font-size:22px;">' + escapeHtml(item.name.substring(0,2).toUpperCase()) + '</div>';

    card.innerHTML =
        '<div class="detail-header">' +
            '<div style="display:flex;align-items:center;gap:16px;">' +
                imgHtml +
                '<div>' +
                    '<h4>' + escapeHtml(item.name) + '</h4>' +
                    '<div class="detail-sku"><code>' + escapeHtml(item.sku) + '</code> &middot; ' + escapeHtml(item.category_name || 'Uncategorized') + '</div>' +
                    '<div style="margin-top:4px;">' + stockBadge + ' ' + statusBadge + '</div>' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div class="detail-body">' +
            '<div class="detail-grid">' +
                '<div class="detail-section-label">Purchase</div>' +
                '<div class="detail-field"><label>Base Price</label><div class="val">' + formatMoney(item.purchase_price) + '</div></div>' +
                '<div class="detail-field"><label>Tax Amount</label><div class="val">' + formatMoney(parseFloat(item.purchase_price_with_tax || 0) - parseFloat(item.purchase_price || 0)) + ' (' + parseFloat(item.purchase_tax_rate || 0).toFixed(1) + '%)</div></div>' +
                '<div class="detail-field"><label>Final Price (incl. tax)</label><div class="val" style="color:var(--primary-color);">' + formatMoney(item.purchase_price_with_tax) + '</div></div>' +
                '<div class="detail-field"><label>Tax Mode</label><div class="val">' + (item.purchase_tax_mode || 'exclusive') + '</div></div>' +
                '<div class="detail-section-label">Sale</div>' +
                '<div class="detail-field"><label>Base Price</label><div class="val">' + formatMoney(item.sale_price) + '</div></div>' +
                '<div class="detail-field"><label>Tax Amount</label><div class="val">' + formatMoney(parseFloat(item.sale_price_with_tax || 0) - parseFloat(item.sale_price || 0)) + ' (' + parseFloat(item.tax_rate || 0).toFixed(1) + '%)</div></div>' +
                '<div class="detail-field"><label>Final Price (incl. tax)</label><div class="val" style="color:var(--primary-color);">' + formatMoney(item.sale_price_with_tax) + '</div></div>' +
                '<div class="detail-field"><label>Tax Mode</label><div class="val">' + (item.sale_tax_mode || 'exclusive') + '</div></div>' +
                '<div class="detail-field"><label>Current Stock</label><div class="val">' + stock + ' ' + escapeHtml(item.unit || '') + '</div></div>' +
                '<div class="detail-field"><label>Min Stock Alert</label><div class="val">' + minStock + '</div></div>' +
                (item.hsn_code ? '<div class="detail-field"><label>HSN Code</label><div class="val">' + escapeHtml(item.hsn_code) + '</div></div>' : '') +
                '<div class="detail-field"><label>Opening Stock</label><div class="val">' + (item.opening_stock || 0) + '</div></div>' +
            '</div>' +
            (item.description ? '<div style="margin-top:16px;"><label style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);">Description</label><div style="font-size:14px;margin-top:2px;">' + escapeHtml(item.description) + '</div></div>' : '') +
        '</div>' +
        '<div class="detail-actions">' +
            '<a href="item_edit.php?id=' + item.id + '" class="btn btn-sm btn-primary"><i class="fa fa-edit me-1"></i> Edit</a>' +
            '<a href="#" class="btn btn-sm btn-outline-danger" onclick="event.preventDefault();confirmDelete(\'items.php?delete=' + item.id + '&csrf=' + csrfToken + '\',\'Delete Item\',\'Are you sure you want to delete ' + escapeHtml(item.name).replace(/'/g, "\\'") + '? This action cannot be undone.\')"><i class="fa fa-trash me-1"></i> Delete</a>' +
        '</div>';
}

function escapeHtml(t) { if (!t) return ''; return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function formatMoney(v) { return '₹' + parseFloat(v || 0).toFixed(2); }
</script>

<?php include 'footer.php'; ?>
