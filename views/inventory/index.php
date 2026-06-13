<?php /** @var array $products */ /** @var array $stats */ /** @var array $cats */ /** @var string $q */ /** @var string $cat */ /** @var bool $low */ ?>
<div class="page-head">
    <h1>库存管理</h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('inventory.txns') ?>">出入库流水</a>
        <a class="btn btn-primary" href="<?= url('inventory.create') ?>">＋ 新建产品</a>
    </div>
</div>

<div class="inv-grid">
    <div class="inv-stat"><div class="inv-stat-label">SKU 数</div><div class="inv-stat-val"><?= $stats['skus'] ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label">总库存（张）</div><div class="inv-stat-val"><?= number_format($stats['stock']) ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label">低库存预警</div><div class="inv-stat-val stock-low"><?= $stats['low'] ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label">缺货</div><div class="inv-stat-val stock-low"><?= $stats['out'] ?></div></div>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="inventory.index">
    <input class="form-input" type="text" name="q" value="<?= e($q) ?>" placeholder="搜索 SKU / 颜色">
    <select class="form-select filter-select" name="cat" onchange="this.form.submit()">
        <option value="">全部规格</option>
        <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
    </select>
    <label class="btn btn-ghost" style="gap:6px"><input type="checkbox" name="low" value="1" onchange="this.form.submit()" <?= $low ? 'checked' : '' ?> style="width:auto"> 仅看低库存</label>
    <button class="btn btn-ghost" type="submit">搜索</button>
</form>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th>SKU</th><th>颜色</th><th>规格</th><th class="right">库存</th><th class="right">安全库存</th><th class="right">单价</th><th class="right">操作</th></tr></thead>
        <tbody>
        <?php if (!$products): ?><tr><td colspan="7" class="empty">暂无产品</td></tr><?php endif; ?>
        <?php foreach ($products as $p): $isLow = $p['stock'] <= $p['min_stock']; ?>
            <tr>
                <td><code><?= e($p['sku']) ?></code></td>
                <td><?= e($p['color_zh']) ?> / <?= e($p['color_en']) ?></td>
                <td><?= e($p['spec']) ?></td>
                <td class="right"><span class="<?= $isLow ? 'stock-low' : 'stock-ok' ?>"><?= number_format($p['stock']) ?></span></td>
                <td class="right muted"><?= $p['min_stock'] ?></td>
                <td class="right"><?= $p['price'] > 0 ? idr($p['price']) : '—' ?></td>
                <td class="right" style="white-space:nowrap">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('adj-<?= $p['id'] ?>').style.display='table-row'">±库存</button>
                    <a class="btn btn-ghost btn-sm" href="<?= url('inventory.edit', ['id' => $p['id']]) ?>">编辑</a>
                </td>
            </tr>
            <tr id="adj-<?= $p['id'] ?>" style="display:none;background:var(--surface2)">
                <td colspan="7">
                    <form method="post" action="<?= url('inventory.adjust') ?>" class="flex-center" style="gap:8px;flex-wrap:wrap">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <select class="form-select" name="type" style="width:auto"><option value="in">入库 +</option><option value="out">出库 −</option></select>
                        <input class="form-input" type="number" name="qty" placeholder="数量" min="1" style="width:120px" required>
                        <input class="form-input" name="ref" placeholder="单据/备注" style="width:200px">
                        <button class="btn btn-primary btn-sm" type="submit">提交</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
