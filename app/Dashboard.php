<?php
declare(strict_types=1);

/**
 * Dashboard figures for a day / week / month.
 *
 * Definitions, chosen so the numbers match what finance sees elsewhere:
 *   销售额 (sales)      = invoices issued in the period (voided excluded). An
 *                         invoice exists only once the warehouse has shipped,
 *                         so this is real revenue, not orders still in approval.
 *   新订单 (orders)     = orders created in the period, drafts excluded.
 *   回款 (collected)    = payment ledger rows in the period, reversals included
 *                         as negatives — cash actually received, net.
 *   应收 (receivable)   = outstanding on live invoices right now. A balance, not
 *                         a flow, so it ignores the selected period.
 *
 * Kept out of the controller so the date arithmetic — the part most likely to
 * be subtly wrong — can be tested against fixed dates.
 */
final class Dashboard
{
    public const PERIODS = ['day', 'week', 'month'];

    /** Overdue bands, in days past due (upper bound inclusive). */
    public const AGING = ['current' => 0, '1_30' => 30, '31_60' => 60, '61_90' => 90, '90' => PHP_INT_MAX];

    /**
     * Resolve the selected period and the window it is compared against.
     *
     * A period still in progress is compared with the same number of elapsed
     * days of the previous one (12 Oct vs 1–12 Sep, not vs all of September),
     * otherwise every month looks like it is collapsing until the last week.
     */
    public static function period(string $p, string $anchor, string $today): array
    {
        $p = in_array($p, self::PERIODS, true) ? $p : 'month';
        $a = self::valid_date($anchor) ? $anchor : $today;
        if ($a > $today) {
            $a = $today;
        }

        [$start, $end] = self::bounds($p, $a);
        [$pStart, $pEnd] = self::bounds($p, self::shift($start, -1));

        $current = $start <= $today && $today <= $end;
        if ($current) {
            $elapsed = self::days_between($start, $today);           // 0-based
            $cmpEnd = min(self::shift($pStart, $elapsed), $pEnd);
        } else {
            $cmpEnd = $pEnd;
        }

        $next = self::shift($end, 1);
        return [
            'p'         => $p,
            'start'     => $start,
            'end'       => $end,
            'to'        => $current ? $today : $end,   // last day with data
            'current'   => $current,
            'cmp_start' => $pStart,
            'cmp_end'   => $cmpEnd,
            'partial'   => $current && $cmpEnd < $pEnd,
            'prev'      => self::shift($start, -1),
            'next'      => $next <= $today ? $next : null,
        ];
    }

    /** Trend columns ending at the selected period: 14 days, 12 weeks or 12 months. */
    public static function buckets(array $period): array
    {
        $count = $period['p'] === 'day' ? 14 : 12;
        $out = [];
        $cursor = $period['start'];
        for ($i = 0; $i < $count; $i++) {
            [$s, $e] = self::bounds($period['p'], $cursor);
            $out[] = ['start' => $s, 'end' => $e, 'sales' => 0.0, 'collected' => 0.0,
                      'current' => $s === $period['start']];
            $cursor = self::shift($s, -1);
        }
        return array_reverse($out);
    }

