<?php
/** @var array $invoice */ /** @var array $items */ /** @var string $orderNo */
$cfg = $GLOBALS['config'];
$subtotal = (float) $invoice['subtotal'];
$dpp = round($subtotal * 11 / 12);
$vat = (float) $invoice['ppn'];           // = subtotal × 11% = DPP × 12%
$total = (float) $invoice['total'];
$fdate = fn($d) => $d ? date('j/M/y', strtotime($d)) : '';
$fdate2 = fn($d) => $d ? date('j-M-y', strtotime($d)) : '';
$rp = fn($n) => 'Rp' . num($n);
$logoDir = dirname(__DIR__, 2) . '/public/assets/img/';
$logoFile = null;
foreach (['logo.png', 'logo.svg', 'logo.jpg'] as $cand) {
    if (is_file($logoDir . $cand)) { $logoFile = $cand; break; }
}
$logo = $cfg['company_logo'] ?? 'ALUSIGNPANEL';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= e($invoice['invoice_no']) ?></title>
    <link rel="stylesheet" href="assets/css/print.css">
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?= url('finance.show', ['id' => $invoice['id']]) ?>">← <?= t('btn_back') ?></a>
    <a class="btn btn-primary" href="javascript:window.print()"><?= t('btn_print') ?> / Cetak</a>
</div>

<div class="sheet inv">
    <!-- Header -->
    <table class="inv-top"><tr>
        <td class="inv-logo">
            <?php if ($logoFile): ?>
                <img src="assets/img/<?= e($logoFile) ?>" alt="logo" style="max-width:200px;max-height:64px">
            <?php else: ?>
                <div class="mark"><span class="r"><?= e(mb_substr($logo, 0, 1)) ?></span><?= e(mb_substr($logo, 1, max(0, mb_strlen($logo) - 2))) ?><span class="b"><?= e(mb_substr($logo, -1)) ?></span></div>
            <?php endif; ?>
        </td>
        <td>
            <div class="inv-co"><b><?= e($cfg['company_full']) ?></b><br>:<?= e($cfg['company_addr']) ?></div>
        </td>
        <td class="inv-title"><h1>Invoice</h1></td>
    </tr></table>

    <!-- Bill To + meta -->
    <table style="width:100%"><tr class="inv-mid">
        <td class="inv-billto" style="width:55%">
            <div class="lbl">Bill To :</div>
            <strong><?= e($invoice['bill_to_name'] ?: $invoice['customer']) ?></strong><br>
            <?= nl2br(e($invoice['address'])) ?><br>
            <?php if ($invoice['npwp']): ?>NPWP : <?= e($invoice['npwp']) ?><?php endif; ?>
        </td>
        <td>
            <table class="inv-meta b" style="width:360px">
                <tr><th>Sales Invoice Date</th><th>Sales Invoice No.</th></tr>
                <tr><td class="c"><?= e($fdate($invoice['invoice_date'])) ?></td><td class="c"><?= e($invoice['invoice_no']) ?></td></tr>
                <tr><td>No CO / PO</td><td class="c"><?= e($orderNo ?: $invoice['no_po']) ?></td></tr>
                <tr><td>Job NO</td><td class="c">Currency <?= e($invoice['currency'] ?: 'IDR') ?></td></tr>
                <tr><td>Due Date</td><td class="c"><?= e($fdate2($invoice['due_date'])) ?></td></tr>
            </table>
        </td>
    </tr></table>

    <!-- Items -->
    <table class="inv-items b">
        <thead><tr><th>No.</th><th>Item Description</th><th>Quantity</th><th>Unit Price</th><th>Amount</th></tr></thead>
        <tbody class="inv-itembody">
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td class="no"><?= $i + 1 ?></td>
                <td>(<?= e($it['spec']) ?>) <?= e($it['sku']) ?> (<?= e($it['color']) ?>) <?= e($it['size']) ?></td>
                <td class="q"><?= num($it['qty']) ?></td>
                <td class="up"><?= num($it['price'], 2) ?></td>
                <td class="amt"><?= num($it['qty'] * $it['price']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($invoice['shipping_cost'] > 0): ?>
            <tr>
                <td class="no"><?= count($items) + 1 ?></td>
                <td>Ongkir</td>
                <td class="q">1</td>
                <td class="up"><?= num($invoice['shipping_cost'], 2) ?></td>
                <td class="amt"><?= num($invoice['shipping_cost']) ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <table class="inv-tot"><tr>
        <td style="width:56%"></td>
        <td class="inv-totbox">
            <table style="width:100%">
                <tr><td class="k">Subtotal</td><td class="v"><?= $rp($subtotal) ?></td></tr>
                <tr><td class="k">DPP</td><td class="v"><?= $rp($dpp) ?></td></tr>
                <tr><td class="k">VAT 12%</td><td class="v"><?= $rp($vat) ?></td></tr>
                <tr><td class="k">Total Invoice</td><td class="v"><?= $rp($total) ?></td></tr>
                <tr class="grand"><td class="k">Total Amount</td><td class="v"><?= $rp($total) ?></td></tr>
            </table>
        </td>
    </tr></table>

    <!-- Bank + signature -->
    <table class="inv-tot" style="margin-top:18px"><tr>
        <td class="inv-bank">
            <div class="hdr">Please deposit above amount to our account</div>
            <?php foreach ($cfg['banks'] as $bk): ?>
                <table style="margin-bottom:8px">
                    <tr><td style="width:38%">Bank Name</td><td>: <?= e($bk['name']) ?></td></tr>
                    <tr><td>Branch</td><td>: <?= e($bk['branch']) ?></td></tr>
                    <tr><td>Account Name</td><td>: <?= e($bk['account_name']) ?></td></tr>
                    <tr><td>Account No</td><td>: <?= e($bk['account_no']) ?></td></tr>
                    <tr><td>Swift Code</td><td>: <?= e($bk['swift']) ?></td></tr>
                </table>
            <?php endforeach; ?>
        </td>
        <td class="inv-sign">
            On Your Behalf<br>
            <strong><?= e($cfg['company_full']) ?></strong>
            <div class="gap"></div>
            <strong><?= e($invoice['issued_by'] ?: $cfg['signer_name']) ?></strong><br>
            <?= e($cfg['signer_title']) ?>
        </td>
    </tr></table>
</div>
</body>
</html>
