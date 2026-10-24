<?php /** @var string $week */ /** @var string $from */ /** @var string $to */ /** @var array $summary */ /** @var int $count */ /** @var bool $canRecord */ /** @var array $newProspects */ /** @var ?array $digest */ /** @var bool $aiOn */
$prev = Visits::week_of(HrContract::add_days($from, -7));
$next = Visits::week_of(HrContract::add_days($from, 7));
$cols = [...array_keys(Visits::OUTCOMES), Visits::UNKNOWN];
$tot = ['total' => 0, 'new' => 0, 'baru' => 0, 'np' => 0, 'counts' => array_fill_keys($cols, 0)];
foreach ($summary as $s) {
    $tot['total'] += $s['total'];
    $tot['new'] += $s['new'];
    $tot['baru'] += $s['baru'] ?? 0;
    $tot['np'] += (int) ($newProspects[$s['eid']] ?? 0);
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
    <thead><tr><th><?= t('vis_sales') ?></th><th class="right"><?= t('vis_count') ?></th><th class="right">Baru</th>
        <?php foreach ($cols as $k): ?><th class="right"><?= e(Visits::outcome_label($k)) ?></th><?php endforeach; ?>
        <th class="right"><?= t('vis_new_customers') ?></th><th class="right"><?= t('vis_new_prospects') ?></th></tr></thead>
    <tbody>
    <?php if (!$summary): ?><tr><td colspan="<?= 5 + count($cols) ?>" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($summary as $s): ?>
        <tr><td><?= e($s['sales']) ?></td><td class="right"><strong><?= $s['total'] ?></strong></td><td class="right"><?= (int) ($s['baru'] ?? 0) ?></td>
            <?php foreach ($cols as $k): ?><td class="right"><?= $cell($s['counts'][$k], $s['total']) ?></td><?php endforeach; ?>
            <td class="right"><?= $s['new'] ?></td><td class="right"><?= (int) ($newProspects[$s['eid']] ?? 0) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($summary): ?>
        <tr class="hr-total"><td><strong><?= t('hrb_total') ?></strong></td><td class="right"><strong><?= $tot['total'] ?></strong></td><td class="right"><?= $tot['baru'] ?></td>
            <?php foreach ($cols as $k): ?><td class="right"><?= $cell($tot['counts'][$k], $tot['total']) ?></td><?php endforeach; ?>
            <td class="right"><?= $tot['new'] ?></td><td class="right"><?= $tot['np'] ?></td></tr>
    <?php endif; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('vis_report_hint') ?></p>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_digest') ?></span>
        <?php if ($aiOn): ?><form method="post" action="<?= url('visits.digest', ['week' => $week]) ?>"><?= Csrf::field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= t($digest ? 'vis_digest_again' : 'vis_digest_make') ?></button></form><?php endif; ?></div>
    <div class="card-body">
        <?php if ($digest): ?>
            <p><?= nl2br(e((string) $digest['text_zh'])) ?></p><p class="muted"><?= nl2br(e((string) $digest['text_id'])) ?></p>
            <p class="muted small"><?= e((string) $digest['created_by']) ?> · <?= e(Hr::dmy(substr((string) $digest['created_at'], 0, 10))) ?></p>
        <?php else: ?><p class="muted"><?= t($aiOn ? 'vis_digest_none' : 'vis_digest_ai_off') ?></p><?php endif; ?>
    </div>
</div>
