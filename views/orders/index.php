<?php /** @var array $orders */ /** @var array $counts */ /** @var string $statusFilter */ ?>
<div class="page-head">
    <h1><?= t('page_orders') ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('delivery.index') ?>"><?= t('do_invoice') ?></a>
        <a class="btn btn-primary" href="<?= url('orders.create') ?>"><?= t('btn_add_order') ?></a>
    </div>
</div>

<div class="task-filters">
    <a class="filter-btn <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= url('orders.index') ?>"><?= t('filter_all') ?></a>
    <?php foreach (order_statuses() as $s): ?>
        <a class="filter-btn <?= $statusFilter === $s ? 'active' : '' ?>" href="<?= url('orders.index', ['status' => $s]) ?>">
            <?= e(order_status_label($s)) ?><?php if (!empty($counts[$s])): ?> (<?= $counts[$s] ?>)<?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('th_order_no') ?></th><th><?= t('th_customer') ?></th><th><?= t('th_submitter') ?></th><th><?= t('th_payment') ?></th><th><?= t('th_status') ?></th><th class="right"><?= t('th_amount') ?></th></tr></thead>
    <tbody>
    <?php if (!$orders): ?><tr><td colspan="6" class="empty"><?= t('no_orders') ?></td></tr><?php endif; ?>
    <?php foreach ($orders as $o): ?>
        <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
            <td><code><?= e($o['order_no']) ?></code></td>
            <td><strong><?= e($o['customer_name']) ?></strong><br><span class="muted"><?= e($o['company']) ?></span></td>
            <td><?= e($o['submitter']) ?></td>
            <td><?= e($o['payment_term']) ?><?= $o['payment_term'] === 'custom' ? ' ' . (int) $o['custom_days'] . 'd' : '' ?></td>
            <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            <td class="right"><?= idr($o['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
