<?php
declare(strict_types=1);

/**
 * Customer-data hygiene: salesperson-name variants, phone numbers, owner
 * reassignment. Used by tools/clean_customer_data.php (one-off, automatic
 * part) and by the admin page customers.cleanup (the parts a human decides).
 *
 * Salesperson names are plain text in customers.owner and orders.submitter,
 * and sees_only_own() compares them to the user's account name — so "fera"
 * vs "Fera", or "erpandi" vs account "erfandi", hide records from the very
 * person they belong to.
 */
final class CustomerData
{
    /**
     * Normalise a phone field.
     * @return array{0:string,1:string} [phone to keep ('' if none), text to move to the note ('' if none)]
     */
    public static function normalize_phone(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['', ''];
        }
        // Several numbers in one field: keep the first, move the rest.
        $parts = preg_split('#\s*(?:/|,|;|&|\s+dan\s+|\s+atau\s+)\s*#iu', $raw) ?: [$raw];
        $main = trim((string) array_shift($parts));
        $rest = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));

        if (preg_match('/\p{L}/u', $main)) {      // "wechat", "Bekasi Jawa Barat"
            return ['', $raw];
        }
        $digits = preg_replace('/\D+/', '', $main) ?? '';
        if (str_starts_with($digits, '62')) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '8') && strlen($digits) >= 9 && strlen($digits) <= 12) {
            $digits = '0' . $digits;
        }
        if (!preg_match('/^0\d{8,12}$/', $digits)) {
            return ['', $raw];
        }
        return [$digits, $rest ? implode(' / ', $rest) : ''];
    }

    /** Append a line to a note, once. */
    public static function append_note(?string $note, string $line): string
    {
        $note = trim((string) $note);
        if ($line === '' || str_contains($note, $line)) {
            return $note;
        }
        return $note === '' ? $line : $note . "\n" . $line;
    }

    /**
     * Every salesperson-name spelling in use, with how often.
     * @return array<string,int> raw value => count (customers + orders)
     */
    public static function name_variants(PDO $pdo): array
    {
        $out = [];
        foreach (['SELECT owner n, COUNT(*) c FROM customers GROUP BY owner', 'SELECT submitter n, COUNT(*) c FROM orders GROUP BY submitter'] as $sql) {
            foreach ($pdo->query($sql) as $r) {
                $n = (string) $r['n'];
                if (trim($n) !== '') {
                    $out[$n] = ($out[$n] ?? 0) + (int) $r['c'];
                }
            }
        }
        return $out;
    }

    /**
     * Spellings that differ only by case or surrounding spaces → one canonical
     * form: the user account's exact name if one matches, else the most used.
     * @param array<string,int> $variants
     * @param string[] $accounts user names
     * @return array<string,string> raw => canonical, only for raws that change
     */
    public static function case_merges(array $variants, array $accounts): array
    {
        $acct = [];
        foreach ($accounts as $a) {
            $acct[mb_strtolower(trim($a))] = trim($a);
        }
        $groups = [];
        foreach ($variants as $raw => $n) {
            $groups[mb_strtolower(trim((string) $raw))][(string) $raw] = $n;
        }
        $map = [];
        foreach ($groups as $key => $forms) {
            if (isset($acct[$key])) {
                $canon = $acct[$key];
            } else {
                arsort($forms);
                $canon = trim((string) array_key_first($forms));
            }
            foreach (array_keys($forms) as $raw) {
                if ($raw !== $canon) {
                    $map[$raw] = $canon;
                }
            }
        }
        return $map;
    }

    /**
     * Different names that may be the same person (erpandi / erfandi): one
     * edit apart (two for names of 7+ letters), or equal once a title is
     * dropped ("Mr Fang" / "Fang"). Never merged automatically.
     *
     * @param string[] $names canonical names
     * @param string[] $ignored "a|b" pairs (lowercase, sorted) an admin dismissed
     * @return array<int,array{0:string,1:string}>
     */
    public static function similar_pairs(array $names, array $ignored = []): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($n) => $n !== '')));
        $ign = array_flip($ignored);
        $out = [];
        $strip = fn(string $s): string => trim(preg_replace('/^(mr|mrs|ms|bpk|bapak|ibu|pak|bu)\.?\s+/i', '', mb_strtolower($s)) ?? '');
        for ($i = 0; $i < count($names); $i++) {
            for ($j = $i + 1; $j < count($names); $j++) {
                $a = $strip($names[$i]);
                $b = $strip($names[$j]);
                if ($a === '' || $b === '') {
                    continue;
                }
                // Short names differ by little by nature (rian / ridwan), so
                // they must be one edit apart; long ones may be two.
                $d = levenshtein($a, $b);
                $short = min(strlen($a), strlen($b));
                $close = $a === $b || ($short >= 4 && $d <= 1) || ($short >= 7 && $d <= 2);
                if (!$close || isset($ign[self::pair_key($names[$i], $names[$j])])) {
                    continue;
                }
                $out[] = [$names[$i], $names[$j]];
            }
        }
        return $out;
    }

    public static function pair_key(string $a, string $b): string
    {
        $p = [mb_strtolower(trim($a)), mb_strtolower(trim($b))];
        sort($p);
        return implode('|', $p);
    }

    /**
     * Rename a salesperson everywhere they are referenced by name.
     * Matches case- and space-insensitively.
     * @return array{customers:int,orders:int}
     */
    public static function rename_salesperson(PDO $pdo, string $from, string $to): array
    {
        $key = mb_strtolower(trim($from));
        $c = $pdo->prepare('UPDATE customers SET owner = ? WHERE LOWER(TRIM(owner)) = ? AND owner IS NOT ?');
        $c->execute([$to, $key, $to]);
        $o = $pdo->prepare('UPDATE orders SET submitter = ? WHERE LOWER(TRIM(submitter)) = ? AND submitter IS NOT ?');
        $o->execute([$to, $key, $to]);
        return ['customers' => $c->rowCount(), 'orders' => $o->rowCount()];
    }

    /**
     * Who most likely owns a customer: the salesperson on most of its orders
     * (ties → the most recent order's).
     * @return array<int,string> customer id => name
     */
    public static function suggested_owners(PDO $pdo): array
    {
        $out = [];
        $seen = [];
        $rows = $pdo->query("SELECT customer_id, TRIM(submitter) s FROM orders WHERE customer_id IS NOT NULL AND TRIM(COALESCE(submitter, '')) <> '' ORDER BY customer_id, id DESC");
        foreach ($rows as $r) {
            $seen[(int) $r['customer_id']][] = (string) $r['s'];
        }
        foreach ($seen as $cid => $list) {
            $counts = array_count_values($list);
            $best = null;
            foreach ($list as $s) {
                if ($best === null || $counts[$s] > $counts[$best]) {
                    $best = $s;
                }
            }
            $out[$cid] = (string) $best;
        }
        return $out;
    }
}
