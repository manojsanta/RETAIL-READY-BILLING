<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$deleteSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        header('Location: parties.php');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $hasTransactions = fetch("SELECT COUNT(*) as cnt FROM transactions WHERE party_id = ?", [$id]);
        if ($hasTransactions && $hasTransactions['cnt'] > 0) {
            setFlash('danger', 'Cannot delete party with existing transactions.');
        } else {
            query("DELETE FROM parties WHERE id = ?", [$id]);
            $deleteSuccess = true;
            setFlash('success', 'Party deleted successfully.');
        }
    }
    header('Location: parties.php');
    exit;
}

$typeFilter = $_GET['type'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$where = [];
$params = [];

if ($typeFilter === 'customers') {
    $where[] = "(type = 'customer' OR type = 'both')";
} elseif ($typeFilter === 'suppliers') {
    $where[] = "(type = 'supplier' OR type = 'both')";
}

if ($search !== '') {
    $where[] = "(name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$countResult = fetch("SELECT COUNT(*) as total FROM parties {$whereClause}", $params);
$totalRecords = $countResult['total'] ?? 0;

$pagination = paginate($totalRecords, $perPage, $page);

$parties = fetchAll(
    "SELECT * FROM parties {$whereClause} ORDER BY name ASC LIMIT {$perPage} OFFSET {$pagination['offset']}",
    $params
);

$stats = fetch("SELECT
    SUM(CASE WHEN type IN ('customer','both') THEN opening_balance ELSE 0 END) as totalReceivable,
    SUM(CASE WHEN type IN ('supplier','both') THEN opening_balance ELSE 0 END) as totalPayable
    FROM parties");
$totalReceivable = $stats['totalReceivable'] ?? 0;
$totalPayable = $stats['totalPayable'] ?? 0;

$pageTitle = 'Parties';
include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Parties <small class="text-muted">(<?php echo $totalRecords; ?>)</small></h4>
    <a href="party_add.php" class="btn btn-primary">
        <i class="fas fa-plus me-1"></i> Add Party
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-center">
            <div class="col-auto">
                <div class="btn-group btn-group-sm">
                    <a href="?type=all&search=<?php echo urlencode($search); ?>"
                       class="btn btn-<?php echo $typeFilter === 'all' ? 'primary' : 'outline-primary'; ?>">All</a>
                    <a href="?type=customers&search=<?php echo urlencode($search); ?>"
                       class="btn btn-<?php echo $typeFilter === 'customers' ? 'primary' : 'outline-primary'; ?>">Customers</a>
                    <a href="?type=suppliers&search=<?php echo urlencode($search); ?>"
                       class="btn btn-<?php echo $typeFilter === 'suppliers' ? 'primary' : 'outline-primary'; ?>">Suppliers</a>
                </div>
            </div>
            <div class="col">
                <form method="GET" class="d-flex gap-2">
                    <?php if ($typeFilter !== 'all'): ?>
                        <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search parties..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="?type=<?php echo urlencode($typeFilter); ?>" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="card text-center border-success">
            <div class="card-body py-2">
                <small class="text-muted">Total Receivable</small>
                <div class="text-success fw-bold"><?php echo money($totalReceivable); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center border-danger">
            <div class="card-body py-2">
                <small class="text-muted">Total Payable</small>
                <div class="text-danger fw-bold"><?php echo money($totalPayable); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center border-primary">
            <div class="card-body py-2">
                <small class="text-muted">Net Balance</small>
                <div class="fw-bold <?php echo ($totalReceivable - $totalPayable) >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo money($totalReceivable - $totalPayable); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Type</th>
                <th class="text-end">Balance</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($parties)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No parties found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($parties as $i => $party):
                    $balance = getPartyBalance($party['id']);
                    $offset = ($page - 1) * $perPage;
                ?>
                    <tr>
                        <td><?php echo $offset + $i + 1; ?></td>
                        <td>
                            <a href="party_view.php?id=<?php echo $party['id']; ?>" class="text-decoration-none fw-semibold">
                                <?php echo htmlspecialchars($party['name']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($party['phone'] ?? '-'); ?></td>
                        <td>
                            <?php if ($party['type'] === 'customer'): ?>
                                <span class="badge bg-info">Customer</span>
                            <?php elseif ($party['type'] === 'supplier'): ?>
                                <span class="badge bg-warning text-dark">Supplier</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Both</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($balance > 0): ?>
                                <span class="text-success fw-bold"><?php echo money($balance); ?></span>
                            <?php elseif ($balance < 0): ?>
                                <span class="text-danger fw-bold"><?php echo money(abs($balance)); ?></span>
                            <?php else: ?>
                                <span class="text-muted"><?php echo money(0); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($party['status'] ?? 1) == 1): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="party_view.php?id=<?php echo $party['id']; ?>" class="btn btn-outline-info" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="party_edit.php?id=<?php echo $party['id']; ?>" class="btn btn-outline-warning" title="Edit">
                                    <i class="fas fa-pencil-alt"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this party?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $party['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$queryParams = array_filter(['type' => $typeFilter !== 'all' ? $typeFilter : null, 'search' => $search ?: null]);
$baseUrl = 'parties.php?' . http_build_query($queryParams);
echo paginationLinks($pagination, $baseUrl);

include 'footer.php';
?>
