<?php

// ── Invoices calculated like Coretax (2 decimals, PPN = DPP Nilai Lain 11/12 × 12%) ──
// So the commercial invoice matches the tax invoice to the sen (docs 6w).

require_once __DIR__ . '/../app/Csrf.php';

// 1) The worked example agreed with the user: invoice 11752, 10 sheets at Rp150.000 inclusive.
$items = [['qty' => 5, 'price' => 150000], ['qty' => 1, 'price' => 150000], ['qty' => 4, 'price' => 150000]];
$a = invoice_amounts_coretax($items, 0, 'truncate');
ok('Harga Satuan 150.000 ÷ 1,11 truncated → 135.135,13', $a['units'][0] === 135135.13, (string) $a['units'][0]);
ok('Subtotal = Σ qty × Harga Satuan exactly → 1.351.351,30', $a['subtotal'] === 1351351.30, (string) $a['subtotal']);
ok('DPP Nilai Lain = 11/12, truncated → 1.238.738,69', $a['dpp'] === 1238738.69, (string) $a['dpp']);
ok('PPN = 12% of DPP, truncated → 148.648,64', $a['ppn'] === 148648.64, (string) $a['ppn']);
ok('Total Invoice (= tax invoice) → 1.499.999,94', $a['invoice_total'] === 1499999.94, (string) $a['invoice_total']);
ok('Total Amount = what the customer was quoted → 1.500.000', $a['total'] === 1500000.0);
ok('the gap is the difference, never folded into PPN → 0,06', $a['rounding'] === 0.06, (string) $a['rounding']);

$r = invoice_amounts_coretax($items, 0, 'round');
ok("'round' mode: 135.135,14 / 1.238.738,78 / 148.648,65", $r['units'][0] === 135135.14 && $r['dpp'] === 1238738.78 && $r['ppn'] === 148648.65, json_encode([$r['units'][0], $r['dpp'], $r['ppn']]));

$s = invoice_amounts_coretax([['qty' => 2, 'price' => 222000]], 55500, 'truncate');
ok('shipping ÷ 1,11 joins the subtotal (55.500 → 50.000,00)', $s['pretax_ship'] === 50000.0 && $s['subtotal'] === 400000.0 + 50000.0, json_encode($s));
ok('round prices come out exact (222.000 → 200.000,00; no gap)', $s['units'][0] === 200000.0 && $s['rounding'] === 0.0, (string) $s['rounding']);
$d = invoice_amounts_coretax([['qty' => 3, 'price' => 135135.14]], 0, 'truncate');
ok('decimal inclusive prices work (now enterable on the order form)', $d['units'][0] === 121743.36, (string) $d['units'][0]);

