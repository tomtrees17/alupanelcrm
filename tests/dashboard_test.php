<?php

// ── Dashboard: periods, figures, permissions ──

require_once __DIR__ . '/../app/Csrf.php';

// ── 1) Period arithmetic ─────────────────────────────────────────────────────
$today = '2026-10-12';   // a Monday

$m = Dashboard::period('month', $today, $today);
ok('month bounds', $m['start'] === '2026-10-01' && $m['end'] === '2026-10-31');
ok('current month is current', $m['current'] && $m['to'] === $today);
// In-progress month compares like for like: 1–12 Oct vs 1–12 Sep, not all of September.
ok('in-progress month compares the same elapsed days', $m['cmp_start'] === '2026-09-01' && $m['cmp_end'] === '2026-09-12', $m['cmp_end']);
ok('...and says so', $m['partial'] === true);
ok('no next period from the current one', $m['next'] === null);
ok('previous anchor is the day before', $m['prev'] === '2026-09-30');

$past = Dashboard::period('month', '2026-09-15', $today);
ok('a past month runs to its end', $past['start'] === '2026-09-01' && $past['to'] === '2026-09-30');
ok('a past month compares the whole previous month', $past['cmp_start'] === '2026-08-01' && $past['cmp_end'] === '2026-08-31' && !$past['partial']);
ok('a past month has a next period', $past['next'] === '2026-10-01');

// 31 March compared with a 28-day February must clamp, not spill into March.
$mar = Dashboard::period('month', '2027-03-31', '2027-03-31');
ok('month-end comparison clamps to the shorter month', $mar['cmp_end'] === '2027-02-28', $mar['cmp_end']);

$jan = Dashboard::period('month', '2027-01-05', '2027-01-05');
ok('January compares with December of the previous year', $jan['cmp_start'] === '2026-12-01' && $jan['cmp_end'] === '2026-12-05');

$w = Dashboard::period('week', '2026-10-15', '2026-10-15');   // a Thursday
ok('weeks start on Monday', $w['start'] === '2026-10-12' && $w['end'] === '2026-10-18');
ok('in-progress week compares Mon–Thu with Mon–Thu', $w['cmp_start'] === '2026-10-05' && $w['cmp_end'] === '2026-10-08', $w['cmp_end']);
$sun = Dashboard::period('week', '2026-10-18', '2026-10-20');  // Sunday belongs to the week before Monday
ok('Sunday belongs to the week that started the Monday before', $sun['start'] === '2026-10-12');
$yr = Dashboard::period('week', '2027-01-01', '2027-01-01');  // Friday, week spans the new year
ok('a week spanning New Year starts in December', $yr['start'] === '2026-12-28' && $yr['end'] === '2027-01-03');

$d = Dashboard::period('day', '2026-10-01', $today);
ok('day bounds', $d['start'] === '2026-10-01' && $d['end'] === '2026-10-01');
ok('day compares with the day before', $d['cmp_start'] === '2026-09-30' && $d['cmp_end'] === '2026-09-30');

// Hostile / sloppy input falls back instead of breaking.
ok('unknown period falls back to month', Dashboard::period('year', $today, $today)['p'] === 'month');
ok('malformed date falls back to today', Dashboard::period('day', '2026-13-45', $today)['start'] === $today);
ok('injection-shaped date falls back to today', Dashboard::period('day', "2026-10-01' OR 1=1", $today)['start'] === $today);
ok('a future date is clamped to today', Dashboard::period('month', '2027-06-01', $today)['start'] === '2026-10-01');

