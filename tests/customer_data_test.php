<?php

// ── Customer-data hygiene (app/CustomerData.php) + the 数据整理 page + industry confirm ──

require_once __DIR__ . '/../app/Csrf.php';

// 1) Phones.
$phones = [
    ['0857-1671-2364', '085716712364', ''],
    ['+62 811-1581-949', '08111581949', ''],
    ['628129813257', '08129813257', ''],
    ['812345678', '0812345678', ''],
    ['0811190014', '0811190014', ''],
    ['wechat', '', 'wechat'],
    ['we chat', '', 'we chat'],
    ['Bekasi Jawa Barat', '', 'Bekasi Jawa Barat'],
    ['081234567890 / 081399988877', '081234567890', '081399988877'],
    ['12', '', '12'],
    ['', '', ''],
];
$bad = [];
foreach ($phones as [$in, $phone, $moved]) {
    $got = CustomerData::normalize_phone($in);
    if ($got !== [$phone, $moved]) {
        $bad[] = "{$in} → " . json_encode($got);
    }
}
ok('phone normalisation (08xx; non-numbers and extra numbers move to the note)', $bad === [], implode(' | ', $bad));
ok('note append is idempotent', CustomerData::append_note(CustomerData::append_note('NPWP 1', '原电话栏: wechat'), '原电话栏: wechat') === "NPWP 1\n原电话栏: wechat");

// 2) Name variants.
$variants = ['Fera' => 70, 'fera' => 45, ' ridwan ' => 1, 'ridwan' => 149, 'erpandi' => 52, 'Mr Fang' => 35, 'calvin' => 3, 'Calvin' => 1];
$m = CustomerData::case_merges($variants, ['Fera', 'ridwan', 'erfandi']);
ok('case/space variants → the account spelling', ($m['fera'] ?? '') === 'Fera' && ($m[' ridwan '] ?? '') === 'ridwan');
ok('no account → the most used spelling', ($m['Calvin'] ?? '') === 'calvin');
ok('different names are never auto-merged', !isset($m['erpandi']) && !isset($m['Mr Fang']) && !isset($m['Fera']));

$pairs = CustomerData::similar_pairs(['erpandi', 'erfandi', 'Fera', 'ridwan', 'rian', 'Mr Fang', 'Fang', 'rusdi', 'tania', 'antonni']);
$keys = array_map(fn($p) => CustomerData::pair_key($p[0], $p[1]), $pairs);
ok('erpandi ↔ erfandi suspected', in_array('erfandi|erpandi', $keys, true));
ok('"Mr Fang" ↔ "Fang" suspected', in_array('fang|mr fang', $keys, true));
ok('rian ↔ ridwan are different people (short names need distance 1)', !in_array('rian|ridwan', $keys, true), implode(',', $keys));
ok('a dismissed pair is not suggested again', !in_array('erfandi|erpandi', array_map(fn($p) => CustomerData::pair_key($p[0], $p[1]),
    CustomerData::similar_pairs(['erpandi', 'erfandi'], ['erfandi|erpandi'])), true));

