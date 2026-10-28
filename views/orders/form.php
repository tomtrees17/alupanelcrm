<?php
/** @var array $customers */ /** @var array $products */ /** @var ?array $order */ /** @var array $items */
$isEdit = !empty($order);
$ov = fn(string $k, $d = '') => e($order[$k] ?? $d);
$sel = fn(string $k, string $val) => (($order[$k] ?? '') === $val ? 'selected' : '');
$prodJson = json_encode(array_map(function ($p) {
    $avail = max(0, (int) $p['stock'] - (int) $p['reserved']);
    return [
        'id' => (int) $p['id'], 'sku' => $p['sku'], 'color' => $p['color_en'], 'zh' => (string) ($p['color_zh'] ?? ''),
        'spec' => $p['spec'], 'size' => $p['size'], 'price' => (float) $p['price'], 'stock' => $avail, 'min' => (int) $p['min_stock'],
    ];
}, $products), JSON_UNESCAPED_UNICODE);
$custJson = json_encode(array_map(fn($c) => [
    'id' => (int) $c['id'], 'name' => $c['name'], 'company' => $c['company'], 'phone' => $c['phone'],
], $customers), JSON_UNESCAPED_UNICODE);
$itemsJson = json_encode(array_map(fn($it) => [
    'product_id' => (int) ($it['product_id'] ?? 0), 'sku' => $it['sku'], 'color' => $it['color'],
    'spec' => $it['spec'], 'size' => $it['size'], 'qty' => (float) $it['qty'], 'price' => (float) $it['price'],
    'price_excl' => isset($it['price_excl']) && $it['price_excl'] !== null ? (float) $it['price_excl'] : null,
], $items ?? []), JSON_UNESCAPED_UNICODE);
$basis = ($order['price_basis'] ?? 'incl') === 'excl' ? 'excl' : 'incl';
?>
<div class="page-head">
    <h1><?= $isEdit ? t('btn_edit') . ' ' . e($order['order_no']) : t('btn_add_order') ?></h1>
    <a class="btn btn-ghost" href="<?= url($isEdit ? 'orders.show' : 'orders.index', $isEdit ? ['id' => $order['id']] : []) ?>"><?= t('btn_back') ?></a>
</div>

