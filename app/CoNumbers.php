<?php
declare(strict_types=1);

/**
 * CO numbers (Confirmation Order, NNNN/AMI-CO/MM/YY).
 *
 * Until October 2026 sales got their CO number from a Google Form ("Request
 * CO Number"); the sheet behind it was the register. The platform, live since
 * 2026-08-04, numbered its own orders from 0001 again, so two sequences ran
 * side by side and the same number means different customers in each.
 *
 * This class makes the platform the single issuer:
 *   - the form's register is imported into `co_requests` (who asked for which
 *     number, when, for whom), so the old sheet can be retired;
 *   - the next order number continues after the highest number anywhere,
 *     orders or register, so the two sequences can never collide again;
 *   - a number already requested through the form can be taken when the
 *     order is created in the platform (order_no = that CO number), and old
 *     platform orders can be linked to the form number the customer knows
 *     (orders.co_no) without renaming the order — DO and invoice already
 *     carry its order_no.
 */
final class CoNumbers
{
    public const SOURCE = 'google_form';

    /** Register columns of the form export, by position (the sheet has no reliable headers). */
    private const COL = ['seq' => 0, 'month' => 4, 'year' => 6, 'ts' => 7, 'sales' => 8, 'company' => 9, 'client' => 10, 'phone' => 11];

