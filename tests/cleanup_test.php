<?php

// ── Deleting an order must not be able to orphan its paperwork ──

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);
$pdo->exec('PRAGMA foreign_keys = ON');   // the app enables this in Database::connect

$mkOrder = function (string $no, string $cust) use ($pdo): int {
    $pdo->prepare("INSERT INTO orders (order_no,customer_name,status,submitter) VALUES (?,?,'approved','Ahmad')")
        ->execute([$no, $cust]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,sku,qty,price) VALUES (?,?,?,?)')->execute([$oid, 'A1', 3, 100]);
    return $oid;
};
$mkInvoice = function (int $oid, string $no, string $cust, float $total) use ($pdo): int {
    $pdo->prepare('INSERT INTO invoices (invoice_no,order_id,customer,total,payment_status) VALUES (?,?,?,?,?)')
        ->execute([$no, $oid, $cust, $total, 'pending']);
    $iid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO invoice_items (invoice_id,sku,qty,price) VALUES (?,?,?,?)')->execute([$iid, 'A1', 3, 100]);
    return $iid;
};

// 1) Cascades: the child rows must go with their parent, nothing left behind.
$oid = $mkOrder('SO-1', 'Test Co');
$iid = $mkInvoice($oid, 'INV-1', 'Test Co', 1000);
$pdo->prepare('INSERT INTO payments (invoice_id,customer,amount) VALUES (?,?,?)')->execute([$iid, 'Test Co', 400]);

$pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid]);
ok('invoice_items cascade with the invoice', (int) $pdo->query("SELECT COUNT(*) FROM invoice_items WHERE invoice_id={$iid}")->fetchColumn() === 0);
ok('payments cascade with the invoice', (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE invoice_id={$iid}")->fetchColumn() === 0);

$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
ok('order_items cascade with the order', (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id={$oid}")->fetchColumn() === 0);

// 2) The orphan the guard exists to prevent: deleting an order that still has an
//    invoice only nulls the link, leaving the invoice stranded on the finance list.
$oid2 = $mkOrder('SO-2', 'Test Co');
$iid2 = $mkInvoice($oid2, 'INV-2', 'Test Co', 2000);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid2]);
$orphan = $pdo->query("SELECT * FROM invoices WHERE id={$iid2}")->fetch();
ok('deleting an order does NOT delete its invoice', $orphan !== false);
ok('the invoice is left orphaned (order_id nulled)', $orphan['order_id'] === null);

// 3) The controller guard: an order with an invoice must be refused.
//    Same query the controller runs, so the two cannot drift apart.
$hasInvoice = function (int $orderId) use ($pdo): string {
    $st = $pdo->prepare('SELECT invoice_no FROM invoices WHERE order_id = ?');
    $st->execute([$orderId]);
    return (string) ($st->fetchColumn() ?: '');
};
$oid3 = $mkOrder('SO-3', 'Test Co');
ok('a plain order is deletable', $hasInvoice($oid3) === '');
$mkInvoice($oid3, 'INV-3', 'Test Co', 3000);
ok('an invoiced order is blocked', $hasInvoice($oid3) === 'INV-3');
ok('the block message names the invoice', str_contains(sprintf(t('msg_order_has_invoice'), 'INV-3'), 'INV-3'));
ok('the block message exists in Indonesian', I18N['id']['msg_order_has_invoice'] !== I18N['zh']['msg_order_has_invoice']);

// 4) Cleaning up properly (what the CLI tool does) leaves nothing behind.
$oid4 = $mkOrder('SO-4', 'fffff');
$iid4 = $mkInvoice($oid4, 'INV-4', 'fffff', 4000);
$pdo->prepare("INSERT INTO delivery_orders (do_no,order_id,customer) VALUES (?,?,?)")->execute(['DO-4', $oid4, 'fffff']);
$pdo->prepare('UPDATE invoices SET do_number = ? WHERE id = ?')->execute(['DO-4', $iid4]);

$pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$iid4]);
$pdo->prepare('DELETE FROM delivery_orders WHERE do_no = ?')->execute(['DO-4']);
$pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid4]);