// ── 2) Trend buckets ─────────────────────────────────────────────────────────
$bm = Dashboard::buckets($m);
ok('12 monthly buckets', count($bm) === 12);
ok('monthly buckets run oldest → newest, ending at the selection', $bm[0]['start'] === '2025-11-01' && $bm[11]['start'] === '2026-10-01');
ok('only the selected bucket is marked current', array_sum(array_map(fn($b) => (int) $b['current'], $bm)) === 1 && $bm[11]['current']);
$bd = Dashboard::buckets($d);
ok('14 daily buckets', count($bd) === 14 && $bd[13]['start'] === '2026-10-01' && $bd[0]['start'] === '2026-09-18');
$bw = Dashboard::buckets($w);
ok('12 weekly buckets, all Mondays', count($bw) === 12 && count(array_filter($bw, fn($b) => date('N', strtotime($b['start'])) === '1')) === 12);

// Empty months before the system was in use are trimmed — but never below 6 columns.
$mk = fn(array $vals) => array_map(fn($v) => ['start' => 'x', 'end' => 'x', 'sales' => (float) $v, 'collected' => 0.0, 'current' => false], $vals);
ok('leading empty columns are trimmed', count(Dashboard::trim_leading($mk([0, 0, 0, 0, 0, 0, 0, 0, 5, 6, 7, 8]), 6)) === 6);
ok('trimming stops at the first column with data', count(Dashboard::trim_leading($mk([0, 0, 3, 0, 5, 6, 7, 8, 9, 1, 2, 3]), 6)) === 10);
ok('a gap in the middle is kept', Dashboard::trim_leading($mk([0, 0, 3, 0, 5, 6, 7, 8, 9, 1, 2, 3]), 6)[1]['sales'] === 0.0);
ok('an all-empty chart keeps the minimum', count(Dashboard::trim_leading($mk(array_fill(0, 12, 0)), 6)) === 6);
ok('collections alone count as data', count(Dashboard::trim_leading([['sales' => 0.0, 'collected' => 9.0]] + $mk(array_fill(0, 12, 0)), 6)) >= 6);

// ── 3) Small maths ───────────────────────────────────────────────────────────
ok('delta up', round((float) Dashboard::delta(120, 100), 1) === 20.0);
ok('delta down', round((float) Dashboard::delta(80, 100), 1) === -20.0);
ok('delta with nothing to compare is null, not infinity', Dashboard::delta(50, 0) === null);
ok('nice_max rounds up to a clean number', Dashboard::nice_max(437218990) === 500000000.0 && Dashboard::nice_max(180) === 200.0 && Dashboard::nice_max(2100) === 2500.0);
ok('nice_max of zero is safe', Dashboard::nice_max(0) === 1.0);

// ── 4) Figures against a real schema ─────────────────────────────────────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

