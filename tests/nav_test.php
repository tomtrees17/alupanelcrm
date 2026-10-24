<?php

// ── 客户 / Pelanggan: one top-level entry, tabs over the existing pages (2026-10-24) ──
// Tabs follow the permissions the pages already check; a salesperson on the
// phone lands on 拜访; nothing else in the sidebar changes.

ok('tab of a route', customer_tab_of('customers.show') === 'list' && customer_tab_of('insights.index') === 'insights' && customer_tab_of('insights.admin') === 'analysis'
    && customer_tab_of('visits.day') === 'visits' && customer_tab_of('visits.prospects') === 'prospects' && customer_tab_of('orders.index') === null);
$saved = $_SERVER['HTTP_USER_AGENT'] ?? null;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; SM-A156E) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36';
ok('phone browsers recognised', is_mobile_ua());
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15';
ok('desktop is not', !is_mobile_ua());
if ($saved === null) {
    unset($_SERVER['HTTP_USER_AGENT']);
} else {
    $_SERVER['HTTP_USER_AGENT'] = $saved;
}

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'nav');
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec((string) @file_get_contents(__DIR__ . '/../database/schema.sql'));
    $p->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1,'Administrator','a@t','x','admin'),(3,'tania','t@t','x','manager'),(4,'ridwan','r@t','x','sales'),(6,'liuxian','l@t','x','warehouse')");
    foreach (default_permissions() as $role => $mods) {
        foreach ($mods as $mod) {
            $p->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
        }
    }
    $p->exec("DELETE FROM role_permissions WHERE role = 'sales' AND module = 'customers'");   // as in production: sales have no 客户管理
    Hr::migrate_users($p);
    $emp = fn(string $n) => (int) $p->query("SELECT employee_id FROM users WHERE name = '{$n}'")->fetchColumn();
    $tania = ['id' => 3, 'name' => 'tania', 'role' => 'manager', 'employee_id' => $emp('tania')];
    $ridwan = ['id' => 4, 'name' => 'ridwan', 'role' => 'sales', 'employee_id' => $emp('ridwan')];
    $liu = ['id' => 6, 'name' => 'liuxian', 'role' => 'warehouse', 'employee_id' => $emp('liuxian')];
    $p = null;
    $run = function (string $ctl, string $action, array $user, array $env = []) use ($db, $harness): string {
        $envs = '';
        foreach ($env as $k => $v) {
            $envs .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode(['r' => "{$ctl}.{$action}"]), json_encode($user)];   // the layout reads the route from r
        $out = [];
        exec($envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $tabs = function (string $html): array {
        preg_match_all('/<a class="group-tab( active)?" href="index\.php\?r=([a-z_.]+)"/', $html, $m, PREG_SET_ORDER);
        return array_map(fn($x) => ($x[1] ? '*' : '') . $x[2], $m);
    };
    $entry = function (string $html): ?string {
        return preg_match('/<a class="nav-item[^"]*" href="index\.php\?r=([a-z_.]+)"><span class="nav-icon">◎<\/span>/u', $html, $m) ? $m[1] : null;
    };
    $h = $run('customers', 'index', $tania);
    ok('manager: one 客户 entry, five tabs in order, the list active', $tabs($h) === ['*customers.index', 'insights.index', 'visits.index', 'visits.prospects', 'insights.admin'], json_encode($tabs($h)));
    ok('the old separate sidebar items are gone', !str_contains($h, 'href="index.php?r=insights.index"><span class="nav-icon">') && !str_contains($h, 'href="index.php?r=visits.index"><span class="nav-icon">')
        && $entry($h) === 'customers.index');
    $h = $run('visits', 'list', $tania);
    ok('a visits page sits under the 拜访 tab, its own sub-tabs below without 潜客', in_array('*visits.index', $tabs($h), true) && !str_contains($h, 'class="filter-btn " href="index.php?r=visits.prospects"'));
    ok('…潜客 tab active on the prospect page', in_array('*visits.prospects', $tabs($run('visits', 'prospects', $tania)), true));
    $desk = $run('visits', 'index', $ridwan, ['TEST_HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128.0']);
    ok('salesperson: only the tabs their permissions allow (回访清单, 拜访)', $tabs($desk) === ['insights.index', '*visits.index'], json_encode($tabs($desk)));
    ok('salesperson on a computer: 客户 opens the first tab', $entry($desk) === 'insights.index');
    $phone = $run('visits', 'index', $ridwan, ['TEST_HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Mobile/15E148 Safari/604.1']);
    ok('salesperson on the phone: 客户 opens 拜访', $entry($phone) === 'visits.index');
    $w = $run('inventory', 'index', $liu);
    ok('warehouse (none of the five): no 客户 entry', $entry($w) === null && !str_contains($w, 'group-tab'));
    @unlink($db);
}