// 2) Random sweep: every step is exactly "keep 2 decimals" of the exact value.
mt_srand(20261021);
$okUnit = $okSub = $okDpp = $okPpn = $okSum = $okGap = $okRound = true;
for ($n = 0; $n < 3000; $n++) {
    $lines = [];
    $qtyTotal = 0;
    for ($k = 0, $m = mt_rand(1, 6); $k < $m; $k++) {
        $price = mt_rand(0, 4) === 0 ? mt_rand(5000000, 90000000) / 100 : mt_rand(50, 900) * 1000;
        $q = mt_rand(1, 300);
        $qtyTotal += $q;
        $lines[] = ['qty' => $q, 'price' => $price];
    }
    $ship = mt_rand(0, 2) === 0 ? mt_rand(0, 50) * 10000 : 0;
    $c = invoice_amounts_coretax($lines, (float) $ship, 'truncate');
    $subSen = 0;
    foreach ($lines as $k => $l) {
        $u = (int) round($c['units'][$k] * 100);
        $p = (int) round($l['price'] * 100);
        // truncation: u·111 ≤ p·100 < (u+1)·111
        $okUnit = $okUnit && $u * 111 <= $p * 100 && $p * 100 < ($u + 1) * 111;
        $subSen += $l['qty'] * $u;
    }
    $subSen += (int) round($c['pretax_ship'] * 100);
    $sub = (int) round($c['subtotal'] * 100);
    $dpp = (int) round($c['dpp'] * 100);
    $ppn = (int) round($c['ppn'] * 100);
    $okSub = $okSub && $sub === $subSen;
    $okDpp = $okDpp && $dpp * 12 <= $sub * 11 && $sub * 11 < ($dpp + 1) * 12;
    $okPpn = $okPpn && $ppn * 100 <= $dpp * 12 && $dpp * 12 < ($ppn + 1) * 100;
    $okSum = $okSum && abs($c['invoice_total'] + $c['rounding'] - $c['total']) < 0.001;
    // Truncation only ever lowers the tax-invoice total, by at most ~1,1 sen per sheet.
    $okGap = $okGap && $c['rounding'] >= -0.005 && $c['rounding'] <= 0.0112 * $qtyTotal + 0.03;
    $cr = invoice_amounts_coretax($lines, (float) $ship, 'round');
    foreach ($lines as $k => $l) {
        $okRound = $okRound && abs($cr['units'][$k] - round($l['price'] / 1.11, 2)) < 0.0051;
    }
}
ok('3000 orders: Harga Satuan is the 2-decimal truncation of price ÷ 1,11', $okUnit);
ok('3000 orders: Subtotal is exactly Σ qty × Harga Satuan (+ shipping)', $okSub);
ok('3000 orders: DPP Nilai Lain is the truncation of Subtotal × 11/12', $okDpp);
ok('3000 orders: PPN is the truncation of DPP × 12%', $okPpn);
ok('3000 orders: Total Invoice + gap = quoted total', $okSum);
ok('3000 orders: the gap stays a few sen per sheet, never negative', $okGap);
ok("3000 orders: 'round' mode rounds instead", $okRound);

// 3) Display: Total Invoice (= Coretax) vs Total Amount (= payable), 2 decimals, PPN untouched.
$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
$GLOBALS['config'] = array_merge($GLOBALS['config'], (array) (@include __DIR__ . '/../config.php'));
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$mem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mem->exec($schemaSql);
$cols = array_fill_keys(array_column($mem->query('PRAGMA table_info(invoices)')->fetchAll(), 'name'), '');
$inv = array_merge($cols, ['id' => 7, 'invoice_no' => 'INV-C', 'order_id' => 1, 'customer' => 'Ibu Dwi', 'bill_to_name' => 'Ibu Dwi',
    'subtotal' => $a['subtotal'], 'ppn' => $a['ppn'], 'dpp' => $a['dpp'], 'rounding' => $a['rounding'], 'total' => $a['total'],
    'calc' => 'coretax', 'shipping_cost' => 0, 'amount_paid' => 0, 'invoice_date' => '2026-10-21', 'due_date' => '2026-10-21',
    'payment_status' => 'pending', 'voided_at' => null, 'currency' => 'IDR']);
$invItems = [['sku' => 'SJ8033', 'color' => 'Light Coffee', 'spec' => '3.0*0.06', 'size' => '1.220 x 2.440', 'qty' => 5, 'unit' => 'Unit', 'price' => 135135.13]];
$disp = invoice_display($inv, $GLOBALS['config']);
ok('display: PPN shown exactly as Coretax (never adjusted)', $disp['vat'] === 148648.64 && $disp['rounding_line'] === null && $disp['decimals'] === 2);
ok('display: Total Invoice = Subtotal + PPN', $disp['invoice_total'] === 1499999.94);
ok("display: the old 'vat' folding setting does not apply to Coretax invoices", ($GLOBALS['config']['invoice_rounding'] ?? 'vat') === 'vat' && $disp['vat'] === (float) $inv['ppn']);

