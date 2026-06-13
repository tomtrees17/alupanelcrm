<?php /** @var array $customer */ /** @var array $quotes */ ?>
<div class="page-head">
    <h1><?= e($customer['company']) ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('customers.edit', ['id' => $customer['id']]) ?>">编辑</a>
        <form method="post" action="<?= url('customers.delete') ?>" onsubmit="return confirm('确定删除该客户及其报价单？')">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
            <button class="btn btn-danger" type="submit">删除</button>
        </form>
    </div>
</div>

<div class="card">
    <dl class="detail">
        <div><dt>联系人</dt><dd><?= e($customer['contact_name']) ?: '—' ?></dd></div>
        <div><dt>邮箱</dt><dd><?= e($customer['email']) ?: '—' ?></dd></div>
        <div><dt>电话</dt><dd><?= e($customer['phone']) ?: '—' ?></dd></div>
        <div><dt>城市</dt><dd><?= e($customer['city']) ?: '—' ?></dd></div>
        <div><dt>国家</dt><dd><?= e($customer['country']) ?: '—' ?></dd></div>
        <div><dt>地址</dt><dd><?= e($customer['address']) ?: '—' ?></dd></div>
    </dl>
    <?php if ($customer['notes']): ?>
        <p class="notes"><?= nl2br(e($customer['notes'])) ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>报价 / 订单</h2>
        <a class="btn btn-primary btn-sm" href="<?= url('quotes.create', ['customer_id' => $customer['id']]) ?>">+ 新建报价</a>
    </div>
    <table class="table">
        <thead><tr><th>单号</th><th>日期</th><th>状态</th><th class="right">金额</th></tr></thead>
        <tbody>
        <?php if (!$quotes): ?><tr><td colspan="4" class="empty">暂无报价</td></tr><?php endif; ?>
        <?php foreach ($quotes as $q): ?>
            <tr>
                <td><a href="<?= url('quotes.show', ['id' => $q['id']]) ?>"><?= e($q['quote_no']) ?></a></td>
                <td><?= e($q['quote_date']) ?></td>
                <td><span class="badge badge-<?= e($q['status']) ?>"><?= e(status_label($q['status'])) ?></span></td>
                <td class="right"><?= money($q['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
