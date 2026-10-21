<?php /** @var array $viewer */
// BPJS sub-links: overview / monthly list / rates (the last two: HR and the boss only).
$hrRoute = (string) ($_GET['r'] ?? '');
?>
<?php if (HrBpjs::can_see_money($viewer)): ?>
<nav class="task-filters">
    <a class="filter-btn <?= $hrRoute === 'hr.bpjs' ? 'active' : '' ?>" href="<?= url('hr.bpjs') ?>"><?= t('hrb_overview') ?></a>
    <a class="filter-btn <?= $hrRoute === 'hr.bpjs_monthly' ? 'active' : '' ?>" href="<?= url('hr.bpjs_monthly') ?>"><?= t('hrb_monthly') ?></a>
    <a class="filter-btn <?= $hrRoute === 'hr.bpjs_rates' ? 'active' : '' ?>" href="<?= url('hr.bpjs_rates') ?>"><?= t('hrb_rates') ?></a>
</nav>
<?php endif; ?>
