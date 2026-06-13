<?php /** @var array $txns */
$typeClass = ['in' => 'tag-green', 'out' => 'tag-orange', 'out_auto' => 'tag-purple'];
?>
<div class="page-head">
    <h1><?= t('btn_stock_txns') ?></h1>
    <a class="btn btn-ghost" href="<?= url('inventory.index') ?>"><?= t('btn_back_inventory') ?></a>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('th_date') ?></th><th><?= t('th_type') ?></th><th><?= t('th_sku') ?></th><th><?= t('th_product') ?></th><th class="right"><?= t('th_qty') ?></th><th><?= t('th_ref') ?></th><th><?= t('th_note') ?></th></tr></thead>
    <tbody>
    <?php if (!$txns): ?><tr><td colspan="7" class="empty"><?= t('no_txn') ?></td></tr><?php endif; ?>
    <?php foreach ($txns as $x): ?>
        <tr>
            <td><?= e($x['txn_date']) ?></td>
            <td><span class="tag <?= $typeClass[$x['type']] ?? 'tag-gray' ?>"><?= e(tr_txn_type($x['type'])) ?></span></td>
            <td><code><?= e($x['sku']) ?></code></td>
            <td><?= e($x['name']) ?></td>
            <td class="right"><?= $x['type'] === 'in' ? '+' : '−' ?><?= number_format($x['qty']) ?></td>
            <td class="muted"><?= e($x['ref']) ?></td>
            <td class="muted"><?= e($x['note']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
