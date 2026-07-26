<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$errors = [];
$old = [
    'expense_no' => '', 'date' => date('Y-m-d'), 'category_id' => '',
    'amount' => '', 'payment_method' => 'cash', 'reference_no' => '', 'notes' => ''
];
$editMode = false;
$editExpense = null;

// Auto-generate expense number
$lastExp = $pdo->query("SELECT expense_no FROM expenses ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($lastExp && !empty($lastExp['expense_no'])) {
    $num = intval(substr($lastExp['expense_no'], 4)) + 1;
} else {
    $num = 1;
}
$suggestedNo = 'EXP-' . str_pad($num, 5, '0', STR_PAD_LEFT);

// Edit mode
if (isset($_GET['id']) && intval($_GET['id']) > 0) {
    $editId = intval($_GET['id']);
    $editStmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ?");
    $editStmt->execute([$editId]);
    $editExpense = $editStmt->fetch(PDO::FETCH_ASSOC);

    if ($editExpense) {
        $editMode = true;
        $old['expense_no'] = $editExpense['expense_no'];
        $old['date'] = $editExpense['date'];
        $old['category_id'] = $editExpense['category_id'];
        $old['amount'] = $editExpense['amount'];
        $old['payment_method'] = $editExpense['payment_method'];
        $old['reference_no'] = $editExpense['reference_no'];
        $old['notes'] = $editExpense['notes'];
        $suggestedNo = $editExpense['expense_no'];
    }
}

// Fetch expense categories
$categories = $pdo->query("SELECT id, name FROM expense_categories WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrfToken(), $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request.';
    } else {
        $old['expense_no'] = trim($_POST['expense_no'] ?? '');
        $old['date'] = trim($_POST['date'] ?? '');
        $old['category_id'] = intval($_POST['category_id'] ?? 0);
        $old['amount'] = trim($_POST['amount'] ?? '');
        $old['payment_method'] = trim($_POST['payment_method'] ?? 'cash');
        $old['reference_no'] = trim($_POST['reference_no'] ?? '');
        $old['notes'] = trim($_POST['notes'] ?? '');

        if ($old['date'] === '') $errors[] = 'Date is required.';
        if ($old['category_id'] <= 0) $errors[] = 'Category is required.';
        if ($old['amount'] === '' || floatval($old['amount']) <= 0) $errors[] = 'Valid amount is required.';
        if (!in_array($old['payment_method'], ['cash', 'bank', 'upi', 'cheque'])) $old['payment_method'] = 'cash';

        if (empty($errors)) {
            $receiptImage = null;

            if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (in_array($_FILES['receipt_image']['type'], $allowed) && $_FILES['receipt_image']['size'] <= 5 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION);
                    $receiptImage = 'receipt_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $uploadDir = __DIR__ . '/assets/images/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    move_uploaded_file($_FILES['receipt_image']['tmp_name'], $uploadDir . $receiptImage);
                } else {
                    $errors[] = 'Receipt image must be JPG, PNG, GIF or WebP and under 5MB.';
                }
            }

            if (empty($errors)) {
                if ($editMode) {
                    $sql = "UPDATE expenses SET date = ?, category_id = ?, amount = ?, payment_method = ?, reference_no = ?, notes = ?";
                    $params = [$old['date'], $old['category_id'], floatval($old['amount']), $old['payment_method'], $old['reference_no'], $old['notes']];
                    if ($receiptImage) {
                        $sql .= ", receipt_image = ?";
                        $params[] = $receiptImage;
                    }
                    $sql .= " WHERE id = ?";
                    $params[] = $editExpense['id'];
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    setFlash('success', 'Expense updated successfully.');
                } else {
                    $expenseNo = $old['expense_no'] ?: $suggestedNo;
                    $stmt = $pdo->prepare("INSERT INTO expenses (expense_no, category_id, user_id, date, amount, payment_method, reference_no, notes, receipt_image, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                    $stmt->execute([
                        $expenseNo,
                        $old['category_id'],
                        $_SESSION['user_id'],
                        $old['date'],
                        floatval($old['amount']),
                        $old['payment_method'],
                        $old['reference_no'],
                        $old['notes'],
                        $receiptImage
                    ]);
                    setFlash('success', 'Expense added successfully.');
                }
                header('Location: expenses.php');
                exit;
            }
        }
    }
}

$pageTitle = $editMode ? 'Edit Expense' : 'Add Expense';
include 'header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?= $editMode ? 'Edit Expense' : 'Add New Expense' ?></h5>
                <a href="expenses.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i> Back to Expenses</a>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <?php if ($editMode && !empty($editExpense['receipt_image'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Receipt Image</label><br>
                        <img src="assets/images/<?= h($editExpense['receipt_image']) ?>" alt="Receipt" class="img-thumbnail" style="max-height: 120px;">
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Expense No</label>
                            <input type="text" name="expense_no" class="form-control" value="<?= h($old['expense_no'] ?: $suggestedNo) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="date" class="form-control" required value="<?= h($old['date']) ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="0">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $old['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required value="<?= h($old['amount']) ?>" placeholder="0.00">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash" <?= $old['payment_method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                <option value="bank" <?= $old['payment_method'] === 'bank' ? 'selected' : '' ?>>Bank</option>
                                <option value="upi" <?= $old['payment_method'] === 'upi' ? 'selected' : '' ?>>UPI</option>
                                <option value="cheque" <?= $old['payment_method'] === 'cheque' ? 'selected' : '' ?>>Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Reference No (Invoice / Bill Number)</label>
                            <input type="text" name="reference_no" class="form-control" value="<?= h($old['reference_no']) ?>" placeholder="Enter reference or bill number">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes about this expense"><?= h($old['notes']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Receipt Image (optional)</label>
                        <input type="file" name="receipt_image" class="form-control" accept="image/*">
                        <div class="form-text">Max 5MB. JPG, PNG, GIF or WebP.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $editMode ? 'Update Expense' : 'Save Expense' ?></button>
                        <a href="expenses.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
