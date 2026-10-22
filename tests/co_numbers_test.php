<?php

// ── CO numbers: register import, one shared sequence, taking / linking numbers, matching old orders ──

require_once __DIR__ . '/../app/Csrf.php';

$mem = fn() => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

// ── 1) Parsing the form export ──────────────────────────────────────────────
$serial = fn(string $dt): float => (strtotime($dt . ' UTC') / 86400) + 25569;
$rows = [
    ['PO NUMBER', null, null, null, null, null, null, 'Timestamp', 'Sales Name', 'Company of Client', 'Name of Client', 'Phone Number of Client', 'Month'],
    ['0001', '/', 'AMI-CO', '/', '02', '/', '26', $serial('2026-02-09 10:49:20'), 'TEST', 'TEST', 'TEST', 'TEST', '02'],
    ['1377', '/', 'AMI-CO', '/', '10', '/', '26', $serial('2026-10-22 11:54:38'), 'Ridwan', 'ARGANI', 'ARIS', '089654957853', '10'],
    ['1376', '/', 'AMI-CO', '/', '10', '/', '26', $serial('2026-10-22 11:29:14'), 'Rian', 'ELBE HOUSE ', 'Pak ind', '0813831707', '10'],
    [0, 0, 0, 0, 0, 0, 0, null, null, null, null, null, null],
];
$parsed = CoNumbers::parse($rows);
ok('three issued numbers, blanks and header skipped', count($parsed) === 3, count($parsed));
ok('number formatted', $parsed[1]['co_no'] === '1377/AMI-CO/10/26' && $parsed[1]['seq'] === 1377);
ok('timestamp from Excel serial', $parsed[1]['issued_at'] === '2026-10-22 11:54:38', $parsed[1]['issued_at']);
ok('text trimmed', $parsed[2]['company'] === 'ELBE HOUSE');
ok('text timestamp accepted', CoNumbers::to_datetime('2026-10-22 11:54:38') === '2026-10-22 11:54:38');
ok('phone key ignores formatting', CoNumbers::phone_key('+62 896-5495-7853') === CoNumbers::phone_key('089654957853') && CoNumbers::phone_key('123') === '');

// ── 2) Import + the shared sequence ─────────────────────────────────────────
$pdo = $mem();
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec("INSERT INTO orders (order_no, customer_name, submitter, status) VALUES ('0487/AMI-CO/10/26', 'Bpk Andi', 'erfandi', 'pending_wh')");
ok('before import the platform would re-issue 0488', next_order_no($pdo) === sprintf('0488/AMI-CO/%s/%s', date('m'), date('y')), next_order_no($pdo));
$rep = CoNumbers::import($pdo, $parsed, 'b1', false);
ok('dry run counts, writes nothing', $rep['added'] === 3 && (int) $pdo->query('SELECT COUNT(*) FROM co_requests')->fetchColumn() === 0);
$rep = CoNumbers::import($pdo, $parsed, 'b1', true);
ok('import writes the register', $rep['added'] === 3 && $rep['max_seq'] === 1377 && (int) $pdo->query('SELECT COUNT(*) FROM co_requests')->fetchColumn() === 3);
ok('after import the next number continues after the register', next_order_no($pdo) === sprintf('1378/AMI-CO/%s/%s', date('m'), date('y')), next_order_no($pdo));
$rep = CoNumbers::import($pdo, $parsed, 'b2', true);
ok('re-import adds nothing', $rep['added'] === 0 && $rep['existing'] === 3);
$rep = CoNumbers::import($pdo, CoNumbers::parse([['1378', '/', 'AMI-CO', '/', '10', '/', '26', $serial('2026-10-22 12:10:00'), 'Erpandi', 'Sinar Alam Aluminium', 'Bpk Andi', '0817768799', '10']]), 'b3', true);
ok('a newer export adds only the new number', $rep['added'] === 1 && CoNumbers::max_seq($pdo) === 1378);

