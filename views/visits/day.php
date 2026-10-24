<?php /** @var string $date */ /** @var array $rows */ /** @var array $per */ /** @var array $map */ /** @var array $legend */ /** @var int $located */ /** @var bool $canRecord */ /** @var bool $canImport */
include __DIR__ . '/_helpers.php';
$prev = HrContract::add_days($date, -1);
$next = HrContract::add_days($date, 1);
$w = $map['cols'] * 256;
$h = $map['rows'] * 256;
$cols = [...array_keys(Visits::OUTCOMES), Visits::UNKNOWN];
?>
<div class="page-head"><h1><?= t('vis_day') ?> · <?= e(Hr::dmy($date)) ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="inline-form" style="justify-content:flex-start;margin-bottom:12px">
    <a class="btn btn-ghost btn-sm" href="<?= url('visits.day', ['date' => $prev]) ?>">‹ <?= e(Hr::dmy($prev)) ?></a>
    <form method="get" action="index.php" class="inline-form"><input type="hidden" name="r" value="visits.day"><input class="form-input" type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()" style="max-width:170px"></form>
    <a class="btn btn-ghost btn-sm" href="<?= url('visits.day', ['date' => $next]) ?>"><?= e(Hr::dmy($next)) ?> ›</a>
</div>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('vis_sales') ?></th><th class="right"><?= t('vis_count') ?></th><th class="right">Lama / Baru</th>
        <?php foreach ($cols as $k): ?><th class="right"><?= e(Visits::outcome_label($k)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if (!$per): ?><tr><td colspan="<?= 3 + count($cols) ?>" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($per as $p): ?>
        <tr><td><?= e($p['sales']) ?></td><td class="right"><strong><?= $p['n'] ?></strong></td><td class="right"><?= $p['lama'] ?> / <?= $p['baru'] ?></td>
            <?php foreach ($cols as $k): ?><td class="right"><?= (int) ($p['outcomes'][$k] ?? 0) ?: '<span class="muted">·</span>' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>

<?php if ($located): ?>
<div class="card"><div class="card-body">
    <div class="map-wrap"><svg class="delivery-map" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= e(t('vis_map')) ?>">
        <g class="map-tiles"><?php for ($i = 0; $i < $map['cols']; $i++): ?><?php for ($j = 0; $j < $map['rows']; $j++): ?>
            <image href="https://tile.openstreetmap.org/<?= $map['zoom'] ?>/<?= $map['x0'] + $i ?>/<?= $map['y0'] + $j ?>.png" x="<?= $i * 256 ?>" y="<?= $j * 256 ?>" width="256" height="256"/>
        <?php endfor; ?><?php endfor; ?></g>
        <?php foreach ($map['points'] as $p): ?><circle class="vis-pt vis-c<?= $p['color'] % 8 ?><?= $p['far'] ? ' vis-pt-far' : '' ?>" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="7"><title><?= e($p['sales'] . ' · ' . $p['label']) ?></title></circle><?php endforeach; ?>
    </svg></div>
    <div class="map-legend small"><?php foreach ($legend as $l): ?><span><i class="dot vis-c<?= $l['i'] % 8 ?>"></i><?= e($l['name']) ?></span><?php endforeach; ?></div>
</div></div>
<?php endif; ?>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th></th><th><?= t('vis_sales') ?></th><th><?= t('vis_who') ?></th><th><?= t('vis_outcome') ?></th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $v): ?>
        <?php $odd = $v['source'] === 'wa_import' && ((string) $v['store_name_raw'] === '' || $v['photo_is_store'] === 'no' || $v['match_status'] === 'pending'); ?>
        <tr class="<?= $odd ? 'vis-far' : '' ?>">
            <td class="nowrap small"><?= e(substr((string) $v['visited_at'], 11, 5)) ?><?= (int) $v['late_flag'] ? ' <span class="tag tag-gray">' . t('vis_late') . '</span>' : '' ?></td>
            <td class="small"><?= e((string) $v['sales']) ?></td>
            <td><?= $targetLabel($v) ?><?php if (($v['status_lama_baru'] ?? null) && $v['system_lama_baru'] && $v['status_lama_baru'] !== $v['system_lama_baru']): ?> <span class="tag tag-orange" title="<?= t('vis_lb_mismatch') ?>"><?= e(ucfirst((string) $v['status_lama_baru'])) ?>≠<?= e(ucfirst((string) $v['system_lama_baru'])) ?></span><?php endif; ?></td>
            <td><?= $outcomeTag((string) $v['outcome']) ?><?= $v['outcome'] === Visits::UNKNOWN && $v['ai_outcome_guess'] ? ' <span class="muted small">AI: ' . e(Visits::outcome_label((string) $v['ai_outcome_guess'])) . '</span>' : '' ?></td>
            <td class="small"><?= e(mb_strimwidth(Visits::catatan($v), 0, 140, '…')) ?><?= $v['photo_path'] ? ' <a href="' . url('visits.photo', ['id' => $v['id']]) . '" target="_blank" rel="noopener">' . t('vis_photo') . '</a>' : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('vis_day_hint') ?></p>
