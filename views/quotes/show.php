<?php /** @var array $quote */ /** @var array $customer */ /** @var array $items */ ?>
<div class="page-head">
    <h1>报价单 <?= e($quote['quote_no']) ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('quotes.edit', ['id' => $quote['id']]) ?>">编辑</a>
        <a class="btn btn-ghost" href="javascript:window.print()">打印</a>
        <form method="post" action="<?= url('quotes.delete') ?>" onsubmit="return confirm('确定删除该报价单？')">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
            <button class="btn btn-danger" type="submit">删除</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="quote-meta">
        <div>
            <span class="muted">客户</span>
            <strong><a href="<?= url('customers.show', ['id' => $customer['id']]) ?>"><?= e($customer['company']) ?></a></strong>
            <div class="muted"><?= e($customer['contact_name']) ?> <?= e($customer['phone']) ?></div>
        </div>
        <div>
            <span class="muted">日期</span><strong><?= e($quote['quote_date']) ?></strong>
            <div class="muted">有效期至 <?= e($quote['valid_until']) ?: '—' ?></div>
        </div>
        <div>
            <span class="muted">状态</span>
            <form method="post" action="<?= url('quotes.status') ?>" class="status-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
                <select name="status" onchange="this.form.submit()">
                    <?php foreach (status_list() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $quote['status'] ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <table class="table">
        <thead>
        <tr><th>产品 / 说明</th><th class="right">数量</th><th class="right">单价</th><th class="right">小计</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td>
                    <?php if (!empty($it['product_name'])): ?><strong><?= e($it['product_name']) ?></strong><br><?php endif; ?>
                    <?= e($it['description']) ?>
                </td>
                <td class="right"><?= e(rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.')) ?></td>
                <td class="right"><?= money($it['unit_price']) ?></td>
                <td class="right"><?= money($it['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr><td colspan="3" class="right">小计</td><td class="right"><?= money($quote['subtotal']) ?></td></tr>
        <tr><td colspan="3" class="right">税 (<?= e($quote['tax_rate']) ?>%)</td><td class="right"><?= money($quote['tax_amount']) ?></td></tr>
        <tr class="total-row"><td colspan="3" class="right">合计</td><td class="right"><?= money($quote['total']) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($quote['notes']): ?>
        <p class="notes"><strong>备注：</strong><?= nl2br(e($quote['notes'])) ?></p>
    <?php endif; ?>
</div>
