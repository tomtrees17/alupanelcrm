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

// Recent orders
$recent = $pdo->query(
    'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0)+o.shipping_cost FROM order_items WHERE order_id=o.id) AS amount
       FROM orders o ORDER BY o.id DESC LIMIT 6'
)->fetchAll();

// Credit alerts: overdue invoices
$overdue = $pdo->query(
    "SELECT * FROM invoices WHERE payment_status = 'overdue' ORDER BY due_date"
)->fetchAll();

view('dashboard.index', [
    'pageTitle' => '数据看板',
    'pageSub'   => '2026年5月概览',
    'revenue'   => $revenue,
    'custCount' => $custCount,
    'activeDeals' => $activeDeals,
    'taskRate'  => $taskRate,
    'funnel'    => $funnel,
    'recent'    => $recent,
    'overdue'   => $overdue,
]);
