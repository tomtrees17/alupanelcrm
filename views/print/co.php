<?php
/** @var array $order */ /** @var array $items */ /** @var array $sales */
// Confirmation Order (CO): the quotation / order confirmation the customer signs.
// Mirrors the sheet the sales team used before the platform issued CO numbers.
// Printed documents carry no Chinese (no_cjk rule) — everything here is English / Indonesian.
$cfg = $GLOBALS['config'];
$printLogo = null;
foreach (['logo-print.png', 'logo-print.svg', 'logo-print.jpg'] as $cand) {
    if (is_file(dirname(__DIR__, 2) . '/public/assets/img/' . $cand)) { $printLogo = $cand; break; }
}
$coNo = (string) ($order['co_no'] ?: $order['order_no']);
$bulan = ['JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];
$ts = strtotime((string) $order['created_at']) ?: time();
$dateId = date('j', $ts) . ' ' . $bulan[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
$preTax = ($order['price_basis'] ?? 'incl') === 'excl';
$amt = invoice_amounts_coretax($items, (float) $order['shipping_cost'], 'truncate', $preTax);
$dec = fn(float $v): int => abs($v - round($v)) >= 0.005 ? 2 : 0;
$rp = fn($v) => num((float) $v, $dec((float) $v));
$termLabel = match ((string) $order['payment_term']) {
    'CBD' => 'Cash Before Delivery',
    'COD' => 'Cash On Delivery',
    default => 'Net ' . (int) $order['custom_days'] . ' days',
};
$pickup = (string) $order['delivery_service'];
$franco = stripos($pickup, 'pickup') !== false || stripos($pickup, 'self') !== false ? 'Prices are franco gudang (Self Pickup).' : 'Prices are franco gudang (On Truck).';
$bca = null;
foreach ($cfg['banks'] ?? [] as $bk) {
    if (stripos((string) $bk['name'], 'BCA') !== false) { $bca = $bk; break; }
}
$bca = $bca ?: ($cfg['banks'][0] ?? null);
$goods = 0.0;
foreach ($items as $it) {
    $goods += (float) $it['qty'] * (float) ($preTax ? ($it['price_excl'] ?? $it['price']) : $it['price']);
}
$coFull = preg_replace('/^Pt\b/', 'PT', ucwords(strtolower((string) $cfg['company_full']))) ?? '';
$clientType = strtoupper(no_cjk((string) $order['client_type']));
$clientType = ['CONTRACTOR' => 'APLIKATOR / KONTRAKTOR', 'DISTRIBUTOR' => 'DISTRIBUTOR', 'RETAILER' => 'TOKO / RETAILER', 'END USER' => 'END USER'][$clientType] ?? $clientType;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>CO <?= e($coNo) ?></title>
    <link rel="stylesheet" href="assets/css/print.css">
    <style>
        .co { font-size: 11px; color: #000; }
        .co .head { display: flex; justify-content: space-between; align-items: flex-end; }
        .co .tag { font-size: 10.5px; color: #1d4ed8; line-height: 1.35; margin-top: 2px; }
        .co .coname { font-size: 13px; font-weight: 800; margin-top: 6px; }
        .co .addr { font-size: 9.5px; font-family: Georgia, 'Times New Roman', serif; margin-top: 2px; }
        .co .rule { border-top: 2px solid #000; border-bottom: 1px solid #000; text-align: center; font-weight: 800; letter-spacing: 1px; font-size: 11px; padding: 2px 0; margin: 10px 0 10px; }
        .co table.meta { width: 100%; border-collapse: collapse; }
        .co table.meta td { padding: 1.5px 0; vertical-align: top; font-size: 10.5px; }
        .co table.meta td.k { width: 150px; font-weight: 700; }
        .co table.meta td.v { width: 60%; }
        .co table.meta td.right { text-align: right; }
        .co .gap { height: 8px; }
        .co .letter { font-size: 10.5px; margin: 10px 0 6px; line-height: 1.5; }
        .co table.items { border-collapse: collapse; width: 100%; margin: 4px 0 0; }
        .co table.items th { background: #808080; color: #fff; border: 1px solid #000; font-size: 9.5px; padding: 5px 4px; text-align: center; font-weight: 700; }
        .co table.items td { border-left: 1px solid #000; border-right: 1px solid #000; padding: 4px 5px; font-size: 10px; vertical-align: top; }
        .co table.items td.c { text-align: center; }
        .co table.items td.r { text-align: right; }
        .co table.items td.rp { position: relative; }
        .co table.items td.rp span.cur { float: left; }
        .co table.items tr.pad td { height: 26px; }
        .co table.items tr.tot td { border-top: 1px solid #000; }
        .co table.items tr.tot td.lbl { text-align: right; font-weight: 700; padding-right: 8px; }
        .co table.items tr.last td { border-bottom: 2px solid #000; }
        .co table.items tr.grand td { font-weight: 800; }
        .co .notes { font-size: 10px; color: #000; margin-top: 8px; line-height: 1.5; }
        .co .notes b { display: block; }
        .co .notes .hl { background: #fde047; }
        .co .closing { font-size: 10.5px; margin-top: 10px; line-height: 1.5; }
        .co .signs { display: flex; margin-top: 10px; font-size: 10px; text-align: left; }
        .co .signs > div { width: 25%; }
        .co .signs .who { font-weight: 700; }
        .co .signs .space { height: 58px; }
        .co .signs .nm { font-weight: 700; }
    </style>
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?= url('orders.show', ['id' => $order['id']]) ?>">← <?= t('btn_back') ?></a>
    <a class="btn btn-primary" href="javascript:window.print()"><?= t('btn_print') ?> / Cetak</a>
</div>

<div class="sheet co">
    <div class="head">
        <div>
            <?php if ($printLogo): ?><img src="assets/img/<?= e($printLogo) ?>" alt="ALUSIGNPANEL" style="max-width:150px;max-height:42px"><?php endif; ?>
            <div class="tag">Aluminium Composite Panel<br>Professional Manufacturer Since 2000</div>
            <div class="coname"><?= e($cfg['company_full']) ?></div>
            <div class="addr"><?= e($cfg['company_addr']) ?><?= $cfg['company_phone'] ? ' / Phone: ' . e($cfg['company_phone']) : '' ?></div>
        </div>
    </div>
    <div class="rule">CONFIRMATION ORDER</div>

    <table class="meta">
        <tr><td class="k">No</td><td class="v"><?= e($coNo) ?></td><td class="right">Jakarta <?= e($dateId) ?></td></tr>
        <tr><td colspan="3" class="gap"></td></tr>
        <tr><td class="k">Company of Client</td><td class="v" colspan="2"><?= e(strtoupper(no_cjk((string) ($order['company'] ?: $order['customer_name'])))) ?></td></tr>
        <tr><td class="k">Name of Client</td><td class="v" colspan="2"><?= e(strtoupper(no_cjk((string) $order['customer_name']))) ?></td></tr>
        <tr><td class="k">Client Address</td><td class="v" colspan="2"><?= e(strtoupper(no_cjk((string) $order['address']))) ?></td></tr>
        <tr><td class="k">Client Phone Number</td><td class="v" colspan="2"><?= e((string) $order['phone']) ?></td></tr>
        <tr><td colspan="3" class="gap"></td></tr>
        <tr><td class="k">Type Of Client</td><td class="v" colspan="2"><?= e($clientType) ?></td></tr>
        <tr><td colspan="3" class="gap"></td></tr>
        <tr><td class="k">Pick Up at The Warehouse</td><td class="v" colspan="2"><?= e(no_cjk($pickup)) ?></td></tr>
        <tr><td class="k">Delivery Address</td><td class="v" colspan="2"><b><?= e(strtoupper(no_cjk((string) ($order['delivery_address'] ?: $order['address'])))) ?></b></td></tr>
    </table>

    <div class="letter">
        Dear Sir/Madam,<br><br>
        With reference to the planned construction project, we would like to submit our quotation for the required materials with the following details:
    </div>
    <div style="font-weight:700;font-size:10.5px;margin-bottom:2px">ACP MATERIAL PRICE :</div>

    <table class="items">
        <thead><tr>
            <th style="width:28px">No</th><th style="width:88px">Material</th><th style="width:62px">Type</th><th style="width:62px">Code</th>
            <th style="width:90px">Color</th><th style="width:70px">Size</th><th style="width:46px">Volume</th><th style="width:34px">Unit</th>
            <th style="width:110px">Price per Unit</th><th>Total Price</th>
        </tr></thead>
        <tbody>
        <tr>
            <td class="c">1</td><td><b>* <?= e($cfg['company_logo'] ?: 'ALUSIGNPANEL') ?></b><br>PE - LDPE Core</td>
            <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <?php $totalQty = 0.0; ?>
        <?php foreach ($items as $i => $it): ?>
            <?php
            $unit = $preTax ? (float) ($it['price_excl'] ?? $it['price']) : (float) $it['price'];
            $line = (float) $it['qty'] * $unit;
            $totalQty += (float) $it['qty'];
            $size = no_cjk((string) ($it['size'] ?: '1.220 x 2.440'));
            $size = preg_replace('/[^0-9xX]/', '', str_replace('.', '', $size)) ?: '1220X2440';
            ?>
            <tr>
                <td></td><td></td>
                <td class="c"><?= e(no_cjk((string) $it['spec'])) ?></td>
                <td class="c"><?= e((string) $it['sku']) ?></td>
                <td class="c"><?= e(strtoupper(no_cjk((string) $it['color']))) ?></td>
                <td class="c"><?= e(strtoupper($size)) ?></td>
                <td class="c"><?= num($it['qty']) ?></td>
                <td class="c">LBR</td>
                <td class="r rp"><span class="cur">Rp</span><?= $rp($unit) ?></td>
                <td class="r rp"><span class="cur">Rp</span><?= $rp($line) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php for ($p = count($items); $p < 6; $p++): ?>
            <tr class="pad"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <?php endfor; ?>
        <tr class="tot">
            <td colspan="6" style="border-top:none"></td><td class="c" style="font-weight:700"><?= num($totalQty) ?></td><td></td>
            <td class="lbl">SUB TOTAL</td><td class="r rp"><span class="cur">Rp</span><?= $rp($goods) ?></td>
        </tr>
        <?php if ((float) $order['shipping_cost'] > 0): ?>
            <tr class="tot"><td colspan="6" style="border-top:none;border-bottom:none"></td><td></td><td></td><td class="lbl" style="border-top:none">ONGKIR</td><td class="r rp" style="border-top:none"><span class="cur">Rp</span><?= $rp((float) $order['shipping_cost']) ?></td></tr>
        <?php endif; ?>
        <?php if ($preTax): ?>
            <tr><td colspan="6"></td><td></td><td></td><td class="lbl">DPP</td><td class="r rp"><span class="cur">Rp</span><?= $rp($amt['dpp']) ?></td></tr>
            <tr><td colspan="6"></td><td></td><td></td><td class="lbl">PPN 12%</td><td class="r rp"><span class="cur">Rp</span><?= $rp($amt['ppn']) ?></td></tr>
        <?php else: ?>
            <tr><td colspan="6"></td><td></td><td></td><td class="lbl" colspan="2" style="text-align:right;font-weight:400;font-style:italic">Harga sudah termasuk PPN (prices include VAT)</td></tr>
        <?php endif; ?>
        <tr class="grand last"><td colspan="6"></td><td></td><td></td><td class="lbl">GRAND TOTAL</td><td class="r rp"><span class="cur">Rp</span><?= $rp($amt['total']) ?></td></tr>
        </tbody>
    </table>

    <div class="notes">
        <b>Notes:</b>
        <?= e($franco) ?><br>
        <span class="hl">Payment Terms: <?= e($termLabel) ?>.</span><br>
        Prices are valid for 30 (thirty) days from the quotation date and are subject to the standard <?= e($cfg['company_logo'] ?: 'ALUSIGNPANEL') ?> color catalogue.<br>
        Delivery schedule or material pick-up is H+2 (two) business days after the Purchase Order (PO) is received in full, provided that payment is received before 12:00 PM on any business day.<br>
        <?php if ($bca): ?>Official payments are accepted only to <?= e($bca['name']) ?> Account No.: <?= e($bca['account_no']) ?> (<?= e($bca['account_name']) ?>).<?php endif; ?>
    </div>

    <div class="closing">
        This price confirmation letter is hereby submitted. We look forward to your favorable response.<br>
        Thank you for your attention and the opportunity given to us.
    </div>

    <div class="signs">
        <div>Sincerely,</div>
        <div>Approved By,<br><span class="who"><?= e($coFull) ?></span><div class="space"></div><span class="nm"><?= e(strtoupper(no_cjk((string) ($sales['name'] ?? $order['submitter'])))) ?></span><br><?= e(no_cjk((string) ($sales['title'] ?? '')) ?: 'Sales') ?></div>
        <div>Acknowledge by Client,<br><span class="who">Company of Client</span><div class="space"></div><span class="nm">Name of Client</span><br>(Department of Client)</div>
        <div>Acknowledge By,<br><span class="who"><?= e($coFull) ?></span><div class="space"></div><span class="nm"><?= e(ucwords(strtolower($cfg['signer_name']))) ?></span><br><?= e($cfg['signer_title']) ?></div>
    </div>
</div>
</body>
</html>
