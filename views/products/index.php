<?php /** @var array $products */ /** @var string $q */ ?>
<div class="page-head">
    <h1>产品</h1>
    <a class="btn btn-primary" href="<?= url('products.create') ?>">+ 新建产品</a>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="products.index">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜索名称 / SKU / 规格">
    <button class="btn" type="submit">搜索</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= url('products.index') ?>">清除</a><?php endif; ?>
</form>

<div class="card">
    <table class="table">
        <thead>
        <tr><th>SKU</th><th>名称</th><th>规格</th><th>单位</th><th class="right">单价</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$products): ?><tr><td colspan="6" class="empty">暂无产品</td></tr><?php endif; ?>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><code><?= e($p['sku']) ?></code></td>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['spec']) ?></td>
                <td><?= e($p['unit']) ?></td>
                <td class="right"><?= money($p['price']) ?></td>
                <td class="right">
                    <a class="btn btn-ghost btn-sm" href="<?= url('products.edit', ['id' => $p['id']]) ?>">编辑</a>
                    <form method="post" action="<?= url('products.delete') ?>" class="inline" onsubmit="return confirm('确定删除该产品？')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button class="btn btn-ghost btn-sm danger-text" type="submit">删除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