$order = function (string $submitter, string $created, string $status = 'approved', ?string $reject = null) use ($pdo): int {
    $pdo->prepare('INSERT INTO orders (order_no,customer_name,submitter,status,created_at,reject_note) VALUES (?,?,?,?,?,?)')
        ->execute(['SO-' . uniqid('', true), 'Cust', $submitter, $status, $created . ' 10:00:00', $reject]);
    return (int) $pdo->lastInsertId();
};
$invoice = function (?int $oid, string $date, float $total, float $paid = 0, string $due = '2099-01-01', string $cust = 'Cust', ?string $void = null) use ($pdo): int {
    $pdo->prepare('INSERT INTO invoices (invoice_no,order_id,customer,invoice_date,due_date,total,amount_paid,voided_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute(['INV-' . uniqid('', true), $oid, $cust, $date, $due, $total, $paid, $void]);
    return (int) $pdo->lastInsertId();
};
$pay = function (int $iid, string $date, float $amt) use ($pdo): void {
    $pdo->prepare('INSERT INTO payments (invoice_id,amount,pay_date) VALUES (?,?,?)')->execute([$iid, $amt, $date]);
};

// October so far (1–12): ridwan 2 invoices, angga 1; September 1–12 for comparison.
$o1 = $order('ridwan', '2026-10-02');
$o2 = $order('ridwan', '2026-10-05');
$o3 = $order('angga', '2026-10-08');
$i1 = $invoice($o1, '2026-10-02', 1000000, 1000000);
$i2 = $invoice($o2, '2026-10-05', 3000000, 0, '2026-10-06', 'PT Telat');          // overdue 6 days
$i3 = $invoice($o3, '2026-10-08', 2000000, 500000);
$pay($i1, '2026-10-03', 1000000);
$pay($i3, '2026-10-09', 500000);

$o4 = $order('ridwan', '2026-09-03');
$i4 = $invoice($o4, '2026-09-03', 4000000, 4000000);
$pay($i4, '2026-09-04', 4000000);
$o5 = $order('angga', '2026-09-20');                                                 // after 12 Sep: not in the like-for-like window
$invoice($o5, '2026-09-20', 9000000, 0, '2026-07-01', 'PT Lama');                    // 103 days overdue

// Noise that must NOT count.
$invoice($order('ridwan', '2026-10-06'), '2026-10-06', 7777777, 0, '2099-01-01', 'Void Co', '2026-10-07 09:00:00');  // voided
$order('ridwan', '2026-10-07', 'draft');                                             // draft
$invoice(null, '2026-10-10', 250000);                                                // orphan invoice, no salesperson
$pay($invoice($order('angga', '2026-10-01'), '2026-10-01', 500000, 0), '2026-10-04', 500000);
$rev = (int) $pdo->query('SELECT MAX(id) FROM invoices')->fetchColumn();
$pay($rev, '2026-10-11', -500000);                                                   // a reversal nets out

$data = Dashboard::data($pdo, $m, $today, null, true, true);
$k = $data['kpi'];
ok('sales = invoices issued in the period', $k['sales'] === 1000000.0 + 3000000 + 2000000 + 250000 + 500000, (string) $k['sales']);
ok('voided invoices are not sales', $k['sales'] !== 7777777.0 + 6750000);
ok('comparison window uses 1–12 Sep only', $k['sales_cmp'] === 4000000.0, (string) $k['sales_cmp']);
ok('orders exclude drafts', $k['orders'] === 5, (string) $k['orders']);  // o1 o2 o3 + voided-invoice order + angga 10-01
ok('collected nets reversals', $k['collected'] === 1000000.0 + 500000 + 500000 - 500000, (string) $k['collected']);
ok('collected comparison', $k['collected_cmp'] === 4000000.0);

$oct = end($data['buckets']);
ok('the selected period is always the last column', end($data['buckets'])['current'] === true);
ok('the current bucket matches the tiles', $oct['sales'] === $k['sales'] && $oct['collected'] === $k['collected']);
$sep = $data['buckets'][count($data['buckets']) - 2];
ok('the previous bucket holds the whole month', $sep['sales'] === 13000000.0, (string) $sep['sales']);

// Receivables: a balance as of today, regardless of the selected period.
$r = $data['receivable'];
ok('receivable total = live outstanding', $r['total'] === 3000000.0 + 1500000 + 250000 + 500000 + 9000000, (string) $r['total']);
// (the exact total above already excludes the voided Rp 7.777.777)
ok('voided invoice is in no aging band', !in_array(7777777.0, $r['bands'], true));
ok('6 days late lands in 1–30', $r['bands']['1_30'] === 3000000.0);
ok('103 days late lands in 90+', $r['bands']['90'] === 9000000.0);
ok('not yet due is current', $r['bands']['current'] === 1500000.0 + 250000 + 500000);
ok('overdue = total − current', $r['overdue'] === 12000000.0 && $r['overdue_count'] === 2);
ok('top overdue customers, largest first', array_keys($r['top_overdue']) === ['PT Lama', 'PT Telat']);

// An invoice overpaid by Rp 1 (rounding) must not offset real debt.
$invoice($order('rian', '2026-08-01'), '2026-08-01', 1000000, 1000001);
$r2 = Dashboard::data($pdo, $m, $today, null, true, true)['receivable'];
ok('overpaid invoices are clamped, not negative', $r2['total'] === $r['total']);

// Ranking for the period.
$rank = array_column($data['ranking'], null, 'name');
ok('ranking sorted by sales', $data['ranking'][0]['name'] === 'ridwan');
ok('ridwan sales', $rank['ridwan']['sales'] === 4000000.0 && $rank['ridwan']['orders'] === 3, $rank['ridwan']['sales'] . ' / ' . $rank['ridwan']['orders']);
ok('ridwan collected in period', $rank['ridwan']['collected'] === 1000000.0);
ok('ridwan outstanding now', $rank['ridwan']['outstanding'] === 3000000.0);
ok('angga outstanding counts his old September debt too', $rank['angga']['outstanding'] === 1500000.0 + 500000 + 9000000);
ok('orphan invoices show as —, not dropped', isset($rank['—']) && $rank['—']['sales'] === 250000.0);
ok('ranking totals match the tile', array_sum(array_column($data['ranking'], 'sales')) === $k['sales']);

// Sheets (张数) on the shipping basis: warehouse-confirmed orders, by confirmation date.
$line = fn(int $oid, float $q) => $pdo->prepare("INSERT INTO order_items (order_id,sku,spec,qty,price) VALUES (?, 'ZZ-SHEETS', 'x', ?, 100000)")->execute([$oid, $q]);
$ship = fn(int $oid, ?string $d, string $status = 'approved') => $pdo->prepare('UPDATE orders SET status = ?, wh_date = ? WHERE id = ?')->execute([$status, $d, $oid]);
$line($o1, 20); $line($o1, 5); $ship($o1, '2026-10-02');            // shipped in the period
$late = $order('ridwan', '2026-09-29'); $line($late, 40); $ship($late, '2026-10-03');   // ordered Sep, shipped Oct → October
$early = $order('ridwan', '2026-10-05'); $line($early, 70); $ship($early, null, 'pending_wh');   // ordered Oct, not shipped yet
$line($o4, 99); $ship($o4, '2026-09-03');                           // September: comparison window only
$voidO = $order('ridwan', '2026-10-06'); $line($voidO, 300); $ship($voidO, '2026-10-06');
$invoice($voidO, '2026-10-06', 1000, 0, '2099-01-01', 'Void Co', '2026-10-07 09:00:00');   // invoice voided
$draftOrder = $order('ridwan', '2026-10-08', 'draft'); $line($draftOrder, 500);
$rk = array_column(Dashboard::data($pdo, $m, $today, null, true, true)['ranking'], null, 'name');
ok('sheets: shipped in the period, by ship date (25 + 40 ordered in Sep)', $rk['ridwan']['sheets'] === 65.0, (string) $rk['ridwan']['sheets']);
ok('sheets: not-yet-shipped, voided-invoice and draft orders excluded', $rk['ridwan']['sheets'] !== 135.0 && $rk['ridwan']['sheets'] < 300);
ok('sheets: zero for someone with orders but nothing shipped', ($rk['angga']['sheets'] ?? null) === 0.0);
$kd = Dashboard::data($pdo, $m, $today, null, true, true)['kpi'];
ok('sheets tile = period total, same as the ranking column', $kd['sheets'] === 65.0 && $kd['sheets'] === array_sum(array_column($rk, 'sheets')));
ok('sheets tile compares with the like-for-like window by ship date', $kd['sheets_cmp'] === 99.0, (string) $kd['sheets_cmp']);
$ka = Dashboard::data($pdo, $m, $today, 'angga', false, false, 'sales')['kpi'];
ok("a salesperson's tile counts only their own sheets", $ka['sheets'] === 0.0);
$pdo->exec("DELETE FROM order_items WHERE sku = 'ZZ-SHEETS'");   // keep the fixture clean for the tests below
foreach ([$draftOrder, $late, $early, $voidO] as $tmpId) {
    $pdo->prepare('DELETE FROM invoices WHERE order_id = ?')->execute([$tmpId]);
    $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$tmpId]);
}
$ship($o1, null); $ship($o4, null);

