<?php
/** @var array $lists */ /** @var string $tab */ /** @var string $computedOn */ /** @var bool $canSeeAll */
/** @var bool $showOwner */ /** @var string $route */ /** @var array $routeParams */ /** @var array $owners */ /** @var string $owner */
?>
<div class="page-head">
    <h1><?= t('nav_insights') ?></h1>
    <div class="head-actions">
        <?php if (!empty($canExport)): ?><a class="btn btn-ghost" href="<?= url('insights.export', array_filter(['owner' => $owner])) ?>"><?= t('btn_export') ?></a><?php endif; ?>
        <?php if ($canSeeAll): ?><a class="btn btn-ghost" href="<?= url('insights.admin') ?>"><?= t('insights_admin') ?></a><?php endif; ?>
    </div>
</div>
<?php if ($computedOn === ''): ?>
    <div class="alert alert-error"><?= t('ins_not_computed') ?></div>
<?php else: ?>
    <p class="muted small"><?= sprintf(t('ins_updated'), e($computedOn)) ?></p>
<?php endif; ?>

<?php if ($canSeeAll && !empty($owners)): ?>
<nav class="ins-owners" aria-label="<?= t('owner') ?>">
    <a class="ins-chip<?= $owner === '' ? ' active' : '' ?>" href="<?= url('insights.index', ['tab' => $tab]) ?>"><?= t('ins_all_sales') ?> <b><?= array_sum($owners) ?></b></a>
    <?php foreach ($owners as $name => $n): ?>
        <a class="ins-chip<?= mb_strtolower($owner) === mb_strtolower((string) $name) ? ' active' : '' ?>" href="<?= url('insights.index', ['tab' => $tab, 'owner' => (string) $name]) ?>"><?= $name === '-' ? t('ins_unassigned') : e((string) $name) ?> <b><?= (int) $n ?></b></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php include __DIR__ . '/_lists.php'; ?>
