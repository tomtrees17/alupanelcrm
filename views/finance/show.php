<?php /** @var array $invoice */ /** @var array $items */ /** @var array $payments */ /** @var ?string $voidBlock */
$remaining = $invoice['total'] - $invoice['amount_paid'];
$isVoid = invoice_is_void($invoice);
$voidBlock = $voidBlock ?? null;
?>
<div class="page-head">
    <h1><?= t('invoice') ?> <?= e($invoice['invoice_no']) ?>
        <?php if ($isVoid): ?>
            <span class="tag tag-red"><?= t('void_tag') ?></span>
        <?php else: ?>
            <span class="tag <?= invoice_status_class($invoice['payment_status']) ?>"><?= e(invoice_status_label($invoice['payment_status'])) ?></span>
        <?php endif; ?>
    </h1>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= url('finance.print', ['id' => $invoice['id']]) ?>" target="_blank"><?= t('btn_print') ?> · Invoice</a>
        <?php if (can_word_export()): ?><a class="btn btn-ghost" href="<?= url('finance.word', ['id' => $invoice['id']]) ?>"><?= t('btn_word') ?></a><?php endif; ?>
        <?php if (!$isVoid && $voidBlock === null): ?>
            <a class="btn btn-ghost" href="<?= url('finance.void_form', ['id' => $invoice['id']]) ?>"><?= t('void_btn') ?></a>
        <?php elseif (!$isVoid): ?>
            <span class="btn btn-ghost btn-disabled" title="<?= e($voidBlock) ?>"><?= t('void_btn') ?></span>
        <?php elseif ($auth->isAdmin()): ?>
            <form method="post" action="<?= url('finance.unvoid') ?>" style="display:inline" onsubmit="return confirm('<?= t('unvoid_btn') ?>?')">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                <button class="btn btn-ghost" type="submit"><?= t('unvoid_btn') ?></button>
            </form>
        <?php endif; ?>
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
        <form method="post" action="<?= url('finance.update_no') ?>" class="no-print" style="margin-top:14px;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:200px">
                <label class="form-label"><?= t('edit_inv_no') ?></label>
                <input class="form-input" name="invoice_no" value="<?= e($invoice['invoice_no']) ?>" required>
            </div>
            <button class="btn btn-ghost" type="submit" onclick="return confirm('?')"><?= t('btn_save') ?></button>
        </form>
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

<?php if (!$isVoid && $voidBlock !== null): ?>
    <div class="alert alert-info no-print"><?= e($voidBlock) ?></div>
<?php endif; ?>

<?php if ($isVoid): ?>
    <div class="alert alert-error">
        <strong><?= t('void_tag') ?></strong> ·
        <?= sprintf(t('voided_by_on'), e((string) $invoice['voided_by']), e((string) $invoice['voided_at'])) ?>
        <?php if (!empty($invoice['void_reason'])): ?> · <?= e($invoice['void_reason']) ?><?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('payment_records') ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('th_date') ?></th><th><?= t('th_method') ?></th><th><?= t('th_receipt') ?></th><th class="right"><?= t('th_amount') ?></th><th class="right no-print"></th></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="5" class="empty"><?= t('no_payment') ?></td></tr><?php endif; ?>
            <?php
            // A payment already offset by a reversal row cannot be reversed twice.
            $reversedIds = [];
            foreach ($payments as $p) {
                if ($p['reversal_of'] !== null) {
                    $reversedIds[(int) $p['reversal_of']] = true;
                }
            }
            ?>
            <?php foreach ($payments as $p): ?>
                <?php
                $isReversal = $p['reversal_of'] !== null;
                $isReversed = isset($reversedIds[(int) $p['id']]);
                ?>
                <tr<?= $isReversal || $isReversed ? ' class="row-void"' : '' ?>>
                    <td><?= e($p['pay_date']) ?></td>
                    <td>
                        <?= e($p['method']) ?: '—' ?>
                        <?php if ($isReversal): ?>
                            <span class="tag tag-red"><?= t('reverse_tag') ?></span>
                        <?php elseif ($isReversed): ?>
                            <span class="tag tag-gray"><?= t('reversed_tag') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($p['created_by'])): ?><div class="muted small"><?= e($p['created_by']) ?></div><?php endif; ?>
                        <?php if ($isReversal && !empty($p['note'])): ?><div class="muted small"><?= e($p['note']) ?></div><?php endif; ?>
                    </td>
                    <td><code><?= e($p['receipt_no']) ?></code></td>
                    <td class="right"><?= idr($p['amount']) ?></td>
                    <td class="right no-print">
                        <?php if (!$isReversal && !$isReversed): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= url('finance.reverse_form', ['pid' => $p['id']]) ?>"><?= t('reverse_btn') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($remaining > 0 && !$isVoid): ?>
    <div class="card no-print">
        <div class="card-header"><span class="card-title"><?= t('record_payment') ?></span></div>
        <div class="card-body">
            <form method="post" action="<?= url('finance.pay') ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= t('th_amount') ?> (Rp) *</label><input class="form-input" type="number" name="amount" value="<?= (int) $remaining ?>" required></div>
                    <div class="form-group"><label class="form-label"><?= t('th_date') ?></label><input class="form-input" type="date" name="pay_date" value="<?= date('Y-m-d') ?>"></div>
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
