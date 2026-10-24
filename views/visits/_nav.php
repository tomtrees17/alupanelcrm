<?php /** @var bool $canRecord */
// Sub-navigation inside the 拜访 tab (HR-04): day / week / queue / list / customers / competitors / map; import for the assistant.
// 潜客 is its own tab of the 客户 group (layout).
$r = (string) ($_GET['r'] ?? '');
$all = Visits::sees_all();
$imp = !empty($canImport);
$boss = isset($auth) && $auth->isAdmin();
$tab = fn(string $route, string $label) => '<a class="filter-btn ' . ($r === $route ? 'active' : '') . '" href="' . url($route) . '">' . t($label) . '</a>';
?>
<nav class="task-filters">
    <?php if ($all): ?>
        <?= $tab('visits.day', 'vis_day') ?><?= $tab('visits.report', 'vis_report') ?><?= $tab('visits.queue', 'vis_queue') ?><?= $tab('visits.list', 'vis_list') ?>
        <?= $tab('visits.customers', 'vis_customers') ?><?= $tab('visits.competitors', 'vis_competitors') ?><?= $tab('visits.map', 'vis_map') ?>
        <?php if ($boss): ?><?= $tab('visits.quality', 'vis_quality') ?><?php endif; ?>
    <?php endif; ?>
    <?php if ($imp): ?><?= $tab('visits.import', 'vis_import') ?><?= $tab('visits.senders', 'vis_senders') ?><?php endif; ?>
    <?php if (!empty($canRecord)): ?><a class="filter-btn" href="<?= url('visits.index') ?>"><?= t('vis_mine') ?></a><?php endif; ?>
</nav>
