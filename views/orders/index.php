<?php /** @var array $orders */ /** @var array $counts */ /** @var string $statusFilter */ ?>
<div class="page-head">
    <h1>订单审批</h1>
    <a class="btn btn-primary" href="<?= url('orders.create') ?>">＋ 新建订单</a>
</div>

<div class="task-filters">
    <a class="filter-btn <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= url('orders.index') ?>">全部</a>
    <?php foreach (order_statuses() as $s): ?>
        <a class="filter-btn <?= $statusFilter === $s ? 'active' : '' ?>" href="<?= url('orders.index', ['status' => $s]) ?>">
            <?= e(order_status_label($s)) ?><?php if (!empty($counts[$s])): ?> (<?= $counts[$s] ?>)<?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>单号</th><th>客户</th><th>提交人</th><th>付款</th><th>状态</th><th class="right">金额</th></tr></thead>
    <tbody>
    <?php if (!$orders): ?><tr><td colspan="6" class="empty">暂无订单</td></tr><?php endif; ?>
    <?php foreach ($orders as $o): ?>
        <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
            <td><code><?= e($o['order_no']) ?></code></td>
            <td><strong><?= e($o['customer_name']) ?></strong><br><span class="muted"><?= e($o['company']) ?></span></td>
            <td><?= e($o['submitter']) ?></td>
            <td><?= e($o['payment_term']) ?><?= $o['payment_term'] === 'custom' ? ' ' . (int) $o['custom_days'] . '天' : '' ?></td>
            <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
            <td class="right"><?= idr($o['amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
