<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    setFlash('danger', 'Invalid item.');
    redirect('items.php');
}

$stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    setFlash('danger', 'Item not found.');
    redirect('items.php');
}

$errors = [];
$old = [
    'name' => $item['name'], 'sku' => $item['sku'], 'barcode' => $item['barcode'] ?? '',
    'category_id' => $item['category_id'] ?? 0, 'description' => $item['description'] ?? '',
    'unit' => $item['unit'], 'purchase_price' => $item['purchase_price'],
    'sale_price' => $item['sale_price'], 'tax_rate_id' => $item['tax_rate_id'] ?? 0,
    'purchase_tax_rate_id' => $item['purchase_tax_rate_id'] ?? 0,
    'purchase_tax_mode' => $item['purchase_tax_mode'] ?? 'exclusive',
    'sale_tax_mode' => $item['sale_tax_mode'] ?? 'exclusive',
    'hsn_code' => $item['hsn_code'] ?? '', 'min_stock' => $item['min_stock'],
    'opening_stock' => $item['opening_stock'] ?? 0, 'status' => $item['status']
];

$categories = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$taxRates = $pdo->query("SELECT id, name, rate FROM tax_rates ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$units = fetchAll("SELECT id, name, short_name FROM units WHERE status = 1 ORDER BY name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        $old['name'] = trim($_POST['name'] ?? '');
        $old['sku'] = trim($_POST['sku'] ?? '');
        $old['barcode'] = trim($_POST['barcode'] ?? '');
        $old['category_id'] = intval($_POST['category_id'] ?? 0);
        $old['description'] = trim($_POST['description'] ?? '');
        $old['unit'] = trim($_POST['unit'] ?? 'Pcs');
        $old['purchase_price'] = $_POST['purchase_price'] ?? '';
        $old['sale_price'] = $_POST['sale_price'] ?? '';
        $old['tax_rate_id'] = intval($_POST['tax_rate_id'] ?? 0);
        $old['purchase_tax_rate_id'] = intval($_POST['purchase_tax_rate_id'] ?? 0);
        $old['hsn_code'] = trim($_POST['hsn_code'] ?? '');
        $old['min_stock'] = intval($_POST['min_stock'] ?? 10);
        $old['opening_stock'] = intval($_POST['opening_stock'] ?? 0);
        $old['status'] = isset($_POST['status']) ? 1 : 0;
        $old['purchase_tax_mode'] = in_array($_POST['purchase_tax_mode'] ?? '', ['exclusive', 'inclusive']) ? $_POST['purchase_tax_mode'] : 'exclusive';
        $old['sale_tax_mode'] = in_array($_POST['sale_tax_mode'] ?? '', ['exclusive', 'inclusive']) ? $_POST['sale_tax_mode'] : 'exclusive';

        if ($old['name'] === '') $errors[] = 'Item name is required.';
        if ($old['purchase_price'] === '' || floatval($old['purchase_price']) < 0) $errors[] = 'Valid purchase price is required.';
        if ($old['sale_price'] === '' || floatval($old['sale_price']) < 0) $errors[] = 'Valid sale price is required.';

        if ($old['sku'] !== '') {
            $checkSku = $pdo->prepare("SELECT COUNT(*) FROM items WHERE sku = ? AND id != ?");
            $checkSku->execute([$old['sku'], $id]);
            if ($checkSku->fetchColumn() > 0) {
                $errors[] = 'SKU already exists.';
            }
        }

        if (empty($errors)) {
            $imageName = $item['image'];
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (in_array($_FILES['image']['type'], $allowed) && $_FILES['image']['size'] <= 2 * 1024 * 1024) {
                    if ($item['image']) {
                        $oldPath = __DIR__ . '/assets/images/' . $item['image'];
                        if (file_exists($oldPath)) unlink($oldPath);
                    }
                    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                    $imageName = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $uploadDir = __DIR__ . '/assets/images/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $imageName);
                } else {
                    $errors[] = 'Image must be JPG, PNG, GIF or WebP and under 2MB.';
                }
            }

            $saleTaxRate = 0;
            if ($old['tax_rate_id'] > 0) {
                $taxStmt = $pdo->prepare("SELECT rate FROM tax_rates WHERE id = ?");
                $taxStmt->execute([$old['tax_rate_id']]);
                $taxRow = $taxStmt->fetch(PDO::FETCH_ASSOC);
                if ($taxRow) $saleTaxRate = $taxRow['rate'];
            }

            $purchaseTaxRate = 0;
            if ($old['purchase_tax_rate_id'] > 0) {
                $ptStmt = $pdo->prepare("SELECT rate FROM tax_rates WHERE id = ?");
                $ptStmt->execute([$old['purchase_tax_rate_id']]);
                $ptRow = $ptStmt->fetch(PDO::FETCH_ASSOC);
                if ($ptRow) $purchaseTaxRate = $ptRow['rate'];
            }

            $ppInput = floatval($old['purchase_price']);
            $spInput = floatval($old['sale_price']);

            if ($old['purchase_tax_mode'] === 'inclusive') {
                $ppBase = $purchaseTaxRate > 0 ? $ppInput / (1 + $purchaseTaxRate / 100) : $ppInput;
                $ppTotal = $ppInput;
            } else {
                $ppBase = $ppInput;
                $ppTotal = $ppInput + ($ppInput * $purchaseTaxRate / 100);
            }

            if ($old['sale_tax_mode'] === 'inclusive') {
                $spBase = $saleTaxRate > 0 ? $spInput / (1 + $saleTaxRate / 100) : $spInput;
                $spTotal = $spInput;
            } else {
                $spBase = $spInput;
                $spTotal = $spInput + ($spInput * $saleTaxRate / 100);
            }

            if (empty($errors)) {
                $upd = $pdo->prepare("UPDATE items SET name = ?, sku = ?, barcode = ?, category_id = ?, description = ?, unit = ?, purchase_price = ?, purchase_price_with_tax = ?, sale_price = ?, sale_price_with_tax = ?, tax_rate_id = ?, purchase_tax_rate_id = ?, purchase_tax_mode = ?, sale_tax_mode = ?, hsn_code = ?, min_stock = ?, opening_stock = ?, image = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([
                    $old['name'], $old['sku'], $old['barcode'], $old['category_id'] ?: null,
                    $old['description'], $old['unit'], $ppBase, $ppTotal,
                    $spBase, $spTotal, $old['tax_rate_id'] ?: null,
                    $old['purchase_tax_rate_id'] ?: null,
                    $old['purchase_tax_mode'], $old['sale_tax_mode'],
                    $old['hsn_code'], $old['min_stock'], $old['opening_stock'],
                    $imageName, $old['status'], $id
                ]);

                setFlash('success', 'Item updated successfully.');
                redirect('items.php');
            }
        }
    }
}

