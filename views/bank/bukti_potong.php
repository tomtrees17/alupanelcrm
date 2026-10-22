<?php /** @var array $rows */ ?>
<?php $money = fn(float $v): string => number_format($v, 2, ',', '.'); ?>
<div class="page-head">
    <div>
        <h1><?= t('bank_bp_title') ?></h1>
        <p class="muted"><?= t('bank_bp_sub') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('bank.index') ?>"><?= t('bank_title') ?></a>
</div>
<div class="card">
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('bank_th_date') ?></th><th><?= t('bank_th_code') ?></th><th><?= t('bank_th_invoices') ?></th><th><?= t('th_customer') ?></th><th class="right"><?= t('bank_th_diff') ?></th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= t('bank_no_rows') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $a): ?>
            <tr>
                <td class="nowrap"><?= e(Hr::dmy($a['trx_date'])) ?></td>
                <td class="nowrap"><a href="<?= url('bank.show', ['id' => $a['transaction_id']]) ?>"><?= e($a['trx_code']) ?></a></td>
                <td><?= e($a['invoice_no']) ?></td>
                <td><?= e((string) $a['customer']) ?></td>
                <td class="right num"><?= $money(-(float) $a['diff_amount']) ?></td>
                <td><?php if ($a['bukti_potong_at']): ?>✓ <?= e(substr((string) $a['bukti_potong_at'], 0, 10)) ?><?php else: ?>
                    <form method="post" action="<?= url('bank.bp') ?>" class="inline-form">
                        <?= Csrf::field() ?><input type="hidden" name="alloc" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="bp">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= t('bank_btn_bp') ?></button>
                    </form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
