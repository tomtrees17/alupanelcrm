<?php
declare(strict_types=1);

/** @var PDO $pdo */

$revenue = (float) $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM invoices")->fetchColumn();
$custCount = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$activeDeals = (int) $pdo->query("SELECT COUNT(*) FROM deals WHERE stage <> '已成交'")->fetchColumn();
$taskTotal = (int) $pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
$taskDone  = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE done = 1')->fetchColumn();
$taskRate  = $taskTotal ? round($taskDone / $taskTotal * 100) : 0;

// Funnel by stage
$funnel = [];
$dealTotal = (int) $pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn();
$byStage = [];
foreach ($pdo->query('SELECT stage, COUNT(*) c FROM deals GROUP BY stage') as $r) {
    $byStage[$r['stage']] = (int) $r['c'];
}
foreach (deal_stages() as $s) {
    $count = $byStage[$s] ?? 0;
    $funnel[] = ['stage' => $s, 'count' => $count, 'pct' => $dealTotal ? round($count / $dealTotal * 100) : 0];
}

// Recent orders (sales see only their own)
if (sees_only_own()) {
    $recentStmt = $pdo->prepare(
        'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0)+o.shipping_cost FROM order_items WHERE order_id=o.id) AS amount
           FROM orders o WHERE o.submitter = ? ORDER BY o.id DESC LIMIT 6'
    );
    $recentStmt->execute([own_name()]);
    $recent = $recentStmt->fetchAll();
} else {
    $recent = $pdo->query(
        'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0)+o.shipping_cost FROM order_items WHERE order_id=o.id) AS amount
           FROM orders o ORDER BY o.id DESC LIMIT 6'
    )->fetchAll();
}

// Credit alerts: overdue invoices
$overdue = $pdo->query(
    "SELECT * FROM invoices WHERE payment_status = 'overdue' ORDER BY due_date"
)->fetchAll();

$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status LIKE 'pending_%'")->fetchColumn();

// Per-salesperson performance (excludes rejected; 成交 = approved orders)
$salesPerf = $pdo->query(
    "SELECT o.submitter AS name,
            COUNT(*) AS orders,
            SUM(CASE WHEN o.status = 'approved' THEN 1 ELSE 0 END) AS won,
            COALESCE(SUM(CASE WHEN o.status = 'approved'
                THEN (SELECT COALESCE(SUM(qty * price), 0) FROM order_items WHERE order_id = o.id) + o.shipping_cost
                ELSE 0 END), 0) AS amount
       FROM orders o
      WHERE o.status <> 'rejected' AND COALESCE(o.submitter, '') <> ''
   GROUP BY o.submitter
   ORDER BY amount DESC, orders DESC"
)->fetchAll();

// Hot products by ordered quantity (placed orders, excluding draft/rejected)
$hotProducts = $pdo->query(
    "SELECT oi.sku,
            MAX(oi.color) AS color, MAX(oi.spec) AS spec,
            SUM(oi.qty) AS qty,
            SUM(oi.qty * oi.price) AS amount
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE o.status NOT IN ('rejected', 'draft')
   GROUP BY oi.sku
   ORDER BY qty DESC
      LIMIT 8"
)->fetchAll();

// Monthly (last 6) & weekly (last 8) sales trend — placed orders (excl. draft/rejected).
// Management view: gated by the same 'performance' permission as the all-staff card.
$monthlySales = [];
$weeklySales  = [];
if (can_access('performance')) {
    $since = date('Y-m-d', strtotime('-6 months'));
    $ts = $pdo->prepare(
        "SELECT o.created_at AS d,
                (SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id=o.id) + o.shipping_cost AS amount
           FROM orders o
          WHERE o.status NOT IN ('draft','rejected') AND o.created_at >= ?"
    );
    $ts->execute([$since]);
    $placed = $ts->fetchAll();

    for ($i = 5; $i >= 0; $i--) {
        $m = strtotime("first day of -$i months");
        $monthlySales[date('Y-m', $m)] = ['label' => date('M', $m), 'amount' => 0.0, 'orders' => 0];
    }
    for ($i = 7; $i >= 0; $i--) {
        $wk = strtotime('monday this week', strtotime("-$i weeks"));
        $weeklySales[date('Y-m-d', $wk)] = ['label' => date('n/j', $wk), 'amount' => 0.0, 'orders' => 0];
    }
    foreach ($placed as $p) {
        $amt = (float) $p['amount'];
        $mk = substr((string) $p['d'], 0, 7);
        if (isset($monthlySales[$mk])) {
            $monthlySales[$mk]['amount'] += $amt;
            $monthlySales[$mk]['orders']++;
        }
        $wkKey = date('Y-m-d', strtotime('monday this week', strtotime((string) $p['d'])));
        if (isset($weeklySales[$wkKey])) {
            $weeklySales[$wkKey]['amount'] += $amt;
            $weeklySales[$wkKey]['orders']++;
        }
    }
    $monthlySales = array_values($monthlySales);
    $weeklySales  = array_values($weeklySales);
}

// Personal performance for users without the 全员业绩 permission (e.g. sales see only their own).
$myPerf = null;
$myName = $auth->user()['name'] ?? '';
if (!can_access('performance') && $myName !== '') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS orders,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS won,
                COALESCE(SUM(CASE WHEN status = 'approved'
                    THEN (SELECT COALESCE(SUM(qty * price), 0) FROM order_items WHERE order_id = orders.id) + shipping_cost
                    ELSE 0 END), 0) AS amount
           FROM orders WHERE submitter = ? AND status <> 'rejected'"
    );
    $stmt->execute([$myName]);
    $myPerf = $stmt->fetch();
}

view('dashboard.index', [
    'revenue'   => $revenue,
    'custCount' => $custCount,
    'activeDeals' => $activeDeals,
    'taskRate'  => $taskRate,
    'funnel'    => $funnel,
    'recent'    => $recent,
    'overdue'   => $overdue,
    'pendingOrders' => $pendingOrders,
    'salesPerf' => $salesPerf,
    'hotProducts' => $hotProducts,
    'myPerf'    => $myPerf,
    'monthlySales' => $monthlySales,
    'weeklySales'  => $weeklySales,
]);
