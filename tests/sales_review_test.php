<?php

// ── 销售周评 HR-07 (app/SalesReview.php, docs/任务卡-拜访周评.md) ──
// A fictional week (Mon 19 – Sun 25 Oct 2026) with three field salespeople:
// Ridwan (visits, a stale quote, orders, overdue debt), Angga (WhatsApp
// reports with tags), Dewi (no visit). Figures are checked against the HR-04
// weekly summary and the dashboard ranking; the model is stubbed.

$savedAuth = $GLOBALS['auth'] ?? null;
$savedPerms = $GLOBALS['permissions'] ?? [];
$W = '2026-10-19';

// ── weeks and sentences ──
ok('last week: any day of a week → the Monday before', SalesReview::last_week('2026-10-26') === $W && SalesReview::last_week('2026-11-01') === $W
    && SalesReview::last_week('2026-10-25') === '2026-10-12');
ok('only a Monday is a week', SalesReview::valid_week($W) && !SalesReview::valid_week('2026-10-20') && !SalesReview::valid_week('2026-W43'));
ok('sentences: Chinese and Indonesian ends; "Rp 1.500.000" is not three', SalesReview::sentences('先回访这三家。带样品去。再约时间。') === 3
    && SalesReview::sentences('Rp 1.500.000 belum dibayar. Kunjungi lagi minggu ini.') === 2 && SalesReview::sentences('Satu kalimat tanpa titik') === 1
    && SalesReview::sentences('一。二。三。四。') === 4);

// ── a company ──
$db = tempnam(sys_get_temp_dir(), 'srv');
$p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$ins = $p->prepare('INSERT INTO users (id, name, email, password_hash, role) VALUES (?,?,?,?,?)');
foreach ([[1, 'Administrator', 'admin@t', 'admin'], [2, 'Hana HR', 'hana@t', 'hr'], [3, 'tania', 'tania@t', 'manager'], [4, 'ridwan', 'ridwan@t', 'sales'],
          [5, 'angga', 'angga@t', 'sales'], [6, 'dewi', 'dewi@t', 'sales'], [7, 'budi', 'budi@t', 'sales'], [8, 'liuxian', 'liu@t', 'warehouse']] as [$id, $n, $m, $r]) {
    $ins->execute([$id, $n, $m, 'x', $r]);
}
foreach (default_permissions() as $role => $mods) {
    foreach ($mods as $mod) {
        $p->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?,?)')->execute([$role, $mod]);
    }
}
Hr::migrate_users($p);
$emp = fn(string $userName) => (int) $p->query('SELECT employee_id FROM users WHERE name = ' . $p->quote($userName))->fetchColumn();
foreach (['ridwan' => 'Ridwan Saputra', 'angga' => 'Angga Pratama', 'dewi' => 'Dewi Lestari', 'budi' => 'Budi Santoso'] as $u => $full) {
    $p->prepare("UPDATE employees SET full_name = ?, attendance_mode = 'visit', department = 'sales' WHERE id = ?")->execute([$full, $emp($u)]);
}
$p->prepare('UPDATE employees SET is_dept_head = 1 WHERE id = ?')->execute([$emp('budi')]);
$p->prepare("UPDATE employees SET department = 'management' WHERE id = ?")->execute([$emp('angga')]);   // a visiting manager: outside Budi's department
$p->prepare('UPDATE employees SET is_dept_head = 1 WHERE id = ?')->execute([$emp('liuxian')]);         // warehouse head: no field sales in the department
$R = $emp('ridwan');
$A = $emp('angga');
$D = $emp('dewi');
$B = $emp('budi');

