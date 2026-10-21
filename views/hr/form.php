<?php
/** @var ?array $e */ /** @var array $viewer */ /** @var array $staff */
$isNew = $e === null;
$old = $_SESSION['hr_form_old'] ?? [];
unset($_SESSION['hr_form_old']);
$v = fn(string $k) => e((string) ($old[$k] ?? ($e[$k] ?? '')));
$sel = fn(string $k, string $opt) => (string) ($old[$k] ?? ($e[$k] ?? '')) === $opt ? 'selected' : '';
$req = fn(bool $r) => $r ? ' *' : '';
?>
<div class="page-head">
    <h1><?= $isNew ? t('hr_new_employee') : e($e['full_name']) ?></h1>
    <a class="btn btn-ghost" href="<?= $isNew ? url('hr.employees') : url('hr.show', ['id' => $e['id']]) ?>"><?= t('btn_back') ?></a>
</div>

<form method="post" action="<?= url($isNew ? 'hr.store' : 'hr.update') ?>" id="hr-form">
    <?= Csrf::field() ?>
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><?php endif; ?>
    <div class="card"><div class="card-header"><span class="card-title"><?= t('hr_tab_basic') ?></span></div><div class="card-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_full_name') ?> *</label><input class="form-input" name="full_name" value="<?= $v('full_name') ?>" required></div>
            <div class="form-group"><label class="form-label">NIK<?= $req($isNew) ?></label><input class="form-input" name="nik" value="<?= $v('nik') ?>" inputmode="numeric" pattern="\d{16}" maxlength="16" <?= $isNew ? 'required' : '' ?> placeholder="16 digit"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">NPWP</label><input class="form-input" name="npwp" value="<?= $v('npwp') ?>"></div>
            <div class="form-group"><label class="form-label">PTKP</label>
                <select class="form-select" name="ptkp_status"><option value=""></option><?php foreach (Hr::PTKP as $p): ?><option value="<?= $p ?>" <?= $sel('ptkp_status', $p) ?>><?= $p ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_birth_place') ?></label><input class="form-input" name="birth_place" value="<?= $v('birth_place') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_birth_date') ?></label><input class="form-input" type="date" name="birth_date" value="<?= $v('birth_date') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_gender') ?></label>
                <select class="form-select" name="gender"><option value=""></option><?php foreach (Hr::GENDERS as $g): ?><option value="<?= $g ?>" <?= $sel('gender', $g) ?>><?= t('hrgender_' . $g) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label"><?= t('hr_religion') ?></label>
                <select class="form-select" name="religion"><option value=""></option><?php foreach (Hr::RELIGIONS as $r): ?><option value="<?= $r ?>" <?= $sel('religion', $r) ?>><?= $r ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label"><?= t('hr_marital') ?></label>
                <select class="form-select" name="marital_status"><option value=""></option><?php foreach (Hr::MARITAL as $m): ?><option value="<?= $m ?>" <?= $sel('marital_status', $m) ?>><?= t('hrmarital_' . $m) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('hr_ktp_address') ?></label><input class="form-input" name="ktp_address" value="<?= $v('ktp_address') ?>"></div>
        <div class="form-group"><label class="form-label"><?= t('hr_current_address') ?></label><input class="form-input" name="current_address" value="<?= $v('current_address') ?>"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_phone') ?></label><input class="form-input" name="phone" value="<?= $v('phone') ?>" inputmode="tel"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_personal_email') ?></label><input class="form-input" type="email" name="personal_email" value="<?= $v('personal_email') ?>"></div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group"><label class="form-label"><?= t('hr_emergency_name') ?></label><input class="form-input" name="emergency_contact_name" value="<?= $v('emergency_contact_name') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_emergency_relation') ?></label><input class="form-input" name="emergency_contact_relation" value="<?= $v('emergency_contact_relation') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_emergency_phone') ?></label><input class="form-input" name="emergency_contact_phone" value="<?= $v('emergency_contact_phone') ?>" inputmode="tel"></div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group"><label class="form-label"><?= t('hr_bank_name') ?></label><input class="form-input" name="bank_name" value="<?= $v('bank_name') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_bank_no') ?></label><input class="form-input" name="bank_account_no" value="<?= $v('bank_account_no') ?>" inputmode="numeric"></div>
            <div class="form-group"><label class="form-label"><?= t('hr_bank_holder') ?></label><input class="form-input" name="bank_account_name" value="<?= $v('bank_account_name') ?>"></div>
        </div>
    </div></div>

    <?php if ($isNew): ?>
    <div class="card"><div class="card-header"><span class="card-title"><?= t('hr_tab_job') ?></span></div><div class="card-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_department') ?></label>
                <select class="form-select" name="department"><option value=""></option><?php foreach (Hr::DEPARTMENTS as $d): ?><option value="<?= $d ?>" <?= $sel('department', $d) ?>><?= t('hrdept_' . $d) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label"><?= t('hr_position') ?></label><input class="form-input" name="position" value="<?= $v('position') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_location') ?></label>
                <select class="form-select" name="work_location"><option value=""></option><?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $sel('work_location', $l) ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label"><?= t('hr_join_date') ?> *</label><input class="form-input" type="date" name="join_date" value="<?= $v('join_date') ?>" max="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('hr_status') ?></label>
                <select class="form-select" name="employment_status"><?php foreach (['active', 'probation'] as $s): ?><option value="<?= $s ?>" <?= $sel('employment_status', $s) ?>><?= t('hrstatus_' . $s) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label"><?= t('hr_supervisor') ?></label>
                <select class="form-select" name="supervisor_id"><option value=""><?= t('hr_none') ?></option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['employee_no'] . ' ' . $s['full_name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('hr_base_salary') ?> (Rp)</label><input class="form-input" type="number" step="any" min="0" inputmode="decimal" name="base_salary" value="<?= $v('base_salary') ?>" placeholder="<?= t('hr_salary_ph') ?>"></div>
    </div></div>
    <?php else: ?>
        <p class="muted small"><?= t('hr_job_elsewhere') ?></p>
    <?php endif; ?>

    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save') ?></button></div>
</form>
