<?php

// ── Call lists A/B/C (app/Insights.php) ──

require_once __DIR__ . '/../app/Csrf.php';

$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$mk = function () use ($schemaSql): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec($schemaSql);
    return $p;
};
$cfg = ['insights' => ((array) (@include __DIR__ . '/../config.php'))['insights'] ?? []];
$today = '2026-12-31';
$ago = fn(int $d): string => date('Y-m-d', strtotime($today . " -{$d} days"));

$pdo = $mk();
$cust = function (string $name, string $owner = 'rina', string $industry = 'aluminium_shop') use ($pdo): int {
    $pdo->prepare('INSERT INTO customers (name, owner, industry) VALUES (?,?,?)')->execute([$name, $owner, $industry]);
    return (int) $pdo->lastInsertId();
};
/** A shipped order with a DO picked up on $day; $amount as one line. */
$buy = function (int $cid, string $day, float $amount, string $status = 'approved', ?string $pickup = null, string $sku = 'SJ8010', float $qty = 10) use ($pdo): int {
    $pdo->prepare('INSERT INTO orders (order_no, customer_id, status, created_at, shipping_cost) VALUES (?,?,?,?,0)')
        ->execute(['SO-' . uniqid('', true), $cid, $status, $day . ' 09:00:00']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, sku, spec, qty, price) VALUES (?, ?, '3.0*0.06', ?, ?)")->execute([$oid, $sku, $qty, $amount / $qty]);
    if ($status === 'approved') {
        $pdo->prepare('INSERT INTO delivery_orders (do_no, order_id, pickup_date) VALUES (?,?,?)')->execute(['DO-' . uniqid('', true), $oid, $pickup ?? $day]);
    }
    return $oid;
};

// The spec's three acceptance customers.
$cA = $cust('Every60');                       // bought 3× every 60 days, last 60 days ago
foreach ([180, 120, 60] as $d) {
    $buy($cA, $ago($d), 5_000_000);
}
$cB = $cust('TwiceThenGone');                 // bought twice 30 days apart, nothing for 150 days
$buy($cB, $ago(180), 2_000_000);
$buy($cB, $ago(150), 2_000_000);
$cC = $cust('BigIdle');                       // big spender, idle 100 days
$buy($cC, $ago(100), 90_000_000);
// Background customers: modest spend, recent, regular — give C its top-20% context.
for ($i = 1; $i <= 12; $i++) {
    $x = $cust('Small' . $i);
    $buy($x, $ago(40), 3_000_000);
    $buy($x, $ago(10), 3_000_000);
}

$s = Insights::compute($pdo, $cfg, $today);
$row = fn(int $cid): array => $pdo->query("SELECT * FROM customer_insights WHERE customer_id = {$cid}")->fetch();
$in = fn(int $cid): string => implode('', array_filter(['a', 'b', 'c'], fn($l) => (int) $row($cid)['in_' . $l] === 1));

ok('A: every 60 days, last 60 days ago → 该回访 only', $in($cA) === 'a', $in($cA));
ok('A: usual interval 60 from own history', (float) $row($cA)['avg_interval'] === 60.0 && $row($cA)['interval_source'] === 'own');
ok('B: twice then 150 days → 快流失 only', $in($cB) === 'b', $in($cB));
ok('B: overdue = 150 − 30 = 120 days, and red (> 2 × 30)', (int) $row($cB)['overdue_days'] === 120 && (int) $row($cB)['overdue_red'] === 1);
ok('C: big spender idle 100 days → 值得多跑', str_contains($in($cC), 'c'), $in($cC));
ok('C: single purchase → interval from the industry median', $row($cC)['interval_source'] === 'industry' && (float) $row($cC)['avg_interval'] === 30.0);
ok('background customers (bought 10 days ago, every 30) are in no list', $in($cA + 3) === '');
ok('counts reported', $s['a'] >= 1 && $s['b'] >= 1 && $s['c'] >= 1 && $s['customers'] === 15);
ok('12-month amount and order count', (float) $row($cA)['total_amount'] === 15_000_000.0 && (int) $row($cA)['order_count'] === 3);
ok('last purchase items recorded (SKU + spec × qty)', $row($cA)['last_items'] === 'SJ8010 3.0*0.06 ×10');

$lists = Insights::lists($pdo, $cfg, $today);
ok('lists split by A/B/C', array_column($lists['a'], 'customer_id') === [$cA] && array_column($lists['b'], 'customer_id') === [$cB] && in_array($cC, array_column($lists['c'], 'customer_id'), true));

