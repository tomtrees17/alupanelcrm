<?php /** @var array $invoice */ ?>
<div class="page-head">
    <h1><?= t('void_invoice') ?></h1>
</div>

<div class="card"><div class="card-body">
    <p class="muted" style="margin-bottom:14px"><?= t('void_hint') ?></p>

    <dl class="detail">
        <div><dt><?= t('th_invoice_no') ?></dt><dd><?= e($invoice['invoice_no']) ?></dd></div>
        <div><dt><?= t('th_customer') ?></dt><dd><?= e($invoice['customer']) ?></dd></div>
        <div><dt><?= t('th_invoice_date') ?></dt><dd><?= e($invoice['invoice_date']) ?></dd></div>
        <div><dt><?= t('th_total') ?></dt><dd><strong><?= idr($invoice['total']) ?></strong></dd></div>
        <?php if (!empty($invoice['do_number'])): ?>
            <div><dt><?= t('delivery_order') ?></dt><dd><?= e($invoice['do_number']) ?></dd></div>
        <?php endif; ?>
    </dl>

    <div class="notes" style="margin-top:14px"><?= t('void_effect') ?></div>

    <form method="post" action="<?= url('finance.void') ?>" style="margin-top:16px">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
        <div class="form-group">
            <label class="form-label"><?= t('void_reason') ?> *</label>
            <input class="form-input" name="reason" required maxlength="200" placeholder="<?= t('void_reason_ph') ?>">
        </div>
        <div class="form-actions">
            <a class="btn btn-ghost" href="<?= url('finance.show', ['id' => $invoice['id']]) ?>"><?= t('btn_cancel') ?></a>
            <button class="btn btn-danger" type="submit"><?= t('void_confirm') ?></button>
        </div>
    </form>
</div></div>
