<?php

// ── FA-TAX-01: Coretax output-tax import file (app/Faktur.php) ──
// Target format: samples_test/Coretax_Faktur_Template_v1.6.1_SAMPLE.xlsx. All buyers are fictional.

$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$cfgFull = (array) (@include __DIR__ . '/../config.php');
$cfg = ['coretax' => $cfgFull['coretax'], 'coretax_decimals' => 'truncate', 'company_npwp' => '0123456789012345', 'company_nitku' => ''];
$store = sys_get_temp_dir() . '/fk-store-' . bin2hex(random_bytes(4));
Faktur::$storeDir = $store;
$sample = __DIR__ . '/../samples_test/Coretax_Faktur_Template_v1.6.1_SAMPLE.xlsx';

// Fixture: customers with and without tax ids, invoices built the way orders.php builds them.
$cust = function (string $name, string $company = '', array $tax = []) use ($pdo): int {
    $pdo->prepare('INSERT INTO customers (name, company, tax_npwp, tax_nik, tax_name, tax_address) VALUES (?,?,?,?,?,?)')
        ->execute([$name, $company, $tax['npwp'] ?? null, $tax['nik'] ?? null, $tax['name'] ?? null, $tax['address'] ?? null]);
    return (int) $pdo->lastInsertId();
};
$n = 2000;
$inv = function (int $cid, array $items, string $date, float $shipping = 0, string $clientType = 'End User', string $address = 'Jl. Contoh 9, Bekasi') use ($pdo, &$n): int {
    $amt = invoice_amounts_coretax($items, $shipping, 'truncate');
    $n++;
    $pdo->prepare("INSERT INTO orders (order_no, customer_id, customer_name, status, client_type) VALUES (?,?,?, 'approved', ?)")->execute(["{$n}/AMI-CO/10/26", $cid, 'x', $clientType]);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO invoices (invoice_no, order_id, customer, bill_to_name, address, shipping_cost, subtotal, ppn, rounding, total, calc, dpp, invoice_date, payment_status)
                   VALUES (?,?,?,?,?,?,?,?,?,?,'coretax',?,?,'pending')")
        ->execute(["{$n} - AMI - INV - 10 - 26", $oid, 'x', 'x', $address, $amt['pretax_ship'], $amt['subtotal'], $amt['ppn'], $amt['rounding'], $amt['total'], $amt['dpp'], $date]);
    $id = (int) $pdo->lastInsertId();
    foreach ($items as $k => $it) {
        $pdo->prepare('INSERT INTO invoice_items (invoice_id, sku, color, spec, size, qty, unit, price) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $it['sku'], $it['color'], $it['spec'], '', $it['qty'], 'Lembar', $amt['units'][$k]]);
    }
    return $id;
};
$item = fn(string $sku, string $color, string $spec, float $qty, float $price): array => ['sku' => $sku, 'color' => $color, 'spec' => $spec, 'qty' => $qty, 'price' => $price];
$cNpwp = $cust('Contoh Reklame', 'PT CONTOH REKLAME', ['npwp' => '01.234.567.8-901.000', 'name' => 'PT CONTOH REKLAME', 'address' => 'JL CONTOH NO 2, BEKASI']);   // old 15-digit NPWP
$cNik = $cust('Andi Contoh', '', ['nik' => '3200000000000001']);
$cPt = $cust('Sinar Contoh', 'PT SINAR CONTOH');         // company without tax id → TBC
$cDist = $cust('Toko Contoh', 'Toko Contoh');             // ordered as Distributor → TBC
$cRetail = $cust('Bu Wati', '');                          // retail → GUNGGUNG
$cBad = $cust('Salah Contoh', 'CV SALAH', ['npwp' => '12345']);
$iMulti = $inv($cNpwp, [$item('SJ8059', 'Royal Blue', '4mm x 0.21', 5, 300000), $item('SJ8001', 'Pure White', '3mm x 0.10', 8, 195000), $item('SJ8019', 'Silver', '3mm x 0.10', 10, 210000)], '2026-10-05', 150000);
$iNik = $inv($cNik, [$item('SJ8001', 'Pure White', '3mm x 0.10', 3, 195000)], '2026-10-06');
$iPt = $inv($cPt, [$item('SJ8019', 'Silver', '4mm x 0.21', 2, 310000)], '2026-10-07');
$iDist = $inv($cDist, [$item('SJ8019', 'Silver', '4mm x 0.21', 1, 310000)], '2026-10-08', 0, 'Distributor');
$iRetail = $inv($cRetail, [$item('SJ8001', 'Pure White', '3mm x 0.10', 1, 195000)], '2026-10-09');
$iBad = $inv($cBad, [$item('SJ8001', 'Pure White', '3mm x 0.10', 1, 195000)], '2026-10-10');
$iOther = $inv($cNpwp, [$item('SJ8001', 'Pure White', '3mm x 0.10', 1, 195000)], '2026-11-02');   // outside the period

// 1) Ids and classes.
ok('NPWP: 16 digits kept, an old 15-digit number gets a leading 0, anything else is not usable',
    Faktur::npwp16('0123456789012345') === '0123456789012345' && Faktur::npwp16('01.234.567.8-901.000') === '0012345678901000' && Faktur::npwp16('12345') === '');
ok('seller NITKU defaults to NPWP + 000000', Faktur::seller_nitku(Faktur::settings($cfg)) === '0123456789012345000000');
$rv = Faktur::review($pdo, $cfg, '2026-10-01', '2026-10-31');
$byId = [];
foreach ($rv['rows'] as $r) {
    $byId[(int) $r['id']] = $r;
}
ok('classes: NPWP or NIK → FAKTUR; company or Distributor without id → TBC; retail → GUNGGUNG',
    $byId[$iMulti]['class'] === 'FAKTUR' && $byId[$iNik]['class'] === 'FAKTUR' && $byId[$iPt]['class'] === 'TBC' && $byId[$iDist]['class'] === 'TBC' && $byId[$iRetail]['class'] === 'GUNGGUNG');
ok('the period filter applies (November invoice not listed)', !isset($byId[$iOther]) && count($rv['rows']) === 6);
ok('NIK buyer: the NIK goes in as TIN (finance\'s practice)', Faktur::buyer_id($byId[$iNik]) === ['id' => '3200000000000001', 'type' => 'TIN', 'doc' => '-', 'nitku' => '3200000000000001000000']);
ok('blocker: NPWP with the wrong number of digits', $byId[$iBad]['blockers'] === ['fk_err_npwp_len:5']);
ok('a good FAKTUR invoice has no blocker', $byId[$iMulti]['blockers'] === [] && $byId[$iNik]['blockers'] === []);
ok('TBC and GUNGGUNG never block the file', $byId[$iPt]['blockers'] === [] && $byId[$iRetail]['blockers'] === []);
$gen = Faktur::generate($pdo, $cfg, '2026-10-01', '2026-10-31', 'Finance');
ok('generate refused while a FAKTUR invoice is blocked, with the reason', !$gen['ok'] && $gen['errors'] === ["2006 - AMI - INV - 10 - 26 · fk_err_npwp_len:5"]);
ok('generate refused without the company NPWP', in_array('fk_err_seller', Faktur::generate($pdo, ['company_npwp' => ''] + $cfg, '2026-10-01', '2026-10-31', 'F')['errors'], true));
$pdo->exec("UPDATE invoices SET faktur_class = 'GUNGGUNG' WHERE id = {$iBad}");   // finance moves it aside
$noAddr = $byId[$iNik];
$noAddr['address'] = '';
ok('blocker: address empty', in_array('fk_err_address', Faktur::blockers($noAddr, $byId[$iNik]['lines']), true));
$wrong = $byId[$iNik];
$wrong['ppn'] = (float) $wrong['ppn'] + 5;
ok('blocker: amounts that do not match the invoice', str_starts_with(Faktur::blockers($wrong, $wrong['lines'])[0] ?? '', 'fk_err_amounts'));

// 2) The file (acceptance ①–③).
$gen = Faktur::generate($pdo, $cfg, '2026-10-01', '2026-10-31', 'Finance');
ok('generated for the FAKTUR invoices', $gen['ok'] && $gen['invoices'] === 2 && $gen['lines'] === 5, json_encode($gen));
$exp = $pdo->query("SELECT * FROM faktur_exports WHERE id = {$gen['id']}")->fetch();
$file = $store . '/' . $exp['stored'];
ok('kept privately under data/faktur (test dir), named by period', is_file($file) && $exp['file_name'] === 'Faktur_PK_20261001_20261031.xlsx');
$read = fn(string $f, string $sheet): array => SalesHistory::read_xlsx($f, $sheet);
$sheets = function (string $f): array {
    $z = new ZipArchive();
    $z->open($f);
    preg_match_all('/<sheet [^>]*name="([^"]+)"/', (string) $z->getFromName('xl/workbook.xml'), $m);
    $z->close();
    return $m[1];
};
ok('① same sheets, same order as DJP\'s template', $sheets($file) === $sheets($sample) && $sheets($file) === ['Faktur', 'DetailFaktur', 'REF-General', 'REF-KetTambahan', 'REF-CapFasilitas', 'REF-KodeNegara', 'Keterangan', 'Rilis note']);
ok('① Faktur header: same names, same order', $read($file, 'Faktur')[1] === $read($sample, 'Faktur')[1]);
ok('① DetailFaktur header: same names, same order', $read($file, 'DetailFaktur')[0] === $read($sample, 'DetailFaktur')[0]);
$sameRefs = true;
foreach (['REF-General', 'REF-KetTambahan', 'REF-CapFasilitas', 'REF-KodeNegara', 'Keterangan', 'Rilis note'] as $sh) {
    $sameRefs = $sameRefs && $read($file, $sh) === $read($sample, $sh);
}
ok('① reference sheets identical to the template', $sameRefs);
$zf = new ZipArchive();
$zf->open($file);
$zs = new ZipArchive();
$zs->open($sample);
ok('① column notes (comments) and validations kept', $zf->getFromName('xl/comments/comment1.xml') === $zs->getFromName('xl/comments/comment1.xml')
    && str_contains((string) $zf->getFromName('xl/worksheets/sheet1.xml'), '<formula1>"Normal"</formula1>') && str_contains((string) $zf->getFromName('xl/worksheets/sheet2.xml'), 'type="whole"'));
$zf->close();
$zs->close();
$f = $read($file, 'Faktur');
ok('seller NPWP in C1, as text', $f[0][2] === '0123456789012345');
$row1 = $f[2];
ok('Faktur row: Baris, DD/MM/YYYY, Normal, 04, Referensi = invoice number, seller NITKU', $row1[0] === 1.0 && $row1[1] === '05/10/2026' && $row1[2] === 'Normal' && $row1[3] === '04'
    && $row1[7] === '2001 - AMI - INV - 10 - 26' && $row1[9] === '0123456789012345000000');
ok('Faktur row: buyer TIN with its leading zero, IDN, "-", registered name and address, NITKU', $row1[10] === '0012345678901000' && $row1[11] === 'TIN' && $row1[12] === 'IDN'
    && $row1[13] === '-' && $row1[14] === 'PT CONTOH REKLAME' && $row1[15] === 'JL CONTOH NO 2, BEKASI' && $row1[17] === '0012345678901000000000');
ok('Faktur rows: one per FAKTUR invoice, Baris 1..n', count(array_filter(array_slice($f, 2), fn($r) => ($r[0] ?? null) !== null)) === 2 && $f[3][0] === 2.0);
$d = array_slice($read($file, 'DetailFaktur'), 1);
$baris1 = array_values(array_filter($d, fn($r) => $r[0] === 1.0));
ok('③ an invoice with 3 SKUs (+ delivery) → 4 DetailFaktur rows, all Baris 1', count($baris1) === 4 && count($d) === 5);
ok('detail row: A / 760600 / name like the printed invoice / UM.0021 / tarif 12 / no PPnBM', $d[0][1] === 'A' && $d[0][2] === '760600' && $d[0][3] === '(4mm x 0.21) SJ8059 (Royal Blue)'
    && $d[0][4] === 'UM.0021' && $d[0][10] === 12.0 && $d[0][12] === 0.0 && $d[0][13] === 0.0 && $d[0][7] === 0.0);
ok('detail row: Harga Satuan = 300,000 ÷ 1.11 to the sen; DPP = qty × price', $d[0][5] === 270270.27 && $d[0][6] === 5.0 && $d[0][8] === 1351351.35);
ok('detail row: DPP Nilai Lain = DPP × 11/12, PPN = 12% of it (Coretax: 2 decimals, cut)', $d[0][9] === 1238738.73 && $d[0][11] === 148648.64);
ok('delivery charged → its own line (B, Ongkos Kirim, 150,000 ÷ 1.11)', $baris1[3][1] === 'B' && $baris1[3][3] === 'Ongkos Kirim' && abs($baris1[3][5] - 135135.13) < 0.001 && $baris1[3][6] === 1.0);
$ppnFile = [];
foreach ($d as $r) {
    $ppnFile[(int) $r[0]] = ($ppnFile[(int) $r[0]] ?? 0) + $r[11];
}
$ppnInv = (float) $pdo->query("SELECT ppn FROM invoices WHERE id = {$iMulti}")->fetchColumn();
ok('② PPN in the file = the invoice PPN (within Rp 1 per line)', abs($ppnFile[1] - $ppnInv) <= 4 * 1.0, $ppnFile[1] . ' vs ' . $ppnInv);
ok('② …and for the one-line invoice too', abs($ppnFile[2] - (float) $pdo->query("SELECT ppn FROM invoices WHERE id = {$iNik}")->fetchColumn()) <= 1.0);
ok('② export total = Σ line PPN', abs((float) $exp['ppn_total'] - array_sum($ppnFile)) < 0.005);

// Regenerating the period marks the old file.
$gen2 = Faktur::generate($pdo, $cfg, '2026-10-01', '2026-10-31', 'Finance');
ok('same period again: new file, the earlier one marked superseded (kept)', $gen2['ok'] && $pdo->query("SELECT superseded_at FROM faktur_exports WHERE id = {$gen['id']}")->fetchColumn() !== null
    && $pdo->query("SELECT superseded_at FROM faktur_exports WHERE id = {$gen2['id']}")->fetchColumn() === null && is_file($file));

// GUNGGUNG list.
$g = Faktur::gunggung($pdo, $cfg, '2026-10-01', '2026-10-31', 'Finance');
$gf = $store . '/' . $pdo->query("SELECT stored FROM faktur_exports WHERE id = {$g['id']}")->fetchColumn();
$gr = SalesHistory::read_xlsx($gf);
ok('Digunggung list: retail invoices (incl. the one finance moved), with a total row', $g['invoices'] === 2 && $gr[0][0] === 'No. Invoice' && count($gr) === 4 && $gr[3][0] === 'TOTAL'
    && abs($gr[3][5] - ((float) $pdo->query("SELECT SUM(ppn) FROM invoices WHERE id IN ({$iRetail}, {$iBad})")->fetchColumn())) < 0.005);

// 3) Buyer data kept by finance.
$r = Faktur::save_customer($pdo, $cPt, ['tax_npwp' => '1234', 'tax_name' => 'x'], 'Finance');
ok('save: a malformed NPWP is refused', !$r['ok'] && $r['error'] === 'fk_err_npwp_input');
$r = Faktur::save_customer($pdo, $cPt, ['tax_npwp' => '02.345.678.9-012.000', 'tax_name' => 'PT SINAR CONTOH', 'tax_address' => 'JL CONTOH 3'], 'Finance');
ok('save: NPWP stored as 16 digits, changes listed', $r['ok'] && $pdo->query("SELECT tax_npwp FROM customers WHERE id = {$cPt}")->fetchColumn() === '0023456789012000' && isset($r['changed']['tax_npwp']));
$rv2 = Faktur::review($pdo, $cfg, '2026-10-01', '2026-10-31');
ok('the TBC invoice becomes FAKTUR once finance adds the NPWP', count(array_filter($rv2['rows'], fn($x) => (int) $x['id'] === $iPt && $x['class'] === 'FAKTUR')) === 1);

// 4) Import from a filled Coretax template (the fixture's Faktur rows: Referensi 1100 / 1101 - AMI - INV - 08 - 26).
$cA = $cust('Import A', '');
$cB = $cust('Import B', '', ['npwp' => '9999999999999999']);
foreach ([[$cA, '1100 - AMI - INV - 08 - 26'], [$cB, '1101 - AMI - INV - 08 - 26']] as [$c, $no]) {
    $pdo->prepare("INSERT INTO orders (order_no, customer_id, customer_name, status) VALUES (?,?,'x','approved')")->execute([$no . '-o', $c]);
    $pdo->prepare("INSERT INTO invoices (invoice_no, order_id, customer, total, invoice_date) VALUES (?,?,?,1,'2026-08-18')")->execute([$no, (int) $pdo->lastInsertId(), 'x']);
}
$sheet = SalesHistory::read_xlsx($sample, 'Faktur');
$dry = Faktur::import_customers($pdo, $sheet, false, 'import');
ok('import dry run: columns found by header, both invoices matched', $dry['header'] === ['ref', 'id', 'type', 'name', 'address', 'nitku', 'email'] && $dry['matched'] === 2 && $dry['applied'] === 0
    && (string) $pdo->query("SELECT tax_npwp FROM customers WHERE id = {$cA}")->fetchColumn() === '');
$app = Faktur::import_customers($pdo, $sheet, true, 'import');
ok('import --apply: empty customer filled; one with data already kept as is', $app['applied'] === 1 && $app['skipped_filled'] === 1
    && $pdo->query("SELECT tax_npwp || '|' || tax_name || '|' || tax_nitku FROM customers WHERE id = {$cA}")->fetchColumn() === '3200000000000001|BUDI SANTOSO|3200000000000001000000'
    && $pdo->query("SELECT tax_npwp FROM customers WHERE id = {$cB}")->fetchColumn() === '9999999999999999');
ok('invoice number keys: with or without "INV"', Faktur::inv_key('1301 - AMI - 10 - 26') === Faktur::inv_key('1301 - AMI - INV - 10 - 26'));

// 5) The fixture is clean: only the two fictional buyers, no other 16-digit ids, no author names.
$fx = array_slice($read($sample, 'Faktur'), 2);
$fxRows = array_values(array_filter($fx, fn($r) => ($r[0] ?? null) !== null));
$z = new ZipArchive();
$z->open($sample);
$core = (string) $z->getFromName('docProps/core.xml');
$s1 = (string) $z->getFromName('xl/worksheets/sheet1.xml');
$z->close();
preg_match_all('/\b\d{16}\b/', $s1, $ids);
ok('samples_test Coretax fixture: two fictional buyers only, fictional ids, no author names',
    count($fxRows) === 2 && $fxRows[0][14] === 'BUDI SANTOSO' && $fxRows[1][14] === 'CV KARYA MANDIRI'
    && array_diff(array_unique($ids[0]), ['1000000000000000', '3200000000000001', '1000000000000002']) === []
    && !preg_match('#<dc:creator[^>]*>[^<]+<#', $core) && !preg_match('#<cp:lastModifiedBy>[^<]+<#', $core));

// 6) Upgrade path.
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$old->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$old->exec('DROP TABLE faktur_exports');
foreach (['tax_npwp', 'tax_nik', 'tax_name', 'tax_address', 'tax_nitku', 'tax_email', 'tax_updated_by', 'tax_updated_at'] as $c) {
    $old->exec("ALTER TABLE customers DROP COLUMN {$c}");
}
$old->exec('ALTER TABLE invoices DROP COLUMN faktur_class');
$old->exec("INSERT INTO app_meta (k, v) VALUES ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),('pwd_policy_v1','1'),('perm_approvals','1'),('perm_insights','1'),('perm_insights_v2','1'),('perm_hr_v1','1'),('hr_employees_v1','1'),('perm_ai_usage_v1','1')");
$old->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Admin', 'admin@alupanel.local', 'x', 'admin')");
$old->exec("INSERT INTO customers (name, company) VALUES ('Lama', 'PT LAMA')");
Database::migrate($old);
Database::migrate($old);
$cc = array_column($old->query('PRAGMA table_info(customers)')->fetchAll(), 'name');
ok('migrate: customer tax columns, invoice class and the exports table added, twice is harmless', in_array('tax_npwp', $cc, true) && in_array('tax_nitku', $cc, true)
    && in_array('faktur_class', array_column($old->query('PRAGMA table_info(invoices)')->fetchAll(), 'name'), true)
    && (bool) $old->query("SELECT 1 FROM sqlite_master WHERE name = 'faktur_exports'")->fetchColumn() && $old->query("SELECT name FROM customers")->fetchColumn() === 'Lama');

// 7) Pages.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = sys_get_temp_dir() . '/fk-pages-' . bin2hex(random_bytes(4)) . '.sqlite';
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Database::migrate($p);
    $p->exec("INSERT INTO customers (name, company, tax_npwp, tax_address) VALUES ('Halaman Contoh', 'PT HALAMAN CONTOH', '0123456789012399', 'JL HALAMAN 1')");
    $hc = (int) $p->lastInsertId();
    $p->exec("INSERT INTO orders (order_no, customer_id, customer_name, status) VALUES ('9001/AMI-CO/10/26', {$hc}, 'x', 'approved')");
    $ho = (int) $p->lastInsertId();
    $a = invoice_amounts_coretax([['qty' => 2, 'price' => 300000]], 0, 'truncate');
    $p->exec("INSERT INTO invoices (invoice_no, order_id, customer, address, subtotal, ppn, total, calc, dpp, invoice_date) VALUES ('9001 - AMI - INV - 10 - 26', {$ho}, 'PT HALAMAN CONTOH', 'x', {$a['subtotal']}, {$a['ppn']}, {$a['total']}, 'coretax', {$a['dpp']}, '2026-10-15')");
    $hi = (int) $p->lastInsertId();
    $p->exec("INSERT INTO invoice_items (invoice_id, sku, color, spec, size, qty, unit, price) VALUES ({$hi}, 'SJ8001', 'Pure White', '3mm', '', 2, 'Lembar', {$a['units'][0]})");
    $p = null;
    $run = function (string $action, array $user, array $get = [], ?array $post = null) use ($db, $harness, $store): string {
        $args = [PHP_BINARY, $harness, $db, 'tax', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $out = [];
        exec('TEST_FAKTUR_STORE=' . escapeshellarg($store) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $fin = ['id' => 7, 'name' => 'Fin', 'role' => 'finance_manager'];
    $page = $run('index', $fin, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    ok('Faktur page renders, invoice classed FAKTUR, no notices', str_contains($page, 'data-invoice="9001 - AMI - INV - 10 - 26" data-class="FAKTUR"') && !str_contains($page, 'PHP:'), mb_substr($page, -400));
    ok('Faktur page: the missing company NPWP is shown and generating is disabled', str_contains($page, t('fk_err_seller')) && str_contains($page, 'disabled>' . t('fk_btn_generate')));
    $run('classify', $fin, [], ['id' => $hi, 'class' => 'GUNGGUNG', 'from' => '2026-10-01', 'to' => '2026-10-31']);
    $chk = new PDO('sqlite:' . $db);
    ok('override the class from the page (audited)', $chk->query("SELECT faktur_class FROM invoices WHERE id = {$hi}")->fetchColumn() === 'GUNGGUNG'
        && (int) $chk->query("SELECT COUNT(*) FROM audit_log WHERE module = 'tax' AND action = 'classify'")->fetchColumn() === 1);
    $run('customer_save', $fin, [], ['id' => $hc, 'tax_npwp' => '0123456789012388', 'tax_name' => 'PT HALAMAN', 'tax_address' => 'JL HALAMAN 2']);
    $log = (string) $chk->query("SELECT detail FROM audit_log WHERE module = 'tax' AND action = 'update'")->fetchColumn();
    ok('customer tax data saved; the audit line masks the ids', $chk->query("SELECT tax_npwp FROM customers WHERE id = {$hc}")->fetchColumn() === '0123456789012388'
        && str_contains($log, '••••••••••••2388') && !str_contains($log, '0123456789012388'));
    ok('customer tax page renders', !str_contains($run('customer', $fin, ['id' => $hc]), 'PHP:'));
    $g = $run('gunggung', $fin, [], ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $eid = (int) $chk->query("SELECT id FROM faktur_exports WHERE kind = 'gunggung'")->fetchColumn();
    ok('Digunggung from the page: file kept, export audited', $eid > 0 && (int) $chk->query("SELECT COUNT(*) FROM audit_log WHERE module = 'tax' AND action = 'export'")->fetchColumn() === 1);
    $denied = $run('index', ['id' => 3, 'name' => 'Rina', 'role' => 'sales']);
    ok('no finance module → no Faktur page', str_contains($denied, t('msg_no_module')) && !str_contains($denied, t('fk_sub')));
    $chk = null;
    @unlink($db);
}

array_map('unlink', glob($store . '/*') ?: []);
@rmdir($store);
Faktur::$storeDir = null;
