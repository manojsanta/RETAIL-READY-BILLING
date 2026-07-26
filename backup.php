<?php
require_once 'db.php';
require_once 'functions.php';
requireLogin();

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

// Handle Backup Download + Save to folder
if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
    if (!isset($_GET['csrf']) || !hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: backup.php');
        exit;
    }

    $dbName = DB_NAME;
    $tables = fetchAll("SHOW TABLES FROM `$dbName`");
    $tableNames = [];
    foreach ($tables as $t) {
        $tableNames[] = reset($t);
    }

    $output = "-- Retail Ready Database Backup\n";
    $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- Database: $dbName\n";
    $output .= "-- =============================================\n\n";
    $output .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $output .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $output .= "SET AUTOCOMMIT = 0;\n\n";

    $totalRecords = 0;
    foreach ($tableNames as $tableName) {
        $output .= "-- -------------------------------------------\n";
        $output .= "-- Table: `$tableName`\n";
        $output .= "-- -------------------------------------------\n";
        $output .= "DROP TABLE IF EXISTS `$tableName`;\n";

        $createTable = fetch("SHOW CREATE TABLE `$tableName`");
        $output .= $createTable['Create Table'] . ";\n\n";

        $rows = fetchAll("SELECT * FROM `$tableName`");
        $totalRecords += count($rows);

        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $columns) . '`';

            $output .= "LOCK TABLES `$tableName` WRITE;\n";
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . addslashes($val) . "'";
                    }
                }
                $output .= "INSERT INTO `$tableName` ($colList) VALUES (" . implode(', ', $values) . ");\n";
            }
            $output .= "UNLOCK TABLES;\n\n";
        }
    }

    $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $output .= "SET AUTOCOMMIT = 1;\n";
    $output .= "-- End of Backup\n";

    $filename = 'retail_ready_backup_' . date('Y-m-d_His') . '.sql';
    file_put_contents($backupDir . '/' . $filename, $output);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $output;
    exit;
}

// Handle Delete Backup
if (isset($_GET['action']) && $_GET['action'] === 'delete_backup') {
    if (!isset($_GET['csrf']) || !hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: backup.php');
        exit;
    }

    $file = $_GET['file'] ?? '';
    $filePath = $backupDir . '/' . basename($file);
    if ($file && file_exists($filePath) && is_file($filePath)) {
        unlink($filePath);
        setFlash('success', 'Backup deleted successfully.');
    } else {
        setFlash('danger', 'Backup file not found.');
    }
    header('Location: backup.php');
    exit;
}