    /**
     * Everything the view needs.
     *
     * $owner   restricts sales figures to one salesperson (users without the
     *          'performance' permission see only their own); null = company.
     * $fin     include cash figures (collected, receivables, aging).
     * $perf    include the per-salesperson ranking.
     */
    public static function data(PDO $pdo, array $period, string $today, ?string $owner, bool $fin, bool $perf, string $role = ''): array
    {
        $buckets = self::buckets($period);
        $from = min($buckets[0]['start'], $period['cmp_start']);
        $to = max(end($buckets)['end'], $period['end']);

        $invoices = self::invoice_rows($pdo, $from, $to, $owner);
        $orders = self::order_rows($pdo, $from, $to, $owner);
        $sheets = self::sheet_rows($pdo, $from, $to, $owner);
        $payments = $fin ? self::payment_rows($pdo, $from, $to) : [];

        foreach ($buckets as &$b) {
            $b['sales'] = self::sum_in($invoices, $b['start'], $b['end']);
            $b['collected'] = self::sum_in($payments, $b['start'], $b['end']);
        }
        unset($b);
        $buckets = self::trim_leading($buckets, 6);

        $kpi = [
            'sales'         => self::sum_in($invoices, $period['start'], $period['to']),
            'sales_cmp'     => self::sum_in($invoices, $period['cmp_start'], $period['cmp_end']),
            'orders'        => self::count_in($orders, $period['start'], $period['to']),
            'orders_cmp'    => self::count_in($orders, $period['cmp_start'], $period['cmp_end']),
            'sheets'        => self::sum_in($sheets, $period['start'], $period['to']),
            'sheets_cmp'    => self::sum_in($sheets, $period['cmp_start'], $period['cmp_end']),
            'collected'     => self::sum_in($payments, $period['start'], $period['to']),
            'collected_cmp' => self::sum_in($payments, $period['cmp_start'], $period['cmp_end']),
        ];

        $out = [
            'period'  => $period,
            'buckets' => $buckets,
            'kpi'     => $kpi,
            'hot'     => self::hot_products($pdo, $period['start'], $period['to']),
        ];

        if ($fin) {
            $out['receivable'] = self::receivables($pdo, $today);
        } else {
            $out['workload'] = self::workload($pdo, $owner, $role);
        }
        if ($perf) {
            $out['ranking'] = self::ranking($pdo, $period['start'], $period['to'], $today, $fin);
        }
        return $out;
    }

    /**
     * Drop empty columns from the start of the trend — months before anyone
     * used the system read as a broken chart — but keep at least $min so a
     * quiet stretch still shows as a quiet stretch.
     */
    public static function trim_leading(array $buckets, int $min): array
    {
        while (count($buckets) > $min && $buckets[0]['sales'] == 0.0 && $buckets[0]['collected'] == 0.0) {
            array_shift($buckets);
        }
        return $buckets;
    }

    /**
     * Offsite-backup health for the admin banner, or null when all is well.
     * A backup that silently stopped leaving the machine is only discovered
     * the day it is needed, so staleness is surfaced, not just errors.
     *
     * @return array{0:string,1:array}|null [i18n key, sprintf args]
     */
    public static function offsite_health(PDO $pdo, array $config, int $now, int $maxAgeHours = 48): ?array
    {
        if ((string) ($config['offsite']['driver'] ?? '') === '') {
            return ['dash_offsite_none', []];
        }
        $meta = [];
        foreach ($pdo->query("SELECT k, v FROM app_meta WHERE k IN ('offsite_last_ok','offsite_last_error')") as $r) {
            $meta[$r['k']] = (string) $r['v'];
        }
        $okAt = isset($meta['offsite_last_ok']) ? strtotime(substr($meta['offsite_last_ok'], 0, 19)) : false;
        $err = trim($meta['offsite_last_error'] ?? '');
        if ($err !== '') {
            return ['dash_offsite_error', [mb_substr($err, 0, 160)]];
        }
        if ($okAt === false) {
            return ['dash_offsite_never', []];
        }
        $hours = (int) floor(($now - $okAt) / 3600);
        return $hours > $maxAgeHours ? ['dash_offsite_stale', [$hours]] : null;
    }

    /** Signed % change, or null when there is nothing to compare with. */
    public static function delta(float $now, float $before): ?float
    {
        if (abs($before) < 0.005) {
            return null;
        }
        return ($now - $before) / abs($before) * 100;
    }

