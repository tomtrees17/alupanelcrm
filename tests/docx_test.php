<?php

// ── Invoice Word export as a real .docx (app/Docx.php + views/word/invoice_docx.php) ──
// Finance reported the old HTML-as-.doc export came out misaligned and without the logo.

$cfgReal = (array) (@include __DIR__ . '/../config.php');
$GLOBALS['config'] = array_merge($GLOBALS['config'], $cfgReal);
$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$tmp = new PDO('sqlite::memory:');
$tmp->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
$cols = array_fill_keys(array_column($tmp->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC), 'name'), '');
$invoice = array_merge($cols, [
    'id' => 9, 'invoice_no' => '11752 - AMI - INV - 10 - 26', 'customer' => 'Ibu Dwi', 'bill_to_name' => 'Ibu Dwi & Co <Toko>',
    'address' => "Jl. Palmerah Barat No. 322\nJakarta Selatan", 'npwp' => '01.234.567.8-901.000',
    'subtotal' => 1351352, 'ppn' => 148649, 'rounding' => -1, 'total' => 1500000, 'shipping_cost' => 0,
    'invoice_date' => '2026-10-21', 'due_date' => '2026-10-21', 'currency' => 'IDR', 'voided_at' => null,
]);
$items = [
    ['sku' => 'SJ8033', 'spec' => '3.0*0.06背涂', 'color' => 'Light Coffee 浅咖', 'size' => '1.220 x 2.440', 'qty' => 5, 'unit' => 'Unit', 'price' => 135135.14],
    ['sku' => 'SJ8038', 'spec' => '3.0*0.06', 'color' => 'Black', 'size' => '1.220 x 2.440', 'qty' => 1, 'unit' => 'Unit', 'price' => 135135.14],
];
$render = function (array $invoice, array $items): array {
    $doc = new Docx();
    $body = '';
    $orderNo = '0468/AMI-CO/10/26';
    $cfg = $GLOBALS['config'];
    include __DIR__ . '/../views/word/invoice_docx.php';
    return [$doc, $body, $doc->build($body)];
};
[$doc, $body, $bin] = $render($invoice, $items);

// 1) A valid package.
$f = tempnam(sys_get_temp_dir(), 'docxt');
file_put_contents($f, $bin);
$zip = new ZipArchive();
ok('it is a zip (.docx container)', $zip->open($f) === true);
$parts = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $parts[] = $zip->getNameIndex($i);
}
foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml', 'word/_rels/document.xml.rels'] as $need) {
    if (!in_array($need, $parts, true)) {
        ok("package has {$need}", false);
    }
}
ok('package has every required part', true);
$bad = [];
foreach ($parts as $p) {
    if (str_ends_with($p, '.xml') || str_ends_with($p, '.rels')) {
        $d = new DOMDocument();
        if (!@$d->loadXML((string) $zip->getFromName($p))) {
            $bad[] = $p;
        }
    }
}
ok('every XML part is well-formed', $bad === [], implode(',', $bad));

// 2) The logo travels inside the file.
$logo = __DIR__ . '/../public/assets/img/logo-print.png';
if (is_file($logo)) {
    ok('logo embedded as word/media/image1.png', in_array('word/media/image1.png', $parts, true) && $zip->getFromName('word/media/image1.png') === file_get_contents($logo));
    $rels = (string) $zip->getFromName('word/_rels/document.xml.rels');
    ok('logo relationship and drawing reference match', str_contains($rels, 'Id="rIdImg1"') && str_contains($rels, 'media/image1.png') && str_contains($body, 'r:embed="rIdImg1"'));
    preg_match('/<wp:extent cx="(\d+)" cy="(\d+)"/', $body, $m);
    ok('logo fits 50 × 14 mm and keeps its aspect ratio', (int) $m[1] <= 50 * 36000 && (int) $m[2] <= 14 * 36000 && abs(((int) $m[1] / (int) $m[2]) - 524 / 112) < 0.05, json_encode($m));
}
$zip->close();
@unlink($f);

