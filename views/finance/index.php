<?php /** @var array $invoices */ /** @var array $stats */ /** @var string $statusFilter */ ?>
<div class="page-head">
    <h1>财务管理</h1>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card c1"><div class="stat-label">已收款</div><div class="stat-value c1"><?= idr_short($stats['received']) ?></div></div>
    <div class="stat-card c3"><div class="stat-label">待收款</div><div class="stat-value c3"><?= idr_short($stats['pending']) ?></div></div>
    <div class="stat-card c4"><div class="stat-label">逾期</div><div class="stat-value" style="color:var(--danger)"><?= idr_short($stats['overdue']) ?></div></div>
</div>

<div class="task-filters">
    <?php foreach (['all' => '全部', 'paid' => '已收款', 'partial' => '部分收款', 'pending' => '待收款', 'overdue' => '逾期'] as $k => $lbl): ?>
        <a class="filter-btn <?= $statusFilter === $k ? 'active' : '' ?>" href="<?= url('finance.index', ['status' => $k]) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>发票号</th><th>客户</th><th>开票日</th><th>到期日</th><th>状态</th><th class="right">总额</th><th class="right">已收</th></tr></thead>
    <tbody>
    <?php if (!$invoices): ?><tr><td colspan="7" class="empty">暂无发票</td></tr><?php endif; ?>
    <?php foreach ($invoices as $iv): ?>
        <tr class="clickable" onclick="location.href='<?= url('finance.show', ['id' => $iv['id']]) ?>'">
            <td><code><?= e($iv['invoice_no']) ?></code></td>
            <td><strong><?= e($iv['customer']) ?></strong></td>
            <td><?= e($iv['invoice_date']) ?></td>
            <td><?= e($iv['due_date']) ?></td>
            <td><span class="tag <?= invoice_status_class($iv['payment_status']) ?>"><?= e(invoice_status_label($iv['payment_status'])) ?></span></td>
            <td class="right"><?= idr($iv['total']) ?></td>
            <td class="right"><?= idr($iv['amount_paid']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
