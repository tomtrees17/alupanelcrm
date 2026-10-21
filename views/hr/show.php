<?php
/** @var array $e */ /** @var string $tab */ /** @var array $viewer */ /** @var bool $salaryVisible */ /** @var ?string $salary */
/** @var array $attachments */ /** @var array $changes */ /** @var ?array $account */ /** @var ?array $supervisor */ /** @var array $staff */ /** @var string $today */
$pct = Hr::completeness($e);
$dash = fn($v) => ($v === null || $v === '') ? '<span class="muted">—</span>' : e((string) $v);
$tabUrl = fn(string $t) => url('hr.show', ['id' => $e['id'], 'tab' => $t]);
$resigned = $e['employment_status'] === 'resigned';
// KTP / NPWP / KK are legal names (never translated); the rest have labels.
$docLabel = fn(string $d): string => in_array($d, ['ijazah', 'foto', 'lainnya'], true) ? t('hrdoc_' . $d) : strtoupper($d);
$valueOf = function (?string $field, ?string $v) use ($salaryVisible): string {
    if ($v === null || $v === '') {
        return '—';
    }
    if ($field !== null && str_contains($field, 'salary')) {
        if (!$salaryVisible) {
            return t('hr_hidden');
        }
        $plain = Hr::decrypt($v);
        return $plain === null ? t('hr_unreadable') : idr((float) $plain);
    }
    return match ($field) {
        'department'        => t('hrdept_' . $v),
        'employment_status' => t('hrstatus_' . $v),
        'is_dept_head'      => $v === '1' ? t('hr_yes') : t('hr_no_word'),
        default             => $v,
    };
};
?>
<div class="page-head">
    <h1><?= e($e['full_name']) ?> <span class="tag <?= $resigned ? 'tag-gray' : ($e['employment_status'] === 'probation' ? 'tag-orange' : 'tag-green') ?>"><?= t('hrstatus_' . $e['employment_status']) ?></span></h1>
    <div class="head-actions">
        <?php if ($viewer['edit']): ?><a class="btn btn-ghost" href="<?= url('hr.edit', ['id' => $e['id']]) ?>"><?= t('hr_edit_basic') ?></a><?php endif; ?>
        <?php if ($viewer['scope'] !== 'self'): ?><a class="btn btn-ghost" href="<?= url('hr.employees') ?>"><?= t('btn_back') ?></a><?php endif; ?>
    </div>
</div>
<?php if ($viewer['scope'] !== 'self') { include __DIR__ . '/_tabs.php'; } ?>

<div class="hr-meta muted small">
    <code><?= e($e['employee_no']) ?></code>
    · <?= t('hr_completeness') ?> <strong class="<?= $pct < 80 ? 'hr-low' : '' ?>"><?= $pct ?>%</strong>
    <?php if ($account): ?> · <?= t('hr_crm_account') ?>: <?= e($account['email']) ?><?= (int) $account['active'] === 1 ? '' : ' <span class="tag tag-gray">' . t('hr_account_off') . '</span>' ?><?php endif; ?>
</div>

<nav class="task-filters">
    <?php foreach (['basic' => 'hr_tab_basic', 'job' => 'hr_tab_job', 'files' => 'hr_tab_files', 'contracts' => 'hr_contracts', 'bpjs' => 'hr_tab_bpjs', 'history' => 'hr_tab_history'] as $k => $label): ?>
        <a class="filter-btn <?= $tab === $k ? 'active' : '' ?>" href="<?= $tabUrl($k) ?>"><?= $k === 'bpjs' ? 'BPJS' : t($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'basic'): ?>
