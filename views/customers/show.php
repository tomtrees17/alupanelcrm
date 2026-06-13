<?php /** @var array $customer */ /** @var array $deals */ /** @var array $orders */ ?>
<div class="page-head">
    <h1><?= e($customer['name']) ?> <span class="tag <?= customer_tag_class($customer['tag']) ?>"><?= e(tr_tag($customer['tag'])) ?></span></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('customers.edit', ['id' => $customer['id']]) ?>">编辑</a>
        <form method="post" action="<?= url('customers.delete') ?>" onsubmit="return confirm('确定删除该客户？')" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
            <button class="btn btn-danger" type="submit">删除</button>
        </form>
    </div>
</div>

<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt>公司</dt><dd><?= e($customer['company']) ?: '—' ?></dd></div>
        <div><dt>联系方式</dt><dd><?= e($customer['phone']) ?: '—' ?></dd></div>
        <div><dt>邮箱</dt><dd><?= e($customer['email']) ?: '—' ?></dd></div>
        <div><dt>城市</dt><dd><?= e($customer['city']) ?: '—' ?></dd></div>
        <div><dt>潜在价值</dt><dd><?= idr($customer['value']) ?></dd></div>
        <div><dt>最后跟进</dt><dd><?= e($customer['last_contact']) ?: '—' ?></dd></div>
    </dl>
    <?php if ($customer['note']): ?><p class="notes"><?= nl2br(e($customer['note'])) ?></p><?php endif; ?>
</div></div>

<div class="card">
    <div class="card-header"><span class="card-title">关联商机</span><a class="btn btn-sm btn-ghost" href="<?= url('pipeline.create') ?>">＋ 新增商机</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>商机</th><th>阶段</th><th>预计成交</th><th class="right">金额</th></tr></thead>
        <tbody>
        <?php if (!$deals): ?><tr><td colspan="4" class="empty">暂无商机</td></tr><?php endif; ?>
        <?php foreach ($deals as $d): ?>
            <tr><td><strong><?= e($d['name']) ?></strong></td>
                <td><span class="tag" style="background:<?= deal_stage_color($d['stage']) ?>22;color:<?= deal_stage_color($d['stage']) ?>"><?= e(tr_stage($d['stage'])) ?></span></td>
                <td><?= e($d['close_date']) ?></td><td class="right"><?= idr($d['value']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title">订单记录</span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>单号</th><th>状态</th><th>日期</th></tr></thead>
        <tbody>
        <?php if (!$orders): ?><tr><td colspan="3" class="empty">暂无订单</td></tr><?php endif; ?>
        <?php foreach ($orders as $o): ?>
            <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
                <td><code><?= e($o['order_no']) ?></code></td>
                <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                <td><?= e($o['created_at']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
