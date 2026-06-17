<?php
/** @var ?array $product */
$isEdit = $product !== null;
$v = fn(string $k) => e($product[$k] ?? '');
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') : t('btn_new') ?> · <?= t('th_product') ?></h1>
    <a class="btn btn-ghost" href="<?= url('inventory.index') ?>"><?= t('btn_back') ?></a>
</div>

<div class="card"><div class="card-body">
    <form method="post" action="<?= url($isEdit ? 'inventory.update' : 'inventory.store') ?>">
        <?= Csrf::field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_sku') ?></label><input class="form-input" name="sku" value="<?= $v('sku') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('th_product') ?> *</label><input class="form-input" name="name" value="<?= $v('name') ?>" required placeholder="纯白 / Pure White"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_color') ?> ZH</label><input class="form-input" name="color_zh" value="<?= $v('color_zh') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('th_color') ?> (EN)</label><input class="form-input" name="color_en" value="<?= $v('color_en') ?>"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('th_spec') ?></label><input class="form-input" name="spec" value="<?= $v('spec') ?>" placeholder="4.0*0.21"></div>
            <div class="form-group"><label class="form-label">Size</label><input class="form-input" name="size" value="<?= $isEdit ? $v('size') : '1.220 x 2.440' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('all_specs') ?></label><input class="form-input" name="category" value="<?= $v('category') ?>"></div>
        </div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('th_price') ?> (Rp)</label><input class="form-input" type="number" step="0.01" name="price" value="<?= $isEdit ? e($product['price']) : '0' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('th_stock') ?></label><input class="form-input" type="number" name="stock" value="<?= $isEdit ? e($product['stock']) : '0' ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('th_min_stock') ?></label><input class="form-input" type="number" name="min_stock" value="<?= $isEdit ? e($product['min_stock']) : '30' ?>"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_product') ?></button></div>
    </form>
</div></div>
