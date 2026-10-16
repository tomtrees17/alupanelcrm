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

        $per = [];
        foreach ($orders as $o) {
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
                $per[$cid]['order_ids12'][] = (int) $o['id'];
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

            $lastItems->execute([$r['last_order_id']]);
            $items = [];
            foreach ($lastItems->fetchAll() as $it) {
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
        $sql = 'SELECT i.*, c.name, c.company, c.owner, c.phone, c.industry, c.region
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