    /**
     * A round axis maximum (1 / 2 / 2.5 / 5 × 10^n) so gridline labels are clean
     * numbers instead of 437.218.990.
     */
    public static function nice_max(float $v): float
    {
        if ($v <= 0) {
            return 1.0;
        }
        $exp = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($v <= $m * $exp) {
                return $m * $exp;
            }
        }
        return 10 * $exp;
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    private static function invoice_rows(PDO $pdo, string $from, string $to, ?string $owner): array
    {
        $sql = 'SELECT i.invoice_date AS d, i.total AS v
                  FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                 WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ?';
        $args = [$from, $to];
        if ($owner !== null) {
            $sql .= ' AND o.submitter = ?';
            $args[] = $owner;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    private static function order_rows(PDO $pdo, string $from, string $to, ?string $owner): array
    {
        $sql = "SELECT date(created_at) AS d, 1 AS v FROM orders
                 WHERE status <> 'draft' AND date(created_at) BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($owner !== null) {
            $sql .= ' AND submitter = ?';
            $args[] = $owner;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    /** Sheets (张数) per order day, on the same orders order_rows() counts. */
    private static function sheet_rows(PDO $pdo, string $from, string $to, ?string $owner): array
    {
        $sql = "SELECT date(o.created_at) AS d, SUM(oi.qty) AS v FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE o.status <> 'draft' AND date(o.created_at) BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($owner !== null) {
            $sql .= ' AND o.submitter = ?';
            $args[] = $owner;
        }
        $st = $pdo->prepare($sql . ' GROUP BY date(o.created_at)');
        $st->execute($args);
        return $st->fetchAll();
    }

    private static function payment_rows(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare(
            "SELECT COALESCE(NULLIF(pay_date,''), date(created_at)) AS d, amount AS v
               FROM payments WHERE COALESCE(NULLIF(pay_date,''), date(created_at)) BETWEEN ? AND ?"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }

    /** Outstanding balance right now, aged by days past due. */
    private static function receivables(PDO $pdo, string $today): array
    {
        $bands = array_fill_keys(array_keys(self::AGING), 0.0);
        $counts = array_fill_keys(array_keys(self::AGING), 0);
        $byCustomer = [];
        $total = 0.0;

        // Per-row clamp: an invoice overpaid by Rp 1 must not offset real debt.
        $rows = $pdo->query(
            'SELECT customer, due_date, total - amount_paid AS owed FROM invoices
              WHERE voided_at IS NULL AND total - amount_paid > 0.5'
        );
        foreach ($rows as $r) {
            $owed = (float) $r['owed'];
            $late = ($r['due_date'] ?? '') !== '' ? self::days_between((string) $r['due_date'], $today) : 0;
            $band = 'current';
            if ($late > 0) {
                foreach (self::AGING as $key => $upto) {
                    if ($key !== 'current' && $late <= $upto) {
                        $band = $key;
                        break;
                    }
                }
                $c = (string) $r['customer'];
                $byCustomer[$c] = ($byCustomer[$c] ?? 0) + $owed;
            }
            $bands[$band] += $owed;
            $counts[$band]++;
            $total += $owed;
        }
        arsort($byCustomer);

        return [
            'total'         => $total,
            'overdue'       => $total - $bands['current'],
            'overdue_count' => array_sum($counts) - $counts['current'],
            'bands'         => $bands,
            'counts'        => $counts,
            'top_overdue'   => array_slice($byCustomer, 0, 5, true),
        ];
    }

    /**
     * For users without finance access the two cash tiles are replaced by what
     * is waiting on them: orders at their approval stage (supervisor / manager /
     * warehouse), or — for everyone else — their own orders still in approval,
     * plus rejected drafts they need to fix.
     */
    private static function workload(PDO $pdo, ?string $owner, string $role): array
    {
        $scope = $owner !== null ? ' AND submitter = ?' : '';
        $args = $owner !== null ? [$owner] : [];
        $q = function (string $where, array $extra = [], bool $scoped = true) use ($pdo, $scope, $args): int {
            $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ' . $where . ($scoped ? $scope : ''));
            $st->execute(array_merge($extra, $scoped ? $args : []));
            return (int) $st->fetchColumn();
        };

        // Same stage → role map the approval permission uses.
        $myStages = array_values(array_filter(
            ['pending_sup', 'pending_mgr', 'pending_wh'],
            fn($s) => order_action_role($s) === $role
        ));
        $toApprove = $myStages
            ? $q('status IN (' . implode(',', array_fill(0, count($myStages), '?')) . ')', $myStages, false)
            : null;

        return [
            'to_approve' => $toApprove,                  // null = this role approves nothing
            'pending'    => $q("status LIKE 'pending_%'"),
            'rework'     => $q("status = 'draft' AND reject_note IS NOT NULL AND reject_note <> ''"),
        ];
    }

    /** Per salesperson: orders, sheets and invoiced sales in the period, plus cash if allowed. */
    private static function ranking(PDO $pdo, string $from, string $to, string $today, bool $fin): array
    {
        $rows = [];
        $add = function (string $name, string $field, float $v) use (&$rows): void {
            $name = trim($name) !== '' ? trim($name) : '—';
            $rows[$name] ??= ['name' => $name, 'orders' => 0, 'sheets' => 0.0, 'sales' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
            $rows[$name][$field] += $v;
        };

        $st = $pdo->prepare("SELECT submitter, COUNT(*) c FROM orders
                              WHERE status <> 'draft' AND date(created_at) BETWEEN ? AND ? GROUP BY submitter");
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            $add((string) $r['submitter'], 'orders', (float) $r['c']);
        }

        // Sheets (张数) on the same orders the order count covers: every
        // order line is ACP sheets (unit "Unit"), so quantities add up.
        $st = $pdo->prepare("SELECT o.submitter, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
                              WHERE o.status <> 'draft' AND date(o.created_at) BETWEEN ? AND ? GROUP BY o.submitter");
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            $add((string) $r['submitter'], 'sheets', (float) $r['q']);
        }

        $st = $pdo->prepare('SELECT o.submitter, SUM(i.total) s FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                              WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ? GROUP BY o.submitter');
        $st->execute([$from, $to]);
        foreach ($st as $r) {
            $add((string) $r['submitter'], 'sales', (float) $r['s']);
        }

        if ($fin) {
            $st = $pdo->prepare(
                "SELECT o.submitter, SUM(p.amount) s FROM payments p
                   JOIN invoices i ON i.id = p.invoice_id LEFT JOIN orders o ON o.id = i.order_id
                  WHERE COALESCE(NULLIF(p.pay_date,''), date(p.created_at)) BETWEEN ? AND ? GROUP BY o.submitter"
            );
            $st->execute([$from, $to]);
            foreach ($st as $r) {
                $add((string) $r['submitter'], 'collected', (float) $r['s']);
            }
            // Outstanding is a balance — only for people already on the board,
            // so a salesperson with old debt but no activity does not crowd it.
            $st = $pdo->query('SELECT o.submitter, SUM(i.total - i.amount_paid) s FROM invoices i
                                 LEFT JOIN orders o ON o.id = i.order_id
                                WHERE i.voided_at IS NULL AND i.total - i.amount_paid > 0.5 GROUP BY o.submitter');
            foreach ($st as $r) {
                $n = trim((string) $r['submitter']) !== '' ? trim((string) $r['submitter']) : '—';
                if (isset($rows[$n])) {
                    $rows[$n]['outstanding'] += (float) $r['s'];
                }
            }
        }

        $rows = array_values(array_map(function (array $r): array {
            $r['orders'] = (int) $r['orders'];   // accumulated through the float helper
            return $r;
        }, $rows));
        usort($rows, fn($a, $b) => [$b['sales'], $b['orders']] <=> [$a['sales'], $a['orders']]);
        return $rows;
    }

    /** Best sellers in the period. SKU here is a colour code, so group with spec. */
    private static function hot_products(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare(
            "SELECT oi.sku, MAX(oi.color) color, oi.spec, SUM(oi.qty) qty, SUM(oi.qty * oi.price) amount
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.status NOT IN ('draft','rejected') AND date(o.created_at) BETWEEN ? AND ?
           GROUP BY oi.sku, oi.spec ORDER BY qty DESC LIMIT 6"
        );
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }

    // ── Date helpers ─────────────────────────────────────────────────────────

    /** [first, last] day of the period containing $d. Weeks run Monday–Sunday. */
    public static function bounds(string $p, string $d): array
    {
        $ts = strtotime($d . ' 12:00:00');
        if ($p === 'week') {
            $mon = strtotime('monday this week', $ts);
            return [date('Y-m-d', $mon), date('Y-m-d', strtotime('+6 days', $mon))];
        }
        if ($p === 'month') {
            return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
        }
        return [date('Y-m-d', $ts), date('Y-m-d', $ts)];
    }

    private static function shift(string $d, int $days): string
    {
        return date('Y-m-d', strtotime(sprintf('%+d days', $days), strtotime($d . ' 12:00:00')));
    }

    private static function days_between(string $a, string $b): int
    {
        return (int) round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
    }

    private static function valid_date(string $d): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    private static function sum_in(array $rows, string $from, string $to): float
    {
        $s = 0.0;
        foreach ($rows as $r) {
            $d = substr((string) $r['d'], 0, 10);
            if ($d >= $from && $d <= $to) {
                $s += (float) $r['v'];
            }
        }
        return $s;
    }

    private static function count_in(array $rows, string $from, string $to): int
    {
        return (int) self::sum_in($rows, $from, $to);
    }
}
