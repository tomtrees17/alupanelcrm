<?php /** @var array $stats */ /** @var array $recent */ ?>
<div class="page-head">
    <h1>仪表盘</h1>
</div>

<div class="cards">
    <div class="card stat">
        <span class="stat-label">客户</span>
        <span class="stat-value"><?= (int) $stats['customers'] ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">产品</span>
        <span class="stat-value"><?= (int) $stats['products'] ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">报价单</span>
        <span class="stat-value"><?= (int) $stats['quotes'] ?></span>
    </div>
    <div class="card stat">
        <span class="stat-label">进行中金额</span>
        <span class="stat-value"><?= money($stats['pipeline']) ?></span>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>最近报价 / 订单</h2>
        <a class="btn btn-primary btn-sm" href="<?= url('quotes.create') ?>">+ 新建报价</a>
    </div>
    <table class="table">
        <thead>
        <tr><th>单号</th><th>客户</th><th>日期</th><th>状态</th><th class="right">金额</th></tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
            <tr><td colspan="5" class="empty">暂无数据</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $q): ?>
            <tr>
                <td><a href="<?= url('quotes.show', ['id' => $q['id']]) ?>"><?= e($q['quote_no']) ?></a></td>
                <td><?= e($q['company']) ?></td>
                <td><?= e($q['quote_date']) ?></td>
                <td><span class="badge badge-<?= e($q['status']) ?>"><?= e(status_label($q['status'])) ?></span></td>
                <td class="right"><?= money($q['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
