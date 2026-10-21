<?php /** @var array $rows */ /** @var array $f */ /** @var array $viewer */ /** @var array $current */ /** @var string $today */ ?>
<div class="page-head">
    <h1><?= t('hr_employees') ?></h1>
    <?php if ($viewer['edit']): ?><a class="btn btn-primary" href="<?= url('hr.create') ?>"><?= t('hr_new_employee') ?></a><?php endif; ?>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="hr.employees">
    <input class="form-input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= t('hr_search') ?>">
    <select class="form-select filter-select" name="department" onchange="this.form.submit()">
        <option value=""><?= t('hr_all_departments') ?></option>
        <?php foreach (Hr::DEPARTMENTS as $d): ?><option value="<?= $d ?>" <?= $f['department'] === $d ? 'selected' : '' ?>><?= t('hrdept_' . $d) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="location" onchange="this.form.submit()">
        <option value=""><?= t('hr_all_locations') ?></option>
        <?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $f['location'] === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <select class="form-select filter-select" name="status" onchange="this.form.submit()">
        <option value=""><?= t('hr_all_statuses') ?></option>
        <?php foreach (Hr::STATUSES as $s): ?><option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= t('hrstatus_' . $s) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>

<div class="card"><div class="table-wrap"><table>
    <thead><tr>
        <th><?= t('hr_no') ?></th><th><?= t('hr_full_name') ?></th><th><?= t('hr_department') ?></th><th><?= t('hr_position') ?></th>
        <th><?= t('hr_location') ?></th><th><?= t('hr_join_date') ?></th><th><?= t('hr_status') ?></th>
        <th><?= t('hr_contract_end') ?></th><th>BPJS</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <?php $pct = Hr::completeness($r); ?>
        <tr class="clickable" onclick="location.href='<?= url('hr.show', ['id' => $r['id']]) ?>'">
            <td><code><?= e($r['employee_no']) ?></code></td>
            <td><strong><?= e($r['full_name']) ?></strong>
                <?php if ($pct < 80): ?><span class="tag tag-yellow" title="<?= t('hr_completeness') ?>"><?= $pct ?>%</span><?php endif; ?></td>
            <td><?= $r['department'] ? t('hrdept_' . $r['department']) : '—' ?></td>
            <td><?= e($r['position']) ?: '—' ?></td>
            <td><?= e($r['work_location']) ?: '—' ?></td>
            <td class="nowrap"><?= e(Hr::dmy($r['join_date'])) ?: '—' ?></td>
            <td><span class="tag <?= $r['employment_status'] === 'resigned' ? 'tag-gray' : ($r['employment_status'] === 'probation' ? 'tag-orange' : 'tag-green') ?>"><?= t('hrstatus_' . $r['employment_status']) ?></span></td>
            <?php $cc = $current[(int) $r['id']] ?? null; ?>
            <td class="nowrap"><?php if (!$cc): ?><span class="muted">—</span><?php elseif ($cc['contract_type'] === 'PKWTT'): ?>PKWTT<?php else: ?><?php $left = HrContract::days($today, (string) $cc['end_date']); ?><?= e(Hr::dmy($cc['end_date'])) ?> <span class="small <?= $left <= 30 ? 'hr-low' : 'muted' ?>">(<?= $left ?>)</span><?php endif; ?></td>
            <td class="muted">—</td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('hr_list_hint') ?></p>