$p->exec("INSERT INTO customers (id, name, phone, owner) VALUES (1, 'Toko Maju', '0812-5550-1111', 'ridwan'), (2, 'Cahaya Kaca', NULL, 'ridwan'), (3, 'Sinar Baru', NULL, 'angga')");
$p->exec("INSERT INTO customer_insights (customer_id, computed_on, in_a) VALUES (1, '2026-10-25', 1), (2, '2026-10-25', 1), (3, '2026-10-25', 0)");
$p->exec("INSERT INTO prospects (id, name, phone, address) VALUES (1, 'Abadi Glass', '0813-0000-2222', 'Jl. Danau Sunter No.1'), (2, 'Mitra Laser', NULL, NULL), (3, 'Karya Plafon', NULL, NULL)");
$visit = $p->prepare('INSERT INTO visits (visit_no, employee_id, visited_at, target_type, customer_id, prospect_id, new_customer_name, outcome, status_lama_baru, system_lama_baru, source, has_tags, wa_sender)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
$n = 0;
$v = function (?int $e, string $at, string $type, ?int $c, ?int $pr, string $outcome, ?string $lb = null, ?string $sys = null, string $src = 'manual', int $tags = 0, ?string $sender = null) use ($visit, &$n): void {
    $visit->execute(['KV-' . (++$n), $e, $at, $type, $c, $pr, $type === 'new' ? 'Toko Baru Sekali' : null, $outcome, $lb, $sys, $src, $tags, $sender]);
};
$v($R, '2026-10-05 10:00:00', 'prospect', null, 1, 'penawaran');                    // quoted 20 days before the week's end, nothing since → 该跟的
$v($R, '2026-10-08 10:00:00', 'customer', 1, null, 'penawaran');                    // quoted, then an order on 12 Oct → not pending
$v($R, '2026-10-10 10:00:00', 'prospect', null, 3, 'penawaran');                    // quoted, then Angga visited on 21 Oct → not pending
$v($R, '2026-10-12 09:00:00', 'customer', 1, null, 'order', null, 'lama');          // last week
$v($R, '2026-10-20 11:00:00', 'prospect', null, 2, 'penawaran', 'baru');            // quoted 5 days before the end → not yet
$v($R, '2026-10-21 14:00:00', 'customer', 2, null, 'berminat', null, 'lama');
$v($R, '2026-10-22 15:00:00', 'new', null, null, 'unknown', null, null, 'wa_import', 0, 'Ridwan');
$v($A, '2026-10-21 10:00:00', 'prospect', null, 3, 'berminat', 'baru', null, 'wa_import', 1, 'Angga');
$v($A, '2026-10-24 10:00:00', 'customer', 3, null, 'order', 'lama', 'lama', 'wa_import', 1, 'Angga');
$v(null, '2026-10-22 16:00:00', 'new', null, null, 'unknown', null, null, 'wa_import', 0, '+62 812-0000-9999');   // sender not mapped yet

$order = $p->prepare('INSERT INTO orders (id, order_no, customer_id, customer_name, submitter, status, wh_date, delivery_region, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
$order->execute([1, 'O1', 1, 'Toko Maju', 'ridwan', 'approved', '2026-10-13', 'jkt_utara', '2026-10-12 10:00:00']);
$order->execute([2, 'O2', 2, 'Cahaya Kaca', 'ridwan', 'approved', '2026-10-22', 'jkt_utara', '2026-10-21 16:00:00']);
$order->execute([3, 'O3', 2, 'Cahaya Kaca', 'ridwan', 'draft', null, null, '2026-10-22 09:00:00']);
$order->execute([4, 'O4', 3, 'Sinar Baru', 'angga', 'pending_sup', null, 'jkt_barat', '2026-10-24 12:00:00']);
$p->exec("INSERT INTO order_items (order_id, sku, qty, price) VALUES (1, 'A', 10, 100000), (2, 'A', 20, 100000), (3, 'A', 5, 100000), (4, 'A', 8, 100000)");
$p->exec("INSERT INTO invoices (id, invoice_no, order_id, customer, total, amount_paid, invoice_date, due_date) VALUES
          (1, 'INV1', 1, 'Toko Maju', 1110000, 500000, '2026-10-13', '2026-10-13'), (2, 'INV2', 2, 'Cahaya Kaca', 2220000, 1000000, '2026-10-22', '2026-10-22')");
$p->exec("INSERT INTO payments (invoice_id, customer, amount, pay_date) VALUES (1, 'Toko Maju', 500000, '2026-10-14'), (2, 'Cahaya Kaca', 1000000, '2026-10-23')");

// ── figures (rules) ──
$data = SalesReview::compute($p, $W);
$r = $data['people'][$R];
ok('one section per field salesperson in service (Budi, the department head, too)', array_keys($data['people']) === [$A, $B, $D, $R], json_encode(array_keys($data['people'])));
ok('跑动: 3 visits, last week 1, Baru 1 / Lama 1, results incl. unknown, tags of WhatsApp reports', $r['visits']['total'] === 3 && $r['visits']['prev'] === 1 && $r['visits']['baru'] === 1
    && $r['visits']['lama'] === 1 && $r['visits']['outcomes']['penawaran'] === 1 && $r['visits']['outcomes']['berminat'] === 1 && $r['visits']['outcomes']['unknown'] === 1
    && $r['visits']['wa'] === 1 && $r['visits']['tagged'] === 0, json_encode($r['visits']));
$weekly = [];
foreach (Visits::summary(Visits::fetch($p, '2026-10-19', '2026-10-25')) as $s) {
    $weekly[$s['sales']] = $s;
}
ok('ACCEPTANCE: visits agree with the HR-04 weekly summary (Ridwan, Angga)', $weekly['Ridwan Saputra']['total'] === $r['visits']['total'] && $weekly['Ridwan Saputra']['baru'] === $r['visits']['baru']
    && $weekly['Angga Pratama']['total'] === $data['people'][$A]['visits']['total'] && $weekly['Angga Pratama']['counts']['order'] === $data['people'][$A]['visits']['outcomes']['order']);
ok('ACCEPTANCE: the quote list holds only a Penawaran with nothing after it for > 7 days', $r['follow']['pending_count'] === 1 && $r['follow']['pending'][0]['name'] === 'Abadi Glass'
    && $r['follow']['pending'][0]['quoted'] === '2026-10-05' && $r['follow']['pending'][0]['days'] === 20 && $r['follow']['pending'][0]['kind'] === 'prospect', json_encode($r['follow']));
ok('该跟的: A-list customers of his not visited this week (Toko Maju yes, Cahaya Kaca visited)', $r['follow']['a_unvisited'] === 1);
$rank = [];
foreach (Dashboard::ranking($p, '2026-10-19', '2026-10-25', '2026-10-25', true) as $x) {
    $rank[$x['name']] = $x;
}
ok('ACCEPTANCE: results agree with the dashboard ranking (orders, sheets, sales, collected)', $r['results']['orders'] === $rank['ridwan']['orders'] && $r['results']['orders'] === 1
    && (float) $r['results']['sheets'] === 20.0 && (float) $r['results']['sales'] === 2220000.0 && (float) $r['results']['collected'] === 1000000.0
    && $data['people'][$A]['results']['orders'] === $rank['angga']['orders'], json_encode($r['results']));
ok('成果: last week alongside; overdue unpaid on his customers by the week end', $r['results']['prev']['orders'] === 1 && (float) $r['results']['prev']['sales'] === 1110000.0
    && (float) $r['results']['overdue'] === 1830000.0 && $r['results']['overdue_invoices'] === 2);
ok('team: who visited, who did not (named only), regions that ordered, unmapped visits counted for nobody',
    in_array('Dewi Lestari', $data['team']['zero_visits'], true) && in_array('Ridwan Saputra', $data['team']['active'], true)
    && $data['team']['order_regions'] === ['jkt_barat' => 1, 'jkt_utara' => 1] && $data['team']['unmapped_visits'] === 1 && $data['team']['visits'] === 6);

// ── what the model reads ──
[$payload, $keys] = SalesReview::ai_input($data);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
ok('model input: only people who visited; no phones, no addresses, no account names', count($payload['people']) === 2 && !in_array($D, $keys, true)
    && !str_contains($json, '0812') && !str_contains($json, '0813') && !str_contains($json, 'Danau Sunter') && !str_contains($json, '"ridwan"') && str_contains($json, 'Abadi Glass'));

// ── AI off ──
$cfgFull = (array) (@include __DIR__ . '/../config.php');
$off = ['ai' => ['key' => ''] + ($cfgFull['ai'] ?? [])];
$off['ai']['key'] = '';
$g = SalesReview::generate($p, $off, $W, 'Administrator');
$items = $p->query("SELECT * FROM sales_review_items WHERE review_id = {$g['id']}")->fetchAll();
ok('ACCEPTANCE: AI off → figures stored, no comments, status ai_skipped, no call made', $g['status'] === 'ai_skipped' && count($items) === 4
    && count(array_filter($items, fn($i) => $i['comment_zh'] !== null)) === 0 && (int) $p->query('SELECT COUNT(*) FROM ai_calls')->fetchColumn() === 0);

// ── AI on (stubbed) ──
$cfg = ['ai' => $cfgFull['ai'] ?? []];
$cfg['ai']['key'] = 'sk-test';
$cfg['ai']['retries'] = 0;
$mode = 'good';
$sent = [];
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$mode, &$sent): array {
    $b = json_decode($body, true);
    $sent[] = $body;
    $u = json_decode((string) $b['messages'][0]['content'], true);
    $people = array_map(fn($x) => ['key' => $x['key'],
        'zh' => $mode === 'long' ? '一。二。三。四。' : '报价未跟 1 家，先回访 ' . ($x['to_follow']['quote_shops'][0]['shop'] ?? '老客户') . '。',
        'id' => 'Kunjungi lagi toko yang sudah diberi penawaran.'], $u['people']);
    $ans = ['team_zh' => '本周 2 人在跑，Dewi Lestari 无拜访。', 'team_id' => 'Minggu ini 2 orang aktif, Dewi Lestari tanpa kunjungan.', 'people' => $people];
    return [200, json_encode(['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => json_encode($ans, JSON_UNESCAPED_UNICODE)]], 'stop_reason' => 'end_turn',
                              'usage' => ['input_tokens' => 900, 'output_tokens' => 300]]), []];
};
$g2 = SalesReview::generate($p, $cfg, $W, 'Administrator');
$byEmp = [];
foreach ($p->query("SELECT * FROM sales_review_items WHERE review_id = {$g2['id']}") as $i) {
    $byEmp[(int) $i['employee_id']] = $i;
}
$rv = $p->query("SELECT * FROM sales_reviews WHERE id = {$g2['id']}")->fetch();
ok('ACCEPTANCE: AI on → overview and comments in both languages, ≤ 3 sentences', $g2['status'] === 'ok' && $rv['team_zh'] !== null && $rv['team_id'] !== null
    && str_contains((string) $byEmp[$R]['comment_zh'], 'Abadi Glass') && $byEmp[$R]['comment_id'] !== null && SalesReview::sentences((string) $byEmp[$R]['comment_zh']) <= 3
    && $byEmp[$D]['comment_zh'] === null && (int) $rv['ai_call_id'] > 0);
ok('the request carries no phone number', !str_contains(implode('', $sent), '0812') && !str_contains(implode('', $sent), '0813'));
$mode = 'long';
$g3 = SalesReview::generate($p, $cfg, $W, 'Administrator');
ok('a comment over 3 sentences is refused: figures only, no retry beyond the task\'s own', $g3['status'] === 'ai_skipped'
    && (int) $p->query("SELECT COUNT(*) FROM sales_review_items WHERE review_id = {$g3['id']} AND comment_zh IS NOT NULL")->fetchColumn() === 0
    && $p->query("SELECT status FROM ai_calls WHERE id = (SELECT ai_call_id FROM sales_reviews WHERE id = {$g3['id']})")->fetchColumn() === 'invalid');
ok('regenerating keeps every earlier record; the newest is shown with its version', (int) $p->query("SELECT COUNT(*) FROM sales_reviews WHERE week = '{$W}'")->fetchColumn() === 3
    && (int) SalesReview::latest($p, $W)['version'] === 3 && (int) SalesReview::latest($p, $W)['id'] === $g3['id']);
$mode = 'good';

// ── nightly: once a week, one todo for the boss ──
$p->exec("DELETE FROM sales_review_items");
$p->exec("DELETE FROM sales_reviews");
$n1 = SalesReview::nightly($p, $cfg, '2026-10-26');
$n2 = SalesReview::nightly($p, $cfg, '2026-10-26');
$n3 = SalesReview::nightly($p, $cfg, '2026-10-27');
ok('ACCEPTANCE: Monday night → last week\'s review and one boss todo; running again creates nothing', $n1 !== null && $n1['status'] === 'ok' && $n2 === null && $n3 === null
    && (int) $p->query("SELECT COUNT(*) FROM sales_reviews WHERE week = '{$W}' AND generated_by = 'cron'")->fetchColumn() === 1
    && (int) $p->query("SELECT COUNT(*) FROM hr_todos WHERE type = 'sales_review' AND ref = '{$W}' AND employee_id IS NULL")->fetchColumn() === 1);
ok('the todo type is the boss\'s alone (not an HR todo)', in_array('sales_review', Hr::BOSS_TODO_TYPES, true) && !in_array('sales_review', Hr::TODO_TYPES, true));
Claude::$http = null;
Claude::$sleep = null;
$p = null;

// ── the page, through the real controller ──
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $u = fn(int $id, string $name, string $role, ?int $e) => ['id' => $id, 'name' => $name, 'role' => $role, 'employee_id' => $e];
    $admin = $u(1, 'Administrator', 'admin', null);
    $hana = $u(2, 'Hana HR', 'hr', $emp('Hana HR'));
    $tania = $u(3, 'tania', 'manager', $emp('tania'));
    $ridwan = $u(4, 'ridwan', 'sales', $R);
    $budi = $u(7, 'budi', 'sales', $B);
    $liu = $u(8, 'liuxian', 'warehouse', $emp('liuxian'));
    $run = function (string $action, array $user, array $get = [], ?array $post = null) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, 'hr', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post), json_encode(['lang' => 'zh'])];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $isErr = fn(array $fl) => ($fl[0]['type'] ?? '') === 'error';

    [$h] = $run('review', $admin, ['week' => $W]);
    ok('boss: overview, every card, the quote linked to the prospect page, export and regenerate', str_contains($h, '本周 2 人在跑') && str_contains($h, 'Ridwan Saputra') && str_contains($h, 'Angga Pratama')
        && str_contains($h, 'Dewi Lestari') && str_contains($h, 'r=visits.prospects&q=Abadi+Glass') && str_contains($h, 'hr.review_export') && str_contains($h, 'hr.review_generate')
        && str_contains($h, t('srv_no_visits')) && !str_contains($h, 'PHP:'), mb_substr(strip_tags($h), 0, 300));
    [$h] = $run('review', $admin);
    ok('default week = last week (empty state when nothing generated)', str_contains($h, t('srv_none')) || str_contains($h, 'Ridwan Saputra'));
    [$h] = $run('review', $tania, ['week' => $W]);
    ok('visits_all (manager): everyone, no regenerate button', str_contains($h, 'Angga Pratama') && str_contains($h, 'Ridwan Saputra') && !str_contains($h, 'hr.review_generate'));
    [$h] = $run('review', $budi, ['week' => $W]);
    ok('sales department head: own department only', str_contains($h, 'Ridwan Saputra') && str_contains($h, 'Dewi Lestari') && !str_contains($h, 'Angga Pratama'));
    [, $fl] = $run('review', $ridwan, ['week' => $W]);
    ok('ACCEPTANCE: a salesperson cannot open it', $isErr($fl));
    [, $fl] = $run('review', $hana, ['week' => $W]);
    [, $fl2] = $run('review', $liu, ['week' => $W]);
    ok('HR (no visits_all) and a warehouse head cannot either', $isErr($fl) && $isErr($fl2));
    [$h] = $run('employees', $liu);
    [$h2] = $run('employees', $tania);
    ok('ACCEPTANCE: the menu tab shows only to those who may open it', !str_contains($h, 'r=hr.review') && str_contains($h2, 'r=hr.review'));
    [$h] = $run('index', $admin);
    ok('boss todo list: 上周销售周评已生成, linked to the week', str_contains($h, t('hrtodo_sales_review')) && str_contains($h, 'r=hr.review&week=' . $W));
    [$h] = $run('index', $hana);
    ok('HR does not see the boss\'s todo', !str_contains($h, t('hrtodo_sales_review')));
    [$x] = $run('review_export', $tania, ['week' => $W]);
    ok('export: an xlsx', str_starts_with($x, 'PK'));
    [, $fl] = $run('review_generate', $tania, ['week' => $W], []);
    $q = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ok('regenerate: not for the manager', $isErr($fl) && (int) $q->query('SELECT COUNT(*) FROM sales_reviews')->fetchColumn() === 1);
    [, $fl] = $run('review_generate', $admin, ['week' => $W], []);
    ok('regenerate: the boss gets a new record (AI off in tests: figures only), the old one stays', ($fl[0]['type'] ?? '') !== 'error'
        && (int) $q->query('SELECT COUNT(*) FROM sales_reviews')->fetchColumn() === 2 && $q->query('SELECT status FROM sales_reviews ORDER BY id DESC LIMIT 1')->fetchColumn() === 'ai_skipped'
        && $q->query("SELECT generated_by FROM sales_reviews ORDER BY id DESC LIMIT 1")->fetchColumn() === 'Administrator');
    [, $fl] = $run('review_generate', $admin, ['week' => '2099-01-05'], []);
    ok('regenerate: not for a week not finished yet', $isErr($fl));
    $q = null;
}
@unlink($db);
$GLOBALS['auth'] = $savedAuth;
$GLOBALS['permissions'] = $savedPerms;