<?php if (!$isEdit): ?>
<div class="card" id="parse-card" style="margin-bottom:12px">
    <div class="card-header"><span class="card-title"><?= t('parse_title') ?></span><span class="muted small"><?= t('parse_sub') ?></span></div>
    <div class="card-body">
        <textarea class="form-textarea" id="parse-text" rows="6" placeholder="<?= e(t('parse_ph')) ?>"></textarea>
        <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
            <button type="button" class="btn btn-primary" id="parse-btn"><?= t('parse_btn') ?></button>
            <span class="muted small" id="parse-status"></span>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="post" action="<?= url($isEdit ? 'orders.update' : 'orders.store') ?>" id="order-form">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><?php endif; ?>
    <div class="card"><div class="card-body">
        <?php if (!empty($canAssignSales)): ?>
        <div class="form-group"><label class="form-label"><?= t('assign_sales') ?></label>
            <input class="form-input" type="text" name="submitter" list="sales-list" autocomplete="off"
                   value="<?= e($order['submitter'] ?? '') ?>" placeholder="<?= t('assign_sales_ph') ?>">
            <datalist id="sales-list">
                <?php foreach (($salesStaff ?? []) as $sname): ?><option value="<?= e($sname) ?>"></option><?php endforeach; ?>
            </datalist>
        </div>
        <?php endif; ?>
        <?php $coLocked = $isEdit && !empty($order['co_no']); ?>
        <div class="form-group">
            <label class="form-label"><?= t('co_pick') ?></label>
            <?php if ($coLocked): ?>
                <input class="form-input" type="text" value="<?= e($order['co_no']) ?>" disabled>
            <?php else: ?>
                <select class="form-select" name="co_no">
                    <option value=""><?= $isEdit ? t('co_keep_no') : e(sprintf(t('co_new_no'), (string) ($nextNo ?? ''))) ?></option>
                    <?php foreach (($coAvailable ?? []) as $r): ?>
                        <option value="<?= e($r['co_no']) ?>"><?= e($r['co_no']) ?> · <?= e($r['sales_name']) ?> · <?= e($r['company'] ?: $r['client_name']) ?> · <?= e(substr((string) $r['issued_at'], 0, 10)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="muted" style="font-size:11px;margin-top:4px"><?= t('co_pick_hint') ?></div>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_customer') ?> *</label>
                <input class="form-input" type="text" name="customer_name" id="customer-name" list="cust-list" autocomplete="off" required value="<?= e($order['customer_name'] ?? '') ?>" placeholder="<?= t('cust_name_ph') ?>">
                <input type="hidden" name="customer_id" id="customer-id" value="<?= (int) ($order['customer_id'] ?? 0) ?>">
                <datalist id="cust-list"><?php foreach ($customers as $c): ?><option value="<?= e($c['name']) ?>"></option><?php endforeach; ?></datalist>
                <label class="muted" style="font-size:11px;display:flex;gap:5px;align-items:center;margin-top:5px"><input type="checkbox" name="save_customer" value="1" checked style="width:auto"> <?= t('save_new_customer') ?></label>
            </div>
            <div class="form-group"><label class="form-label"><?= t('th_company') ?></label><input class="form-input" name="company" id="f-company" value="<?= $ov('company') ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('th_phone') ?></label><input class="form-input" name="phone" id="f-phone" value="<?= $ov('phone') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('f_client_type') ?></label>
                <select class="form-select" name="client_type"><?php foreach (client_types() as $ct): ?><option <?= $sel('client_type', $ct) ?>><?= $ct ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('f_address') ?></label><input class="form-input" name="address" id="f-address" value="<?= $ov('address') ?>"></div>
        <div class="form-row-3">
            <div class="form-group"><label class="form-label"><?= t('f_delivery_service') ?></label>
                <select class="form-select" name="delivery_service"><?php foreach (delivery_services() as $d): ?><option <?= $sel('delivery_service', $d) ?>><?= $d ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_delivery_date') ?></label><input class="form-input" type="date" name="delivery_date" value="<?= $ov('delivery_date') ?>"></div>
            <div class="form-group"><label class="form-label"><?= t('f_shipping') ?></label><input class="form-input" type="number" step="any" min="0" inputmode="decimal" name="shipping_cost" id="ship" value="<?= $isEdit ? e($order['shipping_cost']) : '0' ?>"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('delivery_addr') ?></label><input class="form-input" name="delivery_address" value="<?= $ov('delivery_address') ?>"></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label"><?= t('f_payment_term') ?></label>
                <select class="form-select" name="payment_term" id="pterm">
                    <option value="CBD" <?= $sel('payment_term', 'CBD') ?>>CBD</option>
                    <option value="COD" <?= $sel('payment_term', 'COD') ?>>COD</option>
                    <option value="custom" <?= $sel('payment_term', 'custom') ?>>Net (custom)</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label"><?= t('f_custom_days') ?></label><input class="form-input" type="number" name="custom_days" value="<?= $isEdit ? e($order['custom_days']) : '0' ?>"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('th_note') ?></label><textarea class="form-textarea" name="note"><?= $ov('note') ?></textarea></div>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('product_items') ?></span><button type="button" class="btn btn-sm btn-ghost" id="add-row"><?= t('btn_add_row') ?></button></div>
        <div class="price-basis">
            <span class="form-label"><?= t('price_basis') ?></span>
            <label><input type="radio" name="price_basis" value="incl" <?= $basis === 'incl' ? 'checked' : '' ?>> <?= t('price_basis_incl') ?></label>
            <label><input type="radio" name="price_basis" value="excl" <?= $basis === 'excl' ? 'checked' : '' ?>> <?= t('price_basis_excl') ?></label>
            <span class="muted small" id="basis-hint"><?= t($basis === 'excl' ? 'price_basis_excl_hint' : 'price_basis_incl_hint') ?></span>
        </div>
        <div class="table-wrap"><table id="items-table">
            <thead><tr><th style="width:30%"><?= t('th_product') ?></th><th class="right"><?= t('th_qty') ?></th><th class="right"><?= t('th_price_incl') ?></th><th class="right"><?= t('th_price_excl') ?></th><th class="right"><?= t('th_subtotal') ?></th><th></th></tr></thead>
            <tbody></tbody>
            <tfoot>
                <tr class="total-row"><td colspan="4" class="right"><?= t('total') ?>（<?= t('incl_tax') ?>）</td><td class="right" id="t-total">Rp 0</td><td></td></tr>
                <tr><td colspan="4" class="right" style="color:var(--text3)"><?= t('incl_ppn') ?></td><td class="right" id="t-ppn" style="color:var(--text3)">Rp 0</td><td></td></tr>
                <tr><td colspan="4" class="right" style="color:var(--text3)"><?= t('pretax_subtotal') ?></td><td class="right" id="t-subtotal" style="color:var(--text3)">Rp 0</td><td></td></tr>
            </tfoot>
        </table></div>
    </div>

    <div class="form-actions order-actions">
        <span class="muted small" id="autosave-state"></span>
        <button class="btn btn-ghost" type="submit" name="do" value="draft" formnovalidate title="<?= e(t('order_draft_hint')) ?>"><?= t('btn_save_draft') ?></button>
        <button class="btn btn-primary" type="submit" name="do" value="submit"><?= t('btn_save_order') ?></button>
    </div>
</form>
<div class="alert alert-info order-restore" id="autosave-banner" hidden>
    <span id="autosave-text"></span>
    <button type="button" class="btn btn-primary btn-sm" id="autosave-restore"><?= t('order_autosave_restore') ?></button>
    <button type="button" class="btn btn-ghost btn-sm" id="autosave-discard"><?= t('order_autosave_discard') ?></button>
</div>

<template id="row-tpl">
    <tr class="item-row">
        <td style="position:relative">
            <input type="text" class="form-input product-search" placeholder="<?= t('search_product') ?> …" autocomplete="off">
            <div class="combo-list"></div>
            <input type="hidden" name="product_id[]" class="f-pid">
            <input type="hidden" name="sku[]" class="f-sku"><input type="hidden" name="color[]" class="f-color">
            <input type="hidden" name="spec[]" class="f-spec"><input type="hidden" name="size[]" class="f-size">
        </td>
        <td><input class="form-input qty right" type="number" step="1" min="0" inputmode="numeric" name="qty[]" value="1" style="width:90px"><div class="stock-warn"></div></td>
        <td><input class="form-input price right" type="number" step="any" min="0" inputmode="decimal" name="price[]" value="0" style="width:120px"></td>
        <td><input class="form-input price-excl right" type="number" step="0.01" min="0" inputmode="decimal" name="price_excl[]" value="0" style="width:120px"></td>
        <td class="right line-total">Rp 0</td>
        <td class="right"><button type="button" class="btn btn-ghost btn-sm remove-row" style="color:var(--danger)">×</button></td>
    </tr>
</template>

<script>
const PRODUCTS = <?= $prodJson ?>;
const CUSTOMERS = <?= $custJson ?>;
const EXISTING = <?= $itemsJson ?>;
const STOCK_LBL = <?= json_encode(t('available'), JSON_UNESCAPED_UNICODE) ?>;
const AVAIL_LBL = <?= json_encode(t('available'), JSON_UNESCAPED_UNICODE) ?>;
const BLOCK_MSG = <?= json_encode(t('stock_block_submit'), JSON_UNESCAPED_UNICODE) ?>;
const fmt = n => 'Rp ' + Number(n||0).toLocaleString('id-ID', {maximumFractionDigits: 2});
const BASIS_HINT = <?= json_encode(['incl' => t('price_basis_incl_hint'), 'excl' => t('price_basis_excl_hint')], JSON_UNESCAPED_UNICODE) ?>;
// Same rules as the invoice (invoice_amounts_coretax): pre-tax = inclusive ÷ 1,11
// cut to 2 decimals; inclusive = pre-tax × 1,11 rounded to the sen. Work in sen.
const toSen = v => Math.round((parseFloat(v) || 0) * 100);
const exclFromIncl = incl => Math.floor(toSen(incl) * 100 / 111) / 100;
const inclFromExcl = excl => Math.round(toSen(excl) * 111 / 100) / 100;
const basis = () => (document.querySelector('input[name="price_basis"]:checked') || {}).value === 'excl' ? 'excl' : 'incl';
function syncRow(row, from) {
    const incl = row.querySelector('.price'), excl = row.querySelector('.price-excl');
    if (from === 'excl') { incl.value = inclFromExcl(excl.value); } else { excl.value = exclFromIncl(incl.value); }
}
function applyBasis() {
    const b = basis();
    document.querySelectorAll('.item-row').forEach(r => {
        // The quoted price is typed; the other is derived and read-only.
        r.querySelector('.price').readOnly = b === 'excl';
        r.querySelector('.price-excl').readOnly = b === 'incl';
        r.querySelector('.price').classList.toggle('derived', b === 'excl');
        r.querySelector('.price-excl').classList.toggle('derived', b === 'incl');
    });
    document.getElementById('basis-hint').textContent = BASIS_HINT[b];
}

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

const custInput = document.getElementById('customer-name');
const custId = document.getElementById('customer-id');
custInput.addEventListener('input', () => {
    const c = CUSTOMERS.find(x => x.name === custInput.value.trim());
    custId.value = c ? c.id : '';
    if (c) {
        document.getElementById('f-company').value = c.company || '';
        document.getElementById('f-phone').value = c.phone || '';
    }
});

function recalc() {
    const ship = parseFloat(document.getElementById('ship').value) || 0;
    if (basis() === 'excl') {
        // Pre-tax quote: totals exactly as the invoice / Coretax will compute them.
        let subSen = Math.floor(toSen(ship) * 100 / 111);
        document.querySelectorAll('.item-row').forEach(r => {
            const qty = Math.round(parseFloat(r.querySelector('.qty').value) || 0);
            const lineSen = qty * toSen(r.querySelector('.price-excl').value);
            subSen += lineSen;
            r.querySelector('.line-total').textContent = fmt(qty * inclFromExcl(r.querySelector('.price-excl').value));
        });
        const dppSen = Math.floor(subSen * 11 / 12);
        const ppnSen = Math.floor(dppSen * 12 / 100);
        document.getElementById('t-total').textContent = fmt((subSen + ppnSen) / 100);
        document.getElementById('t-ppn').textContent = fmt(ppnSen / 100);
        document.getElementById('t-subtotal').textContent = fmt(subSen / 100);
        if (window.orderAutosave) window.orderAutosave();
        return;
    }
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(r => {
        const qty = parseFloat(r.querySelector('.qty').value) || 0;
        const price = parseFloat(r.querySelector('.price').value) || 0;
        const line = qty * price;
        subtotal += line;
        r.querySelector('.line-total').textContent = fmt(line);
    });
    const gross = subtotal + ship;
    const ppn = Math.round(gross * 11 / 111);
    document.getElementById('t-total').textContent = fmt(gross);
    document.getElementById('t-ppn').textContent = fmt(ppn);
    document.getElementById('t-subtotal').textContent = fmt(gross - ppn);
    if (window.orderAutosave) window.orderAutosave();
}

// Full designation: SKU · 中文名 / English name · spec
const pName = p => (p.zh && p.zh !== p.color) ? `${p.zh} / ${p.color}` : p.color;
const pLabel = p => `${p.sku} · ${pName(p)} · ${p.spec} (${AVAIL_LBL} ${p.stock})`;

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
        row.querySelector('.price').value = p.price;    // price list is tax-inclusive
        syncRow(row, 'incl');
        row.dataset.stock = p.stock;
        input.value = pLabel(p);
        list.style.display = 'none';
        validateRow(row);
        recalc();
    };

    const render = () => {
        const q = input.value.trim().toLowerCase();
        matches = (q === '' ? PRODUCTS : PRODUCTS.filter(p =>
            (p.sku + ' ' + (p.zh || '') + ' ' + p.color + ' ' + p.spec + ' ' + p.size).toLowerCase().includes(q)
        )).slice(0, 40);
        hi = -1;
        if (!matches.length) { list.style.display = 'none'; return; }
        list.innerHTML = matches.map((p, i) => {
            const cls = p.stock <= 0 ? 'st-out' : (p.stock <= p.min ? 'st-low' : '');
            return `<div class="combo-item" data-i="${i}">${p.sku} · ${pName(p)} · ${p.spec} <span class="${cls}">(${AVAIL_LBL} ${p.stock})</span></div>`;
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
    row.querySelector('.price').addEventListener('input', () => { syncRow(row, 'incl'); recalc(); });
    row.querySelector('.price-excl').addEventListener('input', () => { syncRow(row, 'excl'); recalc(); });
    row.querySelector('.qty').addEventListener('input', () => { validateRow(row); recalc(); });
}

function addRow(item) {
    const tpl = document.getElementById('row-tpl').content.cloneNode(true);
    const tbody = document.querySelector('#items-table tbody');
    tbody.appendChild(tpl);
    const row = tbody.lastElementChild;
    bindRow(row);
    if (item) {
        row.querySelector('.f-pid').value = item.product_id || '';
        row.querySelector('.f-sku').value = item.sku;
        row.querySelector('.f-color').value = item.color;
        row.querySelector('.f-spec').value = item.spec;
        row.querySelector('.f-size').value = item.size;
        row.querySelector('.qty').value = item.qty;
        row.querySelector('.price').value = item.price;
        if (item.price_excl !== null && item.price_excl !== undefined) {
            row.querySelector('.price-excl').value = item.price_excl;
        } else {
            syncRow(row, 'incl');
        }
        row.querySelector('.product-search').value = `${item.sku} · ${item.color} · ${item.spec}`;
        const p = PRODUCTS.find(x => x.id == item.product_id);
        if (p) row.dataset.stock = p.stock;
        validateRow(row);
    }
    applyBasis();
    recalc();
}
document.getElementById('add-row').addEventListener('click', () => addRow());
document.querySelectorAll('input[name="price_basis"]').forEach(el => el.addEventListener('change', () => { applyBasis(); recalc(); }));
document.getElementById('ship').addEventListener('input', recalc);
document.getElementById('order-form').addEventListener('submit', e => {
    if (e.submitter && e.submitter.value === 'draft') return; // drafts skip the stock check
    let ok = true;
    document.querySelectorAll('.item-row').forEach(r => { if (!validateRow(r)) ok = false; });
    if (!ok) { e.preventDefault(); alert(BLOCK_MSG); }
});

if (EXISTING.length) { EXISTING.forEach(it => addRow(it)); } else { addRow(); }

// ── Autosave in this browser (owner 2026-10-28): nothing lost if the page closes or the
// connection drops; after a refused save the form comes back with what was typed.
(function () {
    const KEY = <?= json_encode('order-form:' . ($isEdit ? (string) (int) $order['id'] : 'new')) ?>;
    const form = document.getElementById('order-form');
    const L = <?= json_encode(['found' => t('order_autosave_found'), 'restored' => t('order_autosave_restored'), 'saved' => t('order_autosave_saved')], JSON_UNESCAPED_UNICODE) ?>;
    const FIELDS = ['submitter', 'co_no', 'customer_name', 'customer_id', 'company', 'phone', 'client_type', 'address', 'delivery_service',
                    'delivery_date', 'shipping_cost', 'delivery_address', 'payment_term', 'custom_days', 'note'];
    const store = {
        get() { try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } },
        set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); return true; } catch (e) { return false; } },
        drop() { try { localStorage.removeItem(KEY); } catch (e) {} },
    };
    const snapshot = () => {
        const v = { at: Date.now(), fields: {}, basis: basis(), save_customer: !!(form.querySelector('[name="save_customer"]') || {}).checked, rows: [] };
        FIELDS.forEach(n => { const el = form.querySelector(`[name="${n}"]`); if (el) v.fields[n] = el.value; });
        document.querySelectorAll('#items-table tbody .item-row').forEach(r => v.rows.push({
            product_id: r.querySelector('.f-pid').value, sku: r.querySelector('.f-sku').value, color: r.querySelector('.f-color').value,
            spec: r.querySelector('.f-spec').value, size: r.querySelector('.f-size').value, qty: r.querySelector('.qty').value,
            price: r.querySelector('.price').value, price_excl: r.querySelector('.price-excl').value, label: r.querySelector('.product-search').value,
        }));
        return v;
    };
    const empty = v => !v.fields.customer_name && !v.fields.note && !v.rows.some(r => r.sku || r.label);
    let ready = false, timer = null;
    const state = document.getElementById('autosave-state');
    window.orderAutosave = () => {
        if (!ready) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            const v = snapshot();
            if (empty(v)) { store.drop(); return; }
            if (store.set(v)) state.textContent = L.saved.replace('%s', new Date(v.at).toLocaleTimeString().slice(0, 5));
        }, 600);
    };
    const restore = v => {
        Object.entries(v.fields || {}).forEach(([n, val]) => { const el = form.querySelector(`[name="${n}"]`); if (el) el.value = val; });
        const sc = form.querySelector('[name="save_customer"]'); if (sc) sc.checked = !!v.save_customer;
        const b = form.querySelector(`input[name="price_basis"][value="${v.basis === 'excl' ? 'excl' : 'incl'}"]`); if (b) b.checked = true;
        document.querySelectorAll('#items-table tbody .item-row').forEach(r => r.remove());
        (v.rows || []).forEach(it => {
            if (it.sku) {
                addRow({ product_id: it.product_id, sku: it.sku, color: it.color, spec: it.spec, size: it.size, qty: it.qty, price: it.price,
                         price_excl: v.basis === 'excl' ? it.price_excl : null });
            } else {
                addRow();
                const row = document.querySelector('#items-table tbody').lastElementChild;
                row.querySelector('.product-search').value = it.label || '';
                row.querySelector('.qty').value = it.qty || 1;
                row.querySelector('.price').value = it.price || 0;
                syncRow(row, 'incl');
            }
        });
        if (!(v.rows || []).length) addRow();
        applyBasis();
        recalc();
    };
    const saved = store.get();
    if (saved && !empty(saved)) {
        if (saved.submitted) {
            // The page came back after a save the server refused: put the typing back.
            if (document.querySelector('.alert-error')) { restore(saved); state.textContent = L.restored; } else { store.drop(); }
        } else {
            const banner = document.getElementById('autosave-banner');
            document.getElementById('autosave-text').textContent = L.found.replace('%s', new Date(saved.at).toLocaleString());
            form.parentNode.insertBefore(banner, form);
            banner.hidden = false;
            document.getElementById('autosave-restore').addEventListener('click', () => { restore(saved); banner.hidden = true; });
            document.getElementById('autosave-discard').addEventListener('click', () => { store.drop(); banner.hidden = true; });
        }
    }
    ready = true;
    form.addEventListener('input', window.orderAutosave);
    form.addEventListener('change', window.orderAutosave);
    form.addEventListener('submit', () => { const v = snapshot(); v.submitted = true; store.set(v); });
})();

