<?php
declare(strict_types=1);

/**
 * CM-01 重复客户合并 (docs/任务卡-客户合并.md).
 *
 *   groups()   suspected duplicates: same phone, same name once honorifics /
 *              legal forms are dropped, or company names a letter or two apart
 *   plan()     what a merge would move, and whether it is allowed
 *   merge()    one transaction: every reference moves to the kept record, its
 *              blanks are filled, the others stay as rows with merged_into set
 *   by_phone() the one live customer with this phone (new-order de-duplication)
 *
 * Merged rows are never deleted. Each keeps merge_log (JSON: which ids moved
 * from it, per table) so a merge can be reversed by hand if ever needed.
 */
final class CustomerMerge
{
    /** Tables whose customer_id moves to the kept record. */
    public const MOVED = ['orders', 'deals', 'tasks', 'customer_followups', 'visits', 'sales_history', 'customer_name_aliases', 'bank_book', 'customer_payer_aliases'];

    /** Words that say nothing about who the customer is. */
    private const NOISE = ['bpk', 'bapak', 'pak', 'ibu', 'bu', 'mr', 'mrs', 'ms', 'miss', 'toko', 'tk', 'pt', 'cv', 'ud', 'tb', 'koh', 'ci', 'cik', 'mas', 'mbak'];

    /** Filled on the kept record only where it is blank. */
    private const FILL = ['company', 'phone', 'email', 'city', 'note', 'owner', 'last_contact', 'industry', 'industry_source', 'industry_confidence', 'industry_note', 'region', 'delivery_regions'];

    /** Buyer tax data travels as one block: never mix one record's NPWP with another's address. */
    private const TAX = ['tax_npwp', 'tax_nik', 'tax_name', 'tax_address', 'tax_nitku', 'tax_email', 'tax_updated_by', 'tax_updated_at'];

    public const IGNORED_KEY = 'customer_dup_ignored';

    /** Admin and the sales manager (user 2026-10-30). */
    public static function can(): bool
    {
        $auth = $GLOBALS['auth'] ?? null;
        return $auth !== null && ($auth->isAdmin() || ($auth->user()['role'] ?? '') === 'sales_manager');
    }

    /** Last 9 digits of the first number in the field; '' when it is not a phone number. */
    public static function phone_key(?string $raw): string
    {
        $first = preg_split('#[/,;]|\s{2,}#', trim((string) $raw))[0] ?? '';
        $d = preg_replace('/\D+/', '', $first) ?? '';
        return strlen($d) >= 9 ? substr($d, -9) : '';
    }

    public static function name_key(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        $words = array_values(array_filter(explode(' ', $s), fn($w) => $w !== '' && !in_array($w, self::NOISE, true)));
        $k = implode(' ', $words);
        return mb_strlen($k) >= 3 ? $k : '';
    }

