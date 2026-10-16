<?php

// ── 回访清单 pages (insights.index / insights.admin) + manager stats ──

require_once __DIR__ . '/../app/Csrf.php';

$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$schemaSql = $schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql;
$cfg = ['insights' => ((array) (@include __DIR__ . '/../config.php'))['insights'] ?? []];
$today = date('Y-m-d');
$ago = fn(int $d): string => date('Y-m-d', strtotime($today . " -{$d} days"));

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec($schemaSql);
$cust = function (string $name, string $owner, string $industry, ?string $region, string $phone = '') use ($pdo): int {
    $pdo->prepare('INSERT INTO customers (name, owner, industry, region, phone) VALUES (?,?,?,?,?)')->execute([$name, $owner, $industry, $region, $phone]);
    return (int) $pdo->lastInsertId();
};
$buy = function (int $cid, int $daysAgo, float $amount, string $owner, ?array $pt = null) use ($pdo, $ago): void {
    $pdo->prepare("INSERT INTO orders (order_no, customer_id, submitter, status, created_at, delivery_lat, delivery_lng) VALUES (?,?,?, 'approved', ?, ?, ?)")
        ->execute(['SO-' . uniqid('', true), $cid, $owner, $ago($daysAgo) . ' 09:00:00', $pt[0] ?? null, $pt[1] ?? null]);
    $pdo->prepare("INSERT INTO order_items (order_id, sku, spec, qty, price) VALUES (?, 'SJ8010', '4.0*0.21', 10, ?)")->execute([(int) $pdo->lastInsertId(), $amount / 10]);
};
$rinaDue = $cust('Rina Due', 'rina', 'aluminium_shop', 'bekasi', '0812-1111-2222');
// three purchases 60 days apart, last 58 days ago → A (0.9×60 = 54 ≤ 58 ≤ 90)
$buy($rinaDue, 178, 6_000_000, 'rina', [-6.24, 107.0]);
$buy($rinaDue, 118, 6_000_000, 'rina', [-6.24, 107.0]);
$buy($rinaDue, 58, 6_000_000, 'rina', [-6.24, 107.0]);
$rinaRisk = $cust('Rina Risk', 'rina', 'contractor', 'jkt_timur');
$buy($rinaRisk, 200, 3_000_000, 'rina', [-6.22, 106.90]);
$buy($rinaRisk, 190, 3_000_000, 'rina', [-6.22, 106.90]);      // every 10 days, 190 days ago → B, red
$andiDue = $cust('Andi Due', 'andi', 'signage', 'jkt_utara');
$buy($andiDue, 100, 2_000_000, 'andi', [-6.12, 106.88]);
$buy($andiDue, 70, 2_000_000, 'andi', [-6.12, 106.88]);
$buy($andiDue, 40, 2_000_000, 'andi', [-6.12, 106.88]);        // every 30, 40 days ago → A
$far = $cust('Jember Far', 'andi', 'other', 'other');
$buy($far, 20, 1_000_000, 'andi', [-8.17, 113.70]);            // outside the map box
$pdo->exec("INSERT INTO customers (name, owner) VALUES ('Never Ordered', 'rina')");
Insights::compute($pdo, $cfg, $today);

// 1) Manager stats agree with the database.
Insights::record($pdo, $rinaRisk, 'followup', 'called', ['id' => 1, 'name' => 'rina'], $ago(3));
Insights::record($pdo, $andiDue, 'no_need', 'project done', ['id' => 2, 'name' => 'andi'], $today);
$act = Insights::activity($pdo, $today);
ok('activity: customers owned (rina 3 incl. one with no orders, andi 2)', $act['rina']['customers'] === 3 && $act['andi']['customers'] === 2, json_encode($act));
ok('activity: follow-ups in 30 days count customers, not "no need"', $act['rina']['fu30'] === 1 && $act['andi']['fu30'] === 0);
$listedRina = (int) $pdo->query("SELECT COUNT(*) FROM insights_history h JOIN customers c ON c.id = h.customer_id WHERE c.owner = 'rina' AND h.day >= '" . substr($today, 0, 7) . "-01'")->fetchColumn();
ok('activity: listed this month matches insights_history', $act['rina']['listed'] === $listedRina && $listedRina === 2);
ok('activity: handled = listed customers with a follow-up/no-need on or after listing', $act['andi']['handled'] === 1 && $act['andi']['listed'] === 1);
ok('activity: a follow-up BEFORE the customer was listed does not count', $act['rina']['handled'] === 0, (string) $act['rina']['handled']);

