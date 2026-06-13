<?php /** @var array $invoice */ /** @var array $items */ /** @var array $payments */
$remaining = $invoice['total'] - $invoice['amount_paid'];
?>
<div class="page-head">
    <h1>发票 <?= e($invoice['invoice_no']) ?> <span class="tag <?= invoice_status_class($invoice['payment_status']) ?>"><?= e(invoice_status_label($invoice['payment_status'])) ?></span></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="javascript:window.print()">打印</a>
        <a class="btn btn-ghost" href="<?= url('finance.index') ?>">返回列表</a>
    </div>
</div>

<div class="grid-2">
    <div class="card"><div class="card-body">
        <dl class="detail">
            <div><dt>客户</dt><dd><?= e($invoice['customer']) ?></dd></div>
            <div><dt>开票对象</dt><dd><?= e($invoice['bill_to_name']) ?: '—' ?></dd></div>
            <div><dt>NPWP 税号</dt><dd><?= e($invoice['npwp']) ?: '—' ?></dd></div>
            <div><dt>No. PO</dt><dd><?= e($invoice['no_po']) ?: '—' ?></dd></div>
            <div><dt>关联订单</dt><dd><?= $invoice['order_id'] ? '<a href="' . url('orders.show', ['id' => $invoice['order_id']]) . '">查看</a>' : '—' ?></dd></div>
            <div><dt>送货单</dt><dd><?= e($invoice['do_number']) ?: '—' ?></dd></div>
            <div><dt>开票日</dt><dd><?= e($invoice['invoice_date']) ?></dd></div>
            <div><dt>到期日</dt><dd><?= e($invoice['due_date']) ?></dd></div>
            <div><dt>税票号</dt><dd><?= e($invoice['tax_invoice_no']) ?: '—' ?></dd></div>
        </dl>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title">明细</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>颜色/规格</th><th class="right">数量</th><th class="right">单价</th><th class="right">小计</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><code><?= e($it['sku']) ?></code></td><td><?= e($it['color']) ?> · <?= e($it['spec']) ?></td>
                    <td class="right"><?= (int) $it['qty'] ?></td><td class="right"><?= idr($it['price']) ?></td>
                    <td class="right"><?= idr($it['qty'] * $it['price']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4" class="right">运费</td><td class="right"><?= idr($invoice['shipping_cost']) ?></td></tr>
                <tr><td colspan="4" class="right">小计</td><td class="right"><?= idr($invoice['subtotal']) ?></td></tr>
                <tr><td colspan="4" class="right">PPN 11%</td><td class="right"><?= idr($invoice['ppn']) ?></td></tr>
                <tr class="total-row"><td colspan="4" class="right">合计</td><td class="right"><?= idr($invoice['total']) ?></td></tr>
                <tr><td colspan="4" class="right">已收</td><td class="right"><?= idr($invoice['amount_paid']) ?></td></tr>
                <tr><td colspan="4" class="right" style="color:var(--danger)">未收</td><td class="right" style="color:var(--danger)"><?= idr($remaining) ?></td></tr>
            </tfoot>
        </table></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><span class="card-title">收款记录</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>日期</th><th>方式</th><th>收据号</th><th class="right">金额</th></tr></thead>
            <tbody>
            <?php if (!$payments): ?><tr><td colspan="4" class="empty">暂无收款</td></tr><?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr><td><?= e($p['pay_date']) ?></td><td><?= e($p['method']) ?: '—' ?></td><td><code><?= e($p['receipt_no']) ?></code></td><td class="right"><?= idr($p['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <?php if ($remaining > 0): ?>
    <div class="card no-print">
        <div class="card-header"><span class="card-title">登记收款</span></div>
        <div class="card-body">
            <form method="post" action="<?= url('finance.pay') ?>">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                <div class="form-row">
                    <div class="form-group"><label class="form-label">金额 (Rp) *</label><input class="form-input" type="number" name="amount" value="<?= (int) $remaining ?>" required></div>
                    <div class="form-group"><label class="form-label">日期</label><input class="form-input" type="date" name="pay_date" value="2026-05-26"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">收款方式</label><input class="form-input" name="method" placeholder="BCA Transfer"></div>
                    <div class="form-group"><label class="form-label">收据号</label><input class="form-input" name="receipt_no" placeholder="留空自动生成"></div>
                </div>
                <div class="form-group"><label class="form-label">备注</label><input class="form-input" name="note"></div>
                <button class="btn btn-primary btn-block" type="submit">登记收款</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