$render = function (string $file, array $vars): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($vars, EXTR_SKIP);
    ob_start();
    include $file;
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};
$p = $render(__DIR__ . '/../views/print/invoice.php', ['invoice' => $inv, 'items' => $invItems, 'orderNo' => 'SO-1']);
foreach (['Rp1.351.351,30', 'Rp1.238.738,69', 'Rp148.648,64', 'Rp1.499.999,94', 'Rp1.500.000,00', '675.675,65', '135.135,13'] as $needle) {
    if (!str_contains($p, $needle)) {
        ok("print shows {$needle}", false);
    }
}
ok('print: every figure with 2 decimals, Total Invoice ≠ Total Amount by the gap', str_contains($p, 'Total Invoice') && str_contains($p, 'Rp1.499.999,94') && str_contains($p, 'Rp1.500.000,00'));
ok('print: no Pembulatan row', !str_contains($p, 'Pembulatan'));
ok('print: amount in words is the payable total', str_contains($p, 'Satu Juta Lima Ratus Ribu') || !str_contains($p, 'Say'));

$doc = new Docx();
$body = '';
(function () use ($inv, $invItems, &$body, $doc) {
    $invoice = $inv;
    $items = $invItems;
    $orderNo = 'SO-1';
    $cfg = $GLOBALS['config'];
    include __DIR__ . '/../views/word/invoice_docx.php';
})();
ok('Word: same Coretax figures', str_contains($body, 'Rp148.648,64') && str_contains($body, 'Rp1.499.999,94') && str_contains($body, 'Rp1.500.000,00') && !str_contains($body, 'Pembulatan'));
$sc = $render(__DIR__ . '/../views/finance/show.php', ['invoice' => $inv, 'items' => $invItems, 'payments' => [], 'voidBlock' => null]);
ok('screen: DPP Nilai Lain, PPN and Total Invoice (= Coretax) with 2 decimals', str_contains($sc, 'DPP Nilai Lain') && str_contains($sc, 'Rp 148.648,64') && str_contains($sc, 'Rp 1.499.999,94'));

// CO for a pre-tax order: the PPN rows say how they are computed, so 12% is not read as 12% of Sub Total.
$ocols = array_fill_keys(array_column($mem->query('PRAGMA table_info(orders)')->fetchAll(), 'name'), '');
$icols = array_fill_keys(array_column($mem->query('PRAGMA table_info(order_items)')->fetchAll(), 'name'), '');
$coOrder = array_merge($ocols, ['id' => 9, 'order_no' => 'SO-9', 'customer_name' => 'Ibu Dewi', 'company' => 'DAPUR GEMILANG', 'shipping_cost' => 0,
    'price_basis' => 'excl', 'status' => 'approved', 'created_at' => '2026-10-28 10:00:00', 'submitter' => 'Admin']);
$coItems = [array_merge($icols, ['sku' => 'SJ2020-07', 'spec' => '4.0*0.15', 'size' => '1220X2440', 'qty' => 23, 'unit' => 'LBR', 'price' => 463500.48, 'price_excl' => 417568]),
            array_merge($icols, ['sku' => 'SJ8033', 'spec' => '4.0*0.21', 'size' => '1220X2440', 'qty' => 10, 'unit' => 'LBR', 'price' => 432394.95, 'price_excl' => 389545])];
$co = $render(__DIR__ . '/../views/print/co.php', ['order' => $coOrder, 'items' => $coItems, 'sales' => []]);
ok('CO pre-tax: Sub Total is the DPP, then DPP Nilai Lain and PPN 12%, with the 11/12 and effective 11% note (Coretax wording)',
    str_contains($co, 'SUB TOTAL (DPP)') && str_contains($co, '>DPP Nilai Lain<') && str_contains($co, '>PPN 12%<')
    && str_contains($co, 'DPP Nilai Lain = 11/12 × DPP') && str_contains($co, 'PPN efektif 11%'));
