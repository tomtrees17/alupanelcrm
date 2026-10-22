<?php
declare(strict_types=1);

/**
 * Bank statements and collection matching (FA-AI-01).
 *
 * Finance uploads a KlikBCA (BCA Giro, the operating account) or ICBC
 * (payroll / tax account) CSV export. Every line is stored once (hash),
 * tagged by rule, and numbered like the old Buku Bank (BCA-IN-2610-0001).
 * Only BCA incoming lines are customer money: they are matched to open
 * invoices by rules first (invoice / CO number in the transfer note, a known
 * payer name, a unique amount), and by the model only when finance asks.
 *
 * Nothing reaches the books until a person confirms. Confirming writes the
 * transfer into the existing payments ledger (so AR, the dashboard and the
 * call lists see the new balance with no change of their own), records the
 * split in payment_allocations, and learns the payer name for next time.
 * The model only picks invoices; every amount is computed here.
 */
final class Bank
{
    /** 货款 / 平台代收 / 工资 / 税款 / 内部转账 / 利息 / 费用 / 社保 / 待分类 */
    public const TAGS = ['goods', 'platform', 'salary', 'tax', 'internal', 'interest', 'fee', 'social', 'other'];
    /** 未匹配 / 规则匹配 / AI 建议 / 已确认 / 非货款 / 不参与匹配 (ICBC, outgoing) */
    public const MATCH = ['unmatched', 'rule', 'ai', 'confirmed', 'non_ar', 'na'];
    /** Why an incoming line is not customer money. */
    public const NON_AR = ['capital', 'refund', 'interest', 'internal', 'other'];
    /** What the difference between the transfer and the invoices is. */
    public const DIFF = ['none', 'partial', 'overpaid', 'fee', 'withholding', 'rounding', 'other'];
    /** Shortfalls that close the invoice (a non-cash settlement row covers the gap). */
    public const CLOSING = ['fee', 'withholding', 'rounding', 'other'];
    /** Platforms that collect on a buyer's behalf: the payer name says nothing about the customer. */
    public const PLATFORMS = ['DOMPET ANAK BANGSA', 'ESPAY', 'SHOPEE'];
    /** Words of a transfer note that are never part of the payer's name. */
    private const NOTE_WORDS = ['DP', 'LUNAS', 'PELUNASAN', 'PAYMENT', 'PEMBAYARAN', 'BAYAR', 'INV', 'INVOICE', 'ACP', 'SISA', 'CO',
                                'FEE', 'TF', 'TRF', 'TRANSFER', 'KELEBIHAN', 'CICILAN', 'TAHAP', 'TERMIN', 'UANG', 'MUKA'];

