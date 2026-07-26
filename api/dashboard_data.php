<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
header('Content-Type: application/json');

try {
    $currentMonth = (int) date('m');
    $currentYear = (int) date('Y');
    $today = today();
    $monthStart = date('Y-m-01');

    $monthLabels = [];
    $salesTotals = [];
    $purchaseTotals = [];

    for ($i = max(1, $currentMonth - 5); $i <= $currentMonth; $i++) {
        $monthLabels[] = date('M', mktime(0, 0, 0, $i, 1));
        $start = sprintf('%d-%02d-01', $currentYear, $i);
        $end = sprintf('%d-%02d-%d', $currentYear, $i, date('t', mktime(0, 0, 0, $i, 1)));

        $saleTotal = (float) query(
            "SELECT COALESCE(SUM(total), 0) FROM sales WHERE date >= ? AND date <= ? AND status != 'cancelled'",
            [$start, $end]
        )->fetchColumn();

        $purchaseTotal = (float) query(
            "SELECT COALESCE(SUM(total), 0) FROM purchases WHERE date >= ? AND date <= ? AND status != 'cancelled'",
            [$start, $end]
        )->fetchColumn();

        $salesTotals[] = $saleTotal;
        $purchaseTotals[] = $purchaseTotal;
    }

    $monthlySales = [];
    $monthlyPurchases = [];
    for ($i = 0; $i < count($monthLabels); $i++) {
        $monthlySales[] = ['month' => $monthLabels[$i], 'total' => $salesTotals[$i]];
        $monthlyPurchases[] = ['month' => $monthLabels[$i], 'total' => $purchaseTotals[$i]];
    }

    $expenseRows = fetchAll(
        "SELECT COALESCE(ec.name, 'Uncategorized') AS category, COALESCE(SUM(e.amount), 0) AS total
         FROM expenses e
         LEFT JOIN expense_categories ec ON e.category_id = ec.id
         WHERE e.date >= ? AND e.date <= ?
         GROUP BY ec.id, ec.name
         ORDER BY total DESC",
        [$monthStart, $today]
    );

    echo json_encode([
        'monthly_sales'       => $monthlySales,
        'monthly_purchases'   => $monthlyPurchases,
        'expense_categories'  => $expenseRows,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
