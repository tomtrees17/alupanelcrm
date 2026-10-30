<?php

// ── CM-01 重复客户合并 (docs/任务卡-客户合并.md) ──
// Fictional data shaped like the case that started it: one customer entered
// four times (same phone, name with / without "Bpk", company spelt two ways).

$cmFixture = function (PDO $p): void {
    $p->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES
        (1, 'Admin', 'a@x', 'x', 'admin'), (2, 'rian', 'r@x', 'x', 'sales'), (3, 'calvin', 'c@x', 'x', 'sales'),
        (4, 'Mgr', 'm@x', 'x', 'sales_manager'), (5, 'nasya', 'n@x', 'x', 'sales_assistant')");
    $p->exec("INSERT INTO customers (id, name, company, phone, city, owner, tag, note, tax_npwp, created_at) VALUES
        (1, 'Bpk Darim', 'Multi Jaya Darim', '08129813257', '', 'rian', '潜在', 'minta katalog', NULL, '2026-09-01 10:00:00'),
        (2, 'Darim', 'MULTI JAYA ALUMUNIUM', '08129813257', '', 'rian', '成交', '', NULL, '2026-09-10 10:00:00'),
        (3, 'DARIM', 'Multi Jaya Aluminium', '0812-9813-257', 'KP. KAUM TENGAH CIKARANG', 'rian', '成交', '', NULL, '2026-09-20 10:00:00'),
        (4, 'DARIM', 'Multi Jaya Aluminium', '', 'KP KAUM TENGAH CIKARANG', 'rian', '成交', '', NULL, '2026-10-01 10:00:00'),
        (5, 'Budi', 'Toko Budi Jaya', '081111111111', '', 'calvin', '潜在', '', NULL, '2026-09-01 10:00:00'),
        (6, 'Budi', 'PT Budi Abadi', '082222222222', '', 'calvin', '潜在', '', NULL, '2026-09-01 10:00:00'),
        (7, 'Andi Glass', '', '083333333333', '', 'calvin', '成交', '', '01.234.567.8-901.000', '2026-09-01 10:00:00'),
        (8, 'ANDI GLASS', '', '', '', 'calvin', '潜在', '', '02.999.999.9-999.000', '2026-09-01 10:00:00'),
        (9, 'Sinar Kaca', '', '084444444444', '', 'rian', '潜在', '', NULL, '2026-09-01 10:00:00'),
        (10, 'Ibu Sinar Kaca', '', '', '', 'calvin', '潜在', '', NULL, '2026-09-01 10:00:00'),
        (11, 'Ratna', 'CV Ratna Sign', '085555555555', '', 'calvin', '潜在', '', NULL, '2026-09-01 10:00:00'),
        (12, 'Ratna Sign', '', '085555555555', '', 'calvin', '潜在', '', NULL, '2026-09-01 10:00:00')");
    $o = $p->prepare("INSERT INTO orders (id, order_no, customer_id, customer_name, submitter, status, created_at, payment_term) VALUES (?,?,?,?, 'rian', 'approved', ?, 'CBD')");
    $i = $p->prepare("INSERT INTO invoices (invoice_no, order_id, customer, subtotal, ppn, total, invoice_date, due_date) VALUES (?,?,?,?,?,?,?,?)");
    foreach ([[101, 1, '2026-09-02', 1000000], [102, 2, '2026-09-11', 2000000], [103, 2, '2026-09-15', 0], [104, 3, '2026-09-21', 1400000],
              [105, 3, '2026-10-02', 11180000], [106, 3, '2026-10-20', 500000], [107, 4, '2026-10-03', 0]] as [$oid, $cid, $d, $amt]) {
        $o->execute([$oid, "{$oid}/AMI-CO/10/26", $cid, 'Darim', "{$d} 09:00:00"]);
        if ($amt > 0) {
            $i->execute(["INV-{$oid}", $oid, 'Darim', $amt / 1.11, $amt - $amt / 1.11, $amt, $d, $d]);
        }
    }
    $p->exec("INSERT INTO visits (id, visited_at, target_type, customer_id, outcome) VALUES (1, '2026-10-20 10:00:00', 'customer', 2, 'order'), (2, '2026-10-21 10:00:00', 'customer', 3, 'penawaran')");
    $p->exec("INSERT INTO customer_followups (customer_id, user_name, kind, note) VALUES (1, 'rian', 'call', 'sudah telepon')");
    $p->exec("INSERT INTO deals (name, customer_id) VALUES ('Kanopi gudang', 3)");
    $p->exec("INSERT INTO tasks (title, customer_id) VALUES ('Kirim sampel', 4)");
    $p->exec("INSERT INTO sales_history (doc_no, doc_date, customer_name, customer_id) VALUES ('XSD1', '2026-05-01', 'darim', 2)");
    $p->exec("INSERT INTO customer_name_aliases (customer_id, alias, alias_key, source) VALUES (1, 'Toko Darim Cikarang', 'darim cikarang', 'confirm')");
    $p->exec("INSERT INTO customer_payer_aliases (customer_id, payer_name, payer_key, source) VALUES (2, 'DARIM', 'DARIM', 'history'), (3, 'DARIM', 'DARIM', 'history'), (2, 'MULTI JAYA', 'MULTI JAYA', 'history')");
    $p->exec("INSERT INTO bank_book (trx_code, trx_date, description, amount, customer_id) VALUES ('T1', '2026-10-05', 'TRSF DARIM', 1400000, 1)");
    $p->exec("INSERT INTO customer_insights (customer_id, computed_on) VALUES (1, '2026-10-29'), (3, '2026-10-29')");
};

// 1) Unit: keys, grouping, plan, merge.
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$m = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$m->exec($schemaSql);
$cmFixture($m);
$savedAuth = $GLOBALS['auth'] ?? null;
$GLOBALS['auth'] = new AuthStub(['id' => 4, 'name' => 'Mgr', 'role' => 'sales_manager']);
$one = fn(string $sql) => $m->query($sql)->fetchColumn();

ok('phone key: 0812-9813-257 = 08129813257 = +62 812 9813 257', CustomerMerge::phone_key('0812-9813-257') === CustomerMerge::phone_key('08129813257')
    && CustomerMerge::phone_key('+62 812 9813 257') === '129813257' && CustomerMerge::phone_key('wechat') === '');
ok('name key drops Bpk / Ibu / PT / CV / Toko', CustomerMerge::name_key('Bpk Darim') === 'darim' && CustomerMerge::name_key('CV. Ratna Sign') === 'ratna sign' && CustomerMerge::name_key('Ibu Sinar Kaca') === 'sinar kaca');
ok('company typo is similar (Aluminium / Alumunium), different companies are not',
    CustomerMerge::similar('multi jaya alumunium', 'multi jaya aluminium') && !CustomerMerge::similar('budi jaya', 'budi abadi'));

$groups = CustomerMerge::groups($m);
$find = function (array $groups, int $id): ?array {
    foreach ($groups as $g) {
        if (in_array($id, $g['ids'], true)) {
            return $g;
        }
    }
    return null;
};
$darim = $find($groups, 1);
ok('the four Darim records are one group (phone + name)', $darim && $darim['ids'] === [1, 2, 3, 4] && in_array('phone', $darim['reasons'], true) && in_array('name', $darim['reasons'], true), json_encode($darim['ids'] ?? null));
ok('…listed first (it has orders), the record with most orders on top', $groups[0]['ids'] === [1, 2, 3, 4] && (int) $groups[0]['rows'][0]['id'] === 3 && (int) $groups[0]['rows'][0]['orders'] === 3);
ok('…with sales from invoices (voided excluded) and visits', (float) $groups[0]['rows'][0]['sales'] === 13080000.0 && (int) $groups[0]['rows'][0]['visits'] === 1);
ok('two "Budi" with different phones are not grouped', $find($groups, 5) === null && $find($groups, 6) === null);
ok('Andi Glass / ANDI GLASS grouped (one phone empty)', ($find($groups, 7)['ids'] ?? []) === [7, 8]);
ok('Ratna / Ratna Sign grouped by phone', ($find($groups, 11)['ids'] ?? []) === [11, 12]);

$bad = CustomerMerge::plan($m, 7, [8]);
ok('different NPWP: merge refused', !$bad['ok'] && $bad['error'] === 'cm_err_npwp');
$own = CustomerMerge::plan($m, 9, [10]);
ok('different owners: allowed, with both names to warn about', $own['ok'] && $own['owners'] === ['rian', 'calvin']);
ok('nothing picked / keeping itself: refused', CustomerMerge::plan($m, 3, [])['error'] === 'cm_err_pick' && CustomerMerge::plan($m, 3, [3])['error'] === 'cm_err_pick');

$plan = CustomerMerge::plan($m, 3, [1, 2, 4]);
ok('plan counts what moves', $plan['ok'] && $plan['counts']['orders'] === 4 && $plan['counts']['visits'] === 1 && $plan['counts']['customer_followups'] === 1
    && $plan['counts']['tasks'] === 1 && $plan['counts']['sales_history'] === 1 && $plan['counts']['customer_payer_aliases'] === 2 && $plan['owners'] === [], json_encode($plan['counts']));

$salesBefore = (float) $one('SELECT SUM(total) FROM invoices');
CustomerMerge::merge($m, 3, [1, 2, 4], 'Mgr');
ok('all 7 orders now on the kept record', (int) $one('SELECT COUNT(*) FROM orders WHERE customer_id = 3') === 7 && (int) $one('SELECT COUNT(*) FROM orders WHERE customer_id IN (1,2,4)') === 0);
ok('…sales on it = the four records\' sum, total sales unchanged',
    (float) $one('SELECT SUM(i.total) FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.customer_id = 3') === $salesBefore && (float) $one('SELECT SUM(total) FROM invoices') === $salesBefore);
ok('visits, follow-ups, deals, tasks, history, name alias and bank line moved',
    (int) $one('SELECT COUNT(*) FROM visits WHERE customer_id = 3') === 2 && (int) $one('SELECT customer_id FROM customer_followups') === 3
    && (int) $one('SELECT customer_id FROM deals') === 3 && (int) $one('SELECT customer_id FROM tasks') === 3 && (int) $one('SELECT customer_id FROM sales_history') === 3
    && (int) $one('SELECT customer_id FROM customer_name_aliases') === 3 && (int) $one('SELECT customer_id FROM bank_book') === 3);
ok('payer aliases: the same name twice kept once, the new one moved', (int) $one("SELECT COUNT(*) FROM customer_payer_aliases WHERE payer_key = 'DARIM'") === 1
    && (int) $one("SELECT customer_id FROM customer_payer_aliases WHERE payer_key = 'MULTI JAYA'") === 3);
ok('today\'s call list loses the merged record, keeps the kept one', (int) $one('SELECT COUNT(*) FROM customer_insights WHERE customer_id = 1') === 0 && (int) $one('SELECT COUNT(*) FROM customer_insights WHERE customer_id = 3') === 1);
$merged = $m->query('SELECT * FROM customers WHERE id = 2')->fetch();
$log = json_decode((string) $merged['merge_log'], true);
ok('merged records are still there, pointing at the kept one, with who / when', (int) $one('SELECT COUNT(*) FROM customers WHERE merged_into = 3') === 3 && $merged['merged_by'] === 'Mgr' && $merged['merged_at'] !== null);
ok('…and a log of exactly which ids moved (for a manual reversal)', $log['into'] === 3 && $log['orders'] === [102, 103] && $log['visits'] === [1] && isset($log['customer_payer_aliases_same']));
$kept = $m->query('SELECT * FROM customers WHERE id = 3')->fetch();
ok('kept record: blanks filled (note), existing values untouched (phone, city, owner, tag)', $kept['note'] === 'minta katalog' && $kept['phone'] === '0812-9813-257'
    && $kept['city'] === 'KP. KAUM TENGAH CIKARANG' && $kept['owner'] === 'rian' && $kept['tag'] === '成交');
$au = $m->query("SELECT * FROM audit_log WHERE module = 'customers' AND action = 'merge'")->fetch();
ok('audit log: merge, by the manager, naming the records', $au && (int) $au['entity_id'] === 3 && $au['user_name'] === 'Mgr' && str_contains($au['detail'], '#1 Bpk Darim') && str_contains($au['detail'], 'orders 4'));
ok('the group is gone from the duplicates list', $find(CustomerMerge::groups($m), 3) === null);
ok('a merged record cannot be merged again', CustomerMerge::plan($m, 2, [5])['error'] === 'cm_err_gone' && CustomerMerge::plan($m, 5, [2])['error'] === 'cm_err_gone');

$cands = VisitImport::candidates($m, 'Bpk Darim', ['match_min' => 0.6, 'match_auto' => 0.95]);
ok('a visit written with an old name still finds the kept record', ($cands['list'][0]['key'] ?? '') === 'customer:3', json_encode($cands['list'][0] ?? null));
$lk = SalesHistory::lookups($m);
ok('order paste / history matching: the old company name maps to the kept record', SalesHistory::match_customer($lk, 'Multi Jaya Darim') === 3);

$m->exec("INSERT INTO customers (id, name, phone, tag) VALUES (20, 'Darim Baru', '0899', '潜在')");
CustomerMerge::merge($m, 20, [3], 'Mgr');
ok('merging the kept record later re-points the earlier ones (no chains)', (int) $one('SELECT COUNT(*) FROM customers WHERE merged_into = 20') === 4 && (int) $one('SELECT COUNT(*) FROM customers WHERE merged_into = 3') === 0);

CustomerMerge::ignore($m, [7, 8]);
ok('不是同一个: the group no longer appears', $find(CustomerMerge::groups($m), 7) === null && $find(CustomerMerge::groups($m), 11) !== null);

ok('by phone: one live customer → it', CustomerMerge::by_phone($m, '0844 4444 4444') === 9);
ok('by phone: two live customers with it → none', CustomerMerge::by_phone($m, '085555555555') === null);
ok('by phone: merged records do not count, empty / junk → none', CustomerMerge::by_phone($m, '08129813257') === null && CustomerMerge::by_phone($m, '') === null && CustomerMerge::by_phone($m, 'wa') === null);
$GLOBALS['auth'] = $savedAuth;

// 2) Pages and permissions (admin + sales manager only), new orders matched by phone.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'cmerge');
    $f = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $f->exec($schemaSql);
    $cmFixture($f);
    $f = null;
    $run = function (int $uid, string $ctl, string $action, array $get, ?array $post) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode($get ?: new stdClass()), json_encode(['id' => $uid, 'real' => true]), $post === null ? '' : json_encode($post), json_encode(['lang' => 'zh'])];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $zh = fn(string $k) => I18N['zh'][$k];

    [$list] = $run(4, 'customers', 'index', [], null);
    ok('sales manager: 重复客户 button on the customer list', str_contains($list, 'r=customers.duplicates'));
    [$dup] = $run(4, 'customers', 'duplicates', [], null);
    ok('…the page lists the Darim group with the most-ordered record pre-selected to keep',
        str_contains($dup, 'Multi Jaya Aluminium') && preg_match('/name="keep" value="3" checked/', $dup) === 1 && str_contains($dup, $zh('cm_reason_phone')));
    [$conf] = $run(4, 'customers', 'merge', [], ['keep' => '3', 'from' => ['1', '2', '4']]);
    ok('next step shows what moves, nothing changed yet', str_contains($conf, $zh('cm_confirm_title')) && str_contains($conf, $zh('cm_t_orders') . ' 4')
        && $q('SELECT COUNT(*) n FROM customers WHERE merged_into IS NOT NULL')[0]['n'] == 0);
    [, $fl] = $run(4, 'customers', 'merge', [], ['keep' => '3', 'from' => ['1', '2', '4'], 'confirm' => '1']);
    ok('confirm: merged, told so', ($fl[0]['type'] ?? '') === 'success' && $q('SELECT COUNT(*) n FROM customers WHERE merged_into = 3')[0]['n'] == 3, json_encode($fl));
    [$list2] = $run(4, 'customers', 'index', ['q' => 'darim'], null);
    ok('customer list: only the kept Darim left', substr_count($list2, 'r=customers.show&id=') === 1 && str_contains($list2, 'r=customers.show&id=3'));
    [, $fl] = $run(4, 'customers', 'show', ['id' => 1], null);
    ok('old link to a merged record → kept record, with a note', ($fl[0]['message'] ?? '') === sprintf($zh('cm_msg_was_merged'), 'Bpk Darim', 'DARIM'));
    [$show] = $run(4, 'customers', 'show', ['id' => 3], null);
    ok('kept record shows where it was merged from, and all 7 orders', str_contains($show, $zh('cm_merged_from')) && str_contains($show, '#1 Bpk Darim') && substr_count($show, '/AMI-CO/10/26') >= 7);
    ok('…and the manager gets 合并到其他客户…', str_contains($show, $zh('cm_into_title')));
    [$form] = $run(4, 'orders', 'create', [], null);
    ok('order form customer picker: no merged records', !str_contains($form, 'Multi Jaya Darim') && str_contains($form, 'Multi Jaya Aluminium'));
    [, $fl] = $run(4, 'customers', 'merge', [], ['keep' => '7', 'from' => ['8'], 'confirm' => '1']);
    ok('different NPWP from the page: refused with the reason', ($fl[0]['message'] ?? '') === $zh('cm_err_npwp') && $q('SELECT merged_into FROM customers WHERE id = 8')[0]['merged_into'] === null);
    [, $fl] = $run(4, 'customers', 'merge', [], ['target' => '#9 · Sinar Kaca', 'from' => ['10'], 'back' => 'show', 'confirm' => '1']);
    ok('合并到… from a customer page (target "#9 · name") works', $q('SELECT merged_into FROM customers WHERE id = 10')[0]['merged_into'] == 9, json_encode($fl));
    $run(1, 'customers', 'dup_ignore', [], ['ids' => ['11', '12']]);
    ok('admin can mark 不是同一个', !str_contains($run(1, 'customers', 'duplicates', [], null)[0], 'Ratna Sign'));

    foreach ([2 => 'sales', 5 => 'sales assistant'] as $uid => $who) {
        [$l] = $run($uid, 'customers', 'index', [], null);
        [, $fl] = $run($uid, 'customers', 'duplicates', [], null);
        [, $fl2] = $run($uid, 'customers', 'merge', [], ['keep' => '5', 'from' => ['6'], 'confirm' => '1']);
        ok("{$who}: no button, page and merge refused", !str_contains($l, 'r=customers.duplicates') && ($fl[0]['message'] ?? '') === $zh('cm_msg_no_access')
            && ($fl2[0]['message'] ?? '') === $zh('cm_msg_no_access') && $q('SELECT merged_into FROM customers WHERE id = 6')[0]['merged_into'] === null);
    }
    ok('salesperson: no 合并到… on a customer page', !str_contains($run(2, 'customers', 'show', ['id' => 9], null)[0], $zh('cm_into_title')));

    $base = ['do' => 'draft', 'save_customer' => '1', 'product_id' => [''], 'sku' => [''], 'qty' => ['0'], 'price' => ['0'], 'price_basis' => 'incl', 'sales_name' => 'rian'];
    $nCust = fn() => (int) $q('SELECT COUNT(*) n FROM customers')[0]['n'];
    $n0 = $nCust();
    $run(4, 'orders', 'store', [], $base + ['customer_name' => 'Pak Darim', 'phone' => '0812 9813 257', 'note' => 'cm-a']);
    $oa = $q("SELECT * FROM orders WHERE note = 'cm-a'")[0];
    ok('new order, "save new customer", phone of exactly one customer → linked to it, no new record', $nCust() === $n0 && (int) $oa['customer_id'] === 3 && $oa['customer_matched'] === 'phone' && $oa['customer_name'] === 'Pak Darim');
    [$os] = $run(4, 'orders', 'show', ['id' => $oa['id']], null);
    ok('…the order page says it was matched by phone, linking the customer', str_contains($os, $zh('cm_order_matched')) && str_contains($os, 'r=customers.show&id=3'));
    $run(4, 'orders', 'store', [], $base + ['customer_name' => 'Ratna Baru', 'phone' => '085555555555', 'note' => 'cm-b']);
    $ob = $q("SELECT * FROM orders WHERE note = 'cm-b'")[0];
    ok('phone shared by two customers → a new customer as before', $nCust() === $n0 + 1 && !in_array((int) $ob['customer_id'], [11, 12], true) && $ob['customer_matched'] === null);
    $run(4, 'orders', 'store', [], $base + ['customer_name' => 'Tanpa Telepon', 'phone' => '', 'note' => 'cm-c']);
    ok('no phone → a new customer as before', $nCust() === $n0 + 2);
    @unlink($db);
}