$pageTitle = 'Edit Item';
include 'header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger py-2 px-3 mb-3" style="border-radius:10px;font-size:13px;">
        <i class="fas fa-exclamation-circle me-1"></i>
        <?= implode('<br>', array_map('h', $errors)) ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="itemForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

    <div class="vy-add-grid">

        <!-- LEFT COLUMN -->
        <div class="vy-add-left">

            <!-- Photo -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-camera"></i> Item Photo</div>
                <div class="vy-card-body">
                    <div class="vy-img-upload" id="imageUpload">
                        <?php if ($item['image']): ?>
                            <img id="imagePreview" src="assets/images/<?= h($item['image']) ?>" alt="Current Image">
                            <div class="vy-img-placeholder d-none" onclick="document.getElementById('imageInput').click()">
                                <i class="fas fa-cloud-arrow-up"></i>
                                <span>Tap to replace photo</span>
                                <small>JPG, PNG or WebP &middot; Max 2MB</small>
                            </div>
                        <?php else: ?>
                            <img id="imagePreview" class="d-none" alt="Preview">
                            <div class="vy-img-placeholder" onclick="document.getElementById('imageInput').click()">
                                <i class="fas fa-cloud-arrow-up"></i>
                                <span>Tap to add photo</span>
                                <small>JPG, PNG or WebP &middot; Max 2MB</small>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="image" id="imageInput" accept="image/*" onchange="previewImage(this)">
                    </div>
                </div>
            </div>

            <!-- Basic Info -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-tag"></i> Basic Info</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required placeholder="e.g. Dell Laptop Inspiron 15" value="<?= h($old['name']) ?>">
                    </div>
                    <div class="vy-f-row">
                        <div class="vy-f">
                            <label>Category</label>
                            <select name="category_id" id="categorySelect" onchange="onCategoryChange()">
                                <option value="0">-- Select --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" data-name="<?= h($cat['name']) ?>" <?= $old['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="vy-f">
                            <label>Unit</label>
                            <select name="unit">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?= h($u['short_name']) ?>" <?= $old['unit'] === $u['short_name'] ? 'selected' : '' ?>><?= h($u['name']) ?> (<?= h($u['short_name']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Description</label>
                        <textarea name="description" rows="2" placeholder="Optional description..."><?= h($old['description']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Inventory -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-boxes-stacked"></i> Inventory</div>
                <div class="vy-card-body">
                    <div class="vy-track-toggle">
                        <div>
                            <div class="vy-toggle-label">Track Inventory</div>
                            <div class="vy-toggle-desc">Enable stock tracking for this item</div>
                        </div>
                        <label class="vy-switch">
                            <input type="checkbox" name="track_stock" checked id="trackStockToggle" onchange="toggleStockFields()">
                            <span class="vy-slider"></span>
                        </label>
                    </div>
                    <div id="stockFields">
                        <div class="vy-f">
                            <label>SKU</label>
                            <div class="vy-sku-row">
                                <input type="text" name="sku" value="<?= h($old['sku']) ?>">
                                <button type="button" class="vy-btn-icon" onclick="generateSku()" title="Generate SKU"><i class="fas fa-dice"></i></button>
                            </div>
                        </div>
                        <div class="vy-f-row">
                            <div class="vy-f">
                                <label>Barcode</label>
                                <div class="vy-sku-row">
                                    <input type="text" name="barcode" placeholder="Enter or scan" value="<?= h($old['barcode']) ?>">
                                    <button type="button" class="vy-btn-icon" title="Scan Barcode"><i class="fas fa-barcode"></i></button>
                                </div>
                            </div>
                            <div class="vy-f">
                                <label>HSN Code</label>
                                <input type="text" name="hsn_code" placeholder="GST HSN" value="<?= h($old['hsn_code']) ?>">
                            </div>
                        </div>
                        <div class="vy-f-row">
                            <div class="vy-f">
                                <label>Opening Stock</label>
                                <input type="number" name="opening_stock" min="0" value="<?= $old['opening_stock'] ?>">
                            </div>
                            <div class="vy-f">
                                <label>Min Stock Alert</label>
                                <input type="number" name="min_stock" min="0" value="<?= $old['min_stock'] ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div class="vy-add-right">

            <!-- Purchase Price -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-cart-shopping"></i> Purchase Price</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Purchase Price <span class="text-danger">*</span></label>
                        <div class="vy-input-addon">
                            <span>₹</span>
                            <input type="number" name="purchase_price" id="purchasePrice" step="0.01" min="0" required placeholder="0.00" value="<?= h(($old['purchase_tax_mode'] ?? 'exclusive') === 'inclusive' ? $item['purchase_price_with_tax'] : $old['purchase_price']) ?>">
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Purchase Tax</label>
                        <select name="purchase_tax_rate_id" id="purchaseTaxRate">
                            <option value="0">No Tax</option>
                            <?php foreach ($taxRates as $tr): ?>
                                <option value="<?= $tr['id'] ?>" data-rate="<?= $tr['rate'] ?>" <?= $old['purchase_tax_rate_id'] == $tr['id'] ? 'selected' : '' ?>><?= h($tr['name']) ?> (<?= number_format($tr['rate'], 1) ?>%)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vy-tax-toggle">
                        <span class="vy-tax-label">Tax</span>
                        <div class="vy-tax-pills">
                            <button type="button" class="vy-pill <?= ($old['purchase_tax_mode'] ?? 'exclusive') === 'exclusive' ? 'active' : '' ?>" data-target="purchaseTaxMode" data-val="exclusive" onclick="setTaxMode(this)">Excl.</button>
                            <button type="button" class="vy-pill <?= ($old['purchase_tax_mode'] ?? 'exclusive') === 'inclusive' ? 'active' : '' ?>" data-target="purchaseTaxMode" data-val="inclusive" onclick="setTaxMode(this)">Incl.</button>
                        </div>
                        <input type="hidden" name="purchase_tax_mode" id="purchaseTaxMode" value="<?= h($old['purchase_tax_mode'] ?? 'exclusive') ?>">
                    </div>
                    <div class="vy-price-breakdown" id="purchaseBreakdown">
                        <div class="vy-bd-row">
                            <span>Base Price</span>
                            <span id="ppBase">₹0.00</span>
                        </div>
                        <div class="vy-bd-row">
                            <span>Tax Amount</span>
                            <span id="ppTaxAmt">₹0.00</span>
                        </div>
                        <div class="vy-bd-divider"></div>
                        <div class="vy-bd-row vy-bd-total">
                            <span>Total (incl. tax)</span>
                            <span id="ppTotal">₹0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sale Price -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-tag"></i> Sale Price</div>
                <div class="vy-card-body">
                    <div class="vy-f">
                        <label>Sale Price <span class="text-danger">*</span></label>
                        <div class="vy-input-addon">
                            <span>₹</span>
                            <input type="number" name="sale_price" id="salePrice" step="0.01" min="0" required placeholder="0.00" value="<?= h(($old['sale_tax_mode'] ?? 'exclusive') === 'inclusive' ? $item['sale_price_with_tax'] : $old['sale_price']) ?>">
                        </div>
                    </div>
                    <div class="vy-f">
                        <label>Sale Tax</label>
                        <select name="tax_rate_id" id="saleTaxRate">
                            <option value="0">No Tax</option>
                            <?php foreach ($taxRates as $tr): ?>
                                <option value="<?= $tr['id'] ?>" data-rate="<?= $tr['rate'] ?>" <?= $old['tax_rate_id'] == $tr['id'] ? 'selected' : '' ?>><?= h($tr['name']) ?> (<?= number_format($tr['rate'], 1) ?>%)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vy-tax-toggle">
                        <span class="vy-tax-label">Tax</span>
                        <div class="vy-tax-pills">
                            <button type="button" class="vy-pill <?= ($old['sale_tax_mode'] ?? 'exclusive') === 'exclusive' ? 'active' : '' ?>" data-target="saleTaxMode" data-val="exclusive" onclick="setTaxMode(this)">Excl.</button>
                            <button type="button" class="vy-pill <?= ($old['sale_tax_mode'] ?? 'exclusive') === 'inclusive' ? 'active' : '' ?>" data-target="saleTaxMode" data-val="inclusive" onclick="setTaxMode(this)">Incl.</button>
                        </div>
                        <input type="hidden" name="sale_tax_mode" id="saleTaxMode" value="<?= h($old['sale_tax_mode'] ?? 'exclusive') ?>">
                    </div>
                    <div class="vy-price-breakdown" id="saleBreakdown">
                        <div class="vy-bd-row">
                            <span>Base Price</span>
                            <span id="spBase">₹0.00</span>
                        </div>
                        <div class="vy-bd-row">
                            <span>Tax Amount</span>
                            <span id="spTaxAmt">₹0.00</span>
                        </div>
                        <div class="vy-bd-divider"></div>
                        <div class="vy-bd-row vy-bd-total">
                            <span>Total (incl. tax)</span>
                            <span id="spTotal">₹0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profit Calculator -->
            <div class="vy-card">
                <div class="vy-card-head"><i class="fas fa-calculator"></i> Profit Calculator</div>
                <div class="vy-card-body">
                    <div class="vy-profit-box" id="profitPreview">
                        <div class="vy-profit-row">
                            <span>Purchase (excl. tax)</span>
                            <span id="ppVal">₹0.00</span>
                        </div>
                        <div class="vy-profit-row">
                            <span>Sale (excl. tax)</span>
                            <span id="spVal">₹0.00</span>
                        </div>
                        <div class="vy-profit-divider"></div>
                        <div class="vy-profit-row vy-profit-total">
                            <span>Profit</span>
                            <span id="profitValue">₹0.00</span>
                        </div>
                        <div class="vy-profit-row">
                            <span>Margin</span>
                            <span id="marginValue">0%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Full Width Actions -->
    <div class="vy-form-actions">
        <div class="d-flex align-items-center gap-3">
            <a href="items.php" class="btn btn-light btn-lg"><i class="fas fa-xmark me-1"></i> Cancel</a>
            <div class="d-flex align-items-center gap-2 px-3 py-2 rounded" style="background:#f1f3f5;">
                <label class="form-check-label fw-semibold" style="font-size:13px;color:#495057;">Active</label>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="status" value="1" <?= $old['status'] ? 'checked' : '' ?> style="cursor:pointer;">
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-check me-1"></i> Update Item</button>
    </div>
</form>

<style>
.vy-add-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    align-items: start;
}

