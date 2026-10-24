<?php /** @var ?array $result */ /** @var int $left */ /** @var bool $aiOn */ /** @var string $maxUpload */ /** @var string $maxPost */ /** @var array $recent */ /** @var array $unmapped */ /** @var bool $canRecord */ /** @var bool $canImport */ ?>
<div class="page-head"><h1><?= t('vis_import') ?></h1></div>
<?php include __DIR__ . '/_nav.php'; ?>

<?php if ($result): ?>
<div class="card"><div class="card-body vis-import-result">
    <div><strong><?= (int) $result['new'] ?></strong><span><?= t('vis_imp_new') ?></span></div>
    <div><strong><?= (int) $result['photos'] ?></strong><span><?= t('vis_imp_photos') ?></span></div>
    <div><strong class="<?= $result['unmapped'] ? 'hr-low' : '' ?>"><?= count($result['unmapped']) ?></strong><span><?= t('vis_imp_unmapped') ?></span></div>
</div>
<?php if ($result['unmapped']): ?><div class="card-body"><a class="btn btn-primary btn-sm" href="<?= url('visits.senders') ?>"><?= t('vis_imp_map_now') ?></a> <span class="muted small"><?= e(implode(', ', $result['unmapped'])) ?></span></div><?php endif; ?>
<div class="card-body muted small"><?= sprintf(t('vis_imp_detail'), (int) $result['messages'], (int) $result['found'], (int) $result['found'] - (int) $result['new'], (int) $result['ignored']) ?></div>
</div>
<?php endif; ?>

<?php if ($left > 0): ?>
<div class="card"><div class="card-body inline-form" style="justify-content:flex-start">
    <span><?= sprintf(t($aiOn ? 'vis_imp_left_ai' : 'vis_imp_left'), $left) ?></span>
    <form method="post" action="<?= url('visits.ai_run') ?>"><?= Csrf::field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= t('vis_imp_continue') ?></button></form>
</div></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_imp_upload') ?></span></div>
    <div class="card-body">
        <form method="post" action="<?= url('visits.import') ?>" enctype="multipart/form-data" class="inline-form" style="justify-content:flex-start">
            <?= Csrf::field() ?>
            <input class="form-input" type="file" name="file" accept=".zip,.txt,application/zip,text/plain" required>
            <button class="btn btn-primary" type="submit"><?= t('vis_imp_go') ?></button>
        </form>
        <p class="muted small"><?= sprintf(t('vis_imp_hint'), e($maxUpload), e($maxPost)) ?></p>
        <?php if (!$aiOn): ?><p class="muted small"><?= t('vis_imp_ai_off') ?></p><?php endif; ?>
    </div>
</div>

<?php if ($recent): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('vis_imp_history') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('vis_date') ?></th><th><?= t('hr_file') ?></th><th class="right"><?= t('vis_imp_new') ?></th><th class="right"><?= t('vis_imp_photos') ?></th><th class="right"><?= t('vis_imp_unmapped') ?></th><th><?= t('hr_operator') ?></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $w): ?>
            <tr><td class="nowrap"><?= e(Hr::dmy(substr((string) $w['created_at'], 0, 10))) ?> <?= e(substr((string) $w['created_at'], 11, 5)) ?></td><td class="small"><?= e((string) $w['file_name']) ?></td>
                <td class="right"><?= (int) $w['visits_new'] ?></td><td class="right"><?= (int) $w['photos_added'] ?></td><td class="right"><?= count((array) json_decode((string) $w['unmapped'], true)) ?></td>
                <td class="small"><?= e((string) $w['created_by']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
