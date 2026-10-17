<?php
/** @var array $period */ /** @var array $buckets */ /** @var array $kpi */ /** @var array $hot */
/** @var array $recent */ /** @var bool $fin */ /** @var bool $perf */
/** @var ?array $receivable */ /** @var ?array $offsite */ /** @var ?array $workload */ /** @var ?array $ranking */
$lang = current_lang();
$p = $period['p'];
$link = fn(string $pp, string $d) => url('dashboard.index', ['p' => $pp, 'd' => $d]);

// Bucket label: short enough for 12–14 columns on a phone.
$idMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$bucketLabel = function (array $b) use ($p, $lang, $idMonths): string {
    $ts = strtotime($b['start']);
    if ($p === 'month') {
        return $lang === 'id' ? $idMonths[(int) date('n', $ts) - 1] : date('n', $ts) . '月';
    }
    return $lang === 'id' ? date('j/n', $ts) : date('n/j', $ts);
};

// Period title, e.g. 本月 · 2026年10月 / Minggu ini · 6/10 – 12/10
$ts = strtotime($period['start']);
$title = match ($p) {
    'day'   => $period['start'],
    'week'  => ($lang === 'id' ? date('j/n', $ts) . ' – ' . date('j/n', strtotime($period['end']))
                               : date('n/j', $ts) . ' – ' . date('n/j', strtotime($period['end']))),
    default => ($lang === 'id' ? $idMonths[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts)
                               : date('Y', $ts) . '年' . date('n', $ts) . '月'),
};
if ($period['current']) {
    $title = t('dash_now_' . $p) . ' · ' . $title;
}
$vsLabel = t('dash_vs_' . $p . ($period['partial'] ? '_partial' : ''));

/** ▲ 12% / ▼ 8% / — ; up is good for every tile on this page. */
$delta = function (float $now, float $before) use ($vsLabel): string {
    $d = Dashboard::delta($now, $before);
    if ($d === null) {
        return '<span class="delta flat">' . e($vsLabel) . ' —</span>';
    }
    $cls = abs($d) < 0.5 ? 'flat' : ($d > 0 ? 'up' : 'down');
    $arrow = $cls === 'up' ? '▲' : ($cls === 'down' ? '▼' : '■');
    return '<span class="delta ' . $cls . '">' . $arrow . ' ' . num(abs($d), abs($d) < 10 ? 1 : 0) . '%</span>'
         . ' <span class="muted">' . e($vsLabel) . '</span>';
};

$axisMax = Dashboard::nice_max(max(1.0, ...array_map(fn($b) => max($b['sales'], $b['collected']), $buckets)));
$pct = fn(float $v) => $v <= 0 ? 0 : max(1.5, round($v / $axisMax * 100, 1));
?>

<div class="dash">
<?php if (!empty($offsite)): ?>
    <div class="alert alert-error"><?= e(vsprintf(t($offsite[0]), $offsite[1])) ?></div>