.vy-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    overflow: hidden;
}
.vy-card + .vy-card { margin-top: 16px; }

.vy-card-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary-color);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 20px;
    background: var(--primary-light);
    border-bottom: 1px solid rgba(237,26,59,0.08);
    display: flex;
    align-items: center;
    gap: 8px;
}
.vy-card-head i { font-size: 13px; }
.vy-card-body { padding: 18px 20px; }

.vy-f { margin-bottom: 14px; }
.vy-f:last-child { margin-bottom: 0; }
.vy-f label {
    font-size: 12px; font-weight: 600; color: #555;
    margin-bottom: 5px; display: block;
    text-transform: uppercase; letter-spacing: 0.3px;
}
.vy-f label .text-danger { color: var(--danger-color); }
.vy-f input, .vy-f select, .vy-f textarea {
    width: 100%; height: 40px; border: 1px solid #e0e0e0; border-radius: 8px;
    padding: 0 12px; font-size: 14px; color: #1a1a1a; background: #fafafa;
    transition: border 0.2s;
}
.vy-f textarea { height: auto; padding: 10px 12px; resize: vertical; min-height: 64px; }
.vy-f input:focus, .vy-f select:focus, .vy-f textarea:focus {
    outline: none; border-color: var(--primary-color); background: #fff;
    box-shadow: 0 0 0 3px rgba(237, 26, 59, 0.08);
}
.vy-f input::placeholder { color: #bbb; }

.vy-f-row { display: flex; gap: 12px; }
.vy-f-row .vy-f { flex: 1; }

.vy-input-addon { display: flex; align-items: stretch; }
.vy-input-addon span {
    display: flex; align-items: center; justify-content: center;
    width: 38px; background: #f0f0f0; border: 1px solid #e0e0e0;
    border-right: none; border-radius: 8px 0 0 8px;
    font-size: 13px; font-weight: 700; color: #888;
}
.vy-input-addon input { border-radius: 0 8px 8px 0 !important; flex: 1; }

.vy-img-upload {
    position: relative; border: 2px dashed #d5d5d5; border-radius: 10px;
    background: #fafafa; overflow: hidden; transition: border 0.2s;
}
.vy-img-upload:hover { border-color: var(--primary-color); }
.vy-img-placeholder { padding: 30px; text-align: center; cursor: pointer; }
.vy-img-placeholder i { font-size: 28px; color: #ccc; margin-bottom: 6px; display: block; }
.vy-img-placeholder span { font-size: 13px; color: #888; display: block; }
.vy-img-placeholder small { font-size: 11px; color: #bbb; }
.vy-img-upload input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.vy-img-upload img#imagePreview { display: block; max-height: 150px; margin: 12px auto; border-radius: 8px; }

.vy-track-toggle {
    display: flex; align-items: center; justify-content: space-between;
    padding: 4px 0; margin-bottom: 14px;
}
.vy-toggle-label { font-size: 13px; font-weight: 500; color: #333; }
.vy-toggle-desc { font-size: 11px; color: #999; margin-top: 1px; }
.vy-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.vy-switch input { opacity: 0; width: 0; height: 0; }
.vy-slider {
    position: absolute; inset: 0; background: #ccc; border-radius: 24px;
    cursor: pointer; transition: 0.3s;
}
.vy-slider::before {
    content: ''; position: absolute; height: 18px; width: 18px;
    left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s;
}
.vy-switch input:checked + .vy-slider { background: var(--primary-color); }
.vy-switch input:checked + .vy-slider::before { transform: translateX(20px); }

.vy-sku-row { display: flex; gap: 6px; align-items: center; }
.vy-sku-row input { flex: 1; }
.vy-btn-icon {
    height: 40px; min-width: 40px; border: 1px solid #e0e0e0; border-radius: 8px;
    background: #fafafa; color: var(--primary-color); font-size: 14px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: 0.2s;
}
.vy-btn-icon:hover { background: var(--primary-light); border-color: var(--primary-color); }

.vy-profit-box {
    background: #f8fdf8; border: 1px solid #e6f4e6; border-radius: 10px;
    padding: 12px 14px; margin-top: 16px;
}
.vy-profit-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px; color: #555; padding: 3px 0;
}
.vy-profit-divider { border-top: 1px dashed #d4e8d4; margin: 6px 0; }
.vy-profit-total { font-weight: 700; font-size: 14px; color: #1a1a1a; }

.vy-tax-toggle {
    display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
}
.vy-tax-label { font-size: 12px; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: 0.3px; }
.vy-tax-pills { display: flex; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
.vy-pill {
    border: none; background: #fafafa; padding: 5px 14px; font-size: 12px;
    font-weight: 600; color: #888; cursor: pointer; transition: 0.2s;
}
.vy-pill + .vy-pill { border-left: 1px solid #e0e0e0; }
.vy-pill.active { background: var(--primary-color); color: #fff; }
.vy-pill:hover:not(.active) { background: #f0f0f0; }

.vy-price-breakdown {
    background: #f8f9fa; border: 1px solid #eee; border-radius: 8px;
    padding: 10px 12px;
}
.vy-bd-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: #666; padding: 2px 0;
}
.vy-bd-divider { border-top: 1px dashed #ddd; margin: 4px 0; }
.vy-bd-total { font-weight: 700; font-size: 13px; color: #1a1a1a; }

.vy-summary-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; font-size: 13px;
}
.vy-summary-item + .vy-summary-item { border-top: 1px solid #f0f0f0; }
.vy-summary-item span:last-child { font-weight: 600; color: #1a1a1a; }

.vy-form-actions {
    display: flex; justify-content: flex-end; gap: 12px;
    padding: 16px 0 20px;
}
.vy-form-actions .btn { border-radius: 10px; font-weight: 600; min-width: 140px; height: 46px; }

@media (max-width: 991px) {
    .vy-add-grid { grid-template-columns: 1fr; }
}
</style>

<script>
function previewImage(input) {
    const preview = document.getElementById('imagePreview');
    const placeholder = document.querySelector('.vy-img-placeholder');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function getSkuPrefix() {
    var sel = document.getElementById('categorySelect');
    var opt = sel.options[sel.selectedIndex];
    var name = (opt.dataset.name || '').trim();
    if (!name) return 'ITM';
    return name.substring(0, 3).toUpperCase();
}

function generateSku() {
    var prefix = getSkuPrefix();
    fetch('api/sku_next.php?prefix=' + encodeURIComponent(prefix))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.querySelector('input[name="sku"]').value = data.sku;
        });
}

function onCategoryChange() {
    generateSku();
}

function toggleStockFields() {
    const fields = document.getElementById('stockFields');
    const on = document.getElementById('trackStockToggle').checked;
    fields.style.display = on ? 'block' : 'none';
    fields.querySelectorAll('input, select').forEach(i => i.disabled = !on);
}

function setTaxMode(btn) {
    const target = btn.dataset.target;
    const val = btn.dataset.val;
    document.getElementById(target).value = val;
    btn.parentElement.querySelectorAll('.vy-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    calcProfit();
}

function getTaxRate(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    return parseFloat(opt.dataset.rate) || 0;
}

function calcProfit() {
    const ppInput = parseFloat(document.getElementById('purchasePrice').value) || 0;
    const spInput = parseFloat(document.getElementById('salePrice').value) || 0;
    const ppMode = document.getElementById('purchaseTaxMode').value;
    const spMode = document.getElementById('saleTaxMode').value;
    const ppTaxPct = getTaxRate(document.getElementById('purchaseTaxRate'));
    const spTaxPct = getTaxRate(document.getElementById('saleTaxRate'));

    let ppBase, ppTaxAmt, ppTotal;
    if (ppMode === 'inclusive') {
        ppTotal = ppInput;
        ppBase = ppTaxPct > 0 ? ppInput / (1 + ppTaxPct / 100) : ppInput;
        ppTaxAmt = ppTotal - ppBase;
    } else {
        ppBase = ppInput;
        ppTaxAmt = ppInput * ppTaxPct / 100;
        ppTotal = ppBase + ppTaxAmt;
    }

    let spBase, spTaxAmt, spTotal;
    if (spMode === 'inclusive') {
        spTotal = spInput;
        spBase = spTaxPct > 0 ? spInput / (1 + spTaxPct / 100) : spInput;
        spTaxAmt = spTotal - spBase;
    } else {
        spBase = spInput;
        spTaxAmt = spInput * spTaxPct / 100;
        spTotal = spBase + spTaxAmt;
    }

    const profit = spBase - ppBase;
    const margin = spBase > 0 ? ((profit / spBase) * 100).toFixed(1) : 0;

    document.getElementById('ppBase').textContent = '\u20B9' + ppBase.toFixed(2);
    document.getElementById('ppTaxAmt').textContent = '\u20B9' + ppTaxAmt.toFixed(2);
    document.getElementById('ppTotal').textContent = '\u20B9' + ppTotal.toFixed(2);
    document.getElementById('spBase').textContent = '\u20B9' + spBase.toFixed(2);
    document.getElementById('spTaxAmt').textContent = '\u20B9' + spTaxAmt.toFixed(2);
    document.getElementById('spTotal').textContent = '\u20B9' + spTotal.toFixed(2);
    document.getElementById('ppVal').textContent = '\u20B9' + ppBase.toFixed(2);
    document.getElementById('spVal').textContent = '\u20B9' + spBase.toFixed(2);
    document.getElementById('profitValue').textContent = '\u20B9' + profit.toFixed(2);
    document.getElementById('profitValue').className = profit >= 0 ? 'text-success' : 'text-danger';
    document.getElementById('marginValue').textContent = margin + '%';
    document.getElementById('marginValue').className = profit >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold';
}

document.addEventListener('DOMContentLoaded', function() {
    ['purchasePrice', 'salePrice'].forEach(id => {
        document.getElementById(id).addEventListener('input', calcProfit);
    });
    ['purchaseTaxRate', 'saleTaxRate'].forEach(id => {
        document.getElementById(id).addEventListener('change', calcProfit);
    });
    calcProfit();
});
</script>

<?php include 'footer.php'; ?>
