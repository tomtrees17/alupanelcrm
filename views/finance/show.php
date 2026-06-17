<?php /** @var array $invoice */ /** @var array $items */ /** @var array $payments */
$remaining = $invoice['total'] - $invoice['amount_paid'];
?>
<div class="page-head">
    <h1><?= t('invoice') ?> <?= e($invoice['invoice_no']) ?> <span class="tag <?= invoice_status_class($invoice['payment_status']) ?>"><?= e(invoice_status_label($invoice['payment_status'])) ?></span></h1>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= url('finance.print', ['id' => $invoice['id']]) ?>" target="_blank"><?= t('btn_print') ?> · Invoice</a>
        <a class="btn btn-ghost" href="<?= url('finance.index') ?>"><?= t('btn_back') ?></a>
    </div>
</div>

<div class="grid-2">
    <div class="card"><div class="card-body">
        <dl class="detail">
            <div><dt><?= t('th_customer') ?></dt><dd><?= e($invoice['customer']) ?></dd></div>
            <div><dt><?= t('bill_to') ?></dt><dd><?= e($invoice['bill_to_name']) ?: '—' ?></dd></div>
            <div><dt><?= t('npwp_label') ?></dt><dd><?= e($invoice['npwp']) ?: '—' ?></dd></div>
            <div><dt>No. PO</dt><dd><?= e($invoice['no_po']) ?: '—' ?></dd></div>
            <div><dt><?= t('related_order') ?></dt><dd><?= $invoice['order_id'] ? '<a href="' . url('orders.show', ['id' => $invoice['order_id']]) . '">' . t('view') . '</a>' : '—' ?></dd></div>
            <div><dt><?= t('delivery_order') ?></dt><dd><?= e($invoice['do_number']) ?: '—' ?></dd></div>
            <div><dt><?= t('th_invoice_date') ?></dt><dd><?= e($invoice['invoice_date']) ?></dd></div>
            <div><dt><?= t('th_due_date') ?></dt><dd><?= e($invoice['due_date']) ?></dd></div>
            <div><dt><?= t('tax_invoice_no') ?></dt><dd><?= e($invoice['tax_invoice_no']) ?: '—' ?></dd></div>
        </dl>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('details') ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('th_sku') ?></th><th><?= t('th_color_spec') ?></th><th class="right"><?= t('th_qty') ?></th><th class="right"><?= t('th_unit_price') ?></th><th class="right"><?= t('th_subtotal') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><code><?= e($it['sku']) ?></code></td><td><?= e($it['color']) ?> · <?= e($it['spec']) ?></td>
                    <td class="right"><?= (int) $it['qty'] ?></td><td class="right"><?= idr($it['price']) ?></td>
                    <td class="right"><?= idr($it['qty'] * $it['price']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4" class="right"><?= t('shipping') ?></td><td class="right"><?= idr($invoice['shipping_cost']) ?></td></tr>
                <tr><td colspan="4" class="right"><?= t('th_subtotal') ?></td><td class="right"><?= idr($invoice['subtotal']) ?></td></tr>
                <tr><td colspan="4" class="right">DPP</td><td class="right"><?= idr(round($invoice['subtotal'] * 11 / 12)) ?></td></tr>
                <tr><td colspan="4" class="right">VAT 12%</td><td class="right"><?= idr($invoice['ppn']) ?></td></tr>
                <tr class="total-row"><td colspan="4" class="right"><?= t('total') ?></td><td class="right"><?= idr($invoice['total']) ?></td></tr>
                <tr><td colspan="4" class="right"><?= t('th_paid') ?></td><td class="right"><?= idr($invoice['amount_paid']) ?></td></tr>
                <tr><td colspan="4" class="right" style="color:var(--danger)"><?= t('th_unpaid') ?></td><td class="right" style="color:var(--danger)"><?= idr($remaining) ?></td></tr>
            </tfoot>
        </table></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('payment_records') ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('th_date') ?></th><th><?= t('th_method') ?></th><th><?= t('th_receipt') ?></th><th class="right"><?= t('th_amount') ?></th></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="4" class="empty"><?= t('no_payment') ?></td></tr><?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr><td><?= e($p['pay_date']) ?></td><td><?= e($p['method']) ?: '—' ?></td><td><code><?= e($p['receipt_no']) ?></code></td><td class="right"><?= idr($p['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($remaining > 0): ?>
    <div class="card no-print">
        <div class="card-header"><span class="card-title"><?= t('record_payment') ?></span></div>
        <div class="card-body">
            <form method="post" action="<?= url('finance.pay') ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('th_amount') ?> (Rp) *</label><input class="form-input" type="number" name="amount" value="<?= (int) $remaining ?>" required></div>
                    <div class="form-group"><label class="form-label"><?= t('th_date') ?></label><input class="form-input" type="date" name="pay_date" value="2026-05-26"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('pay_method') ?></label><input class="form-input" name="method" placeholder="BCA Transfer"></div>
                    <div class="form-group"><label class="form-label"><?= t('th_receipt') ?></label><input class="form-input" name="receipt_no" placeholder="<?= t('auto_gen') ?>"></div>
                </div>
                <div class="form-group"><label class="form-label"><?= t('th_note') ?></label><input class="form-input" name="note"></div>
                <button class="btn btn-primary btn-block" type="submit"><?= t('record_payment') ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
