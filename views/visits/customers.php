<?php /** @var string $q */ /** @var array $found */ /** @var array $history */ /** @var array $often */ /** @var array $neglected */ /** @var string $month */ /** @var bool $canRecord */ /** @var bool $canImport */
include __DIR__ . '/_helpers.php';
?>
<div class="page-head"><h1><?= t('vis_customers') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="visits.customers">
    <input class="form-input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= t('vis_customer_search') ?>">
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>
<?php if ($q !== ''): ?>
<div class="card">
    <div class="card-body small"><?php foreach ($found as $c): ?><a class="filter-btn" href="<?= url('visits.customers', ['q' => $q, 'id' => $c['id']]) ?>"><?= e($c['name']) ?></a> <?php endforeach; ?><?= $found ? '' : t('no_data') ?></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_date') ?></th><th><?= t('vis_sales') ?></th><th><?= t('vis_outcome') ?></th><th>Catatan</th></tr></thead>
        <tbody>
        <?php if (!$history): ?><tr><td colspan="4" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($history as $v): ?>
            <tr><td class="nowrap"><?= e(Hr::dmy(substr((string) $v['visited_at'], 0, 10))) ?></td><td><?= e((string) $v['sales']) ?></td><td><?= $outcomeTag((string) $v['outcome']) ?></td><td class="small"><?= e(Visits::catatan($v)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= sprintf(t('vis_often'), e($month)) ?></span><span class="muted small"><?= count($often) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_who') ?></th><th class="right"><?= t('vis_count') ?></th><th><?= t('vis_sales') ?></th></tr></thead>
        <tbody>
        <?php if (!$often): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($often as $c): ?><tr><td><a href="<?= url('visits.customers', ['q' => $c['name'], 'id' => $c['id']]) ?>"><?= e($c['name']) ?></a></td><td class="right"><?= (int) $c['n'] ?></td><td class="small"><?= e((string) $c['who']) ?></td></tr><?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_neglected') ?></span><span class="muted small"><?= count($neglected) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_who') ?></th><th><?= t('owner') ?></th><th><?= t('vis_last_visit') ?></th></tr></thead>
        <tbody>
        <?php if (!$neglected): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($neglected as $c): ?><tr><td><a href="<?= url('visits.customers', ['q' => $c['name'], 'id' => $c['id']]) ?>"><?= e($c['name']) ?></a></td><td class="small"><?= e((string) $c['owner']) ?></td>
            <td class="small"><?= $c['last_visit'] ? e(Hr::dmy(substr((string) $c['last_visit'], 0, 10))) : '<span class="muted">' . t('vis_never') . '</span>' ?></td></tr><?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="card-body muted small"><?= t('vis_neglected_hint') ?></p>
</div>
