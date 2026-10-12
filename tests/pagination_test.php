<?php

// ── List pagination + search, and the one-statement invoice status refresh ──

require_once __DIR__ . '/../app/Csrf.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

// 1) refresh_invoice_statuses() must agree with refresh_invoice_status() row for row.
mt_srand(20261013);
$today = '2026-10-13';
$dates = [null, '', '0', '2026-10-12', '2026-10-13', '2026-10-14', '2025-01-01'];
$statuses = ['pending', 'partial', 'paid', 'overdue', null];
for ($i = 0; $i < 600; $i++) {
    $total = [0, 0.0, 100, 1450000, 8935000.0, null][mt_rand(0, 5)];
    $paid = [0, null, 1, 50, (float) $total, (float) $total + 1, (float) $total - 1][mt_rand(0, 6)];
    $pdo->prepare('INSERT INTO invoices (invoice_no, customer, total, amount_paid, due_date, payment_status, voided_at) VALUES (?,?,?,?,?,?,?)')
        ->execute(['T-' . $i, 'C', $total, $paid, $dates[mt_rand(0, 6)], $statuses[mt_rand(0, 4)], mt_rand(0, 9) === 0 ? '2026-10-01 10:00:00' : null]);
}
$pdo->exec('CREATE TABLE snapshot AS SELECT * FROM invoices');
foreach ($pdo->query('SELECT id FROM invoices')->fetchAll() as $r) {
    refresh_invoice_status($pdo, (int) $r['id'], $today);
}
$expected = $pdo->query('SELECT id, payment_status FROM invoices ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec('UPDATE invoices SET payment_status = (SELECT payment_status FROM snapshot s WHERE s.id = invoices.id)');
$changed = refresh_invoice_statuses($pdo, $today);
$actual = $pdo->query('SELECT id, payment_status FROM invoices ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$diff = array_keys(array_diff_assoc($expected, $actual));
ok('bulk refresh gives the same status as the per-invoice refresh (600 random invoices)', $diff === [], 'ids ' . implode(',', array_slice($diff, 0, 5)));
ok('bulk refresh actually had work to do', $changed > 100, (string) $changed);
ok('a second run writes nothing', refresh_invoice_statuses($pdo, $today) === 0);
$voidKept = (int) $pdo->query('SELECT COUNT(*) FROM invoices i JOIN snapshot s ON s.id = i.id
    WHERE i.voided_at IS NOT NULL AND i.payment_status IS NOT s.payment_status')->fetchColumn();
ok('voided invoices keep their status', $voidKept === 0, (string) $voidKept);
ok('tomorrow, due-today invoices turn overdue', refresh_invoice_statuses($pdo, '2026-10-14') > 0);

// 2) paginate()
$pdo->exec('DELETE FROM invoices');
for ($i = 1; $i <= 123; $i++) {
    $pdo->prepare("INSERT INTO invoices (invoice_no, customer, total, invoice_date, payment_status) VALUES (?,?,1000,'2026-10-01','pending')")
        ->execute([sprintf('INV-%03d', $i), $i % 2 ? 'PT Ganjil' : 'PT Genap']);
}
$_GET = [];
$pg = paginate($pdo, 'SELECT COUNT(*) FROM invoices', []);
ok('page 1 by default', $pg['page'] === 1 && $pg['pages'] === 3 && $pg['total'] === 123 && $pg['offset'] === 0);
ok('limit clause is LIMIT 50 OFFSET 0', $pg['limit'] === ' LIMIT 50 OFFSET 0', $pg['limit']);
$_GET['page'] = '3';
ok('last page offset', paginate($pdo, 'SELECT COUNT(*) FROM invoices', [])['offset'] === 100);
$_GET['page'] = '99';
ok('page past the end clamps to the last page', paginate($pdo, 'SELECT COUNT(*) FROM invoices', [])['page'] === 3);
foreach (['-4', '0', 'abc', '1; DROP TABLE invoices', ['x']] as $bad) {
    $_GET['page'] = $bad;
    $p = paginate($pdo, 'SELECT COUNT(*) FROM invoices', []);
    if ($p['page'] !== 1 || !preg_match('/^ LIMIT \d+ OFFSET \d+$/', $p['limit'])) {
        ok('hostile ?page= is neutralised: ' . json_encode($bad), false, $p['limit']);
    }
}
ok('hostile ?page= values fall back to page 1', true);
$_GET['page'] = '2';
ok('filters narrow the count', paginate($pdo, 'SELECT COUNT(*) FROM invoices WHERE customer = ?', ['PT Genap'])['total'] === 61);
$_GET = [];
$empty = paginate($pdo, 'SELECT COUNT(*) FROM invoices WHERE customer = ?', ['nobody']);
ok('no rows → one empty page, not page 0', $empty['page'] === 1 && $empty['pages'] === 1 && $empty['offset'] === 0);

// 3) pager()
$bar = pager('finance.index', ['status' => 'overdue', 'q' => 'PT & Co', 'blank' => ''], ['page' => 2, 'pages' => 3, 'total' => 123, 'offset' => 50, 'limit' => '']);
ok('pager keeps filters in its links', str_contains($bar, 'status=overdue') && str_contains($bar, 'q=PT+%26+Co'));
ok('pager links are HTML-escaped', str_contains($bar, '&amp;page=1') && !str_contains($bar, '"index.php?r=finance.index&status'));
ok('pager drops empty filters', !str_contains($bar, 'blank='));
ok('pager links to both neighbours', str_contains($bar, 'page=1') && str_contains($bar, 'page=3'));
ok('pager shows position and total', str_contains($bar, sprintf(t('pager_info'), 2, 3, 123)));
$first = pager('orders.index', [], ['page' => 1, 'pages' => 3, 'total' => 123, 'offset' => 0, 'limit' => '']);
ok('no "previous" link on page 1', !str_contains($first, 'page=0') && str_contains($first, 'pager-off'));
ok('single page shows just the count', pager('orders.index', [], ['page' => 1, 'pages' => 1, 'total' => 7, 'offset' => 0, 'limit' => '']) === '<div class="pager"><span class="muted">' . e(sprintf(t('pager_total'), 7)) . '</span></div>');
ok('nothing at all for an empty list', pager('orders.index', [], ['page' => 1, 'pages' => 1, 'total' => 0, 'offset' => 0, 'limit' => '']) === '');
foreach (['pager_info', 'pager_total', 'search_orders', 'search_invoices', 'search_do'] as $k) {
    if (!isset(I18N['zh'][$k], I18N['id'][$k])) {
        ok("{$k} translated", false);
    }
}
ok('pager format strings take their arguments in both languages',
    str_contains(vsprintf(I18N['id']['pager_info'], [2, 3, 123]), '123') && str_contains(vsprintf(I18N['zh']['pager_info'], [2, 3, 123]), '123'));

// 4) The real list controllers, rendered through view() + layout. Each render
//    runs in its own process (controllers declare functions), against a file
//    copy of this in-memory database.
$dbFile = tempnam(sys_get_temp_dir(), 'pgtest');
$harness = __DIR__ . '/support/run_controller.php';
$canRun = function_exists('exec') && is_file($harness);
$runList = function (string $controller, array $get, array $user) use ($pdo, $dbFile, $harness): string {
    @unlink($dbFile);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($dbFile));
    $cmd = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $harness, $dbFile, $controller, 'index', json_encode($get), json_encode($user)]));
    $errFile = $dbFile . '.err';
    exec($cmd . ' 2>' . escapeshellarg($errFile), $out, $rc);
    $err = trim((string) @file_get_contents($errFile));
    @unlink($errFile);
    if ($rc !== 0 || $err !== '') {
        throw new RuntimeException("{$controller}.index failed (rc {$rc}): {$err}");
    }
    return implode("\n", $out);
};
$rows = fn(string $html): int => substr_count($html, '<tr class="clickable');
$admin = ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];

