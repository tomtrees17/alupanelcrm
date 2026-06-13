<?php
/** @var ?array $user */ /** @var array $roles */
$isEdit = $user !== null;
$v = fn(string $k) => e($user[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑用户' : '新建用户' ?></h1>
    <a class="btn btn-ghost" href="<?= url('users.index') ?>">返回列表</a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'users.update' : 'users.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label">姓名 *</label><input class="form-input" name="name" value="<?= $v('name') ?>" required></div>
            <div class="form-group"><label class="form-label">邮箱 *</label><input class="form-input" type="email" name="email" value="<?= $v('email') ?>" required></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label">角色</label>
                <select class="form-select" name="role">
                    <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= ($user['role'] ?? 'sales') === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">职位</label><input class="form-input" name="title" value="<?= $v('title') ?>"></div>
            <div class="form-group"><label class="form-label">密码 <?= $isEdit ? '<small>(留空不改)</small>' : '*' ?></label><input class="form-input" type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">保存用户</button></div>
    </form>
</div></div>
