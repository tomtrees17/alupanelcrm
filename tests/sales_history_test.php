<?php

// ── Historical sales: parsing the old app's export, matching, import, yearly totals ──

require_once __DIR__ . '/../app/Csrf.php';

// ── 1) Product name parsing ──────────────────────────────────────────────────
$p = SalesHistory::parse_product('SJ8037黑金沙（Galaxy Black）4.0*0.21');
ok('sku / colour / spec split', $p['sku'] === 'SJ8037' && $p['spec'] === '4.0*0.21' && $p['color'] === '黑金沙 (Galaxy Black)', json_encode($p));
$p = SalesHistory::parse_product('SJ2020-09抗刮摩卡集木（Anti Scrath Mocha Wood）4.0*0.15抗刮木纹');
ok('hyphenated sku keeps the finish suffix in the spec', $p['sku'] === 'SJ2020-09' && $p['spec'] === '4.0*0.15抗刮木纹', json_encode($p));
$p = SalesHistory::parse_product('SJ8825 高光白（GLOSSY WHITE）3.0*0.06背涂 (ECO)');
ok('(ECO) tag dropped from the spec', $p['sku'] === 'SJ8825' && $p['spec'] === '3.0*0.06背涂', json_encode($p));
$p = SalesHistory::parse_product('825高光白（GLOSSY WHITE）3.0*0.06背涂 (ECO)');
ok('missing SJ prefix repaired', $p['sku'] === 'SJ8825', json_encode($p));
$p = SalesHistory::parse_product('SJ7705樱桃木（Cherry Wood）3.06印花');
ok('short spec form "3.06印花"', $p['sku'] === 'SJ7705' && $p['spec'] === '3.06印花', json_encode($p));
$p = SalesHistory::parse_product('SJ8099不锈钢拉丝（Stainless Steel Brush）3.10拉丝');
ok('short spec form "3.10拉丝"', $p['spec'] === '3.10拉丝', json_encode($p));
$p = SalesHistory::parse_product('SJ7712白大理石 (White Marble)3.0*0.06背涂', '白大理石 （WHITE MARBLE )');
ok('ASCII brackets with a space', $p['sku'] === 'SJ7712' && $p['spec'] === '3.0*0.06背涂' && $p['color'] === '白大理石 (White Marble)', json_encode($p));
$p = SalesHistory::parse_product('Jasa potong', '');
ok('no sku → null, name kept as colour', $p['sku'] === null && $p['color'] === 'Jasa potong');

ok('date 2026-08-31', SalesHistory::normalize_date('2026-08-31') === '2026-08-31');
ok('date 31/08/2026', SalesHistory::normalize_date('31/08/2026') === '2026-08-31');
ok('date 2026/8/3', SalesHistory::normalize_date('2026/8/3') === '2026-08-03');
ok('bad date is null', SalesHistory::normalize_date('yesterday') === null);

ok('name normalisation ignores legal form and spacing', SalesHistory::normalize_name('PT  Unggul Jaya Timber') === SalesHistory::normalize_name('Unggul Jaya Timber'));
ok('walk-in detected', SalesHistory::is_walk_in('零售散客') && !SalesHistory::is_walk_in('Cikeas Aluminium'));

