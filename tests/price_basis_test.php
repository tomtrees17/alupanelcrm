<?php

// ── Orders priced 含税 or 不含税 (a customer quoted before tax) ──

require_once __DIR__ . '/../app/Csrf.php';

// 1) The calculation: a pre-tax quote is printed as typed and the customer pays Subtotal + PPN.
$q = invoice_amounts_coretax([['qty' => 7, 'price' => 134569.74, 'price_excl' => 121234]], 0, 'truncate', true);
ok('pre-tax quote: Harga Satuan is exactly the typed 121.234,00', $q['units'][0] === 121234.0, (string) $q['units'][0]);
ok('pre-tax quote: Subtotal = 7 × 121.234 = 848.638,00', $q['subtotal'] === 848638.0);
ok('pre-tax quote: customer pays Subtotal + PPN, no gap', $q['total'] === $q['invoice_total'] && $q['rounding'] === 0.0, json_encode($q));
$noFlag = invoice_amounts_coretax([['qty' => 7, 'price' => 134569.74, 'price_excl' => 121234]], 0, 'truncate', false);
ok('without the flag the inclusive price rules (price_excl ignored)', $noFlag['units'][0] === 121234.0 && $noFlag['total'] === round(7 * 134569.74, 2));

mt_srand(20261022);
$exact = $noGap = true;
for ($n = 0; $n < 2000; $n++) {
    $lines = [];
    for ($k = 0, $m = mt_rand(1, 5); $k < $m; $k++) {
        $excl = mt_rand(0, 3) === 0 ? mt_rand(4500000, 81000000) / 100 : mt_rand(45, 810) * 1000;
        $lines[] = ['qty' => mt_rand(1, 300), 'price' => round($excl * 1.11, 2), 'price_excl' => $excl];
    }
    $c = invoice_amounts_coretax($lines, (float) (mt_rand(0, 2) ? 0 : mt_rand(1, 50) * 10000), 'truncate', true);
    foreach ($lines as $k => $l) {
        $exact = $exact && abs($c['units'][$k] - $l['price_excl']) < 0.001;
    }
    $noGap = $noGap && $c['rounding'] === 0.0 && abs($c['total'] - $c['invoice_total']) < 0.001;
}
ok('2000 pre-tax orders: every Harga Satuan equals the typed pre-tax price', $exact);
ok('2000 pre-tax orders: payable total = Subtotal + PPN', $noGap);

