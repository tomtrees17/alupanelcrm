<?php
/** @var array $order */ /** @var array $items */ /** @var array $totals */ /** @var ?array $invoice */
/** @var Auth $auth */

// Determine the 4-step approval flow state.
$status = $order['status'];
$steps = [
    ['key' => 'sales', 'label' => t('sales'), 'who' => $order['submitter'], 'note' => '', 'date' => $order['created_at']],
    ['key' => 'sup', 'label' => t('supervisor'), 'who' => $order['sup_approver'], 'note' => $order['sup_note'], 'date' => $order['sup_date']],
    ['key' => 'mgr', 'label' => t('manager'), 'who' => $order['mgr_approver'], 'note' => $order['mgr_note'], 'date' => $order['mgr_date']],
    ['key' => 'wh', 'label' => t('warehouse'), 'who' => $order['wh_approver'], 'note' => $order['wh_note'], 'date' => $order['wh_date']],
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
        <?php if (!empty($deliveryId)): ?><a class="btn btn-ghost" href="<?= url('delivery.print', ['id' => $deliveryId]) ?>" target="_blank"><?= t('btn_print') ?> · DO</a><?php endif; ?>
        <?php if (!empty($invoice)): ?><a class="btn btn-ghost" href="<?= url('finance.print', ['id' => $invoice['id']]) ?>" target="_blank"><?= t('btn_print') ?> · Invoice</a><?php endif; ?>
        <?php if (order_editable($order)): ?>
            <a class="btn btn-ghost" href="<?= url('orders.edit', ['id' => $order['id']]) ?>"><?= t('btn_edit') ?></a>
            <form method="post" action="<?= url('orders.submit') ?>" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <button class="btn btn-primary" type="submit"><?= t('btn_save_order') ?></button>
            </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('orders.index') ?>"><?= t('btn_back') ?></a>
    </div>
</div>

<?php if (!empty($order['reject_note']) || (!empty($order['reject_by']) && $order['status'] === 'draft')): ?>
    <div class="alert alert-error">
        ⚠ 已被 <strong><?= e($order['reject_by']) ?></strong>（<?= e($order['reject_date']) ?>）驳回退回草稿<?= $order['reject_note'] ? '：' . e($order['reject_note']) : '' ?>
    </div>
<?php endif; ?>

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
            <div><dt><?= t('th_customer') ?></dt><dd><?= e($order['customer_name']) ?></dd></div>
            <div><dt><?= t('th_company') ?></dt><dd><?= e($order['company']) ?: '—' ?></dd></div>
            <div><dt><?= t('th_phone') ?></dt><dd><?= e($order['phone']) ?: '—' ?></dd></div>
            <div><dt><?= t('f_client_type') ?></dt><dd><?= e($order['client_type']) ?></dd></div>
            <div><dt><?= t('f_delivery_service') ?></dt><dd><?= e($order['delivery_service']) ?></dd></div>
            <div><dt><?= t('f_delivery_date') ?></dt><dd><?= e($order['delivery_date']) ?: '—' ?></dd></div>
            <div><dt><?= t('f_payment_term') ?></dt><dd><?= e($order['payment_term']) ?><?= $order['payment_term'] === 'custom' ? ' Net ' . (int) $order['custom_days'] . 'd' : '' ?></dd></div>
            <div><dt><?= t('th_submitter') ?></dt><dd><?= e($order['submitter']) ?></dd></div>
            <div><dt><?= t('do_invoice') ?></dt><dd><?= e($order['do_number']) ?: '—' ?> / <?= $invoice ? '<a href="' . url('finance.show', ['id' => $invoice['id']]) . '">' . e($order['invoice_number']) . '</a>' : '—' ?></dd></div>
        </dl>
        <div class="notes"><strong><?= t('delivery_addr') ?>：</strong><?= e($order['delivery_address']) ?: e($order['address']) ?><?= $order['note'] ? '<br><strong>' . t('th_note') . '：</strong>' . e($order['note']) : '' ?></div>
    </div></div>

    <div class="card">
        <div class="card-header"><span class="card-title"><?= t('product_items') ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('th_sku') ?></th><th><?= t('th_color_spec') ?></th><th class="right"><?= t('th_qty') ?></th><th class="right"><?= t('th_unit_price') ?></th><th class="right"><?= t('th_subtotal') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><code><?= e($it['sku']) ?></code></td><td><?= e($it['color']) ?> · <?= e($it['spec']) ?></td>
                    <td class="right"><?= (int) $it['qty'] ?></td><td class="right"><?= idr($it['price']) ?></td>
                    <td class="right"><?= idr($it['qty'] * $it['price']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr><td colspan="4" class="right"><?= t('shipping') ?></td><td class="right"><?= idr($totals['shipping']) ?></td></tr>
            <tr class="total-row"><td colspan="4" class="right"><?= t('total') ?></td><td class="right"><?= idr($totals['total']) ?></td></tr>
            </tfoot>
        </table></div>
    </div>
</div>

<?php if ($order['sup_note'] || $order['mgr_note'] || $order['wh_note']): ?>
    <div class="card"><div class="card-body">
        <span class="card-title"><?= t('approval_opinions') ?></span>
        <?php foreach ([[t('supervisor'), $order['sup_approver'], $order['sup_note'], $order['sup_date']], [t('manager'), $order['mgr_approver'], $order['mgr_note'], $order['mgr_date']], [t('warehouse'), $order['wh_approver'], $order['wh_note'], $order['wh_date']]] as $a): ?>
            <?php if ($a[2] || $a[1]): ?>
                <div class="notes" style="margin-top:8px"><strong><?= $a[0] ?> · <?= e($a[1]) ?> · <?= e($a[3]) ?>：</strong> <?= e($a[2]) ?: '—' ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<?php if ($canAct && in_array($status, ['pending_sup', 'pending_mgr', 'pending_wh'], true)): ?>
    <div class="card"><div class="card-body">
        <span class="card-title"><?= e(role_label(order_action_role($status) ?? '')) ?> · <?= t('approval_by') ?></span>
        <form method="post" style="margin-top:10px">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
            <div class="form-group"><label class="form-label"><?= t('approval_opinions') ?></label><textarea class="form-textarea" name="note"></textarea></div>
            <div class="form-actions">
                <button class="btn btn-danger" type="submit" formaction="<?= url('orders.reject') ?>" onclick="return confirm('?')"><?= t('btn_reject') ?></button>
                <button class="btn btn-success" type="submit" formaction="<?= url('orders.approve') ?>"><?= $status === 'pending_wh' ? t('btn_confirm_ship') : t('btn_approve') ?></button>
            </div>
        </form>
    </div></div>
<?php elseif (in_array($status, ['pending_sup', 'pending_mgr', 'pending_wh'], true)): ?>
    <div class="card"><div class="card-body muted"><?= t('wait_for') ?> <strong><?= e(role_label(order_action_role($status) ?? '')) ?></strong><?= t('no_permission_stage') ?></div></div>
<?php endif; ?>

<?php if ($auth->isAdmin() || order_editable($order)): ?>
    <form method="post" action="<?= url('orders.delete') ?>" onsubmit="return confirm('?')" class="no-print">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><?= t('btn_delete') ?></button>
    </form>
<?php endif; ?>
