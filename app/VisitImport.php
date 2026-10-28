<?php
declare(strict_types=1);

/**
 * HR-04 v3: visits from the WhatsApp group export (docs/任务卡-拜访签到.md §一–二).
 * Sales keep their habit (photo + free text); the sales assistant uploads the
 * day's export; this class turns it into visits. Rules first, AI as the
 * fallback, AI only suggests:
 *
 *   import()   export → visits (dedup on sender + time + caption; a with-media
 *              import only adds photos to existing rows), senders → employees
 *   process()  per visit: free text → visit_parse (store, type, PIC, brands,
 *              outcome guess); store name → customer / prospect (rules; several
 *              or weak candidates → visit_match suggestion, supervisor confirms;
 *              none → a new store in the prospect pool); watermark photo →
 *              visit_photo_read (time, address, is it a storefront) → geocode,
 *              distance, 补发 when the photo is over 2 h older than the message
 *   confirm()  supervisor's one click; writes customer_name_aliases so the
 *              same name matches by itself next time
 * With AI off (no key) everything but the three AI steps still runs; matching
 * and watermarks are left to the supervisor.
 */
final class VisitImport
{
    /** Group short words and Excel words → stored outcome. */
    public const OUTCOME_WORDS = [
        'minat' => 'berminat', 'berminat' => 'berminat', 'penawaran' => 'penawaran', 'sudah penawaran' => 'penawaran', 'order' => 'order',
        'tidak' => 'tidak_butuh', 'tidak butuh' => 'tidak_butuh', 'tutup' => 'tidak_terhubung', 'tidak terhubung' => 'tidak_terhubung',
    ];
    private const LEGAL_WORDS = ['pt', 'cv', 'ud', 'tb', 'toko', 'tbk', 'the'];

    public static function cfg(array $config): array
    {
        return ((array) ($config['visits'] ?? [])) + [
            'competitor_brands' => ['Seven', 'Marks', 'Tajima', 'Alcopan', 'Toin'], 'own_brands' => ['Alusign', 'Alusignpanel', 'Alupanel'],
            'store_types' => ['Toko', 'Workshop', 'Advertising', 'Distributor', 'Aplikator', 'Kontraktor', 'Interior'],
            'template_patterns' => [], 'match_auto' => 0.95, 'match_min' => 0.60, 'late_hours' => 2, 'ai_per_run' => 15, 'ignored_tags' => [],
        ];
    }

    /** Config plus the brands / non-brand tags supervisors confirmed (visit_tags). */
    public static function cfg_db(PDO $pdo, array $config): array
    {
        $cfg = self::cfg($config);
        foreach ($pdo->query('SELECT label, kind FROM visit_tags') as $t) {
            $cfg[$t['kind'] === 'brand' ? 'competitor_brands' : 'ignored_tags'][] = (string) $t['label'];
        }
        return $cfg;
    }

    /** Unknown #tags waiting for the supervisor (maybe new brands), most used first. @return array<string,int> */
    public static function unknown_tags(PDO $pdo): array
    {
        $known = array_map('mb_strtolower', $pdo->query('SELECT label FROM visit_tags')->fetchAll(PDO::FETCH_COLUMN));
        $n = [];
        foreach ($pdo->query("SELECT unknown_tags FROM visits WHERE unknown_tags IS NOT NULL AND unknown_tags <> '[]'")->fetchAll(PDO::FETCH_COLUMN) as $j) {
            foreach ((array) json_decode((string) $j, true) as $t) {
                if (!in_array(mb_strtolower((string) $t), $known, true)) {
                    $n[(string) $t] = ($n[(string) $t] ?? 0) + 1;
                }
            }
        }
        arsort($n);
        return $n;
    }

    /** Supervisor: an unknown tag is a brand (joins the brand list, fills visits that named no brand) or not. */
    public static function decide_tag(PDO $pdo, string $tag, string $kind, string $by): int
    {
        $tag = trim(ltrim($tag, '#'));
        if ($tag === '' || !in_array($kind, ['brand', 'ignore'], true)) {
            return 0;
        }
        $label = $kind === 'brand' ? mb_convert_case($tag, MB_CASE_TITLE) : $tag;
        $pdo->prepare('INSERT OR REPLACE INTO visit_tags (tag_key, label, kind, created_by) VALUES (?,?,?,?)')->execute([mb_strtolower($tag), $label, $kind, $by]);
        $n = 0;
        if ($kind === 'brand') {
            $upd = $pdo->prepare('UPDATE visits SET competitor_brand = ?, current_supplier = COALESCE(current_supplier, ?) WHERE id = ? AND competitor_brand IS NULL');
            foreach ($pdo->query("SELECT id, unknown_tags FROM visits WHERE unknown_tags LIKE " . $pdo->quote('%' . $tag . '%'))->fetchAll() as $v) {
                if (in_array(mb_strtolower($tag), array_map('mb_strtolower', (array) json_decode((string) $v['unknown_tags'], true)), true)) {
                    $upd->execute([$label, $label, $v['id']]);
                    $n += $upd->rowCount();
                }
            }
        }
        audit($pdo, 'visits', 'update', 'visit_tags', null, '#' . $tag, "visit_tags: #{$tag} → {$kind}; {$n} visits");
        return $n;
    }

    public static function photo_dir(string $ym): string
    {
        return (Visits::$photoRoot ?? dirname(__DIR__) . '/data/visits') . '/' . $ym;
    }

    // ───────────────────────────── reading a report ─────────────────────────────

    /** Words a tag may carry besides brands: #Lama / #Baru and the result words. */
    private const STATUS_TAGS = ['lama' => 'lama', 'baru' => 'baru'];

