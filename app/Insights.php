<?php
declare(strict_types=1);

/**
 * The three call lists (回访清单), computed nightly from shipped orders and
 * stored in customer_insights; read back with follow-up snoozes applied live.
 *
 *   A 该回访   — due: days since last purchase within [0.9, 1.5] × their usual interval
 *   B 快流失   — 2+ purchase days and past 1.5 × the usual interval
 *   C 值得多跑 — top 20% by 12-month amount and idle for more than 90 days
 *
 * Definitions (docs 6q):
 *   - a purchase = a shipped order (status approved); its date is the first
 *     delivery-order pickup date, else the order date;
 *   - intervals are between distinct purchase DAYS, so two orders on one day
 *     do not make an interval of 0;
 *   - usual interval: mean gap between purchase days (2+ days); with one
 *     purchase day, the median of the customer's industry, else of everyone;
 *   - order_count / total_amount cover the last 12 months.
 */
final class Insights
{
    public const LISTS = ['a', 'b', 'c'];

    /** @return array{due_low:float,due_high:float,red_factor:float,top_share:float,idle_days:int,snooze_followup:int,snooze_no_need:int} */
    public static function params(array $config): array
    {
        $c = (array) ($config['insights'] ?? []);
        return [
            'due_low'         => (float) ($c['due_low'] ?? 0.9),
            'due_high'        => (float) ($c['due_high'] ?? 1.5),
            'red_factor'      => (float) ($c['red_factor'] ?? 2.0),
            'top_share'       => (float) ($c['top_share'] ?? 0.2),
            'idle_days'       => (int) ($c['idle_days'] ?? 90),
            'snooze_followup' => (int) ($c['snooze_followup_days'] ?? 7),
            'snooze_no_need'  => (int) ($c['snooze_no_need_days'] ?? 30),
        ];
    }

    public static function median(array $xs): ?float
    {
        $xs = array_values(array_filter($xs, fn($x) => $x !== null));
        if ($xs === []) {
            return null;
        }
        sort($xs);
        $n = count($xs);
        return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /** Whole days from $from to $to (Y-m-d). */
    public static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    }

    /**
     * Recompute every customer's row. Replaces the table in one transaction,
     * so readers never see a half-built list.
     * @return array{customers:int,a:int,b:int,c:int,threshold:float}
     */
    public static function compute(PDO $pdo, array $config, string $today): array
    {
        $p = self::params($config);
        $since12 = date('Y-m-d', strtotime($today . ' -365 days'));

        // One row per shipped order: customer, purchase day, amount.
        $orders = $pdo->query(
            "SELECT o.id, o.customer_id,
                    COALESCE((SELECT MIN(d.pickup_date) FROM delivery_orders d WHERE d.order_id = o.id AND COALESCE(d.pickup_date,'') <> ''), date(o.created_at)) AS day,
                    COALESCE((SELECT SUM(qty * price) FROM order_items WHERE order_id = o.id), 0) + COALESCE(o.shipping_cost, 0) AS amount
               FROM orders o
              WHERE o.customer_id IS NOT NULL AND o." . Enrich::SHIPPED . '
              ORDER BY o.customer_id, day, o.id'
        )->fetchAll();

        // Pre-platform sales (sales_history, imported from the old bookkeeping app)
        // are purchases too: without them a customer who bought monthly all year
        // looks like a newcomer with one order. Their ids are negated so the two
        // tables never collide in last_order_id.
        $history = [];
        if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='sales_history'")->fetchColumn()) {
            $history = $pdo->query('SELECT -id AS id, customer_id, doc_date AS day, amount FROM sales_history WHERE customer_id IS NOT NULL ORDER BY customer_id, doc_date, id')->fetchAll();
        }

        $per = [];
        foreach (array_merge($history, $orders) as $o) {
            $day = substr((string) $o['day'], 0, 10);
            if ($day > $today) {
                continue;   // a pickup dated in the future is not a purchase yet
            }
            $cid = (int) $o['customer_id'];
            $per[$cid] ??= ['days' => [], 'last_order_id' => 0, 'last_day' => '', 'count12' => 0, 'amount12' => 0.0, 'order_ids12' => []];
            $per[$cid]['days'][$day] = true;
            if ($day >= $per[$cid]['last_day']) {
                $per[$cid]['last_day'] = $day;
                $per[$cid]['last_order_id'] = (int) $o['id'];
            }
            if ($day >= $since12) {
                $per[$cid]['count12']++;
                $per[$cid]['amount12'] += (float) $o['amount'];
                if ((int) $o['id'] > 0) {
                    $per[$cid]['order_ids12'][] = (int) $o['id'];   // top_specs reads order_items only
                }
            }
        }

