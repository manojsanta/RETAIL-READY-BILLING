<?php
// Shared data layer for Cash & Bank Book (report_cashbook.php / pdf_cashbook.php)

function getCashbookData($from, $to, $method = 'all') {
    $rows = [];
    $seq = 0;
    $addRow = function (&$rows, &$seq, $date, $ref, $desc, $mode, $dir, $amount) {
        $rows[] = [
            'date' => $date,
            'ref' => $ref,
            'desc' => $desc,
            'mode' => $mode,
            'dir' => $dir,
            'amount' => (float) $amount,
            'seq' => ++$seq,
        ];
    };

    $methodSql = '';
    $params = [];
    if ($method !== 'all') {
        $methodSql = ' AND payment_method = ?';
        $params[] = $method;
    }

    // Sales (cash/bank received portion)
    $sales = fetchAll("SELECT s.invoice_no AS ref, s.date, s.paid_amount, s.payment_method,
        p.name AS party
        FROM sales s LEFT JOIN parties p ON s.party_id = p.id
        WHERE s.date >= ? AND s.date <= ? AND s.status != 'cancelled' AND s.paid_amount > 0"
        . $methodSql . " ORDER BY s.date ASC, s.id ASC",
        array_merge([$from, $to], $params));
    foreach ($sales as $s) {
        $addRow($rows, $seq, $s['date'], $s['ref'], 'Sale ' . $s['ref'] . ' - ' . ($s['party'] ?: 'Walk-in'), $s['payment_method'], 'in', $s['paid_amount']);
    }

    // Purchases (cash/bank paid portion)
    $purchases = fetchAll("SELECT pu.bill_no AS ref, pu.date, pu.paid_amount, pu.payment_method,
        p.name AS party
        FROM purchases pu LEFT JOIN parties p ON pu.party_id = p.id
        WHERE pu.date >= ? AND pu.date <= ? AND pu.status != 'cancelled' AND pu.paid_amount > 0"
        . $methodSql . " ORDER BY pu.date ASC, pu.id ASC",
        array_merge([$from, $to], $params));
    foreach ($purchases as $p) {
        $addRow($rows, $seq, $p['date'], $p['ref'], 'Purchase ' . $p['ref'] . ' - ' . ($p['party'] ?: '-'), $p['payment_method'], 'out', $p['paid_amount']);
    }

    // Payments In
    $payIn = fetchAll("SELECT pi.receipt_no AS ref, pi.date, pi.amount, pi.payment_method,
        p.name AS party
        FROM payments_in pi LEFT JOIN parties p ON pi.party_id = p.id
        WHERE pi.date >= ? AND pi.date <= ?" . $methodSql . " ORDER BY pi.date ASC, pi.id ASC",
        array_merge([$from, $to], $params));
    foreach ($payIn as $p) {
        $addRow($rows, $seq, $p['date'], $p['ref'], 'Payment received - ' . ($p['party'] ?: '-'), $p['payment_method'], 'in', $p['amount']);
    }

    // Payments Out
    $payOut = fetchAll("SELECT po.payment_no AS ref, po.date, po.amount, po.payment_method,
        p.name AS party
        FROM payments_out po LEFT JOIN parties p ON po.party_id = p.id
        WHERE po.date >= ? AND po.date <= ?" . $methodSql . " ORDER BY po.date ASC, po.id ASC",
        array_merge([$from, $to], $params));
    foreach ($payOut as $p) {
        $addRow($rows, $seq, $p['date'], $p['ref'], 'Payment made - ' . ($p['party'] ?: '-'), $p['payment_method'], 'out', $p['amount']);
    }

    // Expenses
    $expenses = fetchAll("SELECT e.expense_no AS ref, e.date, e.amount, e.payment_method, e.notes,
        ec.name AS category
        FROM expenses e LEFT JOIN expense_categories ec ON e.category_id = ec.id
        WHERE e.date >= ? AND e.date <= ?" . $methodSql . " ORDER BY e.date ASC, e.id ASC",
        array_merge([$from, $to], $params));
    foreach ($expenses as $e) {
        $addRow($rows, $seq, $e['date'], $e['ref'], 'Expense ' . $e['ref'] . ' - ' . ($e['category'] ?: 'Uncategorized'), $e['payment_method'], 'out', $e['amount']);
    }

    // Adjustments & Transfers
    $adjustments = fetchAll("SELECT * FROM transactions WHERE type = 'adjustment' AND date >= ? AND date <= ? ORDER BY date ASC, id ASC", [$from, $to]);
    foreach ($adjustments as $a) {
        $descLower = strtolower($a['description'] ?? '');
        $legs = [];
        if (strpos($descLower, 'cash received') !== false) {
            $legs[] = ['cash', 'in'];
        } elseif (strpos($descLower, 'cash paid') !== false) {
            $legs[] = ['cash', 'out'];
        } elseif (strpos($descLower, 'transfer') !== false) {
            $fromCash = strpos($descLower, 'from cash') !== false;
            $toCash = strpos($descLower, 'to cash') !== false;
            if ($fromCash) {
                $legs[] = ['cash', 'out'];
                $legs[] = ['bank', 'in'];
            } elseif ($toCash) {
                $legs[] = ['bank', 'out'];
                $legs[] = ['cash', 'in'];
            }
        }
        foreach ($legs as $leg) {
            if ($method !== 'all' && $leg[0] !== $method) continue;
            $addRow($rows, $seq, $a['date'], '-', 'Adjustment: ' . ($a['description'] ?? ''), $leg[0], $leg[1], $a['amount']);
        }
    }

    usort($rows, function ($a, $b) {
        $cmp = strcmp($a['date'], $b['date']);
        return $cmp !== 0 ? $cmp : ($a['seq'] <=> $b['seq']);
    });

    $totalIn = 0;
    $totalOut = 0;
    foreach ($rows as $r) {
        if ($r['dir'] === 'in') $totalIn += $r['amount'];
        else $totalOut += $r['amount'];
    }
    $net = $totalIn - $totalOut;

    $cashNow = (float) getSetting('cash_balance', '0');
    $bankNow = (float) (fetch("SELECT COALESCE(SUM(current_balance),0) as t FROM bank_accounts WHERE status = 1")['t'] ?? 0);

    switch ($method) {
        case 'cash': $current = $cashNow; break;
        case 'bank': $current = $bankNow; break;
        case 'upi':
        case 'cheque': $current = 0; break;
        default: $current = $cashNow + $bankNow; break;
    }

    if ($method === 'upi' || $method === 'cheque') {
        $opening = 0;
        $closing = $net;
    } else {
        $opening = $current - $net;
        $closing = $current;
    }

    return [
        'opening' => round($opening, 2),
        'closing' => round($closing, 2),
        'totalIn' => round($totalIn, 2),
        'totalOut' => round($totalOut, 2),
        'rows' => $rows,
        'cashNow' => $cashNow,
        'bankNow' => $bankNow,
    ];
}

function cashModeLabel($mode) {
    switch ($mode) {
        case 'cash': return 'Cash';
        case 'bank': return 'Bank';
        case 'upi': return 'UPI';
        case 'cheque': return 'Cheque';
        default: return ucfirst($mode);
    }
}