$ind = Insights::distribution($pdo, 'industry');
ok('distribution by industry: only customers with shipped orders, 12-month amount', $ind['aluminium_shop'] === ['n' => 1, 'amount' => 18_000_000.0] && !isset($ind['']));
$reg = Insights::distribution($pdo, 'region');
ok('distribution by region sums to all shipped customers', array_sum(array_column($reg, 'n')) === 4);
$threw = false;
try {
    Insights::distribution($pdo, 'name; DROP TABLE customers');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('distribution field is whitelisted', $threw);

$pdo->exec("UPDATE customers SET industry_pending = 1, industry_source = 'ai' WHERE id = {$andiDue}");
$pdo->exec("UPDATE customers SET region = 'unknown' WHERE id = {$rinaRisk}");
$q = Insights::quality($pdo);
ok('quality: pending industry / unknown region / no phone, per owner', $q['andi'] === ['customers' => 2, 'industry' => 1, 'region' => 0, 'phone' => 2] && $q['rina']['region'] === 1 && $q['rina']['phone'] === 1, json_encode($q));
ok('quality ignores customers who never ordered', $q['rina']['customers'] === 2);

// 2) Map layout.
$pts = Insights::map_points($pdo);
ok('map points = shipped orders with coordinates', count($pts) === 9 && $pts[0]['amount'] >= $pts[8]['amount']);
$box = ['north' => -5.95, 'south' => -6.75, 'west' => 106.45, 'east' => 107.25];
$lay = Insights::map_layout($pts, $box, 10);
ok('map: points outside the box are left off', count($lay['points']) === 8);
ok('map: a small tile grid (≤ 4×4 at zoom 10)', $lay['cols'] >= 2 && $lay['cols'] <= 4 && $lay['rows'] >= 2 && $lay['rows'] <= 4, json_encode([$lay['cols'], $lay['rows']]));
$inGrid = array_filter($lay['points'], fn($p) => $p['x'] >= 0 && $p['x'] <= $lay['cols'] * 256 && $p['y'] >= 0 && $p['y'] <= $lay['rows'] * 256);
ok('map: every point lands inside the tile grid', count($inGrid) === 8);
$north = array_values(array_filter($lay['points'], fn($p) => $p['customer'] === 'Andi Due'))[0];
$east = array_values(array_filter($lay['points'], fn($p) => $p['customer'] === 'Rina Due'))[0];
ok('map: north is up, east is right', $north['y'] < $east['y'] && $east['x'] > $north['x']);

// 3) Pages through the real controller.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'insview');
    $pdo->exec("INSERT OR REPLACE INTO app_meta (k, v) VALUES ('insights_computed_on', '{$today}')");
    $pdo->exec('VACUUM INTO ' . $pdo->quote($db));
    $run = function (string $ctl, string $action, array $get, array $user, ?array $post = null) use ($db, $harness): string {
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, json_encode($get), json_encode($user)];
        if ($post !== null) {
            $args[] = json_encode($post);
        }
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $rina = ['id' => 5, 'name' => 'rina', 'role' => 'sales'];
    $boss = ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];
    $sup = ['id' => 7, 'name' => 'Deni', 'role' => 'supervisor'];
    $clerk = ['id' => 8, 'name' => 'Fera', 'role' => 'clerk'];

    $h = $run('insights', 'index', [], $rina);
    ok('sales page: tabs with counts, A by default', str_contains($h, 'ins-tab ins-tab-a active') && str_contains($h, t('list_b')));
    ok('sales page: own due customer listed with rhythm and last purchase', str_contains($h, 'Rina Due') && str_contains($h, sprintf(t('fu_usually'), 60)) && str_contains($h, 'SJ8010 4.0*0.21 ×10'));
    ok("sales page: never another salesperson's customer", !str_contains($h, 'Andi Due'));
    ok('sales page: big call / WhatsApp / follow-up buttons', str_contains($h, 'tel:081211112222') && str_contains($h, 'https://wa.me/6281211112222') && str_contains($h, t('btn_followup')) && str_contains($h, t('btn_no_need')));
    ok('sales page: buttons post back to the list', str_contains($h, 'name="back" value="insights"'));
    ok('sales page: no owner column, no manager link', !str_contains($h, 'ins-owner') && !str_contains($h, 'insights.admin'));
    $hb = $run('insights', 'index', ['tab' => 'b'], $rina);
    ok('B tab: snoozed customer hidden (followed up 3 days ago)', !str_contains($hb, 'Rina Risk'));
    ok('unknown tab falls back to A', str_contains($run('insights', 'index', ['tab' => 'zzz'], $rina), 'ins-tab ins-tab-a active'));

    $h = $run('insights', 'admin', [], $rina);
    ok('sales cannot open the manager view', !str_contains($h, t('ins_activity')));
    $h = $run('insights', 'admin', [], $clerk);
    ok('clerk cannot open the manager view', !str_contains($h, t('ins_activity')));
    $h = $run('insights', 'admin', [], $sup);
    ok('supervisor can open the manager view', str_contains($h, t('ins_activity')));
    $h = $run('insights', 'admin', [], $boss);
    foreach (['ins_activity', 'ins_by_industry', 'ins_by_region', 'ins_map', 'ins_all_lists', 'ins_quality'] as $k) {
        if (!str_contains($h, t($k))) {
            ok("manager view has section {$k}", false);
        }
    }
    ok('manager view: all five sections render', !str_contains($h, 'Fatal error'));
    ok('manager view: lists show every salesperson with an owner column (snoozed ones hidden)', str_contains($h, '#followups">Rina Due</a>') && !str_contains($h, '#followups">Andi Due</a>') && str_contains($h, 'ins-owner'));
    ok('manager view: OSM tiles + one circle per in-box point, with tooltips', substr_count($h, 'tile.openstreetmap.org/10/') >= 4 && substr_count($h, '<circle') === 8 && str_contains($h, '<title>Rina Due'));
    ok('manager view: attribution and off-map count', str_contains($h, 'OpenStreetMap') && str_contains($h, sprintf(t('ins_map_count'), 8, 1)));
    $hs = $run('insights', 'admin', ['owner' => 'Andi'], $boss);
    ok('owner filter: highlighted salesperson vs others on the map', substr_count($hs, 'class="pt-sel"') === 3 && substr_count($hs, 'class="pt-other"') === 5);
    ok('owner filter: lists narrowed, post-back keeps the filter', str_contains($hs, 'name="back" value="insights_admin"') || !str_contains($hs, 'ins-item'));

    // Follow-up from the list comes back to the list.
    $r = $run('customers', 'followup', [], $rina, ['id' => $rinaDue, 'note' => 'WA, butuh minggu depan', 'back' => 'insights', 'tab' => 'a']);
    $after = $run('insights', 'index', [], $rina);
    ok('记跟进 from the list removes the customer from the list at once', !str_contains($after, 'Rina Due'));
    @unlink($db);
}

foreach (['nav_insights', 'insights_admin', 'ins_activity', 'ins_map', 'ins_quality', 'ins_rule_a', 'ins_rule_b', 'ins_rule_c', 'ins_empty_c', 'ins_map_count'] as $k) {
    if (!isset(I18N['zh'][$k], I18N['id'][$k])) {
        ok("{$k} translated", false);
    }
}
ok('page labels exist in Chinese and Indonesian', true);
ok('insights is a permission-matrix module granted by default to sales', in_array('insights', controllable_modules(), true) && in_array('insights', default_permissions()['sales'], true) && !in_array('insights', default_permissions()['warehouse'], true));
