<?php
/** @var ?array $customer */
$isEdit = $customer !== null;
$v = fn(string $k) => e($customer[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') : t('btn_new') ?> · <?= t('nav_customers') ?></h1>
    <a class="btn btn-ghost" href="<?= url('customers.index') ?>"><?= t('btn_back') ?></a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'customers.update' : 'customers.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_name') ?></label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
            <div class="form-group"><label class="form-label"><?= t('f_company') ?></label><input class="form-input" name="company" value="<?= $v('company') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_phone') ?></label><input class="form-input" name="phone" value="<?= $v('phone') ?>" placeholder="08xx / +62 8xx"></div>
            <div class="form-group"><label class="form-label"><?= t('f_email') ?></label><input class="form-input" type="email" name="email" value="<?= $v('email') ?>"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('f_city') ?></label><input class="form-input" name="city" value="<?= $v('city') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('f_tag') ?></label>
                <select class="form-select" name="tag">
                    <?php foreach (['潜在', '重点', '成交', '流失'] as $tg): ?>
                        <option value="<?= $tg ?>" <?= ($customer['tag'] ?? '潜在') === $tg ? 'selected' : '' ?>><?= e(tr_tag($tg)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_value') ?></label><input class="form-input" type="number" name="value" value="<?= $isEdit ? e($customer['value']) : '0' ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_last_contact') ?></label><input class="form-input" type="date" name="last_contact" value="<?= $v('last_contact') ?>"></div>
            <?php if (!empty($canAssign)): ?>
            <div class="form-group"><label class="form-label"><?= t('owner') ?></label>
                <select class="form-select" name="owner">
                    <option value="">—</option>
                    <?php foreach (($staff ?? []) as $st): ?>
                        <option value="<?= e($st['name']) ?>" <?= ($customer['owner'] ?? '') === $st['name'] ? 'selected' : '' ?>><?= e($st['name']) ?> · <?= e(role_label($st['role'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-group"><label class="form-label"><?= t('f_note') ?></label><textarea class="form-textarea" name="note"><?= $v('note') ?></textarea></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_customer') ?></button></div>
    </form>
</div></div>
