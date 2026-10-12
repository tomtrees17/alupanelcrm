<?php
declare(strict_types=1);

/** @var PDO $pdo */
/** @var Auth $auth */
/** @var array $config */

// Figures live in app/Dashboard.php; this file only decides who sees what.
$today = date('Y-m-d');
$period = Dashboard::period((string) input('p', 'month'), (string) input('d', $today), $today);

$fin  = can_access('finance');       // cash: collected, receivables, aging
$perf = can_access('performance');   // company-wide sales + per-salesperson ranking
// Without 'performance' a user sees only their own sales — same rule as before.
$owner = $perf ? null : own_name();

$data = Dashboard::data($pdo, $period, $today, $owner, $fin, $perf, (string) ($auth->user()['role'] ?? ''));

// Recent orders (sales see only their own).
$recentSql = 'SELECT o.*, (SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id=o.id) + o.shipping_cost AS amount
                FROM orders o';
if (sees_only_own()) {
    $st = $pdo->prepare($recentSql . ' WHERE o.submitter = ? ORDER BY o.id DESC LIMIT 6');
    $st->execute([own_name()]);
    $recent = $st->fetchAll();
} else {
    $recent = $pdo->query($recentSql . ' ORDER BY o.id DESC LIMIT 6')->fetchAll();
}

view('dashboard.index', $data + [
    // Backup health is an admin concern; nobody else can act on it.
    'offsite' => $auth->isAdmin() ? Dashboard::offsite_health($pdo, $config, time()) : null,
    'recent' => $recent,
    'fin'    => $fin,
    'perf'   => $perf,
]);
