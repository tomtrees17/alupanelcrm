<?php
// Sub-navigation of 人力 / SDM (HR-00): 待办 / 员工 / 合同 / BPJS. BPJS arrives with HR-03.
$hrRoute = (string) ($_GET['r'] ?? 'hr.index');
?>
<nav class="task-filters hr-subnav">
    <a class="filter-btn <?= $hrRoute === 'hr.index' ? 'active' : '' ?>" href="<?= url('hr.index') ?>"><?= t('hr_todos') ?></a>
    <a class="filter-btn <?= in_array($hrRoute, ['hr.employees', 'hr.show', 'hr.create', 'hr.edit'], true) ? 'active' : '' ?>" href="<?= url('hr.employees') ?>"><?= t('hr_employees') ?></a>
    <a class="filter-btn <?= str_starts_with($hrRoute, 'hr.contract') ? 'active' : '' ?>" href="<?= url('hr.contracts') ?>"><?= t('hr_contracts') ?></a>
    <span class="filter-btn disabled" title="HR-03">BPJS</span>
</nav>
