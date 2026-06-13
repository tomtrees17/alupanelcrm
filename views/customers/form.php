<?php
/** @var ?array $customer */
$isEdit = $customer !== null;
$v = fn(string $k) => e($customer[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑客户' : '新建客户' ?></h1>
    <a class="btn btn-ghost" href="<?= url('customers.index') ?>">返回列表</a>
</div>

<div class="card">
    <form method="post" action="<?= url($isEdit ? 'customers.update' : 'customers.store') ?>" class="form">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>"><?php endif; ?>

        <div class="grid-2">
            <label>公司名称 *<input type="text" name="company" value="<?= $v('company') ?>" required></label>
            <label>联系人<input type="text" name="contact_name" value="<?= $v('contact_name') ?>"></label>
            <label>邮箱<input type="email" name="email" value="<?= $v('email') ?>"></label>
            <label>电话<input type="text" name="phone" value="<?= $v('phone') ?>"></label>
            <label>城市<input type="text" name="city" value="<?= $v('city') ?>"></label>
            <label>国家<input type="text" name="country" value="<?= $v('country') ?>"></label>
        </div>
        <label>地址<input type="text" name="address" value="<?= $v('address') ?>"></label>
        <label>备注<textarea name="notes" rows="3"><?= $v('notes') ?></textarea></label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存</button>
        </div>
    </form>
</div>
