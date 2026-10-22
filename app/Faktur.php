<?php
declare(strict_types=1);

/**
 * Output tax invoices (Faktur Pajak Keluaran) for Coretax, by rule, no AI
 * (FA-TAX-01).
 *
 * Every invoice is classed FAKTUR (buyer has an NPWP or NIK), GUNGGUNG
 * (retail, reported in total) or TBC (looks like a company but has no tax id:
 * finance asks for it). Finance can override the class per invoice.
 *
 * For a period, the FAKTUR invoices are written into DJP's own Excel import
 * template (v1.6.1, database/coretax/): the template file is copied as it is
 * and only the data rows of the Faktur and DetailFaktur sheets are filled, so
 * headers, column order, reference sheets, validations and the column notes
 * stay exactly DJP's. Amounts reuse invoice_amounts_coretax() per line; the
 * constants (Kode Transaksi 04, Kode Barang 760600, UM.0021, tarif 12) live in
 * config 'coretax'. Generated files are kept in data/faktur/ (private), one
 * current version per period; regenerating marks the previous one superseded.
 *
 * Buyer tax data (NPWP / NIK, name and address as registered, NITKU) is kept
 * on the customer and maintained by finance only (users decided 2026-10-22:
 * no extra field for sales, no reminder to sales for TBC).
 */
final class Faktur
{
    public const CLASSES = ['FAKTUR', 'GUNGGUNG', 'TBC'];
    public const TEMPLATE = __DIR__ . '/../database/coretax/Faktur_PK_Template_v1.6.1.xlsx';
    /** Legal forms that mark a company buyer (a company without a tax id is TBC, not retail). */
    private const COMPANY = '/\b(PT|CV|UD|FA|FIRMA|KOPERASI|YAYASAN|TBK|PD|PERUM|PERSERO)\b\.?/i';

    /** Test seam: where generated files are kept. */
    public static ?string $storeDir = null;