// Boundaries of A: 0.9 × and 1.5 × the interval, inclusive.
foreach ([[53, ''], [54, 'a'], [90, 'a'], [91, 'b']] as [$since, $want]) {
    $p2 = $mk();
    $p2->exec("INSERT INTO customers (id, name, owner, industry) VALUES (1, 'X', 'r', 'other')");
    foreach ([$since + 120, $since + 60, $since] as $k => $d) {
        $p2->prepare("INSERT INTO orders (id, order_no, customer_id, status, created_at) VALUES (?, ?, 1, 'approved', ?)")->execute([$k + 1, "S{$k}", $ago($d) . ' 09:00:00']);
        $p2->prepare("INSERT INTO order_items (order_id, sku, qty, price) VALUES (?, 'A', 1, 1000)")->execute([$k + 1]);
    }
    Insights::compute($p2, $cfg, $today);
    $r = $p2->query('SELECT * FROM customer_insights')->fetch();
    $got = implode('', array_filter(['a', 'b'], fn($l) => (int) $r['in_' . $l] === 1));
    if ($got !== $want) {
        ok("interval 60, {$since} days since → '{$want}'", false, $got);
    }
}
ok('A window is [0.9, 1.5] × interval, B beyond it (54..90 → A, 91 → B, 53 → none)', true);

