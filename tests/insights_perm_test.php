<?php

// ── 第四部分 权限: who sees what in the customer analysis, enforced server-side ──

require_once __DIR__ . '/../app/Csrf.php';

// 1) The rules, against the permission matrix.
$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$GLOBALS['permissions'] = ['sales' => ['insights'], 'supervisor' => ['customers', 'insights', 'insights_all'], 'clerk' => ['customers', 'insights']];
$as = function (string $name, string $role): void {
    $GLOBALS['auth'] = new AuthStub(['id' => 1, 'name' => $name, 'role' => $role]);
};
$mine = ['owner' => 'ridwan'];
$theirs = ['owner' => 'rian'];

$as('ridwan', 'sales');
ok('sales: 回访清单 yes, 客户管理 no (as configured on the live system)', can_access('insights') && !can_access('customers'));
ok('sales: not the manager view', !insights_sees_all());
ok('sales: may act on own customer', insights_can_touch($mine));
ok("sales: may not act on another salesperson's customer", !insights_can_touch($theirs));
ok('sales: owner match ignores case and spaces', insights_can_touch(['owner' => ' Ridwan ']));
ok('sales: a customer with no owner is not "mine"', !insights_can_touch(['owner' => '']));
$as('', 'sales');
ok('a user with an empty name owns nothing (no match on empty owner)', !insights_can_touch(['owner' => '']));

$as('Deni', 'supervisor');
ok('supervisor with 全部客户分析: everything', insights_sees_all() && insights_can_touch($theirs));
$GLOBALS['permissions']['supervisor'] = ['customers', 'insights'];
ok('…and nothing beyond own once an admin unticks 全部客户分析', !insights_sees_all() && !insights_can_touch($theirs));

$as('Fera', 'clerk');
ok('clerk (销售助理): own customers only', !insights_sees_all() && insights_can_touch(['owner' => 'Fera']) && !insights_can_touch($theirs));
$as('Administrator', 'admin');
ok('admin: everything, regardless of the matrix', insights_sees_all() && insights_can_touch($theirs));
ok('全部客户分析 is a configurable permission in the matrix', in_array('insights_all', permission_keys(), true) && isset(I18N['zh']['nav_insights_all'], I18N['id']['nav_insights_all']));
$GLOBALS['auth'] = $savedAuth;
$GLOBALS['permissions'] = $savedPerms;

// 2) Call lists are read-only: nothing but the nightly computation writes them.
$writers = [];
foreach (array_merge(glob(__DIR__ . '/../app/*.php') ?: [], glob(__DIR__ . '/../app/controllers/*.php') ?: [], glob(__DIR__ . '/../tools/*.php') ?: []) as $f) {
    if (preg_match('/(INSERT\s+(OR\s+\w+\s+)?INTO|UPDATE|DELETE\s+FROM)\s+customer_insights\b/i', (string) file_get_contents($f))) {
        $writers[] = basename($f);
    }
}
ok('only Insights.php writes customer_insights (lists are read-only to users)', $writers === ['Insights.php'], implode(',', $writers));

