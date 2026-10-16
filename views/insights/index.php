<?php
/** @var array $lists */ /** @var string $tab */ /** @var string $computedOn */ /** @var bool $canSeeAll */
/** @var bool $showOwner */ /** @var string $route */ /** @var array $routeParams */
?>
<div class="page-head">
    <h1><?= t('nav_insights') ?></h1>
    <?php if ($canSeeAll): ?><a class="btn btn-ghost" href="<?= url('insights.admin') ?>"><?= t('insights_admin') ?></a><?php endif; ?>
</div>
<?php if ($computedOn === ''): ?>
    <div class="alert alert-error"><?= t('ins_not_computed') ?></div>
<?php else: ?>
    <p class="muted small"><?= sprintf(t('ins_updated'), e($computedOn)) ?></p>
<?php endif; ?>

<?php include __DIR__ . '/_lists.php'; ?>
