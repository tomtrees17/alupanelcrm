<?php
/** @var ?array $product */
$isEdit = $product !== null;
$v = fn(string $k) => e($product[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑产品' : '新建产品' ?></h1>
    <a class="btn btn-ghost" href="<?= url('products.index') ?>">返回列表</a>
</div>

<div class="card">
    <form method="post" action="<?= url($isEdit ? 'products.update' : 'products.store') ?>" class="form">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>

        <div class="grid-2">
            <label>SKU<input type="text" name="sku" value="<?= $v('sku') ?>"></label>
            <label>名称 *<input type="text" name="name" value="<?= $v('name') ?>" required></label>
            <label>规格<input type="text" name="spec" value="<?= $v('spec') ?>"></label>
            <label>单位<input type="text" name="unit" value="<?= $isEdit ? $v('unit') : 'sheet' ?>"></label>
            <label>单价<input type="number" step="0.01" name="price" value="<?= $isEdit ? e($product['price']) : '0.00' ?>"></label>
        </div>
        <label>描述<textarea name="description" rows="3"><?= $v('description') ?></textarea></label>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存</button>
        </div>
    </form>
</div>
