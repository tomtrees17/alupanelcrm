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

<?php
$ind = (string) ($customer['industry'] ?? '');
$src = (string) ($customer['industry_source'] ?? '');
$pending = (int) ($customer['industry_pending'] ?? 0) === 1 && $src !== 'manual';
$dist = json_decode((string) ($customer['delivery_regions'] ?? ''), true) ?: [];
?>
<div class="card" id="analysis">
    <div class="card-header"><span class="card-title"><?= t('cust_analysis') ?></span></div>
    <div class="card-body">
        <dl class="detail">
            <div><dt><?= t('th_industry') ?></dt><dd>
                <strong><?= e(industry_label($ind)) ?></strong>
                <?php if ($pending): ?><span class="tag tag-orange"><?= t('ind_pending') ?></span><?php endif; ?>
                <?php if ($src !== ''): ?>
                    <div class="muted small"><?= e(industry_source_label($src)) ?><?php if ($src === 'ai' && ($customer['industry_confidence'] ?? null) !== null): ?> · <?= t('ind_confidence') ?> <?= (int) round((float) $customer['industry_confidence'] * 100) ?>%<?php endif; ?><?php if (!empty($customer['industry_note'])): ?> · <?= e($customer['industry_note']) ?><?php endif; ?></div>
                <?php endif; ?>
            </dd></div>
            <div><dt><?= t('th_region') ?></dt><dd>
                <?= ($customer['region'] ?? null) === null ? '<span class="muted">' . t('reg_none') . '</span>' : e(region_label((string) $customer['region'])) ?>
                <?php if (count($dist) > 1): ?>
                    <div class="muted small"><?= t('reg_delivery_spread') ?>: <?php $bits = []; foreach ($dist as $r => $n) { $bits[] = e(region_label((string) $r)) . ' ' . (int) $n; } echo implode(' · ', $bits); ?></div>
                <?php endif; ?>
            </dd></div>
        </dl>
        <form class="industry-form" method="post" action="<?= url('customers.set_industry') ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
            <select class="form-select" name="industry" aria-label="<?= t('th_industry') ?>">
                <?php if ($ind === ''): ?><option value="" disabled selected><?= t('ind_choose') ?></option><?php endif; ?>
                <?php foreach (Enrich::INDUSTRIES as $code): ?>
                    <option value="<?= $code ?>" <?= $ind === $code ? 'selected' : '' ?>><?= e(industry_label($code)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn <?= $pending || $ind === '' ? 'btn-primary' : 'btn-ghost' ?>" type="submit"><?= $src === 'manual' ? t('btn_save') : t('ind_confirm') ?></button>
        </form>
        <?php if ($src !== 'manual'): ?><p class="muted small"><?= t('ind_confirm_hint') ?></p><?php endif; ?>
    </div>
</div>

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
