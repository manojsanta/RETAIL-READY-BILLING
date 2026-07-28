<?php

// =============================================
// SECURITY
// =============================================
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function h($data) {
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function formatNumber($number, $decimals = 2) {
    return number_format((float)$number, $decimals);
}

// =============================================
// AUTH HELPERS
// =============================================
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function hasRole($role) {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === $role;
}

// =============================================
// FLASH MESSAGES
// =============================================
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function displayFlash() {
    $flash = getFlash();
    if ($flash) {
        $type = $flash['type'];
        $msg = $flash['message'];
        $icons = ['success' => 'check-circle', 'danger' => 'exclamation-circle', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
        $icon = $icons[$type] ?? 'info-circle';
        $title = ucfirst($type);
        echo '<div class="modal fade" id="flashModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
                <div class="modal-content" style="border-radius:14px;overflow:hidden;border-left:4px solid ' . ($type === 'success' ? '#28a745' : ($type === 'danger' ? '#dc3545' : ($type === 'warning' ? '#ffc107' : '#17a2b8'))) . ';">
                    <div class="modal-body text-center py-4 px-4">
                        <div style="width:56px;height:56px;border-radius:50%;background:' . ($type === 'success' ? '#f0fff4' : ($type === 'danger' ? '#fff0f0' : ($type === 'warning' ? '#fffbe6' : '#f0f9ff'))) . ';display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                            <i class="fas fa-' . $icon . '" style="font-size:26px;color:' . ($type === 'success' ? '#28a745' : ($type === 'danger' ? '#dc3545' : ($type === 'warning' ? '#e6a800' : '#17a2b8'))) . ';"></i>
                        </div>
                        <h5 class="fw-semibold mb-2">' . $title . '</h5>
                        <p class="text-muted mb-0" style="font-size:14px;">' . $msg . '</p>
                    </div>
                    <div class="modal-footer border-0 justify-content-center pb-4 pt-0 gap-2">
                        <button type="button" class="btn px-4" style="border-radius:8px;font-size:14px;background:' . ($type === 'success' ? '#28a745' : ($type === 'danger' ? '#dc3545' : ($type === 'warning' ? '#e6a800' : '#17a2b8'))) . ';color:#fff;" data-bs-dismiss="modal">OK</button>
                    </div>
                </div>
            </div>
        </div>
        <script>document.addEventListener("DOMContentLoaded",function(){var m=new bootstrap.Modal(document.getElementById("flashModal"));m.show();});</script>';
    }
}

// =============================================
// DATABASE HELPERS
// =============================================
function query($sql, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch($sql, $params = []) {
    return query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}

function fetchAll($sql, $params = []) {
    return query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function insertId($sql, $params = []) {
    global $pdo;
    query($sql, $params);
    return $pdo->lastInsertId();
}

function dbCount($sql, $params = []) {
    return (int) query($sql, $params)->fetchColumn();
}

// =============================================
// NUMBER FORMATTING
// =============================================
function money($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function num($amount) {
    return number_format((float)$amount, 2);
}

// =============================================
// DATE HELPERS
// =============================================
function dateFormatted($date) {
    return date('d-M-Y', strtotime($date));
}

function dateDB($date) {
    return date('Y-m-d', strtotime($date));
}

function today() {
    return date('Y-m-d');
}

// =============================================
// INVOICE / BILL NUMBER GENERATORS
// =============================================
function generateInvoiceNo($prefix = 'INV') {
    $last = fetch("SELECT invoice_no FROM sales ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['invoice_no'])) {
        $num = intval(substr($last['invoice_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generateBillNo($prefix = 'PUR') {
    $last = fetch("SELECT bill_no FROM purchases ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['bill_no'])) {
        $num = intval(substr($last['bill_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generateReturnNo($prefix = 'RET') {
    $last = fetch("SELECT return_no FROM sale_returns ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['return_no'])) {
        $num = intval(substr($last['return_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generateEstimateNo($prefix = 'EST') {
    $last = fetch("SELECT estimate_no FROM estimates ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['estimate_no'])) {
        $num = intval(substr($last['estimate_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generateChallanNo($prefix = 'CHL') {
    $last = fetch("SELECT challan_no FROM delivery_challans ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['challan_no'])) {
        $num = intval(substr($last['challan_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generateReceiptNo($prefix = 'RCP') {
    $last = fetch("SELECT receipt_no FROM payments_in ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['receipt_no'])) {
        $num = intval(substr($last['receipt_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

function generatePaymentNo($prefix = 'PAY') {
    $last = fetch("SELECT payment_no FROM payments_out ORDER BY id DESC LIMIT 1");
    if ($last && !empty($last['payment_no'])) {
        $num = intval(substr($last['payment_no'], strlen($prefix) + 1)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . '-' . str_pad($num, 5, '0', STR_PAD_LEFT);
}

// =============================================
// SETTINGS HELPERS
// =============================================
function getSetting($key, $default = '') {
    if (!isset($GLOBALS['_setting_cache'])) {
        $GLOBALS['_setting_cache'] = [];
    }
    if (array_key_exists($key, $GLOBALS['_setting_cache'])) {
        return $GLOBALS['_setting_cache'][$key];
    }
    $row = fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    $val = $row ? $row['setting_value'] : $default;
    $GLOBALS['_setting_cache'][$key] = $val;
    return $val;
}

function setSetting($key, $value) {
    query(
        "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()",
        [$key, $value, $value]
    );
    if (isset($GLOBALS['_setting_cache'])) {
        unset($GLOBALS['_setting_cache'][$key]);
    }
}

// =============================================
// COMPANY INFO
// =============================================
function getCompany() {
    static $cache = null;
    if ($cache === null) {
        $cache = fetch("SELECT * FROM company LIMIT 1");
    }
    return $cache;
}

// =============================================
// FINANCIAL YEAR HELPERS
// =============================================
function isFinancialYearSelected() {
    return isset($_SESSION['fy_id']) && isset($_SESSION['fy_start']) && isset($_SESSION['fy_end']);
}

function requireFinancialYear() {
    if (!isFinancialYearSelected()) {
        header('Location: financial_year.php');
        exit;
    }
}

function currentFY() {
    return [
        'id'    => $_SESSION['fy_id'] ?? null,
        'name'  => $_SESSION['fy_name'] ?? '',
        'start' => $_SESSION['fy_start'] ?? '',
        'end'   => $_SESSION['fy_end'] ?? '',
    ];
}

function setCurrentFY($fy) {
    $_SESSION['fy_id']    = $fy['id'];
    $_SESSION['fy_name']  = $fy['name'];
    $_SESSION['fy_start'] = $fy['start_date'];
    $_SESSION['fy_end']   = $fy['end_date'];
}

function getAllFinancialYears() {
    return fetchAll("SELECT * FROM financial_years ORDER BY start_date DESC");
}

function getFinancialYearById($id) {
    return fetch("SELECT * FROM financial_years WHERE id = ?", [$id]);
}

function getActiveFinancialYear() {
    return fetch("SELECT * FROM financial_years WHERE is_active = 1 LIMIT 1");
}

// =============================================
// PAGINATION
// =============================================
function paginate($total, $perPage, $currentPage) {
    $totalPages = max(1, ceil($total / $perPage));
    $currentPage = max(1, min((int)$currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    return [
        'total'         => $total,
        'per_page'      => $perPage,
        'current_page'  => $currentPage,
        'total_pages'   => $totalPages,
        'offset'        => $offset,
    ];
}

function paginationLinks($pagination, $baseUrl) {
    if (!is_array($pagination)) {
        return '';
    }

    $currentPage = $pagination['current_page'];
    $totalPages  = max(1, $pagination['total_pages'] ?? 1);
    $sep         = strpos($baseUrl, '?') === false ? '?' : '&';

    $html = '<nav><ul class="pagination justify-content-center">';

    // Previous
    if ($currentPage > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . ($currentPage - 1) . '">&laquo; Previous</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Previous</span></li>';
    }

    // Page numbers with ellipsis
    $range = 2;
    $start = max(1, $currentPage - $range);
    $end   = min($totalPages, $currentPage + $range);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=1">1</a></li>';
        if ($start > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i == $currentPage) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $i . '">' . $i . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . $sep . 'page=' . ($currentPage + 1) . '">Next &raquo;</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

// =============================================
// STOCK HELPERS
// =============================================
function updateStock($itemId, $qty, $operation = 'subtract') {
    if ($operation === 'subtract') {
        query(
            "UPDATE items SET current_stock = current_stock - ? WHERE id = ? AND current_stock >= ?",
            [$qty, $itemId, $qty]
        );
    } else {
        query(
            "UPDATE items SET current_stock = current_stock + ? WHERE id = ?",
            [$qty, $itemId]
        );
    }
}

function getStockStatus($item) {
    if ($item['current_stock'] <= 0) {
        return ['Out of Stock', 'danger'];
    }
    if ($item['current_stock'] <= $item['min_stock']) {
        return ['Low Stock', 'warning'];
    }
    return ['In Stock', 'success'];
}

// =============================================
// BALANCE HELPERS
// =============================================
function getPartyBalance($partyId) {
    $party = fetch(
        "SELECT opening_balance, balance_type FROM parties WHERE id = ?",
        [$partyId]
    );

    if (!$party) {
        return 0;
    }

    $opening = (float) $party['opening_balance'];

    $salesDueVal = (float) query(
        "SELECT COALESCE(SUM(due_amount), 0) FROM sales WHERE party_id = ? AND payment_status != 'paid'",
        [$partyId]
    )->fetchColumn();

    $purchasesDueVal = (float) query(
        "SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE party_id = ? AND payment_status != 'paid'",
        [$partyId]
    )->fetchColumn();

    $paymentsInVal = (float) query(
        "SELECT COALESCE(SUM(amount), 0) FROM payments_in WHERE party_id = ?",
        [$partyId]
    )->fetchColumn();

    $paymentsOutVal = (float) query(
        "SELECT COALESCE(SUM(amount), 0) FROM payments_out WHERE party_id = ?",
        [$partyId]
    )->fetchColumn();

    // Opening balance: if type is 'credit', party owes us (positive); if 'debit', we owe party (negative)
    if ($party['balance_type'] === 'debit') {
        $opening = -$opening;
    }

    // Balance = opening + sales they owe us - purchases we owe them + payments received - payments made
    $balance = $opening + $salesDueVal - $purchasesDueVal + $paymentsInVal - $paymentsOutVal;

    return round($balance, 2);
}

// =============================================
// EXPORT HELPERS
// =============================================
function exportCSV($headers, $data, $filename) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($output, $headers);

    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function exportPDF($html, $filename) {
    // Placeholder — integrate TCPDF, Dompdf, or mPDF as needed
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '.html"');
    echo $html;
    exit;
}

// =============================================
// AVATAR / INITIALS
// =============================================
function getInitials($name) {
    $words  = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $w) {
        if (!empty($w)) {
            $initials .= strtoupper($w[0]);
        }
    }
    return $initials;
}

// =============================================
// CSRF TOKEN
// =============================================
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf($token = null) {
    $token = $token ?? $_POST['csrf_token'] ?? null;
    return $token !== null && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
