<?php
session_start();

define('DB_FILE', __DIR__ . '/db.php');
define('SQL_FILE', __DIR__ . '/database.sql');
define('INSTALLED_MARKER', __DIR__ . '/.installed');

if (file_exists(INSTALLED_MARKER)) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'test_connection') {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $user = trim($_POST['db_user'] ?? 'root');
        $pass = $_POST['db_pass'] ?? '';
        $dbname = trim($_POST['db_name'] ?? '');

        $conn = @new mysqli($host, $user, $pass);
        if ($conn->connect_error) {
            echo json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]);
            $conn->close();
            exit;
        }

        $dbCheck = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $conn->real_escape_string($dbname) . "'");
        $dbExists = ($dbCheck && $dbCheck->num_rows > 0);

        if ($dbExists) {
            $tablesCheck = $conn->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbname) . "' AND TABLE_NAME = 'settings'");
            $hasSettings = ($tablesCheck && $tablesCheck->fetch_assoc()['cnt'] > 0);
            if ($hasSettings) {
                echo json_encode(['success' => false, 'message' => 'This database already has a settings table. Application may already be installed.']);
                $conn->close();
                exit;
            }
            echo json_encode(['success' => true, 'message' => 'Connected successfully! Database "' . htmlspecialchars($dbname) . '" exists with ' . ($dbExists ? 'existing data' : 'no data') . '.', 'db_exists' => true]);
        } else {
            echo json_encode(['success' => true, 'message' => 'Connected successfully! Database "' . htmlspecialchars($dbname) . '" will be created.', 'db_exists' => false]);
        }

        $conn->close();
        exit;
    }

    if ($_POST['action'] === 'install') {
        $host = trim($_POST['db_host'] ?? 'localhost');
        $user = trim($_POST['db_user'] ?? 'root');
        $pass = $_POST['db_pass'] ?? '';
        $dbname = trim($_POST['db_name'] ?? '');

        $results = [];

        $conn = @new mysqli($host, $user, $pass);
        if ($conn->connect_error) {
            echo json_encode(['success' => false, 'results' => [['step' => 'Database Connection', 'status' => 'error', 'message' => $conn->connect_error]]]);
            $conn->close();
            exit;
        }
        $results[] = ['step' => 'Database Connection', 'status' => 'success', 'message' => 'Connected to MySQL server.'];

        $dbCheck = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $conn->real_escape_string($dbname) . "'");
        $dbExists = ($dbCheck && $dbCheck->num_rows > 0);

        if (!$dbExists) {
            if ($conn->query("CREATE DATABASE `" . $conn->real_escape_string($dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                $results[] = ['step' => 'Create Database', 'status' => 'success', 'message' => 'Database "' . htmlspecialchars($dbname) . '" created.'];
            } else {
                $results[] = ['step' => 'Create Database', 'status' => 'error', 'message' => 'Failed to create database: ' . $conn->error];
                echo json_encode(['success' => false, 'results' => $results]);
                $conn->close();
                exit;
            }
        } else {
            $results[] = ['step' => 'Create Database', 'status' => 'warning', 'message' => 'Database "' . htmlspecialchars($dbname) . '" already exists.'];
        }

        $conn->select_db($dbname);
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        if (!file_exists(SQL_FILE)) {
            $results[] = ['step' => 'Import Schema', 'status' => 'error', 'message' => 'database.sql file not found.'];
            echo json_encode(['success' => false, 'results' => $results]);
            $conn->close();
            exit;
        }

        $sql = file_get_contents(SQL_FILE);

        $sql = preg_replace('/CREATE DATABASE.*?;/i', '', $sql);
        $sql = preg_replace('/USE\s+`[^`]+`\s*;/i', '', $sql);
        $sql = preg_replace('/SET\s+FOREIGN_KEY_CHECKS\s*=\s*\d\s*;/i', '', $sql);
        $sql = preg_replace('/--[^\n]*/', '', $sql);

        $queries = array_filter(array_map('trim', explode(';', $sql)), function ($q) {
            return $q !== '' && $q !== false && trim($q) !== '';
        });

        $successCount = 0;
        $errorCount = 0;
        $lastError = '';

        foreach ($queries as $query) {
            $query = trim($query);
            if ($query === '') continue;

            if (!$conn->query($query)) {
                $errorCount++;
                $lastError = $conn->error;
            } else {
                $successCount++;
            }
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        if ($errorCount > 0 && $successCount === 0) {
            $results[] = ['step' => 'Import Schema', 'status' => 'error', 'message' => 'Failed to execute SQL: ' . $lastError];
            echo json_encode(['success' => false, 'results' => $results]);
            $conn->close();
            exit;
        }

        $results[] = ['step' => 'Import Schema', 'status' => 'success', 'message' => "Executed {$successCount} queries successfully." . ($errorCount > 0 ? " ({$errorCount} skipped)" : '')];

        $dbPhp = '<?php' . "\n";
        $dbPhp .= "define('DB_HOST', " . var_export($host, true) . ");\n";
        $dbPhp .= "define('DB_NAME', " . var_export($dbname, true) . ");\n";
        $dbPhp .= "define('DB_USER', " . var_export($user, true) . ");\n";
        $dbPhp .= "define('DB_PASS', " . var_export($pass, true) . ");\n";
        $dbPhp .= "\n";
        $dbPhp .= '$pdo = null;' . "\n\n";
        $dbPhp .= <<<'DBPHP'
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

} catch (PDOException $e) {
    $basename = basename($_SERVER['PHP_SELF'] ?? '');
    if ($basename !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    $pdo = null;
}
DBPHP;

        if (file_put_contents(DB_FILE, $dbPhp)) {
            $results[] = ['step' => 'Update Configuration', 'status' => 'success', 'message' => 'db.php updated with new credentials.'];
        } else {
            $results[] = ['step' => 'Update Configuration', 'status' => 'warning', 'message' => 'Could not write db.php. Please update it manually.'];
        }

        file_put_contents(INSTALLED_MARKER, date('Y-m-d H:i:s'));
        $results[] = ['step' => 'Finalize', 'status' => 'success', 'message' => 'Installation complete!'];

        $conn->close();
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Computer Shop Billing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{
            min-height:100vh;
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            display:flex;align-items:center;justify-content:center;
            padding:20px;
        }
        .install-container{
            width:100%;max-width:600px;
        }
        .install-card{
            background:#fff;border-radius:16px;
            box-shadow:0 20px 60px rgba(0,0,0,0.25);
            overflow:hidden;
        }
        .install-header{
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            padding:30px;text-align:center;color:#fff;
        }
        .install-header .logo-icon{
            width:64px;height:64px;
            background:rgba(255,255,255,0.2);border-radius:14px;
            display:inline-flex;align-items:center;justify-content:center;
            margin-bottom:14px;font-size:28px;
        }
        .install-header h1{font-size:22px;font-weight:700;margin-bottom:4px}
        .install-header p{font-size:13px;opacity:0.85}
        .install-body{padding:30px}

        .steps-indicator{
            display:flex;justify-content:center;margin-bottom:30px;gap:8px;
        }
        .step-dot{
            width:36px;height:36px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            font-size:13px;font-weight:700;
            background:#e9ecef;color:#aaa;
            transition:all .3s;
        }
        .step-dot.active{
            background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;
            box-shadow:0 4px 15px rgba(102,126,234,0.4);
        }
        .step-dot.done{
            background:#28a745;color:#fff;
        }
        .step-connector{
            width:30px;height:2px;background:#e9ecef;align-self:center;
            transition:background .3s;
        }
        .step-connector.done{background:#28a745}

        .wizard-step{display:none}
        .wizard-step.active{display:block}

        .welcome-icon{
            width:100px;height:100px;margin:0 auto 24px;
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            border-radius:50%;display:flex;align-items:center;justify-content:center;
            font-size:42px;color:#fff;
            animation:pulse 2s infinite;
        }
        @keyframes pulse{
            0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(102,126,234,0.4)}
            50%{transform:scale(1.03);box-shadow:0 0 0 15px rgba(102,126,234,0)}
        }
        .welcome-title{text-align:center;font-size:24px;font-weight:700;color:#333;margin-bottom:8px}
        .welcome-text{text-align:center;color:#888;font-size:14px;margin-bottom:30px;line-height:1.6}

        .form-label{font-weight:600;color:#444;font-size:13px;margin-bottom:6px}
        .form-control{
            height:46px;border-radius:10px;border:1px solid #e0e0e0;
            font-size:14px;padding:0 14px;
        }
        .form-control:focus{
            border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,0.15);
        }
        .form-text{font-size:12px;color:#999}

        .btn-primary-gradient{
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            border:none;border-radius:10px;color:#fff;
            font-weight:600;height:46px;font-size:15px;
            transition:transform .15s,box-shadow .15s;
        }
        .btn-primary-gradient:hover{
            transform:translateY(-1px);
            box-shadow:0 6px 20px rgba(102,126,234,0.4);
            color:#fff;
        }
        .btn-primary-gradient:disabled{
            opacity:.6;transform:none;box-shadow:none;cursor:not-allowed;
        }
        .btn-success-gradient{
            background:linear-gradient(135deg,#28a745,#20c997);
            border:none;border-radius:10px;color:#fff;
            font-weight:600;height:46px;font-size:15px;
            transition:transform .15s,box-shadow .15s;
        }
        .btn-success-gradient:hover{
            transform:translateY(-1px);box-shadow:0 6px 20px rgba(40,167,69,0.4);color:#fff;
        }
        .btn-outline-secondary{border-radius:10px;height:46px;font-weight:600;font-size:14px}

        .test-result{
            margin-top:14px;padding:12px 16px;border-radius:10px;
            font-size:13px;display:none;
        }
        .test-result.show{display:flex;align-items:center;gap:10px}

        .install-log{
            background:#1a1a2e;border-radius:10px;padding:16px;
            max-height:300px;overflow-y:auto;margin-top:16px;
            font-family:'Consolas','Courier New',monospace;font-size:12px;
        }
        .install-log .log-line{
            padding:5px 0;border-bottom:1px solid rgba(255,255,255,0.05);
            display:flex;align-items:flex-start;gap:8px;
        }
        .install-log .log-line:last-child{border-bottom:none}
        .log-icon{width:18px;text-align:center;flex-shrink:0;margin-top:1px}
        .log-success .log-icon{color:#28a745}
        .log-error .log-icon{color:#dc3545}
        .log-warning .log-icon{color:#ffc107}
        .log-text{color:#ccc;line-height:1.5}

        .complete-icon{
            width:100px;height:100px;margin:0 auto 24px;
            background:linear-gradient(135deg,#28a745,#20c997);
            border-radius:50%;display:flex;align-items:center;justify-content:center;
            font-size:42px;color:#fff;
        }
        .spinner-border-sm{width:18px;height:18px;border-width:2px}

        .requirements-list{list-style:none;padding:0;margin:20px 0}
        .requirements-list li{
            padding:8px 0;display:flex;align-items:center;gap:10px;
            font-size:14px;color:#555;
        }
        .requirements-list li i{font-size:16px}
        .req-ok i{color:#28a745}
        .req-fail i{color:#dc3545}
    </style>
</head>
<body>
<div class="install-container">
    <div class="install-card">
        <div class="install-header">
            <div class="logo-icon"><i class="fas fa-desktop"></i></div>
            <h1>Computer Shop Billing</h1>
            <p>Installation Wizard</p>
        </div>
        <div class="install-body">
            <div class="steps-indicator">
                <div class="step-dot active" id="dot-1">1</div>
                <div class="step-connector" id="conn-1"></div>
                <div class="step-dot" id="dot-2">2</div>
                <div class="step-connector" id="conn-2"></div>
                <div class="step-dot" id="dot-3">3</div>
                <div class="step-connector" id="conn-3"></div>
                <div class="step-dot" id="dot-4">4</div>
            </div>

            <!-- STEP 1: Welcome -->
            <div class="wizard-step active" id="step-1">
                <div class="welcome-icon"><i class="fas fa-rocket"></i></div>
                <h2 class="welcome-title">Welcome to the Installer</h2>
                <p class="welcome-text">
                    This wizard will guide you through setting up the Computer Shop Billing application.
                    You'll need your MySQL database credentials ready.
                </p>
                <ul class="requirements-list">
                    <li class="req-ok"><i class="fas fa-check-circle"></i> PHP <?php echo phpversion(); ?></li>
                    <li class="req-ok"><i class="fas fa-check-circle"></i> MySQLi Extension: <?php echo extension_loaded('mysqli') ? 'Available' : 'Missing'; ?></li>
                    <li class="req-ok"><i class="fas fa-check-circle"></i> PDO Extension: <?php echo extension_loaded('pdo') ? 'Available' : 'Missing'; ?></li>
                    <li class="<?php echo is_writable(__DIR__) ? 'req-ok' : 'req-fail'; ?>">
                        <i class="fas <?php echo is_writable(__DIR__) ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                        Writable Directory: <?php echo is_writable(__DIR__) ? 'Yes' : 'No - Please fix permissions'; ?>
                    </li>
                </ul>
                <button class="btn btn-primary-gradient w-100" onclick="goToStep(2)">
                    <i class="fas fa-play me-2"></i>Begin Installation
                </button>
            </div>

            <!-- STEP 2: Database Config -->
            <div class="wizard-step" id="step-2">
                <h5 class="mb-1" style="font-weight:700;color:#333">
                    <i class="fas fa-database me-2" style="color:#667eea"></i>Database Configuration
                </h5>
                <p class="text-muted mb-4" style="font-size:13px">Enter your MySQL database credentials below.</p>

                <form id="dbForm" onsubmit="return false;">
                    <div class="mb-3">
                        <label class="form-label">Database Host</label>
                        <input type="text" class="form-control" id="db_host" value="localhost" required>
                        <div class="form-text">Usually "localhost" or "127.0.0.1"</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" id="db_name" value="retail_ready" required>
                        <div class="form-text">Will be created if it doesn't exist</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="db_user" value="root" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="db_pass" value="">
                        </div>
                    </div>

                    <div id="testResult" class="test-result"></div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)" style="flex:0 0 auto">
                            <i class="fas fa-arrow-left me-1"></i>Back
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnTest" onclick="testConnection()" style="flex:1">
                            <i class="fas fa-plug me-2"></i>Test Connection
                        </button>
                        <button type="button" class="btn btn-primary-gradient" id="btnGoInstall" onclick="goToStep(3)" style="flex:1" disabled>
                            Continue<i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- STEP 3: Install -->
            <div class="wizard-step" id="step-3">
                <h5 class="mb-1" style="font-weight:700;color:#333">
                    <i class="fas fa-cogs me-2" style="color:#667eea"></i>Install Application
                </h5>
                <p class="text-muted mb-3" style="font-size:13px">Review and start the installation process.</p>

                <div class="alert alert-warning d-flex align-items-center py-2 mb-3" style="font-size:13px;border-radius:10px">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    This will create tables and seed data in database "<strong id="installDbName"></strong>".
                </div>

                <div class="install-log" id="installLog" style="display:none"></div>

                <div class="d-flex gap-2 mt-4" id="installActions">
                    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)" style="flex:0 0 auto" id="btnBack3">
                        <i class="fas fa-arrow-left me-1"></i>Back
                    </button>
                    <button type="button" class="btn btn-primary-gradient" id="btnInstall" onclick="runInstall()" style="flex:1">
                        <i class="fas fa-download me-2"></i>Install Now
                    </button>
                    <button type="button" class="btn btn-success-gradient" id="btnGoLogin" onclick="window.location.href='login.php'" style="flex:1;display:none">
                        <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                    </button>
                </div>
            </div>

            <!-- STEP 4: Complete -->
            <div class="wizard-step" id="step-4">
                <div class="complete-icon"><i class="fas fa-check"></i></div>
                <h2 class="welcome-title">Installation Complete!</h2>
                <p class="welcome-text">
                    The Computer Shop Billing application has been installed successfully.<br>
                    You can now log in with the default admin account.
                </p>
                <div class="card mb-4" style="border-radius:10px;border:1px solid #e9ecef">
                    <div class="card-body text-center py-3">
                        <small class="text-muted d-block mb-1">Default Login Credentials</small>
                        <strong style="font-size:15px;color:#333">admin / password</strong>
                    </div>
                </div>
                <a href="login.php" class="btn btn-success-gradient w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentStep = 1;
let dbTested = false;

function goToStep(step) {
    if (step === 3 && !dbTested) return;
    document.querySelectorAll('.wizard-step').forEach(function(el) { el.classList.remove('active'); });
    document.getElementById('step-' + step).classList.add('active');

    for (var i = 1; i <= 4; i++) {
        var dot = document.getElementById('dot-' + i);
        dot.classList.remove('active', 'done');
        if (i < step) { dot.classList.add('done'); dot.innerHTML = '<i class="fas fa-check"></i>'; }
        else if (i === step) { dot.classList.add('active'); dot.textContent = i; }
        else { dot.textContent = i; }
        if (i < 4) {
            var conn = document.getElementById('conn-' + i);
            conn.classList.toggle('done', i < step);
        }
    }
    currentStep = step;

    if (step === 3) {
        document.getElementById('installDbName').textContent = document.getElementById('db_name').value;
    }
}

function testConnection() {
    var btn = document.getElementById('btnTest');
    var result = document.getElementById('testResult');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';
    btn.disabled = true;

    var formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('db_host', document.getElementById('db_host').value);
    formData.append('db_name', document.getElementById('db_name').value);
    formData.append('db_user', document.getElementById('db_user').value);
    formData.append('db_pass', document.getElementById('db_pass').value);

    fetch('install.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            result.className = 'test-result show ' + (data.success ? 'alert-success' : 'alert-danger');
            result.innerHTML = '<i class="fas ' + (data.success ? 'fa-check-circle' : 'fa-times-circle') + '"></i><span>' + data.message + '</span>';
            btn.innerHTML = '<i class="fas fa-plug me-2"></i>Test Connection';
            btn.disabled = false;
            if (data.success) {
                dbTested = true;
                document.getElementById('btnGoInstall').disabled = false;
            }
        })
        .catch(function() {
            result.className = 'test-result show alert-danger';
            result.innerHTML = '<i class="fas fa-times-circle"></i><span>Network error. Please try again.</span>';
            btn.innerHTML = '<i class="fas fa-plug me-2"></i>Test Connection';
            btn.disabled = false;
        });
}

function runInstall() {
    var btn = document.getElementById('btnInstall');
    var log = document.getElementById('installLog');
    var backBtn = document.getElementById('btnBack3');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Installing...';
    backBtn.style.display = 'none';
    log.style.display = 'block';
    log.innerHTML = '<div class="log-line"><span class="log-icon"><i class="fas fa-spinner fa-spin"></i></span><span class="log-text">Starting installation...</span></div>';

    var formData = new FormData();
    formData.append('action', 'install');
    formData.append('db_host', document.getElementById('db_host').value);
    formData.append('db_name', document.getElementById('db_name').value);
    formData.append('db_user', document.getElementById('db_user').value);
    formData.append('db_pass', document.getElementById('db_pass').value);

    fetch('install.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            log.innerHTML = '';
            if (data.results && data.results.length > 0) {
                data.results.forEach(function(r) {
                    var icon = r.status === 'success' ? 'fa-check-circle' : (r.status === 'warning' ? 'fa-exclamation-triangle' : 'fa-times-circle');
                    var cls = 'log-' + r.status;
                    log.innerHTML += '<div class="log-line ' + cls + '"><span class="log-icon"><i class="fas ' + icon + '"></i></span><span class="log-text"><strong>' + r.step + ':</strong> ' + r.message + '</span></div>';
                });
            }
            if (data.success) {
                btn.style.display = 'none';
                document.getElementById('btnGoLogin').style.display = 'inline-flex';
                document.querySelector('#step-3 h5').innerHTML = '<i class="fas fa-check-circle me-2" style="color:#28a745"></i>Installation Successful';
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-redo me-2"></i>Retry Installation';
                backBtn.style.display = 'inline-flex';
            }
        })
        .catch(function() {
            log.innerHTML = '<div class="log-line log-error"><span class="log-icon"><i class="fas fa-times-circle"></i></span><span class="log-text">Network error. Please check your connection and try again.</span></div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-redo me-2"></i>Retry Installation';
            backBtn.style.display = 'inline-flex';
        });
}
</script>
</body>
</html>