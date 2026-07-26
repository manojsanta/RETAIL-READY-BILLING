<?php
requireLogin();
if (empty($skipFYCheck)) {
    requireFinancialYear();
}

$user = currentUser();
$company = getCompany();
$initials = getInitials($user['full_name'] ?? $user['username'] ?? 'U');
$companyName = $company['name'] ?? 'Retail Ready';
$companyLogo = !empty($company['logo']) ? 'assets/images/' . $company['logo'] : null;
$activeFY = isFinancialYearSelected() ? currentFY() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle ?? 'Dashboard') ?> - Computer Shop Billing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body>

<!-- Top Navbar -->
<nav class="top-navbar">
    <div class="d-flex align-items-center">
        <button class="btn-hamburger" onclick="toggleSidebar()">
            <i class="fas fa-sliders"></i>
        </button>

        <?php if ($companyLogo): ?>
            <a href="index.php" class="d-flex align-items-center text-decoration-none ms-2">
                <img src="<?= $companyLogo ?>" alt="<?= $companyName ?>" style="height: 30px; width: auto;" class="me-2">
                <span class="fw-bold text-dark d-none d-md-inline" style="font-size:16px;"><?= sanitize($pageTitle ?? 'Dashboard') ?></span>
            </a>
        <?php else: ?>
            <span class="fw-bold text-dark ms-2 d-none d-md-inline" style="font-size:16px;"><?= sanitize($pageTitle ?? 'Dashboard') ?></span>
        <?php endif; ?>
    </div>

    <div class="d-flex align-items-center gap-3">
        <!-- Search -->
        <form class="d-none d-md-flex" action="search.php" method="GET">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light border-0"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="q" class="form-control bg-light border-0" placeholder="Search..." style="min-width: 200px;">
            </div>
        </form>

        <!-- Financial Year Badge -->
        <div class="dropdown">
            <a href="financial_year.php" class="btn btn-sm d-flex align-items-center gap-2 px-3 py-1.5 text-decoration-none" style="background:<?= $activeFY ? '#eef3ff' : '#fff3cd' ?>;border:1px solid <?= $activeFY ? '#c5d5f5' : '#ffc107' ?>;border-radius:8px;font-size:12px;font-weight:600;color:<?= $activeFY ? '#0d47a1' : '#856404' ?>;cursor:pointer;" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-calendar-alt" style="font-size:12px;"></i>
                <span><?= h($activeFY['name'] ?? 'Select FY') ?></span>
                <?php if ($activeFY): ?><i class="fas fa-chevron-down" style="font-size:9px;"></i><?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:220px;">
                <div class="px-2 py-1 mb-1">
                    <small class="text-muted fw-semibold" style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;">Switch Financial Year</small>
                </div>
                <?php
                $allFYs = getAllFinancialYears();
                foreach ($allFYs as $fyItem):
                    $isActiveItem = $activeFY && ($activeFY['id'] == $fyItem['id']);
                ?>
                    <a href="financial_year_switch.php?id=<?= (int)$fyItem['id'] ?>" class="dropdown-item d-flex align-items-center gap-2 py-2 rounded <?= $isActiveItem ? 'active' : '' ?>" style="font-size:13px;<?= $isActiveItem ? 'background:#eef3ff;color:#0d47a1;font-weight:600;' : '' ?>">
                        <i class="fas fa-calendar-alt" style="font-size:11px;<?= $isActiveItem ? 'color:#0d47a1;' : 'color:#aaa;' ?>"></i>
                        <div>
                            <div><?= h($fyItem['name']) ?></div>
                            <small class="text-muted" style="font-size:10px;"><?= date('d M Y', strtotime($fyItem['start_date'])) ?> - <?= date('d M Y', strtotime($fyItem['end_date'])) ?></small>
                        </div>
                        <?php if ($isActiveItem): ?>
                            <i class="fas fa-check ms-auto" style="font-size:11px;color:#0d47a1;"></i>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a href="financial_year_manage.php" class="dropdown-item d-flex align-items-center gap-2 py-2 rounded" style="font-size:13px;color:#666;">
                    <i class="fas fa-cog" style="font-size:11px;"></i>Manage Financial Years
                </a>
            </div>
        </div>

        <!-- Notification Bell -->
        <div class="dropdown">
            <button class="btn-hamburger position-relative" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-bell"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-0" style="width: 300px;">
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">Notifications</h6>
                    <span class="badge bg-danger rounded-pill">0</span>
                </div>
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-bell-slash fa-2x mb-2 d-block"></i>
                    <small>No new notifications</small>
                </div>
            </div>
        </div>

        <!-- User Dropdown -->
        <div class="dropdown">
            <button class="btn-user d-flex align-items-center" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="user-avatar-sm"><?= $initials ?></div>
                <div class="ms-2 text-start d-none d-sm-block">
                    <div class="fw-semibold text-dark" style="font-size:13px;"><?= $user['full_name'] ?? $user['username'] ?? 'User' ?></div>
                    <small class="text-muted" style="font-size:11px;"><?= ucfirst($user['role'] ?? 'user') ?></small>
                </div>
                <i class="fas fa-chevron-down ms-2 text-muted" style="font-size:10px;"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <div class="px-3 py-2 border-bottom d-none d-sm-block">
                        <div class="fw-semibold" style="font-size:14px;"><?= $user['full_name'] ?? $user['username'] ?? 'User' ?></div>
                        <small class="text-muted"><?= $user['email'] ?? '' ?></small>
                    </div>
                </li>
                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- Sidebar -->
<?php include 'sidebar.php'; ?>

<!-- Main Content -->
<div class="main-content">
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><?= sanitize($pageTitle ?? 'Dashboard') ?></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= sanitize($pageTitle ?? 'Dashboard') ?></li>
                </ol>
            </nav>
        </div>
    </div>
    <div class="container-fluid">
        <?php displayFlash(); ?>
