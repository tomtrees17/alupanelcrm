<?php /** @var array $rows */ /** @var array $viewer */
$latest = $rows[0] ?? null;
$pre = fn(string $k) => $latest[$k] ?? (HrBpjs::DEFAULT_RATES[$k] ?? '');
$pct = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') . '%';
?>
<div class="page-head"><h1><?= t('hrb_rates') ?></h1></div>
<?php include __DIR__ . '/_tabs.php'; ?>
<?php include __DIR__ . '/_bpjs_nav.php'; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hrb_rate_history') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('hrb_effective_period') ?></th>
            <th>Kes. <?= t('hrb_company') ?> / <?= t('hrb_employee_share') ?></th><th>JHT</th><th>JKK</th><th>JKM</th><th>JP</th>
            <th class="right"><?= t('hrb_kes_cap') ?></th><th class="right"><?= t('hrb_jp_cap') ?></th><th><?= t('hr_change_note') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="empty"><?= t('hrb_no_rates') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
            <?php $until = $i > 0 ? (new DateTimeImmutable($rows[$i - 1]['effective_from'] . '-01'))->modify('-1 month')->format('Y-m') : null; ?>
            <tr>
                <td class="nowrap"><?= e($r['effective_from']) ?> → <?= $until ? e($until) : t('hrb_now') ?><?= (int) $r['used'] > 0 ? ' <span class="tag tag-gray">' . t('hrb_used') . '</span>' : '' ?></td>
                <td><?= $pct($r['kes_company']) ?> / <?= $pct($r['kes_employee']) ?></td>
                <td><?= $pct($r['jht_company']) ?> / <?= $pct($r['jht_employee']) ?></td>
                <td><?= $pct($r['jkk']) ?></td>
                <td><?= $pct($r['jkm']) ?></td>
                <td><?= $pct($r['jp_company']) ?> / <?= $pct($r['jp_employee']) ?></td>
                <td class="right nowrap"><?= $r['kes_cap'] !== null ? idr((float) $r['kes_cap']) : '—' ?></td>
                <td class="right nowrap"><?= $r['jp_cap'] !== null ? idr((float) $r['jp_cap']) : '—' ?></td>
                <td class="muted small"><?= e((string) $r['note']) ?> · <?= e((string) ($r['updated_by'] ?: $r['created_by'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php if ($viewer['edit']): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hrb_rate_new') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('hr.bpjs_rate_save') ?>">
            <?= Csrf::field() ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrb_effective_from') ?> *</label><input class="form-input" type="month" name="effective_from" value="<?= e(date('Y-m')) ?>" required></div>
                <div class="form-group"><label class="form-label">JKK — <?= t('hrb_jkk_level') ?> *</label>
                    <select class="form-select" name="jkk"><?php foreach (HrBpjs::JKK_LEVELS as $rate => $lvl): ?><option value="<?= $rate ?>" <?= number_format((float) $pre('jkk'), 2, '.', '') === $rate ? 'selected' : '' ?>><?= $rate ?>% · <?= t('hrb_jkk_' . $lvl) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">BPJS Kesehatan — <?= t('hrb_company') ?> % *</label><input class="form-input" name="kes_company" inputmode="decimal" value="<?= e((string) $pre('kes_company')) ?>" required></div>
                <div class="form-group"><label class="form-label">BPJS Kesehatan — <?= t('hrb_employee_share') ?> % *</label><input class="form-input" name="kes_employee" inputmode="decimal" value="<?= e((string) $pre('kes_employee')) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">JHT — <?= t('hrb_company') ?> % *</label><input class="form-input" name="jht_company" inputmode="decimal" value="<?= e((string) $pre('jht_company')) ?>" required></div>
                <div class="form-group"><label class="form-label">JHT — <?= t('hrb_employee_share') ?> % *</label><input class="form-input" name="jht_employee" inputmode="decimal" value="<?= e((string) $pre('jht_employee')) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">JKM % *</label><input class="form-input" name="jkm" inputmode="decimal" value="<?= e((string) $pre('jkm')) ?>" required></div>
                <div class="form-group"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">JP — <?= t('hrb_company') ?> % *</label><input class="form-input" name="jp_company" inputmode="decimal" value="<?= e((string) $pre('jp_company')) ?>" required></div>
                <div class="form-group"><label class="form-label">JP — <?= t('hrb_employee_share') ?> % *</label><input class="form-input" name="jp_employee" inputmode="decimal" value="<?= e((string) $pre('jp_employee')) ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= t('hrb_kes_cap') ?> (Rp) *</label><input class="form-input" type="number" min="1" step="any" name="kes_cap" value="<?= e((string) ($latest['kes_cap'] ?? '')) ?>" required></div>
                <div class="form-group"><label class="form-label"><?= t('hrb_jp_cap') ?> (Rp) *</label><input class="form-input" type="number" min="1" step="any" name="jp_cap" value="<?= e((string) ($latest['jp_cap'] ?? '')) ?>" required></div>
            </div>
            <div class="form-group"><label class="form-label"><?= t('hr_change_note') ?></label><input class="form-input" name="note" placeholder="<?= t('hrb_rate_note_ph') ?>"></div>
            <button class="btn btn-primary" type="submit"><?= t('btn_save') ?></button>
            <p class="muted small"><?= t('hrb_rates_hint') ?></p>
        </form>
    </div>
</div>
<?php endif; ?>