<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt>NIK</dt><dd><?= $dash($e['nik']) ?></dd></div>
        <div><dt>NPWP</dt><dd><?= $dash($e['npwp']) ?></dd></div>
        <div><dt><?= t('hr_birth') ?></dt><dd><?= $dash(trim(($e['birth_place'] ?? '') . ($e['birth_date'] ? ', ' . Hr::dmy($e['birth_date']) : ''), ', ')) ?></dd></div>
        <div><dt><?= t('hr_gender') ?></dt><dd><?= $e['gender'] ? t('hrgender_' . $e['gender']) : $dash(null) ?></dd></div>
        <div><dt><?= t('hr_religion') ?></dt><dd><?= $dash($e['religion']) ?></dd></div>
        <div><dt><?= t('hr_marital') ?></dt><dd><?= $e['marital_status'] ? t('hrmarital_' . $e['marital_status']) : $dash(null) ?></dd></div>
        <div><dt><?= t('hr_ktp_address') ?></dt><dd><?= $dash($e['ktp_address']) ?></dd></div>
        <div><dt><?= t('hr_current_address') ?></dt><dd><?= $dash($e['current_address']) ?></dd></div>
        <div><dt><?= t('th_phone') ?></dt><dd><?= $dash($e['phone']) ?></dd></div>
        <div><dt><?= t('hr_personal_email') ?></dt><dd><?= $dash($e['personal_email']) ?></dd></div>
        <div><dt><?= t('hr_emergency') ?></dt><dd><?= $dash(trim(implode(' · ', array_filter([$e['emergency_contact_name'], $e['emergency_contact_relation'], $e['emergency_contact_phone']])))) ?></dd></div>
        <div><dt><?= t('hr_bank') ?></dt><dd><?= $dash(trim(implode(' · ', array_filter([$e['bank_name'], $e['bank_account_no'], $e['bank_account_name']])))) ?></dd></div>
        <div><dt>PTKP</dt><dd><?= $dash($e['ptkp_status']) ?></dd></div>
    </dl>
</div></div>

<?php elseif ($tab === 'job'): ?>
<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt><?= t('hr_department') ?></dt><dd><?= $e['department'] ? t('hrdept_' . $e['department']) : $dash(null) ?><?= (int) $e['is_dept_head'] === 1 ? ' <span class="tag tag-blue">' . t('hr_dept_head') . '</span>' : '' ?></dd></div>
        <div><dt><?= t('hr_position') ?></dt><dd><?= $dash($e['position']) ?></dd></div>
        <div><dt><?= t('hr_location') ?></dt><dd><?= $dash($e['work_location']) ?></dd></div>
        <div><dt><?= t('hr_supervisor') ?></dt><dd><?= $supervisor ? e($supervisor['employee_no'] . ' ' . $supervisor['full_name']) : $dash(null) ?></dd></div>
        <div><dt><?= t('hr_join_date') ?></dt><dd><?= $dash(Hr::dmy($e['join_date'])) ?></dd></div>
        <?php if ($resigned): ?>
            <div><dt><?= t('hr_resign_date') ?></dt><dd><?= $dash(Hr::dmy($e['resign_date'])) ?></dd></div>
            <div><dt><?= t('hr_resign_reason') ?></dt><dd><?= $dash($e['resign_reason']) ?></dd></div>
        <?php endif; ?>
        <?php if ($salaryVisible): ?>
            <div><dt><?= t('hr_base_salary') ?></dt><dd><?php if ($e['base_salary_enc'] === null): ?><?= $dash(null) ?><?php elseif ($salary === null): ?><span class="muted"><?= t('hr_unreadable') ?></span><?php else: ?><?= idr((float) $salary) ?><?php endif; ?>
                <?php if ($e['salary_effective_date']): ?><span class="muted small"> · <?= t('hr_effective') ?> <?= e(Hr::dmy($e['salary_effective_date'])) ?></span><?php endif; ?></dd></div>
        <?php endif; ?>
    </dl>
</div></div>

