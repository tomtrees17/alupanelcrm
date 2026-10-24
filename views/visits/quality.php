<?php /** @var string $week */ /** @var string $from */ /** @var string $to */ /** @var array $rows */ /** @var bool $canRecord */ /** @var bool $canImport */
$pct = fn($a, $b) => $b ? round(100 * (int) $a / (int) $b) . '%' : '—';
$prev = Visits::week_of(HrContract::add_days($from, -7));
$next = Visits::week_of(HrContract::add_days($from, 7));
?>
<div class="page-head"><h1><?= t('vis_quality') ?> · <?= e($week) ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="inline-form" style="justify-content:flex-start;margin-bottom:12px">
    <a class="btn btn-ghost btn-sm" href="<?= url('visits.quality', ['week' => $prev]) ?>">‹ <?= e($prev) ?></a><span><?= e(Hr::dmy($from)) ?> – <?= e(Hr::dmy($to)) ?></span><a class="btn btn-ghost btn-sm" href="<?= url('visits.quality', ['week' => $next]) ?>"><?= e($next) ?> ›</a>
</div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('vis_sales') ?></th><th class="right"><?= t('vis_count') ?></th><th class="right"><?= t('vis_q_template') ?></th><th class="right"><?= t('vis_q_unknown') ?></th>
        <th class="right"><?= t('vis_q_not_store') ?></th><th class="right"><?= t('vis_q_late') ?></th><th class="right"><?= t('vis_q_four') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td><?= e((string) $r['sales']) ?></td><td class="right"><?= (int) $r['n'] ?></td><td class="right"><?= $pct($r['template'], $r['n']) ?></td><td class="right"><?= $pct($r['unknown'], $r['n']) ?></td>
            <td class="right"><?= $pct($r['not_store'], $r['photo_read']) ?></td><td class="right"><?= $pct($r['late'], $r['n']) ?></td><td class="right"><?= $pct($r['four_line'], $r['n']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('vis_quality_hint') ?></p>