$coShip = $render(__DIR__ . '/../views/print/co.php', ['order' => array_merge($coOrder, ['shipping_cost' => 500000]), 'items' => $coItems, 'sales' => []]);
ok('CO pre-tax with ongkir: Sub Total is not labelled DPP (the tax base also holds shipping ÷ 1,11)',
    !str_contains($coShip, 'SUB TOTAL (DPP)') && str_contains($coShip, '>SUB TOTAL<') && str_contains($coShip, '>DPP Nilai Lain<'));
ok('CO pre-tax: figures unchanged (DPP 12.374.554,50 · PPN 1.484.946,54 · total 14.984.460,54)',
    str_contains($co, '12.374.554,5') && str_contains($co, '1.484.946,54') && str_contains($co, '14.984.460,54'));
$coIncl = $render(__DIR__ . '/../views/print/co.php', ['order' => array_merge($coOrder, ['price_basis' => 'incl']), 'items' => $coItems, 'sales' => []]);
ok('CO tax-inclusive: no PPN rows, no effective-rate note', !str_contains($coIncl, 'PPN efektif') && !str_contains($coIncl, 'SUB TOTAL (DPP)') && str_contains($coIncl, 'Harga sudah termasuk PPN'));

// 4) Legacy invoices keep their old presentation.
$legacy = array_merge($inv, ['calc' => 'legacy', 'subtotal' => 1351352, 'ppn' => 148649, 'dpp' => null, 'rounding' => -1]);
$dl = invoice_display($legacy, $GLOBALS['config']);
ok('legacy invoice: whole rupiah, rounding folded into VAT as before', $dl['decimals'] === 0 && $dl['vat'] === 148648.0 && $dl['invoice_total'] === 1500000.0);
ok('the old rounding fixer leaves Coretax invoices alone', invoice_rounding_plan($mem, $inv, 11)['action'] === 'skip');

// 5) End to end: the warehouse confirms shipment → a Coretax invoice is issued.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'coretax');
    $f = new PDO('sqlite:' . $db);
    $f->exec($schemaSql);
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'a@x', 'x', 'admin')");
    $f->exec("INSERT INTO products (id, sku, name, spec, price, stock) VALUES (1, 'SJ8033', 'ACP', '3.0*0.06', 150000, 100)");
    $f->exec("INSERT INTO orders (id, order_no, customer_name, company, submitter, status, payment_term, shipping_cost) VALUES (1, 'SO-T1', 'Ibu Dwi', '', 'rina', 'pending_wh', 'CBD', 0)");
    $f->exec("INSERT INTO order_items (order_id, product_id, sku, spec, color, size, qty, unit, price) VALUES
        (1, 1, 'SJ8033', '3.0*0.06', 'Light Coffee', '1.220 x 2.440', 5, 'Unit', 150000), (1, 1, 'SJ8033', '3.0*0.06', 'Black', '1.220 x 2.440', 5, 'Unit', 150000)");
    $f = null;
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $harness, $db, 'orders', 'approve', '{}', json_encode(['id' => 1, 'real' => true]), json_encode(['id' => 1])])) . ' 2>&1', $out);
    $q = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $iv = $q->query('SELECT * FROM invoices WHERE order_id = 1')->fetch();
    ok('shipment confirmed → invoice issued with calc = coretax', $iv && $iv['calc'] === 'coretax', json_encode($iv));
    ok('…with the Coretax figures stored', $iv && (float) $iv['subtotal'] === 1351351.30 && (float) $iv['dpp'] === 1238738.69 && (float) $iv['ppn'] === 148648.64 && (float) $iv['total'] === 1500000.0);
    $units = $q->query('SELECT DISTINCT price FROM invoice_items')->fetchAll(PDO::FETCH_COLUMN);
    ok('…and Harga Satuan 135.135,13 on the lines', $units === [135135.13] || $units === ['135135.13'], json_encode($units));
    ok('payment status works on the payable total', $iv && $iv['payment_status'] === 'pending');
    @unlink($db);
}