        $industry = $pdo->query('SELECT id, COALESCE(industry, \'\') FROM customers')->fetchAll(PDO::FETCH_KEY_PAIR);

        // Own interval where there are 2+ purchase days.
        $own = [];
        foreach ($per as $cid => &$r) {
            $days = array_keys($r['days']);
            sort($days);
            $r['purchase_days'] = count($days);
            if (count($days) >= 2) {
                $gaps = [];
                for ($i = 1; $i < count($days); $i++) {
                    $gaps[] = self::days($days[$i - 1], $days[$i]);
                }
                $own[$cid] = array_sum($gaps) / count($gaps);
            }
        }
        unset($r);
        $byIndustry = [];
        foreach ($own as $cid => $iv) {
            $byIndustry[(string) ($industry[$cid] ?? '')][] = $iv;
        }
        $globalMedian = self::median(array_values($own));

        // Top-20% threshold over customers with 12-month spend.
        $amounts = array_values(array_filter(array_map(fn($r) => $r['amount12'], $per), fn($a) => $a > 0));
        rsort($amounts);
        $topN = (int) ceil(count($amounts) * $p['top_share']);
        $threshold = $topN > 0 ? (float) $amounts[$topN - 1] : INF;

        $lastItems = $pdo->prepare('SELECT sku, spec, color, qty FROM order_items WHERE order_id = ? ORDER BY id');
        $lastHistItems = $history !== [] ? $pdo->prepare('SELECT sku, spec, color, qty FROM sales_history_items WHERE history_id = ? ORDER BY line_no') : null;
        $rows = [];
        foreach ($per as $cid => $r) {
            if (isset($own[$cid])) {
                $iv = $own[$cid];
                $src = 'own';
            } else {
                $ind = (string) ($industry[$cid] ?? '');
                $m = $ind !== '' ? self::median($byIndustry[$ind] ?? []) : null;
                [$iv, $src] = $m !== null ? [$m, 'industry'] : [$globalMedian, 'global'];
            }
            $since = self::days($r['last_day'], $today);
            $inA = $iv !== null && $iv > 0 && $since >= $iv * $p['due_low'] && $since <= $iv * $p['due_high'];
            $inB = $iv !== null && $r['purchase_days'] >= 2 && $since > $iv * $p['due_high'];
            $inC = $r['amount12'] > 0 && $r['amount12'] >= $threshold && $since > $p['idle_days'];
            $overdue = $iv !== null ? (int) round($since - $iv) : null;

            if ($r['last_order_id'] < 0 && $lastHistItems !== null) {
                $lastHistItems->execute([-$r['last_order_id']]);
                $lastRows = $lastHistItems->fetchAll();
            } else {
                $lastItems->execute([$r['last_order_id']]);
                $lastRows = $lastItems->fetchAll();
            }
            $items = [];
            foreach ($lastRows as $it) {
                $items[] = trim($it['sku'] . ' ' . $it['spec']) . ' ×' . rtrim(rtrim(number_format((float) $it['qty'], 1, '.', ''), '0'), '.');
            }
            $rows[] = [
                $cid, $today, $r['last_day'], $r['last_order_id'], implode(', ', $items),
                $r['count12'], $r['purchase_days'], round($r['amount12'], 2),
                $iv !== null ? round($iv, 1) : null, $src, $since,
                $inA ? 1 : 0, $inB ? 1 : 0, $inC ? 1 : 0,
                $overdue, ($inB && $iv > 0 && $overdue > $iv * $p['red_factor']) ? 1 : 0,
                self::top_specs($pdo, $r['order_ids12']),
            ];
        }

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM customer_insights');
        $ins = $pdo->prepare('INSERT INTO customer_insights (customer_id, computed_on, last_order_date, last_order_id, last_items, order_count, purchase_days,
            total_amount, avg_interval, interval_source, days_since, in_a, in_b, in_c, overdue_days, overdue_red, top_specs)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        // Daily membership log: the manager view's "processing rate" needs to
        // know who was on a list this month, after the table is rebuilt.
        $hist = $pdo->prepare('INSERT OR REPLACE INTO insights_history (day, customer_id, lists) VALUES (?,?,?)');
        $pdo->prepare('DELETE FROM insights_history WHERE day = ?')->execute([$today]);
        foreach ($rows as $row) {
            $l = ($row[11] ? 'a' : '') . ($row[12] ? 'b' : '') . ($row[13] ? 'c' : '');
            if ($l !== '') {
                $hist->execute([$today, $row[0], $l]);
            }
        }
        $pdo->prepare("INSERT OR REPLACE INTO app_meta (k, v) VALUES ('insights_computed_on', ?)")->execute([$today]);
        $pdo->commit();

