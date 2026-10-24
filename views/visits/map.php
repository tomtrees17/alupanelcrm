<?php /** @var string $week */ /** @var string $area */ /** @var array $areas */ /** @var array $map */ /** @var array $legend */ /** @var int $total */ /** @var bool $canRecord */
$w = $map['cols'] * 256;
$h = $map['rows'] * 256;
?>
<div class="page-head"><h1><?= t('vis_map') ?> · <?= e($week) ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="visits.map">
    <input class="form-input" type="week" name="week" value="<?= e($week) ?>" style="max-width:180px">
    <select class="form-select filter-select" name="area" onchange="this.form.submit()">
        <?php foreach ($areas as $a): ?><option value="<?= $a ?>" <?= $area === $a ? 'selected' : '' ?>><?= t('vis_area_' . $a) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>
<div class="card"><div class="card-body">
    <div class="map-wrap">
        <svg class="delivery-map" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= e(t('vis_map')) ?>">
            <g class="map-tiles">
                <?php for ($i = 0; $i < $map['cols']; $i++): ?><?php for ($j = 0; $j < $map['rows']; $j++): ?>
                    <image href="https://tile.openstreetmap.org/<?= $map['zoom'] ?>/<?= $map['x0'] + $i ?>/<?= $map['y0'] + $j ?>.png" x="<?= $i * 256 ?>" y="<?= $j * 256 ?>" width="256" height="256"/>
                <?php endfor; ?><?php endfor; ?>
            </g>
            <?php foreach ($map['points'] as $p): ?>
                <circle class="vis-pt vis-c<?= $p['color'] % 8 ?><?= $p['far'] ? ' vis-pt-far' : '' ?>" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="7"><title><?= e($p['sales'] . ' · ' . $p['label']) ?></title></circle>
            <?php endforeach; ?>
        </svg>
    </div>
    <div class="map-legend small">
        <?php foreach ($legend as $l): ?><span><i class="dot vis-c<?= $l['i'] % 8 ?>"></i><?= e($l['name']) ?></span><?php endforeach; ?>
    </div>
    <p class="muted small"><?= sprintf(t('vis_map_count'), count($map['points']), $total - count($map['points'])) ?>
        · Map © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors</p>
</div></div>