// ── 5) Permissions ───────────────────────────────────────────────────────────
$mine = Dashboard::data($pdo, $m, $today, 'angga', false, false, 'sales');
ok('own scope: only angga\'s sales', $mine['kpi']['sales'] === 2500000.0, (string) $mine['kpi']['sales']);
ok('own scope: only angga\'s orders', $mine['kpi']['orders'] === 2);
ok('no finance access → no cash figures at all', !isset($mine['receivable']) && $mine['kpi']['collected'] === 0.0 && end($mine['buckets'])['collected'] === 0.0);
ok('no performance access → no ranking', !isset($mine['ranking']));
ok('sales role approves nothing', $mine['workload']['to_approve'] === null);

$order('ridwan', '2026-10-11', 'pending_sup');
$order('angga', '2026-10-11', 'pending_sup');
$order('angga', '2026-10-11', 'draft', '价格写错');
$sup = Dashboard::data($pdo, $m, $today, 'nobody', false, false, 'supervisor');
ok('supervisor sees what waits on their stage, company-wide', $sup['workload']['to_approve'] === 2);
$sales = Dashboard::data($pdo, $m, $today, 'angga', false, false, 'sales');
ok('sales sees their own orders in approval', $sales['workload']['pending'] === 1);
ok('sales sees their rejected drafts to fix', $sales['workload']['rework'] === 1);

