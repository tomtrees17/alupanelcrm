<?php
/** @var array $plan */ /** @var array $back */
// CM-01: the last look before a merge — what moves where.
$k = $plan['keep'];
$labels = ['orders' => t('cm_t_orders'), 'deals' => t('cm_t_deals'), 'tasks' => t('cm_t_tasks'), 'customer_followups' => t('cm_t_followups'), 'visits' => t('cm_t_visits'),
           'sales_history' => t('cm_t_history'), 'customer_name_aliases' => t('cm_t_aliases'), 'bank_book' => t('cm_t_bank'), 'customer_payer_aliases' => t('cm_t_payers')];
?>
<div class="page-head">
    <h1><?= t('cm_confirm_title') ?></h1>
    <a class="btn btn-ghost" href="<?= url($back[0], $back[1]) ?>"><?= t('btn_back') ?></a>
</div>

<div class="card cm-confirm"><div class="card-body">
    <p><?= t('cm_keep_label') ?>: <strong>#<?= (int) $k['id'] ?> <?= e($k['name']) ?></strong><?= $k['company'] ? ' · ' . e($k['company']) : '' ?><?= $k['phone'] ? ' · ' . e($k['phone']) : '' ?><?= $k['owner'] ? ' · ' . e($k['owner']) : '' ?></p>
    <p><?= t('cm_from_label') ?>:</p>
    <ul>
        <?php foreach ($plan['from'] as $f): ?>
            <li>#<?= (int) $f['id'] ?> <?= e($f['name']) ?><?= $f['company'] ? ' · ' . e($f['company']) : '' ?><?= $f['phone'] ? ' · ' . e($f['phone']) : '' ?><?= $f['owner'] ? ' · ' . e($f['owner']) : '' ?></li>
        <?php endforeach; ?>
    </ul>
    <p><?= t('cm_moves') ?>:
        <?php $moves = array_filter($plan['counts']); ?>
        <?= $moves ? e(implode(' · ', array_map(fn($t, $n) => ($labels[$t] ?? $t) . ' ' . $n, array_keys($moves), $moves))) : t('cm_moves_none') ?>
    </p>
    <?php if ($plan['owners']): ?>
        <div class="alert alert-info"><?= sprintf(t('cm_owner_warn'), e(implode(' / ', $plan['owners'])), e((string) ($k['owner'] ?: '—'))) ?></div>
    <?php endif; ?>
    <p class="muted small"><?= t('cm_confirm_hint') ?></p>
    <form method="post" action="<?= url('customers.merge') ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="keep" value="<?= (int) $k['id'] ?>"><input type="hidden" name="confirm" value="1">
        <?php foreach ($plan['from'] as $f): ?><input type="hidden" name="from[]" value="<?= (int) $f['id'] ?>"><?php endforeach; ?>
        <button class="btn btn-primary" type="submit"><?= t('cm_btn_merge') ?></button>
    </form>
</div></div>
