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
    <h1><?= t('btn_add_order') ?></h1>
    <a class="btn btn-ghost" href="<?= url('orders.index') ?>"><?= t('btn_back') ?></a>
</div>

<form method="post" action="<?= url('orders.store') ?>" id="order-form">
    <?= Csrf::field() ?>
    <div class="card"><div class="card-body">
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_customer') ?> *</label>
                <select class="form-select" name="customer_id" id="customer-select" required>
                    <option value="">—</option>
                    <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> · <?= e($c['company']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('th_company') ?></label><input class="form-input" name="company" id="f-company"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_phone') ?></label><input class="form-input" name="phone" id="f-phone"></div>
            <div class="form-group"><label class="form-label"><?= t('f_client_type') ?></label>
                <select class="form-select" name="client_type"><?php foreach (client_types() as $ct): ?><option><?= $ct ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('f_address') ?></label><input class="form-input" name="address" id="f-address"></div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('f_delivery_service') ?></label>
                <select class="form-select" name="delivery_service"><?php foreach (delivery_services() as $d): ?><option><?= $d ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_delivery_date') ?></label><input class="form-input" type="date" name="delivery_date"></div>
            <div class="form-group"><label class="form-label"><?= t('f_shipping') ?></label><input class="form-input" type="number" name="shipping_cost" id="ship" value="0"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('delivery_addr') ?></label><input class="form-input" name="delivery_address"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_payment_term') ?></label>
                <select class="form-select" name="payment_term" id="pterm">
                    <option value="CBD">CBD</option>
                    <option value="COD">COD</option>
                    <option value="custom">Net (custom)</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_custom_days') ?></label><input class="form-input" type="number" name="custom_days" value="0"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('th_note') ?></label><textarea class="form-textarea" name="note"></textarea></div>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('product_items') ?> <span class="muted" style="font-weight:400;font-size:11px">· 单价为含税价 / harga termasuk PPN</span></span><button type="button" class="btn btn-sm btn-ghost" id="add-row"><?= t('btn_add_row') ?></button></div>
        <div class="table-wrap"><table id="items-table">
            <thead><tr><th style="width:30%"><?= t('th_product') ?></th><th class="right"><?= t('th_qty') ?></th><th class="right"><?= t('th_unit_price') ?></th><th class="right"><?= t('th_subtotal') ?></th><th></th></tr></thead>
            <tbody>
                <!-- rows injected by JS -->
            </tbody>
            <tfoot>
                <tr class="total-row"><td colspan="3" class="right"><?= t('total') ?>（含税 incl. VAT）</td><td class="right" id="t-total">Rp 0</td><td></td></tr>
                <tr><td colspan="3" class="right" style="color:var(--text3)">其中含 PPN 12% / termasuk PPN</td><td class="right" id="t-ppn" style="color:var(--text3)">Rp 0</td><td></td></tr>
                <tr><td colspan="3" class="right" style="color:var(--text3)">税前 Subtotal (DPP base)</td><td class="right" id="t-subtotal" style="color:var(--text3)">Rp 0</td><td></td></tr>
            </tfoot>
        </table></div>
    </div>

    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_order') ?></button></div>
</form>

<template id="row-tpl">
    <tr class="item-row">
        <td>
            <select class="form-select product-select" name="sku_select"><option value="">—</option>
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
    const gross = subtotal + (parseFloat(document.getElementById('ship').value) || 0); // tax-inclusive
    const ppn = Math.round(gross * 11 / 111);   // PPN embedded in inclusive price
    document.getElementById('t-total').textContent = fmt(gross);
    document.getElementById('t-ppn').textContent = fmt(ppn);
    document.getElementById('t-subtotal').textContent = fmt(gross - ppn);
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
