<?php
/** @var array $invoice */ /** @var array $items */
$cfg = $GLOBALS['config'];
$paid = $invoice['payment_status'] === 'paid';
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

<div class="sheet">
    <div class="doc-head">
        <div>
            <div class="co-name"><?= e($cfg['company_full']) ?></div>
            <div class="co-meta">
                <?= e($cfg['company_addr']) ?><br>
                Tel: <?= e($cfg['company_phone']) ?> · NPWP: <?= e($cfg['company_npwp']) ?>
            </div>
        </div>
        <div class="doc-title">
            <h1>INVOICE</h1>
            <div class="no">No: <?= e($invoice['invoice_no']) ?></div>
            <div class="date">Tanggal: <?= e($invoice['invoice_date']) ?></div>
            <?php if ($invoice['tax_invoice_no']): ?><div class="date">Faktur Pajak: <?= e($invoice['tax_invoice_no']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="parties">
        <div class="party">
            <div class="lbl">Kepada / Bill To</div>
            <div class="name"><?= e($invoice['bill_to_name'] ?: $invoice['customer']) ?></div>
            <div class="info">
                <?= e($invoice['customer']) ?><br>
                <?= nl2br(e($invoice['address'])) ?><br>
                <?php if ($invoice['npwp']): ?>NPWP: <?= e($invoice['npwp']) ?><br><?php endif; ?>
                <?php if ($invoice['no_po']): ?>No. PO: <?= e($invoice['no_po']) ?><?php endif; ?>
            </div>
        </div>
        <div class="party" style="text-align:right">
            <div class="lbl">Status</div>
            <div class="status-stamp <?= $paid ? 'stamp-paid' : 'stamp-unpaid' ?>"><?= $paid ? 'LUNAS' : strtoupper(invoice_status_label($invoice['payment_status'])) ?></div>
            <div class="info" style="margin-top:10px">
                Jatuh Tempo: <strong><?= e($invoice['due_date']) ?></strong><br>
                Termin: <?= e($invoice['payment_term']) ?><?= $invoice['payment_term'] === 'custom' ? ' Net ' . (int) $invoice['custom_days'] . ' hari' : '' ?><br>
                <?php if ($invoice['do_number']): ?>Surat Jalan: <?= e($invoice['do_number']) ?><?php endif; ?>
            </div>
        </div>
    </div>

    <table class="items">
        <thead><tr><th style="width:34px">No</th><th>Deskripsi</th><th class="r">Qty</th><th class="r">Harga</th><th class="r">Jumlah</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($it['sku']) ?></strong> — <?= e($it['color']) ?><br><span style="color:#888;font-size:11px"><?= e($it['spec']) ?> · <?= e($it['size']) ?></span></td>
                <td class="r"><?= (int) $it['qty'] ?> <?= e($it['unit']) ?></td>
                <td class="r"><?= idr($it['price']) ?></td>
                <td class="r"><?= idr($it['qty'] * $it['price']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Ongkos Kirim</td><td class="r"><?= idr($invoice['shipping_cost']) ?></td></tr>
        <tr><td>Subtotal (termasuk ongkir)</td><td class="r"><?= idr($invoice['subtotal']) ?></td></tr>
        <tr><td>PPN 11%</td><td class="r"><?= idr($invoice['ppn']) ?></td></tr>
        <tr class="grand"><td>TOTAL</td><td class="r"><?= idr($invoice['total']) ?></td></tr>
        <?php if ($invoice['amount_paid'] > 0): ?>
            <tr><td>Dibayar</td><td class="r"><?= idr($invoice['amount_paid']) ?></td></tr>
            <tr><td>Sisa</td><td class="r"><?= idr($invoice['total'] - $invoice['amount_paid']) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="terbilang">Terbilang: <?= e(terbilang($invoice['total'])) ?></div>

    <div class="notes">
        Pembayaran ke: <?= e($cfg['company_bank']) ?><br>
        <?php if ($invoice['note']): ?>Catatan: <?= e($invoice['note']) ?><?php endif; ?>
    </div>

    <div class="signs">
        <div class="sign"><div>Penerima,</div><div class="line"><?= e($invoice['bill_to_name'] ?: $invoice['customer']) ?></div></div>
        <div class="sign"><div>Hormat kami,</div><div class="line"><?= e($invoice['issued_by'] ?: $cfg['company']) ?></div></div>
    </div>
</div>
</body>
</html>