<?php if ($viewer['edit'] && !$resigned): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hr_change_job') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.job') ?>" class="hr-form">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_department') ?></label>
                    <select class="form-select" name="department"><?php foreach (Hr::DEPARTMENTS as $d): ?><option value="<?= $d ?>" <?= $e['department'] === $d ? 'selected' : '' ?>><?= t('hrdept_' . $d) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label"><?= t('hr_position') ?></label><input class="form-input" name="position" value="<?= e($e['position']) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_location') ?></label>
                    <select class="form-select" name="work_location"><?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $e['work_location'] === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label"><?= t('hr_supervisor') ?></label>
                    <select class="form-select" name="supervisor_id"><option value=""><?= t('hr_none') ?></option>
                        <?php foreach ($staff as $s): ?><?php if ((int) $s['id'] !== (int) $e['id']): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $e['supervisor_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['employee_no'] . ' ' . $s['full_name']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_status') ?></label>
                    <select class="form-select" name="employment_status"><?php foreach (['probation', 'active'] as $s): ?><option value="<?= $s ?>" <?= $e['employment_status'] === $s ? 'selected' : '' ?>><?= t('hrstatus_' . $s) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label"><?= t('hr_base_salary') ?> (Rp)</label>
                    <input class="form-input" type="number" step="any" min="0" inputmode="decimal" name="base_salary" value="<?= e($salary ?? '') ?>" placeholder="<?= t('hr_salary_ph') ?>"></div>
            </div>
            <label class="check-all"><input type="checkbox" name="is_dept_head" value="1" <?= (int) $e['is_dept_head'] === 1 ? 'checked' : '' ?>> <?= t('hr_dept_head') ?> <span class="muted small">— <?= t('hr_dept_head_hint') ?></span></label>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_effective') ?> *</label><input class="form-input" type="date" name="effective_date" required></div>
                <div class="form-group"><label class="form-label"><?= t('hr_change_note') ?> *</label><input class="form-input" name="note" required></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= t('btn_save') ?></button>
            <p class="muted small"><?= t('hr_change_hint') ?></p>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hr_resign') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.resign') ?>" onsubmit="return confirm(<?= e(json_encode(t('hr_resign_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_resign_date') ?> *</label><input class="form-input" type="date" name="resign_date" required></div>
                <div class="form-group"><label class="form-label"><?= t('hr_resign_reason') ?> *</label><input class="form-input" name="resign_reason" required></div>
            </div>
            <button class="btn btn-danger" type="submit"><?= t('hr_resign') ?></button>
            <p class="muted small"><?= t('hr_resign_hint') ?></p>
        </form>
    </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'files'): ?>
<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('hr_doc_type') ?></th><th><?= t('hr_file') ?></th><th><?= t('hr_uploaded') ?></th></tr></thead>
        <tbody>
        <?php if (!$attachments): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($attachments as $a): ?>
            <tr><td><?= e($docLabel((string) $a['doc_type'])) ?></td>
                <td><a href="<?= url('hr.file', ['fid' => $a['id']]) ?>" target="_blank" rel="noopener"><?= e($a['file_name']) ?></a></td>
                <td class="muted small"><?= e(Hr::dmy($a['uploaded_at'])) ?> · <?= e($a['uploaded_by']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($viewer['edit']): ?>
    <div class="card-body">
        <form method="post" action="<?= url('hr.upload') ?>" enctype="multipart/form-data" class="inline-form">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
            <select class="form-select" name="doc_type" required>
                <?php foreach (Hr::DOC_TYPES as $d): ?><option value="<?= $d ?>"><?= e($docLabel($d)) ?></option><?php endforeach; ?>
            </select>
            <input class="form-input" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
            <button class="btn btn-primary" type="submit"><?= t('hr_upload') ?></button>
        </form>
        <p class="muted small"><?= t('hr_upload_hint') ?></p>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'contracts' || $tab === 'bpjs'): ?>
<div class="card"><div class="empty"><?= t($tab === 'contracts' ? 'hr_contracts_soon' : 'hr_bpjs_soon') ?></div></div>

<?php else: ?>
<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('hr_effective') ?></th><th><?= t('hr_change_type') ?></th><th><?= t('hr_change_detail') ?></th><th><?= t('hr_change_note') ?></th><th><?= t('hr_operator') ?></th></tr></thead>
    <tbody>
    <?php if (!$changes): ?><tr><td colspan="5" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
    <?php foreach ($changes as $c): ?>
        <tr><td class="nowrap"><?= e(Hr::dmy($c['effective_date'])) ?></td>
            <td><?= t('hrchange_' . $c['change_type']) ?></td>
            <td><?= e(t('hrfield_' . $c['field'])) ?>: <?= e($valueOf($c['field'], $c['old_value'])) ?> → <strong><?= e($valueOf($c['field'], $c['new_value'])) ?></strong></td>
            <td><?= e($c['note']) ?></td>
            <td class="muted small"><?= e($c['operator']) ?> · <?= e(Hr::dmy($c['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php endif; ?>
