<?php
/** @var ?array $user */
$isEdit = $user !== null;
$v = fn(string $k) => e($user[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑用户' : '新建用户' ?></h1>
    <a class="btn btn-ghost" href="<?= url('users.index') ?>">返回列表</a>
</div>

<div class="card">
    <form method="post" action="<?= url($isEdit ? 'users.update' : 'users.store') ?>" class="form">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $user['id'] ?>"><?php endif; ?>

        <div class="grid-2">
            <label>姓名 *<input type="text" name="name" value="<?= $v('name') ?>" required></label>
            <label>邮箱 *<input type="email" name="email" value="<?= $v('email') ?>" required></label>
            <label>角色
                <select name="role">
                    <option value="sales" <?= ($user['role'] ?? '') === 'sales' ? 'selected' : '' ?>>销售</option>
                    <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>管理员</option>
                </select>
            </label>
            <label>密码 <?= $isEdit ? '<small>（留空则不修改）</small>' : '*' ?>
                <input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6">
            </label>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存</button>
        </div>
    </form>
</div>
