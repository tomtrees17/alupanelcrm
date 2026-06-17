<?php /** @var array $customer */ /** @var array $deals */ /** @var array $orders */ ?>
<div class="page-head">
    <h1><?= e($customer['name']) ?> <span class="tag <?= customer_tag_class($customer['tag']) ?>"><?= e(tr_tag($customer['tag'])) ?></span></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('customers.edit', ['id' => $customer['id']]) ?>"><?= t('btn_edit') ?></a>
        <form method="post" action="<?= url('customers.delete') ?>" onsubmit="return confirm('?')" style="display:inline">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
            <button class="btn btn-danger" type="submit"><?= t('btn_delete') ?></button>
        </form>
    </div>
</div>

<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt><?= t('th_company') ?></dt><dd><?= e($customer['company']) ?: '—' ?></dd></div>
        <div><dt><?= t('th_phone') ?></dt><dd><?= e($customer['phone']) ?: '—' ?></dd></div>
        <div><dt><?= t('th_email') ?></dt><dd><?= e($customer['email']) ?: '—' ?></dd></div>
        <div><dt><?= t('th_city') ?></dt><dd><?= e($customer['city']) ?: '—' ?></dd></div>
        <div><dt><?= t('th_value') ?></dt><dd><?= idr($customer['value']) ?></dd></div>
        <div><dt><?= t('th_last_contact') ?></dt><dd><?= e($customer['last_contact']) ?: '—' ?></dd></div>
        <div><dt><?= t('owner') ?></dt><dd><?= e($customer['owner'] ?? '') ?: '—' ?></dd></div>
    </dl>
    <?php if ($customer['note']): ?><p class="notes"><?= nl2br(e($customer['note'])) ?></p><?php endif; ?>
</div></div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('related_deals') ?></span><a class="btn btn-sm btn-ghost" href="<?= url('pipeline.create') ?>"><?= t('btn_add_deal') ?></a></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('th_deal') ?></th><th><?= t('th_stage') ?></th><th><?= t('th_close_date') ?></th><th class="right"><?= t('th_amount') ?></th></tr></thead>
        <tbody>
        <?php if (!$deals): ?><tr><td colspan="4" class="empty"><?= t('no_deal') ?></td></tr><?php endif; ?>
        <?php foreach ($deals as $d): ?>
            <tr><td><strong><?= e($d['name']) ?></strong></td>
                <td><span class="tag" style="background:<?= deal_stage_color($d['stage']) ?>22;color:<?= deal_stage_color($d['stage']) ?>"><?= e(tr_stage($d['stage'])) ?></span></td>
                <td><?= e($d['close_date']) ?></td><td class="right"><?= idr($d['value']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('order_records') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('th_order_no') ?></th><th><?= t('th_status') ?></th><th><?= t('th_date') ?></th></tr></thead>
        <tbody>
        <?php if (!$orders): ?><tr><td colspan="3" class="empty"><?= t('no_orders') ?></td></tr><?php endif; ?>
        <?php foreach ($orders as $o): ?>
            <tr class="clickable" onclick="location.href='<?= url('orders.show', ['id' => $o['id']]) ?>'">
                <td><code><?= e($o['order_no']) ?></code></td>
                <td><span class="order-status-badge <?= order_status_class($o['status']) ?>"><?= e(order_status_label($o['status'])) ?></span></td>
                <td><?= e($o['created_at']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
