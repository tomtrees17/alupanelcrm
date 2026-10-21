<?php
// Sub-navigation of 人力 / SDM (HR-00): 员工 / 合同 / BPJS. Contracts and BPJS arrive with HR-02 / HR-03.
$hrRoute = (string) ($_GET['r'] ?? 'hr.index');
?>
<nav class="task-filters hr-subnav">
    <a class="filter-btn <?= $hrRoute === 'hr.index' ? 'active' : '' ?>" href="<?= url('hr.index') ?>"><?= t('hr_todos') ?></a>
    <a class="filter-btn <?= in_array($hrRoute, ['hr.employees', 'hr.show', 'hr.create', 'hr.edit'], true) ? 'active' : '' ?>" href="<?= url('hr.employees') ?>"><?= t('hr_employees') ?></a>
    <span class="filter-btn disabled" title="HR-02"><?= t('hr_contracts') ?></span>
    <span class="filter-btn disabled" title="HR-03">BPJS</span>
</nav>
