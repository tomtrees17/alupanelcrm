<?php /** @var array $rows */ /** @var string $month */ /** @var array $months */ ?>
<?php
$money = fn(float $v): string => number_format($v, 2, ',', '.');
$tag = fn(string $s): string => str_starts_with($s, 'PAID') ? (str_contains($s, 'Lebih') ? 'tag-orange' : 'tag-green') : (str_starts_with($s, 'Sebagian') ? 'tag-blue' : 'tag-red');
?>
<div class="page-head">
    <div>
        <h1><?= t('bank_receipts') ?></h1>
        <p class="muted"><?= t('bank_rc_sub') ?></p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <form method="get" action="index.php">
            <input type="hidden" name="r" value="bank.receipts">
            <select class="form-input" name="m" onchange="this.form.submit()">
                <?php foreach ($months as $m): ?><option value="<?= e($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if (can_export()): ?><a class="btn btn-ghost" href="<?= url('bank.receipts_export', ['m' => $month]) ?>"><?= t('btn_export') ?></a><?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('bank.index') ?>"><?= t('bank_title') ?></a>
    </div>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr>
            <th>No. Invoice</th><th><?= t('th_date') ?></th><th>PIC</th><th><?= t('th_customer') ?></th>
            <th class="right">Total</th><th class="right"><?= t('bank_th_paid') ?></th><th class="right">Balanced</th>
            <th>STATUS</th><th><?= t('bank_th_last_paid') ?></th><th><?= t('bank_th_payments') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty"><?= t('bank_no_rows') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr data-invoice="<?= e($r['invoice_no']) ?>">
                <td class="nowrap"><a href="<?= url('finance.show', ['id' => $r['id']]) ?>"><?= e($r['invoice_no']) ?></a></td>
                <td class="nowrap"><?= e(Hr::dmy($r['invoice_date'])) ?></td>
                <td><?= e((string) $r['submitter']) ?></td>
                <td><?= e((string) $r['customer']) ?></td>
                <td class="right num"><?= $money((float) $r['total']) ?></td>
                <td class="right num"><?= $money($r['cash']) ?><?php if ($r['settled'] > 0.005): ?><br><span class="muted small"><?= t('bank_settled') ?> <?= $money($r['settled']) ?></span><?php endif; ?></td>
                <td class="right num"><?= $money($r['balance']) ?></td>
                <td class="nowrap"><span class="tag <?= $tag($r['status']) ?>" data-status><?= e($r['status']) ?></span><?php if ($r['refunds']): ?><br><span class="small muted"><?= t('bank_refund') ?> <?= e(implode(', ', $r['refunds'])) ?></span><?php endif; ?></td>
                <td class="nowrap"><?= $r['last_paid'] !== '' ? e(Hr::dmy($r['last_paid'])) : '—' ?></td>
                <td class="small"><?php foreach ($r['payments'] as $p): ?><div class="nowrap"><?= e(Hr::dmy((string) $p['pay_date'])) ?> · <?= e((string) $p['receipt_no']) ?> · <?= $money((float) $p['amount']) ?><?= (int) $p['noncash'] === 1 ? ' (' . e((string) $p['method']) . ')' : '' ?></div><?php endforeach; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
