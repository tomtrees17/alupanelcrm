<?php /** @var array $invoice */ /** @var array $payment */ ?>
<div class="page-head">
    <h1><?= t('reverse_payment') ?></h1>
</div>

<div class="card">
    <div class="card-body">
        <p class="muted" style="margin-bottom:14px"><?= t('reverse_hint') ?></p>

        <dl class="detail">
            <div><dt><?= t('invoice') ?></dt><dd><?= e($invoice['invoice_no']) ?></dd></div>
            <div><dt><?= t('th_customer') ?></dt><dd><?= e($invoice['customer']) ?></dd></div>
            <div><dt><?= t('th_date') ?></dt><dd><?= e($payment['pay_date']) ?></dd></div>
            <div><dt><?= t('th_amount') ?></dt><dd><strong><?= idr($payment['amount']) ?></strong></dd></div>
            <div><dt><?= t('th_method') ?></dt><dd><?= e($payment['method']) ?: '—' ?></dd></div>
            <div><dt><?= t('th_receipt') ?></dt><dd><code><?= e($payment['receipt_no']) ?></code></dd></div>
            <?php if (!empty($payment['created_by'])): ?>
                <div><dt><?= t('reverse_registered_by') ?></dt><dd><?= e($payment['created_by']) ?></dd></div>
            <?php endif; ?>
        </dl>

        <div class="notes" style="margin-top:14px">
            <?= sprintf(
                t('reverse_effect'),
                idr($invoice['amount_paid']),
                idr((float) $invoice['amount_paid'] - (float) $payment['amount'])
            ) ?>
        </div>

        <form method="post" action="<?= url('finance.reverse') ?>" style="margin-top:16px">
            <?= Csrf::field() ?>
            <input type="hidden" name="pid" value="<?= (int) $payment['id'] ?>">
            <div class="form-group">
                <label class="form-label"><?= t('reverse_reason') ?> *</label>
                <input class="form-input" name="reason" required maxlength="200" placeholder="<?= t('reverse_reason_ph') ?>">
            </div>
            <div class="form-actions">
                <a class="btn btn-ghost" href="<?= url('finance.show', ['id' => $invoice['id']]) ?>"><?= t('btn_cancel') ?></a>
                <button class="btn btn-danger" type="submit"><?= t('reverse_confirm') ?></button>
            </div>
        </form>
    </div>
</div>
