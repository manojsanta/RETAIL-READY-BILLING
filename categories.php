<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        redirect('categories.php');
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $delId = intval($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
        $chk->execute([$delId]);
        if ($chk->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete category: items are assigned to it.');
        } else {
            $delStmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $delStmt->execute([$delId]);
            setFlash('success', 'Category deleted.');
        }
        redirect('categories.php');
    }

    $catId = intval($_POST['cat_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $status = intval($_POST['status'] ?? 1) ? 1 : 0;

    if ($name === '') {
        setFlash('danger', 'Category name is required.');
        redirect('categories.php');
    }

    if ($catId > 0) {
        $upd = $pdo->prepare("UPDATE categories SET name = ?, description = ?, parent_id = ?, status = ? WHERE id = ?");
        $upd->execute([$name, $description, $parent_id ?: null, $status, $catId]);
        setFlash('success', 'Category updated.');
    } else {
        $ins = $pdo->prepare("INSERT INTO categories (name, description, parent_id, status, created_at) VALUES (?, ?, ?, ?, NOW())");
        $ins->execute([$name, $description, $parent_id ?: null, $status]);
        setFlash('success', 'Category added.');
    }
    redirect('categories.php');
}

$perPage = 20;
$page = max(1, intval($_GET['page'] ?? 1));
$totalCats = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$p = paginate($totalCats, $perPage, $page);

$allCats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM items WHERE category_id = c.id) as item_count FROM categories c ORDER BY c.name ASC LIMIT {$p['per_page']} OFFSET {$p['offset']}")->fetchAll(PDO::FETCH_ASSOC);
$allCatsForDropdown = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$editCat = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editStmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $editStmt->execute([$editId]);
    $editCat = $editStmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Categories';
include 'header.php';
?>

<style>
.cat-form .form-label { font-size:11px; margin-bottom:2px; }
.cat-form .form-control, .cat-form .form-select { font-size:13px; padding:5px 8px; }
.cat-form .mb-2 { margin-bottom:8px !important; }
.cat-list table { font-size:13px; }
.cat-list th { font-size:11px; text-transform:uppercase; letter-spacing:0.3px; padding:6px 10px; }
.cat-list td { padding:6px 10px; vertical-align:middle; }
.cat-list .btn { padding:2px 6px; font-size:11px; }
</style>

<div class="row g-2">
    <div class="col-md-3">
        <div class="card">
            <div class="card-header py-2">
                <h6 class="mb-0"><?= $editCat ? '<i class="fa fa-edit me-1"></i>Edit' : '<i class="fa fa-plus me-1"></i>Add' ?> Category</h6>
            </div>
            <div class="card-body py-2 cat-form">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="cat_id" value="<?= $editCat ? $editCat['id'] : 0 ?>">

                    <div class="mb-2">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?= h($editCat['name'] ?? '') ?>" placeholder="Category name">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" value="<?= h($editCat['description'] ?? '') ?>" placeholder="Optional">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Parent</label>
                        <select name="parent_id" class="form-select">
                            <option value="0">-- None --</option>
                            <?php foreach ($allCatsForDropdown as $ac): ?>
                                <?php if (!$editCat || $ac['id'] != $editCat['id']): ?>
                                    <option value="<?= $ac['id'] ?>" <?= ($editCat['parent_id'] ?? 0) == $ac['id'] ? 'selected' : '' ?>><?= h($ac['name']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="1" <?= ($editCat['status'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= ($editCat['status'] ?? 1) == 0 ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-save me-1"></i><?= $editCat ? 'Update' : 'Add' ?></button>
                        <?php if ($editCat): ?>
                            <a href="categories.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-9">
        <div class="card cat-list">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-layer-group me-1"></i>Categories <small class="text-muted">(<?= $totalCats ?>)</small></h6>
                <span class="badge" style="background:var(--primary-color);color:#fff;"><?= $totalCats ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:35px">#</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Parent</th>
                            <th class="text-center" style="width:50px">Items</th>
                            <th style="width:60px">Status</th>
                            <th style="width:70px" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allCats)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No categories yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allCats as $idx => $cat): ?>
                                <tr>
                                    <td><?= $p['offset'] + $idx + 1 ?></td>
                                    <td><strong><?= h($cat['name']) ?></strong></td>
                                    <td class="text-muted"><?= h($cat['description']) ?: '-' ?></td>
                                    <td><?php
                                        if ($cat['parent_id']) {
                                            $parentIdx = array_search($cat['parent_id'], array_column($allCatsForDropdown, 'id'));
                                            echo $parentIdx !== false ? h($allCatsForDropdown[$parentIdx]['name']) : 'N/A';
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                    ?></td>
                                    <td class="text-center"><span class="badge bg-info"><?= intval($cat['item_count']) ?></span></td>
                                    <td><span class="badge <?= $cat['status'] == 1 ? 'bg-success' : 'bg-secondary' ?>"><?= $cat['status'] == 1 ? 'Active' : 'Inact' ?></span></td>
                                    <td class="text-center">
                                        <a href="categories.php?edit=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-edit"></i></a>
                                        <?php if ($cat['item_count'] == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger" disabled title="Has items"><i class="fa fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer py-2">
                <?= paginationLinks($p, 'categories.php') ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
