<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$editId = intval($_GET['edit'] ?? 0);
$editUser = null;
if ($editId > 0) {
    $editUser = fetch("SELECT * FROM users WHERE id = ?", [$editId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: user_settings.php');
        exit;
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $delId = intval($_POST['user_id'] ?? 0);
        if ($delId > 0) {
            if ($delId == $_SESSION['user_id']) {
                setFlash('danger', 'You cannot delete your own account.');
            } else {
                $adminCount = dbCount("SELECT COUNT(*) FROM users WHERE role = 'admin'");
                $delUser = fetch("SELECT role FROM users WHERE id = ?", [$delId]);
                if ($delUser && $delUser['role'] === 'admin' && $adminCount <= 1) {
                    setFlash('danger', 'Cannot delete the last admin user.');
                } else {
                    query("DELETE FROM users WHERE id = ?", [$delId]);
                    setFlash('success', 'User deleted successfully.');
                }
            }
        }
        header('Location: user_settings.php');
        exit;
    }

    $fullName = sanitize($_POST['full_name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = sanitize($_POST['role'] ?? 'sales');
    $status = isset($_POST['status']) ? 1 : 0;

    if ($fullName === '') { setFlash('danger', 'Full name is required.'); header('Location: user_settings.php'); exit; }
    if ($username === '') { setFlash('danger', 'Username is required.'); header('Location: user_settings.php'); exit; }
    if (!in_array($role, ['admin', 'accountant', 'sales'])) $role = 'sales';

    $editIdPost = intval($_POST['edit_id'] ?? 0);
    if ($editIdPost > 0) {
        $existing = fetch("SELECT id FROM users WHERE username = ? AND id != ?", [$username, $editIdPost]);
        if ($existing) { setFlash('danger', 'Username already exists.'); header('Location: user_settings.php?edit=' . $editIdPost); exit; }
        if ($password !== '' && $password !== $confirmPassword) { setFlash('danger', 'Passwords do not match.'); header('Location: user_settings.php?edit=' . $editIdPost); exit; }

        if ($password !== '') {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            query("UPDATE users SET full_name=?, username=?, email=?, phone=?, password=?, role=?, status=?, updated_at=NOW() WHERE id=?",
                [$fullName, $username, $email, $phone, $hashed, $role, $status, $editIdPost]);
        } else {
            query("UPDATE users SET full_name=?, username=?, email=?, phone=?, role=?, status=?, updated_at=NOW() WHERE id=?",
                [$fullName, $username, $email, $phone, $role, $status, $editIdPost]);
        }
        setFlash('success', 'User updated successfully.');
    } else {
        $existing = fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) { setFlash('danger', 'Username already exists.'); header('Location: user_settings.php'); exit; }
        if ($password === '') { setFlash('danger', 'Password is required for new users.'); header('Location: user_settings.php'); exit; }
        if ($password !== $confirmPassword) { setFlash('danger', 'Passwords do not match.'); header('Location: user_settings.php'); exit; }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        query("INSERT INTO users (username, email, password, full_name, phone, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$username, $email, $hashed, $fullName, $phone, $role, $status]);
        setFlash('success', 'User added successfully.');
    }
    header('Location: user_settings.php');
    exit;
}

$users = fetchAll("SELECT *, (SELECT MAX(created_at) FROM sales WHERE user_id = users.id) AS last_login FROM users ORDER BY id ASC");

$pageTitle = 'User Management';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"></h5>
    <a href="user_settings.php?new=1" class="btn btn-primary btn-sm" id="addUserBtn"><i class="fas fa-plus me-1"></i> Add User</a>
</div>

<?php if (isset($_GET['new']) || $editUser): ?>
<div class="row justify-content-center mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-user me-1"></i> <?= $editUser ? 'Edit User' : 'Add New User' ?></h6>
                <a href="user_settings.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="edit_id" value="<?= $editUser['id'] ?? 0 ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= sanitize($editUser['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= sanitize($editUser['username'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($editUser['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($editUser['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password <?= $editUser ? '' : '<span class="text-danger">*</span>' ?></label>
                            <input type="password" name="password" class="form-control" <?= $editUser ? '' : 'required' ?> placeholder="<?= $editUser ? 'Leave blank to keep current' : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Confirm Password <?= $editUser ? '' : '<span class="text-danger">*</span>' ?></label>
                            <input type="password" name="confirm_password" class="form-control" <?= $editUser ? '' : 'required' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="accountant" <?= ($editUser['role'] ?? '') === 'accountant' ? 'selected' : '' ?>>Accountant</option>
                                <option value="sales" <?= ($editUser['role'] ?? '') === 'sales' ? 'selected' : '' ?>>Sales Person</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" id="userStatus" value="1" <?= ($editUser['status'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="userStatus">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> <?= $editUser ? 'Update User' : 'Create User' ?></button>
                        <a href="user_settings.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body p-2 text-muted" style="font-size:13px;">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Role Access:</strong>
        <span class="me-3"><span class="badge bg-primary">Admin</span> Full access to everything</span>
        <span class="me-3"><span class="badge bg-success">Accountant</span> All except User Management</span>
        <span><span class="badge bg-info">Sales</span> Only sales, parties, and items</span>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $idx => $usr): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="fw-semibold"><?= sanitize($usr['full_name']) ?></td>
                            <td><?= sanitize($usr['username']) ?></td>
                            <td><?= sanitize($usr['email']) ?></td>
                            <td><?= sanitize($usr['phone'] ?? '-') ?></td>
                            <td>
                                <span class="badge bg-<?= $usr['role'] === 'admin' ? 'primary' : ($usr['role'] === 'accountant' ? 'success' : 'info') ?>">
                                    <?= ucfirst($usr['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $usr['status'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $usr['status'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= dateFormatted($usr['created_at']) ?></td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="user_settings.php?edit=<?= $usr['id'] ?>" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <?php if ($usr['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
