<?php /** @var array $dos */ ?>
<div class="page-head">
    <h1><?= t('do_invoice') ?></h1>
    <a class="btn btn-ghost" href="<?= url('orders.index') ?>"><?= t('nav_orders') ?></a>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>No. Surat Jalan</th><th><?= t('th_order_no') ?></th><th><?= t('th_customer') ?></th><th><?= t('th_date') ?></th><th><?= t('f_delivery_service') ?></th><th class="right"><?= t('th_action') ?></th></tr></thead>
    <tbody>
    <?php if (!$dos): ?><tr><td colspan="6" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($dos as $d): ?>
        <tr>
            <td><code><?= e($d['do_no']) ?></code></td>
            <td><code><?= e($d['order_no'] ?? '') ?></code></td>
            <td><strong><?= e($d['customer']) ?></strong><br><span class="muted"><?= e($d['company']) ?></span></td>
            <td><?= e($d['pickup_date']) ?></td>
            <td><?= e($d['delivery_service']) ?></td>
            <td class="right"><a class="btn btn-ghost btn-sm" href="<?= url('delivery.print', ['id' => $d['id']]) ?>" target="_blank"><?= t('btn_print') ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