ok('full cleanup leaves no invoice', (int) $pdo->query("SELECT COUNT(*) FROM invoices WHERE id={$iid4}")->fetchColumn() === 0);
ok('full cleanup leaves no delivery order', (int) $pdo->query("SELECT COUNT(*) FROM delivery_orders WHERE do_no='DO-4'")->fetchColumn() === 0);
ok('full cleanup leaves no order', (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE id={$oid4}")->fetchColumn() === 0);
ok('full cleanup leaves no order items', (int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id={$oid4}")->fetchColumn() === 0);

// 5) Stock restoration reverses exactly what the shipment deducted.
$pdo->prepare('INSERT INTO products (sku,name,stock,reserved,price) VALUES (?,?,?,?,?)')->execute(['A1', 'Panel', 100, 0, 10]);
$pid = (int) $pdo->lastInsertId();
$oid5 = $mkOrder('SO-5', 'fffff');
adjust_stock($pdo, $pid, 'out_auto', 30, 'SO-5', 'test shipment');
ok('shipment deducted stock', (int) $pdo->query("SELECT stock FROM products WHERE id={$pid}")->fetchColumn() === 70);

$tx = $pdo->prepare("SELECT product_id, qty FROM stock_txn WHERE ref = ? AND type = 'out_auto'");
$tx->execute(['SO-5']);
foreach ($tx->fetchAll() as $mv) {
    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([(int) $mv['qty'], (int) $mv['product_id']]);
}
$pdo->prepare("DELETE FROM stock_txn WHERE ref = ? AND type = 'out_auto'")->execute(['SO-5']);
ok('restore puts the stock back exactly', (int) $pdo->query("SELECT stock FROM products WHERE id={$pid}")->fetchColumn() === 100);
ok('restore removes the movement row', (int) $pdo->query("SELECT COUNT(*) FROM stock_txn WHERE ref='SO-5'")->fetchColumn() === 0);

// Manual movements must survive — only the order's own auto-deduction is reversed.
adjust_stock($pdo, $pid, 'out', 5, '手动出库', '');
$pdo->prepare("DELETE FROM stock_txn WHERE ref = ? AND type = 'out_auto'")->execute(['手动出库']);
ok('manual stock movements are untouched', (int) $pdo->query("SELECT COUNT(*) FROM stock_txn WHERE type='out'")->fetchColumn() === 1);

// 6) The money guard: anything with a payment must be reported, not deleted.
$oid6 = $mkOrder('SO-6', 'Real Customer');
$iid6 = $mkInvoice($oid6, 'INV-6', 'Real Customer', 6000);
$pdo->prepare('INSERT INTO payments (invoice_id,customer,amount) VALUES (?,?,?)')->execute([$iid6, 'Real Customer', 6000]);
$pdo->prepare('UPDATE invoices SET amount_paid = 6000 WHERE id = ?')->execute([$iid6]);

$hasMoney = function (int $invoiceId) use ($pdo): bool {
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM payments WHERE invoice_id = iv.id) + (iv.amount_paid > 0)
                         FROM invoices iv WHERE iv.id = ?');
    $st->execute([$invoiceId]);
    return (int) $st->fetchColumn() > 0;
};
ok('an invoice with a payment is flagged', $hasMoney($iid6));
$oid7 = $mkOrder('SO-7', '8888');
$iid7 = $mkInvoice($oid7, 'INV-7', '8888', 700);
ok('an unpaid test invoice is not flagged', !$hasMoney($iid7));

// 7) The seed-customer list must match what Database::seed() actually writes,
//    or --seed silently cleans nothing.
$dbSrc = (string) @file_get_contents(__DIR__ . '/../app/Database.php');
$seedNames = ['PT Maju Bersama', 'CV Dagang Makmur', 'PT Konstruksi Prima', 'CV Anugerah Jaya', 'PT Grup Sejahtera', 'PT Logistik Prima'];
$missing = array_values(array_filter($seedNames, fn($nm) => !str_contains($dbSrc, $nm)));
ok('seed customer names still exist in Database.php', $missing === [], implode(' | ', $missing));

// 8) Stranded stock: an order deleted after shipping leaves its movement behind,
//    and the invoice link is gone too — so restoring has to work off stock_txn.
$pdo->exec("DELETE FROM stock_txn");
$pdo->prepare('UPDATE products SET stock = 100 WHERE id = ?')->execute([$pid]);

// A test shipment in June (order since deleted) and a real one in August.
$pdo->prepare("INSERT INTO stock_txn (product_id,type,qty,ref,txn_date) VALUES (?,'out_auto',?,?,?)")
    ->execute([$pid, 20, '0479/AMI-CO/06/26', '2026-06-15 10:00:00']);
$pdo->prepare("INSERT INTO stock_txn (product_id,type,qty,ref,txn_date) VALUES (?,'out_auto',?,?,?)")
    ->execute([$pid, 7, '0147/AMI-CO/08/26', '2026-08-28 10:00:00']);
// And one belonging to an order that still exists — must never be touched.
$live = $mkOrder('0500/AMI-CO/08/26', 'Real Co');
$pdo->prepare("INSERT INTO stock_txn (product_id,type,qty,ref,txn_date) VALUES (?,'out_auto',?,?,?)")
    ->execute([$pid, 3, '0500/AMI-CO/08/26', '2026-08-29 10:00:00']);

$stranded = function (string $before) use ($pdo): array {
    $st = $pdo->prepare("SELECT st.id, st.ref, st.qty, st.product_id FROM stock_txn st
                         WHERE st.type='out_auto' AND st.txn_date < ?
                           AND st.ref NOT IN (SELECT order_no FROM orders WHERE order_no IS NOT NULL)
                         ORDER BY st.id");
    $st->execute([$before]);
    return $st->fetchAll();
};

$all = $stranded('2099-01-01');
ok('live orders are never stranded', !in_array('0500/AMI-CO/08/26', array_column($all, 'ref'), true));
ok('both deleted-order movements are stranded', count($all) === 2, implode(',', array_column($all, 'ref')));

// The date cutoff is what separates a test shipment from a real one.
$june = $stranded('2026-07-01');
ok('--before keeps the real August shipment out', count($june) === 1 && $june[0]['ref'] === '0479/AMI-CO/06/26');

foreach ($june as $m) {
    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([(int) $m['qty'], (int) $m['product_id']]);
    $pdo->prepare('DELETE FROM stock_txn WHERE id = ?')->execute([(int) $m['id']]);
}
ok('only the test deduction is restored', (int) $pdo->query("SELECT stock FROM products WHERE id={$pid}")->fetchColumn() === 120);
ok('the real August movement survives', (int) $pdo->query("SELECT COUNT(*) FROM stock_txn WHERE ref='0147/AMI-CO/08/26'")->fetchColumn() === 1);
ok('the live order movement survives', (int) $pdo->query("SELECT COUNT(*) FROM stock_txn WHERE ref='0500/AMI-CO/08/26'")->fetchColumn() === 1);
ok('restoring twice finds nothing left', $stranded('2026-07-01') === []);