// ── 6) Hot products group colour code with spec ──────────────────────────────
$hp = $order('ridwan', '2026-10-03');
foreach ([['SJ8001', '4.0*0.30', 10], ['SJ8001', '3.0*0.10', 4], ['SJ8001', '4.0*0.30', 5]] as [$sku, $spec, $q]) {
    $pdo->prepare('INSERT INTO order_items (order_id,sku,color,spec,qty,price) VALUES (?,?,?,?,?,?)')->execute([$hp, $sku, '纯白', $spec, $q, 100]);
}
$hot = Dashboard::data($pdo, $m, $today, null, true, true)['hot'];
ok('same colour, different spec are different products', count(array_filter($hot, fn($h) => $h['sku'] === 'SJ8001')) === 2);
ok('quantities sum within a spec', (int) $hot[0]['qty'] === 15 && $hot[0]['spec'] === '4.0*0.30');

// ── 7) The page ──────────────────────────────────────────────────────────────
$render = function (array $vars): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
    $auth = $GLOBALS['auth'] ?? null;
    $config = $GLOBALS['config'] ?? [];
    extract($vars, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/../views/dashboard/index.php';
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};
$full = Dashboard::data($pdo, $m, $today, null, true, true) + ['recent' => [], 'fin' => true, 'perf' => true];
$html = $render($full);
ok('three period tabs', substr_count($html, 'role="tab"') === 3);
ok('month tab selected', preg_match('/class="active" role="tab" aria-selected="true"\s+href="[^"]*p=month/', $html) === 1);
ok('period title names the current month', str_contains($html, t('dash_now_month')));
ok('like-for-like label on an in-progress month', str_contains($html, t('dash_vs_month_partial')));
ok('one clickable column per bucket', preg_match_all('/class="dcol( current)?"/', $html) === count($full['buckets']));
ok('columns drill into their period', str_contains($html, 'p=month&d=2026-09-01'));
ok('two-series legend when cash is shown', str_contains($html, 'class="sw sales"') && str_contains($html, 'class="sw paid"'));
ok('table view exists', str_contains($html, t('dash_table_view')));
ok('ranking shown', str_contains($html, t('dash_ranking')) && str_contains($html, 'ridwan'));
ok('ranking has the 张数 column', str_contains($html, t('dash_sheets')));
ok('tiles include total sheets', str_contains($html, t('dash_sheets_total')));
ok('aging shown', str_contains($html, t('dash_aging')) && str_contains($html, 'age-90'));
ok('overdue called out on the receivable tile', str_contains($html, '⚠'));
ok('no next arrow from the current period', str_contains($html, 'btn-disabled'));

