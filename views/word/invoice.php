<?php
/**
 * Editable Word body for an invoice (mirrors views/print/invoice.php content).
 * Included by the finance controller; expects $invoice, $items, $orderNo, $cfg.
 */
$subtotal = (float) $invoice['subtotal'];
$dpp = round($subtotal * 11 / 12);
$vat = (float) $invoice['ppn'];
$total = (float) $invoice['total'];
$fdate = fn($d) => $d ? date('j/M/y', strtotime($d)) : '';
$rp = fn($n) => 'Rp' . num($n);
?>
<table style="width:100%"><tr>
    <td style="width:34%"><b style="font-size:13pt"><?= e($cfg['company_full']) ?></b></td>
    <td style="border:1px solid #000;padding:4pt;font-size:8.5pt"><?= e($cfg['company_addr']) ?></td>
    <td style="width:22%;text-align:right;font-size:20pt;font-family:Georgia,serif"><b>Invoice</b></td>
</tr></table>

<table style="width:100%;margin-top:8pt"><tr>
    <td style="width:55%;font-size:10pt">
        <span style="color:#555">Bill To :</span><br>
        <b><?= e($invoice['bill_to_name'] ?: $invoice['customer']) ?></b><br>
        <?= nl2br(e($invoice['address'])) ?><br>
        <?php if ($invoice['npwp']): ?>NPWP : <?= e($invoice['npwp']) ?><?php endif; ?>
    </td>
    <td>
        <table style="width:100%;font-size:9pt">
            <tr><th style="border:1px solid #000;padding:3pt">Sales Invoice Date</th><th style="border:1px solid #000;padding:3pt">Sales Invoice No.</th></tr>
            <tr><td style="border:1px solid #000;padding:3pt;text-align:center"><?= e($fdate($invoice['invoice_date'])) ?></td><td style="border:1px solid #000;padding:3pt;text-align:center"><?= e($invoice['invoice_no']) ?></td></tr>
            <tr><td style="border:1px solid #000;padding:3pt">No CO / PO</td><td style="border:1px solid #000;padding:3pt;text-align:center"><?= e($orderNo ?: $invoice['no_po']) ?></td></tr>
            <tr><td style="border:1px solid #000;padding:3pt">Currency</td><td style="border:1px solid #000;padding:3pt;text-align:center"><?= e($invoice['currency'] ?: 'IDR') ?></td></tr>
            <tr><td style="border:1px solid #000;padding:3pt">Due Date</td><td style="border:1px solid #000;padding:3pt;text-align:center"><?= e($fdate($invoice['due_date'])) ?></td></tr>
        </table>
    </td>
</tr></table>

<table style="width:100%;margin-top:8pt;font-size:9.5pt">
    <tr>
        <th style="border:1px solid #000;padding:4pt;width:6%">No.</th>
        <th style="border:1px solid #000;padding:4pt">Item Description</th>
        <th style="border:1px solid #000;padding:4pt;width:12%">Quantity</th>
        <th style="border:1px solid #000;padding:4pt;width:16%">Unit Price</th>
        <th style="border:1px solid #000;padding:4pt;width:17%">Amount</th>
    </tr>
    <?php foreach ($items as $i => $it): ?>
        <?php $spec = no_cjk($it['spec']); $color = no_cjk($it['color']); $size = no_cjk($it['size']); ?>
        <tr>
            <td style="border:1px solid #000;padding:4pt;text-align:center"><?= $i + 1 ?></td>
            <td style="border:1px solid #000;padding:4pt"><?= trim(($spec !== '' ? '(' . e($spec) . ') ' : '') . e($it['sku']) . ($color !== '' ? ' (' . e($color) . ')' : '') . ($size !== '' ? ' ' . e($size) : '')) ?></td>
            <td style="border:1px solid #000;padding:4pt;text-align:center"><?= num($it['qty']) ?></td>
            <td style="border:1px solid #000;padding:4pt;text-align:right"><?= num($it['price'], 2) ?></td>
            <td style="border:1px solid #000;padding:4pt;text-align:right"><?= num($it['qty'] * $it['price']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($invoice['shipping_cost'] > 0): ?>
        <tr>
            <td style="border:1px solid #000;padding:4pt;text-align:center"><?= count($items) + 1 ?></td>
            <td style="border:1px solid #000;padding:4pt">Ongkir</td>
            <td style="border:1px solid #000;padding:4pt;text-align:center">1</td>
            <td style="border:1px solid #000;padding:4pt;text-align:right"><?= num($invoice['shipping_cost'], 2) ?></td>
            <td style="border:1px solid #000;padding:4pt;text-align:right"><?= num($invoice['shipping_cost']) ?></td>
        </tr>
    <?php endif; ?>
</table>

<table style="width:100%;margin-top:8pt"><tr>
    <td style="width:56%;font-size:9pt;font-style:italic">Say : <?= e(terbilang($total)) ?></td>
    <td>
        <table style="width:100%;font-size:9.5pt">
            <tr><td style="padding:2pt">Subtotal</td><td style="padding:2pt;text-align:right"><?= $rp($subtotal) ?></td></tr>
            <tr><td style="padding:2pt">DPP</td><td style="padding:2pt;text-align:right"><?= $rp($dpp) ?></td></tr>
            <tr><td style="padding:2pt">VAT 12%</td><td style="padding:2pt;text-align:right"><?= $rp($vat) ?></td></tr>
            <tr><td style="padding:2pt;border-top:1px solid #000"><b>Total Amount</b></td><td style="padding:2pt;text-align:right;border-top:1px solid #000"><b><?= $rp($total) ?></b></td></tr>
        </table>
    </td>
</tr></table>

<table style="width:100%;margin-top:12pt"><tr>
    <td style="font-size:8.5pt">
        <b>Please deposit above amount to our account</b><br><br>
        <?php foreach ($cfg['banks'] as $bk): ?>
            Bank Name : <?= e($bk['name']) ?><br>
            Branch : <?= e($bk['branch']) ?><br>
            Account Name : <?= e($bk['account_name']) ?><br>
            Account No : <?= e($bk['account_no']) ?><br>
            Swift Code : <?= e($bk['swift']) ?><br><br>
        <?php endforeach; ?>
    </td>
    <td style="width:36%;text-align:center;font-size:9.5pt">
        On Your Behalf
        <br><br><br><br><br>
        <?= e($cfg['signer_title']) ?>
    </td>
</tr></table>
