<?php
declare(strict_types=1);

/**
 * Historical sales (历史销售) — orders from before the platform went live.
 *
 * The company ran a simple bookkeeping app (销售单 export) from January 2026
 * until the platform took over real orders on 2026-08-04. Those orders are
 * needed for one thing above all: yearly sales totals that cover the whole
 * year, not just the months the platform has seen. So they live in their own
 * two tables, read-only, and never touch the live workflow: no approval, no
 * DO / invoice, no stock movement, no receivables (every one of them was
 * recorded as paid in cash — the old app had no credit sales).
 *
 * Reporting rule: history is counted by document date, live sales by invoice
 * date (the dashboard's 销售额), and the two are added. The cut-over date is
 * enforced at import time (--until) so one month is never counted twice.
 */
final class SalesHistory
{
    public const SOURCE = 'legacy_excel';

    /** The platform's first real order; history imports stop the day before. */
    public const DEFAULT_UNTIL = '2026-08-03';

    /** Column headers of the old app's 销售单 export, by meaning. */
    private const COLS = [
        'seq' => '序号', 'date' => '单据日期', 'doc_no' => '单据编号', 'customer' => '客户名称',
        'product' => '商品名称', 'spec' => '规格', 'unit' => '单位', 'qty' => '数量', 'price' => '折后价',
        'line_amount' => '含税金额', 'line_note' => '备注', 'goods' => '折后金额', 'shipping' => '运费',
        'amount' => '本单应收', 'paid' => '本单实收', 'pay_status' => '收款状态', 'account' => '结算账户',
        'doc_note' => '单据备注', 'salesperson' => '业务员',
    ];

    // ── Schema ───────────────────────────────────────────────────────────────

