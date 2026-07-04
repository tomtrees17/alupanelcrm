<?php
/**
 * Editable Word body for a delivery order (mirrors views/print/do.php content).
 * Included by the delivery controller; expects $do, $items, $cfg.
 */
?>
<table style="width:100%"><tr>
    <td>
        <b style="font-size:13pt"><?= e($cfg['company_full']) ?></b><br>
        <span style="font-size:8.5pt;color:#444"><?= e($cfg['company_addr']) ?></span>
    </td>
    <td style="width:38%;text-align:right">
        <span style="font-size:18pt;letter-spacing:3pt"><b>SURAT JALAN</b></span><br>
        <b>No: <?= e($do['do_no']) ?></b><br>
        <span style="font-size:9pt">Tanggal: <?= e($do['pickup_date']) ?></span><br>
        <?php if (!empty($do['order_no'])): ?><span style="font-size:9pt">Ref Pesanan: <?= e($do['order_no']) ?></span><?php endif; ?>
    </td>
</tr></table>

<hr style="border:1px solid #000;margin:6pt 0">

<table style="width:100%;font-size:9.5pt"><tr>
    <td>
        <span style="color:#555;font-size:8.5pt">KEPADA / PENERIMA</span><br>
        <b><?= e($do['customer']) ?></b><br>
        <?= e($do['company']) ?><br>
        <?= nl2br(e($do['delivery_address'] ?: $do['address'])) ?><br>
        <?php if ($do['phone']): ?>Tel: <?= e($do['phone']) ?><?php endif; ?>
    </td>
    <td style="width:38%;text-align:right">
        <span style="color:#555;font-size:8.5pt">PENGIRIMAN</span><br>
        Jasa Kirim: <b><?= e($do['delivery_service']) ?></b><br>
        <?php if ($do['driver']): ?>Driver: <?= e($do['driver']) ?><br><?php endif; ?>
        <?php if ($do['vehicle_plate']): ?>Plat: <?= e($do['vehicle_plate']) ?><br><?php endif; ?>
        Diterbitkan oleh: <?= e($do['issued_by']) ?>
    </td>
</tr></table>

<table style="width:100%;margin-top:8pt;font-size:9.5pt">
    <tr>
        <th style="border:1px solid #000;padding:4pt;width:7%">No</th>
        <th style="border:1px solid #000;padding:4pt;width:16%">SKU</th>
        <th style="border:1px solid #000;padding:4pt">Deskripsi Barang</th>
        <th style="border:1px solid #000;padding:4pt;width:10%">Qty</th>
        <th style="border:1px solid #000;padding:4pt;width:12%">Satuan</th>
    </tr>
    <?php foreach ($items as $i => $it): ?>
        <tr>
            <td style="border:1px solid #000;padding:4pt;text-align:center"><?= $i + 1 ?></td>
            <td style="border:1px solid #000;padding:4pt"><b><?= e($it['sku']) ?></b></td>
            <td style="border:1px solid #000;padding:4pt"><?= e(implode(' · ', array_filter([no_cjk($it['color']), no_cjk($it['spec']), no_cjk($it['size'])], fn($v) => $v !== ''))) ?></td>
            <td style="border:1px solid #000;padding:4pt;text-align:right"><?= (int) $it['qty'] ?></td>
            <td style="border:1px solid #000;padding:4pt"><?= e(no_cjk($it['unit']) ?: 'Unit') ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php $doNote = no_cjk($do['note']); ?>
<?php if ($doNote !== ''): ?><p style="font-size:9pt;margin-top:8pt">Catatan: <?= e($doNote) ?></p><?php endif; ?>
<p style="font-size:9pt;margin-top:6pt">Barang diterima dalam keadaan baik dan cukup.</p>

<table style="width:100%;margin-top:28pt;font-size:9.5pt;text-align:center"><tr>
    <td>Penerima,<br><br><br><br>( <?= e($do['customer']) ?> )</td>
    <td>Pengemudi,<br><br><br><br>( <?= e($do['driver']) ?: '__________' ?> )</td>
    <td>Hormat kami,<br><br><br><br>( <?= e($do['issued_by']) ?> )</td>
</tr></table>
