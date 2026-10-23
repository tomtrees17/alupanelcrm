<?php /** @var array $rows */ /** @var string $q */ /** @var string $only */ /** @var array $pg */ /** @var array $stats */ /** @var string $nextNo */ ?>
<div class="page-head">
    <div>
        <h1><?= t('co_register') ?></h1>
        <p class="muted"><?= t('co_register_sub') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('orders.index') ?>"><?= t('btn_back') ?></a>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card"><div class="stat-label"><?= t('co_stat_total') ?></div><div class="stat-value"><?= num((int) ($stats['total'] ?? 0)) ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('co_stat_open') ?></div><div class="stat-value"><?= num((int) ($stats['open'] ?? 0)) ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('co_stat_last') ?></div><div class="stat-value" style="font-size:18px"><?= $stats['max_seq'] ? sprintf('%04d', (int) $stats['max_seq']) : '—' ?></div><div class="stat-sub muted"><?= e(substr((string) ($stats['last_issued'] ?? ''), 0, 16)) ?></div></div>
    <div class="stat-card c1"><div class="stat-label"><?= t('co_stat_next') ?></div><div class="stat-value c1" style="font-size:18px"><?= e($nextNo) ?></div></div>
</div>

<div class="card" style="margin-bottom:12px">
    <div class="card-header"><span class="card-title"><?= t('co_reserve_title') ?></span><span class="muted small"><?= t('co_reserve_sub') ?></span></div>
    <form method="post" action="<?= url('orders.co_reserve') ?>" class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= Csrf::field() ?>
        <?php if (!empty($canPickSales)): ?>
        <div class="form-group" style="margin:0;min-width:140px"><label class="form-label"><?= t('th_sales_name') ?></label>
            <input class="form-input" name="sales_name" list="co-sales" autocomplete="off" value="<?= e(own_name()) ?>">
            <datalist id="co-sales"><?php foreach (($salesStaff ?? []) as $sn): ?><option value="<?= e($sn) ?>"></option><?php endforeach; ?></datalist>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin:0;min-width:200px"><label class="form-label"><?= t('th_company') ?></label><input class="form-input" name="company"></div>
        <div class="form-group" style="margin:0;min-width:160px"><label class="form-label"><?= t('th_client') ?></label><input class="form-input" name="client_name"></div>
        <div class="form-group" style="margin:0;min-width:140px"><label class="form-label"><?= t('th_phone') ?></label><input class="form-input" name="phone"></div>
        <button class="btn btn-primary" type="submit"><?= t('co_reserve_btn') ?> <?= e($nextNo) ?></button>
    </form>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="orders.co_index">
    <?php if ($only !== ''): ?><input type="hidden" name="only" value="<?= e($only) ?>"><?php endif; ?>
    <input class="form-input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('co_search') ?>">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= url('orders.co_index', array_filter(['only' => $only])) ?>"><?= t('btn_clear') ?></a><?php endif; ?>
</form>

<div class="task-filters">
    <?php foreach (['' => t('filter_all'), 'open' => t('co_only_open'), 'linked' => t('co_only_linked')] as $k => $lbl): ?>
        <a class="filter-btn <?= $only === $k ? 'active' : '' ?>" href="<?= url('orders.co_index', array_filter(['only' => $k, 'q' => $q])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('th_co_no') ?></th><th><?= t('th_issued_at') ?></th><th><?= t('th_sales_name') ?></th><th><?= t('th_company') ?></th><th><?= t('th_client') ?></th><th><?= t('th_phone') ?></th><th><?= t('th_order_no') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= (int) ($stats['total'] ?? 0) === 0 ? t('co_not_imported') : t('co_no_rows') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><code><?= e($r['co_no']) ?></code></td>
            <td class="muted small"><?= e(substr((string) $r['issued_at'], 0, 16)) ?></td>
            <td><?= e($r['sales_name']) ?></td>
            <td><?= e($r['company']) ?></td>
            <td><?= e($r['client_name']) ?></td>
            <td class="muted small"><?= e($r['phone']) ?></td>
            <td>
                <?php if ($r['order_id'] !== null): ?>
                    <a href="<?= url('orders.show', ['id' => $r['order_id']]) ?>"><?= e($r['order_no']) ?></a>
                    <span class="tag <?= order_status_class((string) $r['order_status']) ?>"><?= e(order_status_label((string) $r['order_status'])) ?></span>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?= pager('orders.co_index', ['q' => $q, 'only' => $only], $pg) ?>
