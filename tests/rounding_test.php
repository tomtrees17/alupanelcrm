<?php

// ── Invoice rounding (Pembulatan) ──
// The contract: Subtotal, DPP and VAT are computed exactly as before (they match
// the company's real template); only the total changes, to equal what the
// customer was quoted, with the gap shown as Pembulatan.

require_once __DIR__ . '/../app/Csrf.php';

/** The pre-fix computation, kept verbatim as the reference. */
$legacy = function (array $items, float $ship): array {
    $div = 1.11;
    $itemsSum = 0.0;
    foreach ($items as $it) {
        $pretaxUnit = round((float) $it['price'] / $div, 2);
        $itemsSum += round((float) $it['qty'] * $pretaxUnit);
    }
    $subtotal = $itemsSum + round(round($ship / $div, 2));
    $ppn = round($subtotal * 11 / 100);
    return ['subtotal' => $subtotal, 'ppn' => $ppn, 'total' => $subtotal + $ppn];
};

// 1) The verified template example still holds: 235.000 inclusive → 211.711,71.
$a = invoice_amounts([['qty' => 3, 'price' => 235000]], 0, 11);
ok('unit price matches the company template', $a['units'][0] === 211711.71, (string) $a['units'][0]);

// 2) Randomised sweep against the legacy formula.
mt_srand(20261012);
$sameSubVat = true; $exact = true; $vatRule = true; $small = true; $sums = true; $nonZero = 0; $n = 3000;
for ($i = 0; $i < $n; $i++) {
    $items = [];
    for ($k = 0, $lines = mt_rand(1, 6); $k < $lines; $k++) {
        // Real prices: whole rupiah, mostly round thousands, qty 1–300.
        $price = mt_rand(0, 3) === 0 ? mt_rand(50000, 900000) : mt_rand(50, 900) * 1000;
        $items[] = ['qty' => mt_rand(1, 300), 'price' => $price];
    }
    $ship = mt_rand(0, 2) === 0 ? mt_rand(0, 50) * 10000 : 0;
    $new = invoice_amounts($items, (float) $ship, 11);
    $old = $legacy($items, (float) $ship);
    $quoted = round(array_sum(array_map(fn($it) => $it['qty'] * $it['price'], $items)) + $ship);

    $sameSubVat = $sameSubVat && $new['subtotal'] === $old['subtotal'] && $new['ppn'] === $old['ppn'];
    $exact      = $exact && $new['total'] === $quoted;
    $vatRule    = $vatRule && $new['ppn'] === round($new['subtotal'] * 0.11);
    $sums       = $sums && $new['subtotal'] + $new['ppn'] + $new['rounding'] === $new['total'];
    $small      = $small && abs($new['rounding']) <= 5;
    $nonZero   += $new['rounding'] != 0 ? 1 : 0;
}
ok("Subtotal and VAT identical to the legacy formula ({$n} orders)", $sameSubVat);
ok('total always equals the quoted inclusive amount', $exact);
ok('VAT is always Subtotal × 11% (DPP × 12%)', $vatRule);
ok('Subtotal + VAT + Pembulatan = total', $sums);
ok('Pembulatan stays a rounding-sized amount', $small);
ok('the sweep actually exercises non-zero rounding', $nonZero > $n / 10, (string) $nonZero);

// A known case: the legacy total missed by one rupiah; now it lands exactly.
$found = null;
for ($p = 100000; $p < 400000 && $found === null; $p += 1000) {
    $x = invoice_amounts([['qty' => 7, 'price' => $p]], 0, 11);
    if ($x['rounding'] != 0) {
        $found = [$p, $x, $legacy([['qty' => 7, 'price' => $p]], 0)];
    }
}
ok('a mismatching order exists to test', $found !== null);
[$fp, $fx, $fl] = $found;
ok('legacy total was off from the quote', $fl['total'] !== (float) (7 * $fp));
ok('new total equals the quote', $fx['total'] === (float) (7 * $fp));

ok('rp_signed formats negatives', rp_signed(-1) === '-Rp1' && rp_signed(2) === 'Rp2' && rp_signed(-1250) === '-Rp1.250');

// 3) The correction plan for existing invoices.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

