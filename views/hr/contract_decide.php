<?php /** @var array $c */ /** @var array $e */ /** @var string $today */ /** @var ?string $salary */ /** @var bool $overLimit */ /** @var int $pkwtDays */
include __DIR__ . '/_contract_status.php';
$next = HrContract::add_days((string) $c['end_date'], 1);
$years = round($pkwtDays / 365, 1);
?>
<div class="page-head">
    <h1><?= t('hrc_decide') ?> · <code><?= e($c['contract_no']) ?></code> <?= $contractTag((string) $c['status']) ?></h1>
    <a class="btn btn-ghost" href="<?= url('hr.contract', ['id' => $c['id']]) ?>"><?= t('btn_back') ?></a>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>
<p class="muted"><?= e($e['employee_no'] . ' ' . $e['full_name']) ?> · PKWT <?= e(Hr::dmy($c['start_date'])) ?> – <?= e(Hr::dmy($c['end_date'])) ?>
    · <?= sprintf(t('hrc_chain_summary'), (int) $c['renewal_count'], $years) ?></p>

<div class="card">
    <div class="card-header"><span class="card-title">1 · <?= t('hrc_renew') ?></span></div>
    <div class="card-body">
        <p class="alert alert-error" id="hrc-limit" style="<?= $overLimit ? '' : 'display:none' ?>"><?= t('hrc_limit_warning') ?></p>
        <form method="post" action="<?= url('hr.contract_renew') ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrc_start') ?></label><input class="form-input" type="date" value="<?= e($next) ?>" disabled></div>
                <div class="form-group"><label class="form-label"><?= t('hrc_end') ?> *</label><input class="form-input" type="date" name="end_date" id="hrc-new-end" min="<?= e($next) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_position') ?></label><input class="form-input" name="position" value="<?= e($c['position']) ?>"></div>
                <div class="form-group"><label class="form-label"><?= t('hr_location') ?></label>
                    <select class="form-select" name="work_location"><?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $c['work_location'] === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-group"><label class="form-label"><?= t('hrc_salary') ?> (Rp)</label><input class="form-input" type="number" step="any" min="0" name="salary" value="<?= e($salary ?? '') ?>" placeholder="<?= t('hr_salary_ph') ?>"></div>
            <button class="btn btn-primary" type="submit"><?= t('hrc_renew') ?></button>
            <p class="muted small"><?= t('hrc_renew_hint') ?></p>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title">2 · <?= t('hrc_convert') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.contract_convert') ?>">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrc_start') ?> *</label><input class="form-input" type="date" name="start_date" value="<?= e($next) ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('hr_position') ?></label><input class="form-input" name="position" value="<?= e($c['position']) ?>"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hr_location') ?></label>
                    <select class="form-select" name="work_location"><?php foreach (Hr::LOCATIONS as $l): ?><option value="<?= $l ?>" <?= $c['work_location'] === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label class="form-label"><?= t('hrc_salary') ?> (Rp)</label><input class="form-input" type="number" step="any" min="0" name="salary" value="<?= e($salary ?? '') ?>" placeholder="<?= t('hr_salary_ph') ?>"></div>
            </div>
            <button class="btn btn-primary" type="submit"><?= t('hrc_convert') ?></button>
            <p class="muted small"><?= t('hrc_convert_hint') ?></p>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><span class="card-title">3 · <?= t('hrc_terminate') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.contract_terminate') ?>" onsubmit="return confirm(<?= e(json_encode(t('hrc_terminate_confirm'), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrc_termination_date') ?> *</label><input class="form-input" type="date" name="termination_date" value="<?= e((string) $c['end_date']) ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('hrc_termination_reason') ?> *</label><input class="form-input" name="termination_reason" required></div>
            </div>
            <button class="btn btn-danger" type="submit"><?= t('hrc_terminate') ?></button>
            <p class="muted small"><?= t('hrc_terminate_hint') ?></p>
        </form>
    </div>
</div>
<script>
// Live legal-limit hint: renewals already at the limit, or the chain passing 5 years with the new end date.
(function () {
    const base = <?= (int) $pkwtDays ?>, renewals = <?= (int) $c['renewal_count'] ?>, start = new Date(<?= json_encode($next) ?>);
    const end = document.getElementById('hrc-new-end'), box = document.getElementById('hrc-limit');
    end.addEventListener('input', () => {
        const d = end.value ? (new Date(end.value) - start) / 864e5 + 1 : 0;
        box.style.display = (renewals >= <?= HrContract::MAX_RENEWALS ?> || base + d > <?= HrContract::MAX_PKWT_YEARS * 365 + 1 ?>) ? '' : 'none';
    });
})();
</script>