// ── 2) Parsing the flat export into documents ───────────────────────────────
$h = ['序号', '单据日期', '发货状态', '开票状态', '单据编号', '门店', '客户名称', '商品名称', '条形码', '规格', '编号/货号', '单位', '数量', '原价', '单行折扣(%)', '折后价', '金额', '税率(%)', '税额', '含税金额', '备注', '整单折扣率(%)', '折后金额', '税额合计', '运费', '抹零', '折前金额', '本单应收', '本单实收', '收款状态', '结算账户', '单据备注', '业务员', '联系人', '联系电话', '配送地址'];
$row = function (array $v) use ($h): array {
    $r = array_fill(0, count($h), null);
    foreach ($v as $k => $x) {
        $r[array_search($k, $h, true)] = $x;
    }
    return $r;
};
$rows = [
    $h,
    $row(['序号' => 1.0, '单据日期' => '2026-03-05', '单据编号' => 'XSD202603050001', '客户名称' => 'Cikeas Aluminium', '商品名称' => 'SJ8001纯白（Pure White）4.0*0.21', '规格' => '纯白（Pure White）/4.0*0.21', '单位' => '张', '数量' => 10.0, '折后价' => 300000.0, '含税金额' => 3000000.0, '备注' => '', '折后金额' => 3400000.0, '运费' => 100000.0, '折前金额' => 3400000.0, '本单应收' => 3500000.0, '本单实收' => 3500000.0, '收款状态' => '已结清', '结算账户' => '现金', '单据备注' => '', '业务员' => 'ridwan']),
    $row(['商品名称' => 'SJ8006哑白（Off White）4.0*0.10', '规格' => '哑白（Off White）/4.0*0.10', '单位' => '张', '数量' => 2.0, '折后价' => 200000.0, '含税金额' => 400000.0, '备注' => '样品']),
    array_fill(0, count($h), null),
    $row(['序号' => 2.0, '单据日期' => '2026-08-10', '单据编号' => 'XSD202608100001', '客户名称' => '零售散客', '商品名称' => 'SJ8001纯白（Pure White）4.0*0.21', '单位' => '张', '数量' => 1.0, '折后价' => 300000.0, '含税金额' => 300000.0, '折后金额' => 300000.0, '运费' => 0.0, '本单应收' => 300000.0, '本单实收' => 300000.0, '收款状态' => '已结清', '业务员' => 'anton']),
    $row(['序号' => 3.0, '单据日期' => '2026-07-20', '单据编号' => 'XSD202607200001', '客户名称' => 'PT  Unggul Jaya Timber', '商品名称' => 'SJ8001纯白（Pure White）4.0*0.21', '单位' => '张', '数量' => 5.0, '折后价' => 300000.0, '含税金额' => 1500000.0, '折后金额' => 1500000.0, '运费' => 0.0, '本单应收' => 1500000.0, '本单实收' => 1500000.0, '收款状态' => '已结清', '业务员' => 'Erpandi']),
];
$parsed = SalesHistory::parse($rows);
ok('three documents, no errors', count($parsed['docs']) === 3 && $parsed['errors'] === [], json_encode($parsed['errors']));
ok('continuation line attached to the first document', count($parsed['docs'][0]['items']) === 2 && $parsed['docs'][0]['items'][1]['note'] === '样品');
ok('header amounts read', $parsed['docs'][0]['amount'] === 3500000.0 && $parsed['docs'][0]['shipping'] === 100000.0 && $parsed['docs'][0]['salesperson'] === 'ridwan');
ok('double space in a name collapsed', $parsed['docs'][2]['customer_name'] === 'PT Unggul Jaya Timber');
$bad = SalesHistory::parse([['a', 'b']]);
ok('missing columns reported', $bad['docs'] === [] && str_contains($bad['errors'][0], 'missing columns'));

// ── 3) Import against a live-shaped database ────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Ridwan', 'r@x', 'x', 'sales'), ('erfandi', 'e@x', 'x', 'sales'), ('Fin', 'f@x', 'x', 'finance_manager'), ('Boss', 'b@x', 'x', 'manager')");
$pdo->exec("INSERT INTO customers (name, company) VALUES ('Cikeas Aluminium', NULL), ('Unggul Jaya Timber', 'PT Unggul Jaya Timber')");
$pdo->exec("INSERT INTO products (sku, name, spec) VALUES ('SJ8001', 'Pure White', '4.0*0.21'), ('SJ8006', 'Off White', '4.0*0.10')");