if ($canRun) {
// Invoices: 123 rows → 50 / 50 / 23.
$h = $runList('finance', [], $admin);
ok('finance: page 1 shows 50 invoices', $rows($h) === 50, (string) $rows($h));
ok('finance: newest first (ties broken by id)', strpos($h, 'INV-123') !== false && strpos($h, 'INV-123') < strpos($h, 'INV-122'));
ok('finance: pager rendered', str_contains($h, 'class="pager"') && str_contains($h, 'page=2'));
$h = $runList('finance', ['page' => '3'], $admin);
ok('finance: last page has the remaining 23', $rows($h) === 23 && str_contains($h, 'INV-001'), (string) $rows($h));
$h = $runList('finance', ['q' => 'INV-11'], $admin);
ok('finance: search by invoice number', $rows($h) === 10 && str_contains($h, 'INV-110') && !str_contains($h, 'INV-120'), (string) $rows($h));
$h = $runList('finance', ['q' => 'Genap', 'status' => 'pending'], $admin);
ok('finance: search + status filter combine, pager keeps both', $rows($h) === 50 && str_contains($h, 'q=Genap') && str_contains($h, 'status=pending'));
ok('finance: totals cards ignore paging', str_contains($h, idr_short(123000)));

// Orders: sales see only their own, search stays inside that scope.
$pdo->exec('DELETE FROM orders');
for ($i = 1; $i <= 75; $i++) {
    $pdo->prepare("INSERT INTO orders (order_no, customer_name, company, submitter, status, shipping_cost) VALUES (?,?,?,?, 'approved', 0)")
        ->execute([sprintf('SO-%03d', $i), 'Budi ' . $i, $i <= 60 ? 'PT Sinar' : 'CV Lain', $i % 3 ? 'Rina' : 'Andi']);
}
$h = $runList('orders', [], $admin);
ok('orders: admin page 1 = 50 of 75', $rows($h) === 50 && str_contains($h, sprintf(t('pager_info'), 1, 2, 75)));
$h = $runList('orders', ['q' => 'CV Lain'], $admin);
ok('orders: search by company', $rows($h) === 15 && !str_contains($h, 'class="pager"><a'), (string) $rows($h));
$sales = ['id' => 9, 'name' => 'Andi', 'role' => 'sales'];
$h = $runList('orders', [], $sales);
ok('orders: sales sees only own (25), one page', $rows($h) === 25 && !str_contains($h, 'Rina'), (string) $rows($h));
$h = $runList('orders', ['q' => 'Rina'], $sales);
ok('orders: searching another rep\'s name finds nothing for sales', $rows($h) === 0);
$h = $runList('orders', ['q' => "' OR 1=1 --"], $admin);
ok('orders: SQL in the search box is just text', $rows($h) === 0);

// Delivery orders and customers paginate too.
for ($i = 1; $i <= 55; $i++) {
    $pdo->prepare('INSERT INTO delivery_orders (do_no, customer, company) VALUES (?,?,?)')->execute([sprintf('DO-%03d', $i), 'Budi', 'PT Sinar']);
    $pdo->prepare("INSERT INTO customers (name, company, owner, tag, value) VALUES (?,?,?, '潜在', ?)")->execute(['Cust ' . $i, 'PT X', 'Andi', $i * 1000]);
}
$h = $runList('delivery', ['page' => '2'], $admin);
ok('delivery: page 2 shows the remaining 5', substr_count($h, 'delivery.print') === 5, (string) substr_count($h, 'delivery.print'));
$h = $runList('delivery', ['q' => 'DO-05'], $admin);
ok('delivery: search by DO number', substr_count($h, 'delivery.print') === 6);
$h = $runList('customers', ['sort' => 'value_desc', 'page' => '2'], $admin);
ok('customers: sorted page 2 continues the order', $rows($h) === 5 && str_contains($h, 'Cust 5<') && str_contains($h, 'sort=value_desc'));
$h = $runList('approvals', [], $admin);
ok('approvals: empty list renders without a pager', !str_contains($h, 'class="pager"'));

}
@unlink($dbFile);
$_GET = [];
