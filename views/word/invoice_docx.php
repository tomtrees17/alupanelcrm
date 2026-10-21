<?php
/**
 * Invoice as a real .docx body (see app/Docx.php). Mirrors views/print/invoice.php,
 * the layout matched to the company's own invoice template, plus the "Say"
 * amount-in-words line. English/Indonesian only: exported documents carry no
 * Chinese (no_cjk rule).
 *
 * @var array  $invoice
 * @var array  $items
 * @var string $orderNo
 * @var array  $cfg
 * @var Docx   $doc
 * Sets $body (WordprocessingML for <w:body>).
 */
$disp = invoice_display($invoice, $cfg);    // Pembulatan folded into VAT by default
$subtotal = $disp['subtotal'];
$dpp = $disp['dpp'];
$vat = $disp['vat'];
$total = $disp['total'];
$dec = $disp['decimals'];                  // 2 for Coretax-calculated invoices
$fdate = fn($d) => $d ? date('j/M/y', strtotime((string) $d)) : '';
$fdate2 = fn($d) => $d ? date('j-M-y', strtotime((string) $d)) : '';
$rp = fn($n) => 'Rp' . num($n, $dec);
$W = $doc->width();                       // 10546 twips on A4 with 12 mm sides

$body = '';
if (invoice_is_void($invoice)) {
    $body .= Docx::p('VOID / BATAL', ['align' => 'center', 'b' => true, 'size' => 18, 'color' => 'CC0000', 'border' => true, 'border_color' => 'CC0000', 'after' => 6, 'spacing' => 60]);
}

// ── Header: logo | address box | "Invoice" ──
$logoRun = '';
foreach (['logo-print.png', 'logo-print.jpg'] as $cand) {
    $path = dirname(__DIR__, 2) . '/public/assets/img/' . $cand;
    if (is_file($path)) {
        $logoRun = $doc->image($path, 50, 14);
        break;
    }
}
$logoW = $logoRun !== '' ? 3000 : 0;
$titleW = 2300;
$header = [];
if ($logoRun !== '') {
    $header[] = ['xml' => Docx::p($logoRun, ['raw' => true]), 'valign' => 'center'];
}
$header[] = ['xml' => Docx::p((string) $cfg['company_addr'], ['size' => 8.5]), 'borders' => true, 'valign' => 'center'];
$header[] = ['xml' => Docx::p('Invoice', ['align' => 'right', 'b' => true, 'size' => 22, 'font' => 'Georgia']), 'valign' => 'center'];
$body .= Docx::table(array_values(array_filter([$logoW, $W - $logoW - $titleW, $titleW])), [$header]);
$body .= Docx::p('', ['size' => 4, 'after' => 4]);

// ── Bill to | meta ──
$billTo = Docx::p('Bill To :', ['color' => '555555', 'size' => 9.5])
    . Docx::p((string) ($invoice['bill_to_name'] ?: $invoice['customer']), ['b' => true])
    . Docx::p((string) $invoice['address'], ['size' => 9.5])
    . ($invoice['npwp'] ? Docx::p('NPWP : ' . $invoice['npwp'], ['size' => 9.5]) : '');
$metaW = 5000;
$c = fn(string $t, bool $b = false) => ['text' => $t, 'align' => 'center', 'b' => $b, 'size' => 9];
$l = fn(string $t) => ['text' => $t, 'size' => 9];
$meta = Docx::table([$metaW / 2, $metaW / 2], [
    [$c('Sales Invoice Date', true), $c('Sales Invoice No.', true)],
    [$c($fdate($invoice['invoice_date'])), $c((string) $invoice['invoice_no'])],
    [$l('No CO / PO'), $c((string) ($orderNo ?: $invoice['no_po']))],
    [$l('Job NO'), $c('Currency ' . ($invoice['currency'] ?: 'IDR'))],
    [$l('Due Date'), $c($fdate2($invoice['due_date']))],
], ['borders' => true]);
$body .= Docx::table([$W - $metaW, $metaW], [[['xml' => $billTo], ['xml' => $meta]]], ['pad' => 0]);
$body .= Docx::p('', ['size' => 4, 'after' => 6]);

