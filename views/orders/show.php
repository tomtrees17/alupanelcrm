<?php
/** @var array $order */ /** @var array $items */ /** @var array $totals */ /** @var ?array $invoice */
/** @var Auth $auth */

// Determine the 4-step approval flow state.
$status = $order['status'];
$steps = [
    ['key' => 'sales', 'label' => '销售', 'who' => $order['submitter'], 'note' => '', 'date' => $order['created_at']],
    ['key' => 'sup', 'label' => '主管', 'who' => $order['sup_approver'], 'note' => $order['sup_note'], 'date' => $order['sup_date']],
    ['key' => 'mgr', 'label' => '经理', 'who' => $order['mgr_approver'], 'note' => $order['mgr_note'], 'date' => $order['mgr_date']],
    ['key' => 'wh', 'label' => '仓库', 'who' => $order['wh_approver'], 'note' => $order['wh_note'], 'date' => $order['wh_date']],
];
$activeMap = ['pending_sup' => 'sup', 'pending_mgr' => 'mgr', 'pending_wh' => 'wh'];
$rejStage = null;
if ($status === 'rejected') {
    foreach (['wh', 'mgr', 'sup'] as $k) {
        if (!empty($order["{$k}_date"])) { $rejStage = $k; break; }
    }
    if (!$rejStage) { $rejStage = 'sup'; }
}
$stateOf = function (string $key) use ($status, $activeMap, $rejStage, $order) {
    if ($key === 'sales') return 'approved';
    if ($status === 'approved') return 'approved';
    if ($status === 'rejected') {
        if ($key === $rejStage) return 'rejected';
        return !empty($order["{$key}_date"]) ? 'approved' : 'pending';
    }
    if (($activeMap[$status] ?? null) === $key) return 'active';
    return !empty($order["{$key}_date"]) ? 'approved' : 'pending';
};
$canAct = $auth->isAdmin() || (order_action_role($status) === ($auth->user()['role'] ?? ''));
?>
<div class="page-head">
    <h1>订单 <?= e($order['order_no']) ?> <span class="order-status-badge <?= order_status_class($status) ?>"><?= e(order_status_label($status)) ?></span></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="javascript:window.print()">打印</a>
        <a class="btn btn-ghost" href="<?= url('orders.index') ?>">返回列表</a>
    </div>
</div>

<div class="card"><div class="card-body">
    <div class="approval-flow">
        <?php foreach ($steps as $i => $s): $st = $stateOf($s['key']); ?>
            <?php if ($i > 0): ?><div class="flow-line <?= in_array($st, ['approved', 'rejected'], true) ? 'done' : '' ?>"></div><?php endif; ?>
            <div class="flow-step">
                <div class="flow-dot <?= $st ?>"><?= $st === 'approved' ? '✓' : ($st === 'rejected' ? '✕' : ($i + 1)) ?></div>
                <div class="flow-label"><?= e($s['label']) ?><?= $s['who'] ? '<br>' . e($s['who']) : '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>

<div class="grid-2">
    <div class="card"><div class="card-body">
        <dl class="detail">
            <div><dt>客户</dt><dd><?= e($order['customer_name']) ?></dd></div>
            <div><dt>公司</dt><dd><?= e($order['company']) ?: '—' ?></dd></div>
            <div><dt>电话</dt><dd><?= e($order['phone']) ?: '—' ?></dd></div>
            <div><dt>客户类型</dt><dd><?= e($order['client_type']) ?></dd></div>
            <div><dt>配送方式</dt><dd><?= e($order['delivery_service']) ?></dd></div>
            <div><dt>送货日期</dt><dd><?= e($order['delivery_date']) ?: '—' ?></dd></div>
            <div><dt>付款条件</dt><dd><?= e($order['payment_term']) ?><?= $order['payment_term'] === 'custom' ? ' Net ' . (int) $order['custom_days'] . '天' : '' ?></dd></div>
            <div><dt>提交人</dt><dd><?= e($order['submitter']) ?></dd></div>
            <div><dt>送货单 / 发票</dt><dd><?= e($order['do_number']) ?: '—' ?> / <?= $invoice ? '<a href="' . url('finance.show', ['id' => $invoice['id']]) . '">' . e($order['invoice_number']) . '</a>' : '—' ?></dd></div>
        </dl>
        <div class="notes"><strong>送货地址：</strong><?= e($order['delivery_address']) ?: e($order['address']) ?><?= $order['note'] ? '<br><strong>备注：</strong>' . e($order['note']) : '' ?></div>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title">产品明细</span></div>
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
            <tr><td colspan="4" class="right">运费</td><td class="right"><?= idr($totals['shipping']) ?></td></tr>
            <tr class="total-row"><td colspan="4" class="right">合计</td><td class="right"><?= idr($totals['total']) ?></td></tr>
            </tfoot>
        </table></div>
    </div>
</div>

<?php if ($order['sup_note'] || $order['mgr_note'] || $order['wh_note']): ?>
    <div class="card"><div class="card-body">
        <span class="card-title">审批意见</span>
        <?php foreach ([['主管', $order['sup_approver'], $order['sup_note'], $order['sup_date']], ['经理', $order['mgr_approver'], $order['mgr_note'], $order['mgr_date']], ['仓库', $order['wh_approver'], $order['wh_note'], $order['wh_date']]] as $a): ?>
            <?php if ($a[2] || $a[1]): ?>
                <div class="notes" style="margin-top:8px"><strong><?= $a[0] ?> · <?= e($a[1]) ?> · <?= e($a[3]) ?>：</strong> <?= e($a[2]) ?: '（无意见）' ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<?php if ($canAct && in_array($status, ['pending_sup', 'pending_mgr', 'pending_wh'], true)): ?>
    <div class="card"><div class="card-body">
        <span class="card-title"><?= e(role_label(order_action_role($status) ?? '')) ?>审批</span>
        <form method="post" style="margin-top:10px">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
            <div class="form-group"><label class="form-label">审批意见</label><textarea class="form-textarea" name="note" placeholder="<?= $status === 'pending_wh' ? '确认出货后将自动扣减库存并生成送货单与发票' : '填写审批意见' ?>"></textarea></div>
            <div class="form-actions">
                <button class="btn btn-danger" type="submit" formaction="<?= url('orders.reject') ?>" onclick="return confirm('确定驳回该订单？')">驳回</button>
                <button class="btn btn-success" type="submit" formaction="<?= url('orders.approve') ?>"><?= $status === 'pending_wh' ? '确认出货' : '通过' ?></button>
            </div>
        </form>
    </div></div>
<?php elseif (in_array($status, ['pending_sup', 'pending_mgr', 'pending_wh'], true)): ?>
    <div class="card"><div class="card-body muted">当前等待<strong><?= e(role_label(order_action_role($status) ?? '')) ?></strong>审批，你没有该阶段的操作权限。</div></div>
<?php endif; ?>

<?php if ($auth->isAdmin()): ?>
    <form method="post" action="<?= url('orders.delete') ?>" onsubmit="return confirm('确定删除该订单？')" class="no-print">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)">删除订单</button>
    </form>
<?php endif; ?>
