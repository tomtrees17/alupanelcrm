<?php
declare(strict_types=1);

/**
 * HR-07 销售周评 (docs/任务卡-拜访周评.md): one section per field salesperson
 * for the boss and supervisors, never for the salesperson. Rules count every
 * number; the model only writes the overview and two or three sentences per
 * person. No ranking, no scores.
 *
 * Attribution: a salesperson's CRM account(s) (users.employee_id → users.name)
 * are what orders.submitter and customers.owner hold, so the results block
 * uses the dashboard's own ranking query (Dashboard::ranking) by those names.
 */
final class SalesReview
{
    /** A quote left this many days before the week's end without a visit or order is "该跟的". */
    public const PENDING_DAYS = 7;
    /** Names listed per person (the count is always complete). */
    public const PENDING_LIST = 8;
    public const UNMAPPED = 'Pengirim belum dikenali';

    // ───────────────────────────── weeks ─────────────────────────────

    /** Monday of the week before the one $today is in. */
    public static function last_week(string $today): string
    {
        $d = new DateTimeImmutable($today);
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' day')->modify('-7 day')->format('Y-m-d');
    }

    public static function valid_week(string $w): bool
    {
        return Hr::valid_date($w) && (new DateTimeImmutable($w))->format('N') === '1';
    }

    /** @return array{0:string,1:string} Monday, Sunday */
    public static function bounds(string $monday): array
    {
        return [$monday, HrContract::add_days($monday, 6)];
    }

    // ───────────────────────────── who ─────────────────────────────

    /**
     * Field salespeople (attendance_mode = visit) in service at some point of
     * the week, with their CRM account names.
     * @return list<array{id:int,full_name:string,department:?string,names:list<string>}>
     */
    public static function people(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare("SELECT id, full_name, department FROM employees
                              WHERE attendance_mode = 'visit' AND (join_date IS NULL OR join_date = '' OR join_date <= ?)
                                AND (employment_status <> 'resigned' OR COALESCE(resign_date, '') >= ?)
                              ORDER BY full_name COLLATE NOCASE, id");
        $st->execute([$to, $from]);
        $out = [];
        $names = $pdo->prepare("SELECT DISTINCT TRIM(name) FROM users WHERE employee_id = ? AND TRIM(name) <> ''");
        foreach ($st as $e) {
            $names->execute([(int) $e['id']]);
            $out[] = ['id' => (int) $e['id'], 'full_name' => (string) $e['full_name'], 'department' => $e['department'],
                      'names' => array_map('strval', $names->fetchAll(PDO::FETCH_COLUMN))];
        }
        return $out;
    }

    // ───────────────────────────── figures ─────────────────────────────

    /**
     * Everything the review shows, by rule.
     * @return array{week:string,from:string,to:string,team:array,people:array<int,array>}
     */
    public static function compute(PDO $pdo, string $monday): array
    {
        [$from, $to] = self::bounds($monday);
        [$pFrom, $pTo] = self::bounds(HrContract::add_days($monday, -7));
        $people = self::people($pdo, $from, $to);

        $visits = self::visit_counts($pdo, $from, $to);
        $prevVisits = self::visit_counts($pdo, $pFrom, $pTo);
        $rank = self::by_name(Dashboard::ranking($pdo, $from, $to, $to, true));
        $prevRank = self::by_name(Dashboard::ranking($pdo, $pFrom, $pTo, $pTo, true));
        $pending = self::pending_quotes($pdo, $to);

        $out = [];
        foreach ($people as $p) {
            $id = $p['id'];
            $v = $visits[$id] ?? self::empty_visits();
            $res = ['orders' => 0, 'sheets' => 0.0, 'sales' => 0.0, 'collected' => 0.0];
            $prev = $res;
            foreach ($p['names'] as $n) {
                foreach ($res as $k => $_) {
                    $res[$k] += $rank[$n][$k] ?? 0;
                    $prev[$k] += $prevRank[$n][$k] ?? 0;
                }
            }
            $mine = $pending[$id] ?? [];
            $out[$id] = [
                'employee_id' => $id, 'name' => $p['full_name'], 'department' => $p['department'], 'accounts' => $p['names'],
                'visits' => $v + ['prev' => ($prevVisits[$id] ?? self::empty_visits())['total']],
                'follow' => [
                    'pending_count' => count($mine),
                    'pending' => array_slice($mine, 0, self::PENDING_LIST),
                    'a_unvisited' => self::a_unvisited($pdo, $p['names'], $from, $to),
                ],
                'results' => $res + ['prev' => $prev] + self::overdue($pdo, $p['names'], $to),
            ];
        }

        $active = [];
        $idle = [];
        foreach ($out as $r) {
            if ($r['visits']['total'] > 0) {
                $active[] = $r['name'];
            } else {
                $idle[] = $r['name'];
            }
        }
        $facts = VisitImport::week_facts($pdo, $from, $to);
        $team = [
            'visits' => $facts['visits_total'], 'visits_last_week' => $facts['visits_last_week'],
            'unmapped_visits' => (int) ($facts['per_sales'][self::UNMAPPED]['visits'] ?? 0),
            'active' => $active, 'zero_visits' => $idle,
            'order_regions' => self::order_regions($pdo, $from, $to),
            'visit_areas' => $facts['areas'], 'competitors' => $facts['competitors'],
        ];
        return ['week' => $monday, 'from' => $from, 'to' => $to, 'team' => $team, 'people' => $out];
    }

    private static function empty_visits(): array
    {
        return ['total' => 0, 'baru' => 0, 'lama' => 0, 'outcomes' => array_fill_keys([...array_keys(Visits::OUTCOMES), Visits::UNKNOWN], 0),
                'wa' => 0, 'tagged' => 0];
    }

    /** Per employee: visits, Baru / Lama (as written, else as matched), results, tags (WhatsApp reports only). */
    private static function visit_counts(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare("SELECT employee_id, outcome, COALESCE(status_lama_baru, system_lama_baru) lb, source, has_tags
                               FROM visits WHERE employee_id IS NOT NULL AND date(visited_at) BETWEEN ? AND ?");
        $st->execute([$from, $to]);
        $out = [];
        foreach ($st as $v) {
            $id = (int) $v['employee_id'];
            $out[$id] ??= self::empty_visits();
            $out[$id]['total']++;
            $out[$id]['baru'] += $v['lb'] === 'baru' ? 1 : 0;
            $out[$id]['lama'] += $v['lb'] === 'lama' ? 1 : 0;
            $o = (string) $v['outcome'];
            $out[$id]['outcomes'][$o] = ($out[$id]['outcomes'][$o] ?? 0) + 1;
            if ($v['source'] === 'wa_import') {
                $out[$id]['wa']++;
                $out[$id]['tagged'] += (int) $v['has_tags'];
            }
        }
        return $out;
    }

    /** Dashboard ranking rows keyed by submitter. */
    private static function by_name(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['name']] = $r;
        }
        return $out;
    }

    /**
     * 报价未跟, as of $to: each store's last visit up to $to is a Penawaran more
     * than PENDING_DAYS days before $to, and no order for that customer since.
     * Belongs to whoever made that quote. Oldest quote first.
     * @return array<int,list<array{kind:string,id:int,name:string,quoted:string,days:int}>>
     */
    public static function pending_quotes(PDO $pdo, string $to): array
    {
        $rows = $pdo->prepare("SELECT v.id, v.employee_id, v.customer_id, v.prospect_id, v.outcome, date(v.visited_at) d,
                                      COALESCE(c.name, p.name, v.store_name_raw) name, p.merged_into
                                 FROM visits v LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN prospects p ON p.id = v.prospect_id
                                WHERE date(v.visited_at) <= ? AND (v.customer_id IS NOT NULL OR v.prospect_id IS NOT NULL)
                                ORDER BY v.visited_at, v.id");
        $rows->execute([$to]);
        $last = [];
        foreach ($rows as $v) {
            $key = $v['customer_id'] !== null ? 'c' . $v['customer_id'] : 'p' . $v['prospect_id'];
            $last[$key] = $v;   // ordered by time: the last one wins
        }
        $cut = HrContract::add_days($to, -self::PENDING_DAYS);
        $byCustomer = $pdo->prepare("SELECT 1 FROM orders WHERE customer_id = ? AND status <> 'draft' AND date(created_at) > ? AND date(created_at) <= ? LIMIT 1");
        $byName = $pdo->prepare("SELECT 1 FROM orders WHERE status <> 'draft' AND date(created_at) > ? AND date(created_at) <= ?
                                    AND (LOWER(TRIM(customer_name)) = ? OR LOWER(TRIM(company)) = ?) LIMIT 1");
        $out = [];
        foreach ($last as $v) {
            if ($v['outcome'] !== 'penawaran' || $v['employee_id'] === null || $v['d'] >= $cut || $v['merged_into'] !== null) {
                continue;
            }
            if ($v['customer_id'] !== null) {
                $byCustomer->execute([(int) $v['customer_id'], $v['d'], $to]);
                $ordered = (bool) $byCustomer->fetchColumn();
            } else {   // a prospect has no customer id yet: an order under its name counts
                $n = mb_strtolower(trim((string) $v['name']));
                $byName->execute([$v['d'], $to, $n, $n]);
                $ordered = (bool) $byName->fetchColumn();
            }
            if ($ordered) {
                continue;
            }
            $out[(int) $v['employee_id']][] = [
                'kind' => $v['customer_id'] !== null ? 'customer' : 'prospect',
                'id' => (int) ($v['customer_id'] ?? $v['prospect_id']), 'name' => (string) $v['name'], 'quoted' => (string) $v['d'],
                'days' => (int) ((strtotime($to) - strtotime((string) $v['d'])) / 86400),
            ];
        }
        foreach ($out as &$list) {
            usort($list, fn($a, $b) => [$a['quoted'], $a['name']] <=> [$b['quoted'], $b['name']]);
        }
        return $out;
    }

    /** A-list (回访清单 该回访) customers owned by these accounts with no visit in the week. */
    private static function a_unvisited(PDO $pdo, array $names, string $from, string $to): int
    {
        if ($names === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($names), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM customer_insights i JOIN customers c ON c.id = i.customer_id
                              WHERE i.in_a = 1 AND TRIM(c.owner) IN ({$in})
                                AND NOT EXISTS (SELECT 1 FROM visits v WHERE v.customer_id = c.id AND date(v.visited_at) BETWEEN ? AND ?)");
        $st->execute([...$names, $from, $to]);
        return (int) $st->fetchColumn();
    }

    /** Unpaid invoices past due by $to, of customers these accounts own. */
    private static function overdue(PDO $pdo, array $names, string $to): array
    {
        if ($names === []) {
            return ['overdue' => 0.0, 'overdue_invoices' => 0];
        }
        $in = implode(',', array_fill(0, count($names), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(i.total - i.amount_paid), 0) s
                               FROM invoices i JOIN orders o ON o.id = i.order_id JOIN customers c ON c.id = o.customer_id
                              WHERE i.voided_at IS NULL AND i.total - i.amount_paid > 0.5 AND COALESCE(i.due_date, '') <> '' AND i.due_date < ?
                                AND TRIM(c.owner) IN ({$in})");
        $st->execute([$to, ...$names]);
        $r = $st->fetch();
        return ['overdue' => round((float) $r['s'], 2), 'overdue_invoices' => (int) $r['n']];
    }

    /** Orders placed in the week per delivery region (region of the customer when the order has none). */
    private static function order_regions(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(o.delivery_region, ''), NULLIF(c.region, ''), 'unknown') reg, COUNT(*) n
                               FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                              WHERE o.status <> 'draft' AND date(o.created_at) BETWEEN ? AND ? GROUP BY reg ORDER BY n DESC, reg");
        $st->execute([$from, $to]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    // ───────────────────────────── AI ─────────────────────────────

    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['team_zh', 'team_id', 'people'], 'properties' => [
            'team_zh' => ['type' => 'string'], 'team_id' => ['type' => 'string'],
            'people' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['key', 'zh', 'id'], 'properties' => [
                'key' => ['type' => 'string'], 'zh' => ['type' => 'string'], 'id' => ['type' => 'string'],
            ]]],
        ]];
    }

    /** Sentences in a comment: ends of 。！？ or . ! ? followed by a space / the end ("Rp 1.500.000" is not three). */
    public static function sentences(string $text): int
    {
        $t = trim($text);
        if ($t === '') {
            return 0;
        }
        $n = preg_match_all('/[。！？]|[.!?](?=\s|$)/u', $t);
        return max(1, (int) $n + (preg_match('/[。！？.!?]\s*$/u', $t) ? 0 : 1));
    }

    /**
     * What the model reads: names, numbers, store names. No phones, no addresses,
     * no account names. Only people who visited this week get a comment.
     * @return array{0:array,1:array<string,int>} [payload, key => employee id]
     */
    public static function ai_input(array $data): array
    {
        $people = [];
        $keys = [];
        $i = 0;
        foreach ($data['people'] as $id => $p) {
            if ($p['visits']['total'] === 0) {
                continue;
            }
            $key = 'S' . (++$i);
            $keys[$key] = (int) $id;
            $v = $p['visits'];
            $people[] = [
                'key' => $key, 'name' => $p['name'],
                'visits' => ['this_week' => $v['total'], 'last_week' => $v['prev'], 'baru' => $v['baru'], 'lama' => $v['lama'],
                             'results' => array_filter($v['outcomes']), 'whatsapp_reports' => $v['wa'], 'with_tags' => $v['tagged']],
                'to_follow' => ['quotes_without_follow_up' => $p['follow']['pending_count'],
                                'quote_shops' => array_map(fn($q) => ['shop' => $q['name'], 'days_since_quote' => $q['days']], $p['follow']['pending']),
                                'a_list_customers_not_visited' => $p['follow']['a_unvisited']],
                'results' => ['orders' => $p['results']['orders'], 'sheets_shipped' => $p['results']['sheets'], 'invoiced_idr' => round($p['results']['sales']),
                              'collected_idr' => round($p['results']['collected']), 'overdue_unpaid_idr' => round($p['results']['overdue'])],
            ];
        }
        $t = $data['team'];
        $payload = ['week' => [$data['from'], $data['to']], 'team' => [
            'visits' => $t['visits'], 'visits_last_week' => $t['visits_last_week'], 'visits_from_unknown_senders' => $t['unmapped_visits'],
            'visiting' => $t['active'], 'no_visits_this_week' => $t['zero_visits'], 'orders_by_region' => $t['order_regions'],
            'visit_areas' => $t['visit_areas'], 'competitors' => $t['competitors'],
        ], 'people' => $people];
        return [$payload, $keys];
    }

    // ───────────────────────────── generate ─────────────────────────────

    /**
     * Compute, ask for comments, store. Always a new record (a regeneration
     * keeps the old one). AI off / over budget / failed: figures only.
     * @return array{id:int,status:string,error:string}
     */
    public static function generate(PDO $pdo, array $config, string $monday, string $by): array
    {
        $data = self::compute($pdo, $monday);
        [$payload, $keys] = self::ai_input($data);
        $comments = [];
        $team = ['zh' => null, 'id' => null];
        $callId = null;
        $error = '';
        if (!AiTask::enabled($config)) {
            $error = 'AI off';
        } else {
            $r = AiTask::run($pdo, $config, 'sales_review', [
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'schema' => self::schema(),
                'check' => function (array $d) use ($keys): string {
                    $got = array_column($d['people'], 'key');
                    sort($got);
                    $want = array_keys($keys);
                    sort($want);
                    if ($got !== $want) {
                        return 'people keys must be exactly: ' . implode(', ', array_keys($keys));
                    }
                    foreach ($d['people'] as $p) {
                        foreach (['zh', 'id'] as $l) {
                            if (trim($p[$l]) === '' || self::sentences($p[$l]) > 3) {
                                return "{$p['key']}.{$l}: one to three sentences";
                            }
                        }
                    }
                    return trim($d['team_zh']) === '' || trim($d['team_id']) === '' ? 'team overview missing' : '';
                },
            ]);
            $callId = $r['call_id'];
            if ($r['status'] === 'ok') {
                $team = ['zh' => trim($r['data']['team_zh']), 'id' => trim($r['data']['team_id'])];
                foreach ($r['data']['people'] as $p) {
                    $comments[$keys[$p['key']]] = ['zh' => trim($p['zh']), 'id' => trim($p['id'])];
                }
            } else {
                $error = $r['status'] . ': ' . $r['error'];
            }
        }
        $status = $team['zh'] !== null ? 'ok' : 'ai_skipped';

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO sales_reviews (week, generated_by, team_zh, team_id, data_json, ai_call_id, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$monday, $by, $team['zh'], $team['id'],
                       json_encode(['from' => $data['from'], 'to' => $data['to'], 'team' => $data['team']], JSON_UNESCAPED_UNICODE), $callId, $status]);
        $id = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO sales_review_items (review_id, employee_id, metrics_json, comment_zh, comment_id) VALUES (?,?,?,?,?)');
        foreach ($data['people'] as $eid => $p) {
            $ins->execute([$id, $eid, json_encode($p, JSON_UNESCAPED_UNICODE), $comments[$eid]['zh'] ?? null, $comments[$eid]['id'] ?? null]);
        }
        $pdo->commit();
        audit($pdo, 'hr', 'create', 'sales_reviews', $id, $monday, "sales_reviews: {$monday} by {$by}, {$status}" . ($error !== '' ? " ({$error})" : ''));
        return ['id' => $id, 'status' => $status, 'error' => $error];
    }

    /**
     * Nightly step: last week's review, once. A missed Monday is caught up the
     * next night. The boss gets one todo per week.
     * @return ?array the generate() result, or null when it already existed
     */
    public static function nightly(PDO $pdo, array $config, string $today): ?array
    {
        $week = self::last_week($today);
        $st = $pdo->prepare('SELECT 1 FROM sales_reviews WHERE week = ? LIMIT 1');
        $st->execute([$week]);
        if ($st->fetchColumn()) {
            return null;
        }
        $r = self::generate($pdo, $config, $week, 'cron');
        $has = $pdo->prepare("SELECT 1 FROM hr_todos WHERE type = 'sales_review' AND ref = ?");
        $has->execute([$week]);
        if (!$has->fetchColumn()) {
            $pdo->prepare("INSERT INTO hr_todos (type, employee_id, ref, due_date) VALUES ('sales_review', NULL, ?, ?)")->execute([$week, $today]);
        }
        return $r;
    }

    // ───────────────────────────── read ─────────────────────────────

    /** The newest record of a week, with its version number. */
    public static function latest(PDO $pdo, string $week): ?array
    {
        $st = $pdo->prepare('SELECT r.*, (SELECT COUNT(*) FROM sales_reviews x WHERE x.week = r.week AND x.id <= r.id) version
                               FROM sales_reviews r WHERE r.week = ? ORDER BY r.id DESC LIMIT 1');
        $st->execute([$week]);
        $r = $st->fetch();
        if (!$r) {
            return null;
        }
        $r['data'] = json_decode((string) $r['data_json'], true) ?: [];
        return $r;
    }

    /** A record's sections the viewer may read (department heads: own department), by name. */
    public static function items(PDO $pdo, int $reviewId, array $viewer): array
    {
        [$w, $a] = Hr::scope_sql($viewer);
        $st = $pdo->prepare("SELECT s.*, e.full_name, e.department FROM sales_review_items s JOIN employees e ON e.id = s.employee_id
                              WHERE s.review_id = ? AND {$w} ORDER BY e.full_name COLLATE NOCASE, e.id");
        $st->execute([$reviewId, ...$a]);
        return array_map(function (array $r): array {
            $r['m'] = json_decode((string) $r['metrics_json'], true) ?: [];
            return $r;
        }, $st->fetchAll());
    }

    /** Excel for the weekly meeting: one row per salesperson, figures then the comment in both languages. */
    public static function export_headers(): array
    {
        $h = [t('srv_x_sales'), t('srv_x_visits'), t('srv_x_visits_prev'), 'Baru', 'Lama'];
        foreach ([...array_keys(Visits::OUTCOMES), Visits::UNKNOWN] as $o) {
            $h[] = Visits::outcome_label($o);
        }
        return [...$h, t('srv_x_tagged'), t('srv_x_pending'), t('srv_x_pending_names'), t('srv_x_a_unvisited'),
                t('srv_x_orders'), t('srv_x_sheets'), t('srv_x_sales_amt'), t('srv_x_collected'), t('srv_x_overdue'), t('srv_x_comment_zh'), t('srv_x_comment_id')];
    }

    public static function export_row(array $item): array
    {
        $m = $item['m'];
        $v = $m['visits'];
        $row = [(string) $item['full_name'], $v['total'], $v['prev'], $v['baru'], $v['lama']];
        foreach ([...array_keys(Visits::OUTCOMES), Visits::UNKNOWN] as $o) {
            $row[] = (int) ($v['outcomes'][$o] ?? 0);
        }
        return [...$row, $v['wa'] ? "{$v['tagged']}/{$v['wa']}" : '', $m['follow']['pending_count'],
                implode(', ', array_map(fn($q) => $q['name'] . ' (' . Hr::dmy($q['quoted']) . ')', $m['follow']['pending'])), $m['follow']['a_unvisited'],
                $m['results']['orders'], $m['results']['sheets'], round($m['results']['sales']), round($m['results']['collected']), round($m['results']['overdue']),
                (string) ($item['comment_zh'] ?? ''), (string) ($item['comment_id'] ?? '')];
    }

    /** Weeks with a review, newest first. */
    public static function weeks(PDO $pdo): array
    {
        return $pdo->query('SELECT DISTINCT week FROM sales_reviews ORDER BY week DESC LIMIT 52')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Who may open the page: the boss and visits_all see everyone; a department
     * head sees their department when it has field salespeople. Sales do not.
     * @return array viewer (scope all / department), or scope none
     */
    public static function viewer(PDO $pdo): array
    {
        $v = Hr::viewer($pdo);
        $auth = $GLOBALS['auth'] ?? null;
        if (($auth && $auth->isAdmin()) || can_access('visits_all')) {
            return ['scope' => 'all', 'department' => null, 'employee_id' => $v['employee_id']];
        }
        if ($v['scope'] === 'department') {
            $st = $pdo->prepare("SELECT 1 FROM employees WHERE department = ? AND attendance_mode = 'visit' LIMIT 1");
            $st->execute([$v['department']]);
            if ($st->fetchColumn()) {
                return ['scope' => 'department', 'department' => $v['department'], 'employee_id' => $v['employee_id']];
            }
        }
        return ['scope' => 'none', 'department' => null, 'employee_id' => null];
    }
}
