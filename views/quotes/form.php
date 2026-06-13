<?php
/** @var ?array $quote */
/** @var array $items */
/** @var array $customers */
/** @var array $products */
$isEdit = $quote !== null;
$selCustomer = (int) ($quote['customer_id'] ?? input('customer_id', 0));
$rows = $items ?: [['product_id' => '', 'description' => '', 'qty' => 1, 'unit_price' => 0]];
$prodJson = json_encode(array_map(fn($p) => [
    'id' => (int) $p['id'], 'name' => $p['name'], 'price' => (float) $p['price'],
    'spec' => $p['spec'],
], $products), JSON_UNESCAPED_UNICODE);
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑报价单' : '新建报价单' ?></h1>
    <a class="btn btn-ghost" href="<?= url('quotes.index') ?>">返回列表</a>
</div>

<form method="post" action="<?= url($isEdit ? 'quotes.update' : 'quotes.store') ?>" id="quote-form">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>"><?php endif; ?>

    <div class="card">
        <div class="grid-4">
            <label>客户 *
                <select name="customer_id" required>
                    <option value="">— 选择客户 —</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $c['id'] == $selCustomer ? 'selected' : '' ?>><?= e($c['company']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>日期<input type="date" name="quote_date" value="<?= e($quote['quote_date'] ?? date('Y-m-d')) ?>"></label>
            <label>有效期至<input type="date" name="valid_until" value="<?= e($quote['valid_until'] ?? '') ?>"></label>
            <label>状态
                <select name="status">
                    <?php foreach (status_list() as $s): ?>
                        <option value="<?= e($s) ?>" <?= ($quote['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>明细</h2><button type="button" class="btn btn-sm" id="add-row">+ 添加行</button></div>
        <table class="table" id="items-table">
            <thead>
            <tr><th style="width:34%">产品</th><th>说明</th><th class="right" style="width:90px">数量</th><th class="right" style="width:120px">单价</th><th class="right" style="width:120px">小计</th><th style="width:40px"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $it): ?>
                <tr class="item-row">
                    <td>
                        <select name="product_id[]" class="product-select">
                            <option value="">— 自定义 —</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= ($it['product_id'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="item_desc[]" value="<?= e($it['description'] ?? '') ?>" placeholder="说明 / 规格"></td>
                    <td><input type="number" step="0.01" name="qty[]" class="qty right" value="<?= e($it['qty'] ?? 1) ?>"></td>
                    <td><input type="number" step="0.01" name="unit_price[]" class="price right" value="<?= e($it['unit_price'] ?? 0) ?>"></td>
                    <td class="right line-total">0.00</td>
                    <td class="right"><button type="button" class="btn btn-ghost btn-sm danger-text remove-row">×</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="grid-2">
            <label>备注<textarea name="notes" rows="3"><?= e($quote['notes'] ?? '') ?></textarea></label>
            <div class="totals-box">
                <div><span>小计</span><strong id="t-subtotal">0.00</strong></div>
                <div><span>税率 %</span><input type="number" step="0.01" name="tax_rate" id="tax-rate" value="<?= e($quote['tax_rate'] ?? 0) ?>" style="width:90px"></div>
                <div><span>税额</span><strong id="t-tax">0.00</strong></div>
                <div class="grand"><span>合计</span><strong id="t-total">0.00</strong></div>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">保存报价单</button>
        </div>
    </div>
</form>

<template id="row-template">
    <tr class="item-row">
        <td>
            <select name="product_id[]" class="product-select">
                <option value="">— 自定义 —</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="item_desc[]" placeholder="说明 / 规格"></td>
        <td><input type="number" step="0.01" name="qty[]" class="qty right" value="1"></td>
        <td><input type="number" step="0.01" name="unit_price[]" class="price right" value="0"></td>
        <td class="right line-total">0.00</td>
        <td class="right"><button type="button" class="btn btn-ghost btn-sm danger-text remove-row">×</button></td>
    </tr>
</template>

<script>
const PRODUCTS = <?= $prodJson ?>;
const cur = '<?= e($GLOBALS['config']['currency']) ?>';

function fmt(n) { return cur + Number(n || 0).toFixed(2); }

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty').value) || 0;
        const price = parseFloat(row.querySelector('.price').value) || 0;
        const line = qty * price;
        subtotal += line;
        row.querySelector('.line-total').textContent = fmt(line);
    });
    const rate = parseFloat(document.getElementById('tax-rate').value) || 0;
    const tax = subtotal * rate / 100;
    document.getElementById('t-subtotal').textContent = fmt(subtotal);
    document.getElementById('t-tax').textContent = fmt(tax);
    document.getElementById('t-total').textContent = fmt(subtotal + tax);
}

function onProductChange(select) {
    const row = select.closest('.item-row');
    const p = PRODUCTS.find(x => x.id == select.value);
    if (p) {
        row.querySelector('[name="unit_price[]"]').value = p.price.toFixed(2);
        const desc = row.querySelector('[name="item_desc[]"]');
        if (!desc.value) desc.value = p.spec || p.name;
    }
    recalc();
}

document.addEventListener('input', e => {
    if (e.target.matches('.qty, .price, #tax-rate')) recalc();
});
document.addEventListener('change', e => {
    if (e.target.matches('.product-select')) onProductChange(e.target);
});
document.addEventListener('click', e => {
    if (e.target.matches('.remove-row')) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length > 1) e.target.closest('.item-row').remove();
        else e.target.closest('.item-row').querySelectorAll('input').forEach(i => { if (!i.classList.contains('qty')) i.value=''; });
        recalc();
    }
});
document.getElementById('add-row').addEventListener('click', () => {
    const tpl = document.getElementById('row-template').content.cloneNode(true);
    document.querySelector('#items-table tbody').appendChild(tpl);
    recalc();
});

recalc();
</script>
