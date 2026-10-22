<?php /** @var array $yr */ /** @var array $years */ /** @var int $year */ /** @var bool $perf */ /** @var string $cutover */ ?>
<?php
$t = $yr['total'];
$hasHist = $t['hist_docs'] > 0 || $cutover !== '';
$monthLabel = fn(string $m): string => current_lang() === 'id'
    ? ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][(int) substr($m, 5, 2) - 1]
    : (int) substr($m, 5, 2) . '月';
$max = 0.0;
foreach ($yr['months'] as $m) {
    $max = max($max, $m['hist'] + $m['live']);
}
$pct = fn(float $v): float => $max > 0 ? round($v / $max * 100, 1) : 0.0;
?>
<div class="page-head">
    <div>
        <h1><?= t('yr_title') ?> <?= $year ?></h1>
        <p class="muted"><?= t('yr_sub') ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <form method="get" action="index.php" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="r" value="finance.yearly">
            <select class="form-input" name="year" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select>
        </form>
        <a class="btn btn-ghost" href="<?= url('finance.history', ['year' => $year]) ?>"><?= t('yr_link_history') ?></a>
        <?php if (can_export()): ?><a class="btn btn-ghost" href="<?= url('finance.yearly_export', ['year' => $year]) ?>"><?= t('btn_export') ?></a><?php endif; ?>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card c1"><div class="stat-label"><?= t('yr_total') ?></div><div class="stat-value c1"><?= idr_short($t['all']) ?></div><div class="stat-sub muted"><?= idr($t['all']) ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('yr_history') ?></div><div class="stat-value"><?= idr_short($t['hist']) ?></div><div class="stat-sub muted"><?= num($t['hist_docs']) ?> <?= t('yr_docs') ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('yr_live') ?></div><div class="stat-value"><?= idr_short($t['live']) ?></div><div class="stat-sub muted"><?= num($t['live_docs']) ?> <?= t('yr_docs') ?></div></div>
    <div class="stat-card"><div class="stat-label"><?= t('dash_sheets_total') ?></div><div class="stat-value"><?= num($t['hist_sheets'] + $t['live_sheets']) ?></div></div>
</div>

<?php if ($hasHist): ?>
    <p class="muted small"><?= e(sprintf(t('yr_hist_hint'), $cutover)) ?></p>
<?php else: ?>
    <p class="muted small"><?= t('yr_no_hist') ?></p>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('yr_month') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('yr_month') ?></th>
            <th style="width:30%"></th>
            <th class="right"><?= t('yr_history') ?></th>
            <th class="right"><?= t('yr_live') ?></th>
            <th class="right"><?= t('yr_total') ?></th>
            <th class="right"><?= t('yr_docs') ?></th>
            <th class="right"><?= t('dash_sheets') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($yr['months'] as $m => $v): ?>
            <?php $sum = $v['hist'] + $v['live']; ?>
            <tr>
                <td><?= e($monthLabel($m)) ?></td>
                <td>
                    <div style="display:flex;height:10px;background:var(--surface3);border-radius:4px;overflow:hidden" title="<?= e(idr($sum)) ?>">
                        <i style="width:<?= $pct($v['hist']) ?>%;background:#b8c2cc"></i>
                        <i style="width:<?= $pct($v['live']) ?>%;background:var(--accent)"></i>
                    </div>
                </td>
                <td class="right muted"><?= $v['hist'] > 0 ? idr($v['hist']) : '–' ?></td>
                <td class="right"><?= $v['live'] > 0 ? idr($v['live']) : '–' ?></td>
                <td class="right"><strong><?= $sum > 0 ? idr($sum) : '–' ?></strong></td>
                <td class="right"><?= num($v['hist_docs'] + $v['live_docs']) ?></td>
                <td class="right"><?= num($v['hist_sheets'] + $v['live_sheets']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <th><?= t('yr_total') ?></th><th></th>
            <th class="right"><?= idr($t['hist']) ?></th>
            <th class="right"><?= idr($t['live']) ?></th>
            <th class="right"><?= idr($t['all']) ?></th>
            <th class="right"><?= num($t['hist_docs'] + $t['live_docs']) ?></th>
            <th class="right"><?= num($t['hist_sheets'] + $t['live_sheets']) ?></th>
        </tr></tfoot>
    </table></div>
</div>

<?php if ($perf): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('yr_by_person') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('th_submitter') ?></th><th class="right"><?= t('yr_history') ?></th><th class="right"><?= t('yr_live') ?></th><th class="right"><?= t('yr_total') ?></th><th class="right"><?= t('yr_docs') ?></th><th class="right"><?= t('dash_sheets') ?></th></tr></thead>
        <tbody>
        <?php foreach ($yr['people'] as $p): ?>
            <tr>
                <td><?= e($p['name']) ?></td>
                <td class="right muted"><?= $p['hist'] > 0 ? idr($p['hist']) : '–' ?></td>
                <td class="right"><?= $p['live'] > 0 ? idr($p['live']) : '–' ?></td>
                <td class="right"><strong><?= idr($p['total']) ?></strong></td>
                <td class="right"><?= num($p['docs']) ?></td>
                <td class="right"><?= num($p['sheets']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$yr['people']): ?><tr><td colspan="6" class="empty"><?= t('hist_no_data') ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('yr_by_customer') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th>#</th><th><?= t('th_customer') ?></th><th class="right"><?= t('yr_history') ?></th><th class="right"><?= t('yr_live') ?></th><th class="right"><?= t('yr_total') ?></th><th class="right"><?= t('yr_docs') ?></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($yr['customers'], 0, 50) as $i => $c): ?>
            <tr>
                <td class="muted"><?= $i + 1 ?></td>
                <td>
                    <?php if ($c['id'] !== null && can_access('customers')): ?>
                        <a href="<?= url('customers.show', ['id' => $c['id']]) ?>"><?= e($c['name']) ?></a>
                    <?php else: ?>
                        <?= e($c['name']) ?><?php if ($c['id'] === null): ?> <span class="muted small">(<?= t('hist_unlinked') ?>)</span><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="right muted"><?= $c['hist'] > 0 ? idr($c['hist']) : '–' ?></td>
                <td class="right"><?= $c['live'] > 0 ? idr($c['live']) : '–' ?></td>
                <td class="right"><strong><?= idr($c['total']) ?></strong></td>
                <td class="right"><?= num($c['docs']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$yr['customers']): ?><tr><td colspan="6" class="empty"><?= t('hist_no_data') ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if (count($yr['customers']) > 50): ?>
        <div class="card-body muted small"><?= e(sprintf(t('yr_customer_more'), count($yr['customers']) - 50)) ?></div>
    <?php endif; ?>
</div>
