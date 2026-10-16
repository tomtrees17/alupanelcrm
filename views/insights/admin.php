<?php
/** @var array $lists */ /** @var string $tab */ /** @var string $owner */ /** @var array $owners */ /** @var string $computedOn */
/** @var array $activity */ /** @var array $byIndustry */ /** @var array $byRegion */ /** @var array $quality */
/** @var array $map */ /** @var int $mapTotal */ /** @var array $mapOwners */
/** @var bool $showOwner */ /** @var string $route */ /** @var array $routeParams */
$pct = fn(int $a, int $b): string => $b > 0 ? (string) round(100 * $a / $b) . '%' : '—';
$maxAmt = function (array $rows): float {
    return max([1.0, ...array_values(array_map(fn($r) => (float) $r['amount'], $rows))]);
};
?>
<div class="page-head">
    <h1><?= t('insights_admin') ?></h1>
    <a class="btn btn-ghost" href="<?= url('insights.index') ?>"><?= t('ins_my_lists') ?></a>
</div>
<?php if ($computedOn === ''): ?>
    <div class="alert alert-error"><?= t('ins_not_computed') ?></div>
<?php else: ?>
    <p class="muted small"><?= sprintf(t('ins_updated'), e($computedOn)) ?></p>
<?php endif; ?>

<!-- 1. Activity per salesperson -->
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('ins_activity') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('owner') ?></th><th class="right"><?= t('ins_customers') ?></th><th class="right"><?= t('ins_fu30') ?></th><th class="right"><?= t('ins_processed') ?></th></tr></thead>
        <tbody>
        <?php if (!$activity): ?><tr><td colspan="4" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($activity as $name => $a): ?>
            <tr>
                <td><a href="<?= url('insights.admin', ['owner' => $name]) ?>#lists"><?= e((string) $name) ?></a></td>
                <td class="right"><?= (int) $a['customers'] ?></td>
                <td class="right"><?= (int) $a['fu30'] ?> <span class="muted small">(<?= $pct($a['fu30'], $a['customers']) ?>)</span></td>
                <td class="right"><?= $a['listed'] > 0 ? (int) $a['handled'] . ' / ' . (int) $a['listed'] . ' <span class="muted small">(' . $pct($a['handled'], $a['listed']) . ')</span>' : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small ins-note"><?= t('ins_activity_hint') ?></p>
</div>

