<?php /** @var array $quotes */ ?>
<div class="page-head">
    <h1>报价 / 订单</h1>
    <a class="btn btn-primary" href="<?= url('quotes.create') ?>">+ 新建报价</a>
</div>

<div class="card">
    <table class="table">
        <thead>
        <tr><th>单号</th><th>客户</th><th>日期</th><th>有效期至</th><th>状态</th><th class="right">金额</th></tr>
        </thead>
        <tbody>
        <?php if (!$quotes): ?><tr><td colspan="6" class="empty">暂无报价单</td></tr><?php endif; ?>
        <?php foreach ($quotes as $q): ?>
            <tr>
                <td><a href="<?= url('quotes.show', ['id' => $q['id']]) ?>"><?= e($q['quote_no']) ?></a></td>
                <td><?= e($q['company']) ?></td>
                <td><?= e($q['quote_date']) ?></td>
                <td><?= e($q['valid_until']) ?: '—' ?></td>
                <td><span class="badge badge-<?= e($q['status']) ?>"><?= e(status_label($q['status'])) ?></span></td>
                <td class="right"><?= money($q['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
