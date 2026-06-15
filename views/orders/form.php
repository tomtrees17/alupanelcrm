<?php
/** @var array $customers */ /** @var array $products */
$prodJson = json_encode(array_map(function ($p) {
    $avail = max(0, (int) $p['stock'] - (int) $p['reserved']);
    return [
        'id' => (int) $p['id'], 'sku' => $p['sku'], 'color' => $p['color_en'], 'spec' => $p['spec'],
        'size' => $p['size'], 'price' => (float) $p['price'], 'stock' => $avail, 'min' => (int) $p['min_stock'],
    ];
}, $products), JSON_UNESCAPED_UNICODE);
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
        <td style="position:relative">
            <input type="text" class="form-input product-search" placeholder="搜索 SKU / 颜色 / 规格 …" autocomplete="off">
            <div class="combo-list"></div>
            <input type="hidden" name="product_id[]" class="f-pid">
            <input type="hidden" name="sku[]" class="f-sku"><input type="hidden" name="color[]" class="f-color">
            <input type="hidden" name="spec[]" class="f-spec"><input type="hidden" name="size[]" class="f-size">
        </td>
        <td><input class="form-input qty right" type="number" name="qty[]" value="1" style="width:90px"><div class="stock-warn"></div></td>
        <td><input class="form-input price right" type="number" name="price[]" value="0" style="width:120px"></td>
        <td class="right line-total">Rp 0</td>
        <td class="right"><button type="button" class="btn btn-ghost btn-sm remove-row" style="color:var(--danger)">×</button></td>
    </tr>
</template>

<script>
const PRODUCTS = <?= $prodJson ?>;
const CUSTOMERS = <?= $custJson ?>;
const STOCK_LBL = <?= json_encode(t('available'), JSON_UNESCAPED_UNICODE) ?>;
const AVAIL_LBL = <?= json_encode(t('available'), JSON_UNESCAPED_UNICODE) ?>;
const BLOCK_MSG = <?= json_encode(t('stock_block_submit'), JSON_UNESCAPED_UNICODE) ?>;
const fmt = n => 'Rp ' + Number(n||0).toLocaleString('id-ID');

function validateRow(row) {
    const stock = row.dataset.stock;
    const qtyInput = row.querySelector('.qty');
    const warn = row.querySelector('.stock-warn');
    const qty = parseFloat(qtyInput.value) || 0;
    if (stock !== undefined && stock !== '' && qty > Number(stock)) {
        qtyInput.classList.add('over');
        warn.textContent = STOCK_LBL + ' ' + stock;
        return false;
    }
    qtyInput.classList.remove('over');
    warn.textContent = '';
    return true;
}

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

const pLabel = p => `${p.sku} · ${p.color} · ${p.spec} (${AVAIL_LBL} ${p.stock})`;

function bindRow(row) {
    const input = row.querySelector('.product-search');
    const list = row.querySelector('.combo-list');
    let matches = [], hi = -1;

    const pick = p => {
        row.querySelector('.f-pid').value = p.id;
        row.querySelector('.f-sku').value = p.sku;
        row.querySelector('.f-color').value = p.color;
        row.querySelector('.f-spec').value = p.spec;
        row.querySelector('.f-size').value = p.size;
        row.querySelector('.price').value = p.price;
        row.dataset.stock = p.stock;
        input.value = pLabel(p);
        list.style.display = 'none';
        validateRow(row);
        recalc();
    };

    const render = () => {
        const q = input.value.trim().toLowerCase();
        matches = (q === '' ? PRODUCTS : PRODUCTS.filter(p =>
            (p.sku + ' ' + p.color + ' ' + p.spec).toLowerCase().includes(q)
        )).slice(0, 40);
        hi = -1;
        if (!matches.length) { list.style.display = 'none'; return; }
        list.innerHTML = matches.map((p, i) => {
            const cls = p.stock <= 0 ? 'st-out' : (p.stock <= p.min ? 'st-low' : '');
            return `<div class="combo-item" data-i="${i}">${p.sku} · ${p.color} · ${p.spec} <span class="${cls}">(${AVAIL_LBL} ${p.stock})</span></div>`;
        }).join('');
        list.style.display = 'block';
    };

    input.addEventListener('focus', render);
    input.addEventListener('input', render);
    input.addEventListener('blur', () => setTimeout(() => list.style.display = 'none', 150));
    input.addEventListener('keydown', e => {
        if (list.style.display === 'none') return;
        const items = list.querySelectorAll('.combo-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, items.length - 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); }
        else if (e.key === 'Enter') { e.preventDefault(); if (matches[hi >= 0 ? hi : 0]) pick(matches[hi >= 0 ? hi : 0]); return; }
        else if (e.key === 'Escape') { list.style.display = 'none'; return; }
        items.forEach((el, i) => el.classList.toggle('active', i === hi));
        if (items[hi]) items[hi].scrollIntoView({ block: 'nearest' });
    });
    list.addEventListener('mousedown', e => {
        const it = e.target.closest('.combo-item');
        if (it) pick(matches[+it.dataset.i]);
    });

    row.querySelector('.remove-row').addEventListener('click', () => {
        if (document.querySelectorAll('.item-row').length > 1) row.remove();
        recalc();
    });
    row.querySelector('.price').addEventListener('input', recalc);
    row.querySelector('.qty').addEventListener('input', () => { validateRow(row); recalc(); });
}

function addRow() {
    const tpl = document.getElementById('row-tpl').content.cloneNode(true);
    const tbody = document.querySelector('#items-table tbody');
    tbody.appendChild(tpl);
    bindRow(tbody.lastElementChild);
}
document.getElementById('add-row').addEventListener('click', addRow);
document.getElementById('ship').addEventListener('input', recalc);
document.getElementById('order-form').addEventListener('submit', e => {
    let ok = true;
    document.querySelectorAll('.item-row').forEach(r => { if (!validateRow(r)) ok = false; });
    if (!ok) { e.preventDefault(); alert(BLOCK_MSG); }
});
addRow();
</script>
