<?php /** @var array $customers */ /** @var string $q */ /** @var string $tag */ ?>
<div class="page-head">
    <h1><?= t('page_customers') ?></h1>
    <div class="head-actions">
        <?php if (can_export()): ?><a class="btn btn-ghost" href="<?= url('customers.export') ?>"><?= t('btn_export') ?></a><?php endif; ?>
        <a class="btn btn-primary" href="<?= url('customers.create') ?>"><?= t('btn_add_customer') ?></a>
    </div>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="customers.index">
    <input class="form-input" type="text" name="q" value="<?= e($q) ?>" placeholder="<?= t('btn_search') ?>...">
    <select class="form-select filter-select" name="tag" onchange="this.form.submit()">
        <option value=""><?= t('all_tags') ?></option>
        <?php foreach (['重点', '潜在', '成交', '流失'] as $tg): ?>
            <option value="<?= $tg ?>" <?= $tag === $tg ? 'selected' : '' ?>><?= e(tr_tag($tg)) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= t('th_name') ?></th><th><?= t('th_company') ?></th><th><?= t('th_phone') ?></th><th><?= t('th_city') ?></th><?php if (!sees_only_own()): ?><th><?= t('owner') ?></th><?php endif; ?><th><?= t('th_tag') ?></th><th class="right"><?= t('th_value') ?></th></tr></thead>
            <tbody>
            <?php if (!$customers): ?><tr><td colspan="<?= sees_only_own() ? 6 : 7 ?>" class="empty"><?= t('no_customer') ?></td></tr><?php endif; ?>
            <?php foreach ($customers as $c): ?>
                <tr class="clickable" onclick="location.href='<?= url('customers.show', ['id' => $c['id']]) ?>'">
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td><?= e($c['company']) ?></td>
                    <td><?= e($c['phone']) ?></td>
                    <td><?= e($c['city']) ?></td>
                    <?php if (!sees_only_own()): ?><td><?= e($c['owner'] ?? '') ?: '<span class="muted">—</span>' ?></td><?php endif; ?>
                    <td><span class="tag <?= customer_tag_class($c['tag']) ?>"><?= e(tr_tag($c['tag'])) ?></span></td>
                    <td class="right"><?= idr($c['value']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
