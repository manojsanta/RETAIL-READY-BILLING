<?php
require_once 'db.php';
require_once 'functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

if ($pdo === null) {
    header('Location: install.php');
    exit;
}

$company = getCompany();
$companyName = $company['name'] ?? 'Computer Shop Billing';
$companyLogo = !empty($company['logo']) ? 'uploads/logo/' . $company['logo'] : null;

$financialYears = getAllFinancialYears();
$activeFY = getActiveFinancialYear();
$user = currentUser();
$initials = getInitials($user['full_name'] ?? $user['username'] ?? 'U');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $fyId = (int)($_POST['fy_id'] ?? 0);
        if ($fyId <= 0) {
            $error = 'Please select a financial year.';
        } else {
            $fy = getFinancialYearById($fyId);
            if (!$fy) {
                $error = 'Selected financial year not found.';
            } else {
                setCurrentFY($fy);
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Financial Year - <?= h($companyName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); }

        .fy-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .brand-panel {
            flex: 0 0 50%;
            max-width: 50%;
            background: linear-gradient(160deg, #1B3A5C 0%, #0D2137 50%, #0A1628 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 50px;
            position: relative;
            overflow: hidden;
            color: #fff;
        }
        .brand-panel::before, .brand-panel::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.04;
            background: #fff;
        }
        .brand-panel::before {
            width: 600px; height: 600px; top: -200px; left: -150px;
            animation: floatShape 20s ease-in-out infinite;
        }
        .brand-panel::after {
            width: 450px; height: 450px; bottom: -150px; right: -100px;
            animation: floatShape 15s ease-in-out infinite reverse;
        }
        .geo-shape {
            position: absolute;
            border: 2px solid rgba(255,255,255,0.04);
            transform: rotate(45deg);
        }
        .geo-shape.shape-1 { width: 200px; height: 200px; top: 15%; right: 10%; animation: rotateShape 25s linear infinite; }
        .geo-shape.shape-2 { width: 120px; height: 120px; bottom: 20%; left: 8%; animation: rotateShape 18s linear infinite reverse; }
        .geo-shape.shape-3 { width: 80px; height: 80px; top: 60%; right: 25%; border-radius: 12px; animation: rotateShape 12s linear infinite; }
        @keyframes floatShape {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, 20px) scale(1.05); }
        }
        @keyframes rotateShape {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .brand-content {
            position: relative; z-index: 2; text-align: center; max-width: 420px;
        }
        .brand-logo {
            width: 80px; height: 80px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px; backdrop-filter: blur(10px);
        }
        .brand-logo i { font-size: 36px; color: #64B5F6; }
        .brand-title { font-size: 28px; font-weight: 700; margin-bottom: 10px; line-height: 1.2; }
        .brand-subtitle { font-size: 15px; font-weight: 300; color: rgba(255,255,255,0.65); margin-bottom: 40px; }
        .brand-features { list-style: none; text-align: left; padding: 0; margin: 0 auto; max-width: 300px; }
        .brand-features li {
            display: flex; align-items: center; gap: 14px;
            padding: 11px 0; font-size: 14px; color: rgba(255,255,255,0.8);
        }
        .brand-features li i {
            width: 34px; height: 34px;
            background: rgba(100,181,246,0.12); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #64B5F6; font-size: 14px; flex-shrink: 0;
        }
        .brand-footer {
            position: absolute; bottom: 28px; left: 0; right: 0;
            text-align: center; font-size: 12px; color: rgba(255,255,255,0.3); z-index: 2;
        }

        .form-panel {
            flex: 0 0 50%;
            max-width: 50%;
            background: #fff;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            padding: 40px 35px; position: relative; overflow-y: auto;
        }
        .form-container { width: 100%; max-width: 440px; }

        .mobile-brand { display: none; text-align: center; margin-bottom: 24px; }
        .mobile-brand .mobile-logo {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, #1B3A5C, #0D2137);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
        }
        .mobile-brand .mobile-logo i { font-size: 24px; color: #64B5F6; }
        .mobile-brand h2 { font-size: 20px; font-weight: 700; color: #1a1a2e; }

        .form-header { text-align: center; margin-bottom: 28px; }
        .form-header h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
        .form-header p { font-size: 14px; color: #888; font-weight: 400; }

        .user-badge {
            display: flex; align-items: center; gap: 12px;
            background: #f8f9fa; border-radius: 12px; padding: 12px 16px;
            margin-bottom: 24px;
        }
        .user-avatar {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, #1B3A5C, #0D2137);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #64B5F6; font-size: 15px; font-weight: 600; flex-shrink: 0;
        }
        .user-info { flex: 1; }
        .user-info .name { font-size: 14px; font-weight: 600; color: #1a1a2e; }
        .user-info .role { font-size: 12px; color: #888; text-transform: capitalize; }

        .fy-card {
            border: 2px solid #e8e8e8; border-radius: 12px; padding: 16px 18px;
            cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 14px;
            margin-bottom: 10px; position: relative;
        }
        .fy-card:hover { border-color: #0d47a1; background: #f8faff; }
        .fy-card.selected { border-color: #0d47a1; background: #eef3ff; }
        .fy-card.active-badge::after {
            content: 'ACTIVE';
            position: absolute; top: 10px; right: 14px;
            background: #28a745; color: #fff; font-size: 9px; font-weight: 700;
            padding: 2px 8px; border-radius: 8px; letter-spacing: 0.5px;
        }
        .fy-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: rgba(13,71,161,0.08);
            display: flex; align-items: center; justify-content: center;
            color: #0d47a1; font-size: 18px; flex-shrink: 0;
        }
        .fy-card.selected .fy-icon { background: #0d47a1; color: #fff; }
        .fy-details { flex: 1; }
        .fy-name { font-size: 15px; font-weight: 600; color: #1a1a2e; margin-bottom: 2px; }
        .fy-dates { font-size: 12px; color: #888; }

        .fy-radio { display: none; }

        .btn-submit {
            width: 100%; height: 48px;
            background: #0d47a1; border: none; border-radius: 10px; color: #fff;
            font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            gap: 10px; transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
        }
        .btn-submit:hover {
            background: #0a3678; transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(13,71,161,0.3); color: #fff;
        }
        .btn-submit:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .form-footer { text-align: center; margin-top: 24px; font-size: 12px; color: #aaa; }

        .alert { border-radius: 10px; font-size: 13px; border: none; margin-bottom: 20px; }

        @media (max-width: 991.98px) {
            html, body { overflow: auto; }
            .brand-panel { display: none; }
            .form-panel {
                flex: 0 0 100%; max-width: 100%; min-height: 100vh; padding: 40px 24px;
            }
            .mobile-brand { display: block; }
        }
        @media (max-width: 480px) {
            .form-panel { padding: 30px 18px; }
            .form-header h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="fy-wrapper">
        <!-- LEFT: Branding Panel -->
        <div class="brand-panel">
            <div class="geo-shape shape-1"></div>
            <div class="geo-shape shape-2"></div>
            <div class="geo-shape shape-3"></div>

            <div class="brand-content">
                <div class="brand-logo">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h1 class="brand-title">Select Financial Year</h1>
                <p class="brand-subtitle">Choose the financial year to start your transactions</p>
                <ul class="brand-features">
                    <li><i class="fas fa-calendar-check"></i> All transactions are filtered by FY</li>
                    <li><i class="fas fa-chart-bar"></i> Reports scoped to selected period</li>
                    <li><i class="fas fa-sync-alt"></i> Switch FY anytime from header</li>
                    <li><i class="fas fa-shield-alt"></i> Data isolation between periods</li>
                </ul>
            </div>

            <div class="brand-footer">&copy; <?= date('Y') ?> <?= h($companyName) ?>. All rights reserved.</div>
        </div>

        <!-- RIGHT: FY Selection Panel -->
        <div class="form-panel">
            <div class="form-container">
                <div class="mobile-brand">
                    <div class="mobile-logo">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h2><?= h($companyName) ?></h2>
                </div>

                <div class="form-header">
                    <h1>Welcome, <?= h($user['full_name'] ?? $user['username']) ?></h1>
                    <p>Select a financial year to continue</p>
                </div>

                <div class="user-badge">
                    <div class="user-avatar"><?= $initials ?></div>
                    <div class="user-info">
                        <div class="name"><?= h($user['full_name'] ?? $user['username']) ?></div>
                        <div class="role"><?= h($user['role'] ?? 'user') ?></div>
                    </div>
                    <a href="logout.php" style="font-size:12px;color:#dc3545;text-decoration:none;font-weight:500;">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </a>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="fyForm">
                    <?= csrfField() ?>

                    <?php if (empty($financialYears)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times d-block mb-3" style="font-size:40px;color:#ddd;"></i>
                            <p class="text-muted mb-3">No financial years found. Please create one first.</p>
                            <a href="financial_year_manage.php" class="btn btn-outline-primary">
                                <i class="fas fa-plus me-2"></i>Create Financial Year
                            </a>
                        </div>
                    <?php else: ?>
                        <?php
                            $selectedFy = null;
                            if ($activeFY) {
                                $selectedFy = $activeFY['id'];
                            } elseif (!empty($financialYears)) {
                                $selectedFy = $financialYears[0]['id'];
                            }
                        ?>
                        <?php foreach ($financialYears as $fy): ?>
                            <label class="fy-card<?= ($selectedFy == $fy['id']) ? ' selected' : '' ?><?= $fy['is_active'] ? ' active-badge' : '' ?>" onclick="selectFY(this, <?= (int)$fy['id'] ?>)">
                                <input type="radio" name="fy_id" value="<?= (int)$fy['id'] ?>" class="fy-radio" <?= ($selectedFy == $fy['id']) ? 'checked' : '' ?>>
                                <div class="fy-icon"><i class="fas fa-calendar-alt"></i></div>
                                <div class="fy-details">
                                    <div class="fy-name"><?= h($fy['name']) ?></div>
                                    <div class="fy-dates"><?= dateFormatted($fy['start_date']) ?> - <?= dateFormatted($fy['end_date']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>

                        <button type="submit" class="btn-submit mt-3" id="btnProceed" <?= !$selectedFy ? 'disabled' : '' ?>>
                            <i class="fas fa-arrow-right"></i> Proceed to Dashboard
                        </button>
                    <?php endif; ?>
                </form>

                <div class="form-footer"><?= h($companyName) ?></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectFY(el, id) {
            document.querySelectorAll('.fy-card').forEach(function(card) {
                card.classList.remove('selected');
            });
            el.classList.add('selected');
            el.querySelector('input[type="radio"]').checked = true;
            document.getElementById('btnProceed').disabled = false;
        }
    </script>
</body>
</html>
