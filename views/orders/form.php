<?php
/** @var array $customers */ /** @var array $products */
$prodJson = json_encode(array_map(fn($p) => [
    'id' => (int) $p['id'], 'sku' => $p['sku'], 'color' => $p['color_en'], 'spec' => $p['spec'],
    'size' => $p['size'], 'price' => (float) $p['price'], 'stock' => (int) $p['stock'],
], $products), JSON_UNESCAPED_UNICODE);
$custJson = json_encode(array_map(fn($c) => [
    'id' => (int) $c['id'], 'company' => $c['company'], 'phone' => $c['phone'],
], $customers), JSON_UNESCAPED_UNICODE);
?>
<div class="page-head">
    <h1>新建销售订单</h1>
    <a class="btn btn-ghost" href="<?= url('orders.index') ?>">返回列表</a>
</div>

<form method="post" action="<?= url('orders.store') ?>" id="order-form">
    <?= Csrf::field() ?>
    <div class="card"><div class="card-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label">客户 *</label>
                <select class="form-select" name="customer_id" id="customer-select" required>
                    <option value="">— 选择客户 —</option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> · <?= e($c['company']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label">公司</label><input class="form-input" name="company" id="f-company"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">电话</label><input class="form-input" name="phone" id="f-phone"></div>
            <div class="form-group"><label class="form-label">客户类型</label>
                <select class="form-select" name="client_type"><?php foreach (client_types() as $ct): ?><option><?= $ct ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="form-group"><label class="form-label">客户地址</label><input class="form-input" name="address" id="f-address"></div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label">配送方式</label>
                <select class="form-select" name="delivery_service"><?php foreach (delivery_services() as $d): ?><option><?= $d ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group"><label class="form-label">送货日期</label><input class="form-input" type="date" name="delivery_date"></div>
            <div class="form-group"><label class="form-label">运费 (Rp)</label><input class="form-input" type="number" name="shipping_cost" id="ship" value="0"></div>
        </div>
        <div class="form-group"><label class="form-label">送货地址</label><input class="form-input" name="delivery_address"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">付款条件</label>
                <select class="form-select" name="payment_term" id="pterm">
                    <option value="CBD">CBD（货前付款）</option>
                    <option value="COD">COD（货到付款）</option>
                    <option value="custom">账期 Net（自定义天数）</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label">账期天数</label><input class="form-input" type="number" name="custom_days" value="0"></div>
        </div>
        <div class="form-group"><label class="form-label">备注</label><textarea class="form-textarea" name="note"></textarea></div>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title">产品明细</span><button type="button" class="btn btn-sm btn-ghost" id="add-row">＋ 添加行</button></div>
        <div class="table-wrap"><table id="items-table">
            <thead><tr><th style="width:30%">产品</th><th class="right">数量</th><th class="right">单价</th><th class="right">小计</th><th></th></tr></thead>
            <tbody>
                <!-- rows injected by JS -->
            </tbody>
            <tfoot>
                <tr><td colspan="3" class="right">小计（含运费）</td><td class="right" id="t-subtotal">Rp 0</td><td></td></tr>
                <tr><td colspan="3" class="right">PPN 11%</td><td class="right" id="t-ppn">Rp 0</td><td></td></tr>
                <tr class="total-row"><td colspan="3" class="right">合计</td><td class="right" id="t-total">Rp 0</td><td></td></tr>
            </tfoot>
        </table></div>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit">提交订单（进入主管审批）</button></div>
</form>

<template id="row-tpl">
    <tr class="item-row">
        <td>
            <select class="form-select product-select" name="sku_select"><option value="">— 选择产品 —</option>
                <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['sku']) ?> · <?= e($p['color_en']) ?> · <?= e($p['spec']) ?> (<?= $p['stock'] ?>)</option><?php endforeach; ?>
            </select>
            <input type="hidden" name="sku[]" class="f-sku"><input type="hidden" name="color[]" class="f-color">
            <input type="hidden" name="spec[]" class="f-spec"><input type="hidden" name="size[]" class="f-size">
        </td>
        <td><input class="form-input qty right" type="number" name="qty[]" value="1" style="width:90px"></td>
        <td><input class="form-input price right" type="number" name="price[]" value="0" style="width:120px"></td>
        <td class="right line-total">Rp 0</td>
        <td class="right"><button type="button" class="btn btn-ghost btn-sm remove-row" style="color:var(--danger)">×</button></td>
    </tr>
</template>

<script>
const PRODUCTS = <?= $prodJson ?>;
const CUSTOMERS = <?= $custJson ?>;
const fmt = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');

document.getElementById('customer-select').addEventListener('change', e => {
    const c = CUSTOMERS.find(x => x.id == e.target.value);
    if (c) {
        document.getElementById('f-company').value = c.company || '';
        document.getElementById('f-address').value = c.address || '';
        document.getElementById('f-phone').value = c.phone || '';
    }
});

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(r => {
        const qty = parseFloat(r.querySelector('.qty').value) || 0;
        const price = parseFloat(r.querySelector('.price').value) || 0;
        const line = qty * price;
        subtotal += line;
        r.querySelector('.line-total').textContent = fmt(line);
    });
    subtotal += parseFloat(document.getElementById('ship').value) || 0;
    const ppn = Math.round(subtotal * 0.11);
    document.getElementById('t-subtotal').textContent = fmt(subtotal);
    document.getElementById('t-ppn').textContent = fmt(ppn);
    document.getElementById('t-total').textContent = fmt(subtotal + ppn);
}

function bindRow(row) {
    const sel = row.querySelector('.product-select');
    sel.addEventListener('change', () => {
        const p = PRODUCTS.find(x => x.id == sel.value);
        if (p) {
            row.querySelector('.f-sku').value = p.sku;
            row.querySelector('.f-color').value = p.color;
            row.querySelector('.f-spec').value = p.spec;
            row.querySelector('.f-size').value = p.size;
            row.querySelector('.price').value = p.price;
        }
        recalc();
    });
    row.querySelector('.remove-row').addEventListener('click', () => {
        if (document.querySelectorAll('.item-row').length > 1) row.remove();
        recalc();
    });
    row.querySelectorAll('.qty,.price').forEach(i => i.addEventListener('input', recalc));
}

function addRow() {
    const tpl = document.getElementById('row-tpl').content.cloneNode(true);
    const tbody = document.querySelector('#items-table tbody');
    tbody.appendChild(tpl);
    bindRow(tbody.lastElementChild);
}
document.getElementById('add-row').addEventListener('click', addRow);
document.getElementById('ship').addEventListener('input', recalc);
addRow();
</script>
