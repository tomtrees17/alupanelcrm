<?php
/** @var ?array $user */ /** @var array $roles */
$isEdit = $user !== null;
$v = fn(string $k) => e($user[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') : t('btn_new') ?> · <?= t('nav_users') ?></h1>
    <a class="btn btn-ghost" href="<?= url('users.index') ?>"><?= t('btn_back') ?></a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'users.update' : 'users.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_name') ?> *</label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
            <div class="form-group"><label class="form-label"><?= t('th_email') ?> *</label><input class="form-input" type="email" name="email" value="<?= $v('email') ?>" required></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('f_role') ?></label>
                <select class="form-select" name="role">
                    <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= ($user['role'] ?? 'sales') === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_title') ?></label><input class="form-input" name="title" value="<?= $v('title') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label"><?= t('f_phone_wa') ?></label>
                <input class="form-input" name="phone" value="<?= $v('phone') ?>" placeholder="0812xxxxxxx">
                <div class="muted small" style="margin-top:4px"><?= t('f_phone_wa_hint') ?></div>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_lang') ?></label>
                <select class="form-select" name="lang">
                    <?php foreach (['id' => 'Bahasa Indonesia', 'zh' => '中文'] as $lk => $ll): ?>
                        <option value="<?= $lk ?>" <?= ($user['lang'] ?? 'id') === $lk ? 'selected' : '' ?>><?= $ll ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_password') ?> <?= $isEdit ? '' : '*' ?></label><input class="form-input" type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_user') ?></button></div>
    </form>
</div></div>