// ── 粘贴识别: fill the form from the pasted sales message ──
(function () {
    const btn = document.getElementById('parse-btn');
    if (!btn) return;
    const txt = document.getElementById('parse-text');
    const status = document.getElementById('parse-status');
    const L = <?= json_encode(['busy' => t('parse_busy'), 'done' => t('parse_done'), 'newc' => t('parse_new_cust'), 'unm' => t('parse_item_unmatched'), 'fail' => t('parse_fail')], JSON_UNESCAPED_UNICODE) ?>;
    const form = document.getElementById('order-form');
    const mark = (el, on) => { if (el) el.classList.toggle('ai-check', !!on); };
    const setVal = (name, v, unsure) => {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el) return;
        if (el.tagName === 'SELECT') {
            if (v !== '' && [...el.options].some(o => o.value === v || o.text === v)) el.value = v;
        } else if (v !== '' && v !== null && v !== undefined) {
            el.value = v;
        }
        // Yellow = look at this: the model was unsure, or a field the order cannot do without is still empty.
        const must = ['delivery_date', 'payment_term', 'delivery_service'];
        mark(el, unsure || ((v === '' || v === null) && must.includes(name)));
    };
    btn.addEventListener('click', async () => {
        const text = txt.value.trim();
        if (!text) { txt.focus(); return; }
        btn.disabled = true; status.textContent = L.busy;
        try {
            const fd = new FormData();
            fd.append('_csrf', form.querySelector('[name="_csrf"]').value);
            fd.append('text', text);
            const res = await fetch(<?= json_encode(url('orders.parse')) ?>, { method: 'POST', body: fd, credentials: 'same-origin' });
            const r = await res.json();
            if (!r.ok) { status.textContent = L.fail + (r.error || ''); return; }
            const d = r.data;
            const unsure = new Set(d.uncertain || []);
            form.querySelectorAll('.ai-check').forEach(el => el.classList.remove('ai-check'));
            const sales = form.querySelector('[name="submitter"]');
            if (sales && d.sales_name) { sales.value = d.sales_name; mark(sales, unsure.has('sales_name')); }
            const cn = document.getElementById('customer-name');
            cn.value = d.customer_name || '';
            document.getElementById('customer-id').value = d.customer_id || 0;
            mark(cn, !d.customer_name || d.new_customer);
            setVal('company', d.company, unsure.has('company'));
            setVal('phone', d.phone, unsure.has('phone'));
            setVal('address', d.address, unsure.has('address'));
            setVal('delivery_address', d.delivery_address, unsure.has('delivery_address'));
            setVal('delivery_service', d.delivery_service, unsure.has('delivery_service'));
            setVal('delivery_date', d.delivery_date, unsure.has('delivery_date'));
            setVal('payment_term', d.payment_term, unsure.has('payment_term'));
            if (d.payment_term === 'custom') setVal('custom_days', d.custom_days, false);
            setVal('note', d.note, false);
            document.querySelectorAll('#items-table tbody .item-row').forEach(r => r.remove());
            (d.items || []).forEach((it, i) => {
                if (it.matched) {
                    addRow({ product_id: it.product_id, sku: it.sku, color: it.color, spec: it.spec, size: it.size, qty: it.qty, price: it.price, price_excl: null });
                } else {
                    addRow();
                    const row = document.querySelector('#items-table tbody').lastElementChild;
                    row.querySelector('.product-search').value = (it.sku + ' ' + it.spec).trim() || it.raw;
                    row.querySelector('.qty').value = it.qty || 1;
                    if (it.price) { row.querySelector('.price').value = it.price; syncRow(row, 'incl'); }
                    row.querySelector('.product-search').title = L.unm;
                }
                const row = document.querySelector('#items-table tbody').lastElementChild;
                mark(row.querySelector('.product-search'), !it.matched || unsure.has('items.' + i) || unsure.has('items.' + i + '.spec'));
            });
            if (!(d.items || []).length) addRow();
            recalc();
            status.textContent = L.done + (d.new_customer ? ' · ' + L.newc : '');
            const first = form.querySelector('.ai-check');
            if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } catch (e) {
            status.textContent = L.fail + e;
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
