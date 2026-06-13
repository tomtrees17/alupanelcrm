<?php
declare(strict_types=1);

/** @var PDO $pdo */

$stats = [
    'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'products'  => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
    'quotes'    => (int) $pdo->query('SELECT COUNT(*) FROM quotes')->fetchColumn(),
    'pipeline'  => (float) $pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM quotes WHERE status IN ('sent','accepted','ordered')"
    )->fetchColumn(),
];

$recent = $pdo->query(
    "SELECT q.*, c.company
       FROM quotes q
       JOIN customers c ON c.id = q.customer_id
   ORDER BY q.id DESC
      LIMIT 8"
)->fetchAll();

view('dashboard.index', ['stats' => $stats, 'recent' => $recent]);
