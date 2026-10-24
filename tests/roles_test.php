<?php

// ── Roles 财务助理 / 销售助理 / 销售经理 (2026-10-24) ──
// In the user form and the permission matrix; default permissions seeded once
// on an existing database; the sales manager approves at the supervisor
// level next to the supervisor (either is enough) and is told about it.

ok('three new roles exist, labelled in both languages', count(array_intersect(['finance_assistant', 'sales_assistant', 'sales_manager'], all_roles())) === 3
    && I18N['zh']['role_sales_manager'] === '销售经理' && I18N['zh']['role_sales_assistant'] === '销售助理' && I18N['zh']['role_finance_assistant'] === '财务助理'
    && isset(I18N['id']['role_sales_manager'], I18N['id']['role_sales_assistant'], I18N['id']['role_finance_assistant']));
ok('supervisor stage: supervisor or sales manager; other stages unchanged', order_action_roles('pending_sup') === ['supervisor', 'sales_manager']
    && order_action_roles('pending_mgr') === ['manager'] && order_action_roles('pending_wh') === ['warehouse'] && order_action_roles('approved') === []);
ok('stage label names both', order_stage_label('pending_sup') === role_label('supervisor') . ' / ' . role_label('sales_manager'));
$d = default_permissions();
ok('sales assistant: orders, customers, visit import; not finance, not everyone\'s visits', in_array('orders', $d['sales_assistant'], true) && in_array('visits_import', $d['sales_assistant'], true)
    && !in_array('finance', $d['sales_assistant'], true) && !in_array('visits_all', $d['sales_assistant'], true));
ok('finance assistant: finance, no AI usage page, no team performance', in_array('finance', $d['finance_assistant'], true) && !in_array('ai_usage', $d['finance_assistant'], true) && !in_array('performance', $d['finance_assistant'], true));
ok('sales manager: all customer analysis, all visits, performance, export; no finance', !array_diff(['insights_all', 'visits_all', 'performance', 'export', 'orders'], $d['sales_manager']) && !in_array('finance', $d['sales_manager'], true));

// existing database: seeded once, an admin's later change is kept
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Admin', 'admin@alupanel.local', 'x', 'admin')");
$pdo->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),('pwd_policy_v1','1'),('perm_approvals','1'),('perm_insights','1'),('perm_insights_v2','1'),('perm_hr_v1','1'),('hr_employees_v1','1'),('visits_v1','1'),('perm_ai_usage_v1','1')");
Database::migrate($pdo);
$has = fn(string $role) => $pdo->query("SELECT module FROM role_permissions WHERE role = " . $pdo->quote($role) . ' ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$sorted = function (array $a) { sort($a); return $a; };
ok('existing database: defaults seeded for the new roles', $has('sales_assistant') === $sorted($d['sales_assistant']) && $has('sales_manager') === $sorted($d['sales_manager']) && $has('finance_assistant') === $sorted($d['finance_assistant']));
$pdo->exec("DELETE FROM role_permissions WHERE role = 'sales_assistant' AND module = 'inventory'");
Database::migrate($pdo);
ok('…once: removing a permission later sticks', !in_array('inventory', $has('sales_assistant'), true));

// approval and notification through the real controller
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'rol');
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $p->exec("INSERT INTO users (id, name, email, password_hash, role, phone) VALUES (1,'Admin','a@t','x','admin',NULL),(2,'Sari','s@t','x','supervisor','628111'),(3,'Joko','j@t','x','sales_manager','628222'),(4,'Mgr','m@t','x','manager','628333'),(5,'Rina','r@t','x','sales',NULL)");
    $p->exec("INSERT INTO orders (id, order_no, customer_name, status, submitter) VALUES (1, '2001', 'Toko A', 'pending_sup', 'Rina'), (2, '2002', 'Toko B', 'pending_mgr', 'Rina')");
    $p = null;
    $run = function (string $action, array $user, array $get, ?array $post) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get), json_encode($user), $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $joko = ['real' => true, 'id' => 3];
    [$h] = $run('show', $joko, ['id' => 1], null);
    ok('sales manager sees the approve buttons at the supervisor stage', str_contains($h, 'r=orders.approve') && str_contains($h, e(order_stage_label('pending_sup'))));
    [$h2] = $run('show', $joko, ['id' => 2], null);
    ok('…but not at the manager stage (waits for 经理)', !str_contains($h2, 'r=orders.approve') && str_contains($h2, t('wait_for')));
    $q = fn() => new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    notify_order_stage($q(), ['id' => 1, 'order_no' => '2001', 'customer_name' => 'Toko A'], 'pending_sup');
    $who = $q()->query("SELECT GROUP_CONCAT(user_id) FROM (SELECT user_id FROM notifications WHERE entity_id = 1 ORDER BY user_id)")->fetchColumn();
    ok('the supervisor-stage notice goes to the supervisor and the sales manager', $who === '2,3', (string) $who);
    @unlink($db);
}
