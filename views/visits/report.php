<?php /** @var string $week */ /** @var string $from */ /** @var string $to */ /** @var array $summary */ /** @var int $count */ /** @var bool $canRecord */
$prev = Visits::week_of(HrContract::add_days($from, -7));
$next = Visits::week_of(HrContract::add_days($from, 7));
$tot = ['total' => 0, 'new' => 0, 'counts' => array_fill_keys(array_keys(Visits::OUTCOMES), 0)];
foreach ($summary as $s) {
    $tot['total'] += $s['total'];
    $tot['new'] += $s['new'];
    foreach ($s['counts'] as $k => $n) {
        $tot['counts'][$k] += $n;
    }
}
$cell = fn(int $n, int $total) => $n . ($total ? ' <span class="muted small">(' . round(100 * $n / $total) . '%)</span>' : '');
?>
<div class="page-head">
    <h1><?= t('vis_report') ?> · <?= e($week) ?></h1>
    <a class="btn btn-ghost" href="<?= url('visits.export', ['week' => $week]) ?>"><?= t('vis_export') ?></a>
</div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="inline-form" style="justify-content:flex-start;margin-bottom:12px">
    <a class="btn btn-ghost btn-sm" href="<?= url('visits.report', ['week' => $prev]) ?>">‹ <?= e($prev) ?></a>
    <span><?= e(Hr::dmy($from)) ?> – <?= e(Hr::dmy($to)) ?></span>
    <a class="btn btn-ghost btn-sm" href="<?= url('visits.report', ['week' => $next]) ?>"><?= e($next) ?> ›</a>
</div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('vis_sales') ?></th><th class="right"><?= t('vis_count') ?></th>
        <?php foreach (Visits::OUTCOMES as $label): ?><th class="right"><?= e($label) ?></th><?php endforeach; ?>
        <th class="right"><?= t('vis_new_customers') ?></th></tr></thead>
    <tbody>
    <?php if (!$summary): ?><tr><td colspan="<?= 3 + count(Visits::OUTCOMES) ?>" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($summary as $s): ?>
        <tr><td><?= e($s['sales']) ?></td><td class="right"><strong><?= $s['total'] ?></strong></td>
            <?php foreach (array_keys(Visits::OUTCOMES) as $k): ?><td class="right"><?= $cell($s['counts'][$k], $s['total']) ?></td><?php endforeach; ?>
            <td class="right"><?= $s['new'] ?></td></tr>
    <?php endforeach; ?>
    <?php if ($summary): ?>
        <tr class="hr-total"><td><strong><?= t('hrb_total') ?></strong></td><td class="right"><strong><?= $tot['total'] ?></strong></td>
            <?php foreach (array_keys(Visits::OUTCOMES) as $k): ?><td class="right"><?= $cell($tot['counts'][$k], $tot['total']) ?></td><?php endforeach; ?>
            <td class="right"><?= $tot['new'] ?></td></tr>
    <?php endif; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('vis_report_hint') ?></p>
