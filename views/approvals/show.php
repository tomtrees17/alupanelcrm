<?php
/** @var array $req */
/** @var Auth $auth */
$status = $req['status'];
$twoStage = request_needs_finance((string) $req['type']);   // expense & payment go through finance

// Approval timeline: applicant → (HR for trip/expense/leave) → manager → (finance for expense / payment).
$steps = [
    ['key' => 'apply', 'label' => t('applicant'), 'who' => $req['applicant'], 'date' => $req['created_at']],
];
if (request_needs_hr((string) $req['type'])) {
    $steps[] = ['key' => 'hr', 'label' => role_label('hr'), 'who' => $req['hr_approver'], 'date' => $req['hr_date']];
}
$steps[] = ['key' => 'mgr', 'label' => t('manager'), 'who' => $req['mgr_approver'], 'date' => $req['mgr_date']];
if ($twoStage) {
    $steps[] = ['key' => 'fin', 'label' => t('finance'), 'who' => $req['fin_approver'], 'date' => $req['fin_date']];
}
$activeMap = ['pending_hr' => 'hr', 'pending_mgr' => 'mgr', 'pending_fin' => 'fin'];
$stateOf = function (string $key) use ($status, $activeMap, $req) {
    if ($key === 'apply') return $status === 'draft' ? 'active' : 'approved';
    if ($status === 'approved') return 'approved';
    if (($activeMap[$status] ?? null) === $key) return 'active';
    return !empty($req[$key . '_date']) ? 'approved' : 'pending';
};
$canAct = request_can_act($auth, $req) && in_array($status, ['pending_hr', 'pending_mgr', 'pending_fin'], true);
?>
<div class="page-head">
    <h1><?= e($req['req_no']) ?> <span class="order-status-badge <?= request_status_class($status) ?>"><?= e(request_status_label($status)) ?></span></h1>
    <div class="head-actions">
        <?php if (request_editable($req)): ?>
            <a class="btn btn-ghost" href="<?= url('approvals.edit', ['id' => $req['id']]) ?>"><?= t('btn_edit') ?></a>
            <form method="post" action="<?= url('approvals.submit') ?>" style="display:inline">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                <button class="btn btn-primary" type="submit"><?= t('btn_save_order') ?></button>
            </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= url('approvals.index') ?>"><?= t('btn_back') ?></a>
    </div>
</div>

<?php if (!empty($req['reject_by']) && $status === 'draft'): ?>
    <div class="alert alert-error">
        ⚠ <strong><?= e($req['reject_by']) ?></strong> · <?= e($req['reject_date']) ?> · <?= t('rejected_back') ?><?= $req['reject_note'] ? '：' . e($req['reject_note']) : '' ?>
    </div>
<?php endif; ?>

<div class="card"><div class="card-body">
    <div class="approval-flow">
        <?php foreach ($steps as $i => $s): $st = $stateOf($s['key']); ?>
            <?php if ($i > 0): ?><div class="flow-line <?= $st === 'approved' ? 'done' : '' ?>"></div><?php endif; ?>
            <div class="flow-step">
                <div class="flow-dot <?= $st ?>"><?= $st === 'approved' ? '✓' : ($i + 1) ?></div>
                <div class="flow-label"><?= e($s['label']) ?><?= $s['who'] ? '<br>' . e($s['who']) : '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div></div>

