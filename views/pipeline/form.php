<?php
/** @var ?array $deal */ /** @var array $customers */ /** @var ?string $stage */
$isEdit = $deal !== null;
$v = fn(string $k) => e($deal[$k] ?? '');
$curStage = $deal['stage'] ?? $stage ?? '初步接触';
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') : t('btn_new') ?> · <?= t('nav_pipeline') ?></h1>
    <a class="btn btn-ghost" href="<?= url('pipeline.index') ?>"><?= t('btn_back') ?></a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'pipeline.update' : 'pipeline.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $deal['id'] ?>"><?php endif; ?>
        <div class="form-group"><label class="form-label"><?= t('f_deal_name') ?></label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_customer') ?></label>
                <select class="form-select" name="customer_id">
                    <option value="">—</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($deal['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> · <?= e($c['company']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_deal_value') ?></label><input class="form-input" type="number" name="value" value="<?= $isEdit ? e($deal['value']) : '0' ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_stage') ?></label>
                <select class="form-select" name="stage">
                    <?php foreach (deal_stages() as $sg): ?>
                        <option value="<?= e($sg) ?>" <?= $curStage === $sg ? 'selected' : '' ?>><?= e(tr_stage($sg)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_close_date') ?></label><input class="form-input" type="date" name="close_date" value="<?= $v('close_date') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('f_note') ?></label><textarea class="form-textarea" name="note"><?= $v('note') ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_deal') ?></button></div>
    </form>
</div></div>
