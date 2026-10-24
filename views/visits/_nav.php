<?php /** @var bool $canRecord */
// Management sub-navigation: list / weekly report / map / prospects.
$r = (string) ($_GET['r'] ?? '');
?>
<nav class="task-filters">
    <a class="filter-btn <?= $r === 'visits.list' ? 'active' : '' ?>" href="<?= url('visits.list') ?>"><?= t('vis_list') ?></a>
    <a class="filter-btn <?= $r === 'visits.report' ? 'active' : '' ?>" href="<?= url('visits.report') ?>"><?= t('vis_report') ?></a>
    <a class="filter-btn <?= $r === 'visits.map' ? 'active' : '' ?>" href="<?= url('visits.map') ?>"><?= t('vis_map') ?></a>
    <a class="filter-btn <?= $r === 'visits.prospects' ? 'active' : '' ?>" href="<?= url('visits.prospects') ?>"><?= t('vis_prospects') ?></a>
    <?php if (!empty($canRecord)): ?><a class="filter-btn" href="<?= url('visits.index') ?>"><?= t('vis_mine') ?></a><?php endif; ?>
</nav>
