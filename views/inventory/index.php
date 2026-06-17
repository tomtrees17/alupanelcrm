<?php /** @var array $products */ /** @var array $stats */ /** @var array $cats */ /** @var string $q */ /** @var string $cat */ /** @var bool $low */ ?>
<div class="page-head">
    <h1><?= t('page_inventory') ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('inventory.txns') ?>"><?= t('btn_stock_txns') ?></a>
        <?php if (can_export()): ?><a class="btn btn-ghost" href="<?= url('inventory.export') ?>"><?= t('btn_export') ?></a><?php endif; ?>
        <?php if (can_edit_inventory()): ?><a class="btn btn-primary" href="<?= url('inventory.create') ?>"><?= t('btn_add_product') ?></a><?php endif; ?>
    </div>
</div>

<div class="inv-grid">
    <div class="inv-stat"><div class="inv-stat-label"><?= t('inv_skus') ?></div><div class="inv-stat-val"><?= $stats['skus'] ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label"><?= t('inv_total_stock') ?></div><div class="inv-stat-val"><?= number_format($stats['stock']) ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label"><?= t('inv_low') ?></div><div class="inv-stat-val stock-low"><?= $stats['low'] ?></div></div>
    <div class="inv-stat"><div class="inv-stat-label"><?= t('inv_out') ?></div><div class="inv-stat-val stock-low"><?= $stats['out'] ?></div></div>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="inventory.index">
    <input class="form-input" type="text" name="q" value="<?= e($q) ?>" placeholder="SKU...">
    <select class="form-select filter-select" name="cat" onchange="this.form.submit()">
        <option value=""><?= t('all_specs') ?></option>
        <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
    </select>
    <label class="btn btn-ghost" style="gap:6px"><input type="checkbox" name="low" value="1" onchange="this.form.submit()" <?= $low ? 'checked' : '' ?> style="width:auto"> <?= t('only_low') ?></label>
    <button class="btn btn-ghost" type="submit"><?= t('btn_search') ?></button>
</form>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('th_sku') ?></th><th><?= t('th_color') ?></th><th><?= t('th_spec') ?></th><th class="right"><?= t('available') ?></th><th class="right"><?= t('th_min_stock') ?></th><th class="right"><?= t('th_price') ?></th><th class="right"><?= t('th_action') ?></th></tr></thead>
        <tbody>
        <?php if (!$products): ?><tr><td colspan="7" class="empty"><?= t('no_products') ?></td></tr><?php endif; ?>
        <?php foreach ($products as $p):
            $reserved = (int) ($p['reserved'] ?? 0);
            $avail = $p['stock'] - $reserved;
            $isLow = $avail <= $p['min_stock'];
        ?>
            <tr>
                <td><code><?= e($p['sku']) ?></code></td>
                <td><?= e($p['color_zh']) ?> / <?= e($p['color_en']) ?></td>
                <td><?= e($p['spec']) ?></td>
                <td class="right">
                    <span class="<?= $isLow ? 'stock-low' : 'stock-ok' ?>"><?= number_format($avail) ?></span>
                    <?php if ($reserved > 0): ?><div class="reserved-note"><?= t('have') ?> <?= number_format($p['stock']) ?> · <?= t('reserved') ?> <?= number_format($reserved) ?></div><?php endif; ?>
                </td>
                <td class="right muted"><?= $p['min_stock'] ?></td>
                <td class="right"><?= $p['price'] > 0 ? idr($p['price']) : '—' ?></td>
                <td class="right" style="white-space:nowrap">
                    <?php if (can_edit_inventory()): ?>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="document.getElementById('adj-<?= $p['id'] ?>').style.display='table-row'"><?= t('stock_adjust') ?></button>
                        <a class="btn btn-ghost btn-sm" href="<?= url('inventory.edit', ['id' => $p['id']]) ?>"><?= t('btn_edit') ?></a>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (can_edit_inventory()): ?>
            <tr id="adj-<?= $p['id'] ?>" style="display:none;background:var(--surface2)">
                <td colspan="7">
                    <form method="post" action="<?= url('inventory.adjust') ?>" class="flex-center" style="gap:8px;flex-wrap:wrap">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <select class="form-select" name="type" style="width:auto"><option value="in"><?= t('txn_in') ?> +</option><option value="out"><?= t('txn_out') ?> −</option></select>
                        <input class="form-input" type="number" name="qty" placeholder="<?= t('th_qty') ?>" min="1" style="width:120px" required>
                        <input class="form-input" name="ref" placeholder="<?= t('th_ref') ?>" style="width:200px">
                        <button class="btn btn-primary btn-sm" type="submit"><?= t('btn_submit') ?></button>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
