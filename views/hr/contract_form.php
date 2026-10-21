<?php /** @var ?array $c */ /** @var array $e */ /** @var array $viewer */ /** @var array $others */ /** @var ?string $salary */
$isNew = $c === null;
$locked = !$isNew && $c['status'] === 'active';   // active: only end date and signing date may be corrected
$old = $_SESSION['hr_form_old'] ?? [];
unset($_SESSION['hr_form_old']);
$defaults = ['contract_type' => 'PKWT', 'position' => $e['position'], 'work_location' => $e['work_location'] ?: 'Jakarta', 'probation_months' => 3];
$val = fn(string $k) => (string) ($old[$k] ?? ($c[$k] ?? ($defaults[$k] ?? '')));
$dis = $locked ? 'disabled' : '';
?>
<div class="page-head">
    <h1><?= $isNew ? t('hrc_new') : e($c['contract_no']) ?></h1>
    <a class="btn btn-ghost" href="<?= $isNew ? url('hr.show', ['id' => $e['id'], 'tab' => 'contracts']) : url('hr.contract', ['id' => $c['id']]) ?>"><?= t('btn_back') ?></a>
</div>

<form method="post" action="<?= url($isNew ? 'hr.contract_store' : 'hr.contract_update') ?>" id="hrc-form">
    <?= Csrf::field() ?>
    <?php if ($isNew): ?><input type="hidden" name="employee_id" value="<?= (int) $e['id'] ?>"><?php else: ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php endif; ?>
    <div class="card"><div class="card-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_employee') ?></label><input class="form-input" value="<?= e($e['employee_no'] . ' ' . $e['full_name']) ?>" disabled></div>
            <div class="form-group"><label class="form-label"><?= t('hrc_type') ?> *</label>
                <select class="form-select" name="contract_type" id="hrc-type" <?= $dis ?>><?php foreach (HrContract::TYPES as $ty): ?><option value="<?= $ty ?>" <?= $val('contract_type') === $ty ? 'selected' : '' ?>><?= $ty ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hrc_start') ?> *</label><input class="form-input" type="date" name="start_date" value="<?= e($val('start_date')) ?>" required <?= $dis ?>></div>
            <div class="form-group hrc-pkwt"><label class="form-label"><?= t('hrc_end') ?> *</label><input class="form-input" type="date" name="end_date" value="<?= e($val('end_date')) ?>"></div>
        </div>
        <div class="form-row hrc-pkwtt">
            <div class="form-group"><label class="check-all"><input type="checkbox" name="is_probation" value="1" id="hrc-prob" <?= $val('is_probation') === '1' ? 'checked' : '' ?> <?= $dis ?>> <?= t('hrc_probation') ?></label></div>
            <div class="form-group"><label class="form-label"><?= t('hrc_probation_months') ?></label>
                <select class="form-select" name="probation_months" <?= $dis ?>><?php foreach ([1, 2, 3] as $m): ?><option value="<?= $m ?>" <?= $val('probation_months') === (string) $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_position') ?> *</label><input class="form-input" name="position" value="<?= e($val('position')) ?>" required <?= $dis ?>></div>
            <div class="form-group"><label class="form-label"><?= t('hr_location') ?> *</label>
                <select class="form-select" name="work_location" <?= $dis ?>><?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $val('work_location') === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hrc_salary') ?> (Rp)</label>
                <input class="form-input" type="number" step="any" min="0" inputmode="decimal" name="salary" value="<?= e((string) ($old['salary'] ?? $salary ?? '')) ?>" <?= $dis ?>></div>
            <div class="form-group"><label class="form-label"><?= t('hrc_signed') ?></label><input class="form-input" type="date" name="signed_date" value="<?= e($val('signed_date')) ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hrc_previous') ?></label>
                <select class="form-select" name="previous_contract_id" <?= $dis ?>><option value=""><?= t('hr_none') ?></option>
                    <?php foreach ($others as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $val('previous_contract_id') === (string) $o['id'] ? 'selected' : '' ?>><?= e($o['contract_no'] . ' · ' . $o['contract_type'] . ' · ' . Hr::dmy($o['start_date']) . ' – ' . (Hr::dmy($o['end_date']) ?: '—')) ?></option><?php endforeach; ?>
                </select>
                <span class="muted small"><?= t('hrc_previous_hint') ?></span></div>
        </div>
        <p class="muted small"><?= t($locked ? 'hrc_active_edit_hint' : 'hrc_form_hint') ?></p>
    </div></div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save') ?></button></div>
</form>
<script>
// PKWT has an end date; PKWTT has none but may carry a probation period (≤ 3 months).
(function () {
    const type = document.getElementById('hrc-type');
    const show = () => {
        const pkwt = type.value === 'PKWT';
        document.querySelectorAll('.hrc-pkwt').forEach(el => { el.style.display = pkwt ? '' : 'none'; el.querySelectorAll('input').forEach(i => i.required = pkwt); });
        document.querySelectorAll('.hrc-pkwtt').forEach(el => { el.style.display = pkwt ? 'none' : ''; });
    };
    type.addEventListener('change', show);
    show();
})();
</script>