// A sales user must never get cash numbers in the HTML.
$GLOBALS['auth'] = new AuthStub(['id' => 9, 'name' => 'angga', 'role' => 'sales']);
$limited = Dashboard::data($pdo, $m, $today, 'angga', false, false, 'sales') + ['recent' => [], 'fin' => false, 'perf' => false];
$hs = $render($limited);
ok('sales view: personal labels', str_contains($hs, t('dash_my_sales')));
ok('sales view: no collected tile', !str_contains($hs, t('dash_collected')));
ok('sales view: no receivable / aging', !str_contains($hs, t('dash_receivable')) && !str_contains($hs, t('dash_aging')));
ok('sales view: no ranking', !str_contains($hs, t('dash_ranking')) && !str_contains($hs, 'ridwan'));
ok('sales view: no collected series', !str_contains($hs, 'class="b paid"') && !str_contains($hs, 'class="sw paid"'));
ok('sales view: chart title does not promise collections', str_contains($hs, t('dash_trend_month_sales')) && !str_contains($hs, t('dash_trend_month')));
ok('sales view: workload tiles instead', str_contains($hs, t('dash_in_approval')) && str_contains($hs, t('dash_rework')));

// Past period: a way back to now, and a next arrow.
$hp2 = $render(Dashboard::data($pdo, $past, $today, null, true, true) + ['recent' => [], 'fin' => true, 'perf' => true]);
ok('past period offers "back to now"', str_contains($hp2, t('dash_back_now')));
ok('past period has a next link', str_contains($hp2, 'd=2026-10-01') && !str_contains($hp2, 'btn-disabled'));
ok('past period compares whole months', str_contains($hp2, t('dash_vs_month')) && !str_contains($hp2, t('dash_vs_month_partial')));

// Names come from free-text input — escape them.
$order('<script>alert(1)</script>', '2026-10-12');
$xss = $render(Dashboard::data($pdo, $m, $today, null, true, true) + ['recent' => [], 'fin' => true, 'perf' => true]);
ok('salesperson names are escaped', !str_contains($xss, '<script>alert(1)</script>') && str_contains($xss, '&lt;script&gt;'));

// Indonesian: no raw keys anywhere on the page.
$_SESSION['lang'] = 'id';
$hid = $render($full);
preg_match_all('/\bdash_[a-z0-9_]+/', $hid, $mm);
ok('Indonesian dashboard has no raw keys', $mm[0] === [], implode(' ', array_unique($mm[0])));
ok('Indonesian month name used', str_contains($hid, 'Okt'));
$_SESSION['lang'] = 'zh';
preg_match_all('/\bdash_[a-z0-9_]+/', $html, $mz);
ok('Chinese dashboard has no raw keys', $mz[0] === [], implode(' ', array_unique($mz[0])));

// Every dash_* key used by the view exists in both dictionaries.
$src = (string) file_get_contents(__DIR__ . '/../views/dashboard/index.php');
preg_match_all("/t\\('(dash_[a-z0-9_]+)'/", $src, $used);
// Keys built by concatenation ('dash_now_' . $p) are listed explicitly below.
$keys = array_values(array_filter(array_unique($used[1]), fn($k) => !str_ends_with($k, '_')));
foreach (Dashboard::PERIODS as $pp) {
    $keys[] = 'dash_' . $pp; $keys[] = 'dash_now_' . $pp; $keys[] = 'dash_trend_' . $pp;
    $keys[] = 'dash_vs_' . $pp; $keys[] = 'dash_vs_' . $pp . '_partial'; $keys[] = 'dash_trend_' . $pp . '_sales';
}
foreach (array_keys(Dashboard::AGING) as $band) {
    $keys[] = 'dash_age_' . $band;
}
$missing = [];
foreach (array_unique($keys) as $key) {
    foreach (['zh', 'id'] as $lang) {
        if (!isset(I18N[$lang][$key])) {
            $missing[] = "{$lang}/{$key}";
        }
    }
}
ok('all dashboard keys translated in zh + id', $missing === [], implode(' ', $missing));
