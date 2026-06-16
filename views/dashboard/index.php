<?php
/** @var float $revenue */ /** @var int $custCount */ /** @var int $activeDeals */
/** @var int $taskRate */ /** @var array $funnel */ /** @var array $recent */ /** @var array $overdue */
?>
<?php if (can_access('finance') && $overdue): ?>
    <div class="credit-alert">
        ⚠ <?= t('credit_alert_1') ?> <strong><?= count($overdue) ?></strong> <?= t('credit_alert_2') ?>
        <strong><?= idr(array_sum(array_map(fn($i) => $i['total'] - $i['amount_paid'], $overdue))) ?></strong>。
        <a class="ml-auto btn btn-sm btn-danger" href="<?= url('finance.index', ['status' => 'overdue']) ?>"><?= t('view_more') ?></a>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <?php if (can_access('finance')): ?>
        <div class="stat-card c1"><div class="stat-label"><?= t('stat_received') ?></div><div class="stat-value c1"><?= idr_short($revenue) ?></div></div>
    <?php else: ?>
        <div class="stat-card c1"><div class="stat-label"><?= t('nav_orders') ?></div><div class="stat-value c1"><?= (int) ($pendingOrders ?? 0) ?></div></div>
    <?php endif; ?>
    <div class="stat-card c2"><div class="stat-label"><?= t('stat_customers') ?></div><div class="stat-value c2"><?= $custCount ?></div></div>
    <div class="stat-card c3"><div class="stat-label"><?= t('stat_active_deals') ?></div><div class="stat-value c3"><?= $activeDeals ?></div></div>
    <div class="stat-card c4"><div class="stat-label"><?= t('stat_task_rate') ?></div><div class="stat-value c4"><?= $taskRate ?>%</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('funnel') ?></span><a class="muted" href="<?= url('pipeline.index') ?>" style="font-size:11px"><?= t('view_more') ?></a></div>
        <div class="card-body">
            <div class="funnel-bars">
                <?php foreach ($funnel as $f): $color = deal_stage_color($f['stage']); ?>
                    <div class="funnel-row">
                        <div class="funnel-label"><?= e(tr_stage($f['stage'])) ?></div>
                        <div class="funnel-track"><div class="funnel-fill" style="width:<?= max(6, $f['pct']) ?>%;background:<?= $color ?>"><?= $f['pct'] ?>%</div></div>
                        <div class="funnel-count"><?= $f['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('recent_orders') ?></span><a class="muted" href="<?= url('orders.index') ?>" style="font-size:11px"><?= t('view_more') ?></a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th><?= t('th_order_no') ?></th><th><?= t('th_customer') ?></th><th><?= t('th_status') ?></th><th class="right"><?= t('th_amount') ?></th></tr></thead>
                <tbody>
                <?php if (!$recent): ?><tr><td colspan="4" class="empty"><?= t('no_orders') ?></td></tr><?php endif; ?>
                <?php foreach ($recent as $o): ?>
                    <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
                        <td><code><?= e($o['order_no']) ?></code></td>
                        <td><?= e($o['customer_name']) ?></td>
                        <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                        <td class="right"><?= idr($o['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
