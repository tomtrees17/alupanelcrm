<?php /** @var array $rows */ /** @var string $status */ /** @var int $unregistered */ /** @var array $viewer */
include __DIR__ . '/_bpjs_status.php';
?>
<div class="page-head"><h1>BPJS</h1></div>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php include __DIR__ . '/_bpjs_nav.php'; ?>

<div class="card"><div class="card-body hr-kpi">
    <span class="muted"><?= t('hrb_unregistered') ?></span>
    <strong class="<?= $unregistered > 0 ? 'hr-kpi-bad' : '' ?>"><?= $unregistered ?></strong>
</div></div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="hr.bpjs">
    <select class="form-select filter-select" name="status" onchange="this.form.submit()">
        <option value=""><?= t('hr_all_statuses') ?></option>
        <?php foreach (HrBpjs::STATUSES as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= t('hrbstatus_' . $s) ?></option><?php endforeach; ?>
    </select>
</form>

<div class="card"><div class="table-wrap"><table>
    <thead><tr>
        <th><?= t('hr_no') ?></th><th><?= t('hr_full_name') ?></th><th><?= t('hr_status') ?></th>
        <th>BPJS Kesehatan</th><th>BPJS Ketenagakerjaan</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr class="clickable" onclick="location.href='<?= url('hr.show', ['id' => $r['id'], 'tab' => 'bpjs']) ?>'">
            <td><code><?= e($r['employee_no']) ?></code></td>
            <td><strong><?= e($r['full_name']) ?></strong></td>
            <td><span class="tag <?= $r['employment_status'] === 'resigned' ? 'tag-gray' : 'tag-green' ?>"><?= t('hrstatus_' . $r['employment_status']) ?></span></td>
            <td><?= $bpjsTag($r['kes_status']) ?> <span class="muted small"><?= e((string) $r['kes_no']) ?></span></td>
            <td><?= $bpjsTag($r['tk_status']) ?> <span class="muted small"><?= e((string) $r['tk_no']) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small"><?= t('hrb_overview_hint') ?></p>