    public static function ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            doc_no TEXT NOT NULL UNIQUE, doc_date TEXT NOT NULL,
            customer_name TEXT NOT NULL, customer_id INTEGER,
            salesperson TEXT, submitter TEXT,
            goods_amount REAL NOT NULL DEFAULT 0, shipping REAL NOT NULL DEFAULT 0,
            amount REAL NOT NULL DEFAULT 0, paid REAL NOT NULL DEFAULT 0,
            note TEXT, source TEXT NOT NULL DEFAULT 'legacy_excel', batch TEXT,
            imported_by TEXT, imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sales_history_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            history_id INTEGER NOT NULL REFERENCES sales_history(id) ON DELETE CASCADE,
            line_no INTEGER NOT NULL DEFAULT 1, product_id INTEGER, sku TEXT, color TEXT, spec TEXT,
            qty REAL NOT NULL DEFAULT 0, unit TEXT, price REAL NOT NULL DEFAULT 0, amount REAL NOT NULL DEFAULT 0,
            raw_name TEXT, note TEXT)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_history_date ON sales_history(doc_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_history_customer ON sales_history(customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sales_history_items ON sales_history_items(history_id)');
    }

    // ── Reading the export ───────────────────────────────────────────────────

    /**
     * Minimal .xlsx reader: first worksheet, shared strings resolved, each row
     * as a list of cell values (floats for numbers, strings otherwise, null for
     * empty). Enough for a flat export; no styles, formulas or dates — the old
     * app writes dates as text. $sheet picks a worksheet by its tab name
     * (the finance workbook, FA-AI-01); null = the first one.
     */
    public static function read_xlsx(string $path, ?string $sheet = null): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('zip extension missing');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("cannot open $path");
        }
        $strings = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = new SimpleXMLElement($ss);
            foreach ($x->si as $si) {
                // A string may be split into runs (<r><t>..</t></r>); join them.
                $t = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                    $t .= (string) $node;
                }
                $strings[] = $t;
            }
        }
        $sheetName = self::first_sheet($zip, $sheet);
        $xml = $zip->getFromName($sheetName);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('worksheet not found');
        }
        $rows = [];
        $sheet = new SimpleXMLElement($xml);
        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $out = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::col_index(preg_replace('/\d+/', '', $ref) ?? '');
                $type = (string) $c['t'];
                $v = null;
                if ($type === 's') {
                    $v = $strings[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $v = (string) $c->is->t;
                } elseif (isset($c->v)) {
                    $v = $type === 'str' || $type === 'b' ? (string) $c->v : (float) $c->v;
                }
                $out[$col] = $v;
            }
            if ($out === []) {
                continue;
            }
            $max = max(array_keys($out));
            $line = array_fill(0, $max + 1, null);
            foreach ($out as $i => $v) {
                $line[$i] = $v;
            }
            $rows[] = $line;
        }
        return $rows;
    }

    private static function first_sheet(ZipArchive $zip, ?string $name = null): string
    {
        // Resolve sheet 1 through the workbook relationships; fall back to the usual path.
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false) {
            $w = new SimpleXMLElement($wb);
            $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $first = null;
            foreach ($w->xpath('//m:sheets/m:sheet') ?: [] as $sh) {
                if ($name === null || trim((string) $sh['name']) === $name) {
                    $first = $sh;
                    break;
                }
            }
            if ($first === null && $name !== null) {
                throw new RuntimeException("worksheet \"{$name}\" not found");
            }
            if ($first !== null) {
                $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $r = new SimpleXMLElement($rels);
                foreach ($r->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = ltrim((string) $rel['Target'], '/');
                        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    }
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function col_index(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }

    // ── Parsing ──────────────────────────────────────────────────────────────

    /**
     * Group the flat export into documents. The export repeats the header
     * columns only on a document's first line; continuation lines carry just
     * the product columns.
     *
     * @return array{docs: array, errors: array}
     */
    public static function parse(array $rows): array
    {
        if ($rows === []) {
            return ['docs' => [], 'errors' => ['empty file']];
        }
        $header = array_map(fn($v) => trim((string) $v), $rows[0]);
        $idx = [];
        $missing = [];
        foreach (self::COLS as $key => $label) {
            $i = array_search($label, $header, true);
            if ($i === false) {
                $missing[] = $label;
            } else {
                $idx[$key] = $i;
            }
        }
        if ($missing !== []) {
            return ['docs' => [], 'errors' => ['missing columns: ' . implode(', ', $missing)]];
        }
        $get = function (array $r, string $k) use ($idx) {
            return $r[$idx[$k]] ?? null;
        };
        $str = fn($v): string => trim((string) ($v ?? ''));
        $num = fn($v): float => is_numeric($v) ? (float) $v : (float) str_replace(',', '', $str($v));

        $docs = [];
        $errors = [];
        $cur = null;
        foreach (array_slice($rows, 1) as $n => $r) {
            $lineNo = $n + 2;
            if (array_filter($r, fn($v) => $v !== null && $v !== '') === []) {
                continue;
            }
            $docNo = $str($get($r, 'doc_no'));
            if ($docNo !== '') {
                if ($cur !== null) {
                    $docs[] = $cur;
                }
                $date = self::normalize_date($str($get($r, 'date')));
                if ($date === null) {
                    $errors[] = "line $lineNo: bad date '" . $str($get($r, 'date')) . "' on $docNo";
                }
                $cur = [
                    'doc_no'        => $docNo,
                    'doc_date'      => $date ?? '',
                    'customer_name' => preg_replace('/\s+/u', ' ', $str($get($r, 'customer'))) ?? '',
                    'salesperson'   => $str($get($r, 'salesperson')),
                    'goods_amount'  => $num($get($r, 'goods')),
                    'shipping'      => $num($get($r, 'shipping')),
                    'amount'        => $num($get($r, 'amount')),
                    'paid'          => $num($get($r, 'paid')),
                    'pay_status'    => $str($get($r, 'pay_status')),
                    'account'       => $str($get($r, 'account')),
                    'note'          => $str($get($r, 'doc_note')),
                    'items'         => [],
                ];
            } elseif ($cur === null) {
                $errors[] = "line $lineNo: product line before any document";
                continue;
            }
            $name = $str($get($r, 'product'));
            if ($name === '') {
                continue;
            }
            $p = self::parse_product($name, $str($get($r, 'spec')));
            $cur['items'][] = $p + [
                'raw_name' => $name,
                'qty'      => $num($get($r, 'qty')),
                'unit'     => $str($get($r, 'unit')) !== '' ? $str($get($r, 'unit')) : '张',
                'price'    => $num($get($r, 'price')),
                'amount'   => $num($get($r, 'line_amount')),
                'note'     => $str($get($r, 'line_note')),
            ];
        }
        if ($cur !== null) {
            $docs[] = $cur;
        }
        return ['docs' => $docs, 'errors' => $errors];
    }

    /** '2026-08-31', '2026/8/31' and '31-08-2026' all become ISO; anything else is null. */
    public static function normalize_date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (is_numeric($s) && (float) $s > 20000) {
            // Excel serial date
            return date('Y-m-d', (int) (((float) $s - 25569) * 86400));
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Split "SJ8037黑金沙（Galaxy Black）4.0*0.21" into sku / colour / spec, the
     * three fields the product catalogue is keyed on. The old app's 规格 column
     * ("黑金沙（Galaxy Black）/4.0*0.21") is the fallback for the spec.
     *
     * @return array{sku: ?string, color: string, spec: string}
     */
    public static function parse_product(string $name, string $specCol = ''): array
    {
        $n = str_replace(['（', '）'], ['(', ')'], trim($name));
        $sku = null;
        if (preg_match('/^\s*(SJ\s?[0-9][0-9A-Z]*(?:-[0-9A-Z]+)?)/i', $n, $m)) {
            $sku = strtoupper(str_replace(' ', '', $m[1]));
            $n = trim(substr($n, strlen($m[0])));
        } elseif (preg_match('/^\s*(\d{3,4})(?=\D)/', $n, $m)) {
            // Typo in the old app: "825高光白…" lost its SJ prefix. SJ8825 exists; 825 does not.
            $sku = 'SJ' . (strlen($m[1]) === 3 ? '8' . $m[1] : $m[1]);
            $n = trim(substr($n, strlen($m[0])));
        }
        $spec = '';
        // Spec is "4.0*0.21…" or the short form "3.10拉丝" / "3.06印花" the catalogue also uses.
        if (preg_match('/(\d\.\d\*\d\.\d{2}|\d\.\d{2}(?=[^\d.]|$))(.*)$/u', $n, $m)) {
            $spec = $m[1] . trim(preg_replace('/\s*\(ECO\)\s*/i', '', $m[2]) ?? '');
            $n = trim(substr($n, 0, (int) strpos($n, $m[1])));
        } elseif ($specCol !== '' && preg_match('/((?:\d\.\d\*\d\.\d{2}|\d\.\d{2})\S*)/u', $specCol, $m)) {
            $spec = preg_replace('/\s*\(ECO\)\s*/i', '', $m[1]) ?? $m[1];
        }
        $color = preg_replace('/\s*\(\s*/', ' (', $n) ?? $n;
        $color = trim(preg_replace('/\s+/u', ' ', $color) ?? $color);
        return ['sku' => $sku, 'color' => $color, 'spec' => $spec];
    }

    // ── Matching against the platform's master data ──────────────────────────

    /** Lower-case, legal-form words and punctuation stripped: "PT  Unggul Jaya" ≡ "Unggul Jaya". */
    public static function normalize_name(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\b(pt|cv|tk|toko|ud|bpk|ibu)\b\.?/u', ' ', $s) ?? $s;
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? $s;
    }

    /** Retail walk-ins were booked under one generic name; they are not a customer. */
    public static function is_walk_in(string $name): bool
    {
        return in_array(self::normalize_name($name), ['零售散客', '散客', 'retail', 'umum', 'cash'], true);
    }

    /**
     * Build the lookup tables once per import. Customers match on name or
     * company; a name with a "/" alias ("A / B") matches on either half.
     *
     * @return array{customers: array<string,int>, products: array<string,int>, users: array<string,string>}
     */
    public static function lookups(PDO $pdo): array
    {
        $customers = [];
        foreach ($pdo->query('SELECT COALESCE(merged_into, id) id, name, company FROM customers ORDER BY merged_into IS NOT NULL, id') as $c) {
            foreach ([(string) $c['name'], (string) $c['company']] as $field) {
                foreach (explode('/', $field) as $part) {
                    $k = self::normalize_name($part);
                    if ($k !== '' && !isset($customers[$k])) {
                        $customers[$k] = (int) $c['id'];
                    }
                }
            }
        }
        $products = [];
        foreach ($pdo->query('SELECT id, sku, spec FROM products ORDER BY id') as $p) {
            $products[strtoupper((string) $p['sku']) . '|' . (string) $p['spec']] ??= (int) $p['id'];
            // Spec with the finish suffix dropped ("4.0*0.15抗刮木纹" → "4.0*0.15") as a weaker key.
            $base = preg_replace('/[^0-9.*].*$/u', '', (string) $p['spec']) ?? '';
            $products[strtoupper((string) $p['sku']) . '|~' . $base] ??= (int) $p['id'];
        }
        $users = [];
        foreach ($pdo->query('SELECT name FROM users') as $u) {
            $users[mb_strtolower(trim((string) $u['name']))] = (string) $u['name'];
        }
        return ['customers' => $customers, 'products' => $products, 'users' => $users];
    }

    public static function match_customer(array $lk, string $name): ?int
    {
        if (self::is_walk_in($name)) {
            return null;
        }
        foreach (explode('/', $name) as $part) {
            $k = self::normalize_name($part);
            if ($k !== '' && isset($lk['customers'][$k])) {
                return $lk['customers'][$k];
            }
        }
        return null;
    }

    public static function match_product(array $lk, ?string $sku, string $spec): ?int
    {
        if ($sku === null) {
            return null;
        }
        $sku = strtoupper($sku);
        if (isset($lk['products'][$sku . '|' . $spec])) {
            return $lk['products'][$sku . '|' . $spec];
        }
        $base = preg_replace('/[^0-9.*].*$/u', '', $spec) ?? '';
        return $lk['products'][$sku . '|~' . $base] ?? null;
    }

    /** Old-app salesperson → platform user name; an unknown name is kept as typed. */
    public static function match_user(array $lk, string $name, array $aliases = []): string
    {
        $k = mb_strtolower(trim($name));
        if (isset($aliases[$k])) {
            $k = mb_strtolower($aliases[$k]);
        }
        return $lk['users'][$k] ?? trim($name);
    }

    // ── Import ───────────────────────────────────────────────────────────────

    /**
     * Resolve every document against master data and (with $apply) write it.
     * Documents already present (same doc_no) are skipped, so re-running an
     * import after fixing the file only adds what is new. Nothing is ever
     * updated or deleted here; use the CLI's --purge-batch for a redo.
     *
     * @param array $opt  until: last document date to take (inclusive);
     *                    aliases: salesperson name → user name; batch: label;
     *                    by: who ran it.
     * @return array report with counts and the unmatched names
     */
    public static function import(PDO $pdo, array $docs, array $opt, bool $apply): array
    {
        $until = (string) ($opt['until'] ?? self::DEFAULT_UNTIL);
        $aliases = [];
        foreach ((array) ($opt['aliases'] ?? []) as $from => $to) {
            $aliases[mb_strtolower(trim((string) $from))] = (string) $to;
        }
        $lk = self::lookups($pdo);
        $existing = $pdo->query('SELECT doc_no FROM sales_history')->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_fill_keys(array_map('strval', $existing), true);

        $rep = [
            'docs' => 0, 'items' => 0, 'amount' => 0.0, 'skipped_after' => 0, 'skipped_existing' => 0, 'skipped_bad' => 0,
            'customers_matched' => 0, 'customers_unmatched' => [], 'walk_in' => 0,
            'products_unmatched' => [], 'users_unmatched' => [], 'first' => null, 'last' => null,
        ];
        $ready = [];
        foreach ($docs as $d) {
            if ($d['doc_date'] === '' || $d['customer_name'] === '') {
                $rep['skipped_bad']++;
                continue;
            }
            if ($d['doc_date'] > $until) {
                $rep['skipped_after']++;
                continue;
            }
            if (isset($existing[$d['doc_no']])) {
                $rep['skipped_existing']++;
                continue;
            }
            $cid = self::match_customer($lk, $d['customer_name']);
            if ($cid !== null) {
                $rep['customers_matched']++;
            } elseif (self::is_walk_in($d['customer_name'])) {
                $rep['walk_in']++;
            } else {
                $rep['customers_unmatched'][$d['customer_name']] = ($rep['customers_unmatched'][$d['customer_name']] ?? 0) + 1;
            }
            $submitter = self::match_user($lk, $d['salesperson'], $aliases);
            if ($d['salesperson'] !== '' && !isset($lk['users'][mb_strtolower($submitter)])) {
                $rep['users_unmatched'][$d['salesperson']] = ($rep['users_unmatched'][$d['salesperson']] ?? 0) + 1;
            }
            foreach ($d['items'] as &$it) {
                $it['product_id'] = self::match_product($lk, $it['sku'], $it['spec']);
                if ($it['product_id'] === null) {
                    $key = ($it['sku'] ?? '?') . ' ' . $it['spec'];
                    $rep['products_unmatched'][$key] = ($rep['products_unmatched'][$key] ?? 0) + 1;
                }
            }
            unset($it);
            $d['customer_id'] = $cid;
            $d['submitter'] = $submitter;
            $ready[] = $d;
            $rep['docs']++;
            $rep['items'] += count($d['items']);
            $rep['amount'] += $d['amount'];
            $rep['first'] = $rep['first'] === null ? $d['doc_date'] : min($rep['first'], $d['doc_date']);
            $rep['last'] = $rep['last'] === null ? $d['doc_date'] : max($rep['last'], $d['doc_date']);
        }
        arsort($rep['customers_unmatched']);
        arsort($rep['products_unmatched']);
        arsort($rep['users_unmatched']);

        if (!$apply || $ready === []) {
            return $rep;
        }
        $batch = (string) ($opt['batch'] ?? date('Ymd-His'));
        $by = (string) ($opt['by'] ?? 'cli');
        $insDoc = $pdo->prepare('INSERT INTO sales_history (doc_no, doc_date, customer_name, customer_id, salesperson, submitter,
            goods_amount, shipping, amount, paid, note, source, batch, imported_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insItem = $pdo->prepare('INSERT INTO sales_history_items (history_id, line_no, product_id, sku, color, spec, qty, unit, price, amount, raw_name, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $pdo->beginTransaction();
        try {
            foreach ($ready as $d) {
                // Every old-app document was booked as settled; keep what it says but default to paid.
                $paid = $d['paid'] > 0 || $d['amount'] == 0.0 ? $d['paid'] : $d['amount'];
                $insDoc->execute([
                    $d['doc_no'], $d['doc_date'], $d['customer_name'], $d['customer_id'], $d['salesperson'] !== '' ? $d['salesperson'] : null,
                    $d['submitter'] !== '' ? $d['submitter'] : null, $d['goods_amount'], $d['shipping'], $d['amount'], $paid,
                    $d['note'] !== '' ? $d['note'] : null, self::SOURCE, $batch, $by,
                ]);
                $hid = (int) $pdo->lastInsertId();
                foreach ($d['items'] as $i => $it) {
                    $insItem->execute([
                        $hid, $i + 1, $it['product_id'], $it['sku'], $it['color'], $it['spec'], $it['qty'], $it['unit'],
                        $it['price'], $it['amount'], $it['raw_name'], $it['note'] !== '' ? $it['note'] : null,
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $rep['batch'] = $batch;
        return $rep;
    }

    /** Remove one import batch (a redo after fixing the file). Returns rows removed. */
    public static function purge_batch(PDO $pdo, string $batch): int
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $st = $pdo->prepare('DELETE FROM sales_history_items WHERE history_id IN (SELECT id FROM sales_history WHERE batch = ?)');
        $st->execute([$batch]);
        $st = $pdo->prepare('DELETE FROM sales_history WHERE batch = ?');
        $st->execute([$batch]);
        return $st->rowCount();
    }

    // ── Customer list from history ───────────────────────────────────────────

    /**
     * Create the customers that only the old app knew, and refresh the ones
     * that bought: tag 潜在 → 成交, empty owner ← history salesperson,
     * last_contact forward to the last history purchase. See
     * tools/sync_history_customers.php. Returns what was (or would be) done.
     */
    public static function sync_customers(PDO $pdo, bool $apply): array
    {
        $rep = ['create' => [], 'tagged' => 0, 'owned' => 0, 'contacted' => 0];

        // 1. Unlinked names → one customer per normalised name (most common spelling wins).
        $rows = $pdo->query('SELECT id, customer_name, COALESCE(submitter, salesperson) AS who, amount, doc_date FROM sales_history WHERE customer_id IS NULL ORDER BY doc_date')->fetchAll();
        $groups = [];
        foreach ($rows as $r) {
            if (self::is_walk_in($r['customer_name'])) {
                continue;
            }
            $k = self::normalize_name(explode('/', $r['customer_name'])[0]);
            if ($k === '') {
                continue;
            }
            $g = &$groups[$k];
            $g ??= ['names' => [], 'who' => [], 'ids' => [], 'docs' => 0, 'amount' => 0.0, 'last' => ''];
            $g['names'][$r['customer_name']] = ($g['names'][$r['customer_name']] ?? 0) + 1;
            if ((string) $r['who'] !== '') {
                $g['who'][$r['who']] = ($g['who'][$r['who']] ?? 0) + 1;
            }
            $g['ids'][] = (int) $r['id'];
            $g['docs']++;
            $g['amount'] += (float) $r['amount'];
            $g['last'] = max($g['last'], (string) $r['doc_date']);
            unset($g);
        }
        $lk = self::lookups($pdo);
        foreach ($groups as $k => $g) {
            arsort($g['names']);
            arsort($g['who']);
            $name = (string) array_key_first($g['names']);
            // Prefer a spelling with the legal form when the counts tie ("PT X" over "X").
            foreach ($g['names'] as $n => $c) {
                if ($c === reset($g['names']) && preg_match('/^(PT|CV)\b/i', $n)) {
                    $name = $n;
                    break;
                }
            }
            // Owner only when that salesperson has a platform account; otherwise the
            // customer lands in the "no owner" list for the manager to assign.
            $owner = $g['who'] !== [] ? (string) array_key_first($g['who']) : null;
            if ($owner !== null && !isset($lk['users'][mb_strtolower($owner)])) {
                $owner = null;
            }
            $existing = $lk['customers'][$k] ?? null;   // created by an earlier run, or added by hand since the import
            $rep['create'][] = ['name' => $name, 'owner' => $owner, 'docs' => $g['docs'], 'amount' => $g['amount'], 'ids' => $g['ids'], 'existing' => $existing];
        }
        if ($apply) {
            $pdo->beginTransaction();
            try {
                $insC = $pdo->prepare('INSERT INTO customers (name, company, owner, tag, note, last_contact) VALUES (?, ?, ?, ?, ?, ?)');
                $linkH = $pdo->prepare('UPDATE sales_history SET customer_id = ? WHERE id = ?');
                foreach ($rep['create'] as &$c) {
                    $cid = $c['existing'];
                    if ($cid === null) {
                        $insC->execute([$c['name'], preg_match('/^(PT|CV)\b/i', $c['name']) ? $c['name'] : null, $c['owner'], '成交', '来自历史销售导入', null]);
                        $cid = (int) $pdo->lastInsertId();
                        $c['created'] = true;
                    }
                    foreach ($c['ids'] as $hid) {
                        $linkH->execute([$cid, $hid]);
                    }
                    $c['id'] = $cid;
                }
                unset($c);
                $pdo->commit();
                $made = count(array_filter($rep['create'], fn($c) => !empty($c['created'])));
                if ($made > 0) {
                    audit($pdo, 'customers', 'create', 'customer', null, '历史销售导入', sprintf('从历史销售批量建档 %d 个客户（tools/sync_history_customers.php）', $made));
                }
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        // 2. Refresh customers that bought (now including the ones just created).
        $agg = $pdo->query("SELECT customer_id, MAX(doc_date) last_day, COUNT(*) n FROM sales_history WHERE customer_id IS NOT NULL GROUP BY customer_id")->fetchAll();
        $whoOf = $pdo->prepare("SELECT COALESCE(submitter, salesperson) w, COUNT(*) c FROM sales_history WHERE customer_id = ? AND COALESCE(submitter, salesperson, '') <> '' GROUP BY w ORDER BY c DESC LIMIT 1");
        $cust = $pdo->prepare('SELECT id, tag, owner, last_contact FROM customers WHERE id = ?');
        $updTag = $pdo->prepare("UPDATE customers SET tag = '成交' WHERE id = ?");
        $updOwner = $pdo->prepare('UPDATE customers SET owner = ? WHERE id = ?');
        $updContact = $pdo->prepare('UPDATE customers SET last_contact = ? WHERE id = ?');
        if ($apply) {
            $pdo->beginTransaction();
        }
        foreach ($agg as $a) {
            $cust->execute([(int) $a['customer_id']]);
            $c = $cust->fetch();
            if (!$c) {
                continue;
            }
            if (($c['tag'] ?? '') === '潜在' || ($c['tag'] ?? '') === '') {
                $rep['tagged']++;
                if ($apply) {
                    $updTag->execute([(int) $c['id']]);
                }
            }
            if (trim((string) $c['owner']) === '') {
                $whoOf->execute([(int) $c['id']]);
                $w = $whoOf->fetch();
                if ($w && isset($lk['users'][mb_strtolower((string) $w['w'])])) {
                    $rep['owned']++;
                    if ($apply) {
                        $updOwner->execute([(string) $w['w'], (int) $c['id']]);
                    }
                }
            }
            if ((string) $c['last_contact'] < (string) $a['last_day']) {
                $rep['contacted']++;
                if ($apply) {
                    $updContact->execute([(string) $a['last_day'], (int) $c['id']]);
                }
            }
        }
        if ($apply) {
            $pdo->commit();
        }
        return $rep;
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    /** Years that have any sales, history or live, newest first. */
    public static function years(PDO $pdo): array
    {
        $ys = $pdo->query("SELECT DISTINCT substr(doc_date,1,4) y FROM sales_history
                            UNION SELECT DISTINCT substr(invoice_date,1,4) FROM invoices WHERE voided_at IS NULL
                            ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
        $ys = array_values(array_filter(array_map('intval', $ys)));
        return $ys === [] ? [(int) date('Y')] : $ys;
    }

    /**
     * One year, month by month: history amount, live invoiced amount, sheets,
     * document counts — and the same split by salesperson and by customer.
     * Live figures use exactly the dashboard's definitions (invoices by
     * invoice date, voided excluded; sheets on the shipping basis).
     */
    public static function yearly(PDO $pdo, int $year, ?string $owner = null): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[sprintf('%04d-%02d', $year, $m)] = ['hist' => 0.0, 'live' => 0.0, 'hist_docs' => 0, 'live_docs' => 0, 'hist_sheets' => 0.0, 'live_sheets' => 0.0];
        }
        $ownerSql = $owner !== null ? ' AND submitter = ?' : '';
        $ownerArg = $owner !== null ? [$owner] : [];

        $st = $pdo->prepare("SELECT substr(doc_date,1,7) m, SUM(amount) v, COUNT(*) c FROM sales_history
                              WHERE doc_date BETWEEN ? AND ?$ownerSql GROUP BY m");
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['hist'] = (float) $r['v'];
                $months[$r['m']]['hist_docs'] = (int) $r['c'];
            }
        }
        $st = $pdo->prepare("SELECT substr(h.doc_date,1,7) m, SUM(i.qty) q FROM sales_history_items i JOIN sales_history h ON h.id = i.history_id
                              WHERE h.doc_date BETWEEN ? AND ?" . str_replace('submitter', 'h.submitter', $ownerSql) . ' GROUP BY m');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['hist_sheets'] = (float) $r['q'];
            }
        }
        $st = $pdo->prepare("SELECT substr(i.invoice_date,1,7) m, SUM(i.total) v, COUNT(*) c FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                              WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ?" . str_replace('submitter', 'o.submitter', $ownerSql) . ' GROUP BY m');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['live'] = (float) $r['v'];
                $months[$r['m']]['live_docs'] = (int) $r['c'];
            }
        }
        $st = $pdo->prepare("SELECT substr(o.wh_date,1,7) m, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
                              WHERE o.status = 'approved' AND COALESCE(o.wh_date,'') <> ''
                                AND NOT EXISTS (SELECT 1 FROM invoices vi WHERE vi.order_id = o.id AND vi.voided_at IS NOT NULL)
                                AND date(o.wh_date) BETWEEN ? AND ?" . str_replace('submitter', 'o.submitter', $ownerSql) . ' GROUP BY m');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            if (isset($months[$r['m']])) {
                $months[$r['m']]['live_sheets'] = (float) $r['q'];
            }
        }
        $total = ['hist' => 0.0, 'live' => 0.0, 'hist_docs' => 0, 'live_docs' => 0, 'hist_sheets' => 0.0, 'live_sheets' => 0.0];
        foreach ($months as $m) {
            foreach ($total as $k => $_) {
                $total[$k] += $m[$k];
            }
        }

        // By salesperson: history by submitter, live by order submitter.
        $people = [];
        $add = function (array &$rows, string $name, string $field, float $v): void {
            $name = trim($name) !== '' ? trim($name) : '—';
            $rows[$name] ??= ['name' => $name, 'hist' => 0.0, 'live' => 0.0, 'docs' => 0, 'sheets' => 0.0];
            $rows[$name][$field] += $v;
        };
        $st = $pdo->prepare('SELECT COALESCE(submitter, salesperson, \'\') s, SUM(amount) v, COUNT(*) c
                              FROM sales_history WHERE doc_date BETWEEN ? AND ?' . $ownerSql . ' GROUP BY s');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $add($people, (string) $r['s'], 'hist', (float) $r['v']);
            $add($people, (string) $r['s'], 'docs', (float) $r['c']);
        }
        $st = $pdo->prepare('SELECT COALESCE(h.submitter, h.salesperson, \'\') s, SUM(i.qty) q FROM sales_history_items i
                              JOIN sales_history h ON h.id = i.history_id WHERE h.doc_date BETWEEN ? AND ?' . str_replace('submitter', 'h.submitter', $ownerSql) . ' GROUP BY s');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $add($people, (string) $r['s'], 'sheets', (float) $r['q']);
        }
        $st = $pdo->prepare('SELECT COALESCE(o.submitter, \'\') s, SUM(i.total) v, COUNT(*) c FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                              WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ?' . str_replace('submitter', 'o.submitter', $ownerSql) . ' GROUP BY s');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $add($people, (string) $r['s'], 'live', (float) $r['v']);
            $add($people, (string) $r['s'], 'docs', (float) $r['c']);
        }
        $st = $pdo->prepare("SELECT COALESCE(o.submitter,'') s, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id
                              WHERE o.status = 'approved' AND COALESCE(o.wh_date,'') <> ''
                                AND NOT EXISTS (SELECT 1 FROM invoices vi WHERE vi.order_id = o.id AND vi.voided_at IS NOT NULL)
                                AND date(o.wh_date) BETWEEN ? AND ?" . str_replace('submitter', 'o.submitter', $ownerSql) . ' GROUP BY s');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $add($people, (string) $r['s'], 'sheets', (float) $r['q']);
        }
        $people = array_values($people);
        foreach ($people as &$p) {
            $p['total'] = $p['hist'] + $p['live'];
            $p['docs'] = (int) $p['docs'];
        }
        unset($p);
        usort($people, fn($a, $b) => $b['total'] <=> $a['total']);

        // By customer: linked customers merge both sources; unlinked history keeps its name.
        $customers = [];
        $addC = function (?int $id, string $name, string $field, float $v) use (&$customers): void {
            $key = $id !== null ? 'c' . $id : 'n' . mb_strtolower(trim($name));
            $customers[$key] ??= ['id' => $id, 'name' => trim($name), 'hist' => 0.0, 'live' => 0.0, 'docs' => 0];
            $customers[$key][$field] += $v;
        };
        $st = $pdo->prepare('SELECT customer_id, customer_name, SUM(amount) v, COUNT(*) c FROM sales_history
                              WHERE doc_date BETWEEN ? AND ?' . $ownerSql . ' GROUP BY customer_id, CASE WHEN customer_id IS NULL THEN customer_name ELSE \'\' END');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $id = $r['customer_id'] !== null ? (int) $r['customer_id'] : null;
            $addC($id, (string) $r['customer_name'], 'hist', (float) $r['v']);
            $addC($id, (string) $r['customer_name'], 'docs', (float) $r['c']);
        }
        $st = $pdo->prepare('SELECT o.customer_id, COALESCE(i.customer, o.customer_name, \'\') n, SUM(i.total) v, COUNT(*) c
                               FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                              WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ?' . str_replace('submitter', 'o.submitter', $ownerSql) . '
                              GROUP BY o.customer_id, CASE WHEN o.customer_id IS NULL THEN n ELSE \'\' END');
        $st->execute(array_merge([$from, $to], $ownerArg));
        foreach ($st as $r) {
            $id = $r['customer_id'] !== null ? (int) $r['customer_id'] : null;
            $addC($id, (string) $r['n'], 'live', (float) $r['v']);
            $addC($id, (string) $r['n'], 'docs', (float) $r['c']);
        }
        // Prefer the platform's own customer name over the old app's spelling.
        $ids = array_values(array_filter(array_map(fn($c) => $c['id'], $customers)));
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, name FROM customers WHERE id IN ($in)");
            $st->execute($ids);
            $names = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($customers as &$c) {
                if ($c['id'] !== null && isset($names[$c['id']])) {
                    $c['name'] = (string) $names[$c['id']];
                }
            }
            unset($c);
        }
        $customers = array_values($customers);
        foreach ($customers as &$c) {
            $c['total'] = $c['hist'] + $c['live'];
            $c['docs'] = (int) $c['docs'];
        }
        unset($c);
        usort($customers, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'year' => $year, 'months' => $months, 'total' => $total + ['all' => $total['hist'] + $total['live']],
            'people' => $people, 'customers' => $customers,
        ];
    }
}
