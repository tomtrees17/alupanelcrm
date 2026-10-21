<?php /** @var array $rows */ /** @var array $f */ /** @var array $viewer */ /** @var string $today */
include __DIR__ . '/_contract_status.php';
?>
<div class="page-head">
    <h1><?= t('hr_contracts') ?></h1>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="hr.contracts">
    <select class="form-select filter-select" name="type" onchange="this.form.submit()">
        <option value=""><?= t('hrc_all_types') ?></option>
        <?php foreach (HrContract::TYPES as $ty): ?><option value="<?= $ty ?>" <?= $f['type'] === $ty ? 'selected' : '' ?>><?= $ty ?></option><?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="status" onchange="this.form.submit()">
        <option value=""><?= t('hr_all_statuses') ?></option>
        <?php foreach (HrContract::STATUSES as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= t('hrcstatus_' . $s) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="within" onchange="this.form.submit()">
        <option value=""><?= t('hrc_any_end') ?></option>
        <?php foreach ([30, 60] as $n): ?><option value="<?= $n ?>" <?= $f['within'] === $n ? 'selected' : '' ?>><?= sprintf(t('hrc_within'), $n) ?></option><?php endforeach; ?>
    </select>
</form>

<div class="card"><div class="table-wrap"><table>
    <thead><tr>
        <th><?= t('hrc_no') ?></th><th><?= t('hr_full_name') ?></th><th><?= t('hrc_type') ?></th>
        <th><?= t('hrc_period') ?></th><th><?= t('hr_status') ?></th><th class="right"><?= t('hrc_days_left') ?></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <?php $left = ($r['contract_type'] === 'PKWT' && $r['status'] === 'active' && $r['end_date']) ? HrContract::days($today, (string) $r['end_date']) : null; ?>
        <tr class="clickable" onclick="location.href='<?= url('hr.contract', ['id' => $r['id']]) ?>'">
            <td class="nowrap"><code><?= e($r['contract_no']) ?></code></td>
            <td><?= e($r['full_name']) ?> <span class="muted small"><?= e($r['employee_no']) ?></span></td>
            <td><?= e($r['contract_type']) ?><?= (int) $r['is_probation'] === 1 ? ' <span class="muted small">' . t('hrc_probation_short') . '</span>' : '' ?></td>
            <td class="nowrap"><?= e(Hr::dmy($r['start_date'])) ?> – <?= $r['end_date'] ? e(Hr::dmy($r['end_date'])) : '—' ?></td>
            <td><?= $contractTag((string) $r['status']) ?></td>
            <td class="right nowrap"><?= $left === null ? '<span class="muted">—</span>' : $left ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('hrc_list_hint') ?></p>