    /**
     * Trailing tags (规则-销售日报 v2): #Lama/#Baru, #Minat/#Penawaran/#Order/#Tidak/#Tutup,
     * #Brand. Case-insensitive; a last line without # counts only when every
     * word on it is a known tag word. Unknown #words are returned for the
     * supervisor (maybe a new brand).
     * @return array{body:string,status:?string,outcome:?string,brands:list<string>,unknown:list<string>,any:bool}
     */
    public static function tags(string $caption, array $cfg): array
    {
        $brandOf = [];
        foreach ((array) $cfg['competitor_brands'] as $b) {
            $brandOf[mb_strtolower((string) $b)] = (string) $b;
        }
        $ignored = array_map('mb_strtolower', (array) ($cfg['ignored_tags'] ?? []));
        $own = array_map('mb_strtolower', (array) $cfg['own_brands']);
        // A line without # is tags only if every word is a status, a result or a competitor brand
        // ("Penawaran Alusign" as a last line is a sentence about our product, not tags).
        $known = fn(string $w) => isset(self::STATUS_TAGS[$w]) || (isset(self::OUTCOME_WORDS[$w]) && !str_contains($w, ' ')) || isset($brandOf[$w]);
        $words = [];
        $body = (string) preg_replace_callback('/#([\p{L}\p{N}_\-]+)/u', function ($m) use (&$words) {
            $words[] = ['w' => mb_strtolower($m[1]), 'raw' => $m[1], 'hash' => true];
            return '';
        }, $caption);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), fn($l) => $l !== ''));
        if (count($lines) > 1) {
            $last = preg_split('/[\s,]+/u', mb_strtolower(end($lines)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($last && count(array_filter($last, $known)) === count($last)) {
                foreach ($last as $w) {
                    $words[] = ['w' => $w, 'raw' => $w, 'hash' => false];
                }
                array_pop($lines);
            }
        }
        $out = ['body' => implode("\n", $lines), 'status' => null, 'outcome' => null, 'brands' => [], 'unknown' => [], 'any' => false];
        foreach ($words as $t) {
            $w = $t['w'];
            if (isset(self::STATUS_TAGS[$w])) {
                $out['status'] ??= self::STATUS_TAGS[$w];
            } elseif (isset(self::OUTCOME_WORDS[$w])) {
                $out['outcome'] ??= self::OUTCOME_WORDS[$w];
            } elseif (isset($brandOf[$w])) {
                $out['brands'][] = $brandOf[$w];
            } elseif (!in_array($w, $ignored, true) && !in_array($w, $own, true) && $t['hash']) {
                $out['unknown'][] = $t['raw'];
            }
        }
        $out['brands'] = array_values(array_unique($out['brands']));
        $out['unknown'] = array_values(array_unique($out['unknown']));
        $out['any'] = $out['status'] !== null || $out['outcome'] !== null || $out['brands'] !== [];
        return $out;
    }

    /**
     * Rule parse of one caption, in the order of the sales report rule v2:
     * tags → first line = store → brand regex (the AI fills only what is still
     * missing, later). Also reads the shelved four-line format (Toko / Status /
     * Hasil / Catatan, optional date line) that some reports already use.
     * @return array{store:string,type:?string,status:?string,outcome:string,note:string,pic:?string,format_ok:int,has_tags:int,unknown_tags:list<string>,template:int,brand:?string,price:?int,brands:list<string>,date:?string}
     */
    public static function parse_report(string $caption, string $msgTs, array $cfg): array
    {
        $tag = self::tags($caption, $cfg);
        $caption = $tag['body'];
        $lines = array_values(array_filter(array_map('trim', explode("\n", $caption)), fn($l) => $l !== ''));
        $r = ['store' => '', 'type' => null, 'status' => null, 'outcome' => Visits::UNKNOWN, 'note' => '', 'pic' => null,
              'format_ok' => 0, 'has_tags' => (int) $tag['any'], 'unknown_tags' => $tag['unknown'], 'template' => 0, 'brand' => null, 'price' => null, 'brands' => [], 'date' => null];
        $isFour = (bool) preg_grep('/^toko\s*:/i', $lines);
        if ($isFour) {
            if ($lines && preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?$/', $lines[0], $m)) {
                $year = isset($m[3]) ? ((int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]) : (int) substr($msgTs, 0, 4);
                if (checkdate((int) $m[2], (int) $m[1], $year)) {
                    $date = sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
                    $r['date'] = $date > substr($msgTs, 0, 10) ? sprintf('%04d-%02d-%02d', $year - 1, (int) $m[2], (int) $m[1]) : $date;
                }
                array_shift($lines);
            }
            $f = [];
            foreach ($lines as $l) {
                if (preg_match('/^(toko|status|hasil|catatan)\s*:\s*(.*)$/i', $l, $m)) {
                    $f[strtolower($m[1])] = trim($m[2]);
                } elseif (isset($f['catatan'])) {
                    $f['catatan'] .= ' ' . $l;
                }
            }
            $r['store'] = (string) ($f['toko'] ?? '');
            $st = strtolower((string) ($f['status'] ?? ''));
            $r['status'] = in_array($st, ['lama', 'baru'], true) ? $st : null;
            $r['outcome'] = self::OUTCOME_WORDS[strtolower(trim((string) ($f['hasil'] ?? '')))] ?? Visits::UNKNOWN;
            $r['note'] = (string) ($f['catatan'] ?? '');
            $r['format_ok'] = (int) ($r['store'] !== '' && $r['status'] !== null && $r['outcome'] !== Visits::UNKNOWN);
            $text = $r['note'];
        } else {
            $clean = array_map(fn($l) => trim((string) preg_replace('/^[\-•*·]+\s*/u', '', $l)), $lines);
            $clean = array_values(array_filter($clean, fn($l) => $l !== ''));
            $types = array_map('strtolower', $cfg['store_types']);
            if ($clean && preg_match('/^visit\s+(.+)$/i', $clean[0], $m) && isset($clean[1])) {
                $r['type'] = self::type_word(trim($m[1]), $cfg);
                $r['store'] = $clean[1];
            } elseif ($clean) {
                $r['store'] = $clean[0];
                if (isset($clean[1]) && in_array(strtolower($clean[1]), $types, true)) {
                    $r['type'] = self::type_word($clean[1], $cfg);
                }
            }
            if (preg_match('/\bpic\s*:?\s*(?:bpk\.?|bp\.?|pak|ibu|bu|mr\.?|mrs\.?)?\s*([A-Za-z][A-Za-z.\' ]{0,40})/i', $caption, $m)) {
                $r['pic'] = trim($m[1]);
            }
            $r['note'] = trim($caption);
            $text = $caption;
        }
        $r['store'] = mb_substr(trim($r['store']), 0, 200);
        // Tags win over what the text seems to say.
        $r['status'] = $tag['status'] ?? $r['status'];
        if ($tag['outcome'] !== null) {
            $r['outcome'] = $tag['outcome'];
        }
        if ($tag['status'] !== null && $tag['outcome'] !== null && $r['store'] !== '') {
            $r['format_ok'] = 1;
        }
        $r['brands'] = array_values(array_unique([...$tag['brands'], ...self::brands($text, $cfg)]));
        $r['brand'] = $r['brands'][0] ?? null;
        $r['price'] = $r['brand'] !== null ? (self::price_near($text, $r['brand']) ?? self::price_near($caption, $r['brand'])) : null;
        foreach ((array) $cfg['template_patterns'] as $pat) {
            if (@preg_match('/' . str_replace('/', '\/', (string) $pat) . '/iu', $caption)) {
                $r['template'] = 1;
                break;
            }
        }
        return $r;
    }

    private static function type_word(string $w, array $cfg): string
    {
        foreach ($cfg['store_types'] as $t) {
            if (strcasecmp($t, $w) === 0) {
                return $t;
            }
        }
        return mb_convert_case($w, MB_CASE_TITLE);
    }

    /** Competitor brands in order of appearance (whole words; our own brand never). */
    public static function brands(string $text, array $cfg): array
    {
        $found = [];
        foreach ((array) $cfg['competitor_brands'] as $b) {
            if (preg_match('/\b' . preg_quote((string) $b, '/') . '\b/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
                $found[(string) $b] = $m[0][1];
            }
        }
        asort($found);
        return array_keys($found);
    }

    /** A price written right after the brand: "Seven 180rb", "Alcopan Rp 175.000", "Marks 1,2jt". Thicknesses (3mm) are not prices. */
    public static function price_near(string $text, string $brand): ?int
    {
        if (!preg_match('/\b' . preg_quote($brand, '/') . '\b[^\d\n]{0,20}?(?:rp\.?\s*)?(\d{1,3}(?:[.,]\d{3})+|\d+(?:[.,]\d+)?)(?!\s*mm)(?!\d)\s*(rb|ribu|k|jt|juta)?\b/iu', $text, $m)) {
            return null;
        }
        $num = $m[1];
        $unit = strtolower((string) ($m[2] ?? ''));
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $num)) {
            $v = (float) str_replace(['.', ','], '', $num);
        } else {
            $v = (float) str_replace(',', '.', $num);
        }
        if (in_array($unit, ['rb', 'ribu', 'k'], true)) {
            $v *= 1000;
        } elseif (in_array($unit, ['jt', 'juta'], true)) {
            $v *= 1000000;
        } elseif ($v < 5000) {
            $v *= 1000;   // "Seven 180" in a sales note means 180 thousand a sheet
        }
        return $v >= 1000 ? (int) round($v) : null;
    }

    // ───────────────────────────── store ↔ customer / prospect ─────────────────────────────

    /** Name for comparing: folded, no punctuation, without PT / CV / Toko …. */
    public static function name_key(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $tokens = array_values(array_filter(explode(' ', $s), fn($t) => $t !== '' && !in_array($t, self::LEGAL_WORDS, true)));
        return $tokens ? implode(' ', $tokens) : trim($s);
    }

    public static function similarity(string $a, string $b): float
    {
        $ka = self::name_key($a);
        $kb = self::name_key($b);
        if ($ka === '' || $kb === '') {
            return 0.0;
        }
        if ($ka === $kb) {
            return 1.0;
        }
        similar_text($ka, $kb, $pct);
        $score = $pct / 100;
        $ta = explode(' ', $ka);
        $tb = explode(' ', $kb);
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        if (array_diff($short, $long) === [] && max(array_map('mb_strlen', $short)) >= 4) {
            $score = max($score, 0.9);   // "Abadi Glass" inside "Abadi Glass Bekasi"
        }
        return round($score, 3);
    }

    /**
     * Candidates for a store name: a confirmed alias wins outright; otherwise
     * CRM customers (name, company), prospects and bank payer names, best first.
     * @return array{alias:?array,list:list<array{key:string,kind:string,id:int,name:string,where:string,score:float}>}
     */
    public static function candidates(PDO $pdo, string $store, array $cfg): array
    {
        $key = self::name_key($store);
        if ($key === '') {
            return ['alias' => null, 'list' => []];
        }
        $st = $pdo->prepare('SELECT * FROM customer_name_aliases WHERE alias_key = ?');
        $st->execute([$key]);
        if ($a = $st->fetch()) {
            $kind = $a['customer_id'] ? 'customer' : 'prospect';
            $id = (int) ($a['customer_id'] ?: $a['prospect_id']);
            return ['alias' => ['key' => "{$kind}:{$id}", 'kind' => $kind, 'id' => $id, 'name' => (string) $a['alias'], 'where' => '', 'score' => 1.0], 'list' => []];
        }
        $best = [];
        $add = function (string $kind, int $id, string $name, string $where) use (&$best, $store, $cfg) {
            $s = self::similarity($store, $name);
            $k = "{$kind}:{$id}";
            if ($s >= (float) $cfg['match_min'] && (!isset($best[$k]) || $best[$k]['score'] < $s)) {
                $best[$k] = ['key' => $k, 'kind' => $kind, 'id' => $id, 'name' => $name, 'where' => $where, 'score' => $s];
            }
        };
        foreach ($pdo->query('SELECT id, name, company, city FROM customers') as $c) {
            $add('customer', (int) $c['id'], (string) $c['name'], (string) $c['city']);
            if (trim((string) $c['company']) !== '') {
                $add('customer', (int) $c['id'], (string) $c['company'], (string) $c['city']);
            }
        }
        foreach ($pdo->query('SELECT id, name, region FROM prospects WHERE merged_into IS NULL') as $p) {
            $add('prospect', (int) $p['id'], (string) $p['name'], (string) $p['region']);
        }
        foreach ($pdo->query('SELECT a.customer_id, a.payer_name, c.city FROM customer_payer_aliases a JOIN customers c ON c.id = a.customer_id') as $a) {
            $add('customer', (int) $a['customer_id'], (string) $a['payer_name'], (string) $a['city']);
        }
        usort($best, fn($x, $y) => $y['score'] <=> $x['score']);
        return ['alias' => null, 'list' => array_slice(array_values($best), 0, 10)];
    }

    /** auto (one clear winner) | pending (choose) | new (nothing close). */
    public static function decide(array $cands, array $cfg): string
    {
        if ($cands['alias']) {
            return 'auto';
        }
        $l = $cands['list'];
        if (!$l) {
            return 'new';
        }
        $auto = (float) $cfg['match_auto'];
        return ($l[0]['score'] >= $auto && (($l[1]['score'] ?? 0) < $auto || $l[1]['key'] === $l[0]['key'])) ? 'auto' : 'pending';
    }

    /** Point a visit at a customer or prospect and write what follows (follow-up, prospect stats, 老/新). */
    public static function attach(PDO $pdo, array $v, string $kind, int $id, string $status, string $by): void
    {
        $set = ['target_type' => $kind, 'customer_id' => $kind === 'customer' ? $id : null, 'prospect_id' => $kind === 'prospect' ? $id : null,
                'new_customer_name' => null, 'match_status' => $status, 'system_lama_baru' => $kind === 'customer' ? 'lama' : 'baru',
                'updated_by' => $by, 'updated_at' => date('Y-m-d H:i:s')];
        $pdo->prepare('UPDATE visits SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $v['id']]);
        $v = array_merge($v, $set);
        if ($kind === 'customer') {
            $fid = Visits::link_customer($pdo, $v, ['id' => null, 'name' => self::sales_name($pdo, $v)]);
            if ($fid && !$v['followup_id']) {
                $pdo->prepare('UPDATE visits SET followup_id = ? WHERE id = ?')->execute([$fid, $v['id']]);
            }
        }
        if (!empty($v['prospect_id'])) {
            Visits::refresh_prospect($pdo, (int) $v['prospect_id']);
        }
    }

    /** A store nobody knows: into the prospect pool (source = visit) with what the visit told us. */
    public static function new_store(PDO $pdo, array $v, string $by): int
    {
        $name = trim((string) ($v['store_name_raw'] ?: $v['new_customer_name']));
        $key = Visits::prospect_key($name, null, null) . ':visit';
        $st = $pdo->prepare('SELECT COALESCE(merged_into, id) FROM prospects WHERE source_key = ?');
        $st->execute([$key]);
        $id = (int) $st->fetchColumn();
        if (!$id) {
            $pdo->prepare("INSERT INTO prospects (name, category, store_type, address, lat, lng, assigned_to, assigned_at, assigned_by, source, source_key, created_by) VALUES (?,?,?,?,?,?,?,?,?, 'visit', ?, ?)")
                ->execute([$name, $v['store_type'], $v['store_type'], $v['address_text'], $v['lat'], $v['lng'], $v['employee_id'],
                           $v['employee_id'] ? date('Y-m-d H:i:s') : null, $v['employee_id'] ? 'visit' : null, $key, $by]);
            $id = (int) $pdo->lastInsertId();
        }
        self::attach($pdo, $v, 'prospect', $id, 'new', $by);
        return $id;
    }

    private static function sales_name(PDO $pdo, array $v): string
    {
        if ($v['employee_id']) {
            $st = $pdo->prepare('SELECT full_name FROM employees WHERE id = ?');
            $st->execute([$v['employee_id']]);
            return (string) ($st->fetchColumn() ?: $v['wa_sender']);
        }
        return (string) $v['wa_sender'];
    }

    /**
     * The same store twice in the pool (a new store met on a visit, then the
     * list imported): visits move to $into, the written names become aliases
     * so the group's spelling matches by itself, $from stays but is hidden.
     * @return int visits moved
     */
    public static function merge_prospect(PDO $pdo, int $from, int $into, string $by): int
    {
        if ($from === $into) {
            return 0;
        }
        $st = $pdo->prepare('SELECT * FROM prospects WHERE id = ?');
        $st->execute([$from]);
        $a = $st->fetch();
        $st->execute([$into]);
        $b = $st->fetch();
        if (!$a || !$b || $b['merged_into'] !== null) {
            return 0;
        }
        $pdo->beginTransaction();
        $names = $pdo->prepare('SELECT DISTINCT store_name_raw FROM visits WHERE prospect_id = ? AND store_name_raw IS NOT NULL');
        $names->execute([$from]);
        $alias = $pdo->prepare('INSERT OR REPLACE INTO customer_name_aliases (customer_id, prospect_id, alias, alias_key, source, confirmed_by) VALUES (NULL,?,?,?,?,?)');
        foreach (array_unique([(string) $a['name'], ...$names->fetchAll(PDO::FETCH_COLUMN)]) as $n) {
            if (self::name_key($n) !== '' && self::name_key($n) !== self::name_key((string) $b['name'])) {
                $alias->execute([$into, $n, self::name_key($n), 'merge', $by]);
            }
        }
        $mv = $pdo->prepare('UPDATE visits SET prospect_id = ? WHERE prospect_id = ?');
        $mv->execute([$into, $from]);
        $n = $mv->rowCount();
        $pdo->prepare('UPDATE customer_name_aliases SET prospect_id = ? WHERE prospect_id = ?')->execute([$into, $from]);
        $pdo->prepare('UPDATE prospects SET merged_into = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$into, $by, date('Y-m-d H:i:s'), $from]);
        $pdo->prepare('UPDATE prospects SET assigned_to = COALESCE(assigned_to, ?), store_type = COALESCE(store_type, ?) WHERE id = ?')->execute([$a['assigned_to'], $a['store_type'], $into]);
        $pdo->commit();
        Visits::refresh_prospect($pdo, $into);
        Visits::refresh_prospect($pdo, $from);
        audit($pdo, 'visits', 'update', 'prospects', $from, (string) $a['name'], "prospects.merged_into: (空) → #{$into} {$b['name']}; {$n} visits moved");
        return $n;
    }

    // ───────────────────────────── import ─────────────────────────────

    /** WhatsApp sender → employee id, from employee_wa_ids. */
    public static function sender_map(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT wa_key, employee_id FROM employee_wa_ids') as $r) {
            $out[$r['wa_key']] = (int) $r['employee_id'];
        }
        return $out;
    }

    /**
     * One export. New messages become visits (not yet matched); a message that
     * is already in only gets the photos it lacked.
     * @return array{import_id:int,messages:int,found:int,new:int,photos:int,ignored:int,unmapped:list<string>,new_ids:list<int>}
     */
    public static function import(PDO $pdo, array $config, string $txt, array $files, string $fileName, string $by): array
    {
        $cfg = self::cfg_db($pdo, $config);
        $msgs = WaChat::messages($txt);
        $res = WaChat::visits($msgs);
        $map = self::sender_map($pdo);
        $find = $pdo->prepare('SELECT id, photos, photo_path FROM visits WHERE wa_hash = ?');
        $out = ['import_id' => 0, 'messages' => $res['messages'], 'found' => count($res['visits']), 'new' => 0, 'photos' => 0, 'ignored' => $res['ignored'], 'unmapped' => [], 'new_ids' => []];
        $pdo->prepare('INSERT INTO wa_imports (file_name, file_hash, messages, visits_found, ignored, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$fileName, sha1($txt), $res['messages'], count($res['visits']), $res['ignored'], $by]);
        $importId = (int) $pdo->lastInsertId();
        $out['import_id'] = $importId;
        foreach ($res['visits'] as $wv) {
            $find->execute([$wv['hash']]);
            $ex = $find->fetch();
            $emp = $map[WaChat::sender_key($wv['sender'])] ?? null;
            if ($emp === null) {
                $out['unmapped'][$wv['sender']] = true;
            }
            if ($ex) {
                $had = array_filter((array) json_decode((string) $ex['photos'], true));
                if (!$had && $files) {
                    $stored = self::store_photos($wv['photos'], $files, substr($wv['ts'], 0, 7));
                    if (array_filter($stored)) {
                        $main = current(array_filter($stored));
                        $pdo->prepare('UPDATE visits SET photos = ?, photo_path = ?, photo_name = ?, photo_mime = ? WHERE id = ?')
                            ->execute([json_encode($stored), $main, $main, self::mime($main), $ex['id']]);
                        $out['photos'] += count(array_filter($stored));
                    }
                }
                continue;
            }
            $p = self::parse_report($wv['caption'], $wv['ts'], $cfg);
            $stored = self::store_photos($wv['photos'], $files, substr($wv['ts'], 0, 7));
            $main = array_values(array_filter($stored))[0] ?? null;
            $visited = $p['date'] !== null ? $p['date'] . ' ' . substr($wv['ts'], 11) : $wv['ts'];
            $row = [
                'visit_no' => Visits::next_no($pdo, date('Y-m-d')), 'employee_id' => $emp, 'visited_at' => $visited,
                'target_type' => 'new', 'new_customer_name' => $p['store'] !== '' ? $p['store'] : null, 'outcome' => $p['outcome'],
                'note' => $p['note'] !== '' ? $p['note'] : null, 'current_supplier' => $p['brand'], 'current_price' => $p['price'] !== null ? (string) $p['price'] : null,
                'photo_path' => $main, 'photo_name' => $main, 'photo_mime' => $main ? self::mime($main) : null, 'photos' => json_encode($stored),
                'source' => 'wa_import', 'wa_sender' => $wv['sender'], 'wa_message_ts' => $wv['ts'], 'wa_hash' => $wv['hash'], 'wa_import_id' => $importId,
                'caption' => $wv['caption'], 'store_name_raw' => $p['store'] !== '' ? $p['store'] : null, 'store_type' => $p['type'], 'pic_name' => $p['pic'],
                'status_lama_baru' => $p['status'], 'late_flag' => $p['date'] !== null ? 1 : 0, 'competitor_brand' => $p['brand'], 'competitor_price' => $p['price'],
                'format_ok' => $p['format_ok'], 'has_tags' => $p['has_tags'], 'unknown_tags' => $p['unknown_tags'] ? json_encode($p['unknown_tags'], JSON_UNESCAPED_UNICODE) : null,
                'template_text' => $p['template'], 'created_by' => $by,
            ];
            $pdo->prepare('INSERT INTO visits (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            $out['new_ids'][] = (int) $pdo->lastInsertId();
            $out['new']++;
            $out['photos'] += count(array_filter($stored));
        }
        $out['unmapped'] = array_keys($out['unmapped']);
        $pdo->prepare('UPDATE wa_imports SET visits_new = ?, photos_added = ?, unmapped = ? WHERE id = ?')
            ->execute([$out['new'], $out['photos'], json_encode($out['unmapped'], JSON_UNESCAPED_UNICODE), $importId]);
        return $out;
    }

    /** Save the export's photos under data/visits/{yyyymm}/ with random names; null where the export left a photo out. */
    private static function store_photos(array $names, array $files, string $ym): array
    {
        $out = [];
        foreach ($names as $n) {
            $src = $n !== null ? ($files[$n] ?? null) : null;
            $bytes = is_array($src) ? (string) @file_get_contents((string) $src['path']) : $src;   // temp file from a zip, or bytes
            if ($bytes === null || Hr::sniff_bytes($bytes) === null) {
                $out[] = null;
                continue;
            }
            $dir = self::photo_dir(str_replace('-', '', $ym));
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $ext = Hr::sniff_bytes($bytes) === 'image/png' ? 'png' : 'jpg';
            $stored = str_replace('-', '', $ym) . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
            file_put_contents(self::photo_dir('') . '/' . $stored, $bytes);
            $out[] = $stored;
        }
        return $out;
    }

    public static function photo_file(string $stored): string
    {
        return self::photo_dir('') . '/' . $stored;
    }

    private static function mime(string $stored): string
    {
        return str_ends_with($stored, '.png') ? 'image/png' : 'image/jpeg';
    }

    // ───────────────────────────── processing (rules + AI) ─────────────────────────────

    /** Visits still to process, oldest first. With AI on: also those the AI has not read yet. */
    public static function todo(PDO $pdo, array $config, int $limit): array
    {
        $ai = AiTask::enabled($config);
        $where = $ai
            ? "source = 'wa_import' AND (match_status IS NULL OR ((outcome = 'unknown' OR store_name_raw IS NULL) AND ai_parse_status IS NULL) OR (photo_path IS NOT NULL AND ai_photo_status IS NULL)
                 OR (match_status = 'pending' AND ai_match_status IS NULL))"
            : "source = 'wa_import' AND match_status IS NULL";
        return $pdo->query("SELECT id FROM visits WHERE {$where} ORDER BY id LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Process a batch. @return array{done:int,left:int} */
    public static function process(PDO $pdo, array $config, int $limit, string $by): array
    {
        $ids = self::todo($pdo, $config, $limit);
        foreach ($ids as $id) {
            self::process_one($pdo, $config, (int) $id, $by);
        }
        return ['done' => count($ids), 'left' => count(self::todo($pdo, $config, 100000))];
    }

    public static function process_one(PDO $pdo, array $config, int $id, string $by): void
    {
        $cfg = self::cfg($config);
        $ai = AiTask::enabled($config);
        $load = function () use ($pdo, $id): array {
            $st = $pdo->prepare('SELECT * FROM visits WHERE id = ?');
            $st->execute([$id]);
            return (array) $st->fetch();
        };
        $v = $load();
        if (!$v) {
            return;
        }
        // 1. Still missing the result or the store after tags / first line / brand regex → AI fills the gaps only.
        if ($ai && ($v['outcome'] === Visits::UNKNOWN || (string) $v['store_name_raw'] === '') && $v['ai_parse_status'] === null) {
            $r = self::ai_parse($pdo, $config, $v);
            $d = $r['data'] ?? null;
            $set = ['ai_parse_status' => $r['status'] === 'ok' ? 'ok' : 'failed', 'ai_parse' => $d ? json_encode($d, JSON_UNESCAPED_UNICODE) : null];
            if ($d) {
                $guess = (string) ($d['outcome_guess'] ?? 'unknown');
                $set['ai_outcome_guess'] = isset(Visits::OUTCOMES[$guess]) ? $guess : null;
                if (trim((string) ($d['store_name'] ?? '')) !== '' && (string) $v['store_name_raw'] === '' && $v['match_status'] === null) {
                    $set['store_name_raw'] = mb_substr(trim((string) $d['store_name']), 0, 200);
                    $set['new_customer_name'] = $set['store_name_raw'];
                }
                foreach (['store_type' => 'store_type', 'pic_name' => 'pic_name', 'needs' => 'needs'] as $col => $k) {
                    if (empty($v[$col]) && trim((string) ($d[$k] ?? '')) !== '') {
                        $set[$col] = mb_substr(trim((string) $d[$k]), 0, 200);
                    }
                }
                if (empty($v['competitor_brand']) && !empty($d['brands'])) {
                    $brands = array_values(array_filter(array_map('strval', (array) $d['brands']), fn($b) => !in_array(mb_strtolower($b), array_map('mb_strtolower', $cfg['own_brands']), true)));
                    if ($brands) {
                        $set['competitor_brand'] = $brands[0];
                        $set['current_supplier'] = $brands[0];
                    }
                }
            }
            $pdo->prepare('UPDATE visits SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
            $v = $load();
        }
        // 2. Store → customer / prospect.
        if ($v['match_status'] === null) {
            $c = self::candidates($pdo, (string) $v['store_name_raw'], $cfg);
            $decision = (string) $v['store_name_raw'] === '' ? 'pending' : self::decide($c, $cfg);
            if ($decision === 'auto') {
                $top = $c['alias'] ?? $c['list'][0];
                $pdo->prepare('UPDATE visits SET match_score = ?, match_candidates = ? WHERE id = ?')->execute([$top['score'], json_encode([$top], JSON_UNESCAPED_UNICODE), $id]);
                self::attach($pdo, $v, $top['kind'], $top['id'], 'auto', $by);
            } elseif ($decision === 'new') {
                $pdo->prepare('UPDATE visits SET match_candidates = ? WHERE id = ?')->execute(['[]', $id]);
                self::new_store($pdo, $load(), $by);
            } else {
                $pdo->prepare("UPDATE visits SET match_status = 'pending', match_score = ?, match_candidates = ? WHERE id = ?")
                    ->execute([$c['list'][0]['score'] ?? null, json_encode($c['list'], JSON_UNESCAPED_UNICODE), $id]);
            }
            $v = $load();
        }
        if ($ai && $v['match_status'] === 'pending' && $v['ai_match_status'] === null) {
            $cands = (array) json_decode((string) $v['match_candidates'], true);
            $r = $cands ? self::ai_match($pdo, $config, $v, $cands) : ['status' => 'skipped', 'data' => null];
            $pdo->prepare('UPDATE visits SET ai_match_status = ?, ai_match = ? WHERE id = ?')
                ->execute([$r['status'] === 'ok' ? 'ok' : ($r['status'] === 'skipped' ? 'skipped' : 'failed'), $r['data'] ? json_encode($r['data'], JSON_UNESCAPED_UNICODE) : null, $id]);
            $v = $load();
        }
        // 3. Watermark → time, address, storefront; address → point → distance.
        if ($ai && $v['photo_path'] && $v['ai_photo_status'] === null) {
            $r = self::ai_photo($pdo, $config, $v);
            $d = $r['data'] ?? null;
            $set = ['ai_photo_status' => $r['status'] === 'ok' ? 'ok' : 'failed', 'ai_photo' => $d ? json_encode($d, JSON_UNESCAPED_UNICODE) : null];
            if ($d) {
                $set['photo_is_store'] = in_array($d['is_store'] ?? '', ['yes', 'no', 'unsure'], true) ? $d['is_store'] : 'unsure';
                $lines = array_values(array_filter(array_map('trim', array_map('strval', (array) ($d['address_lines'] ?? [])))));
                if ($lines) {
                    $set['address_text'] = implode(', ', $lines);
                    $geo = Enrich::resolve($pdo, $config, $set['address_text']);
                    if ($geo['lat'] !== null) {
                        $set['lat'] = $geo['lat'];
                        $set['lng'] = $geo['lng'];
                        [$set['distance_m'], $set['distance_flag']] = Visits::distance(['lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng']],
                            Visits::target_point($pdo, (string) $v['target_type'], (int) ($v['customer_id'] ?: $v['prospect_id']) ?: null));
                    }
                }
                $wm = (string) ($d['datetime'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $wm)) {
                    $wm = strlen($wm) === 16 ? $wm . ':00' : $wm;
                    $set['watermark_at'] = $wm;
                    if (abs(strtotime((string) $v['wa_message_ts']) - strtotime($wm)) > (float) $cfg['late_hours'] * 3600) {
                        $set['visited_at'] = $wm;   // sent later: the photo tells when the visit was
                        $set['late_flag'] = 1;
                    }
                }
            }
            $pdo->prepare('UPDATE visits SET ' . implode(',', array_map(fn($k) => "{$k} = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
        }
    }

    // ───────────────────────────── supervisor ─────────────────────────────

    /**
     * One click: a candidate (customer:ID / prospect:ID) or "new". A chosen
     * candidate becomes an alias of the written name, so it matches by itself next time.
     */
    public static function confirm(PDO $pdo, int $id, string $choice, string $by): bool
    {
        $st = $pdo->prepare("SELECT * FROM visits WHERE id = ? AND source = 'wa_import'");
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) {
            return false;
        }
        // The same written name waiting elsewhere is settled by the same click.
        $same = function () use ($pdo, $v, $id): array {
            $key = self::name_key((string) $v['store_name_raw']);
            if ($key === '') {
                return [];
            }
            $out = [];
            foreach ($pdo->query("SELECT * FROM visits WHERE match_status = 'pending' AND id <> " . (int) $id)->fetchAll() as $o) {
                if (self::name_key((string) $o['store_name_raw']) === $key) {
                    $out[] = $o;
                }
            }
            return $out;
        };
        if ($choice === 'new') {
            foreach (array_merge([$v], $same()) as $o) {
                self::new_store($pdo, $o, $by);
                $pdo->prepare("UPDATE visits SET match_status = 'confirmed' WHERE id = ?")->execute([$o['id']]);
            }
            audit($pdo, 'visits', 'update', 'visits', $id, (string) $v['visit_no'], 'visits.match: ' . (string) $v['store_name_raw'] . ' → (新店)');
            return true;
        }
        if (!preg_match('/^(customer|prospect):(\d+)$/', $choice, $m)) {
            return false;
        }
        $tbl = $m[1] === 'customer' ? 'customers' : 'prospects';
        $chk = $pdo->prepare("SELECT name FROM {$tbl} WHERE id = ?");
        $chk->execute([(int) $m[2]]);
        $name = $chk->fetchColumn();
        if ($name === false) {
            return false;
        }
        foreach (array_merge([$v], $same()) as $o) {
            self::attach($pdo, $o, $m[1], (int) $m[2], 'confirmed', $by);
        }
        $raw = trim((string) $v['store_name_raw']);
        if ($raw !== '' && self::name_key($raw) !== self::name_key((string) $name)) {
            $pdo->prepare('INSERT OR REPLACE INTO customer_name_aliases (customer_id, prospect_id, alias, alias_key, source, confirmed_by) VALUES (?,?,?,?,?,?)')
                ->execute([$m[1] === 'customer' ? (int) $m[2] : null, $m[1] === 'prospect' ? (int) $m[2] : null, $raw, self::name_key($raw), 'confirm', $by]);
        }
        $ai = (array) json_decode((string) $v['ai_match'], true);
        if ($ai) {
            AiTask::suggest($pdo, 'visit_match', 'visits', $id, $ai, (string) ($ai['confidence'] ?? ''), null);
        }
        audit($pdo, 'visits', 'update', 'visits', $id, (string) $v['visit_no'], sprintf('visits.match: %s → %s %s', $raw, $m[1], $name));
        return true;
    }

    public static function adopt_outcome(PDO $pdo, int $id, string $by): bool
    {
        $st = $pdo->prepare('SELECT * FROM visits WHERE id = ? AND ai_outcome_guess IS NOT NULL');
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v || !isset(Visits::OUTCOMES[$v['ai_outcome_guess']])) {
            return false;
        }
        $pdo->prepare('UPDATE visits SET outcome = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$v['ai_outcome_guess'], $by, date('Y-m-d H:i:s'), $id]);
        $v['outcome'] = $v['ai_outcome_guess'];
        if ($v['target_type'] === 'customer') {
            Visits::link_customer($pdo, $v, ['id' => null, 'name' => self::sales_name($pdo, $v)]);
        }
        Visits::refresh_prospect($pdo, $v['prospect_id'] ? (int) $v['prospect_id'] : null);
        audit($pdo, 'visits', 'update', 'visits', $id, (string) $v['visit_no'], 'visits.outcome: unknown → ' . $v['ai_outcome_guess'] . ' (AI)');
        return true;
    }

    /** Senders without an employee yet (distinct, as written), with how many visits wait for them. */
    public static function unmapped(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT wa_sender, COUNT(*) n FROM visits WHERE source = 'wa_import' AND employee_id IS NULL GROUP BY wa_sender ORDER BY wa_sender") as $r) {
            $out[] = ['sender' => (string) $r['wa_sender'], 'visits' => (int) $r['n']];
        }
        return $out;
    }

    /** Map a sender to an employee; the visits waiting for them get the employee. @return int visits filled */
    public static function map_sender(PDO $pdo, string $sender, int $employeeId, string $by): int
    {
        $key = WaChat::sender_key($sender);
        $pdo->prepare('INSERT OR REPLACE INTO employee_wa_ids (employee_id, wa_key, wa_display, created_by) VALUES (?,?,?,?)')->execute([$employeeId, $key, $sender, $by]);
        $n = 0;
        $st = $pdo->query("SELECT id, wa_sender FROM visits WHERE source = 'wa_import' AND employee_id IS NULL");
        $upd = $pdo->prepare('UPDATE visits SET employee_id = ? WHERE id = ?');
        foreach ($st->fetchAll() as $v) {
            if (WaChat::sender_key((string) $v['wa_sender']) === $key) {
                $upd->execute([$employeeId, $v['id']]);
                $n++;
            }
        }
        $pdo->prepare("UPDATE prospects SET assigned_to = ? WHERE source = 'visit' AND assigned_to IS NULL AND id IN (SELECT prospect_id FROM visits WHERE employee_id = ?)")->execute([$employeeId, $employeeId]);
        audit($pdo, 'visits', 'update', 'employee_wa_ids', $employeeId, $sender, "employee_wa_ids: {$sender} → employee #{$employeeId}; {$n} visits");
        return $n;
    }

    // ───────────────────────────── AI tasks ─────────────────────────────

    public static function parse_schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['store_name', 'store_type', 'pic_name', 'brands', 'outcome_guess', 'needs'], 'properties' => [
            'store_name' => ['type' => 'string'], 'store_type' => ['type' => ['string', 'null']], 'pic_name' => ['type' => ['string', 'null']],
            'brands' => ['type' => 'array', 'items' => ['type' => 'string']],
            'outcome_guess' => ['type' => 'string', 'enum' => ['berminat', 'penawaran', 'order', 'tidak_butuh', 'tidak_terhubung', 'unknown']],
            'needs' => ['type' => ['string', 'null']],
        ]];
    }

    public static function photo_schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['datetime', 'address_lines', 'is_store', 'brands_seen'], 'properties' => [
            'datetime' => ['type' => ['string', 'null']], 'address_lines' => ['type' => 'array', 'items' => ['type' => 'string']],
            'is_store' => ['type' => 'string', 'enum' => ['yes', 'no', 'unsure']], 'brands_seen' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
    }

    public static function match_schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['choice', 'confidence', 'reason_zh'], 'properties' => [
            'choice' => ['type' => ['string', 'null']], 'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'reason_zh' => ['type' => 'string'],
        ]];
    }

    public static function digest_schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['zh', 'id'], 'properties' => ['zh' => ['type' => 'string'], 'id' => ['type' => 'string']]];
    }

    public static function ai_parse(PDO $pdo, array $config, array $v): array
    {
        return AiTask::run($pdo, $config, 'visit_parse', [
            'content' => json_encode(['message' => (string) $v['caption']], JSON_UNESCAPED_UNICODE), 'schema' => self::parse_schema(),
            'ref_table' => 'visits', 'ref_id' => (int) $v['id'],
        ]);
    }

    public static function ai_photo(PDO $pdo, array $config, array $v): array
    {
        [$block, $err] = AiTask::image_block(self::photo_file((string) $v['photo_path']), 1024);
        if ($block === null) {
            return ['status' => 'failed', 'data' => null, 'error' => $err];
        }
        return AiTask::run($pdo, $config, 'visit_photo_read', [
            'content' => [$block, ['type' => 'text', 'text' => 'Baca watermark foto ini.']], 'schema' => self::photo_schema(),
            'ref_table' => 'visits', 'ref_id' => (int) $v['id'],
        ]);
    }

    public static function ai_match(PDO $pdo, array $config, array $v, array $cands): array
    {
        $keys = array_column($cands, 'key');
        $list = array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'kind' => $c['kind'] === 'customer' ? 'pelanggan' : 'calon', 'area' => $c['where']], $cands);
        return AiTask::run($pdo, $config, 'visit_match', [
            'content' => json_encode(['store' => ['name' => (string) $v['store_name_raw'], 'type' => $v['store_type'], 'address' => $v['address_text']], 'candidates' => $list], JSON_UNESCAPED_UNICODE),
            'schema' => self::match_schema(), 'ref_table' => 'visits', 'ref_id' => (int) $v['id'],
            'check' => fn(array $d): string => ($d['choice'] === null || in_array($d['choice'], $keys, true)) ? '' : 'choice is not one of the candidate keys',
        ]);
    }

    /** The week's facts the digest is written from: numbers only, no phones, no prices of our own. */
    public static function week_facts(PDO $pdo, string $from, string $to): array
    {
        $rows = Visits::fetch($pdo, $from, $to);
        $prevFrom = HrContract::add_days($from, -7);
        $prev = Visits::fetch($pdo, $prevFrom, HrContract::add_days($to, -7));
        $per = [];
        $areas = [];
        $brands = [];
        foreach ($rows as $v) {
            $s = $v['employee_id'] ? (string) $v['sales'] : 'Pengirim belum dikenali';   // never a phone number to the model
            $per[$s] ??= ['visits' => 0, 'baru' => 0, 'outcomes' => []];
            $per[$s]['visits']++;
            $per[$s]['baru'] += (($v['status_lama_baru'] ?? $v['system_lama_baru']) === 'baru') ? 1 : 0;
            $per[$s]['outcomes'][$v['outcome']] = ($per[$s]['outcomes'][$v['outcome']] ?? 0) + 1;
            $area = (string) ($v['region'] ?? '') ?: (string) ($v['customer_city'] ?? '');
            if ($area !== '') {
                $areas[$area] = ($areas[$area] ?? 0) + 1;
            }
            if ($v['competitor_brand']) {
                $brands[$v['competitor_brand']][] = $v['competitor_price'] !== null ? (int) $v['competitor_price'] : null;
            }
        }
        $prevBrands = [];
        foreach ($prev as $v) {
            if ($v['competitor_brand'] && $v['competitor_price'] !== null) {
                $prevBrands[$v['competitor_brand']][] = (int) $v['competitor_price'];
            }
        }
        $avg = fn(array $xs) => ($xs = array_filter($xs, fn($x) => $x !== null)) ? (int) round(array_sum($xs) / count($xs)) : null;
        $brandOut = [];
        foreach ($brands as $b => $ps) {
            $brandOut[$b] = ['mentions' => count($ps), 'avg_price' => $avg($ps), 'avg_price_last_week' => $avg($prevBrands[$b] ?? [])];
        }
        arsort($areas);
        return ['week' => [$from, $to], 'per_sales' => $per, 'areas' => array_slice($areas, 0, 10, true), 'competitors' => $brandOut,
                'neglected_a_customers' => array_slice(array_column(self::neglected($pdo, $to, 60), 'name'), 0, 15), 'visits_total' => count($rows), 'visits_last_week' => count($prev)];
    }

    public static function digest(PDO $pdo, array $config, string $week, string $by): array
    {
        $b = Visits::week_bounds($week);
        if (!$b) {
            return ['status' => 'failed', 'error' => 'bad week'];
        }
        $r = AiTask::run($pdo, $config, 'visit_weekly_digest', [
            'content' => json_encode(self::week_facts($pdo, $b[0], $b[1]), JSON_UNESCAPED_UNICODE), 'schema' => self::digest_schema(),
        ]);
        if ($r['status'] === 'ok') {
            $pdo->prepare('INSERT INTO visit_digests (week, text_zh, text_id, ai_call_id, created_by) VALUES (?,?,?,?,?)')
                ->execute([$week, (string) $r['data']['zh'], (string) $r['data']['id'], $r['call_id'], $by]);
        }
        return $r;
    }

    // ───────────────────────────── analysis ─────────────────────────────

    /** A-list customers (该回访 in the call lists) nobody has visited for $days days. */
    public static function neglected(PDO $pdo, string $today, int $days): array
    {
        $cut = HrContract::add_days($today, -$days);
        return $pdo->query("SELECT c.id, c.name, c.owner, MAX(v.visited_at) last_visit FROM customer_insights i JOIN customers c ON c.id = i.customer_id
                             LEFT JOIN visits v ON v.customer_id = c.id WHERE i.in_a = 1 GROUP BY c.id
                             HAVING last_visit IS NULL OR date(last_visit) < " . $pdo->quote($cut) . ' ORDER BY last_visit IS NOT NULL, last_visit, c.name')->fetchAll();
    }

    /** Quality, per salesperson (boss only): template text, no result, not a storefront, sent > 2 h later. */
    public static function quality(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare("SELECT COALESCE(e.full_name, v.wa_sender) sales, COUNT(*) n,
                    SUM(v.template_text) template, SUM(v.outcome = 'unknown') unknown, SUM(v.photo_is_store = 'no') not_store,
                    SUM(v.photo_is_store IS NOT NULL) photo_read, SUM(v.late_flag) late, SUM(v.has_tags) tagged
               FROM visits v LEFT JOIN employees e ON e.id = v.employee_id
              WHERE v.source = 'wa_import' AND date(v.visited_at) BETWEEN ? AND ? GROUP BY sales ORDER BY sales");
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }

    /** Competitor board: brand × price × area (Tidak Butuh visits, or all). */
    public static function competitors(PDO $pdo, string $from, string $to, bool $onlyNoNeed): array
    {
        $st = $pdo->prepare("SELECT v.competitor_brand brand, COALESCE(NULLIF(p.region, ''), NULLIF(c.city, ''), '—') area, COUNT(*) n,
                    MIN(v.competitor_price) min_price, MAX(v.competitor_price) max_price, AVG(v.competitor_price) avg_price, SUM(v.competitor_price IS NOT NULL) priced
               FROM visits v LEFT JOIN prospects p ON p.id = v.prospect_id LEFT JOIN customers c ON c.id = v.customer_id
              WHERE v.competitor_brand IS NOT NULL AND date(v.visited_at) BETWEEN ? AND ?" . ($onlyNoNeed ? " AND v.outcome = 'tidak_butuh'" : '') . '
              GROUP BY brand, area ORDER BY n DESC, brand');
        $st->execute([$from, $to]);
        return $st->fetchAll();
    }
}