// 2) Through the real controllers: save a pre-tax order, ship it, look at the invoice.
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'pbasis');
    $f = new PDO('sqlite:' . $db);
    $f->exec($schemaSql);
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@x', 'x', 'admin')");
    $f->exec("INSERT INTO products (id, sku, name, spec, price, stock) VALUES (1, 'SJ8001', 'ACP', '4.0*0.21', 235000, 500)");
    $f = null;
    $admin = json_encode(['id' => 1, 'real' => true]);
    $run = function (string $ctl, string $action, array $get, ?array $post) use ($db, $harness, $admin): string {
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode($get), $admin, $post === null ? '' : json_encode($post)];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $qr = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $line = ['product_id' => ['1'], 'sku' => ['SJ8001'], 'color' => ['White'], 'spec' => ['4.0*0.21'], 'size' => ['1.22x2.44'], 'qty' => ['7']];

    $form = $run('orders', 'create', [], null);
    ok('order form: 含税 / 不含税 switch and both price columns', str_contains($form, 'name="price_basis" value="incl" checked') && str_contains($form, 'name="price_basis" value="excl"')
        && str_contains($form, 'name="price[]"') && str_contains($form, 'name="price_excl[]"') && str_contains($form, t('th_price_excl')));

    // Pre-tax order. The inclusive field carries junk on purpose: on a pre-tax order the server must ignore it.
    $run('orders', 'store', [], $line + ['customer_name' => 'PT Netto', 'do' => 'draft', 'price_basis' => 'excl', 'price' => ['999999'], 'price_excl' => ['121234'], 'shipping_cost' => '0']);
    $o = $qr("SELECT * FROM orders WHERE customer_name = 'PT Netto'")[0] ?? null;
    $it = $o ? $qr("SELECT * FROM order_items WHERE order_id = {$o['id']}")[0] : null;
    ok('saved as a pre-tax order', $o && $o['price_basis'] === 'excl');
    ok('typed pre-tax price stored; inclusive derived from it (× 1,11), not from the form', $it && (float) $it['price_excl'] === 121234.0 && (float) $it['price'] === 134569.74, json_encode($it));

    $edit = $run('orders', 'edit', ['id' => $o['id']], null);
    ok('editing keeps the 不含税 basis and the typed price', str_contains($edit, 'name="price_basis" value="excl" checked') && str_contains($edit, '"price_excl":121234'));
    $show = $run('orders', 'show', ['id' => $o['id']], null);
    ok('order page marks the pre-tax quote and shows both prices', str_contains($show, t('order_quoted_excl')) && str_contains($show, 'Rp 121.234,00') && str_contains($show, 'Rp 134.569,74'));

    (new PDO('sqlite:' . $db))->exec("UPDATE orders SET status = 'pending_wh' WHERE id = {$o['id']}");
    $run('orders', 'approve', [], ['id' => $o['id']]);
    $iv = $qr("SELECT * FROM invoices WHERE order_id = {$o['id']}")[0] ?? null;
    $ii = $iv ? $qr("SELECT price FROM invoice_items WHERE invoice_id = {$iv['id']}")[0] : null;
    ok('shipped → invoice Harga Satuan is exactly the typed 121.234,00', $ii && (float) $ii['price'] === 121234.0, json_encode($ii));
    ok('…and the customer is billed Subtotal + PPN (no Pembulatan)', $iv && abs((float) $iv['total'] - ((float) $iv['subtotal'] + (float) $iv['ppn'])) < 0.001 && (float) $iv['rounding'] === 0.0, json_encode($iv));

    // An ordinary (inclusive) order through the same path is unchanged.
    $run('orders', 'store', [], $line + ['customer_name' => 'PT Bruto', 'do' => 'draft', 'price_basis' => 'incl', 'price' => ['150000'], 'price_excl' => ['0'], 'shipping_cost' => '0']);
    $o2 = $qr("SELECT * FROM orders WHERE customer_name = 'PT Bruto'")[0] ?? null;
    $it2 = $o2 ? $qr("SELECT * FROM order_items WHERE order_id = {$o2['id']}")[0] : null;
    ok('inclusive order: basis incl, price as typed, no pre-tax stored', $o2 && $o2['price_basis'] === 'incl' && (float) $it2['price'] === 150000.0 && $it2['price_excl'] === null);
    (new PDO('sqlite:' . $db))->exec("UPDATE orders SET status = 'pending_wh' WHERE id = {$o2['id']}");
    $run('orders', 'approve', [], ['id' => $o2['id']]);
    $iv2 = $qr("SELECT * FROM invoices WHERE order_id = {$o2['id']}")[0] ?? null;
    ok('inclusive order invoice: unit 135.135,13, customer pays the quoted 1.050.000', $iv2 && (float) $iv2['total'] === 1050000.0
        && (float) $qr("SELECT price FROM invoice_items WHERE invoice_id = {$iv2['id']}")[0]['price'] === 135135.13);
    @unlink($db);
}

foreach (['th_price_incl', 'th_price_excl', 'price_basis', 'price_basis_incl', 'price_basis_excl', 'price_basis_incl_hint', 'price_basis_excl_hint', 'order_quoted_excl'] as $k) {
    if (!isset(I18N['zh'][$k], I18N['id'][$k])) {
        ok("{$k} translated", false);
    }
}
ok('labels exist in Chinese and Indonesian', true);