    public static function ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS co_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            seq INTEGER NOT NULL UNIQUE, co_no TEXT NOT NULL UNIQUE,
            issued_at TEXT NOT NULL, sales_name TEXT, company TEXT, client_name TEXT, phone TEXT,
            order_id INTEGER, linked_by TEXT, linked_at TEXT, link_note TEXT,
            source TEXT NOT NULL DEFAULT 'google_form', batch TEXT,
            imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_co_requests_order ON co_requests(order_id)');
        $ocols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
        if (!in_array('co_no', $ocols, true)) {
            $pdo->exec('ALTER TABLE orders ADD COLUMN co_no TEXT');
        }
    }

    public static function format(int $seq, string $mm, string $yy): string
    {
        return sprintf('%04d/AMI-CO/%s/%s', $seq, $mm, $yy);
    }

    /** Sequence part of a CO / order number, or null. */
    public static function seq_of(?string $no): ?int
    {
        return $no !== null && preg_match('/^(\d+)\//', $no, $m) ? (int) $m[1] : null;
    }

    /** Highest sequence anywhere: platform orders and the imported register. */
    public static function max_seq(PDO $pdo): int
    {
        $max = 0;
        foreach ($pdo->query('SELECT order_no FROM orders') as $r) {
            $max = max($max, self::seq_of((string) $r['order_no']) ?? 0);
        }
        if (self::has_table($pdo)) {
            $max = max($max, (int) $pdo->query('SELECT COALESCE(MAX(seq),0) FROM co_requests')->fetchColumn());
        }
        return $max;
    }

    private static function has_table(PDO $pdo): bool
    {
        return (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='co_requests'")->fetchColumn();
    }

    // ── Register import ──────────────────────────────────────────────────────

    /**
     * Parse the "Request CO Number (Responses)" export. Each row: seq, '/',
     * 'AMI-CO', '/', MM, '/', YY, timestamp, sales, company, client, phone.
     * Rows without a timestamp are the sheet's pre-filled blanks.
     */
    public static function parse(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $r) {
            if ($i === 0 && !is_numeric($r[self::COL['seq']] ?? null)) {
                continue;   // header
            }
            $seq = (int) ($r[self::COL['seq']] ?? 0);
            $ts = $r[self::COL['ts']] ?? null;
            if ($seq <= 0 || $ts === null || $ts === '') {
                continue;
            }
            $issued = self::to_datetime($ts);
            if ($issued === null) {
                continue;
            }
            $mm = str_pad((string) (int) ($r[self::COL['month']] ?? substr($issued, 5, 2)), 2, '0', STR_PAD_LEFT);
            $yy = (string) ($r[self::COL['year']] ?? substr($issued, 2, 2));
            $yy = str_pad(substr(trim($yy), -2), 2, '0', STR_PAD_LEFT);
            $s = fn($k): string => trim(preg_replace('/\s+/u', ' ', (string) ($r[self::COL[$k]] ?? '')) ?? '');
            $out[] = [
                'seq' => $seq, 'co_no' => self::format($seq, $mm, $yy), 'issued_at' => $issued,
                'sales_name' => $s('sales'), 'company' => $s('company'), 'client_name' => $s('client'), 'phone' => $s('phone'),
            ];
        }
        return $out;
    }

    /** Excel serial or text → 'Y-m-d H:i:s'. */
    public static function to_datetime($v): ?string
    {
        if (is_float($v) || is_int($v)) {
            $secs = (int) round(((float) $v - 25569) * 86400);
            return $secs > 0 ? gmdate('Y-m-d H:i:s', $secs) : null;
        }
        $t = strtotime(trim((string) $v));
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /**
     * Insert register rows not yet present (by seq). Nothing is updated, so a
     * re-import of a newer export only adds the numbers issued since.
     *
     * @return array{added:int, existing:int, max_seq:int}
     */
    public static function import(PDO $pdo, array $rows, string $batch, bool $apply): array
    {
        $have = array_fill_keys(array_map('intval', $pdo->query('SELECT seq FROM co_requests')->fetchAll(PDO::FETCH_COLUMN)), true);
        $new = array_values(array_filter($rows, fn($r) => !isset($have[$r['seq']])));
        $rep = ['added' => count($new), 'existing' => count($rows) - count($new), 'max_seq' => 0];
        foreach ($rows as $r) {
            $rep['max_seq'] = max($rep['max_seq'], $r['seq']);
        }
        if (!$apply || $new === []) {
            return $rep;
        }
        $ins = $pdo->prepare('INSERT INTO co_requests (seq, co_no, issued_at, sales_name, company, client_name, phone, source, batch) VALUES (?,?,?,?,?,?,?,?,?)');
        $pdo->beginTransaction();
        try {
            foreach ($new as $r) {
                $ins->execute([$r['seq'], $r['co_no'], $r['issued_at'], $r['sales_name'] ?: null, $r['company'] ?: null, $r['client_name'] ?: null, $r['phone'] ?: null, self::SOURCE, $batch]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $rep;
    }

    // ── Taking / linking numbers ─────────────────────────────────────────────

    /** Register entries not yet tied to an order, newest first; $days limits how far back. */
    public static function available(PDO $pdo, int $days = 60, ?string $keep = null): array
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $st = $pdo->prepare('SELECT * FROM co_requests WHERE (order_id IS NULL AND issued_at >= ?) OR co_no = ? ORDER BY seq DESC');
        $st->execute([$since, (string) $keep]);
        return $st->fetchAll();
    }

    /**
     * Hand out the next number before the order exists — what the Google Form
     * did. The entry sits in the register until an order takes it (order form
     * dropdown / link box), and next_order_no() skips past it meanwhile.
     */
    public static function reserve(PDO $pdo, string $sales, string $company, string $client, string $phone, string $by): array
    {
        $seq = self::max_seq($pdo) + 1;
        $coNo = self::format($seq, date('m'), date('y'));
        $pdo->prepare("INSERT INTO co_requests (seq, co_no, issued_at, sales_name, company, client_name, phone, source, batch, link_note)
                        VALUES (?, ?, datetime('now','localtime'), ?, ?, ?, ?, 'platform', NULL, ?)")
            ->execute([$seq, $coNo, $sales !== '' ? $sales : null, $company !== '' ? $company : null, $client !== '' ? $client : null, $phone !== '' ? $phone : null, 'reserved by ' . $by]);
        return ['seq' => $seq, 'co_no' => $coNo];
    }

    public static function find(PDO $pdo, string $coNo): ?array
    {
        $st = $pdo->prepare('SELECT * FROM co_requests WHERE co_no = ?');
        $st->execute([$coNo]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * Tie a register entry to an order. With $rename the order takes the CO
     * number as its order_no (only for orders that have no paperwork yet —
     * the caller checks); otherwise just orders.co_no is set.
     */
    public static function link(PDO $pdo, int $orderId, string $coNo, string $by, bool $rename, string $note = ''): void
    {
        $req = self::find($pdo, $coNo);
        if ($req === null) {
            throw new RuntimeException('co_not_found');
        }
        if ($req['order_id'] !== null && (int) $req['order_id'] !== $orderId) {
            throw new RuntimeException('co_taken');
        }
        if ($rename) {
            $st = $pdo->prepare('SELECT id FROM orders WHERE order_no = ? AND id <> ?');
            $st->execute([$coNo, $orderId]);
            if ($st->fetchColumn()) {
                throw new RuntimeException('co_taken');
            }
        }
        // Release whatever this order was linked to before.
        $pdo->prepare('UPDATE co_requests SET order_id = NULL, linked_by = NULL, linked_at = NULL, link_note = NULL WHERE order_id = ? AND co_no <> ?')->execute([$orderId, $coNo]);
        $pdo->prepare("UPDATE co_requests SET order_id = ?, linked_by = ?, linked_at = datetime('now','localtime'), link_note = ? WHERE co_no = ?")
            ->execute([$orderId, $by, $note !== '' ? $note : null, $coNo]);
        if ($rename) {
            $pdo->prepare('UPDATE orders SET order_no = ?, co_no = ? WHERE id = ?')->execute([$coNo, $coNo, $orderId]);
        } else {
            $pdo->prepare('UPDATE orders SET co_no = ? WHERE id = ?')->execute([$coNo, $orderId]);
        }
    }

    public static function unlink(PDO $pdo, int $orderId): void
    {
        $pdo->prepare('UPDATE co_requests SET order_id = NULL, linked_by = NULL, linked_at = NULL, link_note = NULL WHERE order_id = ?')->execute([$orderId]);
        $pdo->prepare('UPDATE orders SET co_no = NULL WHERE id = ?')->execute([$orderId]);
    }

    // ── Matching old platform orders to the register ─────────────────────────

    /** Digits only, last 9 — Indonesian numbers typed with 0/62/+62 and spaces still compare equal. */
    public static function phone_key(?string $p): string
    {
        $d = preg_replace('/\D+/', '', (string) $p) ?? '';
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    private static function name_key(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/\b(pt|cv|tk|toko|ud|bpk|bapak|ibu|bu|pak|mr|mrs)\b\.?/u', ' ', $s) ?? $s;
        $s = str_replace(['alumunium', 'aluminum'], 'aluminium', $s);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;
    }

    /** Who a register entry is for: phone when given, else the company / client name. */
    private static function ident(array $req): string
    {
        $p = self::phone_key($req['phone'] ?? null);
        if ($p !== '') {
            return 'p' . $p;
        }
        $n = self::name_key($req['company'] ?? null) ?: self::name_key($req['client_name'] ?? null);
        return $n !== '' ? 'n' . $n : 'r' . (int) ($req['id'] ?? 0);
    }

    private static function sales_key(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/^(mr|mrs|pak|bu)\.?\s+/u', '', $s) ?? $s;
        return preg_replace('/[^\p{L}\p{N}]+/u', '', explode(' ', $s)[0] ?? '') ?? '';
    }

    /**
     * Propose a register entry for every platform order that has no co_no yet.
     * A match needs the same customer (phone, or company / client name) and a
     * request date near the order date; sales name only breaks ties. Each
     * register entry is used at most once (best order wins).
     *
     * @param array $aliases platform user name (lower) → form sales name (lower), e.g. erfandi → erpandi
     * @return array{matched: array, ambiguous: array, unmatched: array}
     */
    public static function propose(PDO $pdo, string $fromDate, array $aliases = [], int $before = 10, int $after = 3): array
    {
        $orders = $pdo->prepare("SELECT id, order_no, customer_name, company, phone, submitter, date(created_at) d FROM orders
                                  WHERE COALESCE(co_no,'') = '' AND date(created_at) >= ? ORDER BY id");
        $orders->execute([$fromDate]);
        $reqs = $pdo->query('SELECT * FROM co_requests WHERE order_id IS NULL ORDER BY seq')->fetchAll();

        $scored = [];   // [order id][] = [score, req]
        foreach ($orders as $o) {
            $oPhone = self::phone_key($o['phone']);
            $oNames = array_filter([self::name_key($o['company']), self::name_key($o['customer_name'])]);
            $oSales = self::sales_key($o['submitter']);
            $oSales = $aliases[$oSales] ?? $oSales;
            $lo = date('Y-m-d', strtotime($o['d'] . " -{$before} days"));
            $hi = date('Y-m-d', strtotime($o['d'] . " +{$after} days"));
            foreach ($reqs as $r) {
                $day = substr((string) $r['issued_at'], 0, 10);
                if ($day < $lo || $day > $hi) {
                    continue;
                }
                $score = 0;
                $rPhone = self::phone_key($r['phone']);
                if ($rPhone !== '' && $rPhone === $oPhone) {
                    $score += 5;
                }
                $rNames = array_filter([self::name_key($r['company']), self::name_key($r['client_name'])]);
                foreach ($oNames as $a) {
                    foreach ($rNames as $b) {
                        if ($a === $b) {
                            $score = max($score, $score + 4);
                        } elseif (strlen($a) >= 5 && strlen($b) >= 5 && (str_contains($a, $b) || str_contains($b, $a))) {
                            $score += 3;
                        } elseif (strlen($a) >= 6 && strlen($b) >= 6 && levenshtein(substr($a, 0, 40), substr($b, 0, 40)) <= 2) {
                            $score += 3;
                        }
                    }
                }
                if ($score === 0) {
                    continue;   // customer must agree; date and sales alone prove nothing
                }
                if ($oSales !== '' && self::sales_key($r['sales_name']) === $oSales) {
                    $score += 1;
                }
                // Closer in time is better (the number is usually requested the same day or a few days before).
                $gap = abs((int) round((strtotime($day) - strtotime($o['d'])) / 86400));
                $score += max(0, 2 - $gap * 0.25);
                $scored[(int) $o['id']][] = ['score' => $score, 'req' => $r, 'order' => $o];
            }
            $scored[(int) $o['id']] ??= [];
            if ($scored[(int) $o['id']] === []) {
                $scored[(int) $o['id']][] = ['score' => 0, 'req' => null, 'order' => $o];
            }
        }

        // Greedy: strongest pairs first, each register entry and each order once.
        $pairs = [];
        foreach ($scored as $oid => $cands) {
            foreach ($cands as $c) {
                if ($c['req'] !== null) {
                    $pairs[] = $c;
                }
            }
        }
        usort($pairs, fn($a, $b) => $b['score'] <=> $a['score']);
        $usedReq = [];
        $usedOrder = [];
        $matched = [];
        foreach ($pairs as $p) {
            $oid = (int) $p['order']['id'];
            $rid = (int) $p['req']['id'];
            if (isset($usedReq[$rid]) || isset($usedOrder[$oid])) {
                continue;
            }
            // Ambiguous when the runner-up for this order is nearly as good AND is
            // a different customer. A regular customer asks for a number per order,
            // so several near-identical requests in the window are expected; they
            // are paired off in date order and the pick is not in doubt.
            $ident = self::ident($p['req']);
            $others = array_filter($scored[$oid], fn($c) => $c['req'] !== null && (int) $c['req']['id'] !== $rid
                && !isset($usedReq[(int) $c['req']['id']]) && self::ident($c['req']) !== $ident);
            $second = $others ? max(array_map(fn($c) => $c['score'], $others)) : 0;
            $sure = $p['score'] >= 4 && $p['score'] - $second >= 1.5;
            $entry = ['order' => $p['order'], 'req' => $p['req'], 'score' => round($p['score'], 2), 'second' => round((float) $second, 2)];
            if ($sure) {
                $matched[$oid] = $entry;
                $usedReq[$rid] = true;
            } else {
                $matched[$oid] = $entry + ['ambiguous' => true];
            }
            $usedOrder[$oid] = true;
        }
        $out = ['matched' => [], 'ambiguous' => [], 'unmatched' => []];
        foreach ($scored as $oid => $cands) {
            if (isset($matched[$oid])) {
                $out[isset($matched[$oid]['ambiguous']) ? 'ambiguous' : 'matched'][] = $matched[$oid];
            } else {
                $out['unmatched'][] = ['order' => $cands[0]['order']];
            }
        }
        return $out;
    }
}