<?php endif; ?>
<!-- ── Period selector: one control scopes everything below ── -->
<div class="period-bar">
    <div class="seg" role="tablist">
        <?php foreach (Dashboard::PERIODS as $pp): ?>
            <a class="<?= $pp === $p ? 'active' : '' ?>" role="tab" aria-selected="<?= $pp === $p ? 'true' : 'false' ?>"
               href="<?= $link($pp, $period['current'] ? date('Y-m-d') : $period['start']) ?>"><?= t('dash_' . $pp) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="period-nav">
        <a class="btn btn-ghost btn-sm" href="<?= $link($p, $period['prev']) ?>" aria-label="<?= t('dash_prev') ?>">‹</a>
        <span class="period-title"><?= e($title) ?></span>
        <?php if ($period['next'] !== null): ?>
            <a class="btn btn-ghost btn-sm" href="<?= $link($p, $period['next']) ?>" aria-label="<?= t('dash_next') ?>">›</a>
        <?php else: ?>
            <span class="btn btn-ghost btn-sm btn-disabled" aria-hidden="true">›</span>
        <?php endif; ?>
        <?php if (!$period['current']): ?>
            <a class="btn btn-ghost btn-sm" href="<?= $link($p, date('Y-m-d')) ?>"><?= t('dash_back_now') ?></a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Headline tiles ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label"><?= t($perf ? 'dash_sales' : 'dash_my_sales') ?></div>
        <div class="stat-value"><?= idr_short($kpi['sales']) ?></div>
        <div class="stat-sub"><?= $delta($kpi['sales'], $kpi['sales_cmp']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= t($perf ? 'dash_orders' : 'dash_my_orders') ?></div>
        <div class="stat-value"><?= num($kpi['orders']) ?></div>
        <div class="stat-sub"><?= $delta((float) $kpi['orders'], (float) $kpi['orders_cmp']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label"><?= t($perf ? 'dash_sheets_total' : 'dash_my_sheets') ?></div>
        <div class="stat-value"><?= num($kpi['sheets']) ?></div>
        <div class="stat-sub"><?= $delta($kpi['sheets'], $kpi['sheets_cmp']) ?></div>
    </div>
    <?php if ($fin): ?>
        <div class="stat-card">
            <div class="stat-label"><?= t('dash_collected') ?></div>
            <div class="stat-value"><?= idr_short($kpi['collected']) ?></div>
            <div class="stat-sub"><?= $delta($kpi['collected'], $kpi['collected_cmp']) ?></div>
        </div>
        <a class="stat-card stat-link" href="<?= url('finance.index', ['status' => 'overdue']) ?>">
            <div class="stat-label"><?= t('dash_receivable') ?> <span class="muted">· <?= t('dash_as_of_now') ?></span></div>
            <div class="stat-value"><?= idr_short($receivable['total']) ?></div>
            <div class="stat-sub">
                <?php if ($receivable['overdue'] > 0.5): ?>
                    <span class="delta down">⚠ <?= sprintf(t('dash_overdue_part'), idr_short($receivable['overdue']), (int) $receivable['overdue_count']) ?></span>
                <?php else: ?>
                    <span class="delta up">✓ <?= t('dash_no_overdue') ?></span>
                <?php endif; ?>
            </div>
        </a>
    <?php else: ?>
        <?php if ($workload['to_approve'] !== null): ?>
            <a class="stat-card stat-link" href="<?= url('orders.index') ?>">
                <div class="stat-label"><?= t('dash_to_approve') ?></div>
                <div class="stat-value"><?= (int) $workload['to_approve'] ?></div>
                <div class="stat-sub muted"><?= t('dash_as_of_now') ?></div>
            </a>
        <?php else: ?>
            <a class="stat-card stat-link" href="<?= url('orders.index') ?>">
                <div class="stat-label"><?= t('dash_in_approval') ?></div>
                <div class="stat-value"><?= (int) $workload['pending'] ?></div>
                <div class="stat-sub muted"><?= t('dash_as_of_now') ?></div>
            </a>
        <?php endif; ?>
        <a class="stat-card stat-link" href="<?= url('orders.index', ['status' => 'draft']) ?>">
            <div class="stat-label"><?= t('dash_rework') ?></div>
            <div class="stat-value"><?= (int) $workload['rework'] ?></div>
            <div class="stat-sub muted"><?= t('dash_as_of_now') ?></div>
        </a>
    <?php endif; ?>
</div>

<!-- ── Trend: invoiced vs collected, one shared axis ── -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><?= t('dash_trend_' . $p . ($fin ? '' : '_sales')) ?></span>
        <?php if ($fin): ?>
            <div class="chart-legend">
                <span><i class="sw sales"></i><?= t('dash_series_sales') ?></span>
                <span><i class="sw paid"></i><?= t('dash_series_collected') ?></span>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="dchart">
            <div class="dchart-axis" aria-hidden="true">
                <span style="bottom:100%"><?= idr_short($axisMax) ?></span>
                <span style="bottom:50%"><?= idr_short($axisMax / 2) ?></span>
                <span style="bottom:0">0</span>
            </div>
            <div class="dchart-plot">
                <div class="dchart-grid" aria-hidden="true"><i style="bottom:100%"></i><i style="bottom:50%"></i><i style="bottom:0"></i></div>
                <?php foreach ($buckets as $b): ?>
                    <?php
                    $tip = $bucketLabel($b) . ' · ' . t('dash_series_sales') . ' ' . idr($b['sales'])
                         . ($fin ? ' · ' . t('dash_series_collected') . ' ' . idr($b['collected']) : '');
                    ?>
                    <a class="dcol<?= $b['current'] ? ' current' : '' ?>" href="<?= $link($p, $b['start']) ?>" title="<?= e($tip) ?>" aria-label="<?= e($tip) ?>">
                        <span class="dcol-bars">
                            <i class="b sales" style="height:<?= $pct($b['sales']) ?>%"></i>
                            <?php if ($fin): ?><i class="b paid" style="height:<?= $pct($b['collected']) ?>%"></i><?php endif; ?>
                        </span>
                        <span class="dcol-label"><?= e($bucketLabel($b)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="chart-foot">
            <span class="muted small"><?= t('dash_click_hint') ?></span>
            <details class="table-view">
                <summary><?= t('dash_table_view') ?></summary>
                <div class="table-wrap"><table>
                    <thead><tr><th><?= t('dash_period_col') ?></th><th class="right"><?= t('dash_series_sales') ?></th><?php if ($fin): ?><th class="right"><?= t('dash_series_collected') ?></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach (array_reverse($buckets) as $b): ?>
                        <tr<?= $b['current'] ? ' class="row-current"' : '' ?>>
                            <td><?= e($b['start'] === $b['end'] ? $b['start'] : $b['start'] . ' ~ ' . $b['end']) ?></td>
                            <td class="right num"><?= idr($b['sales']) ?></td>
                            <?php if ($fin): ?><td class="right num"><?= idr($b['collected']) ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </details>
        </div>
    </div>
</div>

<div class="dash-row<?= ($perf && $fin) ? ' dash-row-split' : '' ?>">
<?php if ($perf): ?>
<!-- ── Who sold, who collected ── -->
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('dash_ranking') ?></span><span class="muted small"><?= e($title) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('th_submitter') ?></th>
            <th class="right"><?= t('dash_orders') ?></th>
            <th class="right"><?= t('dash_sheets') ?></th>
            <th class="right"><?= t('dash_sales') ?></th>
            <?php if ($fin): ?>
                <th class="right"><?= t('dash_rank_collected') ?></th>
                <th class="right"><?= t('dash_rank_outstanding') ?></th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$ranking): ?><tr><td colspan="<?= $fin ? 6 : 4 ?>" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php
        $topSales = max(1.0, ...array_map(fn($r) => $r['sales'], $ranking ?: [['sales' => 0]]));
        $sum = ['orders' => 0, 'sheets' => 0.0, 'sales' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0];
        ?>
        <?php foreach ($ranking as $i => $r): ?>
            <?php foreach ($sum as $k => $_) { $sum[$k] += $r[$k]; } ?>
            <tr>
                <td class="rank-name">
                    <span class="rank-no"><?= $i + 1 ?></span><strong><?= e($r['name']) ?></strong>
                    <div class="bar"><div class="bar-fill" style="width:<?= round($r['sales'] / $topSales * 100) ?>%;background:var(--c-sales)"></div></div>
                </td>
                <td class="right num"><?= (int) $r['orders'] ?></td>
                <td class="right num"><?= num($r['sheets']) ?></td>
                <td class="right num"><strong><?= idr_short($r['sales']) ?></strong></td>
                <?php if ($fin): ?>
                    <td class="right num"><?= idr_short($r['collected']) ?></td>
                    <td class="right num<?= $r['outstanding'] > 0.5 ? '' : ' muted' ?>"><?= idr_short($r['outstanding']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($ranking): ?>
            <tfoot><tr class="total-row">
                <td><?= t('dash_total') ?></td>
                <td class="right num"><?= (int) $sum['orders'] ?></td>
                <td class="right num"><?= num($sum['sheets']) ?></td>
                <td class="right num"><?= idr_short($sum['sales']) ?></td>
                <?php if ($fin): ?>
                    <td class="right num"><?= idr_short($sum['collected']) ?></td>
                    <td class="right num"><?= idr_short($sum['outstanding']) ?></td>
                <?php endif; ?>
            </tr></tfoot>
        <?php endif; ?>
    </table></div>
</div>
<?php endif; ?>

    <?php if ($fin): ?>
    <!-- ── Receivables aging (a balance as of today, not the selected period) ── -->
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('dash_aging') ?></span><span class="muted small"><?= t('dash_as_of_now') ?></span></div>
        <div class="card-body">
            <?php if ($receivable['total'] <= 0.5): ?>
                <div class="empty"><?= t('dash_no_receivable') ?></div>
            <?php else: ?>
                <div class="aging-bar" role="img" aria-label="<?= t('dash_aging') ?>">
                    <?php foreach ($receivable['bands'] as $band => $amt): ?>
                        <?php if ($amt > 0.5): ?>
                            <i class="age-<?= $band ?>" style="flex-grow:<?= round($amt / $receivable['total'] * 1000) ?>"
                               title="<?= e(t('dash_age_' . $band) . ' · ' . idr($amt)) ?>"></i>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <table class="aging-table">
                    <?php foreach ($receivable['bands'] as $band => $amt): ?>
                        <tr<?= $amt <= 0.5 ? ' class="muted"' : '' ?>>
                            <td><i class="sw age-<?= $band ?>"></i><?= t('dash_age_' . $band) ?></td>
                            <td class="right num"><?= (int) $receivable['counts'][$band] ?> <?= t('dash_inv_unit') ?></td>
                            <td class="right num"><strong><?= idr($amt) ?></strong></td>
                            <td class="right num muted"><?= num($amt / $receivable['total'] * 100) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ($receivable['top_overdue']): ?>
                    <div class="trend-title" style="margin-top:14px"><?= t('dash_top_overdue') ?></div>
                    <table class="aging-table">
                        <?php foreach ($receivable['top_overdue'] as $cust => $amt): ?>
                            <tr><td><?= e($cust) ?></td><td class="right num"><strong><?= idr($amt) ?></strong></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <!-- ── Best sellers in the period ── -->
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('dash_hot') ?></span><span class="muted small"><?= e($title) ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('th_product') ?></th><th class="right"><?= t('th_qty') ?></th><?php if ($fin): ?><th class="right"><?= t('th_amount') ?></th><?php endif; ?></tr></thead>
            <tbody>
            <?php if (!$hot): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
            <?php $maxQty = max(1.0, ...array_map(fn($h) => (float) $h['qty'], $hot ?: [['qty' => 0]])); ?>
            <?php foreach ($hot as $h): ?>
                <tr>
                    <td style="min-width:180px"><code><?= e($h['sku']) ?></code> <?= e($h['color']) ?> <span class="muted"><?= e($h['spec']) ?></span>
                        <div class="bar"><div class="bar-fill" style="width:<?= round($h['qty'] / $maxQty * 100) ?>%;background:var(--text2)"></div></div>
                    </td>
                    <td class="right num"><strong><?= num($h['qty']) ?></strong></td>
                    <?php if ($fin): ?><td class="right num"><?= idr_short($h['amount']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('recent_orders') ?></span><a class="muted small" href="<?= url('orders.index') ?>"><?= t('view_more') ?></a></div>
        <?php include __DIR__ . '/_recent.php'; ?>
    </div>
</div>
</div>
