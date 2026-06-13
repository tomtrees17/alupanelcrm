<?php
/** @var float $revenue */ /** @var int $custCount */ /** @var int $activeDeals */
/** @var int $taskRate */ /** @var array $funnel */ /** @var array $recent */ /** @var array $overdue */
?>
<?php if ($overdue): ?>
    <div class="credit-alert">
        ⚠ 有 <strong><?= count($overdue) ?></strong> 张发票已逾期，应收
        <strong><?= idr(array_sum(array_map(fn($i) => $i['total'] - $i['amount_paid'], $overdue))) ?></strong>。
        <a class="ml-auto btn btn-sm btn-danger" href="<?= url('finance.index', ['status' => 'overdue']) ?>">查看</a>
    </div>
<?php endif; ?>

<div class="stats-grid">
    <div class="stat-card c1"><div class="stat-label">已收款总额</div><div class="stat-value c1"><?= idr_short($revenue) ?></div><div class="stat-sub">累计回款</div></div>
    <div class="stat-card c2"><div class="stat-label">客户总数</div><div class="stat-value c2"><?= $custCount ?></div><div class="stat-sub">CUSTOMERS</div></div>
    <div class="stat-card c3"><div class="stat-label">进行中商机</div><div class="stat-value c3"><?= $activeDeals ?></div><div class="stat-sub">ACTIVE DEALS</div></div>
    <div class="stat-card c4"><div class="stat-label">任务完成率</div><div class="stat-value c4"><?= $taskRate ?>%</div><div class="stat-sub">TASK RATE</div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><span class="card-title">销售漏斗</span><a class="muted" href="<?= url('pipeline.index') ?>" style="font-size:11px">查看 →</a></div>
        <div class="card-body">
            <div class="funnel-bars">
                <?php foreach ($funnel as $f): $color = deal_stage_color($f['stage']); ?>
                    <div class="funnel-row">
                        <div class="funnel-label"><?= e($f['stage']) ?></div>
                        <div class="funnel-track"><div class="funnel-fill" style="width:<?= max(6, $f['pct']) ?>%;background:<?= $color ?>"><?= $f['pct'] ?>%</div></div>
                        <div class="funnel-count"><?= $f['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">最近订单</span><a class="muted" href="<?= url('orders.index') ?>" style="font-size:11px">查看 →</a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>单号</th><th>客户</th><th>状态</th><th class="right">金额</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?><tr><td colspan="4" class="empty">暂无订单</td></tr><?php endif; ?>
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