        return [
            'customers' => count($rows),
            'a' => array_sum(array_column($rows, 11)),
            'b' => array_sum(array_column($rows, 12)),
            'c' => array_sum(array_column($rows, 13)),
            'threshold' => is_finite($threshold) ? $threshold : 0.0,
        ];
    }

    /** The two specs bought most (by sheets) in the given orders. */
    private static function top_specs(PDO $pdo, array $orderIds): string
    {
        if ($orderIds === []) {
            return '';
        }
        $in = implode(',', array_map('intval', $orderIds));
        $rows = $pdo->query("SELECT COALESCE(NULLIF(TRIM(spec),''), sku) s, SUM(qty) q FROM order_items WHERE order_id IN ({$in}) GROUP BY s ORDER BY q DESC LIMIT 2")->fetchAll();
        return implode(' / ', array_map(fn($r) => $r['s'] . ' ×' . rtrim(rtrim(number_format((float) $r['q'], 1, '.', ''), '0'), '.'), $rows));
    }

    /**
     * Customers currently snoozed: a follow-up in the last 7 days, or "no
     * need" in the last 30. Read live, so an item disappears the moment it is
     * handled, not the next night.
     * @return array<int,array{kind:string,until:string}>
     */
    public static function snoozed(PDO $pdo, array $config, string $today): array
    {
        $p = self::params($config);
        $out = [];
        $rows = $pdo->query("SELECT customer_id, kind, MAX(date(created_at)) d FROM customer_followups GROUP BY customer_id, kind")->fetchAll();
        foreach ($rows as $r) {
            $days = $r['kind'] === 'no_need' ? $p['snooze_no_need'] : $p['snooze_followup'];
            $until = date('Y-m-d', strtotime($r['d'] . " +{$days} days"));
            if ($until > $today && (!isset($out[(int) $r['customer_id']]) || $until > $out[(int) $r['customer_id']]['until'])) {
                $out[(int) $r['customer_id']] = ['kind' => (string) $r['kind'], 'until' => $until];
            }
        }
        return $out;
    }

    /**
     * The three lists, each sorted by 12-month amount (largest first).
     * @param ?string $owner limit to one salesperson's customers (case/space-insensitive); null = everyone
     * @return array{a:array,b:array,c:array}
     */
    public static function lists(PDO $pdo, array $config, string $today, ?string $owner = null): array
    {
        $snoozed = self::snoozed($pdo, $config, $today);
        $sql = 'SELECT i.*, c.name, c.company, c.owner, c.phone, c.industry, c.industry_source, c.industry_pending, c.region
                  FROM customer_insights i JOIN customers c ON c.id = i.customer_id
                 WHERE (i.in_a = 1 OR i.in_b = 1 OR i.in_c = 1)';
        $args = [];
        if ($owner !== null) {
            $sql .= ' AND LOWER(TRIM(c.owner)) = ?';
            $args[] = mb_strtolower(trim($owner));
        }
        $sql .= ' ORDER BY i.total_amount DESC, i.customer_id';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $out = ['a' => [], 'b' => [], 'c' => []];
        foreach ($st->fetchAll() as $r) {
            if (isset($snoozed[(int) $r['customer_id']])) {
                continue;
            }
            foreach (self::LISTS as $l) {
                if ((int) $r['in_' . $l] === 1) {
                    $out[$l][] = $r;
                }
            }
        }
        return $out;
    }

    // ───────────────────────────── manager view ─────────────────────────────

    /**
     * Per salesperson: customers owned, customers followed up in the last 30
     * days, and this month's list processing rate (customers that appeared on
     * any list this month and got a follow-up or "no need" on/after that day).
     * @return array<string,array{customers:int,fu30:int,listed:int,handled:int}>
     */
    public static function activity(PDO $pdo, string $today): array
    {
        $out = [];
        foreach ($pdo->query("SELECT TRIM(owner) o, COUNT(*) n FROM customers WHERE TRIM(COALESCE(owner,'')) <> '' GROUP BY TRIM(owner)") as $r) {
            $out[(string) $r['o']] = ['customers' => (int) $r['n'], 'fu30' => 0, 'listed' => 0, 'handled' => 0];
        }
        $since30 = date('Y-m-d', strtotime($today . ' -30 days'));
        $st = $pdo->prepare("SELECT TRIM(c.owner) o, COUNT(DISTINCT f.customer_id) n FROM customer_followups f JOIN customers c ON c.id = f.customer_id
                              WHERE f.kind = 'followup' AND date(f.created_at) > ? AND TRIM(COALESCE(c.owner,'')) <> '' GROUP BY TRIM(c.owner)");
        $st->execute([$since30]);
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['o']])) {
                $out[$r['o']]['fu30'] = (int) $r['n'];
            }
        }
        $month = substr($today, 0, 7) . '-01';
        $st = $pdo->prepare("SELECT TRIM(c.owner) o, h.customer_id, MIN(h.day) first_day
               FROM insights_history h JOIN customers c ON c.id = h.customer_id
              WHERE h.day >= ? AND h.day <= ? AND TRIM(COALESCE(c.owner,'')) <> ''
              GROUP BY h.customer_id, TRIM(c.owner)");
        $st->execute([$month, $today]);
        $done = $pdo->prepare('SELECT 1 FROM customer_followups WHERE customer_id = ? AND date(created_at) >= ? AND date(created_at) <= ? LIMIT 1');
        foreach ($st->fetchAll() as $r) {
            if (!isset($out[$r['o']])) {
                continue;
            }
            $out[$r['o']]['listed']++;
            $done->execute([$r['customer_id'], $r['first_day'], $today]);
            $out[$r['o']]['handled'] += $done->fetchColumn() ? 1 : 0;
        }
        uasort($out, fn($a, $b) => $b['customers'] <=> $a['customers']);
        return $out;
    }

    /**
     * Customers with shipped orders, grouped by industry or region: count and
     * 12-month amount.
     * @return array<string,array{n:int,amount:float}>
     */
    public static function distribution(PDO $pdo, string $field): array
    {
        if (!in_array($field, ['industry', 'region'], true)) {
            throw new InvalidArgumentException('field');
        }
        $out = [];
        $rows = $pdo->query("SELECT COALESCE(NULLIF(c.{$field}, ''), 'unknown') k, COUNT(*) n, COALESCE(SUM(i.total_amount), 0) a
                               FROM customer_insights i JOIN customers c ON c.id = i.customer_id GROUP BY k ORDER BY a DESC");
        foreach ($rows as $r) {
            $out[(string) $r['k']] = ['n' => (int) $r['n'], 'amount' => (float) $r['a']];
        }
        return $out;
    }

    /**
     * Data gaps among customers that matter (those with shipped orders), per
     * salesperson. Customers who never ordered are left out on purpose: most
     * are a bare name, and counting them would bury the gaps that affect the lists.
     * @return array<string,array{customers:int,industry:int,region:int,phone:int}>
     */
    public static function quality(PDO $pdo): array
    {
        $out = [];
        $rows = $pdo->query("SELECT COALESCE(NULLIF(TRIM(c.owner), ''), '') o, COUNT(*) n,
                    SUM(c.industry IS NULL OR (c.industry_pending = 1 AND COALESCE(c.industry_source, '') <> 'manual')) ind,
                    SUM(COALESCE(c.region, 'unknown') = 'unknown') reg,
                    SUM(TRIM(COALESCE(c.phone, '')) = '') ph
               FROM customer_insights i JOIN customers c ON c.id = i.customer_id GROUP BY o ORDER BY n DESC");
        foreach ($rows as $r) {
            $out[(string) $r['o']] = ['customers' => (int) $r['n'], 'industry' => (int) $r['ind'], 'region' => (int) $r['reg'], 'phone' => (int) $r['ph']];
        }
        return $out;
    }

    /**
     * Shipped orders with a map point.
     * @return array<int,array{lat:float,lng:float,amount:float,owner:string,customer:string,customer_id:int,day:string}>
     */
    public static function map_points(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT o.delivery_lat lat, o.delivery_lng lng, TRIM(COALESCE(o.submitter, '')) owner, o.customer_id, COALESCE(c.name, o.customer_name) customer,
                    date(o.created_at) day, COALESCE((SELECT SUM(qty * price) FROM order_items WHERE order_id = o.id), 0) + COALESCE(o.shipping_cost, 0) amount
               FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
              WHERE o." . Enrich::SHIPPED . ' AND o.delivery_lat IS NOT NULL AND o.delivery_lng IS NOT NULL ORDER BY amount DESC');
        return array_map(fn($r) => [
            'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'amount' => (float) $r['amount'], 'owner' => (string) $r['owner'],
            'customer' => (string) $r['customer'], 'customer_id' => (int) $r['customer_id'], 'day' => (string) $r['day'],
        ], $rows->fetchAll());
    }

    /**
     * Web-Mercator layout for a fixed OpenStreetMap tile grid covering the
     * given box: which tiles to draw and where each point lands, in pixels of
     * that grid. Plain SVG on the page; no map library.
     * @return array{zoom:int,x0:int,y0:int,cols:int,rows:int,points:array}
     */
    public static function map_layout(array $points, array $box, int $zoom): array
    {
        $px = function (float $lat, float $lng) use ($zoom): array {
            $n = 256 * (2 ** $zoom);
            $x = ($lng + 180) / 360 * $n;
            $s = sin(deg2rad($lat));
            $y = (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * $n;
            return [$x, $y];
        };
        [$xa, $ya] = $px($box['north'], $box['west']);
        [$xb, $yb] = $px($box['south'], $box['east']);
        $x0 = (int) floor($xa / 256);
        $y0 = (int) floor($ya / 256);
        $cols = (int) floor($xb / 256) - $x0 + 1;
        $rows = (int) floor($yb / 256) - $y0 + 1;
        $inside = [];
        foreach ($points as $p) {
            if ($p['lat'] > $box['north'] || $p['lat'] < $box['south'] || $p['lng'] < $box['west'] || $p['lng'] > $box['east']) {
                continue;
            }
            [$x, $y] = $px($p['lat'], $p['lng']);
            $inside[] = $p + ['x' => round($x - $x0 * 256, 1), 'y' => round($y - $y0 * 256, 1)];
        }
        return ['zoom' => $zoom, 'x0' => $x0, 'y0' => $y0, 'cols' => $cols, 'rows' => $rows, 'points' => $inside];
    }

    /**
     * The latest follow-ups of the given customers, newest first.
     * @return array<int,array> customer id => rows
     */
    public static function recent_followups(PDO $pdo, array $customerIds, int $per = 2): array
    {
        $ids = array_values(array_unique(array_map('intval', $customerIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = $pdo->query('SELECT * FROM customer_followups WHERE customer_id IN (' . implode(',', $ids) . ') ORDER BY id DESC');
        foreach ($rows as $r) {
            $cid = (int) $r['customer_id'];
            if (count($out[$cid] ?? []) < $per) {
                $out[$cid][] = $r;
            }
        }
        return $out;
    }

    /** Record a follow-up or a "no need" (reason required). Also stamps customers.last_contact. */
    public static function record(PDO $pdo, int $customerId, string $kind, string $note, ?array $user, string $today): int
    {
        if (!in_array($kind, ['followup', 'no_need'], true)) {
            throw new InvalidArgumentException('kind');
        }
        $pdo->prepare('INSERT INTO customer_followups (customer_id, user_id, user_name, kind, note, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$customerId, $user['id'] ?? null, (string) ($user['name'] ?? ''), $kind, $note, $today . ' ' . date('H:i:s')]);
        $id = (int) $pdo->lastInsertId();
        if ($kind === 'followup') {
            $pdo->prepare('UPDATE customers SET last_contact = ? WHERE id = ?')->execute([$today, $customerId]);
        }
        return $id;
    }
}
