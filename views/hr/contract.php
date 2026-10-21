<?php /** @var array $c */ /** @var array $e */ /** @var array $viewer */ /** @var string $today */ /** @var ?array $prev */ /** @var array $next */
/** @var bool $salaryVisible */ /** @var ?string $salary */ /** @var bool $decidable */
include __DIR__ . '/_contract_status.php';
$dash = fn($v) => ($v === null || $v === '') ? '<span class="muted">—</span>' : e((string) $v);
$probEnd = HrContract::probation_end($c);
$left = ($c['contract_type'] === 'PKWT' && $c['status'] === 'active' && $c['end_date']) ? HrContract::days($today, (string) $c['end_date']) : null;
?>
<div class="page-head">
    <h1><code><?= e($c['contract_no']) ?></code> <?= $contractTag((string) $c['status']) ?></h1>
    <div class="head-actions">
        <?php if ($viewer['edit'] && in_array($c['status'], ['draft', 'active'], true)): ?><a class="btn btn-ghost" href="<?= url('hr.contract_edit', ['id' => $c['id']]) ?>"><?= t('btn_edit') ?></a><?php endif; ?>
        <?php if ($viewer['edit'] && $decidable): ?><a class="btn btn-primary" href="<?= url('hr.contract_decide', ['id' => $c['id']]) ?>"><?= t('hrc_decide') ?></a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('hr.show', ['id' => $e['id'], 'tab' => 'contracts']) ?>"><?= e($e['full_name']) ?></a>
    </div>
</div>
<?php if ($viewer['scope'] !== 'self') { include __DIR__ . '/_tabs.php'; } ?>

<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt><?= t('hr_employee') ?></dt><dd><a href="<?= url('hr.show', ['id' => $e['id']]) ?>"><?= e($e['employee_no'] . ' ' . $e['full_name']) ?></a></dd></div>
        <div><dt><?= t('hrc_type') ?></dt><dd><?= e($c['contract_type']) ?><?php if ($probEnd): ?> · <?= sprintf(t('hrc_probation_until'), (int) $c['probation_months'], e(Hr::dmy($probEnd))) ?><?php endif; ?></dd></div>
        <div><dt><?= t('hrc_period') ?></dt><dd><?= e(Hr::dmy($c['start_date'])) ?> – <?= $c['end_date'] ? e(Hr::dmy($c['end_date'])) : '—' ?>
            <?php if ($left !== null): ?><span class="muted small"> · <?= sprintf(t('hrc_days_left_n'), $left) ?></span><?php endif; ?></dd></div>
        <div><dt><?= t('hr_position') ?></dt><dd><?= $dash($c['position']) ?></dd></div>
        <div><dt><?= t('hr_location') ?></dt><dd><?= $dash($c['work_location']) ?></dd></div>
        <?php if ($salaryVisible): ?>
            <div><dt><?= t('hrc_salary') ?></dt><dd><?php if ($c['salary_enc'] === null): ?><?= $dash(null) ?><?php elseif ($salary === null): ?><span class="muted"><?= t('hr_unreadable') ?></span><?php else: ?><?= idr((float) $salary) ?><?php endif; ?></dd></div>
        <?php endif; ?>
        <div><dt><?= t('hrc_signed') ?></dt><dd><?= $dash(Hr::dmy($c['signed_date'])) ?></dd></div>
        <?php if ($c['contract_type'] === 'PKWT'): ?><div><dt><?= t('hrc_renewals') ?></dt><dd><?= (int) $c['renewal_count'] ?></dd></div><?php endif; ?>
        <div><dt><?= t('hrc_previous') ?></dt><dd><?= $prev ? '<a href="' . url('hr.contract', ['id' => $prev['id']]) . '">' . e($prev['contract_no']) . '</a> ' . e($prev['contract_type']) : $dash(null) ?></dd></div>
        <?php if ($next): ?><div><dt><?= t('hrc_next') ?></dt><dd><?php foreach ($next as $n): ?><a href="<?= url('hr.contract', ['id' => $n['id']]) ?>"><?= e($n['contract_no']) ?></a> <?= e($n['contract_type']) ?> <?= $contractTag((string) $n['status']) ?> <?php endforeach; ?></dd></div><?php endif; ?>
        <?php if ($c['status'] === 'terminated'): ?>
            <div><dt><?= t('hrc_termination_date') ?></dt><dd><?= $dash(Hr::dmy($c['termination_date'])) ?></dd></div>
            <div><dt><?= t('hrc_termination_reason') ?></dt><dd><?= $dash($c['termination_reason']) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= t('hrc_scan') ?></dt><dd><?php if ($c['file_path']): ?><a href="<?= url('hr.contract_file', ['id' => $c['id']]) ?>" target="_blank" rel="noopener"><?= e($c['file_name']) ?></a><?php else: ?><span class="hr-low"><?= t('hrc_no_scan') ?></span><?php endif; ?></dd></div>
    </dl>
</div></div>

<?php if ($viewer['edit'] && $c['status'] === 'draft'): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hrc_activate') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.contract_upload') ?>" enctype="multipart/form-data" class="inline-form" style="justify-content:flex-start">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <input class="form-input" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
            <button class="btn btn-ghost" type="submit"><?= t($c['file_path'] ? 'hrc_replace_scan' : 'hr_upload') ?></button>
        </form>
        <p class="muted small"><?= t('hr_upload_hint') ?></p>
        <form method="post" action="<?= url('hr.contract_activate') ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-primary" type="submit" <?= $c['file_path'] ? '' : 'disabled' ?>><?= t('hrc_activate') ?></button>
            <span class="muted small"><?= t($c['file_path'] ? 'hrc_activate_hint' : 'hrc_err_need_scan') ?></span>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($viewer['edit'] && in_array($c['status'], ['draft', 'active', 'expired'], true)): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t($c['status'] === 'draft' ? 'hrc_close_draft' : 'hrc_terminate') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.contract_terminate') ?>" onsubmit="return confirm(<?= e(json_encode(t($c['status'] === 'draft' ? 'hrc_close_draft_confirm' : 'hrc_terminate_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrc_termination_date') ?> *</label><input class="form-input" type="date" name="termination_date" value="<?= e($c['status'] === 'expired' ? (string) $c['end_date'] : $today) ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('hrc_termination_reason') ?> *</label><input class="form-input" name="termination_reason" required></div>
            </div>
            <button class="btn btn-danger" type="submit"><?= t($c['status'] === 'draft' ? 'hrc_close_draft' : 'hrc_terminate') ?></button>
            <p class="muted small"><?= t($c['status'] === 'draft' ? 'hrc_close_draft_hint' : 'hrc_terminate_hint') ?></p>
        </form>
    </div>
</div>
<?php endif; ?>