    public static function ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, bank TEXT NOT NULL, last4 TEXT NOT NULL, currency TEXT NOT NULL DEFAULT 'IDR',
            parser TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), UNIQUE (parser, last4))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL REFERENCES bank_accounts(id), file_name TEXT, period TEXT,
            rows_total INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0, skipped INTEGER NOT NULL DEFAULT 0, pending INTEGER NOT NULL DEFAULT 0,
            credit_sum REAL NOT NULL DEFAULT 0, credit_n INTEGER NOT NULL DEFAULT 0, debit_sum REAL NOT NULL DEFAULT 0, debit_n INTEGER NOT NULL DEFAULT 0,
            file_credit REAL, file_credit_n INTEGER, file_debit REAL, file_debit_n INTEGER,
            totals_ok INTEGER, balance_ok INTEGER, rule_matched INTEGER NOT NULL DEFAULT 0,
            imported_by TEXT, imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL REFERENCES bank_accounts(id), import_id INTEGER,
            trx_date TEXT NOT NULL, amount REAL NOT NULL, direction TEXT NOT NULL, description TEXT NOT NULL, branch TEXT, balance REAL,
            counterparty_name TEXT, refs TEXT, hash TEXT NOT NULL UNIQUE, trx_code TEXT, tag TEXT NOT NULL DEFAULT 'other',
            match_status TEXT NOT NULL DEFAULT 'na', match_note TEXT, proposal TEXT,
            ai_suggestion_id INTEGER, ai_confidence TEXT, ai_reason TEXT, ai_status TEXT,
            non_ar_reason TEXT, confirmed_by TEXT, confirmed_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bank_trx_date ON bank_transactions(account_id, trx_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bank_trx_status ON bank_transactions(match_status, trx_date)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bank_trx_code ON bank_transactions(trx_code)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS payment_allocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER NOT NULL REFERENCES bank_transactions(id),
            invoice_id INTEGER NOT NULL REFERENCES invoices(id), amount REAL NOT NULL,
            diff_amount REAL NOT NULL DEFAULT 0, diff_reason TEXT NOT NULL DEFAULT 'none',
            payment_id INTEGER REFERENCES payments(id), settle_payment_id INTEGER REFERENCES payments(id),
            refund_transaction_id INTEGER REFERENCES bank_transactions(id),
            bukti_potong_at TEXT, created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alloc_trx ON payment_allocations(transaction_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alloc_invoice ON payment_allocations(invoice_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_payer_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL REFERENCES customers(id),
            payer_name TEXT NOT NULL, payer_key TEXT NOT NULL, source TEXT NOT NULL, confirmed_by TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')), UNIQUE (payer_key, customer_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_book (
            id INTEGER PRIMARY KEY AUTOINCREMENT, trx_code TEXT NOT NULL UNIQUE, trx_date TEXT NOT NULL, description TEXT NOT NULL,
            amount REAL NOT NULL, invoice_raw TEXT, invoice_ids TEXT, customer_id INTEGER, payer_name TEXT,
            batch TEXT, imported_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pcols = array_column($pdo->query('PRAGMA table_info(payments)')->fetchAll(), 'name');
        if (!in_array('noncash', $pcols, true)) {
            // 1 = a settlement (customer's withholding tax, bank fee, rounding): closes the invoice, is not cash in.
            $pdo->exec('ALTER TABLE payments ADD COLUMN noncash INTEGER NOT NULL DEFAULT 0');
        }
    }

    /** @return array{tolerance:float,candidate_days:int,max_candidates:int,ai_batch:int} */
    public static function settings(array $config): array
    {
        $b = (array) ($config['bank'] ?? []);
        return [
            'tolerance'      => (float) ($b['tolerance'] ?? 1000),
            'candidate_days' => (int) ($b['candidate_days'] ?? 120),
            'max_candidates' => (int) ($b['max_candidates'] ?? 30),
            'ai_batch'       => (int) ($b['ai_batch'] ?? 15),
        ];
    }

    // ───────────────────────────── parsing ─────────────────────────────

    /** 'bca_csv' | 'icbc_csv' | '' from the first line of the export. */
    public static function detect(string $content): string
    {
        $first = ltrim(strtok(self::strip_bom($content), "\n") ?: '', "\" \t");
        if (str_starts_with($first, 'Informasi Rekening')) {
            return 'bca_csv';
        }
        if (str_starts_with($first, 'Branch Name:')) {
            return 'icbc_csv';
        }
        return '';
    }

    /**
     * KlikBCA Bisnis "Mutasi Rekening" CSV (spec: task card 0.2). PEND lines
     * are not booked yet and are skipped: the next export carries them dated.
     * @return array{last4:string,period:string,rows:list<array>,pending:int,summary:array,errors:list<string>}
     */
    public static function parse_bca(string $content): array
    {
        $out = ['last4' => '', 'period' => '', 'rows' => [], 'pending' => 0, 'summary' => [], 'errors' => []];
        $inRows = false;
        foreach (preg_split('/\r\n|\n|\r/', self::strip_bom($content)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = array_map(fn($v) => trim((string) $v), str_getcsv($line, ',', '"', ''));
            $first = $f[0] ?? '';
            if (preg_match('/^No\.\s*rekening\s*:\s*([\d\s-]+)/i', $first, $m)) {
                $out['last4'] = substr(preg_replace('/\D/', '', $m[1]) ?? '', -4);
                continue;
            }
            if (preg_match('/^Periode\s*:\s*(.+)$/i', $first, $m)) {
                $out['period'] = trim($m[1]);
                continue;
            }
            if ($first === 'Tanggal Transaksi') {
                $inRows = true;
                continue;
            }
            if (preg_match('/^(Saldo Awal|Mutasi Debet|Mutasi Kredit|Saldo Akhir)\s*:\s*(.+)$/i', $first, $m)) {
                $inRows = false;
                $key = ['saldo awal' => 'opening', 'mutasi debet' => 'debit', 'mutasi kredit' => 'credit', 'saldo akhir' => 'closing'][strtolower($m[1])];
                $out['summary'][$key] = self::num_us($m[2]);
                if (isset($f[1]) && $f[1] !== '' && in_array($key, ['debit', 'credit'], true)) {
                    $out['summary'][$key . '_n'] = (int) $f[1];
                }
                continue;
            }
            if (!$inRows) {
                continue;
            }
            if (strtoupper($first) === 'PEND') {
                $out['pending']++;
                continue;
            }
            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $first, $d) || !preg_match('/^([\d,.]+)\s*(CR|DB)$/i', $f[3] ?? '', $a)) {
                $out['errors'][] = 'unreadable line: ' . mb_substr($line, 0, 80);
                continue;
            }
            $desc = rtrim($f[1] ?? '');
            $out['rows'][] = [
                'date'        => "{$d[3]}-{$d[2]}-{$d[1]}",
                'description' => $desc,
                'branch'      => $f[2] ?? '',
                'amount'      => self::num_us($a[1]),
                'direction'   => strtoupper($a[2]) === 'CR' ? 'in' : 'out',
                'balance'     => self::num_us($f[4] ?? '0'),
                'hash'        => hash('sha256', implode('|', ['bca', $first, $f[1] ?? '', $f[3] ?? '', $f[4] ?? ''])),
            ];
        }
        return $out;
    }

    /**
     * ICBC Indonesia "current account history" (tab-separated, every value
     * quoted with a trailing tab inside the quotes; spec: task card 0.3).
     * The counterpart account is read only to spot transfers between our own
     * accounts; it is never stored.
     * @return array{last4:string,period:string,rows:list<array>,pending:int,summary:array,errors:list<string>}
     */
    public static function parse_icbc(string $content, array $ownLast4 = []): array
    {
        $out = ['last4' => '', 'period' => '', 'rows' => [], 'pending' => 0, 'summary' => [], 'errors' => []];
        $idx = null;
        foreach (preg_split('/\r\n|\n|\r/', self::strip_bom($content)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $f = array_map(fn($v) => trim((string) $v, " \t\""), str_getcsv($line, "\t", '"', ''));
            $first = $f[0] ?? '';
            if ($first === 'Account:') {
                $out['last4'] = substr(preg_replace('/\D/', '', explode(' ', $f[1] ?? '')[0]) ?? '', -4);
                continue;
            }
            if ($first === 'Date:') {
                $out['period'] = $f[1] ?? '';
                continue;
            }
            if ($first === 'Seq.') {
                $idx = array_flip($f);
                continue;
            }
            if ($idx === null) {
                continue;
            }
            $col = fn(string $name): string => (string) ($f[$idx[$name] ?? -1] ?? '');
            if ($first === 'Total') {
                $out['summary']['credit'] = self::num_id($col('Credit Amount'));
                $out['summary']['debit'] = self::num_id($col('Debit Amount'));
                $idx = null;
                continue;
            }
            if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})/', $col('Transaction Time'), $d)) {
                $out['errors'][] = 'unreadable line: ' . mb_substr($line, 0, 80);
                continue;
            }
            $cr = self::num_id($col('Credit Amount'));
            $db = self::num_id($col('Debit Amount'));
            $summary = $col('Summary');
            $receiver = $col('Receiver');
            $counter = substr(preg_replace('/\D/', '', $col('Counterpart Account')) ?? '', -4);
            $out['rows'][] = [
                'date'        => "{$d[3]}-{$d[2]}-{$d[1]}",
                'description' => $summary,
                'branch'      => $col('Business Type'),
                'amount'      => $cr > 0 ? $cr : $db,
                'direction'   => $cr > 0 ? 'in' : 'out',
                'balance'     => self::num_id($col('Balance')),
                'receiver'    => $receiver,
                'own_counterpart' => $counter !== '' && in_array($counter, $ownLast4, true),
                'hash'        => hash('sha256', implode('|', ['icbc', $col('Transaction Time'), $summary, $col('Credit Amount'), $col('Debit Amount'), $col('Balance')])),
            ];
        }
        return $out;
    }

    /** "16,095,000.00" (BCA, US style) → float. */
    public static function num_us(string $s): float
    {
        return (float) str_replace(',', '', trim($s));
    }

    /** "1.005.321,88" (ICBC, Indonesian style) → float. */
    public static function num_id(string $s): float
    {
        $s = trim($s);
        return $s === '' ? 0.0 : (float) str_replace(['.', ','], ['', '.'], $s);
    }

    private static function strip_bom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    /**
     * Does the file add up? Count and sum of incoming / outgoing lines against
     * the file's own footer, and the running balance line by line.
     * @return array{credit:float,credit_n:int,debit:float,debit_n:int,totals_ok:?bool,balance_ok:bool,notes:list<string>}
     */
    public static function check(array $parsed, string $parser): array
    {
        $r = ['credit' => 0.0, 'credit_n' => 0, 'debit' => 0.0, 'debit_n' => 0, 'totals_ok' => null, 'balance_ok' => true, 'notes' => []];
        $prev = null;
        foreach ($parsed['rows'] as $i => $row) {
            $signed = $row['direction'] === 'in' ? $row['amount'] : -$row['amount'];
            $r[$row['direction'] === 'in' ? 'credit' : 'debit'] += $row['amount'];
            $r[$row['direction'] === 'in' ? 'credit_n' : 'debit_n']++;
            $before = $prev ?? ($i === 0 && isset($parsed['summary']['opening']) ? $parsed['summary']['opening'] : null);
            if ($before !== null && abs($before + $signed - $row['balance']) > 0.005) {
                $r['balance_ok'] = false;
                $r['notes'][] = "balance breaks at {$row['date']} " . mb_substr($row['description'], 0, 40);
            }
            $prev = $row['balance'];
        }
        if ($prev !== null && isset($parsed['summary']['closing']) && abs($prev - $parsed['summary']['closing']) > 0.005) {
            $r['balance_ok'] = false;
            $r['notes'][] = 'last balance differs from Saldo Akhir';
        }
        $s = $parsed['summary'];
        if (isset($s['credit'])) {
            $ok = abs($s['credit'] - $r['credit']) < 0.005 && (!isset($s['credit_n']) || $s['credit_n'] === $r['credit_n']);
            if (isset($s['debit'])) {
                $ok = $ok && abs($s['debit'] - $r['debit']) < 0.005 && (!isset($s['debit_n']) || $s['debit_n'] === $r['debit_n']);
            }
            $r['totals_ok'] = $ok;
        }
        $r['credit'] = round($r['credit'], 2);
        $r['debit'] = round($r['debit'], 2);
        return $r;
    }

    // ───────────────────────────── reading a line ─────────────────────────────

    /**
     * Payer name and the customer's own note from a BCA description. The
     * payer is always last and cut to ~18 characters by the bank.
     * @return array{payer:string,note:string,platform:bool}
     */
    public static function bca_payer(string $desc): array
    {
        $d = trim(preg_replace('/\s+$/', '', $desc) ?? '');
        $up = strtoupper($d);
        foreach (self::PLATFORMS as $p) {
            if (str_contains($up, $p)) {
                return ['payer' => $p, 'note' => '', 'platform' => true];
            }
        }
        if (preg_match('/^TRSF E-BANKING (?:CR|DB) \S+\s+[\d.,]+\s+(.*)$/', $d, $m)) {
            $tail = trim($m[1]);
            $parts = array_values(array_filter(preg_split('/\s{2,}/', $tail) ?: [], fn($x) => trim($x) !== ''));
            if (count($parts) >= 2) {
                return ['payer' => trim((string) array_pop($parts)), 'note' => trim(implode(' ', $parts)), 'platform' => false];
            }
            // One run: the payer is the trailing block of upper-case words that are not note words.
            $tokens = preg_split('/\s+/', $tail) ?: [];
            $payer = [];
            while ($tokens) {
                $t = (string) end($tokens);
                if (!preg_match("/^[A-Z][A-Z.&'-]*$/", $t) || in_array(rtrim($t, '.'), self::NOTE_WORDS, true)) {
                    break;
                }
                array_unshift($payer, array_pop($tokens));
            }
            return ['payer' => implode(' ', $payer), 'note' => implode(' ', $tokens), 'platform' => false];
        }
        if (preg_match('/^BI-FAST CR TRANSFER\s+DR\s+\d{3}\s+(.+)$/', $d, $m)) {
            return ['payer' => trim($m[1]), 'note' => '', 'platform' => false];
        }
        if (preg_match('/^SWITCHING CR (?:TRF|TRANSFER)\s+(.+?)\s{2,}\d{3}\b/', $d, $m)) {
            return ['payer' => trim($m[1]), 'note' => '', 'platform' => false];
        }
        if (preg_match('/^SETORAN (?:VIA CDM|TUNAI)\b(.*)$/', $d, $m)) {
            $rest = trim(preg_replace(['#^\d{2}/\d{2}#', '/WSID:\S+/'], '', trim($m[1])) ?? '');
            return ['payer' => $rest, 'note' => '', 'platform' => false];
        }
        return ['payer' => '', 'note' => '', 'platform' => false];
    }

    /**
     * Invoice / CO references in a transfer note: "1306/AMI-CO/10/26",
     * "1229/AMICO/09/26", "1301 - AMI - INV - 10 - 26", "1302,1303,1309-AMI",
     * "CO 1222", "Inv 1309".
     * @return list<array{serial:int,mm:?int,yy:?int,kind:string}> kind: co (an order number) | inv | any
     */
    public static function refs(string $text): array
    {
        $out = [];
        $add = function (int $serial, ?int $mm, ?int $yy, string $kind) use (&$out): void {
            foreach ($out as $r) {
                if ($r['serial'] === $serial) {
                    return;
                }
            }
            $out[] = ['serial' => $serial, 'mm' => $mm, 'yy' => $yy, 'kind' => $kind];
        };
        $rest = $text;
        if (preg_match_all('#(\d{1,4})\s*/\s*AMI\s*-?\s*CO\s*/\s*(\d{1,2})\s*/\s*(\d{2})#i', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $add((int) $x[1], (int) $x[2], (int) $x[3], 'co');
            }
            $rest = preg_replace('#(\d{1,4})\s*/\s*AMI\s*-?\s*CO\s*/\s*(\d{1,2})\s*/\s*(\d{2})#i', ' ', $rest) ?? '';
        }
        if (preg_match_all('/(\d{1,4})\s*-\s*AMI\s*-\s*(?:INV\s*-\s*)?(\d{1,2})\s*-\s*(\d{2})\b/i', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $add((int) $x[1], (int) $x[2], (int) $x[3], 'inv');
            }
            $rest = preg_replace('/(\d{1,4})\s*-\s*AMI\s*-\s*(?:INV\s*-\s*)?(\d{1,2})\s*-\s*(\d{2})\b/i', ' ', $rest) ?? '';
        }
        if (preg_match_all('/((?:\d{3,4}\s*[,&]\s*)*\d{3,4})\s*-?\s*AMI\b/i', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                foreach (preg_split('/\s*[,&]\s*/', $x[1]) ?: [] as $n) {
                    $add((int) $n, null, null, 'any');
                }
            }
        }
        if (preg_match_all('/\b(?:CO|INV(?:OICE)?)\s*\.?\s*(\d{3,4})\b/i', $rest, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $add((int) $x[1], null, null, 'any');
            }
        }
        return $out;
    }

    /** Tag a line by rule. @param array $row a parsed row (+ payer for BCA) */
    public static function tag(string $parser, array $row): string
    {
        $up = strtoupper($row['description']);
        if ($parser === 'icbc_csv') {
            $rcv = strtoupper((string) ($row['receiver'] ?? ''));
            return match (true) {
                str_contains($up, 'SALARY')                                                    => 'salary',
                str_contains($rcv, 'PENERIMAAN NEGARA') || str_contains($rcv, 'PAJAK') || str_starts_with($up, 'PAJAK') => 'tax',
                str_contains($up, 'PINDAH DANA') || !empty($row['own_counterpart'])            => 'internal',
                str_contains($up, 'BUNGA')                                                     => 'interest',
                str_contains($up, 'BPJS') || str_contains($rcv, 'BPJS')                        => 'social',
                default                                                                        => 'other',
            };
        }
        if ($row['direction'] === 'in') {
            if (!empty($row['platform'])) {
                return 'platform';
            }
            return str_contains($up, 'BUNGA') ? 'interest' : 'goods';
        }
        if (preg_match('/^(BIAYA ADM|BA JASA|BIAYA TRANSFER)|BIAYA TRANSFER|DELIV FEE|PAJAK BUNGA/', $up)) {
            return 'fee';
        }
        if (str_contains($up, 'BPJS')) {
            return 'social';
        }
        return 'other';
    }

    /** The note as the model sees it: bank-internal codes and long ids (accounts, wallet ids) removed. */
    public static function clean_for_ai(string $desc): string
    {
        $s = preg_replace(['#\b\d{4}/[A-Z]{5}/[A-Z0-9]+\b#', '/WSID:\S+/', '/\b(?:ID)?\d{10,}\b/', '/\s+/'], ['', '', '', ' '], $desc) ?? $desc;
        return trim($s);
    }

    /** Payer name as a lookup key: upper case, punctuation and spacing folded. */
    public static function payer_key(string $name): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($name)) ?? '');
    }

    /** 0–1: how alike a payer and a customer name are, legal forms and shop words ignored. */
    public static function name_similarity(string $a, string $b): float
    {
        $n = fn(string $s): string => trim(preg_replace('/\b(PT|CV|UD|TB|TOKO|PD|FA)\b/', ' ', self::payer_key($s)) ?? '');
        $a = preg_replace('/\s+/', ' ', $n($a)) ?? '';
        $b = preg_replace('/\s+/', ' ', $n($b)) ?? '';
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b || (min(strlen($a), strlen($b)) >= 5 && (str_contains($a, $b) || str_contains($b, $a)))) {
            return 1.0;
        }
        similar_text($a, $b, $pct);
        return round($pct / 100, 3);
    }

    // ───────────────────────────── import ─────────────────────────────

    /**
     * Store one export. Lines already stored are skipped (same hash), so the
     * same file — or overlapping periods — can be uploaded again safely.
     * @return array{ok:bool,error:string,parser:string,account_id:int,import_id:int,added:int,skipped:int,pending:int,rule_matched:int,check:array,last4:string,period:string}
     */
    public static function import(PDO $pdo, array $config, string $content, string $fileName, string $by): array
    {
        $parser = self::detect($content);
        $fail = fn(string $e): array => ['ok' => false, 'error' => $e, 'parser' => $parser, 'account_id' => 0, 'import_id' => 0, 'added' => 0, 'skipped' => 0,
                                         'pending' => 0, 'rule_matched' => 0, 'check' => [], 'last4' => '', 'period' => ''];
        if ($parser === '') {
            return $fail('unknown_format');
        }
        $own = $pdo->query("SELECT last4 FROM bank_accounts")->fetchAll(PDO::FETCH_COLUMN);
        $parsed = $parser === 'bca_csv' ? self::parse_bca($content) : self::parse_icbc($content, $own);
        if ($parsed['last4'] === '' || ($parsed['rows'] === [] && $parsed['pending'] === 0)) {
            return $fail('empty_file');
        }
        $bank = $parser === 'bca_csv' ? 'BCA' : 'ICBC';
        $pdo->prepare('INSERT OR IGNORE INTO bank_accounts (bank, last4, parser) VALUES (?,?,?)')->execute([$bank, $parsed['last4'], $parser]);
        $st = $pdo->prepare('SELECT id FROM bank_accounts WHERE parser = ? AND last4 = ?');
        $st->execute([$parser, $parsed['last4']]);
        $accountId = (int) $st->fetchColumn();
        $check = self::check($parsed, $parser);

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO bank_imports (account_id, file_name, period, rows_total, pending, credit_sum, credit_n, debit_sum, debit_n,
                                                 file_credit, file_credit_n, file_debit, file_debit_n, totals_ok, balance_ok, imported_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$accountId, mb_substr($fileName, 0, 200), $parsed['period'], count($parsed['rows']), $parsed['pending'],
                       $check['credit'], $check['credit_n'], $check['debit'], $check['debit_n'],
                       $parsed['summary']['credit'] ?? null, $parsed['summary']['credit_n'] ?? null,
                       $parsed['summary']['debit'] ?? null, $parsed['summary']['debit_n'] ?? null,
                       $check['totals_ok'] === null ? null : (int) $check['totals_ok'], (int) $check['balance_ok'], $by]);
        $importId = (int) $pdo->lastInsertId();
        $has = $pdo->prepare('SELECT 1 FROM bank_transactions WHERE hash = ?');
        $ins = $pdo->prepare('INSERT INTO bank_transactions (account_id, import_id, trx_date, amount, direction, description, branch, balance,
                                                             counterparty_name, refs, hash, trx_code, tag, match_status)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $added = $skipped = 0;
        $new = [];
        foreach ($parsed['rows'] as $row) {
            $has->execute([$row['hash']]);
            if ($has->fetchColumn()) {
                $skipped++;
                continue;
            }
            $payer = '';
            $refs = [];
            if ($parser === 'bca_csv') {
                $p = self::bca_payer($row['description']);
                $row['platform'] = $p['platform'];
                $payer = $p['payer'];
                $refs = $row['direction'] === 'in' ? self::refs($row['description']) : [];
            } else {
                $payer = (string) ($row['receiver'] ?? '');
            }
            $tag = self::tag($parser, $row);
            $status = 'na';
            if ($parser === 'bca_csv' && $row['direction'] === 'in') {
                $status = $tag === 'interest' ? 'non_ar' : 'unmatched';
            }
            $ins->execute([$accountId, $importId, $row['date'], round($row['amount'], 2), $row['direction'], $row['description'], $row['branch'],
                           $row['balance'], $payer !== '' ? $payer : null, $refs ? json_encode($refs) : null, $row['hash'],
                           self::next_code($pdo, $bank, $row['direction'], $row['date']), $tag, $status]);
            $added++;
            if ($status === 'unmatched') {
                $new[] = (int) $pdo->lastInsertId();
            }
        }
        $pdo->commit();

        $matched = 0;
        foreach ($new as $id) {
            if (self::rule_match($pdo, $config, $id)) {
                $matched++;
            }
        }
        $pdo->prepare('UPDATE bank_imports SET added = ?, skipped = ?, rule_matched = ? WHERE id = ?')->execute([$added, $skipped, $matched, $importId]);
        return ['ok' => true, 'error' => '', 'parser' => $parser, 'account_id' => $accountId, 'import_id' => $importId, 'added' => $added, 'skipped' => $skipped,
                'pending' => $parsed['pending'], 'rule_matched' => $matched, 'check' => $check, 'last4' => $parsed['last4'], 'period' => $parsed['period']];
    }

    /**
     * The next number in the Buku Bank series, BCA-IN-2610-0001: per bank,
     * direction and month of the transaction, continuing what is stored.
     */
    private static function next_code(PDO $pdo, string $bank, string $direction, string $date): string
    {
        $prefix = sprintf('%s-%s-%s-', $bank, $direction === 'in' ? 'IN' : 'OUT', substr($date, 2, 2) . substr($date, 5, 2));
        $st = $pdo->prepare('SELECT MAX(CAST(substr(trx_code, ?) AS INTEGER)) FROM bank_transactions WHERE trx_code LIKE ?');
        $st->execute([strlen($prefix) + 1, $prefix . '%']);
        return $prefix . str_pad((string) ((int) $st->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
    }

    // ───────────────────────────── open invoices ─────────────────────────────

    /**
     * Invoices with money still owed. $asOf (benchmark replays) = balances as
     * they were on that day: only invoices issued by then, payments before it.
     * @return array<int,array> by invoice id
     */
    public static function open_invoices(PDO $pdo, ?string $asOf = null): array
    {
        $sql = 'SELECT i.id, i.invoice_no, i.customer, i.bill_to_name, i.invoice_date, i.total, i.amount_paid,
                       o.order_no, o.customer_id, o.submitter, c.name AS cname, c.company AS ccompany
                  FROM invoices i LEFT JOIN orders o ON o.id = i.order_id LEFT JOIN customers c ON c.id = o.customer_id
                 WHERE i.voided_at IS NULL';
        $rows = $pdo->query($sql . ' ORDER BY i.invoice_date, i.id')->fetchAll();
        $paidBefore = [];
        if ($asOf !== null) {
            $st = $pdo->prepare("SELECT invoice_id, SUM(amount) s FROM payments WHERE COALESCE(NULLIF(pay_date,''), date(created_at)) < ? GROUP BY invoice_id");
            $st->execute([$asOf]);
            $paidBefore = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $out = [];
        foreach ($rows as $r) {
            if ($asOf !== null && (string) $r['invoice_date'] > $asOf) {
                continue;
            }
            $paid = $asOf !== null ? (float) ($paidBefore[$r['id']] ?? 0) : (float) $r['amount_paid'];
            $bal = round((float) $r['total'] - $paid, 2);
            if ($bal <= 0.005) {
                continue;
            }
            $r['balance'] = $bal;
            preg_match('/^(\d+)\s*-\s*AMI\s*-\s*(?:INV\s*-\s*)?(\d{1,2})\s*-\s*(\d{2})/i', (string) $r['invoice_no'], $mi);
            preg_match('#^(\d+)\s*/\s*AMI-CO\s*/\s*(\d{1,2})\s*/\s*(\d{2})#i', (string) $r['order_no'], $mo);
            $r['inv_ref'] = $mi ? [(int) $mi[1], (int) $mi[2], (int) $mi[3]] : null;
            $r['co_ref'] = $mo ? [(int) $mo[1], (int) $mo[2], (int) $mo[3]] : null;
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /** Open invoices a reference could mean (by invoice serial and / or order serial). @return list<int> */
    private static function ref_candidates(array $ref, array $open): array
    {
        $hit = [];
        $fits = fn(?array $r): bool => $r !== null && $r[0] === $ref['serial']
            && ($ref['mm'] === null || ($r[1] === $ref['mm'] && $r[2] === $ref['yy']));
        foreach ($open as $id => $inv) {
            if (($ref['kind'] !== 'inv' && $fits($inv['co_ref'])) || ($ref['kind'] !== 'co' && $fits($inv['inv_ref']))) {
                $hit[] = $id;
            }
        }
        return $hit;
    }

    // ───────────────────────────── allocation ─────────────────────────────

    /**
     * Split a transfer over invoices (in the given order). Amounts are rules,
     * never the model: each invoice gets its balance, the last one takes what
     * is left over (overpayment) or short.
     * @param list<array> $invoices rows with id, balance
     * @return array{lines:list<array{invoice_id:int,amount:float}>,diff_amount:float,diff_reason:string}
     */
    public static function allocate(float $amount, array $invoices, string $hint, float $tol): array
    {
        $owed = round(array_sum(array_column($invoices, 'balance')), 2);
        $diff = round($amount - $owed, 2);
        $lines = [];
        if ($diff >= -$tol) {
            foreach ($invoices as $inv) {
                $lines[] = ['invoice_id' => (int) $inv['id'], 'amount' => (float) $inv['balance']];
            }
            if ($lines) {
                $lines[count($lines) - 1]['amount'] = round($lines[count($lines) - 1]['amount'] + $diff, 2);
            }
            $reason = abs($diff) < 0.005 ? 'none' : ($diff > 0 ? 'overpaid' : (in_array($hint, ['fee', 'withholding'], true) ? $hint : 'rounding'));
        } else {
            $left = $amount;
            foreach ($invoices as $inv) {
                if ($left <= 0.005) {
                    break;
                }
                $take = round(min($left, (float) $inv['balance']), 2);
                $lines[] = ['invoice_id' => (int) $inv['id'], 'amount' => $take];
                $left = round($left - $take, 2);
            }
            $reason = in_array($hint, ['fee', 'withholding'], true) ? $hint : 'partial';
        }
        return ['lines' => $lines, 'diff_amount' => $diff, 'diff_reason' => $reason];
    }

    // ───────────────────────────── rule matching ─────────────────────────────

    /**
     * The three rules of the card, in order. On a hit the line becomes
     * 「规则匹配」 with a proposal; nothing is booked.
     */
    public static function rule_match(PDO $pdo, array $config, int $trxId, ?array $open = null, ?callable $aliasLookup = null): bool
    {
        $t = self::find($pdo, $trxId);
        if ($t === null || $t['match_status'] !== 'unmatched') {
            return false;
        }
        $hit = self::rule_proposal($pdo, $config, $t, $open ?? self::open_invoices($pdo), $aliasLookup);
        if ($hit === null) {
            return false;
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'rule', match_note = ?, proposal = ? WHERE id = ? AND match_status = 'unmatched'")
            ->execute([$hit['note'], json_encode($hit['proposal'], JSON_PRESERVE_ZERO_FRACTION), $trxId]);
        return true;
    }

    /**
     * Run the rules again over every unmatched incoming line, e.g. after the
     * history import taught new payer names. Free: no model call.
     */
    public static function rematch(PDO $pdo, array $config): int
    {
        $open = self::open_invoices($pdo);
        $n = 0;
        foreach ($pdo->query("SELECT id FROM bank_transactions WHERE match_status = 'unmatched' AND direction = 'in' ORDER BY trx_date, id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (self::rule_match($pdo, $config, (int) $id, $open)) {
                $n++;
            }
        }
        return $n;
    }

    /** @return ?array{note:string,proposal:array} */
    public static function rule_proposal(PDO $pdo, array $config, array $t, array $open, ?callable $aliasLookup = null): ?array
    {
        $tol = self::settings($config)['tolerance'];
        $amount = (float) $t['amount'];
        $proposal = fn(array $invoices, string $source, string $hint = ''): array => ['source' => $source] + self::allocate($amount, $invoices, $hint, $tol);

        // 1) Invoice / CO numbers in the note, each pointing at exactly one open invoice.
        $refs = $t['refs'] ? (array) json_decode((string) $t['refs'], true) : [];
        if ($refs) {
            $picked = [];
            foreach ($refs as $ref) {
                $c = self::ref_candidates($ref, $open);
                if (count($c) !== 1 || in_array($c[0], $picked, true)) {
                    $picked = [];
                    break;
                }
                $picked[] = $c[0];
            }
            if ($picked) {
                $invs = array_map(fn($id) => $open[$id], $picked);
                $owed = array_sum(array_column($invs, 'balance'));
                if ($amount <= $owed + $tol) {
                    $nos = implode(', ', array_column($invs, 'invoice_no'));
                    return ['note' => "ref:{$nos}", 'proposal' => $proposal($invs, 'rule')];
                }
            }
        }
        if ($t['tag'] === 'platform') {
            return null;   // the payer is the platform, not the customer: rules 2 and 3 do not apply
        }
        $payer = (string) $t['counterparty_name'];
        $equal = array_filter($open, fn($inv) => abs((float) $inv['balance'] - $amount) <= $tol);

        // 2) A payer finance has confirmed before, and one open invoice of theirs with this balance.
        if ($payer !== '') {
            $customers = $aliasLookup ? $aliasLookup(self::payer_key($payer)) : self::alias_customers($pdo, self::payer_key($payer));
            if ($customers) {
                $mine = array_values(array_filter($equal, fn($inv) => in_array((int) $inv['customer_id'], $customers, true)));
                if (count($mine) === 1) {
                    return ['note' => 'alias:' . $payer, 'proposal' => $proposal($mine, 'rule')];
                }
            }
        }

        // 3) The amount fits exactly one open invoice in the whole system, and the names agree.
        if ($payer !== '' && count($equal) === 1) {
            $inv = array_values($equal)[0];
            $best = max(array_map(fn($n) => self::name_similarity($payer, (string) $n), [$inv['customer'], $inv['bill_to_name'], $inv['cname'], $inv['ccompany']]));
            if ($best >= 0.8) {
                return ['note' => 'amount+name:' . $inv['invoice_no'], 'proposal' => $proposal([$inv], 'rule')];
            }
        }
        return null;
    }

    /** @return list<int> customer ids this payer name is known for */
    public static function alias_customers(PDO $pdo, string $key): array
    {
        if ($key === '') {
            return [];
        }
        $st = $pdo->prepare('SELECT DISTINCT customer_id FROM customer_payer_aliases WHERE payer_key = ?');
        $st->execute([$key]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    // ───────────────────────────── AI matching ─────────────────────────────

    public static function ai_schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 6,
                                  'description' => 'Candidate ids this transfer pays, oldest first. Empty if none fits.'],
                'confidence'  => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                'diff_type'   => ['type' => 'string', 'enum' => ['exact', 'partial', 'combined', 'overpaid', 'fee', 'withholding', 'rounding', 'unknown']],
                'reason_id'   => ['type' => 'string', 'description' => 'One sentence in Indonesian.'],
                'reason_zh'   => ['type' => 'string', 'description' => 'One sentence in Chinese.'],
            ],
            'required' => ['invoice_ids', 'confidence', 'diff_type', 'reason_id', 'reason_zh'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Up to N open invoices worth showing the model for one transfer: recent
     * ones, ranked by how well amount, payer name and note fit.
     * @return list<array>
     */
    public static function candidates(PDO $pdo, array $config, array $t, array $open, ?callable $aliasLookup = null): array
    {
        $s = self::settings($config);
        $amount = (float) $t['amount'];
        $from = date('Y-m-d', strtotime($t['trx_date'] . ' -' . $s['candidate_days'] . ' days'));
        $to = date('Y-m-d', strtotime($t['trx_date'] . ' +7 days'));
        $payer = $t['tag'] === 'platform' ? '' : (string) $t['counterparty_name'];
        $aliasCust = $payer !== '' ? ($aliasLookup ? $aliasLookup(self::payer_key($payer)) : self::alias_customers($pdo, self::payer_key($payer))) : [];
        $refIds = [];
        foreach ($t['refs'] ? (array) json_decode((string) $t['refs'], true) : [] as $ref) {
            $refIds = array_merge($refIds, self::ref_candidates($ref, $open));
        }
        $scored = [];
        foreach ($open as $id => $inv) {
            $d = (string) $inv['invoice_date'];
            if (($d !== '' && ($d < $from || $d > $to)) && !in_array($id, $refIds, true)) {
                continue;
            }
            $bal = (float) $inv['balance'];
            $score = abs($bal - $amount) <= $s['tolerance'] ? 4.0 : ($bal > $amount ? 1.0 / (1 + ($bal - $amount) / max($amount, 1)) : 0.5);
            if ($payer !== '') {
                $score += 3 * max(array_map(fn($n) => self::name_similarity($payer, (string) $n), [$inv['customer'], $inv['bill_to_name'], $inv['cname'], $inv['ccompany']]));
            }
            if (in_array((int) $inv['customer_id'], $aliasCust, true)) {
                $score += 4;
            }
            if (in_array($id, $refIds, true)) {
                $score += 6;
            }
            $scored[$id] = $score;
        }
        arsort($scored);
        $out = [];
        foreach (array_slice(array_keys($scored), 0, $s['max_candidates']) as $id) {
            $inv = $open[$id];
            $out[] = $inv;
        }
        return $out;
    }

    /**
     * The user message for one transfer: only what matching needs (no account numbers).
     * $aliasNames (benchmark): fn(int $customerId): list<string> instead of the alias table.
     */
    public static function ai_content(PDO $pdo, array $t, array $cands, ?callable $aliasNames = null): string
    {
        $aliases = [];
        $ids = array_values(array_unique(array_filter(array_map(fn($c) => (int) $c['customer_id'], $cands))));
        if ($aliasNames !== null) {
            foreach ($ids as $cid) {
                $aliases[$cid] = $aliasNames($cid);
            }
        } elseif ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT customer_id, payer_name FROM customer_payer_aliases WHERE customer_id IN ({$in}) ORDER BY id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $a) {
                $aliases[(int) $a['customer_id']][] = (string) $a['payer_name'];
            }
        }
        $platform = $t['tag'] === 'platform';
        $payload = [
            'transfer' => [
                'date'   => $t['trx_date'],
                'amount' => (float) $t['amount'],
                'text'   => self::clean_for_ai((string) $t['description']),
                'payer'  => $platform ? null : $t['counterparty_name'],
                'note'   => $platform ? 'Collected by a payment platform: the payer name is not usable, match by amount.' : null,
            ],
            'candidates' => array_map(fn($c) => [
                'id'           => (int) $c['id'],
                'invoice_no'   => $c['invoice_no'],
                'order_no'     => $c['order_no'],
                'customer'     => trim((string) ($c['cname'] ?: $c['customer'])),
                'company'      => $c['ccompany'] ?: ($c['bill_to_name'] ?: null),
                'payer_names'  => array_slice($aliases[(int) $c['customer_id']] ?? [], 0, 5),
                'pic'          => $c['submitter'],
                'invoice_date' => $c['invoice_date'],
                'balance'      => (float) $c['balance'],
            ], $cands),
        ];
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ask the model about one unmatched incoming BCA line. Its answer (the
     * invoices) becomes a proposal with amounts split by allocate(); a failure
     * leaves the line for manual matching.
     * @return array{status:string,error:string}
     */
    public static function ai_match(PDO $pdo, array $config, int $trxId): array
    {
        $t = self::find($pdo, $trxId);
        if ($t === null || $t['match_status'] !== 'unmatched' || $t['direction'] !== 'in') {
            return ['status' => 'skipped', 'error' => 'not an unmatched incoming line'];
        }
        $open = self::open_invoices($pdo);
        $cands = self::candidates($pdo, $config, $t, $open);
        if ($cands === []) {
            $pdo->prepare("UPDATE bank_transactions SET ai_status = 'no_candidates' WHERE id = ?")->execute([$trxId]);
            return ['status' => 'no_candidates', 'error' => ''];
        }
        $res = self::ai_ask($pdo, $config, $t, $cands, 'bank_transactions', $trxId);
        if ($res['status'] !== 'ok') {
            $pdo->prepare('UPDATE bank_transactions SET ai_status = ?, ai_reason = ? WHERE id = ?')
                ->execute([$res['status'] === 'over_budget' ? 'over_budget' : 'failed', mb_substr($res['error'], 0, 300), $trxId]);
            return ['status' => $res['status'], 'error' => $res['error']];
        }
        $d = $res['data'];
        $reason = trim($d['reason_zh'] . ' / ' . $d['reason_id']);
        if ($d['invoice_ids'] === []) {
            $pdo->prepare("UPDATE bank_transactions SET ai_status = 'none', ai_confidence = ?, ai_reason = ? WHERE id = ?")
                ->execute([$d['confidence'], $reason, $trxId]);
            return ['status' => 'none', 'error' => ''];
        }
        $invs = array_map(fn($id) => $open[$id], $d['invoice_ids']);
        $alloc = self::allocate((float) $t['amount'], $invs, (string) $d['diff_type'], self::settings($config)['tolerance']);
        $proposal = ['source' => 'ai'] + $alloc;
        $sid = AiTask::suggest($pdo, 'bank_match', 'bank_transactions', $trxId, self::suggestion_value($proposal), $d['confidence'], $res['call_id']);
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ai', proposal = ?, ai_suggestion_id = ?, ai_confidence = ?, ai_reason = ?, ai_status = 'ok'
                        WHERE id = ? AND match_status = 'unmatched'")
            ->execute([json_encode($proposal, JSON_PRESERVE_ZERO_FRACTION), $sid, $d['confidence'], $reason, $trxId]);
        return ['status' => 'ok', 'error' => ''];
    }

    /** The model call itself, shared with the benchmark tool. */
    public static function ai_ask(PDO $pdo, array $config, array $t, array $cands, string $refTable, int $refId, ?callable $aliasNames = null): array
    {
        $ids = array_map(fn($c) => (int) $c['id'], $cands);
        return AiTask::run($pdo, $config, 'bank_match', [
            'content'   => self::ai_content($pdo, $t, $cands, $aliasNames),
            'schema'    => self::ai_schema(),
            'ref_table' => $refTable,
            'ref_id'    => $refId,
            'check'     => function (array $d) use ($ids): string {
                $bad = array_diff($d['invoice_ids'], $ids);
                if ($bad) {
                    return 'invoice id not among the candidates: ' . implode(',', $bad);
                }
                return count($d['invoice_ids']) === count(array_unique($d['invoice_ids'])) ? '' : 'an invoice listed twice';
            },
        ]);
    }

    /** What is compared on confirmation (adoption, most-changed fields). */
    private static function suggestion_value(array $proposal): array
    {
        return [
            'invoice_ids' => array_map(fn($l) => (int) $l['invoice_id'], $proposal['lines']),
            'amounts'     => array_map(fn($l) => round((float) $l['amount'], 2), $proposal['lines']),
            'diff_reason' => (string) $proposal['diff_reason'],
        ];
    }

    // ───────────────────────────── confirmation ─────────────────────────────

    /**
     * Book a transfer: one cash row per invoice in the payments ledger (date =
     * the bank date, receipt = the line's code), a non-cash settlement row
     * where a closing shortfall is accepted, the allocations, and the payer
     * name learned for this customer.
     *
     * @param list<array{invoice_id:int,amount:float}> $lines
     * @return array{ok:bool,error:string,invoices:list<array>,ai:?array}
     */
    public static function confirm(PDO $pdo, array $config, int $trxId, array $lines, string $diffReason, string $by): array
    {
        $fail = fn(string $e): array => ['ok' => false, 'error' => $e, 'invoices' => [], 'ai' => null];
        $t = self::find($pdo, $trxId);
        if ($t === null || !in_array($t['match_status'], ['unmatched', 'rule', 'ai'], true) || $t['direction'] !== 'in') {
            return $fail('not_confirmable');
        }
        $lines = array_values(array_filter($lines, fn($l) => (int) ($l['invoice_id'] ?? 0) > 0 && (float) ($l['amount'] ?? 0) > 0));
        if ($lines === []) {
            return $fail('no_lines');
        }
        if (abs(array_sum(array_column($lines, 'amount')) - (float) $t['amount']) > 0.005) {
            return $fail('sum_mismatch');
        }
        if (!in_array($diffReason, self::DIFF, true)) {
            $diffReason = 'none';
        }
        $ids = array_map(fn($l) => (int) $l['invoice_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            return $fail('duplicate_invoice');
        }
        $inv = [];
        $st = $pdo->prepare('SELECT i.*, o.customer_id FROM invoices i LEFT JOIN orders o ON o.id = i.order_id WHERE i.id = ?');
        foreach ($ids as $id) {
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row || ($row['voided_at'] ?? null) !== null) {
                return $fail('invoice_not_found');
            }
            $inv[$id] = $row;
        }
        $bank = (string) $pdo->query('SELECT bank FROM bank_accounts WHERE id = ' . (int) $t['account_id'])->fetchColumn();
        $method = $bank . ($t['tag'] === 'platform' ? ' · ' . $t['counterparty_name'] : '');
        $settleLabel = ['fee' => 'Biaya bank', 'withholding' => 'PPh (Bukti Potong)', 'rounding' => 'Pembulatan', 'other' => 'Selisih'];

        $pdo->beginTransaction();
        $pay = $pdo->prepare('INSERT INTO payments (invoice_id, customer, amount, pay_date, method, receipt_no, note, created_by, noncash) VALUES (?,?,?,?,?,?,?,?,?)');
        $alloc = $pdo->prepare('INSERT INTO payment_allocations (transaction_id, invoice_id, amount, diff_amount, diff_reason, payment_id, settle_payment_id, created_by)
                                VALUES (?,?,?,?,?,?,?,?)');
        foreach ($lines as $l) {
            $i = $inv[(int) $l['invoice_id']];
            $owed = round((float) $i['total'] - (float) $i['amount_paid'], 2);
            $amt = round((float) $l['amount'], 2);
            $diff = round($amt - $owed, 2);
            $pay->execute([$i['id'], $i['customer'], $amt, $t['trx_date'], $method, $t['trx_code'], 'Bank ' . $t['trx_code'], $by, 0]);
            $payId = (int) $pdo->lastInsertId();
            $settleId = null;
            $reason = abs($diff) < 0.005 ? 'none' : ($diff > 0 ? 'overpaid' : $diffReason);
            if ($diff < -0.005 && in_array($reason, self::CLOSING, true)) {
                $pay->execute([$i['id'], $i['customer'], -$diff, $t['trx_date'], $settleLabel[$reason], $t['trx_code'], 'Settlement ' . $t['trx_code'], $by, 1]);
                $settleId = (int) $pdo->lastInsertId();
            } elseif ($diff < -0.005) {
                $reason = 'partial';
            }
            $alloc->execute([$trxId, $i['id'], $amt, $diff, $reason, $payId, $settleId, $by]);
        }
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'confirmed', confirmed_by = ?, confirmed_at = datetime('now','localtime') WHERE id = ?")
            ->execute([$by, $trxId]);
        // The payer name, learned for the customer of the (first) invoice. A platform is never a customer's alias.
        $payer = trim((string) $t['counterparty_name']);
        $cust = (int) ($inv[$ids[0]]['customer_id'] ?? 0);
        if ($payer !== '' && $t['tag'] !== 'platform' && $cust > 0) {
            $pdo->prepare("INSERT OR IGNORE INTO customer_payer_aliases (customer_id, payer_name, payer_key, source, confirmed_by) VALUES (?,?,?,'confirmed',?)")
                ->execute([$cust, $payer, self::payer_key($payer), $by]);
        }
        $pdo->commit();

        $today = date('Y-m-d');
        $after = [];
        foreach ($ids as $id) {
            recompute_invoice_paid($pdo, $id, $today);
            $after[] = $inv[$id] + ['paid_after' => (float) $pdo->query('SELECT amount_paid FROM invoices WHERE id = ' . $id)->fetchColumn()];
        }
        // The payer just became known: their other waiting lines may now fit rule 2, with no model call.
        if ($payer !== '' && $t['tag'] !== 'platform') {
            $more = $pdo->prepare("SELECT id FROM bank_transactions WHERE match_status = 'unmatched' AND direction = 'in' AND counterparty_name = ? AND id <> ?");
            $more->execute([$payer, $trxId]);
            foreach ($more->fetchAll(PDO::FETCH_COLUMN) as $other) {
                self::rule_match($pdo, $config, (int) $other);
            }
        }
        $ai = null;
        if (!empty($t['ai_suggestion_id'])) {
            $final = ['invoice_ids' => $ids, 'amounts' => array_map(fn($l) => round((float) $l['amount'], 2), $lines), 'diff_reason' => $diffReason];
            $ai = AiTask::confirm($pdo, (int) $t['ai_suggestion_id'], $final, $by);
        }
        return ['ok' => true, 'error' => '', 'invoices' => $after, 'ai' => $ai];
    }

    /** Incoming money that is not a customer paying (capital, refund, interest, internal, other). */
    public static function mark_non_ar(PDO $pdo, int $trxId, string $reason, string $by): bool
    {
        if (!in_array($reason, self::NON_AR, true)) {
            return false;
        }
        $st = $pdo->prepare("UPDATE bank_transactions SET match_status = 'non_ar', non_ar_reason = ?, confirmed_by = ?, confirmed_at = datetime('now','localtime')
                              WHERE id = ? AND direction = 'in' AND match_status IN ('unmatched', 'rule', 'ai')");
        $st->execute([$reason, $by, $trxId]);
        return $st->rowCount() === 1;
    }

    /** Undo a non-AR mark (nothing was booked, so this is only a status). */
    public static function unmark_non_ar(PDO $pdo, int $trxId): bool
    {
        $st = $pdo->prepare("UPDATE bank_transactions SET match_status = 'unmatched', non_ar_reason = NULL, confirmed_by = NULL, confirmed_at = NULL
                              WHERE id = ? AND match_status = 'non_ar' AND tag <> 'interest'");
        $st->execute([$trxId]);
        return $st->rowCount() === 1;
    }

    /** Link an overpayment to the outgoing line that refunded it. */
    public static function link_refund(PDO $pdo, int $allocId, string $code): bool
    {
        $st = $pdo->prepare("SELECT id FROM bank_transactions WHERE trx_code = ? AND direction = 'out'");
        $st->execute([trim($code)]);
        $out = (int) $st->fetchColumn();
        if ($out === 0) {
            return false;
        }
        $u = $pdo->prepare("UPDATE payment_allocations SET refund_transaction_id = ? WHERE id = ? AND diff_reason = 'overpaid'");
        $u->execute([$out, $allocId]);
        return $u->rowCount() === 1;
    }

    /** The customer's withholding slip (Bukti Potong) has arrived. */
    public static function bukti_potong_received(PDO $pdo, int $allocId): bool
    {
        $u = $pdo->prepare("UPDATE payment_allocations SET bukti_potong_at = datetime('now','localtime') WHERE id = ? AND diff_reason = 'withholding' AND bukti_potong_at IS NULL");
        $u->execute([$allocId]);
        return $u->rowCount() === 1;
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT t.*, a.bank, a.last4, a.parser FROM bank_transactions t JOIN bank_accounts a ON a.id = t.account_id WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    // ───────────────────────────── collection status (the finance workbook's names) ─────────────────────────────

    /**
     * Status as finance names it in Catatan Penerimaan: PAID - Balance /
     * Lebih Bayar / Kurang Bayar / Shoppe / Gudang / Cash, Sebagian - Kurang
     * Bayar, UNPAID - Kurang Bayar. LOST (bad debt) has no source in the
     * platform yet and is not produced.
     */
    public static function collection_status(float $total, float $cash, float $settled, string $methods): string
    {
        $m = strtolower($methods);
        if ($cash <= 0.005 && $settled <= 0.005) {
            return 'UNPAID - Kurang Bayar';
        }
        if ($cash + $settled < $total - 0.005) {
            return 'Sebagian - Kurang Bayar';
        }
        return match (true) {
            str_contains($m, 'shopee')                                          => 'PAID - Shoppe',
            str_contains($m, 'gudang')                                          => 'PAID - Gudang',
            (str_contains($m, 'cash') || str_contains($m, 'tunai')) && !preg_match('/bca|icbc|transfer/', $m) => 'PAID - Cash',
            $cash > $total + 0.005                                              => 'PAID - Lebih Bayar',
            $settled > 0.005                                                    => 'PAID - Kurang Bayar',
            default                                                             => 'PAID - Balance',
        };
    }

    /**
     * One row per invoice issued in a month, Catatan Penerimaan style: totals,
     * status, and up to six payments (date, code, note, amount).
     * @return list<array>
     */
    public static function receipts(PDO $pdo, string $month, ?string $owner = null): array
    {
        $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.customer, i.total, o.submitter
                  FROM invoices i LEFT JOIN orders o ON o.id = i.order_id
                 WHERE i.voided_at IS NULL AND substr(i.invoice_date, 1, 7) = ?" . ($owner !== null ? ' AND o.submitter = ?' : '') . '
                 ORDER BY i.invoice_date, i.id';
        $st = $pdo->prepare($sql);
        $st->execute($owner !== null ? [$month, $owner] : [$month]);
        $rows = $st->fetchAll();
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $p = $pdo->prepare("SELECT invoice_id, amount, pay_date, method, receipt_no, note, COALESCE(noncash, 0) noncash FROM payments
                             WHERE invoice_id IN ({$in}) ORDER BY COALESCE(NULLIF(pay_date,''), date(created_at)), id");
        $p->execute($ids);
        $pays = [];
        foreach ($p->fetchAll() as $r) {
            $pays[(int) $r['invoice_id']][] = $r;
        }
        $r = $pdo->prepare("SELECT a.invoice_id, t.trx_code FROM payment_allocations a JOIN bank_transactions t ON t.id = a.refund_transaction_id WHERE a.invoice_id IN ({$in})");
        $r->execute($ids);
        $refunds = [];
        foreach ($r->fetchAll() as $x) {
            $refunds[(int) $x['invoice_id']][] = $x['trx_code'];
        }
        foreach ($rows as &$row) {
            $list = $pays[(int) $row['id']] ?? [];
            $cash = $settled = 0.0;
            $methods = [];
            $last = '';
            foreach ($list as $x) {
                if ((int) $x['noncash'] === 1) {
                    $settled += (float) $x['amount'];
                } else {
                    $cash += (float) $x['amount'];
                    if ((float) $x['amount'] > 0) {
                        $last = max($last, (string) $x['pay_date']);
                    }
                }
                $methods[] = (string) $x['method'];
            }
            $row['cash'] = round($cash, 2);
            $row['settled'] = round($settled, 2);
            $row['balance'] = round((float) $row['total'] - $cash, 2);
            $row['status'] = self::collection_status((float) $row['total'], $cash, $settled, implode(' ', $methods));
            $row['last_paid'] = $last;
            $row['payments'] = $list;
            $row['refunds'] = $refunds[(int) $row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }

    // ───────────────────────────── history: Buku Bank (one-off) and the benchmark ─────────────────────────────

    /**
     * IN lines of the finance workbook's 「1. Mutasi Bank BCA」 sheet. Columns
     * are found by their header (Tanggal Transaksi, Kode Transaksi, the
     * description column, Nomor Invoice, CR), not by position.
     * @return array{rows:list<array>,errors:list<string>}
     */
    public static function book_rows(array $sheet): array
    {
        $idx = null;
        $rows = [];
        $errors = [];
        foreach ($sheet as $n => $line) {
            $cells = array_map(fn($v) => is_string($v) ? trim($v) : $v, $line);
            if ($idx === null) {
                $names = array_map(fn($v) => strtolower(trim((string) $v)), $cells);
                if (in_array('kode transaksi', $names, true)) {
                    $idx = array_flip($names);
                }
                continue;
            }
            $get = function (array $keys) use ($idx, $cells) {
                foreach ($keys as $k) {
                    if (isset($idx[$k])) {
                        return $cells[$idx[$k]] ?? null;
                    }
                }
                return null;
            };
            $code = trim((string) $get(['kode transaksi']));
            $cr = $get(['cr', 'kredit', 'credit']);
            if (!preg_match('/^BCA-IN-\d{4}-\d+$/', $code) || !is_numeric($cr) || (float) $cr <= 0) {
                continue;
            }
            $date = self::sheet_date($get(['tanggal transaksi', 'tanggal']));
            if ($date === null) {
                $errors[] = "row " . ($n + 1) . " ({$code}): unreadable date";
                continue;
            }
            $rows[] = [
                'code' => $code, 'date' => $date, 'amount' => round((float) $cr, 2),
                'description' => trim((string) $get(['petty cash', 'keterangan', 'deskripsi', 'description'])),
                'invoice_raw' => trim((string) $get(['nomor invoice', 'no. invoice', 'no invoice', 'invoice'])),
            ];
        }
        if ($idx === null) {
            $errors[] = 'header row with "Kode Transaksi" not found';
        }
        return ['rows' => $rows, 'errors' => $errors];
    }

    /** 2026-10-02 | 02/10/2026 | an Excel day number → ISO date. */
    private static function sheet_date(mixed $v): ?string
    {
        if (is_float($v) || is_int($v) || (is_string($v) && is_numeric($v))) {
            $d = (int) $v;
            return $d > 20000 && $d < 80000 ? gmdate('Y-m-d', ($d - 25569) * 86400) : null;
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) {
            return sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    /**
     * Load Buku Bank IN lines: each one's invoice(s) found among all CRM
     * invoices by number, the payer learned for that customer (source
     * 'history'), and the line kept in bank_book for the benchmark.
     * Dry run unless $apply. Safe to repeat (codes and aliases are unique).
     */
    public static function book_import(PDO $pdo, array $rows, bool $apply, string $batch): array
    {
        $all = [];
        foreach ($pdo->query('SELECT i.id, i.invoice_no, o.customer_id FROM invoices i LEFT JOIN orders o ON o.id = i.order_id WHERE i.voided_at IS NULL') as $r) {
            if (preg_match('/^(\d+)\s*-\s*AMI\s*-\s*(?:INV\s*-\s*)?(\d{1,2})\s*-\s*(\d{2})/i', (string) $r['invoice_no'], $m)) {
                $all[] = ['id' => (int) $r['id'], 'customer_id' => (int) $r['customer_id'], 'ref' => [(int) $m[1], (int) $m[2], (int) $m[3]]];
            }
        }
        $rep = ['lines' => count($rows), 'resolved' => 0, 'unresolved' => [], 'no_payer' => 0, 'aliases' => [], 'conflicts' => [], 'stored' => 0, 'aliases_added' => 0];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO bank_book (trx_code, trx_date, description, amount, invoice_raw, invoice_ids, customer_id, payer_name, batch) VALUES (?,?,?,?,?,?,?,?,?)');
        $alias = $pdo->prepare("INSERT OR IGNORE INTO customer_payer_aliases (customer_id, payer_name, payer_key, source, confirmed_by) VALUES (?,?,?,'history',?)");
        $seen = [];
        foreach ($rows as $row) {
            $ids = [];
            foreach (self::refs($row['invoice_raw']) as $ref) {
                foreach ($all as $inv) {
                    if ($inv['ref'][0] === $ref['serial'] && ($ref['mm'] === null || ($inv['ref'][1] === $ref['mm'] && $inv['ref'][2] === $ref['yy']))) {
                        $ids[] = $inv['id'];
                    }
                }
            }
            $ids = array_values(array_unique($ids));
            $customer = null;
            if ($ids) {
                $rep['resolved']++;
                foreach ($all as $inv) {
                    if ($inv['id'] === $ids[0]) {
                        $customer = $inv['customer_id'] ?: null;
                    }
                }
            } elseif ($row['invoice_raw'] !== '') {
                $rep['unresolved'][] = "{$row['code']} {$row['invoice_raw']}";
            }
            $p = self::bca_payer($row['description']);
            $payer = $p['platform'] ? '' : $p['payer'];
            if ($payer === '') {
                $rep['no_payer']++;
            } elseif ($customer) {
                $key = self::payer_key($payer);
                $seen[$key][$customer] = $payer;
            }
            if ($apply) {
                $ins->execute([$row['code'], $row['date'], $row['description'], $row['amount'], $row['invoice_raw'] ?: null,
                               $ids ? implode(',', $ids) : null, $customer, $payer ?: null, $batch]);
                $rep['stored'] += $ins->rowCount();
            }
        }
        foreach ($seen as $key => $byCustomer) {
            foreach ($byCustomer as $cid => $name) {
                $rep['aliases'][] = "{$name} → customer #{$cid}";
                if ($apply) {
                    $alias->execute([$cid, $name, $key, 'history']);
                    $rep['aliases_added'] += $alias->rowCount();
                }
            }
            if (count($byCustomer) > 1) {
                $rep['conflicts'][] = reset($byCustomer) . ' → ' . count($byCustomer) . ' customers';
            }
        }
        return $rep;
    }

    /**
     * Blind test on one month of Buku Bank IN lines (card: September): the
     * workbook's invoice number is hidden, rules (and the model with $ai)
     * match each line against the invoices open on that day, and the answer
     * is compared with what finance recorded. Payer names learned from that
     * month are not used, so the month is unseen.
     */
    public static function benchmark(PDO $pdo, array $config, string $month, bool $ai, int $limit = 0, ?callable $log = null): array
    {
        $book = $pdo->prepare("SELECT * FROM bank_book WHERE substr(trx_date, 1, 7) = ? AND invoice_ids IS NOT NULL ORDER BY trx_date, id");
        $book->execute([$month]);
        $lines = $book->fetchAll();
        if ($limit > 0) {
            $lines = array_slice($lines, 0, $limit);
        }
        // Payer → customers from every other month (history), never from the month under test.
        $known = [];
        $names = [];
        $other = $pdo->prepare("SELECT payer_name, customer_id FROM bank_book WHERE substr(trx_date, 1, 7) <> ? AND customer_id IS NOT NULL AND payer_name IS NOT NULL");
        $other->execute([$month]);
        foreach ($other->fetchAll() as $r) {
            $known[self::payer_key((string) $r['payer_name'])][(int) $r['customer_id']] = true;
            $names[(int) $r['customer_id']][(string) $r['payer_name']] = true;
        }
        $lookup = fn(string $key): array => array_keys($known[$key] ?? []);
        $aliasNames = fn(int $cid): array => array_slice(array_keys($names[$cid] ?? []), 0, 5);
        $r = ['month' => $month, 'lines' => count($lines), 'rule' => 0, 'rule_ok' => 0, 'ai' => 0, 'ai_ok' => 0, 'ai_high' => 0, 'ai_high_wrong' => 0,
              'ai_failed' => 0, 'none' => 0, 'detail' => []];
        foreach ($lines as $b) {
            $truth = array_map('intval', explode(',', (string) $b['invoice_ids']));
            sort($truth);
            $p = self::bca_payer((string) $b['description']);
            $t = ['trx_date' => $b['trx_date'], 'amount' => (float) $b['amount'], 'description' => $b['description'],
                  'tag' => $p['platform'] ? 'platform' : 'goods', 'counterparty_name' => $p['payer'],
                  'refs' => ($x = self::refs((string) $b['description'])) ? json_encode($x) : null];
            $open = self::open_invoices($pdo, (string) $b['trx_date']);
            $how = 'none';
            $got = [];
            $conf = '';
            $hit = self::rule_proposal($pdo, $config, $t, $open, $lookup);
            if ($hit !== null) {
                $how = 'rule';
                $got = array_map(fn($l) => (int) $l['invoice_id'], $hit['proposal']['lines']);
            } elseif ($ai) {
                $cands = self::candidates($pdo, $config, $t, $open, $lookup);
                if ($cands) {
                    $res = self::ai_ask($pdo, $config, $t, $cands, 'bank_book', (int) $b['id'], $aliasNames);
                    if ($res['status'] === 'ok') {
                        $got = array_map('intval', $res['data']['invoice_ids']);
                        $conf = (string) $res['data']['confidence'];
                        $how = $got ? 'ai' : 'none';
                    } else {
                        $how = 'ai_failed';
                        if ($res['status'] === 'over_budget') {
                            $r['detail'][] = ['code' => $b['trx_code'], 'how' => 'stopped', 'ok' => false, 'truth' => $truth, 'got' => [], 'conf' => ''];
                            break;
                        }
                    }
                }
            }
            sort($got);
            $ok = $got !== [] && $got === $truth;
            if ($how === 'rule') {
                $r['rule']++;
                $r['rule_ok'] += (int) $ok;
            } elseif ($how === 'ai') {
                $r['ai']++;
                $r['ai_ok'] += (int) $ok;
                if ($conf === 'high') {
                    $r['ai_high']++;
                    $r['ai_high_wrong'] += (int) !$ok;
                }
            } elseif ($how === 'ai_failed') {
                $r['ai_failed']++;
            } else {
                $r['none']++;
            }
            $r['detail'][] = ['code' => $b['trx_code'], 'how' => $how, 'ok' => $ok, 'truth' => $truth, 'got' => $got, 'conf' => $conf];
            if ($log) {
                $log(sprintf('%s %-9s %-5s truth %s got %s %s', $b['trx_code'], $how, $ok ? 'ok' : 'WRONG', implode(',', $truth), implode(',', $got) ?: '-', $conf));
            }
        }
        $r['accuracy'] = $r['lines'] > 0 ? round(($r['rule_ok'] + $r['ai_ok']) / $r['lines'], 4) : 0.0;
        $r['ai_high_error_rate'] = $r['ai_high'] > 0 ? round($r['ai_high_wrong'] / $r['ai_high'], 4) : 0.0;
        return $r;
    }
}
