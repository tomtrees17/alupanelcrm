<?php /** @var array $imports */ /** @var int $last */ ?>
<?php
$money = fn(?float $v): string => $v === null ? '—' : number_format($v, 2, ',', '.');
$okTag = fn(?int $v): string => $v === null ? '<span class="muted">' . t('bank_none') . '</span>'
    : ((int) $v === 1 ? '<span class="tag tag-green">' . t('bank_ok') . '</span>' : '<span class="tag tag-red">' . t('bank_bad') . '</span>');
?>
<div class="page-head">
    <div>
        <h1><?= t('bank_import') ?></h1>
        <p class="muted"><?= t('bank_import_hint') ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= url('bank.index') ?>"><?= t('bank_title') ?></a>
</div>

<div class="card" style="padding:16px">
    <form method="post" action="<?= url('bank.upload') ?>" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= Csrf::field() ?>
        <div class="form-group" style="margin-bottom:0;flex:1;min-width:220px">
            <label class="form-label"><?= t('bank_file') ?> (.csv)</label>
            <input class="form-input" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
        </div>
        <button class="btn btn-primary" type="submit"><?= t('bank_import') ?></button>
    </form>
</div>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('bank_recent_imports') ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('bank_th_account') ?></th><th><?= t('bank_th_file') ?></th><th><?= t('bank_th_period') ?></th>
            <th class="right"><?= t('bank_imp_added') ?></th><th class="right"><?= t('bank_imp_skipped') ?></th><th class="right"><?= t('bank_imp_pending') ?></th>
            <th class="right"><?= t('bank_imp_credit') ?></th><th class="right"><?= t('bank_imp_file_credit') ?></th>
            <th><?= t('bank_imp_totals') ?></th><th><?= t('bank_imp_balance') ?></th><th class="right"><?= t('bank_imp_rule') ?></th><th><?= t('bank_th_by') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$imports): ?><tr><td colspan="12" class="empty"><?= t('bank_no_data') ?></td></tr><?php endif; ?>
        <?php foreach ($imports as $i): ?>
            <tr<?= (int) $i['id'] === $last ? ' style="background:var(--primary-soft, rgba(0,168,132,.08))"' : '' ?> data-import="<?= (int) $i['id'] ?>">
                <td class="nowrap"><?= e($i['bank'] . ' …' . $i['last4']) ?></td>
                <td class="small"><?= e((string) $i['file_name']) ?></td>
                <td class="small nowrap"><?= e((string) $i['period']) ?></td>
                <td class="right num" data-added><?= (int) $i['added'] ?></td>
                <td class="right num" data-skipped><?= (int) $i['skipped'] ?></td>
                <td class="right num"><?= (int) $i['pending'] ?></td>
                <td class="right num"><?= (int) $i['credit_n'] ?> · <?= $money((float) $i['credit_sum']) ?></td>
                <td class="right num"><?= $i['file_credit'] === null ? '—' : ((int) $i['file_credit_n'] . ' · ' . $money((float) $i['file_credit'])) ?></td>
                <td data-totals><?= $okTag($i['totals_ok'] === null ? null : (int) $i['totals_ok']) ?></td>
                <td data-balance><?= $okTag((int) $i['balance_ok']) ?></td>
                <td class="right num"><?= (int) $i['rule_matched'] ?></td>
                <td class="small nowrap"><?= e((string) $i['imported_by']) ?><br><span class="muted"><?= e((string) $i['imported_at']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small" style="padding:0 16px 12px"><?= t('bank_import_check_hint') ?></p>
</div>
