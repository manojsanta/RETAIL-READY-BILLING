<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$q = trim($_GET['q'] ?? '');
$results = [];

if ($q !== '') {
    $like = "%$q%";

    // Search Items
    $stmtItems = $pdo->prepare("SELECT i.id, i.name, i.sku, i.barcode, i.purchase_price, i.sale_price, i.current_stock,
        c.name as category_name
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ? OR c.name LIKE ?
        ORDER BY i.name ASC LIMIT 20");
    $stmtItems->execute([$like, $like, $like, $like]);
    $results['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Search Sales (Invoices)
    $stmtSales = $pdo->prepare("SELECT s.id, s.invoice_no, s.date, s.total, s.paid_amount, s.payment_status,
        p.name as party_name, p.phone as party_phone
        FROM sales s
        LEFT JOIN parties p ON s.party_id = p.id
        WHERE s.invoice_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?
        ORDER BY s.date DESC LIMIT 20");
    $stmtSales->execute([$like, $like, $like]);
    $results['sales'] = $stmtSales->fetchAll(PDO::FETCH_ASSOC);

    // Search Stock
    $stmtStock = $pdo->prepare("SELECT i.id, i.name, i.sku, i.current_stock, i.min_stock, i.unit,
        c.name as category_name,
        CASE
            WHEN i.current_stock = 0 THEN 'Out of Stock'
            WHEN i.current_stock <= i.min_stock THEN 'Low Stock'
            ELSE 'In Stock'
        END as stock_status
        FROM items i
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE i.name LIKE ? OR i.sku LIKE ?
        ORDER BY i.current_stock ASC LIMIT 20");
    $stmtStock->execute([$like, $like]);
    $results['stock'] = $stmtStock->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Search Results';
include 'header.php';
?>

<div class="search-page">
    <!-- Search Box -->
    <form method="GET" class="mb-4">
        <div class="input-group input-group-lg">
            <span class="input-group-text bg-white border-end-0" style="border-radius:10px 0 0 10px;">
                <i class="fas fa-search text-muted"></i>
            </span>
            <input type="text" name="q" class="form-control bg-white border-start-0" placeholder="Search items, invoices, stock..."
                   value="<?= h($q) ?>" autofocus style="border-radius:0 10px 10px 0;font-size:15px;height:52px;">
            <button type="submit" class="btn btn-primary" style="border-radius:0 10px 10px 0;padding:0 24px;font-weight:600;">Search</button>
        </div>
    </form>

    <?php if ($q === ''): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-search fa-3x mb-3 d-block" style="opacity:0.2;"></i>
            <p style="font-size:15px;">Type something to search across items, invoices, and stock.</p>
        </div>
    <?php elseif (empty($results['items']) && empty($results['sales']) && empty($results['stock'])): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-search fa-3x mb-3 d-block" style="opacity:0.2;"></i>
            <p style="font-size:15px;">No results found for "<strong><?= h($q) ?></strong>"</p>
        </div>
    <?php else: ?>

        <!-- Items -->
        <?php if (!empty($results['items'])): ?>
        <div class="search-section mb-4">
            <div class="search-section-head">
                <i class="fas fa-cube"></i> Items <span class="badge bg-primary rounded-pill ms-2"><?= count($results['items']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item Name</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th class="text-end">Purchase</th>
                            <th class="text-end">Sale</th>
                            <th>Stock</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['items'] as $r): ?>
                        <tr>
                            <td><strong><?= h($r['name']) ?></strong></td>
                            <td><code><?= h($r['sku']) ?></code></td>
                            <td><?= h($r['category_name'] ?? 'N/A') ?></td>
                            <td class="text-end"><?= formatMoney($r['purchase_price']) ?></td>
                            <td class="text-end"><?= formatMoney($r['sale_price']) ?></td>
                            <td><?= intval($r['current_stock']) ?></td>
                            <td><a href="item_edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Sales / Invoices -->
        <?php if (!empty($results['sales'])): ?>
        <div class="search-section mb-4">
            <div class="search-section-head">
                <i class="fas fa-file-invoice"></i> Invoices <span class="badge bg-primary rounded-pill ms-2"><?= count($results['sales']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice No</th>
                            <th>Party</th>
                            <th>Date</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['sales'] as $r): ?>
                        <tr>
                            <td><strong><?= h($r['invoice_no']) ?></strong></td>
                            <td><?= h($r['party_name'] ?? 'N/A') ?></td>
                            <td><?= h($r['date']) ?></td>
                            <td class="text-end"><?= formatMoney($r['total']) ?></td>
                            <td class="text-end"><?= formatMoney($r['paid_amount']) ?></td>
                            <td>
                                <?php
                                $statusClass = $r['payment_status'] === 'paid' ? 'bg-success' : ($r['payment_status'] === 'unpaid' ? 'bg-danger' : 'bg-warning text-dark');
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= ucfirst(h($r['payment_status'])) ?></span>
                            </td>
                            <td><a href="sale_view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stock -->
        <?php if (!empty($results['stock'])): ?>
        <div class="search-section mb-4">
            <div class="search-section-head">
                <i class="fas fa-boxes-stacked"></i> Stock <span class="badge bg-primary rounded-pill ms-2"><?= count($results['stock']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item</th>
                            <th>SKU</th>
                            <th>Category</th>
                            <th class="text-center">Current</th>
                            <th class="text-center">Min</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['stock'] as $r): ?>
                        <tr>
                            <td><strong><?= h($r['name']) ?></strong></td>
                            <td><code><?= h($r['sku']) ?></code></td>
                            <td><?= h($r['category_name'] ?? 'N/A') ?></td>
                            <td class="text-center"><?= intval($r['current_stock']) ?> <?= h($r['unit']) ?></td>
                            <td class="text-center"><?= intval($r['min_stock']) ?></td>
                            <td>
                                <?php
                                $stockBadge = $r['stock_status'] === 'Out of Stock' ? 'bg-danger' : ($r['stock_status'] === 'Low Stock' ? 'bg-warning text-dark' : 'bg-success');
                                ?>
                                <span class="badge <?= $stockBadge ?>"><?= h($r['stock_status']) ?></span>
                            </td>
                            <td><a href="item_edit.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-edit"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<style>
.search-page { max-width: 1100px; margin: 0 auto; }
.search-section {
    background: #fff; border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.search-section-head {
    font-size: 13px; font-weight: 700; color: var(--primary-color);
    text-transform: uppercase; letter-spacing: 0.5px;
    padding: 14px 20px; background: var(--primary-light);
    border-bottom: 1px solid rgba(237,26,59,0.08);
    display: flex; align-items: center; gap: 8px;
}
.search-section-head i { font-size: 14px; }
.search-section .table { margin-bottom: 0; }
.search-section .table td { vertical-align: middle; padding: 10px 16px; font-size: 13px; }
.search-section .table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; padding: 10px 16px; }
</style>

<?php include 'footer.php'; ?>
