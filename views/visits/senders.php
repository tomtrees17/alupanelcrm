<?php /** @var array $unmapped */ /** @var array $mapped */ /** @var array $staff */ /** @var bool $canRecord */ /** @var bool $canImport */ ?>
<div class="page-head"><h1><?= t('vis_senders') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_senders_unmapped') ?></span><span class="muted small"><?= count($unmapped) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_sender') ?></th><th class="right"><?= t('vis_count') ?></th><th><?= t('hr_employee') ?></th></tr></thead>
        <tbody>
        <?php if (!$unmapped): ?><tr><td colspan="3" class="empty"><?= t('vis_senders_none') ?></td></tr><?php endif; ?>
        <?php foreach ($unmapped as $u): ?>
            <tr><td><code><?= e($u['sender']) ?></code></td><td class="right"><?= (int) $u['visits'] ?></td>
                <td><form method="post" action="<?= url('visits.senders') ?>" class="inline-form" style="justify-content:flex-start">
                    <?= Csrf::field() ?><input type="hidden" name="sender" value="<?= e($u['sender']) ?>">
                    <select class="form-select" name="employee_id" style="max-width:240px"><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['full_name']) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-primary btn-sm" type="submit"><?= t('btn_save') ?></button></form></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_senders_mapped') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_sender') ?></th><th><?= t('hr_employee') ?></th><th><?= t('hr_operator') ?></th></tr></thead>
        <tbody>
        <?php if (!$mapped): ?><tr><td colspan="3" class="empty"><?= t('no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($mapped as $m): ?>
            <tr><td><code><?= e((string) $m['wa_display']) ?></code></td><td><?= e($m['full_name']) ?></td><td class="muted small"><?= e((string) $m['created_by']) ?> · <?= e(Hr::dmy(substr((string) $m['created_at'], 0, 10))) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<p class="muted small"><?= t('vis_senders_hint') ?></p>
