<?php /** @var array $recent */ ?>
<div class="table-wrap"><table>
    <thead><tr><th><?= t('th_order_no') ?></th><th><?= t('th_customer') ?></th><th><?= t('th_status') ?></th><th class="right"><?= t('th_amount') ?></th></tr></thead>
    <tbody>
    <?php if (!$recent): ?><tr><td colspan="4" class="empty"><?= t('no_orders') ?></td></tr><?php endif; ?>
    <?php foreach ($recent as $o): ?>
        <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
            <td><code><?= e($o['order_no']) ?></code></td>
            <td><?= trim((string) $o['customer_name']) !== '' ? e($o['customer_name']) : '<span class="muted">' . t('order_no_customer') . '</span>' ?></td>
            <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            <td class="right num"><?= idr($o['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