/** An invoice exactly as the old code wrote it (rounding 0, total = Subtotal + VAT). */
$legacyInvoice = function (array $items, float $ship, float $paid = 0) use ($pdo, $legacy): int {
    $pdo->prepare("INSERT INTO orders (order_no,customer_name,status,shipping_cost) VALUES (?,?,'approved',?)")
        ->execute(['SO-' . uniqid('', true), 'C', $ship]);
    $oid = (int) $pdo->lastInsertId();
    foreach ($items as $it) {
        $pdo->prepare('INSERT INTO order_items (order_id,sku,qty,price) VALUES (?,?,?,?)')->execute([$oid, 'A', $it['qty'], $it['price']]);
    }
    $l = $legacy($items, $ship);
    $pdo->prepare("INSERT INTO invoices (invoice_no,order_id,customer,subtotal,ppn,total,amount_paid,due_date,payment_status) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute(['INV-' . uniqid('', true), $oid, 'C', $l['subtotal'], $l['ppn'], $l['total'], $paid, '2026-01-01', 'overdue']);
    return (int) $pdo->lastInsertId();
};
$inv = fn(int $id): array => $pdo->query("SELECT * FROM invoices WHERE id={$id}")->fetch();

$quote = 7 * $fp;
$bad = $legacyInvoice([['qty' => 7, 'price' => $fp]], 0, $quote);     // customer paid the quote exactly
refresh_invoice_status($pdo, $bad, '2026-10-12');
ok('before: a fully-paid customer shows a phantom debt', $inv($bad)['payment_status'] !== 'paid');

$plan = invoice_rounding_plan($pdo, $inv($bad), 11);
ok('plan: fix', $plan['action'] === 'fix');
ok('plan: total becomes the quote', $plan['total'] === (float) $quote);
ok('plan: Pembulatan = quote − (Subtotal + VAT)', $plan['rounding'] === (float) $quote - (float) $inv($bad)['total']);

// Apply exactly as the tool does.
$pdo->prepare('UPDATE invoices SET rounding = ?, total = ? WHERE id = ?')->execute([$plan['rounding'], $plan['total'], $bad]);
refresh_invoice_status($pdo, $bad, '2026-10-12');
$after = $inv($bad);
ok('after: the invoice reads as paid', $after['payment_status'] === 'paid', $after['payment_status']);
ok('after: Subtotal untouched', (float) $after['subtotal'] === $fl['subtotal']);
ok('after: VAT untouched', (float) $after['ppn'] === $fl['ppn']);
ok('after: running again changes nothing', invoice_rounding_plan($pdo, $after, 11)['action'] === 'ok');

$good = $legacyInvoice([['qty' => 3, 'price' => 235000]], 0);
$gp = invoice_rounding_plan($pdo, $inv($good), 11);
ok('an invoice that was already right is left alone', $gp['action'] === 'ok' || $gp['rounding'] == 0.0);

// Anything that is not a pure rounding gap is refused.
$edited = $legacyInvoice([['qty' => 2, 'price' => 500000]], 0);
$pdo->prepare('UPDATE invoices SET subtotal = subtotal + 1000 WHERE id = ?')->execute([$edited]);
ok('edited Subtotal is skipped', invoice_rounding_plan($pdo, $inv($edited), 11)['action'] === 'skip');

$pdo->prepare("INSERT INTO invoices (invoice_no,order_id,customer,subtotal,ppn,total) VALUES ('ORPHAN',NULL,'C',100,11,111)")->execute();
ok('orphan invoice is skipped', invoice_rounding_plan($pdo, $inv((int) $pdo->lastInsertId()), 11)['action'] === 'skip');

$changed = $legacyInvoice([['qty' => 1, 'price' => 1000000]], 0);
$pdo->exec("UPDATE order_items SET price = 2000000 WHERE order_id = (SELECT order_id FROM invoices WHERE id = {$changed})");
ok('an order changed after invoicing is skipped', invoice_rounding_plan($pdo, $inv($changed), 11)['action'] === 'skip');

// ── 4) Every rendering shows the line when, and only when, it is non-zero ──
$GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
$realConfig = @include __DIR__ . '/../config.php';
if (is_array($realConfig)) {
    $GLOBALS['config'] = array_merge($GLOBALS['config'], $realConfig);
}
$render = function (string $file, array $vars): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    $cfg = $config;
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
$cols = array_fill_keys(array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name'), '');
$base = array_merge($cols, ['id' => 5, 'invoice_no' => 'INV-5', 'order_id' => 1, 'customer' => 'PT Maju',
    'subtotal' => 8049550, 'ppn' => 885451, 'rounding' => -1, 'total' => 8935000, 'amount_paid' => 8935000,
    'shipping_cost' => 0, 'invoice_date' => '2026-08-05', 'due_date' => '2026-08-05', 'payment_status' => 'paid', 'voided_at' => null]);
$zero = array_merge($base, ['rounding' => 0, 'total' => 8935001]);
$items = [['sku' => 'SJ', 'color' => 'W', 'spec' => '3.0', 'size' => '1.22', 'qty' => 1, 'unit' => 'Unit', 'price' => 8049550]];

$printFile = __DIR__ . '/../views/print/invoice.php';
$p1 = $render($printFile, ['invoice' => $base, 'items' => $items, 'orderNo' => 'SO-1']);
ok('print shows Pembulatan when non-zero', str_contains($p1, 'Pembulatan') && str_contains($p1, '-Rp1'));
ok('print total is the quoted amount', str_contains($p1, 'Rp8.935.000'));
ok('print has no Pembulatan when zero', !str_contains($render($printFile, ['invoice' => $zero, 'items' => $items, 'orderNo' => 'SO-1']), 'Pembulatan'));
ok('print stays free of Chinese', preg_match('/[\x{4e00}-\x{9fff}]/u', strip_tags(preg_replace('/<(script|style|div class="toolbar").*?<\/(script|style|div)>/su', '', $p1))) === 0);

$w1 = $render(__DIR__ . '/../views/word/invoice.php', ['invoice' => $base, 'items' => $items, 'orderNo' => 'SO-1']);
ok('Word export shows Pembulatan', str_contains($w1, 'Pembulatan') && str_contains($w1, '-Rp1'));

$showFile = __DIR__ . '/../views/finance/show.php';
$s1 = $render($showFile, ['invoice' => $base, 'items' => $items, 'payments' => [], 'voidBlock' => null]);
ok('invoice screen shows the rounding row', str_contains($s1, t('inv_rounding')) && str_contains($s1, '-Rp 1'));
ok('invoice screen hides it when zero', !str_contains($render($showFile, ['invoice' => $zero, 'items' => $items, 'payments' => [], 'voidBlock' => null]), t('inv_rounding')));
ok('rounding label translated', I18N['id']['inv_rounding'] === 'Pembulatan' && isset(I18N['zh']['inv_rounding']));