// Handle Restore from uploaded file
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'restore') {
    if (!verifyCsrf()) {
        setFlash('danger', 'Invalid request.');
        header('Location: backup.php');
        exit;
    }

    $understood = $_POST['understand'] ?? '';
    if ($understood !== '1') {
        setFlash('danger', 'Please confirm that you understand this will overwrite all data.');
        header('Location: backup.php');
        exit;
    }

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('danger', 'Please select a valid .sql backup file.');
        header('Location: backup.php');
        exit;
    }

    $file = $_FILES['backup_file'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (strtolower($ext) !== 'sql') {
        setFlash('danger', 'Only .sql files are supported.');
        header('Location: backup.php');
        exit;
    }

    $sqlContent = file_get_contents($file['tmp_name']);
    if ($sqlContent === false) {
        setFlash('danger', 'Failed to read the backup file.');
        header('Location: backup.php');
        exit;
    }

    global $pdo;
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $queries = array_filter(array_map('trim', explode(';', $sqlContent)), function ($q) {
        return $q !== '' && !preg_match('/^--/', $q) && !preg_match('/^SET\s/i', $q) && !preg_match('/^LOCK\s/i', $q) && !preg_match('/^UNLOCK\s/i', $q);
    });

    $successCount = 0;
    $errorCount = 0;
    foreach ($queries as $queryStr) {
        $queryStr = trim($queryStr);
        if ($queryStr === '') continue;
        try {
            $pdo->exec($queryStr);
            $successCount++;
        } catch (Exception $e) {
            $errorCount++;
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    setSetting('cash_balance', '');
    setFlash('success', "Restore complete! Executed $successCount queries successfully." . ($errorCount > 0 ? " ($errorCount queries skipped)" : ''));
    header('Location: backup.php');
    exit;
}

// Handle Restore from saved backup
if (isset($_GET['action']) && $_GET['action'] === 'restore_backup') {
    if (!isset($_GET['csrf']) || !hash_equals(csrfToken(), $_GET['csrf'])) {
        setFlash('danger', 'Invalid request.');
        header('Location: backup.php');
        exit;
    }

    $file = $_GET['file'] ?? '';
    $filePath = $backupDir . '/' . basename($file);
    if (!$file || !file_exists($filePath) || !is_file($filePath)) {
        setFlash('danger', 'Backup file not found.');
        header('Location: backup.php');
        exit;
    }

    $sqlContent = file_get_contents($filePath);
    global $pdo;
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $queries = array_filter(array_map('trim', explode(';', $sqlContent)), function ($q) {
        return $q !== '' && !preg_match('/^--/', $q) && !preg_match('/^SET\s/i', $q) && !preg_match('/^LOCK\s/i', $q) && !preg_match('/^UNLOCK\s/i', $q);
    });

    $successCount = 0;
    $errorCount = 0;
    foreach ($queries as $queryStr) {
        $queryStr = trim($queryStr);
        if ($queryStr === '') continue;
        try {
            $pdo->exec($queryStr);
            $successCount++;
        } catch (Exception $e) {
            $errorCount++;
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    setSetting('cash_balance', '');
    setFlash('success', "Restore complete from " . basename($file) . "! Executed $successCount queries." . ($errorCount > 0 ? " ($errorCount skipped)" : ''));
    header('Location: backup.php');
    exit;
}

$dbName = DB_NAME;
$tableCount = count(fetchAll("SHOW TABLES FROM `$dbName`"));
$totalRecords = 0;
$tablesInfo = fetchAll("SHOW TABLES FROM `$dbName`");
foreach ($tablesInfo as $t) {
    $tbl = reset($t);
    $row = fetch("SELECT COUNT(*) AS cnt FROM `$tbl`");
    $totalRecords += (int) ($row['cnt'] ?? 0);
}

// Scan backups folder
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.sql');
    foreach ($files as $f) {
        $backups[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'time' => filemtime($f),
        ];
    }
    usort($backups, function ($a, $b) { return $b['time'] - $a['time']; });
}

$pageTitle = 'Backup & Restore';
include 'header.php';
?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-download me-2"></i>Create Backup</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3" style="font-size:13px;">Download and save a backup to the server. Backups are stored in the <code>backups/</code> folder.</p>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded" style="background:#f8f9fa;">
                            <small class="text-muted d-block">Database</small>
                            <strong><?= sanitize($dbName) ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded" style="background:#f8f9fa;">
                            <small class="text-muted d-block">Tables</small>
                            <strong><?= $tableCount ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 rounded" style="background:#f8f9fa;">
                            <small class="text-muted d-block">Records</small>
                            <strong><?= number_format($totalRecords) ?></strong>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn" style="background:#ed1a3b;color:#fff;" onclick="doBackup()">
                    <i class="fas fa-download me-1"></i> Download & Save Backup
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-clock-rotate-left me-2"></i>Saved Backups (<?= count($backups) ?>)</h5>
            </div>
            <?php if (empty($backups)): ?>
                <div class="card-body text-center py-4">
                    <i class="fas fa-inbox text-muted" style="font-size:36px;"></i>
                    <p class="text-muted mt-2 mb-0">No backups saved yet. Create your first backup above.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Filename</th>
                                <th class="text-end">Size</th>
                                <th class="text-center">Date</th>
                                <th class="text-end" style="width:140px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $b): ?>
                                <tr>
                                    <td class="fw-semibold" style="font-size:13px;">
                                        <i class="fas fa-file-lines text-success me-1"></i>
                                        <?= sanitize($b['name']) ?>
                                    </td>
                                    <td class="text-end" style="font-size:13px;">
                                        <?php
                                        $sz = $b['size'];
                                        if ($sz >= 1048576) echo round($sz / 1048576, 2) . ' MB';
                                        elseif ($sz >= 1024) echo round($sz / 1024, 1) . ' KB';
                                        else echo $sz . ' B';
                                        ?>
                                    </td>
                                    <td class="text-center" style="font-size:13px;"><?= date('d M Y, h:i A', $b['time']) ?></td>
                                    <td class="text-end">
                                        <a href="backup.php?action=delete_backup&file=<?= urlencode($b['name']) ?>&csrf=<?= csrfToken() ?>"
                                           class="btn btn-sm btn-outline-danger" style="font-size:12px;"
                                           onclick="return confirm('Delete this backup permanently?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-upload me-2 text-danger"></i>Restore from File</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger d-flex align-items-start mb-3" style="font-size:13px;">
                    <i class="fas fa-exclamation-triangle me-2 mt-1"></i>
                    <div>
                        <strong>Warning!</strong> This will <u>overwrite all existing data</u> in your database. This action cannot be undone. Make sure you have a backup before proceeding.
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" id="restoreForm">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="form_action" value="restore">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Backup File (.sql)</label>
                        <input type="file" name="backup_file" class="form-control" accept=".sql" required>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="understand" value="1" id="confirmCheck" required>
                            <label class="form-check-label fw-semibold" for="confirmCheck">
                                I understand this will replace all data
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger" id="restoreBtn" disabled>
                        <i class="fas fa-upload me-1"></i> Upload & Restore
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function doBackup() {
    var iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = 'backup.php?action=download_backup&csrf=<?= csrfToken() ?>';
    document.body.appendChild(iframe);
    setTimeout(function() { location.reload(); }, 2000);
}

document.addEventListener('DOMContentLoaded', function() {
    var check = document.getElementById('confirmCheck');
    var btn = document.getElementById('restoreBtn');
    if (check && btn) {
        check.addEventListener('change', function() {
            btn.disabled = !this.checked;
        });
    }

    var restoreForm = document.getElementById('restoreForm');
    if (restoreForm) {
        restoreForm.addEventListener('submit', function(e) {
            if (!confirm('Are you absolutely sure? This will OVERWRITE ALL DATA in the database!')) {
                e.preventDefault();
            }
        });
    }
});
</script>

<?php include 'footer.php'; ?>