<!-- 2. Distribution -->
<div class="grid-2">
    <?php foreach (['industry' => [$byIndustry, 'industry_label'], 'region' => [$byRegion, 'region_label']] as $k => [$rows, $label]): ?>
        <?php $mx = $maxAmt($rows); ?>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= t('ins_by_' . $k) ?></span></div>
            <div class="table-wrap"><table class="dist-table">
                <thead><tr><th><?= t('th_' . $k) ?></th><th class="right"><?= t('ins_customers') ?></th><th><?= t('ins_amount12') ?></th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $code => $d): ?>
                    <tr>
                        <td><?= e($label((string) $code)) ?></td>
                        <td class="right"><?= (int) $d['n'] ?></td>
                        <td class="dist-cell" title="<?= e(idr($d['amount'])) ?>">
                            <span class="dist-bar" style="width:<?= max(1, round(100 * $d['amount'] / $mx)) ?>%"></span>
                            <span class="dist-val"><?= idr_short($d['amount']) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- 3. Delivery map: OSM tiles + SVG points, no map library -->
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('ins_map') ?></span></div>
    <div class="card-body">
        <form class="searchbar" method="get" action="index.php">
            <input type="hidden" name="r" value="insights.admin">
            <select class="form-select filter-select" name="owner" onchange="this.form.submit()" aria-label="<?= t('owner') ?>">
                <option value=""><?= t('ins_all_sales') ?></option>
                <?php foreach ($owners as $o): ?><option value="<?= e((string) $o) ?>" <?= mb_strtolower($owner) === mb_strtolower((string) $o) ? 'selected' : '' ?>><?= e((string) $o) ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php
        $w = $map['cols'] * 256;
        $h = $map['rows'] * 256;
        $amts = array_column($map['points'], 'amount');
        $max = $amts ? max($amts) : 1;
        $sel = mb_strtolower($owner);
        // Draw the highlighted salesperson's points last, so they sit on top.
        $pts = $map['points'];
        usort($pts, fn($a, $b) => [(int) ($sel !== '' && mb_strtolower($a['owner']) === $sel), -$a['amount']] <=> [(int) ($sel !== '' && mb_strtolower($b['owner']) === $sel), -$b['amount']]);
        ?>
        <div class="map-wrap">
            <svg class="delivery-map" viewBox="0 0 <?= $w ?> <?= $h ?>" role="img" aria-label="<?= e(sprintf(t('ins_map_aria'), count($map['points']))) ?>">
                <g class="map-tiles">
                    <?php for ($i = 0; $i < $map['cols']; $i++): ?><?php for ($j = 0; $j < $map['rows']; $j++): ?>
                        <image href="https://tile.openstreetmap.org/<?= $map['zoom'] ?>/<?= $map['x0'] + $i ?>/<?= $map['y0'] + $j ?>.png" x="<?= $i * 256 ?>" y="<?= $j * 256 ?>" width="256" height="256"/>
                    <?php endfor; ?><?php endfor; ?>
                </g>
                <?php foreach ($pts as $p): ?>
                    <?php
                    $mine = $sel !== '' && mb_strtolower($p['owner']) === $sel;
                    $cls = $sel === '' ? 'pt-all' : ($mine ? 'pt-sel' : 'pt-other');
                    $r = round(3 + 9 * sqrt(max(0.0, $p['amount']) / max(1.0, $max)), 1);
                    ?>
                    <circle class="<?= $cls ?>" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="<?= $r ?>"><title><?= e($p['customer'] . ' · ' . ($p['owner'] ?: '—') . ' · ' . idr($p['amount']) . ' · ' . $p['day']) ?></title></circle>
                <?php endforeach; ?>
            </svg>
        </div>
        <div class="map-legend small">
            <?php if ($sel === ''): ?>
                <span><i class="dot pt-all"></i><?= t('ins_map_all') ?></span>
            <?php else: ?>
                <span><i class="dot pt-sel"></i><?= e($owner) ?></span><span><i class="dot pt-other"></i><?= t('ins_map_others') ?></span>
            <?php endif; ?>
            <span class="muted"><?= t('ins_map_size') ?></span>
        </div>
        <p class="muted small">
            <?= sprintf(t('ins_map_count'), count($map['points']), $mapTotal - count($map['points'])) ?>
            · Map © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors
        </p>
    </div>
</div>

<!-- 4. All call lists, filterable by salesperson -->
<div class="card" id="lists">
    <div class="card-header"><span class="card-title"><?= t('ins_all_lists') ?><?= $owner !== '' ? ' · ' . e($owner) : '' ?></span>
        <?php if ($owner !== ''): ?><a class="btn btn-ghost btn-sm" href="<?= url('insights.admin', ['tab' => $tab]) ?>#lists"><?= t('ins_all_sales') ?></a><?php endif; ?>
    </div>
    <div class="card-body">
        <?php include __DIR__ . '/_lists.php'; ?>
    </div>
</div>

<!-- 5. Data quality -->
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('ins_quality') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('owner') ?></th><th class="right"><?= t('ins_customers') ?></th><th class="right"><?= t('ins_q_industry') ?></th><th class="right"><?= t('ins_q_region') ?></th><th class="right"><?= t('ins_q_phone') ?></th></tr></thead>
        <tbody>
        <?php if (!$quality): ?><tr><td colspan="5" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($quality as $name => $q): ?>
            <tr>
                <td><?= $name === '' ? '<span class="muted">' . t('cleanup_no_owner') . '</span>' : e((string) $name) ?></td>
                <td class="right"><?= (int) $q['customers'] ?></td>
                <?php foreach (['industry', 'region', 'phone'] as $k): ?>
                    <td class="right<?= $q[$k] > 0 ? ' q-gap' : '' ?>"><?= (int) $q[$k] ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small ins-note"><?= t('ins_quality_hint') ?></p>
</div>