    public static function ensure_schema(PDO $pdo): void
    {
        $cols = array_column($pdo->query('PRAGMA table_info(customers)')->fetchAll(), 'name');
        foreach (['tax_npwp', 'tax_nik', 'tax_name', 'tax_address', 'tax_nitku', 'tax_email', 'tax_updated_by', 'tax_updated_at'] as $c) {
            if (!in_array($c, $cols, true)) {
                $pdo->exec("ALTER TABLE customers ADD COLUMN {$c} TEXT");
            }
        }
        $icols = array_column($pdo->query('PRAGMA table_info(invoices)')->fetchAll(), 'name');
        if (!in_array('faktur_class', $icols, true)) {
            $pdo->exec('ALTER TABLE invoices ADD COLUMN faktur_class TEXT');   // NULL = automatic
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS faktur_exports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, period_from TEXT NOT NULL, period_to TEXT NOT NULL,
            stored TEXT NOT NULL, file_name TEXT NOT NULL, invoices INTEGER NOT NULL DEFAULT 0, lines INTEGER NOT NULL DEFAULT 0,
            dpp_total REAL NOT NULL DEFAULT 0, ppn_total REAL NOT NULL DEFAULT 0, superseded_at TEXT,
            created_by TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
    }

    public static function settings(array $config): array
    {
        $c = (array) ($config['coretax'] ?? []);
        $ship = (array) ($c['shipping'] ?? []);
        return [
            'seller_npwp'    => preg_replace('/\D/', '', (string) ($config['company_npwp'] ?? '')) ?? '',
            'seller_nitku'   => preg_replace('/\D/', '', (string) ($config['company_nitku'] ?? '')) ?? '',
            'kode_transaksi' => (string) ($c['kode_transaksi'] ?? '04'),
            'kode_barang'    => (string) ($c['kode_barang'] ?? '760600'),
            'satuan'         => (string) ($c['satuan'] ?? 'UM.0021'),
            'tarif_ppn'      => (int) ($c['tarif_ppn'] ?? 12),
            'negara'         => (string) ($c['negara'] ?? 'IDN'),
            'mode'           => (string) ($config['coretax_decimals'] ?? 'truncate'),
            'ship_jenis'     => (string) ($ship['jenis'] ?? 'B'),
            'ship_kode'      => (string) ($ship['kode'] ?? ''),
            'ship_nama'      => (string) ($ship['nama'] ?? 'Ongkos Kirim'),
            'ship_satuan'    => (string) ($ship['satuan'] ?? 'UM.0033'),
        ];
    }

    // ───────────────────────────── tax ids ─────────────────────────────

    /** NPWP as Coretax wants it: 16 digits; an old 15-digit number gets a leading 0. '' when not usable. */
    public static function npwp16(?string $npwp): string
    {
        $d = preg_replace('/\D/', '', (string) $npwp) ?? '';
        return match (strlen($d)) {
            16      => $d,
            15      => '0' . $d,
            default => '',
        };
    }

    /** The seller's 22-digit NITKU: configured, or NPWP + 000000 (head office). */
    public static function seller_nitku(array $s): string
    {
        if (strlen($s['seller_nitku']) === 22) {
            return $s['seller_nitku'];
        }
        $n = self::npwp16($s['seller_npwp']);
        return $n === '' ? '' : $n . '000000';
    }

    /**
     * The buyer's id for the template. Finance's practice (their own filled
     * templates): a 16-digit NIK is used as the TIN, like an NPWP.
     * @return ?array{id:string,type:string,doc:string,nitku:string} null = no usable id
     */
    public static function buyer_id(array $c): ?array
    {
        $npwp = self::npwp16($c['tax_npwp'] ?? '');
        $nik = preg_replace('/\D/', '', (string) ($c['tax_nik'] ?? '')) ?? '';
        $id = $npwp !== '' ? $npwp : (strlen($nik) === 16 ? $nik : '');
        if ($id === '') {
            return null;
        }
        $nitku = preg_replace('/\D/', '', (string) ($c['tax_nitku'] ?? '')) ?? '';
        return ['id' => $id, 'type' => 'TIN', 'doc' => '-', 'nitku' => strlen($nitku) === 22 ? $nitku : $id . '000000'];
    }

    // ───────────────────────────── classes ─────────────────────────────

    /** Automatic class from the buyer's data, before any override. */
    public static function auto_class(array $row): string
    {
        $hasId = trim((string) ($row['tax_npwp'] ?? '')) !== '' || trim((string) ($row['tax_nik'] ?? '')) !== '';
        if ($hasId) {
            return 'FAKTUR';
        }
        $names = implode(' ', [(string) ($row['cname'] ?? ''), (string) ($row['ccompany'] ?? ''), (string) ($row['customer'] ?? ''), (string) ($row['bill_to_name'] ?? '')]);
        if (preg_match(self::COMPANY, $names) || in_array((string) ($row['client_type'] ?? ''), ['Distributor', 'Contractor'], true)) {
            return 'TBC';
        }
        return 'GUNGGUNG';
    }

    public static function class_of(array $row): string
    {
        $o = (string) ($row['faktur_class'] ?? '');
        return in_array($o, self::CLASSES, true) ? $o : self::auto_class($row);
    }

    /** Non-void invoices issued in the period, with their buyer's tax data. */
    public static function period_invoices(PDO $pdo, string $from, string $to): array
    {
        $st = $pdo->prepare("SELECT i.*, o.customer_id, o.client_type, o.order_no, c.name AS cname, c.company AS ccompany,
                                    c.tax_npwp, c.tax_nik, c.tax_name, c.tax_address, c.tax_nitku, c.tax_email
                               FROM invoices i LEFT JOIN orders o ON o.id = i.order_id LEFT JOIN customers c ON c.id = o.customer_id
                              WHERE i.voided_at IS NULL AND i.invoice_date BETWEEN ? AND ? ORDER BY i.invoice_date, i.id");
        $st->execute([$from, $to]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['class'] = self::class_of($r);
            $r['auto_class'] = self::auto_class($r);
        }
        unset($r);
        return $rows;
    }

    // ───────────────────────────── lines and checks ─────────────────────────────

    /**
     * DetailFaktur lines of one invoice. Harga Satuan is the invoice's pre-tax
     * unit price; DPP, DPP Nilai Lain and PPN per line come from
     * invoice_amounts_coretax() run on that line alone, so each line follows
     * Coretax's own rule (Tarif PPN × DPP Nilai Lain, 2 decimals). Shipping,
     * when charged, is one more line.
     * @return list<array>
     */
    public static function lines(array $items, float $pretaxShipping, array $s): array
    {
        $out = [];
        foreach ($items as $it) {
            $unit = round((float) $it['price'], 2);
            $qty = round((float) $it['qty'], 2);
            // Price given pre-tax (price_excl): the line's figures follow from it exactly.
            $a = invoice_amounts_coretax([['qty' => $qty, 'price' => 0, 'price_excl' => $unit]], 0.0, $s['mode'], true);
            $spec = no_cjk((string) $it['spec']);
            $color = no_cjk((string) $it['color']);
            $size = no_cjk((string) $it['size']);
            $name = trim(($spec !== '' ? "({$spec}) " : '') . no_cjk((string) $it['sku']) . ($color !== '' ? " ({$color})" : '') . ($size !== '' ? " {$size}" : ''));
            $out[] = ['jenis' => 'A', 'kode' => $s['kode_barang'], 'nama' => $name !== '' ? $name : 'ACP', 'satuan' => $s['satuan'],
                      'harga' => $unit, 'qty' => $qty, 'dpp' => $a['subtotal'], 'dpp_nl' => $a['dpp'], 'ppn' => $a['ppn']];
        }
        if ($pretaxShipping > 0.004) {
            $a = invoice_amounts_coretax([['qty' => 1, 'price' => 0, 'price_excl' => round($pretaxShipping, 2)]], 0.0, $s['mode'], true);
            $out[] = ['jenis' => $s['ship_jenis'], 'kode' => $s['ship_kode'], 'nama' => $s['ship_nama'], 'satuan' => $s['ship_satuan'],
                      'harga' => round($pretaxShipping, 2), 'qty' => 1.0, 'dpp' => $a['subtotal'], 'dpp_nl' => $a['dpp'], 'ppn' => $a['ppn']];
        }
        return $out;
    }

    /**
     * What stops an invoice from going into the template (card: wrong NPWP
     * length, no address, amounts that do not add up to the invoice).
     * @return list<string> i18n keys, optionally "key:detail"
     */
    public static function blockers(array $inv, array $lines): array
    {
        $b = [];
        $npwp = trim((string) ($inv['tax_npwp'] ?? ''));
        $nik = preg_replace('/\D/', '', (string) ($inv['tax_nik'] ?? '')) ?? '';
        if ($npwp !== '' && self::npwp16($npwp) === '') {
            $b[] = 'fk_err_npwp_len:' . strlen(preg_replace('/\D/', '', $npwp) ?? '');
        } elseif ($npwp === '' && $nik !== '' && strlen($nik) !== 16) {
            $b[] = 'fk_err_nik_len:' . strlen($nik);
        } elseif (self::buyer_id($inv) === null) {
            $b[] = 'fk_err_no_id';
        }
        if (self::buyer_address($inv) === '') {
            $b[] = 'fk_err_address';
        }
        if ($lines === []) {
            $b[] = 'fk_err_no_lines';
        } else {
            $tol = 1.0 * count($lines);   // card: PPN within Rp 1 per line
            $dpp = array_sum(array_column($lines, 'dpp'));
            $ppn = array_sum(array_column($lines, 'ppn'));
            if (abs($dpp - (float) $inv['subtotal']) > $tol || abs($ppn - (float) $inv['ppn']) > $tol) {
                $b[] = sprintf('fk_err_amounts:%s / %s', number_format($ppn, 2, ',', '.'), number_format((float) $inv['ppn'], 2, ',', '.'));
            }
        }
        return $b;
    }

    public static function buyer_name(array $inv): string
    {
        foreach (['tax_name', 'ccompany', 'bill_to_name', 'customer', 'cname'] as $k) {
            if (trim((string) ($inv[$k] ?? '')) !== '') {
                return trim(no_cjk((string) $inv[$k]));
            }
        }
        return '';
    }

    public static function buyer_address(array $inv): string
    {
        foreach (['tax_address', 'address'] as $k) {   // registered address, else the invoice's
            if (trim((string) ($inv[$k] ?? '')) !== '') {
                return trim(no_cjk((string) $inv[$k]));
            }
        }
        return '';
    }

    /** Items per invoice id. @return array<int,list<array>> */
    private static function items(PDO $pdo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id IN ({$in}) ORDER BY invoice_id, id");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['invoice_id']][] = $r;
        }
        return $out;
    }

    /**
     * Everything the Faktur page shows for a period: each invoice with class,
     * lines and blockers, plus what stops the whole export.
     */
    public static function review(PDO $pdo, array $config, string $from, string $to): array
    {
        $s = self::settings($config);
        $rows = self::period_invoices($pdo, $from, $to);
        $items = self::items($pdo, array_map(fn($r) => (int) $r['id'], $rows));
        $global = [];
        if (self::npwp16($s['seller_npwp']) === '') {
            $global[] = 'fk_err_seller';
        }
        $count = ['FAKTUR' => 0, 'GUNGGUNG' => 0, 'TBC' => 0, 'blocked' => 0];
        foreach ($rows as &$r) {
            $r['lines'] = self::lines($items[(int) $r['id']] ?? [], (float) $r['shipping_cost'], $s);
            $r['blockers'] = $r['class'] === 'FAKTUR' ? self::blockers($r, $r['lines']) : [];
            $count[$r['class']]++;
            $count['blocked'] += $r['blockers'] ? 1 : 0;
        }
        unset($r);
        return ['rows' => $rows, 'count' => $count, 'global' => $global, 'settings' => $s];
    }

    // ───────────────────────────── the template ─────────────────────────────

    /**
     * Fill DJP's template. Faktur rows start at row 4 (rows 1–3 are the
     * seller NPWP and the header), DetailFaktur rows at row 2; cells reuse the
     * template's own row styles. Text cells are inline strings so ids keep
     * their leading zeros.
     * @param list<array> $fakturRows each: baris, tanggal (DD/MM/YYYY), ref, buyer, nama, alamat, email
     * @param list<array> $detailRows each: baris + a lines() entry
     */
    public static function build_xlsx(string $sellerNpwp, string $sellerNitku, string $kodeTransaksi, int $tarif, string $negara, array $fakturRows, array $detailRows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fk');
        copy(self::TEMPLATE, $tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('cannot open the Coretax template');
        }
        $str = fn(string $ref, string $v, int $style): string => sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $style, htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $num = fn(string $ref, float|int $v, int $style): string => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'));

        // Sheet 1 — Faktur. Styles as in DJP's own row 4.
        $x = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $seller = $str('C1', $sellerNpwp, 9);
        $x = preg_replace_callback('#<c r="C1"([^>]*)>.*?</c>#s', fn() => $seller, $x, 1) ?? $x;
        $rowsXml = '';
        $last = 3;
        foreach ($fakturRows as $i => $f) {
            $r = 4 + $i;
            $last = $r;
            $b = $f['buyer'];
            $rowsXml .= sprintf('<row r="%d" ht="15.5" customHeight="1" s="49">', $r)
                . $num("A{$r}", $f['baris'], 6) . $str("B{$r}", $f['tanggal'], 7) . $str("C{$r}", 'Normal', 8) . $str("D{$r}", $kodeTransaksi, 9)
                . $str("H{$r}", $f['ref'], 9) . $str("J{$r}", $sellerNitku, 11) . $str("K{$r}", $b['id'], 1) . $str("L{$r}", $b['type'], 15)
                . $str("M{$r}", $negara, 9) . $str("N{$r}", $b['doc'], 9) . $str("O{$r}", $f['nama'], 9) . $str("P{$r}", $f['alamat'], 9)
                . ($f['email'] !== '' ? $str("Q{$r}", $f['email'], 10) : '') . $str("R{$r}", $b['nitku'], 41)
                . '</row>';
        }
        // Keep DJP's empty styled rows below the data, as in the template (up to row 47).
        for ($r = $last + 1; $r <= max(47, $last); $r++) {
            $rowsXml .= sprintf('<row r="%d" ht="15.5" customHeight="1" s="49"/>', $r);
        }
        // A callback, not a replacement string: buyer names and addresses may contain "$" or "\\".
        $x = preg_replace_callback('#(<row r="3"[^>]*>.*?</row>).*?(</sheetData>)#s', fn($m) => $m[1] . $rowsXml . $m[2], $x, 1) ?? $x;
        $x = preg_replace('#<dimension ref="[^"]*"/>#', '<dimension ref="A1:R' . $last . '"/>', $x, 1) ?? $x;
        $x = str_replace('sqref="C4:C47"', 'sqref="C4:C' . max(47, $last) . '"', $x);
        $zip->addFromString('xl/worksheets/sheet1.xml', $x);

        // Sheet 2 — DetailFaktur.
        $x = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $rowsXml = '';
        $last = 1;
        foreach ($detailRows as $i => $d) {
            $r = 2 + $i;
            $last = $r;
            $rowsXml .= sprintf('<row r="%d">', $r)
                . $num("A{$r}", $d['baris'], 0) . $str("B{$r}", $d['jenis'], 0) . ($d['kode'] !== '' ? $str("C{$r}", $d['kode'], 0) : '')
                . $str("D{$r}", $d['nama'], 0) . $str("E{$r}", $d['satuan'], 0) . $num("F{$r}", $d['harga'], 0) . $num("G{$r}", $d['qty'], 0)
                . $num("H{$r}", 0, 0) . $num("I{$r}", $d['dpp'], 0) . $num("J{$r}", $d['dpp_nl'], 0) . $num("K{$r}", $tarif, 0)
                . $num("L{$r}", $d['ppn'], 0) . $num("M{$r}", 0, 0) . $num("N{$r}", 0, 0)
                . '</row>';
        }
        $x = preg_replace_callback('#(<row r="1"[^>]*>.*?</row>).*?(</sheetData>)#s', fn($m) => $m[1] . $rowsXml . $m[2], $x, 1) ?? $x;
        $x = preg_replace('#<dimension ref="[^"]*"/>#', '<dimension ref="A1:N' . $last . '"/>', $x, 1) ?? $x;
        $x = str_replace('sqref="K2:K73"', 'sqref="K2:K' . max(73, $last) . '"', $x);
        $zip->addFromString('xl/worksheets/sheet2.xml', $x);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    // ───────────────────────────── generating ─────────────────────────────

    private static function store_dir(): string
    {
        return self::$storeDir ?? __DIR__ . '/../data/faktur';
    }

    /** Keep a generated file; the same period's earlier version is marked superseded. */
    private static function store(PDO $pdo, string $kind, string $from, string $to, string $fileName, string $bytes, array $tot, string $by): int
    {
        $dir = self::store_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $stored = bin2hex(random_bytes(12)) . '.xlsx';
        file_put_contents($dir . '/' . $stored, $bytes);
        $pdo->prepare("UPDATE faktur_exports SET superseded_at = datetime('now','localtime') WHERE kind = ? AND period_from = ? AND period_to = ? AND superseded_at IS NULL")
            ->execute([$kind, $from, $to]);
        $pdo->prepare('INSERT INTO faktur_exports (kind, period_from, period_to, stored, file_name, invoices, lines, dpp_total, ppn_total, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$kind, $from, $to, $stored, $fileName, $tot['invoices'], $tot['lines'], $tot['dpp'], $tot['ppn'], $by]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * The Coretax import file for a period (FAKTUR invoices only). Refused
     * while anything blocks: the seller's NPWP is missing, or any FAKTUR
     * invoice has a blocker — finance fixes the data or changes the class.
     * @return array{ok:bool,errors:list<string>,id:int,invoices:int,lines:int,ppn:float}
     */
    public static function generate(PDO $pdo, array $config, string $from, string $to, string $by): array
    {
        $rv = self::review($pdo, $config, $from, $to);
        $s = $rv['settings'];
        $errors = $rv['global'];
        foreach ($rv['rows'] as $r) {
            foreach ($r['blockers'] as $b) {
                $errors[] = $r['invoice_no'] . ' · ' . $b;
            }
        }
        $fak = array_values(array_filter($rv['rows'], fn($r) => $r['class'] === 'FAKTUR'));
        if ($fak === []) {
            $errors[] = 'fk_err_none';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'id' => 0, 'invoices' => 0, 'lines' => 0, 'ppn' => 0.0];
        }
        $fRows = $dRows = [];
        $tot = ['invoices' => 0, 'lines' => 0, 'dpp' => 0.0, 'ppn' => 0.0];
        foreach ($fak as $i => $r) {
            $baris = $i + 1;
            $fRows[] = ['baris' => $baris, 'tanggal' => date('d/m/Y', strtotime((string) $r['invoice_date'])), 'ref' => (string) $r['invoice_no'],
                        'buyer' => self::buyer_id($r), 'nama' => self::buyer_name($r), 'alamat' => self::buyer_address($r), 'email' => trim((string) $r['tax_email'])];
            foreach ($r['lines'] as $l) {
                $dRows[] = ['baris' => $baris] + $l;
                $tot['lines']++;
                $tot['dpp'] += $l['dpp'];
                $tot['ppn'] += $l['ppn'];
            }
            $tot['invoices']++;
        }
        $tot['dpp'] = round($tot['dpp'], 2);
        $tot['ppn'] = round($tot['ppn'], 2);
        $bytes = self::build_xlsx(self::npwp16($s['seller_npwp']), self::seller_nitku($s), $s['kode_transaksi'], $s['tarif_ppn'], $s['negara'], $fRows, $dRows);
        $name = sprintf('Faktur_PK_%s_%s.xlsx', str_replace('-', '', $from), str_replace('-', '', $to));
        $id = self::store($pdo, 'faktur', $from, $to, $name, $bytes, $tot, $by);
        return ['ok' => true, 'errors' => [], 'id' => $id, 'invoices' => $tot['invoices'], 'lines' => $tot['lines'], 'ppn' => $tot['ppn']];
    }

    /** The GUNGGUNG (retail, reported in total) list for a period, with totals; kept like the Faktur file. */
    public static function gunggung(PDO $pdo, array $config, string $from, string $to, string $by): array
    {
        $rv = self::review($pdo, $config, $from, $to);
        $rows = array_values(array_filter($rv['rows'], fn($r) => $r['class'] === 'GUNGGUNG'));
        $out = [];
        $tot = ['invoices' => 0, 'lines' => 0, 'dpp' => 0.0, 'ppn' => 0.0, 'total' => 0.0];
        foreach ($rows as $r) {
            $dppNl = (float) ($r['dpp'] ?? round((float) $r['subtotal'] * 11 / 12, 2));
            $out[] = [$r['invoice_no'], $r['invoice_date'], self::buyer_name($r), (float) $r['subtotal'], $dppNl, (float) $r['ppn'], (float) $r['total']];
            $tot['invoices']++;
            $tot['dpp'] += (float) $r['subtotal'];
            $tot['ppn'] += (float) $r['ppn'];
            $tot['total'] += (float) $r['total'];
        }
        $out[] = ['TOTAL', '', count($rows) . ' invoice', round($tot['dpp'], 2), '', round($tot['ppn'], 2), round($tot['total'], 2)];
        $tmp = tempnam(sys_get_temp_dir(), 'gg');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        xlsx_build($zip, 'Digunggung', ['No. Invoice', 'Tanggal', 'Nama Pembeli', 'DPP', 'DPP Nilai Lain', 'PPN', 'Total'], $out);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $tot['dpp'] = round($tot['dpp'], 2);
        $tot['ppn'] = round($tot['ppn'], 2);
        $name = sprintf('Digunggung_%s_%s.xlsx', str_replace('-', '', $from), str_replace('-', '', $to));
        $id = self::store($pdo, 'gunggung', $from, $to, $name, $bytes, $tot, $by);
        return ['ok' => true, 'id' => $id, 'invoices' => $tot['invoices'], 'ppn' => $tot['ppn']];
    }

    /** Stream a kept file (after the caller's permission check). */
    public static function send(array $export): void
    {
        $path = self::store_dir() . '/' . basename((string) $export['stored']);
        if (!is_file($path)) {
            http_response_code(404);
            exit('File not found');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $export['file_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ───────────────────────────── buyer data ─────────────────────────────

    /**
     * Save one customer's tax data (finance). Digits only for ids; returns the
     * fields that changed (for the audit line) or an error key.
     * @return array{ok:bool,error:string,changed:array}
     */
    public static function save_customer(PDO $pdo, int $customerId, array $in, string $by): array
    {
        $st = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $st->execute([$customerId]);
        $before = $st->fetch();
        if (!$before) {
            return ['ok' => false, 'error' => 'fk_err_customer', 'changed' => []];
        }
        $npwp = preg_replace('/\D/', '', (string) ($in['tax_npwp'] ?? '')) ?? '';
        $nik = preg_replace('/\D/', '', (string) ($in['tax_nik'] ?? '')) ?? '';
        $nitku = preg_replace('/\D/', '', (string) ($in['tax_nitku'] ?? '')) ?? '';
        if ($npwp !== '' && self::npwp16($npwp) === '') {
            return ['ok' => false, 'error' => 'fk_err_npwp_input', 'changed' => []];
        }
        if ($nik !== '' && strlen($nik) !== 16) {
            return ['ok' => false, 'error' => 'fk_err_nik_input', 'changed' => []];
        }
        if ($nitku !== '' && strlen($nitku) !== 22) {
            return ['ok' => false, 'error' => 'fk_err_nitku_input', 'changed' => []];
        }
        $after = [
            'tax_npwp' => $npwp !== '' ? self::npwp16($npwp) : null, 'tax_nik' => $nik ?: null,
            'tax_name' => trim((string) ($in['tax_name'] ?? '')) ?: null, 'tax_address' => trim((string) ($in['tax_address'] ?? '')) ?: null,
            'tax_nitku' => $nitku ?: null, 'tax_email' => trim((string) ($in['tax_email'] ?? '')) ?: null,
        ];
        $changed = [];
        foreach ($after as $k => $v) {
            if ((string) ($before[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = [(string) ($before[$k] ?? ''), (string) ($v ?? '')];
            }
        }
        if ($changed) {
            $pdo->prepare("UPDATE customers SET tax_npwp = ?, tax_nik = ?, tax_name = ?, tax_address = ?, tax_nitku = ?, tax_email = ?,
                                  tax_updated_by = ?, tax_updated_at = datetime('now','localtime') WHERE id = ?")
                ->execute([$after['tax_npwp'], $after['tax_nik'], $after['tax_name'], $after['tax_address'], $after['tax_nitku'], $after['tax_email'], $by, $customerId]);
        }
        return ['ok' => true, 'error' => '', 'changed' => $changed];
    }

    /**
     * One-off: buyer tax data from a filled Coretax template (Faktur sheet:
     * Referensi = invoice number) or any sheet whose header has an invoice
     * number column and an NPWP column. Fills only customers whose tax data
     * is still empty (finance's later edits win). Dry run unless $apply.
     */
    public static function import_customers(PDO $pdo, array $sheet, bool $apply, string $by): array
    {
        $idx = null;
        $rep = ['header' => [], 'rows' => 0, 'matched' => 0, 'unmatched' => [], 'updates' => [], 'conflicts' => [], 'skipped_filled' => 0, 'applied' => 0];
        $want = [
            'ref'     => ['referensi', 'no. invoice', 'nomor invoice', 'no invoice', 'invoice'],
            'id'      => ['npwp/nik pembeli', 'npwp pembeli', 'npwp', 'npwp/nik'],
            'type'    => ['jenis id pembeli'],
            'name'    => ['nama pembeli', 'nama client', 'nama customer'],
            'address' => ['alamat pembeli', 'alamat'],
            'nitku'   => ['id tku pembeli', 'nitku'],
            'email'   => ['email pembeli'],
        ];
        $byCustomer = [];
        $invoices = [];
        foreach ($pdo->query('SELECT i.invoice_no, o.customer_id FROM invoices i JOIN orders o ON o.id = i.order_id WHERE o.customer_id IS NOT NULL') as $r) {
            $invoices[self::inv_key((string) $r['invoice_no'])] = (int) $r['customer_id'];
        }
        foreach ($sheet as $line) {
            $cells = array_map(fn($v) => is_string($v) ? trim($v) : $v, $line);
            if ($idx === null) {
                $names = array_map(fn($v) => strtolower(trim((string) $v)), $cells);
                $found = [];
                foreach ($want as $k => $alts) {
                    foreach ($alts as $a) {
                        if (($p = array_search($a, $names, true)) !== false) {
                            $found[$k] = $p;
                            break;
                        }
                    }
                }
                if (isset($found['ref'], $found['id'])) {
                    $idx = $found;
                    $rep['header'] = array_keys($found);
                }
                continue;
            }
            $get = fn(string $k): string => isset($idx[$k]) ? trim((string) (is_float($cells[$idx[$k]] ?? null) ? number_format((float) $cells[$idx[$k]], 0, '', '') : ($cells[$idx[$k]] ?? ''))) : '';
            $ref = $get('ref');
            if ($ref === '') {
                continue;
            }
            $rep['rows']++;
            $cid = $invoices[self::inv_key($ref)] ?? 0;
            if ($cid === 0) {
                $rep['unmatched'][] = $ref;
                continue;
            }
            $rep['matched']++;
            $id = preg_replace('/\D/', '', $get('id')) ?? '';
            if ($id === '' || trim($id, '0') === '') {
                continue;   // 0000000000000000 = no TIN on that invoice
            }
            $rec = ['tax_npwp' => self::npwp16($id), 'tax_name' => $get('name'), 'tax_address' => $get('address'), 'tax_nitku' => preg_replace('/\D/', '', $get('nitku')) ?? '', 'tax_email' => $get('email')];
            if (isset($byCustomer[$cid]) && $byCustomer[$cid]['tax_npwp'] !== $rec['tax_npwp']) {
                $rep['conflicts'][] = "customer #{$cid}: {$byCustomer[$cid]['tax_npwp']} / {$rec['tax_npwp']}";
                continue;
            }
            $byCustomer[$cid] = $rec;
        }
        $empty = $pdo->prepare("SELECT COALESCE(tax_npwp, '') || COALESCE(tax_nik, '') FROM customers WHERE id = ?");
        foreach ($byCustomer as $cid => $rec) {
            $empty->execute([$cid]);
            if ((string) $empty->fetchColumn() !== '') {
                $rep['skipped_filled']++;
                continue;
            }
            $rep['updates'][] = "customer #{$cid}: {$rec['tax_npwp']} {$rec['tax_name']}";
            if ($apply && $rec['tax_npwp'] !== '') {
                $pdo->prepare("UPDATE customers SET tax_npwp = ?, tax_name = ?, tax_address = ?, tax_nitku = ?, tax_email = ?, tax_updated_by = ?, tax_updated_at = datetime('now','localtime')
                                WHERE id = ? AND COALESCE(tax_npwp, '') = '' AND COALESCE(tax_nik, '') = ''")
                    ->execute([$rec['tax_npwp'], $rec['tax_name'] ?: null, $rec['tax_address'] ?: null, strlen((string) $rec['tax_nitku']) === 22 ? $rec['tax_nitku'] : null, $rec['tax_email'] ?: null, $by, $cid]);
                $rep['applied']++;
            }
        }
        return $rep;
    }

    /** Invoice number as a key: "1301 - AMI - 10 - 26" and "1301 - AMI - INV - 10 - 26" are the same invoice. */
    public static function inv_key(string $no): string
    {
        if (preg_match('/^(\d+)\s*-\s*AMI\s*-\s*(?:INV\s*-\s*)?(\d{1,2})\s*-\s*(\d{2})/i', $no, $m)) {
            return sprintf('%d-%02d-%s', $m[1], $m[2], $m[3]);
        }
        return strtoupper(preg_replace('/\s+/', '', $no) ?? $no);
    }
}