// ── 3) Taking a form number on a draft; linking an old order ────────────────
$avail = CoNumbers::available($pdo, 365);
ok('available = unlinked numbers, newest first', count($avail) === 4 && $avail[0]['co_no'] === '1378/AMI-CO/10/26');
ok('old numbers fall outside the window', count(CoNumbers::available($pdo, 30)) === 3);

$pdo->exec("INSERT INTO orders (order_no, co_no, customer_name, submitter, status) VALUES ('1379/AMI-CO/10/26', '1379/AMI-CO/10/26', 'New Co', 'Ridwan', 'draft')");
$draftId = (int) $pdo->lastInsertId();
CoNumbers::link($pdo, $draftId, '1377/AMI-CO/10/26', 'Ridwan', true, 'picked');
$o = $pdo->query("SELECT order_no, co_no FROM orders WHERE id = $draftId")->fetch();
ok('draft takes the form number as its order number', $o['order_no'] === '1377/AMI-CO/10/26' && $o['co_no'] === '1377/AMI-CO/10/26');
$r = $pdo->query("SELECT order_id, linked_by, link_note FROM co_requests WHERE seq = 1377")->fetch();
ok('register entry points at the order', (int) $r['order_id'] === $draftId && $r['linked_by'] === 'Ridwan' && $r['link_note'] === 'picked');
ok('taken number leaves the pool', !in_array('1377/AMI-CO/10/26', array_column(CoNumbers::available($pdo, 365), 'co_no'), true));
ok('...but stays listed for its own order', in_array('1377/AMI-CO/10/26', array_column(CoNumbers::available($pdo, 365, '1377/AMI-CO/10/26'), 'co_no'), true));

