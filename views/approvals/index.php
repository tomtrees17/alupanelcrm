<?php /** @var array $requests */ /** @var array $counts */ /** @var string $typeFilter */ /** @var string $statusFilter */ ?>
<div class="page-head">
    <h1><?= t('page_approvals') ?></h1>
    <div class="head-actions">
        <a class="btn btn-primary" href="<?= url('approvals.create') ?>"><?= t('btn_add_request') ?></a>
    </div>
</div>

<div class="task-filters">
    <a class="filter-btn <?= $typeFilter === '' ? 'active' : '' ?>" href="<?= url('approvals.index', array_filter(['status' => $statusFilter])) ?>"><?= t('filter_all') ?></a>
    <?php foreach (request_types() as $rt): ?>
        <a class="filter-btn <?= $typeFilter === $rt ? 'active' : '' ?>" href="<?= url('approvals.index', array_filter(['type' => $rt, 'status' => $statusFilter])) ?>"><?= e(request_type_label($rt)) ?></a>
    <?php endforeach; ?>
</div>
<div class="task-filters">
    <a class="filter-btn <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= url('approvals.index', array_filter(['type' => $typeFilter])) ?>"><?= t('filter_all') ?></a>
    <?php foreach (request_statuses() as $s): ?>
        <a class="filter-btn <?= $statusFilter === $s ? 'active' : '' ?>" href="<?= url('approvals.index', array_filter(['type' => $typeFilter, 'status' => $s])) ?>">
            <?= e(request_status_label($s)) ?><?php if (!empty($counts[$s])): ?> (<?= $counts[$s] ?>)<?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr>
        <th><?= t('th_req_no') ?></th><th><?= t('th_type') ?></th><th><?= t('applicant') ?></th>
        <th><?= t('th_subject') ?></th><th><?= t('th_period') ?></th>
        <th class="right"><?= t('th_amount') ?></th><th><?= t('th_status') ?></th>
    </tr></thead>
    <tbody>
    <?php if (!$requests): ?><tr><td colspan="7" class="empty"><?= t('no_requests') ?></td></tr><?php endif; ?>
    <?php foreach ($requests as $r): ?>
        <tr class="clickable" onclick="location.href='<?= url('approvals.show', ['id' => $r['id']]) ?>'">
            <td><code><?= e($r['req_no']) ?></code></td>
            <td><?= e(request_type_label($r['type'])) ?></td>
            <td><?= e($r['applicant']) ?></td>
            <td><strong><?= e($r['title']) ?></strong><?php if ($r['type'] === 'trip' && $r['destination']): ?><br><span class="muted"><?= e($r['destination']) ?></span><?php endif; ?></td>
            <td><?= e($r['start_date']) ?><?= $r['end_date'] ? ' → ' . e($r['end_date']) : '' ?></td>
            <td class="right"><?= $r['type'] === 'leave' ? '—' : idr($r['amount']) ?></td>
            <td><span class="order-status-badge <?= request_status_class($r['status']) ?>"><?= e(request_status_label($r['status'])) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