<div class="card"><div class="card-body">
    <dl class="detail">
        <div><dt><?= t('th_type') ?></dt><dd><?= e(request_type_label($req['type'])) ?></dd></div>
        <div><dt><?= t('applicant') ?></dt><dd><?= e($req['applicant']) ?></dd></div>
        <div><dt><?= t('th_subject') ?></dt><dd><?= e($req['title']) ?></dd></div>
        <?php if ($req['type'] === 'trip'): ?>
            <div><dt><?= t('f_destination') ?></dt><dd><?= e($req['destination']) ?: '—' ?></dd></div>
        <?php elseif ($req['type'] === 'payment'): ?>
            <div><dt><?= t('f_payee') ?></dt><dd><strong><?= e($req['destination']) ?: '—' ?></strong></dd></div>
        <?php endif; ?>
        <?php if (!empty($req['ref_no'])): ?>
            <div><dt><?= t('f_ref') ?></dt><dd><?php if (!empty($refLink)): ?><a href="<?= e($refLink['url']) ?>" style="color:var(--accent2)"><code><?= e($refLink['label']) ?></code></a><?php else: ?><code><?= e($req['ref_no']) ?></code><?php endif; ?></dd></div>
        <?php endif; ?>
        <?php if ($req['category']): ?>
            <div><dt><?= $req['type'] === 'leave' ? t('f_leave_type') : t('f_category') ?></dt><dd><?= e(tr_req_cat($req['category'])) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= t('th_period') ?></dt><dd><?= e($req['start_date']) ?: '—' ?><?= $req['end_date'] ? ' → ' . e($req['end_date']) : '' ?></dd></div>
        <?php if ($req['type'] !== 'leave'): ?>
            <div><dt><?= t('th_amount') ?></dt><dd><strong><?= idr($req['amount']) ?></strong></dd></div>
        <?php endif; ?>
        <div><dt><?= t('th_date') ?></dt><dd><?= e($req['created_at']) ?></dd></div>
    </dl>
    <?php if ($req['reason']): ?>
        <div class="notes"><strong><?= t('f_reason') ?>：</strong><?= nl2br(e($req['reason'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($files)): ?>
        <div class="notes" style="margin-top:10px">
            <strong><?= t('attachments') ?>：</strong>
            <?php foreach ($files as $f): ?>
                <div style="margin-top:6px"><a href="<?= url('approvals.file', ['fid' => $f['id']]) ?>" target="_blank" style="color:var(--accent2)">📎 <?= e($f['orig_name']) ?></a> <span class="muted" style="font-size:11px"><?= num(round($f['size'] / 1024)) ?> KB · <?= e($f['uploaded_by']) ?></span></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div></div>

<?php if ($req['hr_note'] || $req['hr_approver'] || $req['mgr_note'] || $req['mgr_approver'] || $req['fin_note'] || $req['fin_approver']): ?>
    <div class="card"><div class="card-body">
        <span class="card-title"><?= t('approval_opinions') ?></span>
        <?php foreach ([[role_label('hr'), $req['hr_approver'], $req['hr_note'], $req['hr_date']], [t('manager'), $req['mgr_approver'], $req['mgr_note'], $req['mgr_date']], [t('finance'), $req['fin_approver'], $req['fin_note'], $req['fin_date']]] as $a): ?>
            <?php if ($a[1] || $a[2]): ?>
                <div class="notes" style="margin-top:8px"><strong><?= $a[0] ?> · <?= e($a[1]) ?> · <?= e($a[3]) ?>：</strong> <?= e($a[2]) ?: '—' ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<?php if ($canAct): ?>
    <div class="card"><div class="card-body">
        <span class="card-title"><?= e(role_label(request_action_role($status) ?? '')) ?> · <?= t('approval_by') ?></span>
        <form method="post" style="margin-top:10px">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
            <div class="form-group"><label class="form-label"><?= t('approval_opinions') ?></label><textarea class="form-textarea" name="note"></textarea></div>
            <div class="form-actions">
                <button class="btn btn-danger" type="submit" formaction="<?= url('approvals.reject') ?>" onclick="return confirm('?')"><?= t('btn_reject') ?></button>
                <button class="btn btn-success" type="submit" formaction="<?= url('approvals.approve') ?>"><?= $status === 'pending_fin' ? t('btn_confirm_pay') : t('btn_approve') ?></button>
            </div>
        </form>
    </div></div>
<?php elseif (in_array($status, ['pending_hr', 'pending_mgr', 'pending_fin'], true)): ?>
    <div class="card"><div class="card-body muted"><?= t('wait_for') ?> <strong><?= e(role_label(request_action_role($status) ?? '')) ?></strong><?= t('no_permission_stage') ?></div></div>
<?php endif; ?>

<?php if ($auth->isAdmin() || request_editable($req)): ?>
    <form method="post" action="<?= url('approvals.delete') ?>" onsubmit="return confirm('?')" class="no-print">
        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><?= t('btn_delete') ?></button>
    </form>
<?php endif; ?>