$shipped = (int) $pdo->query("SELECT id FROM orders WHERE order_no = '0487/AMI-CO/10/26'")->fetchColumn();
CoNumbers::link($pdo, $shipped, '1378/AMI-CO/10/26', 'Fin', false, 'manual');
$o = $pdo->query("SELECT order_no, co_no FROM orders WHERE id = $shipped")->fetch();
ok('old order keeps its order number, gains the CO number', $o['order_no'] === '0487/AMI-CO/10/26' && $o['co_no'] === '1378/AMI-CO/10/26');
$err = '';
try {
    CoNumbers::link($pdo, $draftId, '1378/AMI-CO/10/26', 'x', true);
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
ok('a number already tied to another order is refused', $err === 'co_taken');
$err = '';
try {
    CoNumbers::link($pdo, $draftId, '9999/AMI-CO/10/26', 'x', true);
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
ok('unknown number refused', $err === 'co_not_found');
CoNumbers::link($pdo, $draftId, '1376/AMI-CO/10/26', 'Ridwan', true);
ok('re-picking releases the previous number', $pdo->query("SELECT order_id FROM co_requests WHERE seq = 1377")->fetchColumn() === null
    && (int) $pdo->query("SELECT order_id FROM co_requests WHERE seq = 1376")->fetchColumn() === $draftId);
CoNumbers::unlink($pdo, $shipped);
ok('unlink clears both sides', $pdo->query("SELECT co_no FROM orders WHERE id = $shipped")->fetchColumn() === null && $pdo->query("SELECT order_id FROM co_requests WHERE seq = 1378")->fetchColumn() === null);
ok('sequence continues after the highest number in use (1379 was released by the rename)', next_order_no($pdo) === sprintf('1379/AMI-CO/%s/%s', date('m'), date('y')), next_order_no($pdo));

// ── 4) Matching old platform orders to the register ─────────────────────────
$pdo = $mem();
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$ins = $pdo->prepare('INSERT INTO co_requests (seq, co_no, issued_at, sales_name, company, client_name, phone) VALUES (?,?,?,?,?,?,?)');
$ins->execute([1300, '1300/AMI-CO/10/26', '2026-10-02 09:00:00', 'Ridwan', 'Cikeas Alumunium', 'Wahyu', '0878 3124 0125']);
$ins->execute([1301, '1301/AMI-CO/10/26', '2026-10-02 10:00:00', 'Erpandi', 'PT Sinar Alam Aluminium', 'Bapak Andi', '0817768799']);
$ins->execute([1302, '1302/AMI-CO/10/26', '2026-10-03 10:00:00', 'Erpandi', 'Sinar Alam Aluminium', 'Andi', '0817768799']);   // a second request, same customer
$ins->execute([1303, '1303/AMI-CO/10/26', '2026-10-05 10:00:00', 'Fera', 'Mozaik Karya', 'Dewi', '0811111111']);
$ins->execute([900, '0900/AMI-CO/08/26', '2026-08-10 10:00:00', 'Fera', 'Mozaik Karya', 'Dewi', '0811111111']);   // too old for the October order
$oi = $pdo->prepare('INSERT INTO orders (order_no, customer_name, company, phone, submitter, status, created_at) VALUES (?,?,?,?,?,?,?)');
$oi->execute(['0470/AMI-CO/10/26', 'Wahyu', 'Cikeas Aluminium', '087831240125', 'Ridwan', 'approved', '2026-10-03 11:00:00']);
$oi->execute(['0471/AMI-CO/10/26', 'Bpk Andi', 'Sinar Alam Aluminium', '', 'erfandi', 'approved', '2026-10-03 12:00:00']);
$oi->execute(['0472/AMI-CO/10/26', 'Dewi', 'Mozaik Karya Nusantara', '+62 811 111 111', 'fera', 'approved', '2026-10-06 12:00:00']);
$oi->execute(['0473/AMI-CO/10/26', 'Nobody', 'Unknown Shop', '', 'anton', 'approved', '2026-10-06 12:00:00']);
$oi->execute(['0300/AMI-CO/07/26', 'Dewi', 'Mozaik Karya', '0811111111', 'fera', 'approved', '2026-07-20 12:00:00']);   // before the platform's go-live window
$res = CoNumbers::propose($pdo, '2026-08-04', ['erfandi' => 'erpandi']);
$byNo = fn(array $list) => array_combine(array_map(fn($e) => $e['order']['order_no'], $list), $list);
$m = $byNo($res['matched']);
ok('phone + name match is sure', isset($m['0470/AMI-CO/10/26']) && $m['0470/AMI-CO/10/26']['req']['co_no'] === '1300/AMI-CO/10/26', json_encode(array_keys($m)));
ok('phone match beats a spelling difference', isset($m['0472/AMI-CO/10/26']) && $m['0472/AMI-CO/10/26']['req']['co_no'] === '1303/AMI-CO/10/26');
$a = $byNo($res['ambiguous']);
ok('two requests by the same customer: the one nearest in time is taken, no doubt raised', ($m['0471/AMI-CO/10/26']['req']['co_no'] ?? '') === '1302/AMI-CO/10/26', json_encode(array_keys($a)));
// A regular customer: three orders, three requests days apart, same phone. Each
// order pairs with the request nearest in time; none of them is "ambiguous".
$ins->execute([1400, '1400/AMI-CO/10/26', '2026-10-10 09:00:00', 'Fera', 'Sasmita', 'Sasmita', '0812000000']);
$ins->execute([1401, '1401/AMI-CO/10/26', '2026-10-13 09:00:00', 'Fera', 'Sasmita', 'Sasmita', '0812000000']);
$ins->execute([1402, '1402/AMI-CO/10/26', '2026-10-16 09:00:00', 'Fera', 'Sasmita', 'Sasmita', '0812000000']);
$oi->execute(['0480/AMI-CO/10/26', 'Sasmita', 'Sasmita', '0812000000', 'Fera', 'approved', '2026-10-10 12:00:00']);
$oi->execute(['0481/AMI-CO/10/26', 'Sasmita', 'Sasmita', '0812000000', 'Fera', 'approved', '2026-10-13 12:00:00']);
$oi->execute(['0482/AMI-CO/10/26', 'Sasmita', 'Sasmita', '0812000000', 'Fera', 'approved', '2026-10-16 12:00:00']);
$res = CoNumbers::propose($pdo, '2026-08-04', ['erfandi' => 'erpandi']);
$m = $byNo($res['matched']);
ok('repeat customer: requests paired off in date order, all sure',
    ($m['0480/AMI-CO/10/26']['req']['co_no'] ?? '') === '1400/AMI-CO/10/26' && ($m['0481/AMI-CO/10/26']['req']['co_no'] ?? '') === '1401/AMI-CO/10/26'
    && ($m['0482/AMI-CO/10/26']['req']['co_no'] ?? '') === '1402/AMI-CO/10/26', json_encode(array_map(fn($e) => $e['req']['co_no'] ?? null, $m)));
// Two different shops, similar names, both plausible → a human decides.
$ins->execute([1410, '1410/AMI-CO/10/26', '2026-10-18 09:00:00', 'Ridwan', 'Argani Aluminium', 'Aris', '']);
$ins->execute([1411, '1411/AMI-CO/10/26', '2026-10-18 10:00:00', 'Ridwan', 'Anugerah Aluminium', 'Aris', '']);
$oi->execute(['0483/AMI-CO/10/26', 'Aris', 'Argani Alumunium', '', 'Ridwan', 'approved', '2026-10-18 12:00:00']);
$res = CoNumbers::propose($pdo, '2026-08-04', ['erfandi' => 'erpandi']);
ok('a look-alike shop name does not steal the match', ($byNo($res['matched'])['0483/AMI-CO/10/26']['req']['co_no'] ?? '') === '1410/AMI-CO/10/26');
ok('no candidate → unmatched', count($res['unmatched']) === 1 && $res['unmatched'][0]['order']['order_no'] === '0473/AMI-CO/10/26');
ok('orders before the window are not touched', !isset($m['0300/AMI-CO/07/26']) && !isset($a['0300/AMI-CO/07/26']));
ok('nothing written by propose', (int) $pdo->query('SELECT COUNT(*) FROM co_requests WHERE order_id IS NOT NULL')->fetchColumn() === 0);

// ── 5) Migration: older live DB gains the table and column, idempotently ────
$old = $mem();
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$schema = preg_replace('/-- ── CO number register.*$/s', '', $schema);
$schema = str_replace("    co_no            TEXT,", '', $schema);
$old->exec($schema);
$old->exec("INSERT INTO orders (order_no, customer_name, status) VALUES ('0001/AMI-CO/08/26', 'Keep', 'approved')");
$cols = fn() => array_column($old->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
ok('before: no co_no column, no register', !in_array('co_no', $cols(), true) && !$old->query("SELECT 1 FROM sqlite_master WHERE name='co_requests'")->fetchColumn());
CoNumbers::ensure_schema($old);
CoNumbers::ensure_schema($old);
ok('after (twice): column + table, data kept', in_array('co_no', $cols(), true) && $old->query("SELECT 1 FROM sqlite_master WHERE name='co_requests'")->fetchColumn()
    && (int) $old->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1);

// ── 6) Through the controller: pick a number on creation, link on the show page ──
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'co') . '.sqlite';
    $f = new PDO('sqlite:' . $db);
    $f->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO role_permissions (role, module) VALUES ('sales','orders'), ('finance_manager','orders'), ('finance_manager','finance')");
    $f->exec("INSERT INTO products (sku, name, spec, price, stock) VALUES ('SJ8001', 'Pure White', '4.0*0.21', 300000, 100)");
    $f->exec("INSERT INTO co_requests (seq, co_no, issued_at, sales_name, company, client_name, phone) VALUES (1378, '1378/AMI-CO/10/26', datetime('now','localtime'), 'Ridwan', 'Argani', 'Aris', '08123')");
    $f->exec("INSERT INTO orders (order_no, customer_name, submitter, status) VALUES ('0487/AMI-CO/10/26', 'Old One', 'Ridwan', 'approved')");
    $f = null;
    $run = function (string $action, array $user, array $get = [], ?array $post = null) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn() => new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sales = ['id' => 3, 'name' => 'Ridwan', 'role' => 'sales'];
    $fin = ['id' => 4, 'name' => 'Fin', 'role' => 'finance_manager'];

    [$html] = $run('create', $sales);
    ok('order form offers the requested number and shows the next automatic one', str_contains($html, '1378/AMI-CO/10/26') && str_contains($html, '1379/AMI-CO/'), substr($html, 0, 200));

    $item = ['customer_name' => 'Aris', 'company' => 'Argani', 'sku' => ['SJ8001'], 'color' => ['Pure White'], 'spec' => ['4.0*0.21'], 'size' => [''], 'qty' => [2], 'price' => [300000], 'product_id' => [1], 'do' => 'draft'];
    $run('store', $sales, [], $item + ['co_no' => '1378/AMI-CO/10/26']);
    $o = $q()->query("SELECT order_no, co_no FROM orders WHERE customer_name = 'Aris'")->fetch();
    ok('new order created with the picked form number', $o && $o['order_no'] === '1378/AMI-CO/10/26' && $o['co_no'] === '1378/AMI-CO/10/26', json_encode($o));
    ok('register now points at it', (int) $q()->query('SELECT order_id FROM co_requests WHERE seq = 1378')->fetchColumn() > 0);

    $run('store', $sales, [], array_replace($item, ['customer_name' => 'Second', 'co_no' => '']));
    $o = $q()->query("SELECT order_no, co_no FROM orders WHERE customer_name = 'Second'")->fetch();
    ok('next order gets 1379 automatically, co_no = order_no', $o && str_starts_with($o['order_no'], '1379/AMI-CO/') && $o['co_no'] === $o['order_no'], json_encode($o));

    [, $fl] = $run('store', $sales, [], array_replace($item, ['customer_name' => 'Third', 'co_no' => '1378/AMI-CO/10/26']));
    ok('a taken number is refused with a message', ($fl[0]['type'] ?? '') === 'error' && !$q()->query("SELECT 1 FROM orders WHERE customer_name = 'Third'")->fetchColumn());

    $oldId = (int) $q()->query("SELECT id FROM orders WHERE order_no = '0487/AMI-CO/10/26'")->fetchColumn();
    $q()->exec("INSERT INTO co_requests (seq, co_no, issued_at, sales_name, company) VALUES (1377, '1377/AMI-CO/10/26', datetime('now','localtime'), 'Ridwan', 'Old One')");
    [, $fl] = $run('link_co', $fin, [], ['id' => $oldId, 'co_no' => '1377/AMI-CO/10/26']);
    $o = $q()->query("SELECT order_no, co_no FROM orders WHERE id = $oldId")->fetch();
    ok('finance links an old shipped order without renaming it', $o['order_no'] === '0487/AMI-CO/10/26' && $o['co_no'] === '1377/AMI-CO/10/26', json_encode([$o, $fl]));
    [$html] = $run('show', $fin, ['id' => $oldId]);
    ok('show page displays the CO number as the form number', str_contains($html, '1377/AMI-CO/10/26') && str_contains($html, t('co_form_number')));
    [$html] = $run('co_index', $fin, ['q' => '1377']);
    ok('register page lists the number with its order', str_contains($html, '1377/AMI-CO/10/26') && str_contains($html, '0487/AMI-CO/10/26'));
    [$html] = $run('index', $fin, ['q' => '1377']);
    ok('order list finds an order by its CO number', str_contains($html, '0487/AMI-CO/10/26'));
    @unlink($db);
}
