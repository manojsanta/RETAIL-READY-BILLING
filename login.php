<?php
require_once 'db.php';
require_once 'functions.php';

if (isLoggedIn()) {
    header('Location: financial_year.php');
    exit;
}

if ($pdo === null) {
    header('Location: install.php');
    exit;
}

try {
    $pdo->query('SELECT 1 FROM settings LIMIT 1');
} catch (PDOException $e) {
    header('Location: install.php');
    exit;
}

$error = '';
$company = getCompany();
$companyName = $company['name'] ?? 'Computer Shop Billing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($login) || empty($password)) {
            $error = 'Please enter both username/email and password.';
        } else {
            $user = fetch(
                "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 1",
                [$login, $login]
            );

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user'] = [
                    'id'        => $user['id'],
                    'username'  => $user['username'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                    'email'     => $user['email'],
                ];
                unset($_SESSION['fy_id'], $_SESSION['fy_name'], $_SESSION['fy_start'], $_SESSION['fy_end']);
                header('Location: financial_year.php');
                exit;
            } else {
                $error = 'Invalid username/email or password.';
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
    <title>Login - <?= h($companyName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Poppins', sans-serif; }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* ========== LEFT PANEL ========== */
        .brand-panel {
            flex: 0 0 55%;
            max-width: 55%;
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

        /* Animated geometric shapes */
        .brand-panel::before,
        .brand-panel::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.04;
            background: #fff;
        }
        .brand-panel::before {
            width: 600px;
            height: 600px;
            top: -200px;
            left: -150px;
            animation: floatShape 20s ease-in-out infinite;
        }
        .brand-panel::after {
            width: 450px;
            height: 450px;
            bottom: -150px;
            right: -100px;
            animation: floatShape 15s ease-in-out infinite reverse;
        }
        .geo-shape {
            position: absolute;
            border: 2px solid rgba(255,255,255,0.04);
            transform: rotate(45deg);
        }
        .geo-shape.shape-1 {
            width: 200px; height: 200px;
            top: 15%; right: 10%;
            animation: rotateShape 25s linear infinite;
        }
        .geo-shape.shape-2 {
            width: 120px; height: 120px;
            bottom: 20%; left: 8%;
            animation: rotateShape 18s linear infinite reverse;
        }
        .geo-shape.shape-3 {
            width: 80px; height: 80px;
            top: 60%; right: 25%;
            border-radius: 12px;
            animation: rotateShape 12s linear infinite;
        }
        .geo-dot {
            position: absolute;
            width: 6px; height: 6px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
        }
        .geo-dot.d1 { top: 25%; left: 20%; }
        .geo-dot.d2 { top: 70%; right: 15%; }
        .geo-dot.d3 { top: 45%; left: 50%; width: 4px; height: 4px; }
        .geo-dot.d4 { bottom: 30%; left: 35%; width: 8px; height: 8px; }

        @keyframes floatShape {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, 20px) scale(1.05); }
        }
        @keyframes rotateShape {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .brand-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 420px;
        }
        .brand-logo {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            backdrop-filter: blur(10px);
        }
        .brand-logo i {
            font-size: 36px;
            color: #64B5F6;
        }
        .brand-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 10px;
            line-height: 1.2;
        }
        .brand-subtitle {
            font-size: 15px;
            font-weight: 300;
            color: rgba(255,255,255,0.65);
            margin-bottom: 40px;
        }
        .brand-features {
            list-style: none;
            text-align: left;
            padding: 0;
            margin: 0 auto;
            max-width: 280px;
        }
        .brand-features li {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 11px 0;
            font-size: 14px;
            font-weight: 400;
            color: rgba(255,255,255,0.8);
        }
        .brand-features li i {
            width: 34px;
            height: 34px;
            background: rgba(100,181,246,0.12);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64B5F6;
            font-size: 14px;
            flex-shrink: 0;
        }
        .brand-footer {
            position: absolute;
            bottom: 28px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,0.3);
            z-index: 2;
        }

        /* ========== RIGHT PANEL ========== */
        .form-panel {
            flex: 0 0 45%;
            max-width: 45%;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 35px;
            position: relative;
            overflow-y: auto;
        }

        .form-container {
            width: 100%;
            max-width: 380px;
        }

        /* Mobile brand (hidden on desktop) */
        .mobile-brand {
            display: none;
            text-align: center;
            margin-bottom: 28px;
        }
        .mobile-brand .mobile-logo {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #1B3A5C, #0D2137);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }
        .mobile-brand .mobile-logo i { font-size: 24px; color: #64B5F6; }
        .mobile-brand h2 {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .form-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 6px;
        }
        .form-header p {
            font-size: 14px;
            color: #888;
            font-weight: 400;
        }

        /* Tabs */
        .auth-tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 24px;
            gap: 0;
        }
        .auth-tab {
            flex: 1;
            background: none;
            border: none;
            padding: 12px 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            font-weight: 500;
            color: #999;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            transition: color 0.25s;
        }
        .auth-tab i { font-size: 14px; }
        .auth-tab::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: transparent;
            transition: background 0.25s;
            border-radius: 1px;
        }
        .auth-tab.active {
            color: #0d47a1;
            font-weight: 600;
        }
        .auth-tab.active::after {
            background: #0d47a1;
        }
        .auth-tab:hover:not(.active) {
            color: #666;
        }

        /* Form fields */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .field-group {
            position: relative;
            margin-bottom: 18px;
        }
        .field-group .field-icon {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 15px;
            z-index: 4;
            pointer-events: none;
        }
        .field-group .form-control {
            width: 100%;
            height: 48px;
            padding: 0 44px 0 44px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            color: #333;
            background: #fafafa;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .field-group .form-control:focus {
            border-color: #0d47a1;
            box-shadow: 0 0 0 3px rgba(13,71,161,0.08);
            background: #fff;
            outline: none;
        }
        .field-group .form-control::placeholder {
            color: #bbb;
            font-weight: 400;
        }
        .pass-toggle {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #aaa;
            cursor: pointer;
            font-size: 15px;
            z-index: 4;
            padding: 4px;
            transition: color 0.2s;
        }
        .pass-toggle:hover { color: #0d47a1; }

        /* Remember + Forgot row */
        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .form-check-input:checked {
            background-color: #0d47a1;
            border-color: #0d47a1;
        }
        .form-check-label {
            font-size: 13px;
            color: #666;
            font-weight: 400;
        }
        .forgot-link {
            font-size: 13px;
            color: #0d47a1;
            text-decoration: none;
            font-weight: 500;
        }
        .forgot-link:hover { text-decoration: underline; color: #0a3678; }

        /* Submit button */
        .btn-submit {
            width: 100%;
            height: 48px;
            background: #0d47a1;
            border: none;
            border-radius: 10px;
            color: #fff;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
        }
        .btn-submit:hover {
            background: #0a3678;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(13,71,161,0.3);
            color: #fff;
        }
        .btn-submit:active {
            transform: translateY(0);
            box-shadow: none;
        }
        .btn-submit i { font-size: 16px; }

        .form-footer {
            text-align: center;
            margin-top: 28px;
            font-size: 12px;
            color: #aaa;
        }

        /* Error alert */
        .alert {
            border-radius: 10px;
            font-size: 13px;
            font-weight: 400;
            margin-bottom: 20px;
            border: none;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 991.98px) {
            html, body { overflow: auto; }
            .brand-panel { display: none; }
            .form-panel {
                flex: 0 0 100%;
                max-width: 100%;
                min-height: 100vh;
                padding: 40px 24px;
            }
            .mobile-brand { display: block; }
            .form-footer {
                position: static;
                margin-top: 32px;
            }
        }
        @media (max-width: 480px) {
            .form-panel { padding: 30px 18px; }
            .form-header h1 { font-size: 22px; }
            .auth-tab { font-size: 12px; padding: 10px 6px; }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- LEFT: Branding Panel -->
        <div class="brand-panel">
            <div class="geo-shape shape-1"></div>
            <div class="geo-shape shape-2"></div>
            <div class="geo-shape shape-3"></div>
            <div class="geo-dot d1"></div>
            <div class="geo-dot d2"></div>
            <div class="geo-dot d3"></div>
            <div class="geo-dot d4"></div>

            <div class="brand-content">
                <div class="brand-logo">
                    <i class="fas fa-desktop"></i>
                </div>
                <h1 class="brand-title"><?= h($companyName) ?></h1>
                <p class="brand-subtitle">Smart Billing Software for Your Business</p>
                <ul class="brand-features">
                    <li><i class="fas fa-file-invoice"></i> GST-Ready Invoicing</li>
                    <li><i class="fas fa-boxes-stacked"></i> Inventory Management</li>
                    <li><i class="fas fa-chart-line"></i> Real-time Reports</li>
                    <li><i class="fas fa-users-gear"></i> Multi-user Access</li>
                    <li><i class="fas fa-barcode"></i> Barcode Billing</li>
                </ul>
            </div>

            <div class="brand-footer">&copy; <?php echo date('Y'); ?> <?= h($companyName) ?>. All rights reserved.</div>
        </div>

        <!-- RIGHT: Login Form Panel -->
        <div class="form-panel">
            <div class="form-container">
                <div class="mobile-brand">
                    <div class="mobile-logo">
                        <i class="fas fa-desktop"></i>
                    </div>
                    <h2><?= h($companyName) ?></h2>
                </div>

                <div class="form-header">
                    <h1>Welcome Back</h1>
                    <p>Sign in to your account</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm" novalidate>
                    <?php echo csrfField(); ?>

                    <div class="field-group">
                        <i class="fas fa-user field-icon"></i>
                        <input type="text" class="form-control" id="emailField" name="login" placeholder="Email or Username"
                               value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>" autofocus>
                    </div>
                    <div class="field-group">
                        <i class="fas fa-lock field-icon"></i>
                        <input type="password" class="form-control" id="emailPass" name="password" placeholder="Password">
                        <button type="button" class="pass-toggle" onclick="togglePass()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>

                    <div class="form-options">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <a href="#" class="forgot-link">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-right-to-bracket"></i> Login
                    </button>
                </form>

                <div class="form-footer"><?= h($companyName) ?></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePass() {
            var input = document.getElementById('emailPass');
            var icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>