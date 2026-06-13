<?php /** @var array $txns */
$typeLabel = ['in' => '入库', 'out' => '出库', 'out_auto' => '自动扣减'];
$typeClass = ['in' => 'tag-green', 'out' => 'tag-orange', 'out_auto' => 'tag-purple'];
?>
<div class="page-head">
    <h1>出入库流水</h1>
    <a class="btn btn-ghost" href="<?= url('inventory.index') ?>">返回库存</a>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>日期</th><th>类型</th><th>SKU</th><th>产品</th><th class="right">数量</th><th>单据</th><th>备注</th></tr></thead>
    <tbody>
    <?php if (!$txns): ?><tr><td colspan="7" class="empty">暂无流水</td></tr><?php endif; ?>
    <?php foreach ($txns as $x): ?>
        <tr>
            <td><?= e($x['txn_date']) ?></td>
            <td><span class="tag <?= $typeClass[$x['type']] ?? 'tag-gray' ?>"><?= $typeLabel[$x['type']] ?? $x['type'] ?></span></td>
            <td><code><?= e($x['sku']) ?></code></td>
            <td><?= e($x['name']) ?></td>
            <td class="right"><?= $x['type'] === 'in' ? '+' : '−' ?><?= number_format($x['qty']) ?></td>
            <td class="muted"><?= e($x['ref']) ?></td>
            <td class="muted"><?= e($x['note']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
