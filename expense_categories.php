<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

// Handle add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), $_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: expense_categories.php');
        exit;
    }

    // Delete
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $delId = intval($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ?");
        $chk->execute([$delId]);
        if ($chk->fetchColumn() > 0) {
            setFlash('danger', 'Cannot delete category: expenses are assigned to it.');
        } else {
            $delStmt = $pdo->prepare("DELETE FROM expense_categories WHERE id = ?");
            $delStmt->execute([$delId]);
            setFlash('success', 'Expense category deleted.');
        }
        header('Location: expense_categories.php');
        exit;
    }

    // Add or Edit
    $catId = intval($_POST['cat_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        setFlash('danger', 'Category name is required.');
        header('Location: expense_categories.php');
        exit;
    }

    if ($catId > 0) {
        $upd = $pdo->prepare("UPDATE expense_categories SET name = ?, description = ? WHERE id = ?");
        $upd->execute([$name, $description, $catId]);
        setFlash('success', 'Category updated.');
    } else {
        $ins = $pdo->prepare("INSERT INTO expense_categories (name, description, status, created_at) VALUES (?, ?, 1, NOW())");
        $ins->execute([$name, $description]);
        setFlash('success', 'Category added.');
    }
    header('Location: expense_categories.php');
    exit;
}

// Fetch all categories with expense counts
$allCats = $pdo->query(
    "SELECT ec.*, (SELECT COUNT(*) FROM expenses WHERE category_id = ec.id) as expense_count
     FROM expense_categories ec
     ORDER BY ec.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Edit mode
$editCat = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editStmt = $pdo->prepare("SELECT * FROM expense_categories WHERE id = ?");
    $editStmt->execute([$editId]);
    $editCat = $editStmt->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = 'Expense Categories';
include 'header.php';
?>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?= $editCat ? 'Edit Category' : 'Add Category' ?></h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="cat_id" value="<?= $editCat ? $editCat['id'] : 0 ?>">

                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?= h($editCat['name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= h($editCat['description'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $editCat ? 'Update' : 'Add' ?></button>
                        <?php if ($editCat): ?>
                            <a href="expense_categories.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Expense Categories</h5>
                <a href="expenses.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i> Back to Expenses</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Expenses</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allCats)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No expense categories yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($allCats as $idx => $cat): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><strong><?= h($cat['name']) ?></strong></td>
                                    <td><?= h($cat['description']) ?: '-' ?></td>
                                    <td><span class="badge bg-info"><?= intval($cat['expense_count']) ?></span></td>
                                    <td>
                                        <a href="expense_categories.php?edit=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-edit"></i></a>
                                        <?php if ($cat['expense_count'] == 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger" disabled title="Has expenses assigned - cannot delete"><i class="fa fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