$rep = SalesHistory::import($pdo, $parsed['docs'], ['until' => '2026-08-03', 'aliases' => ['Erpandi' => 'erfandi']], false);
ok('dry run: two documents taken, the August one skipped', $rep['docs'] === 2 && $rep['skipped_after'] === 1 && $rep['amount'] === 5000000.0, json_encode($rep));
ok('dry run writes nothing', (int) $pdo->query('SELECT COUNT(*) FROM sales_history')->fetchColumn() === 0);
ok('customers matched by name and by company', $rep['customers_matched'] === 2 && $rep['customers_unmatched'] === []);
ok('alias resolves the salesperson', $rep['users_unmatched'] === [], json_encode($rep['users_unmatched']));

$rep = SalesHistory::import($pdo, $parsed['docs'], ['until' => '2026-08-03', 'aliases' => ['Erpandi' => 'erfandi'], 'batch' => 'b1'], true);
ok('import writes two documents, three lines', (int) $pdo->query('SELECT COUNT(*) FROM sales_history')->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM sales_history_items')->fetchColumn() === 3);
$d = $pdo->query("SELECT * FROM sales_history WHERE doc_no = 'XSD202603050001'")->fetch();
ok('customer linked, salesperson mapped (case-insensitive)', (int) $d['customer_id'] === 1 && $d['submitter'] === 'Ridwan' && $d['salesperson'] === 'ridwan');
ok('paid defaults to the amount', (float) $d['paid'] === 3500000.0 && (float) $d['amount'] === 3500000.0 && $d['batch'] === 'b1');
$d2 = $pdo->query("SELECT * FROM sales_history WHERE doc_no = 'XSD202607200001'")->fetch();
ok('alias stored as submitter, original kept', $d2['submitter'] === 'erfandi' && $d2['salesperson'] === 'Erpandi' && (int) $d2['customer_id'] === 2);
$it = $pdo->query("SELECT * FROM sales_history_items WHERE line_no = 2")->fetch();
ok('line matched to the catalogue', (int) $it['product_id'] === 2 && $it['sku'] === 'SJ8006' && $it['note'] === '样品');

$rep = SalesHistory::import($pdo, $parsed['docs'], ['until' => '2026-08-03'], true);
ok('re-run skips what is already there', $rep['docs'] === 0 && $rep['skipped_existing'] === 2 && (int) $pdo->query('SELECT COUNT(*) FROM sales_history')->fetchColumn() === 2);
ok('nothing touched stock / orders / invoices', (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM stock_txn')->fetchColumn() === 0);

// ── 4) Yearly totals: history + live invoices, never double counted ─────────
$pdo->exec("INSERT INTO orders (order_no, customer_id, customer_name, submitter, status, wh_date) VALUES ('0001/AMI-CO/08/26', 1, 'Cikeas Aluminium', 'Ridwan', 'approved', '2026-08-20 10:00:00')");
$pdo->exec("INSERT INTO order_items (order_id, sku, qty, price) VALUES (1, 'SJ8001', 4, 300000)");
$pdo->exec("INSERT INTO invoices (invoice_no, order_id, customer, invoice_date, due_date, subtotal, ppn, total) VALUES ('1 - AMI - INV - 08 - 26', 1, 'Cikeas Aluminium', '2026-08-20', '2026-08-20', 1081081, 118919, 1200000)");
$pdo->exec("INSERT INTO invoices (invoice_no, order_id, customer, invoice_date, due_date, subtotal, ppn, total, voided_at) VALUES ('2 - AMI - INV - 08 - 26', NULL, 'Cikeas Aluminium', '2026-08-21', '2026-08-21', 0, 0, 9999999, '2026-08-22')");
$y = SalesHistory::yearly($pdo, 2026);
ok('history in March and July, live in August', $y['months']['2026-03']['hist'] === 3500000.0 && $y['months']['2026-07']['hist'] === 1500000.0 && $y['months']['2026-08']['live'] === 1200000.0 && $y['months']['2026-08']['hist'] === 0.0);
ok('voided invoice excluded', $y['total']['live'] === 1200000.0 && $y['total']['all'] === 6200000.0, json_encode($y['total']));
ok('sheets: 17 history + 4 shipped', $y['total']['hist_sheets'] === 17.0 && $y['total']['live_sheets'] === 4.0, json_encode($y['total']));
ok('salesperson merges both sources', $y['people'][0]['name'] === 'Ridwan' && $y['people'][0]['total'] === 4700000.0 && $y['people'][0]['sheets'] === 16.0, json_encode($y['people']));
ok('customer merges both sources under the platform name', $y['customers'][0]['id'] === 1 && $y['customers'][0]['name'] === 'Cikeas Aluminium' && $y['customers'][0]['total'] === 4700000.0 && $y['customers'][0]['docs'] === 2);
ok('years list', SalesHistory::years($pdo) === [2026]);
$own = SalesHistory::yearly($pdo, 2026, 'erfandi');
ok('owner filter: only that person\'s history', $own['total']['all'] === 1500000.0 && count($own['people']) === 1);
ok('empty year is all zeros', SalesHistory::yearly($pdo, 2025)['total']['all'] === 0.0);

// ── 4b) Customer list from history ──────────────────────────────────────────
$pdo->exec("INSERT INTO sales_history (doc_no, doc_date, customer_name, salesperson, submitter, amount, paid) VALUES
    ('XSD1', '2026-04-01', 'Toko Baru Jaya', 'anton', 'anton', 1000000, 1000000),
    ('XSD2', '2026-05-01', 'TOKO BARU JAYA', 'Ridwan', 'Ridwan', 2000000, 2000000),
    ('XSD3', '2026-05-02', 'PT Baru Jaya Abadi', 'Ridwan', 'Ridwan', 500000, 500000),
    ('XSD4', '2026-06-01', '零售散客', 'Ridwan', 'Ridwan', 100000, 100000)");
$pdo->exec("UPDATE customers SET tag = '潜在', owner = NULL, last_contact = '2026-01-01' WHERE id = 1");
$rep = SalesHistory::sync_customers($pdo, false);
ok('dry run: two names to create (spelling variants merged), walk-ins skipped', count($rep['create']) === 2, json_encode(array_column($rep['create'], 'name')));
$tbj = array_values(array_filter($rep['create'], fn($c) => stripos($c['name'], 'baru jaya') === 0 || stripos($c['name'], 'toko') === 0))[0] ?? null;
ok('most common spelling wins; owner only if a platform user', $tbj !== null && $tbj['docs'] === 2 && $tbj['owner'] === null, json_encode($tbj));
ok('dry run creates nothing', (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 2);
ok('existing buyer: tag / owner / last_contact would be refreshed', $rep['tagged'] >= 1 && $rep['owned'] >= 1 && $rep['contacted'] >= 1, json_encode([$rep['tagged'], $rep['owned'], $rep['contacted']]));
$rep = SalesHistory::sync_customers($pdo, true);
ok('apply: customers created and history linked', (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 4
    && (int) $pdo->query("SELECT COUNT(*) FROM sales_history WHERE customer_id IS NULL AND customer_name <> '零售散客'")->fetchColumn() === 0);
$c1 = $pdo->query('SELECT tag, owner, last_contact FROM customers WHERE id = 1')->fetch();
ok('existing buyer refreshed: 成交, owner Ridwan, last_contact = last purchase', $c1['tag'] === '成交' && $c1['owner'] === 'Ridwan' && $c1['last_contact'] === '2026-03-05', json_encode($c1));
$new = $pdo->query("SELECT name, tag, company, owner FROM customers WHERE name LIKE 'PT Baru Jaya%'")->fetch();
ok('created customer tagged 成交, PT name copied to company, owner Ridwan', $new && $new['tag'] === '成交' && $new['company'] === 'PT Baru Jaya Abadi' && $new['owner'] === 'Ridwan', json_encode($new));
$rep = SalesHistory::sync_customers($pdo, true);
ok('second run is a no-op', $rep['create'] === [] && $rep['tagged'] === 0 && $rep['owned'] === 0 && $rep['contacted'] === 0, json_encode($rep));
$GLOBALS['config'] = ($GLOBALS['config'] ?? []) + ['insights' => []];
$ins = Insights::compute($pdo, $GLOBALS['config'], date('Y-m-d'));   // the platform order is dated today
$row = $pdo->query('SELECT last_order_date, purchase_days FROM customer_insights WHERE customer_id = 1')->fetch();
ok('call lists count history + platform purchases', $row && (int) $row['purchase_days'] === 2, json_encode($row));
$row = $pdo->query('SELECT last_order_date, last_order_id, last_items FROM customer_insights WHERE customer_id = 2')->fetch();
ok('history-only customer: last purchase and items come from the history document', $row && $row['last_order_date'] === '2026-07-20' && (int) $row['last_order_id'] < 0 && str_contains((string) $row['last_items'], 'SJ8001 4.0*0.21 ×5'), json_encode($row));

$n = SalesHistory::purge_batch($pdo, 'b1');
ok('purge removes the batch with its lines', $n === 2 && (int) $pdo->query('SELECT COUNT(*) FROM sales_history_items')->fetchColumn() === 0);

// ── 5) Migration: an older live DB gets the two tables, idempotently ────────
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$schema = preg_replace('/-- ── Historical sales.*$/s', '', $schema);   // the live DB before this change
$old->exec($schema);
$old->exec("INSERT INTO customers (name) VALUES ('Keep Me')");
$tables = fn() => $old->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'sales_history%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
ok('before: no history tables', $tables() === []);
SalesHistory::ensure_schema($old);
ok('after: both tables added', $tables() === ['sales_history', 'sales_history_items']);
SalesHistory::ensure_schema($old);
ok('idempotent; existing data untouched', $tables() === ['sales_history', 'sales_history_items'] && (int) $old->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 1);

// ── 6) Pages render and respect permissions ─────────────────────────────────
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'sh') . '.sqlite';
    $f = new PDO('sqlite:' . $db);
    $f->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO role_permissions (role, module) VALUES ('finance_manager','finance'), ('finance_manager','performance'), ('finance_manager','export'), ('clerk','finance')");
    $f->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Ridwan', 'r@x', 'x', 'sales')");
    $f->exec("INSERT INTO customers (name) VALUES ('Cikeas Aluminium')");
    $f = null;
    $pdoF = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    SalesHistory::import($pdoF, $parsed['docs'], ['until' => '2026-08-03', 'batch' => 'b2'], true);
    $pdoF = null;
    $run = function (string $action, array $user, array $get = []) use ($db, $harness): string {
        $args = [PHP_BINARY, $harness, $db, 'finance', $action, json_encode($get ?: new stdClass()), json_encode($user)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $fin = ['id' => 7, 'name' => 'Fin', 'role' => 'finance_manager'];
    $clerk = ['id' => 8, 'name' => 'Ridwan', 'role' => 'clerk'];
    $html = $run('yearly', $fin, ['year' => '2026']);
    ok('yearly page: totals and monthly table', str_contains($html, 'Rp 5.000.000') && str_contains($html, '3月') && str_contains($html, 'Cikeas Aluminium'), substr($html, 0, 300));
    ok('yearly page: per-salesperson block for performance holders', str_contains($html, t('yr_by_person')) && str_contains($html, 'Ridwan'));
    $html = $run('yearly', $clerk, ['year' => '2026']);
    ok('without performance: no ranking, only own figures', !str_contains($html, t('yr_by_person')) && str_contains($html, 'Rp 3.500.000') && !str_contains($html, 'Rp 5.000.000'));
    $html = $run('history', $fin, ['q' => 'XSD202607']);
    ok('history list: search by doc no', str_contains($html, 'XSD202607200001') && !str_contains($html, 'XSD202603050001'));
    $html = $run('history', $fin, ['q' => 'SJ8006']);
    ok('history list: search by sku inside lines', str_contains($html, 'XSD202603050001') && str_contains($html, '样品'));
    @unlink($db);
}