// Purchase date = delivery pickup date; same-day orders are one purchase day.
$p3 = $mk();
$p3->exec("INSERT INTO customers (id, name, owner, industry) VALUES (1, 'Y', 'r', 'other')");
$p3->exec("INSERT INTO orders (id, order_no, customer_id, status, created_at) VALUES
    (1,'S1',1,'approved','2026-11-01 09:00:00'),(2,'S2',1,'approved','2026-11-01 15:00:00'),(3,'S3',1,'approved','2026-11-21 09:00:00'),(4,'S4',1,'draft','2026-12-30 09:00:00')");
$p3->exec("INSERT INTO order_items (order_id, sku, qty, price) VALUES (1,'A',1,100),(2,'A',1,100),(3,'A',1,100),(4,'A',1,100)");
$p3->exec("INSERT INTO delivery_orders (do_no, order_id, pickup_date) VALUES ('D3', 3, '2026-12-01')");
Insights::compute($p3, $cfg, $today);
$r = $p3->query('SELECT * FROM customer_insights')->fetch();
ok('two orders on one day are one purchase day (no 0-day interval)', (int) $r['purchase_days'] === 2 && (float) $r['avg_interval'] === 30.0, json_encode($r));
ok('purchase date is the delivery pickup date (12-01), not the order date (11-21)', $r['last_order_date'] === '2026-12-01');
ok('drafts are not purchases', (int) $r['order_count'] === 3);

// 12-month window for amount; older purchases still count for rhythm.
$p4 = $mk();
$p4->exec("INSERT INTO customers (id, name, owner, industry) VALUES (1, 'Z', 'r', 'other')");
$p4->exec("INSERT INTO orders (id, order_no, customer_id, status, created_at) VALUES (1,'S1',1,'approved','2025-06-01 09:00:00'),(2,'S2',1,'approved','2026-12-01 09:00:00')");
$p4->exec("INSERT INTO order_items (order_id, sku, qty, price) VALUES (1,'A',1,7000000),(2,'A',1,1000000)");
Insights::compute($p4, $cfg, $today);
$r = $p4->query('SELECT * FROM customer_insights')->fetch();
ok('12-month amount ignores purchases older than a year', (float) $r['total_amount'] === 1000000.0 && (int) $r['order_count'] === 1);
ok('…but the old purchase still sets the rhythm', (int) $r['purchase_days'] === 2 && $r['interval_source'] === 'own');

// Single purchase, industry with no history → global median.
$p5 = $mk();
$p5->exec("INSERT INTO customers (id, name, owner, industry) VALUES (1,'One','r','signage'),(2,'Reg','r','other'),(3,'Unclassified','r',NULL)");
$p5->exec("INSERT INTO orders (id, order_no, customer_id, status, created_at) VALUES (1,'S1',1,'approved','2026-12-01'),(2,'S2',2,'approved','2026-10-01'),(3,'S3',2,'approved','2026-12-20'),(4,'S4',3,'approved','2026-11-01')");
$p5->exec("INSERT INTO order_items (order_id, sku, qty, price) VALUES (1,'A',1,1),(2,'A',1,1),(3,'A',1,1),(4,'A',1,1)");
Insights::compute($p5, $cfg, $today);
$src = $p5->query('SELECT customer_id, interval_source || \':\' || avg_interval FROM customer_insights')->fetchAll(PDO::FETCH_KEY_PAIR);
ok('no same-industry history → global median', $src[1] === 'global:80.0', json_encode($src));
ok('no industry at all → global median', $src[3] === 'global:80.0');
ok('median helper', Insights::median([5, 1, 3]) === 3.0 && Insights::median([4, 1, 3, 2]) === 2.5 && Insights::median([]) === null);

// ── 记跟进 / 无需求 snoozes, read live ──
Insights::record($pdo, $cB, 'followup', '电话联系，下月补货', ['id' => 5, 'name' => 'rina'], $today);
$l = Insights::lists($pdo, $cfg, $today);
ok('after 记跟进 the customer leaves the list immediately (no nightly wait)', array_column($l['b'], 'customer_id') === []);
ok('记跟进 stamps last_contact', $pdo->query("SELECT last_contact FROM customers WHERE id = {$cB}")->fetchColumn() === $today);
$pdo->exec("UPDATE customer_followups SET created_at = '" . $ago(6) . " 10:00:00' WHERE customer_id = {$cB}");
ok('…still hidden on day 6', Insights::lists($pdo, $cfg, $today)['b'] === []);
$pdo->exec("UPDATE customer_followups SET created_at = '" . $ago(7) . " 10:00:00' WHERE customer_id = {$cB}");
ok('…back on day 7', array_column(Insights::lists($pdo, $cfg, $today)['b'], 'customer_id') === [$cB]);

Insights::record($pdo, $cA, 'no_need', '客户项目暂停', ['id' => 5, 'name' => 'rina'], $today);
$pdo->exec("UPDATE customer_followups SET created_at = '" . $ago(29) . " 10:00:00' WHERE customer_id = {$cA}");
ok('无需求 hides for 30 days (day 29 hidden)', Insights::lists($pdo, $cfg, $today)['a'] === []);
$pdo->exec("UPDATE customer_followups SET created_at = '" . $ago(30) . " 10:00:00' WHERE customer_id = {$cA}");
ok('…back on day 30', array_column(Insights::lists($pdo, $cfg, $today)['a'], 'customer_id') === [$cA]);
ok('无需求 does not count as contact', $pdo->query("SELECT last_contact FROM customers WHERE id = {$cA}")->fetchColumn() === null);
$threw = false;
try {
    Insights::record($pdo, $cA, 'delete', 'x', null, $today);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('unknown follow-up kind is rejected', $threw);

// Owner scope (sales see their own only), case/space-insensitive.
$pdo->exec("UPDATE customers SET owner = ' Andi' WHERE id = {$cC}");
ok('owner filter: rina does not see Andi\'s customer', !in_array($cC, array_column(Insights::lists($pdo, $cfg, $today, 'rina')['c'], 'customer_id'), true));
ok('owner filter is case/space-insensitive', array_column(Insights::lists($pdo, $cfg, $today, 'andi')['c'], 'customer_id') === [$cC]);
ok('recompute is idempotent', Insights::compute($pdo, $cfg, $today)['customers'] === 15 && (int) $pdo->query('SELECT COUNT(*) FROM customer_insights')->fetchColumn() === 15);

// ── Through the controller: follow-up form on the customer page ──
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'instest');
    $pdo->exec('VACUUM INTO ' . $pdo->quote($db));
    $run = function (string $action, array $get, array $user, ?array $post = null) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'customers', $action, json_encode($get), json_encode($user)];
        if ($post !== null) {
            $args[] = json_encode($post);
        }
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $q = fn(string $sql) => (new PDO('sqlite:' . $db, null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]))->query($sql)->fetchAll();
    $rina = ['id' => 5, 'name' => 'rina', 'role' => 'sales'];
    $andi = ['id' => 6, 'name' => 'Andi', 'role' => 'sales'];

    [$h] = $run('show', ['id' => $cA], $rina);
    ok('customer page shows the follow-up card with rhythm', str_contains($h, t('followups')) && str_contains($h, sprintf(t('fu_usually'), 60)));
    [, $fl] = $run('followup', [], $rina, ['id' => $cB, 'note' => '']);
    ok('empty follow-up is refused', ($fl[0]['type'] ?? '') === 'error');
    [, $fl] = $run('followup', [], $andi, ['id' => $cB, 'note' => 'sneaky']);
    ok("a salesperson cannot log a follow-up on someone else's customer", $q("SELECT COUNT(*) n FROM customer_followups WHERE note = 'sneaky'")[0]['n'] === 0);
    [, $fl] = $run('followup', [], $rina, ['id' => $cB, 'note' => '见面，下周下单']);
    ok('follow-up saved with who and when', count($q("SELECT * FROM customer_followups WHERE customer_id = {$cB} AND user_name = 'rina' AND kind = 'followup' AND note = '见面，下周下单'")) === 1);
    [, $fl] = $run('no_need', [], $rina, ['id' => $cB, 'note' => '']);
    ok('无需求 without a reason is refused', ($fl[0]['type'] ?? '') === 'error');
    [, $fl] = $run('no_need', [], $rina, ['id' => $cB, 'note' => '已改用其他品牌']);
    ok('无需求 with a reason is saved', count($q("SELECT * FROM customer_followups WHERE customer_id = {$cB} AND kind = 'no_need'")) === 1);
    [$h] = $run('show', ['id' => $cB], $rina);
    ok('customer page says it is snoozed and lists the history', str_contains($h, '已改用其他品牌') && str_contains($h, '见面，下周下单'));
    @unlink($db);
}

$need = ['followups', 'btn_followup', 'btn_no_need', 'fu_hint', 'fu_usually', 'list_a', 'list_b', 'list_c', 'msg_followup_saved', 'msg_no_need_reason'];
$missing = array_values(array_filter($need, fn($k) => !isset(I18N['zh'][$k], I18N['id'][$k])));
ok('follow-up labels exist in Chinese and Indonesian', $missing === [], implode(',', $missing));
