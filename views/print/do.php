<?php
/** @var array $do */ /** @var array $items */
$cfg = $GLOBALS['config'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Jalan <?= e($do['do_no']) ?></title>
    <link rel="stylesheet" href="assets/css/print.css">
</head>
<body>
<div class="toolbar">
    <a class="btn" href="<?= url('delivery.index') ?>">← <?= t('btn_back') ?></a>
    <?php if ($do['order_id']): ?><a class="btn" href="<?= url('orders.show', ['id' => $do['order_id']]) ?>"><?= t('order_info') ?></a><?php endif; ?>
    <a class="btn btn-primary" href="javascript:window.print()"><?= t('btn_print') ?> / Cetak</a>
</div>

<div class="sheet">
    <div class="doc-head">
        <div>
            <div class="co-name"><?= e($cfg['company_full']) ?></div>
            <div class="co-meta">
                <?= e($cfg['company_addr']) ?><br>
                Tel: <?= e($cfg['company_phone']) ?>
            </div>
        </div>
        <div class="doc-title">
            <h1>SURAT JALAN</h1>
            <div class="no">No: <?= e($do['do_no']) ?></div>
            <div class="date">Tanggal: <?= e($do['pickup_date']) ?></div>
            <?php if (!empty($do['order_no'])): ?><div class="date">Ref Pesanan: <?= e($do['order_no']) ?></div><?php endif; ?>
        </div>
    </div>

    <div class="parties">
        <div class="party">
            <div class="lbl">Kepada / Penerima</div>
            <div class="name"><?= e($do['customer']) ?></div>
            <div class="info">
                <?= e($do['company']) ?><br>
                <?= nl2br(e($do['delivery_address'] ?: $do['address'])) ?><br>
                <?php if ($do['phone']): ?>Tel: <?= e($do['phone']) ?><?php endif; ?>
            </div>
        </div>
        <div class="party" style="text-align:right">
            <div class="lbl">Pengiriman</div>
            <div class="info">
                Jasa Kirim: <strong><?= e($do['delivery_service']) ?></strong><br>
                <?php if ($do['driver']): ?>Driver: <?= e($do['driver']) ?><br><?php endif; ?>
                <?php if ($do['vehicle_plate']): ?>Plat: <?= e($do['vehicle_plate']) ?><br><?php endif; ?>
                Diterbitkan oleh: <?= e($do['issued_by']) ?>
            </div>
        </div>
    </div>

    <table class="items">
        <thead><tr><th style="width:34px">No</th><th>SKU</th><th>Deskripsi Barang</th><th class="r">Qty</th><th>Satuan</th></tr></thead>
        <tbody>
        <?php if (!$items): ?><tr><td colspan="5" style="text-align:center;color:#888;padding:20px">—</td></tr><?php endif; ?>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e($it['sku']) ?></strong></td>
                <td><?= e($it['color']) ?> · <?= e($it['spec']) ?> · <?= e($it['size']) ?></td>
                <td class="r"><?= (int) $it['qty'] ?></td>
                <td><?= e($it['unit']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($do['note']): ?><div class="notes">Catatan: <?= e($do['note']) ?></div><?php endif; ?>
    <div class="notes" style="margin-top:6px">Barang diterima dalam keadaan baik dan cukup. / 货物已如数收到且完好。</div>

    <div class="signs">
        <div class="sign"><div>Penerima,</div><div class="line"><?= e($do['customer']) ?></div></div>
        <div class="sign"><div>Pengemudi,</div><div class="line"><?= e($do['driver']) ?: '(__________)' ?></div></div>
        <div class="sign"><div>Hormat kami,</div><div class="line"><?= e($do['issued_by']) ?></div></div>
    </div>
</div>
</body>
</html>
