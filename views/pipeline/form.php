<?php
/** @var ?array $deal */ /** @var array $customers */ /** @var ?string $stage */
$isEdit = $deal !== null;
$v = fn(string $k) => e($deal[$k] ?? '');
$curStage = $deal['stage'] ?? $stage ?? '初步接触';
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑商机' : '新增商机' ?></h1>
    <a class="btn btn-ghost" href="<?= url('pipeline.index') ?>">返回看板</a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'pipeline.update' : 'pipeline.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $deal['id'] ?>"><?php endif; ?>
        <div class="form-group"><label class="form-label">商机名称 *</label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">关联客户</label>
                <select class="form-select" name="customer_id">
                    <option value="">— 选择客户 —</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($deal['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> · <?= e($c['company']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">商机金额 (Rp) *</label><input class="form-input" type="number" name="value" value="<?= $isEdit ? e($deal['value']) : '0' ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">当前阶段</label>
                <select class="form-select" name="stage">
                    <?php foreach (deal_stages() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $curStage === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">预计成交日期</label><input class="form-input" type="date" name="close_date" value="<?= $v('close_date') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">备注</label><textarea class="form-textarea" name="note"><?= $v('note') ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存商机</button></div>
    </form>
</div></div>