// ── Items ──
$cols = [650, $W - 650 - 1250 - 1900 - 2000, 1250, 1900, 2000];
$hdr = fn(string $t) => ['text' => $t, 'align' => 'center', 'b' => true, 'size' => 9];
$rows = [[$hdr('No.'), $hdr('Item Description'), $hdr('Quantity'), $hdr('Unit Price'), $hdr('Amount')]];
foreach ($items as $i => $it) {
    $spec = no_cjk((string) $it['spec']);
    $color = no_cjk((string) $it['color']);
    $size = no_cjk((string) $it['size']);
    $desc = trim(($spec !== '' ? '(' . $spec . ') ' : '') . $it['sku'] . ($color !== '' ? ' (' . $color . ')' : '') . ($size !== '' ? ' ' . $size : ''));
    $rows[] = [
        ['text' => (string) ($i + 1), 'align' => 'center', 'size' => 9.5],
        ['text' => $desc, 'size' => 9.5],
        ['text' => num($it['qty']), 'align' => 'center', 'size' => 9.5],
        ['text' => num($it['price'], 2), 'align' => 'right', 'size' => 9.5],
        ['text' => num((float) $it['qty'] * (float) $it['price'], $dec), 'align' => 'right', 'size' => 9.5],
    ];
}
if ((float) $invoice['shipping_cost'] > 0) {
    $rows[] = [
        ['text' => (string) (count($items) + 1), 'align' => 'center', 'size' => 9.5],
        ['text' => 'Ongkir', 'size' => 9.5],
        ['text' => '1', 'align' => 'center', 'size' => 9.5],
        ['text' => num($invoice['shipping_cost'], 2), 'align' => 'right', 'size' => 9.5],
        ['text' => num($invoice['shipping_cost'], $dec), 'align' => 'right', 'size' => 9.5],
    ];
}
$body .= Docx::table($cols, $rows, ['borders' => true]);
$body .= Docx::p('', ['size' => 4, 'after' => 6]);

// ── Say | totals ──
$totW = 4600;
$k = fn(string $t, bool $b = false, bool $rule = false) => ['text' => $t, 'size' => 9.5, 'b' => $b, 'top_rule' => $rule];
$v = fn(string $t, bool $b = false, bool $rule = false) => ['text' => $t, 'size' => 9.5, 'align' => 'right', 'b' => $b, 'top_rule' => $rule];
$tot = [[$k('Subtotal'), $v($rp($subtotal))], [$k('DPP'), $v($rp($dpp))], [$k('VAT 12%'), $v($rp($vat))]];
if ($disp['rounding_line'] !== null) {
    $tot[] = [$k('Pembulatan'), $v(rp_signed($disp['rounding_line']))];
}
$tot[] = [$k('Total Invoice'), $v($rp($disp['invoice_total']))];
$tot[] = [$k('Total Amount', true, true), $v($rp($total), true, true)];
$body .= Docx::table([$W - $totW, $totW], [[
    ['xml' => Docx::p('Say : ' . terbilang($total), ['i' => true, 'size' => 9])],
    ['xml' => Docx::table([$totW / 2, $totW / 2], $tot, ['pad' => 40])],
]], ['pad' => 0]);
$body .= Docx::p('', ['size' => 6, 'after' => 10]);

// ── Bank details | signature ──
$bank = Docx::p('Please deposit above amount to our account', ['b' => true, 'size' => 9, 'after' => 4]);
$bankRow = fn(string $a, string $b) => [['text' => $a, 'size' => 8.5], ['text' => ': ' . $b, 'size' => 8.5]];
foreach ($cfg['banks'] as $bk) {
    $bank .= Docx::table([1700, 4400], [
        $bankRow('Bank Name', (string) $bk['name']),
        $bankRow('Branch', (string) $bk['branch']),
        $bankRow('Account Name', (string) $bk['account_name']),
        $bankRow('Account No', (string) $bk['account_no']),
        $bankRow('Swift Code', (string) $bk['swift']),
    ], ['pad' => 30]);
    $bank .= Docx::p('', ['size' => 4, 'after' => 4]);
}
$sign = Docx::p('On Your Behalf', ['align' => 'center', 'size' => 9.5, 'after' => 54])
    . Docx::p((string) $cfg['signer_title'], ['align' => 'center', 'size' => 9.5]);
$body .= Docx::table([$W - 4000, 4000], [[['xml' => $bank], ['xml' => $sign]]], ['pad' => 0]);