// 3) Through the real controllers, with a permission matrix like production.
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'insperm');
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec($schemaSql);
    $p->exec("INSERT INTO role_permissions (role, module) VALUES ('sales','inventory'),('sales','insights'),('supervisor','customers'),('supervisor','insights'),('supervisor','insights_all')");
    $today = date('Y-m-d');
    $p->exec("INSERT INTO customers (id, name, owner, industry, industry_source, industry_pending, phone) VALUES
        (1, 'Ridwan Shop', 'ridwan', 'other', 'ai', 1, '0812000111'), (2, 'Rian Shop', 'rian', 'other', 'ai', 1, '0812000222')");
    foreach ([1, 2] as $cid) {
        $p->prepare("INSERT INTO customer_insights (customer_id, computed_on, last_order_date, order_count, purchase_days, total_amount, avg_interval, interval_source, days_since, in_a, in_b, in_c, overdue_days)
            VALUES (?, ?, ?, 2, 2, 9000000, 30, 'own', 30, 1, 0, 0, 0)")->execute([$cid, $today, $today]);
    }
    $p->exec("INSERT INTO app_meta (k, v) VALUES ('insights_computed_on', '{$today}')");
    $p = null;
    $run = function (string $ctl, string $action, array $get, array $user, ?array $post = null) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode($get), json_encode($user)];
        if ($post !== null) {
            $args[] = json_encode($post);
        }
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $ridwan = ['id' => 9, 'name' => 'ridwan', 'role' => 'sales'];
    $deni = ['id' => 7, 'name' => 'Deni', 'role' => 'supervisor'];

    [$h] = $run('insights', 'index', [], $ridwan);
    ok('sales list: own customer only', str_contains($h, 'Ridwan Shop') && !str_contains($h, 'Rian Shop'));
    ok('sales list: no link into 客户管理 (they have no access)', !str_contains($h, 'customers.show'));
    ok('sales list: details panel with industry confirm, posting to insights.*', str_contains($h, t('ins_details')) && str_contains($h, 'insights.set_industry') && str_contains($h, 'insights.followup') && !str_contains($h, 'customers.followup'));
    ok('sales list: no 12-month totals across customers', !str_contains($h, t('ins_activity')) && !str_contains($h, t('ins_by_industry')));

    // Tampered requests: another salesperson's customer id.
    [, $fl] = $run('insights', 'followup', [], $ridwan, ['id' => 2, 'note' => 'sneaky']);
    ok("follow-up on someone else's customer is refused server-side", ($fl[0]['type'] ?? '') === 'error' && $q("SELECT COUNT(*) n FROM customer_followups")[0]['n'] === 0);
    [, $fl] = $run('insights', 'no_need', [], $ridwan, ['id' => 2, 'note' => 'sneaky']);
    ok("…so is 无需求", $q("SELECT COUNT(*) n FROM customer_followups")[0]['n'] === 0);
    [, $fl] = $run('insights', 'set_industry', [], $ridwan, ['id' => 2, 'industry' => 'contractor']);
    ok("…and changing their industry", $q('SELECT industry_source FROM customers WHERE id = 2')[0]['industry_source'] === 'ai');
    [, $fl] = $run('insights', 'followup', [], $ridwan, ['id' => 999, 'note' => 'ghost']);
    ok('a non-existent customer id is refused', ($fl[0]['type'] ?? '') === 'error');
    [, $fl] = $run('insights', 'admin', ['owner' => 'rian'], $ridwan);
    ok('sales cannot reach the manager view even with a crafted URL', ($fl[0]['type'] ?? '') === 'error');
    [$h] = $run('insights', 'index', ['owner' => 'rian'], $ridwan);
    ok('an owner= parameter cannot widen the sales list', !str_contains($h, 'Rian Shop'));

    // Legit writes from the list.
    [, $fl] = $run('insights', 'set_industry', [], $ridwan, ['id' => 1, 'industry' => 'aluminium_shop', 'tab' => 'a']);
    ok('sales confirms own customer industry from the list → manual + audited', $q('SELECT industry, industry_source FROM customers WHERE id = 1')[0] === ['industry' => 'aluminium_shop', 'industry_source' => 'manual']
        && count($q("SELECT * FROM audit_log WHERE entity_id = 1 AND detail LIKE '%人工确认%'")) === 1);
    [, $fl] = $run('insights', 'set_industry', [], $ridwan, ['id' => 1, 'industry' => 'pirate']);
    ok('an industry outside the list is refused', ($fl[0]['type'] ?? '') === 'error' && $q('SELECT industry FROM customers WHERE id = 1')[0]['industry'] === 'aluminium_shop');
    [, $fl] = $run('insights', 'followup', [], $ridwan, ['id' => 1, 'note' => 'WA ok']);
    ok('sales logs a follow-up on own customer from the list', count($q("SELECT * FROM customer_followups WHERE customer_id = 1 AND user_name = 'ridwan'")) === 1);
    [$h] = $run('insights', 'index', [], $ridwan);
    ok('…and it leaves the list at once', !str_contains($h, 'Ridwan Shop'));
    [, $fl] = $run('insights', 'followup', [], $ridwan, ['id' => 1, 'note' => '   ']);
    ok('blank notes are refused', ($fl[0]['type'] ?? '') === 'error');

    // Supervisor: everyone, from the manager view; posts come back there.
    [$h] = $run('insights', 'admin', [], $deni);
    ok('supervisor sees every salesperson in the manager view', str_contains($h, 'Rian Shop') && str_contains($h, 'name="back" value="insights_admin"'));
    [, $fl] = $run('insights', 'followup', [], $deni, ['id' => 2, 'note' => 'visited', 'back' => 'insights_admin']);
    ok("supervisor may log a follow-up on any salesperson's customer", count($q("SELECT * FROM customer_followups WHERE customer_id = 2 AND user_name = 'Deni'")) === 1);
    @unlink($db);
}
