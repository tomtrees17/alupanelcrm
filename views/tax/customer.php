<?php /** @var array $c */ /** @var array $invoices */ /** @var string $from */ /** @var string $to */ ?>
<div class="page-head">
    <div>
        <h1><?= t('fk_customer_title') ?> · <?= e((string) $c['name']) ?></h1>
        <p class="muted"><?= t('fk_customer_sub') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('tax.index', $from !== '' ? ['from' => $from, 'to' => $to] : []) ?>"><?= t('fk_title') ?></a>
</div>
<div class="card" style="padding:16px;max-width:760px">
    <form method="post" action="<?= url('tax.customer_save') ?>">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
        <div class="form-row">
            <div class="form-group"><label class="form-label">NPWP (16 / 15)</label><input class="form-input" name="tax_npwp" inputmode="numeric" value="<?= e((string) $c['tax_npwp']) ?>"></div>
            <div class="form-group"><label class="form-label">NIK (16)</label><input class="form-input" name="tax_nik" inputmode="numeric" value="<?= e((string) $c['tax_nik']) ?>"></div>
        </div>
        <div class="form-group"><label class="form-label"><?= t('fk_tax_name') ?></label><input class="form-input" name="tax_name" value="<?= e((string) $c['tax_name']) ?>" placeholder="<?= e((string) ($c['company'] ?: $c['name'])) ?>"></div>
        <div class="form-group"><label class="form-label"><?= t('fk_tax_address') ?></label><textarea class="form-input" name="tax_address" rows="2"><?= e((string) $c['tax_address']) ?></textarea></div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">NITKU (22) · <?= t('fk_optional') ?></label><input class="form-input" name="tax_nitku" inputmode="numeric" value="<?= e((string) $c['tax_nitku']) ?>"></div>
            <div class="form-group"><label class="form-label">Email · <?= t('fk_optional') ?></label><input class="form-input" name="tax_email" type="email" value="<?= e((string) $c['tax_email']) ?>"></div>
        </div>
        <p class="muted small"><?= t('fk_customer_hint') ?></p>
        <button class="btn btn-primary" type="submit"><?= t('btn_save') ?></button>
        <?php if ($c['tax_updated_by']): ?><span class="muted small" style="margin-left:8px"><?= e((string) $c['tax_updated_by']) ?> · <?= e((string) $c['tax_updated_at']) ?></span><?php endif; ?>
    </form>
</div>
<?php if ($invoices): ?>
<div class="card" style="max-width:760px">
    <div class="card-header"><span class="card-title"><?= t('fk_recent_invoices') ?></span></div>
    <div class="table-wrap"><table>
        <tbody><?php foreach ($invoices as $i): ?><tr><td><?= e($i['invoice_no']) ?></td><td><?= e(Hr::dmy($i['invoice_date'])) ?></td><td class="small"><?= e((string) $i['address']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<?php endif; ?>
