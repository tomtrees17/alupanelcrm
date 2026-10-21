<?php /** @var string $period */ /** @var int $run */ /** @var array $runs */ /** @var array $monthly */ /** @var ?array $rates */ /** @var array $viewer */
$n = fn($v) => number_format((float) $v, 0, ',', '.');
$sum = ['k_company' => 0, 'k_employee' => 0, 'k_total' => 0, 'j_company' => 0, 'j_employee' => 0, 'j_total' => 0];
?>
<div class="page-head">
    <h1><?= t('hrb_monthly') ?> · <?= e($period) ?></h1>
    <?php if ($monthly): ?><a class="btn btn-ghost" href="<?= url('hr.bpjs_export', ['period' => $period, 'run' => $run]) ?>"><?= t('hrb_export') ?></a><?php endif; ?>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php include __DIR__ . '/_bpjs_nav.php'; ?>

<div class="card"><div class="card-body">
    <form class="inline-form" method="get" action="index.php" style="justify-content:flex-start">
        <input type="hidden" name="r" value="hr.bpjs_monthly">
        <label class="muted small"><?= t('hrb_period') ?></label>
        <input class="form-input" type="month" name="period" value="<?= e($period) ?>" style="max-width:180px">
        <?php if (count($runs) > 1): ?>
            <select class="form-select" name="run" style="max-width:220px"><?php foreach ($runs as $r): ?><option value="<?= (int) $r['run'] ?>" <?= (int) $r['run'] === $run ? 'selected' : '' ?>><?= sprintf(t('hrb_run_n'), (int) $r['run']) ?> · <?= e(Hr::dmy($r['at'])) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
    </form>
    <?php $cur = current(array_filter($runs, fn($r) => (int) $r['run'] === $run)) ?: null; ?>
    <?php if ($cur): ?>
        <p class="muted small"><?= sprintf(t('hrb_locked'), $run, e(Hr::dmy($cur['at'])), e((string) $cur['by_'])) ?></p>
    <?php endif; ?>
    <?php if ($viewer['edit']): ?>
        <form method="post" action="<?= url('hr.bpjs_generate') ?>" <?= $runs ? 'onsubmit="return confirm(' . e(json_encode(t('hrb_regenerate_confirm'), JSON_UNESCAPED_UNICODE)) . ')"' : '' ?>>
            <?= Csrf::field() ?><input type="hidden" name="period" value="<?= e($period) ?>">
            <button class="btn btn-primary" type="submit" <?= $rates ? '' : 'disabled' ?>><?= t($runs ? 'hrb_regenerate' : 'hrb_generate') ?></button>
            <?php if (!$rates): ?><span class="hr-low small"><?= t('hrb_err_no_rates') ?></span><?php else: ?><span class="muted small"><?= sprintf(t('hrb_rates_used'), e($rates['effective_from'])) ?></span><?php endif; ?>
        </form>
    <?php endif; ?>
</div></div>

<?php if ($monthly): ?>
<p class="alert alert-error"><?= t('hrb_check_official') ?></p>
<div class="card"><div class="table-wrap"><table>
    <thead>
        <tr><th rowspan="2"><?= t('hr_no') ?></th><th rowspan="2"><?= t('hr_full_name') ?></th><th colspan="4" class="center">BPJS Kesehatan</th><th colspan="8" class="center">BPJS Ketenagakerjaan</th><th rowspan="2" class="right"><?= t('hrb_grand_total') ?></th></tr>
        <tr><th class="right"><?= t('hrb_base') ?></th><th class="right"><?= t('hrb_company') ?></th><th class="right"><?= t('hrb_employee_share') ?></th><th class="right"><?= t('hrb_total') ?></th>
            <th class="right"><?= t('hrb_base') ?></th><th class="right">JHT</th><th class="right">JKK</th><th class="right">JKM</th><th class="right">JP</th><th class="right"><?= t('hrb_company') ?></th><th class="right"><?= t('hrb_employee_share') ?></th><th class="right"><?= t('hrb_total') ?></th></tr>
    </thead>
    <tbody>
    <?php foreach ($monthly as $r): ?>
        <?php $k = $r['kesehatan']; $j = $r['ketenagakerjaan'];
        if ($k) { $sum['k_company'] += $k['company']; $sum['k_employee'] += $k['employee']; $sum['k_total'] += $k['total']; }
        if ($j) { $sum['j_company'] += $j['company']; $sum['j_employee'] += $j['employee']; $sum['j_total'] += $j['total']; } ?>
        <tr>
            <td><code><?= e($r['no']) ?></code></td><td><?= e($r['name']) ?></td>
            <?php if ($k): ?>
                <td class="right"><?= $n($k['base']) ?></td><td class="right"><?= $n($k['company']) ?></td>
                <td class="right"><?= $n($k['employee']) ?><?php if (($k['parts']['extra_dependents'] ?? 0) > 0): ?> <span class="muted small" title="<?= t('hrb_extra_deps') ?>">(+<?= (int) $k['parts']['extra_dependents'] ?>)</span><?php endif; ?></td>
                <td class="right"><strong><?= $n($k['total']) ?></strong></td>
            <?php else: ?><td colspan="4" class="muted center">—</td><?php endif; ?>
            <?php if ($j): ?>
                <td class="right"><?= $n($j['base']) ?></td>
                <td class="right"><?= $n($j['parts']['jht_company'] + $j['parts']['jht_employee']) ?></td>
                <td class="right"><?= $n($j['parts']['jkk']) ?></td><td class="right"><?= $n($j['parts']['jkm']) ?></td>
                <td class="right"><?= $n($j['parts']['jp_company'] + $j['parts']['jp_employee']) ?></td>
                <td class="right"><?= $n($j['company']) ?></td><td class="right"><?= $n($j['employee']) ?></td>
                <td class="right"><strong><?= $n($j['total']) ?></strong></td>
            <?php else: ?><td colspan="8" class="muted center">—</td><?php endif; ?>
            <td class="right"><strong><?= $n(($k['total'] ?? 0) + ($j['total'] ?? 0)) ?></strong></td>
        </tr>
    <?php endforeach; ?>
    <tr class="hr-total">
        <td colspan="2"><strong><?= t('hrb_total') ?></strong></td>
        <td></td><td class="right"><?= $n($sum['k_company']) ?></td><td class="right"><?= $n($sum['k_employee']) ?></td><td class="right"><strong><?= $n($sum['k_total']) ?></strong></td>
        <td colspan="5"></td><td class="right"><?= $n($sum['j_company']) ?></td><td class="right"><?= $n($sum['j_employee']) ?></td><td class="right"><strong><?= $n($sum['j_total']) ?></strong></td>
        <td class="right"><strong><?= $n($sum['k_total'] + $sum['j_total']) ?></strong></td>
    </tr>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('hrb_monthly_hint') ?></p>
<?php elseif ($runs === []): ?>
<div class="card"><div class="empty"><?= t('hrb_not_generated') ?></div></div>
<?php endif; ?>