    /** Company names that are the same apart from a typo (Aluminium / Alumunium). */
    public static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $len = min(strlen($a), strlen($b));
        if ($len < 6 || strlen($a) > 255 || strlen($b) > 255) {
            return false;
        }
        return levenshtein($a, $b) <= ($len >= 12 ? 2 : 1);
    }

    /** @return list<string> "min-max" id pairs marked 不是同一个 */
    public static function ignored(PDO $pdo): array
    {
        $v = $pdo->query("SELECT v FROM app_meta WHERE k = '" . self::IGNORED_KEY . "'")->fetchColumn();
        return $v ? (array) json_decode((string) $v, true) : [];
    }

    public static function pair(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /** Mark every pair in a group as "not the same customer". */
    public static function ignore(PDO $pdo, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $list = self::ignored($pdo);
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $list[] = self::pair($a, $b);
            }
        }
        $pdo->prepare('INSERT OR REPLACE INTO app_meta (k, v) VALUES (?, ?)')
            ->execute([self::IGNORED_KEY, json_encode(array_values(array_unique($list)))]);
    }

    /**
     * Suspected duplicate groups among live customers. Name / company matches
     * only count when the two do not carry different phone numbers.
     * @return list<array{ids:list<int>,reasons:list<string>,rows:list<array>}>
     */
    public static function groups(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT id, name, company, phone, city, owner, tag, tax_npwp, created_at FROM customers WHERE merged_into IS NULL ORDER BY id')->fetchAll();
        $ignored = array_flip(self::ignored($pdo));
        $parent = [];
        $find = function (int $x) use (&$parent, &$find): int {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $reasons = [];
        $link = function (int $a, int $b, string $why) use (&$parent, $find, $ignored, &$reasons): void {
            if ($a === $b || isset($ignored[self::pair($a, $b)])) {
                return;
            }
            $reasons[self::pair($a, $b)][$why] = true;
            $parent[$find($a)] = $find($b);
        };
        $info = [];
        $byPhone = $byName = $byFirst = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $parent[$id] = $id;
            $info[$id] = ['phone' => self::phone_key($r['phone']), 'name' => self::name_key($r['name']), 'company' => self::name_key($r['company'])];
            if ($info[$id]['phone'] !== '') {
                $byPhone[$info[$id]['phone']][] = $id;
            }
            foreach (array_unique(array_filter([$info[$id]['name'], $info[$id]['company']])) as $k) {
                $byName[$k][] = $id;
            }
            if ($info[$id]['company'] !== '') {
                $byFirst[explode(' ', $info[$id]['company'])[0]][] = $id;
            }
        }
        $phonesClash = fn(int $a, int $b) => $info[$a]['phone'] !== '' && $info[$b]['phone'] !== '' && $info[$a]['phone'] !== $info[$b]['phone'];
        foreach ($byPhone as $ids) {
            foreach (array_slice($ids, 1) as $id) {
                $link($ids[0], $id, 'phone');
            }
        }
        foreach ($byName as $ids) {
            foreach ($ids as $i => $a) {
                foreach (array_slice($ids, $i + 1) as $b) {
                    if (!$phonesClash($a, $b)) {
                        $link($a, $b, 'name');
                    }
                }
            }
        }
        foreach ($byFirst as $ids) {
            foreach ($ids as $i => $a) {
                foreach (array_slice($ids, $i + 1) as $b) {
                    if ($info[$a]['company'] !== $info[$b]['company'] && !$phonesClash($a, $b) && self::similar($info[$a]['company'], $info[$b]['company'])) {
                        $link($a, $b, 'company');
                    }
                }
            }
        }

        $members = [];
        foreach ($parent as $id => $_) {
            $members[$find($id)][] = $id;
        }
        $members = array_values(array_filter($members, fn($m) => count($m) > 1));
        if (!$members) {
            return [];
        }
        $stats = self::stats($pdo, array_merge(...$members));
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($members as $ids) {
            sort($ids);
            $why = [];
            foreach ($ids as $i => $a) {
                foreach (array_slice($ids, $i + 1) as $b) {
                    $why += $reasons[self::pair($a, $b)] ?? [];
                }
            }
            $list = array_map(fn($id) => $byId[$id] + $stats[$id], $ids);
            usort($list, fn($x, $y) => [$y['orders'], $y['sales']] <=> [$x['orders'], $x['sales']]);
            $out[] = ['ids' => $ids, 'reasons' => array_keys($why), 'rows' => $list];
        }
        usort($out, fn($x, $y) => [array_sum(array_column($y['rows'], 'orders')) > 0, count($y['ids'])] <=> [array_sum(array_column($x['rows'], 'orders')) > 0, count($x['ids'])]);
        return $out;
    }

    /** Orders, last order day, invoiced sales (voided excluded) and visits per customer. */
    public static function stats(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = array_fill_keys($ids, ['orders' => 0, 'last_order' => '', 'sales' => 0.0, 'visits' => 0]);
        if (!$ids) {
            return $out;
        }
        $in = implode(',', $ids);
        foreach ($pdo->query("SELECT customer_id, COUNT(*) n, MAX(date(created_at)) d FROM orders WHERE customer_id IN ($in) GROUP BY customer_id") as $r) {
            $out[(int) $r['customer_id']]['orders'] = (int) $r['n'];
            $out[(int) $r['customer_id']]['last_order'] = (string) $r['d'];
        }
        foreach ($pdo->query("SELECT o.customer_id, SUM(i.total) s FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.customer_id IN ($in) AND i.voided_at IS NULL GROUP BY o.customer_id") as $r) {
            $out[(int) $r['customer_id']]['sales'] = (float) $r['s'];
        }
        foreach ($pdo->query("SELECT customer_id, COUNT(*) n FROM visits WHERE customer_id IN ($in) GROUP BY customer_id") as $r) {
            $out[(int) $r['customer_id']]['visits'] = (int) $r['n'];
        }
        return $out;
    }

    private static function npwp(?string $v): string
    {
        return preg_replace('/\D+/', '', (string) $v) ?? '';
    }

    /**
     * What merging $from into $keep would do.
     * @return array{ok:bool,error:string,keep:?array,from:list<array>,counts:array<string,int>,owners:list<string>}
     */
    public static function plan(PDO $pdo, int $keep, array $from): array
    {
        $from = array_values(array_unique(array_filter(array_map('intval', $from), fn($i) => $i > 0 && $i !== $keep)));
        $res = ['ok' => false, 'error' => '', 'keep' => null, 'from' => [], 'counts' => [], 'owners' => []];
        if (!$from) {
            $res['error'] = 'cm_err_pick';
            return $res;
        }
        $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        foreach ([$keep, ...$from] as $id) {
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || $row['merged_into'] !== null) {
                $res['error'] = 'cm_err_gone';
                return $res;
            }
            if ($id === $keep) {
                $res['keep'] = $row;
            } else {
                $res['from'][] = $row;
            }
        }
        $all = [$res['keep'], ...$res['from']];
        $npwps = array_unique(array_filter(array_map(fn($r) => self::npwp($r['tax_npwp']), $all)));
        if (count($npwps) > 1) {
            $res['error'] = 'cm_err_npwp';
            return $res;
        }
        $owners = array_values(array_unique(array_filter(array_map(fn($r) => trim((string) $r['owner']), $all))));
        $res['owners'] = count(array_unique(array_map('mb_strtolower', $owners))) > 1 ? $owners : [];
        $in = implode(',', $from);
        foreach (self::MOVED as $t) {
            $res['counts'][$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t} WHERE customer_id IN ($in)")->fetchColumn();
        }
        $res['ok'] = true;
        return $res;
    }

    /**
     * Merge $from into $keep. Returns the plan with ok=false (nothing changed)
     * when the merge is not allowed.
     */
    public static function merge(PDO $pdo, int $keep, array $from, string $by): array
    {
        $plan = self::plan($pdo, $keep, $from);
        if (!$plan['ok']) {
            return $plan;
        }
        $k = $plan['keep'];
        $now = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            foreach ($plan['from'] as $f) {
                $fid = (int) $f['id'];
                $log = ['into' => $keep, 'at' => $now, 'by' => $by];
                foreach (self::MOVED as $t) {
                    $ids = array_map('intval', $pdo->query("SELECT id FROM {$t} WHERE customer_id = {$fid}")->fetchAll(PDO::FETCH_COLUMN));
                    if (!$ids) {
                        continue;
                    }
                    $log[$t] = $ids;
                    if ($t === 'customer_payer_aliases') {
                        // UNIQUE(payer_key, customer_id): a payer name the kept record already has is the same alias twice.
                        $pdo->exec("UPDATE OR IGNORE customer_payer_aliases SET customer_id = {$keep} WHERE customer_id = {$fid}");
                        $dup = $pdo->query("SELECT id, payer_name FROM customer_payer_aliases WHERE customer_id = {$fid}")->fetchAll();
                        if ($dup) {
                            $log['customer_payer_aliases_same'] = $dup;
                            $pdo->exec("DELETE FROM customer_payer_aliases WHERE customer_id = {$fid}");
                        }
                        continue;
                    }
                    $pdo->exec("UPDATE {$t} SET customer_id = {$keep} WHERE customer_id = {$fid}");
                }
                Insights::drop_merged($pdo, $fid);

                $set = [];
                foreach (self::FILL as $col) {
                    if (self::blank($k[$col] ?? null) && !self::blank($f[$col] ?? null)) {
                        $set[$col] = $k[$col] = $f[$col];
                    }
                }
                if ((float) ($k['value'] ?? 0) <= 0 && (float) ($f['value'] ?? 0) > 0) {
                    $set['value'] = $k['value'] = $f['value'];
                }
                if (self::npwp($k['tax_npwp']) === '' && self::blank($k['tax_nik']) && (self::npwp($f['tax_npwp']) !== '' || !self::blank($f['tax_nik']))) {
                    foreach (self::TAX as $col) {
                        $set[$col] = $k[$col] = $f[$col];
                    }
                }
                if (($f['tag'] ?? '') === '成交' && ($k['tag'] ?? '') !== '成交') {
                    $set['tag'] = $k['tag'] = '成交';
                }
                if (!self::blank($f['last_contact'] ?? null) && (string) $f['last_contact'] > (string) ($k['last_contact'] ?? '')) {
                    $set['last_contact'] = $k['last_contact'] = $f['last_contact'];
                }
                if ($set) {
                    $log['filled'] = array_keys($set);
                    $pdo->prepare('UPDATE customers SET ' . implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($set))) . ' WHERE id = ?')
                        ->execute([...array_values($set), $keep]);
                }
                // Earlier merges into this record now point at the kept one.
                $pdo->exec("UPDATE customers SET merged_into = {$keep} WHERE merged_into = {$fid}");
                $pdo->prepare('UPDATE customers SET merged_into = ?, merged_at = ?, merged_by = ?, merge_log = ? WHERE id = ?')
                    ->execute([$keep, $now, $by, json_encode($log, JSON_UNESCAPED_UNICODE), $fid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $names = implode(', ', array_map(fn($f) => '#' . $f['id'] . ' ' . $f['name'], $plan['from']));
        $moved = implode(', ', array_map(fn($t, $n) => "{$t} {$n}", array_keys(array_filter($plan['counts'])), array_filter($plan['counts'])));
        audit($pdo, 'customers', 'merge', 'customer', $keep, (string) $k['name'],
            sprintf('合并客户: %s → #%d %s; 转移: %s; 明细见被并记录的 merge_log', $names, $keep, (string) $k['name'], $moved ?: '无'));
        return $plan;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }

    /** The one live customer with this phone; null when none or more than one. */
    public static function by_phone(PDO $pdo, string $phone): ?int
    {
        $key = self::phone_key($phone);
        if ($key === '') {
            return null;
        }
        $hits = [];
        foreach ($pdo->query("SELECT id, phone FROM customers WHERE merged_into IS NULL AND TRIM(COALESCE(phone, '')) <> ''") as $r) {
            if (self::phone_key($r['phone']) === $key) {
                $hits[] = (int) $r['id'];
            }
        }
        return count($hits) === 1 ? $hits[0] : null;
    }
}