// 3) Against a database.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);
$pdo->exec("INSERT INTO customers (id, name, owner) VALUES (1,'A','erpandi'),(2,'B','Erpandi '),(3,'C','erfandi'),(4,'D',''),(5,'E','')");
$pdo->exec("INSERT INTO orders (order_no, customer_id, submitter, status) VALUES
    ('S1',1,'erpandi','approved'),('S2',4,'rian','approved'),('S3',4,'rian','approved'),('S4',4,'angga','approved'),
    ('S5',5,'angga','approved'),('S6',5,'rian','approved')");
$n = CustomerData::rename_salesperson($pdo, 'erpandi', 'erfandi');
ok('rename covers customers and orders, any case/spacing', $n === ['customers' => 2, 'orders' => 1]);
ok('nothing left under the old name', (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE LOWER(TRIM(owner)) = 'erpandi'")->fetchColumn() === 0);
$sg = CustomerData::suggested_owners($pdo);
ok('suggested owner = most frequent salesperson on the orders', ($sg[4] ?? '') === 'rian');
ok('suggestion tie → the most recent order', ($sg[5] ?? '') === 'rian');

// 4) The pages and actions, through the real controller (one process each).
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'cdtest');
    $f = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $f->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);
    $f->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1,'Admin','a@x','x','admin'),(9,'erfandi','e@x','x','sales'),(10,'rian','r@x','x','sales')");
    $f->exec("INSERT INTO role_permissions (role, module) VALUES ('sales','customers')");
    $f->exec("INSERT INTO customers (id, name, company, owner, industry, industry_source, industry_confidence, industry_pending, industry_note, region, delivery_regions)
        VALUES (1,'Andi','PT Andi','erpandi','renovation','ai',0.55,1,'公司名像装修','jkt_utara','{\"jkt_utara\":2,\"bekasi\":1}'),
               (2,'Budi','','erfandi',NULL,NULL,NULL,0,NULL,NULL,NULL),
               (3,'Citra','','',NULL,NULL,NULL,0,NULL,NULL,NULL)");
    $f->exec("INSERT INTO orders (order_no, customer_id, submitter, status) VALUES ('S1',3,'rian','approved'),('S2',1,'erpandi','approved')");
    $f = null;

    $run = function (string $action, array $get, array $user, ?array $post = null) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'customers', $action, json_encode($get), json_encode($user)];
        if ($post !== null) {
            $args[] = json_encode($post);
        }
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: [], $rc];
    };
    $q = function (string $sql) use ($db) {
        $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        return $p->query($sql)->fetchAll();
    };
    $admin = ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];
    $erfandi = ['id' => 9, 'name' => 'erfandi', 'role' => 'sales'];
    $rian = ['id' => 10, 'name' => 'rian', 'role' => 'sales'];

    [$h] = $run('show', ['id' => 1], $admin);
    ok('detail page shows the inferred industry, its source and 待确认', str_contains($h, t('ind_renovation')) && str_contains($h, t('indsrc_ai')) && str_contains($h, t('ind_pending')) && str_contains($h, '55%'));
    ok('detail page shows region and delivery spread', str_contains($h, t('reg_jkt_utara')) && str_contains($h, t('reg_delivery_spread')));
    ok('detail page offers the confirm form', str_contains($h, 'customers.set_industry') && str_contains($h, 'name="industry"'));
    [$h] = $run('show', ['id' => 2], $erfandi);
    ok('no industry yet → a "choose" prompt; no region → "no shipped order"', str_contains($h, t('ind_choose')) && str_contains($h, t('reg_none')));

    // Confirming makes it manual.
    [, $fl] = $run('set_industry', [], $admin, ['id' => 1, 'industry' => 'contractor']);
    $row = $q('SELECT industry, industry_source, industry_pending FROM customers WHERE id = 1')[0];
    ok('confirm → manual, no longer pending', $row === ['industry' => 'contractor', 'industry_source' => 'manual', 'industry_pending' => 0], json_encode($row));
    ok('confirm is audited', count($q("SELECT * FROM audit_log WHERE action = 'update' AND entity_id = 1 AND detail LIKE '%人工确认%'")) === 1);
    [, $fl] = $run('set_industry', [], $admin, ['id' => 1, 'industry' => 'bakery']);
    ok('an industry outside the list is rejected', ($fl[0]['type'] ?? '') === 'error' && $q('SELECT industry FROM customers WHERE id = 1')[0]['industry'] === 'contractor');
    [, $fl] = $run('set_industry', [], $rian, ['id' => 2, 'industry' => 'other']);
    ok("a salesperson cannot set another's customer", $q('SELECT industry FROM customers WHERE id = 2')[0]['industry'] === null);
    [, $fl] = $run('set_industry', [], $erfandi, ['id' => 2, 'industry' => 'end_owner']);
    ok('the owner can', $q('SELECT industry_source FROM customers WHERE id = 2')[0]['industry_source'] === 'manual');

    // 数据整理 is admin-only.
    [$h] = $run('cleanup', [], $erfandi);
    ok('sales cannot open 数据整理', !str_contains($h, t('cleanup_pairs')));
    [, $fl] = $run('merge_owner', [], $erfandi, ['from' => 'erpandi', 'to' => 'erfandi']);
    ok('sales cannot merge names', $q("SELECT owner FROM customers WHERE id = 1")[0]['owner'] === 'erpandi');
    [$h] = $run('cleanup', [], $admin);
    ok('admin sees the suspected pair', str_contains($h, t('cleanup_pairs')) && str_contains($h, 'erpandi') && str_contains($h, 'erfandi'));
    ok('admin sees the unowned customer with a suggestion', str_contains($h, 'Citra') && str_contains($h, '>rian<'));

    [, $fl] = $run('merge_owner', [], $admin, ['from' => 'erpandi', 'to' => 'erfandi']);
    ok('merge renames customers and orders', $q("SELECT owner FROM customers WHERE id = 1")[0]['owner'] === 'erfandi'
        && $q("SELECT submitter FROM orders WHERE order_no = 'S2'")[0]['submitter'] === 'erfandi');
    ok('merge is audited', count($q("SELECT * FROM audit_log WHERE action = 'merge'")) === 1);
    [, $fl] = $run('merge_owner', [], $admin, ['from' => 'rian', 'to' => 'RIAN']);
    ok('merging a name into itself is refused', ($fl[0]['type'] ?? '') === 'error');

    [, $fl] = $run('assign', [], $admin, ['ids' => [3], 'mode' => 'suggest']);
    ok('assign by suggestion', $q('SELECT owner FROM customers WHERE id = 3')[0]['owner'] === 'rian');
    [, $fl] = $run('assign', [], $admin, ['ids' => [2, 3], 'mode' => 'target', 'owner_to' => 'Admin']);
    ok('bulk assign to a chosen person', count($q("SELECT * FROM customers WHERE id IN (2,3) AND owner = 'Admin'")) === 2);
    ok('each assignment is audited', count($q("SELECT * FROM audit_log WHERE action = 'assign'")) === 3);
    [, $fl] = $run('assign', [], $admin, ['ids' => [], 'mode' => 'target', 'owner_to' => 'Admin']);
    ok('nothing ticked → a clear error', ($fl[0]['type'] ?? '') === 'error');

    [, $fl] = $run('ignore_pair', [], $admin, ['a' => 'Admin', 'b' => 'admin2']);
    ok('dismissed pairs are remembered', str_contains((string) ($q("SELECT v FROM app_meta WHERE k = 'salesname_ignored'")[0]['v'] ?? ''), 'admin|admin2'));
    @unlink($db);
}

// 5) i18n completeness for everything this feature shows.
$need = array_merge(
    array_map(fn($c) => 'ind_' . $c, Enrich::INDUSTRIES),
    array_map(fn($r) => 'reg_' . $r, Enrich::REGIONS),
    ['indsrc_rule', 'indsrc_ai', 'indsrc_manual', 'cust_analysis', 'th_industry', 'th_region', 'ind_pending', 'ind_confirm',
     'cleanup_title', 'cleanup_pairs', 'cleanup_assign', 'msg_merged', 'msg_assigned', 'ai_budget_hit',
     'audit_act_merge', 'audit_act_assign', 'audit_act_enrich', 'audit_act_cleanup']
);
$missing = array_values(array_filter($need, fn($k) => !isset(I18N['zh'][$k], I18N['id'][$k])));
ok('every new label exists in Chinese and Indonesian', $missing === [], implode(',', $missing));
ok('new audit actions are filterable', count(array_intersect(['merge', 'assign', 'enrich', 'cleanup'], audit_actions())) === 4);