// 3) Content.
$text = html_entity_decode(strip_tags(str_replace(['</w:t>', '<w:br/>'], [' ', ' '], $body)), ENT_QUOTES | ENT_XML1);
foreach (['Invoice', '11752 - AMI - INV - 10 - 26', '0468/AMI-CO/10/26', 'Subtotal', 'DPP', 'VAT 12%', 'Pembulatan', '-Rp1', 'Total Amount', 'Rp1.500.000', 'Satu Juta Lima Ratus', 'Bank Name', 'On Your Behalf', 'Ibu Dwi & Co <Toko>', 'NPWP : 01.234.567.8-901.000', '135.135,14'] as $needle) {
    if (!str_contains($text, $needle)) {
        ok("document contains {$needle}", false, $needle);
    }
}
ok('all invoice fields are present (numbers, totals, bank, signature)', true);
ok('special characters are escaped, not breaking the XML', str_contains($body, 'Ibu Dwi &amp; Co &lt;Toko&gt;'));
ok('no Chinese in the exported document (no_cjk rule)', preg_match('/[\x{4e00}-\x{9fff}]/u', $text) === 0, $text);
ok('Arial set on every text run (some viewers ignore defaults)', substr_count($body, '<w:r>') - substr_count($body, '<w:r><w:drawing>') === substr_count($body, 'w:ascii='));

// 4) Layout is fixed, inside the page, and in schema order (Word is strict about it).
ok('tables use fixed layout', substr_count($body, '<w:tbl>') === substr_count($body, '<w:tblLayout w:type="fixed"/>'));
preg_match_all('/<w:tblW w:w="(\d+)"/', $body, $tw);
ok('no table is wider than the printable width', max(array_map('intval', $tw[1])) <= $doc->width(), implode(',', $tw[1]));
ok('items table spans the full printable width', in_array((string) $doc->width(), $tw[1], true));
ok('tblPr children in schema order (tblW → tblBorders → tblLayout → tblCellMar)', preg_match('#<w:tblLayout[^>]*/>.*?<w:tblW #', preg_replace('#<w:tbl>#', "\n<w:tbl>", $body)) === 0
    && !preg_match('#<w:tblLayout w:type="fixed"/><w:tblBorders>#', $body));
ok('pPr children in schema order (pBdr before spacing)', !preg_match('#<w:spacing [^>]*/><w:pBdr>#', $body));
ok('every table cell ends with a paragraph', preg_match('#</w:tbl></w:tc>#', $body) === 0);

// 5) A voided invoice is stamped; shipping shows as a line.
[, $vbody] = $render(array_merge($invoice, ['voided_at' => '2026-10-21 10:00:00', 'shipping_cost' => 250000]), $items);
ok('voided invoice carries the VOID / BATAL stamp', str_contains($vbody, 'VOID / BATAL'));
ok('shipping appears as an Ongkir line', str_contains($vbody, '>Ongkir<'));
ok('live invoice has no stamp', !str_contains($body, 'VOID / BATAL'));

// 6) Decimal input on the order form (finance could not type "12,5" / "12.5").
$form = (string) file_get_contents(__DIR__ . '/../views/orders/form.php');
ok('order unit price accepts decimals', preg_match('/class="form-input price right" type="number" step="any"/', $form) === 1);
ok('order shipping cost accepts decimals', preg_match('/type="number" step="any"[^>]*name="shipping_cost"/', $form) === 1);
ok('order quantity stays whole sheets (stock is counted in whole sheets)', preg_match('/class="form-input qty right" type="number" step="1"/', $form) === 1);
$fin = (string) file_get_contents(__DIR__ . '/../views/finance/show.php');
ok('payment amount accepts decimals', preg_match('/type="number" step="any"[^>]*name="amount"/', $fin) === 1);
