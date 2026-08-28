<?php
/** @var array $rows */ /** @var int $total */ /** @var int $page */ /** @var int $pages */
/** @var string $st */ /** @var array $counts */ /** @var string $driver */
$cls = ['sent' => 'tag-green', 'queued' => 'tag-blue', 'failed' => 'tag-red', 'skipped' => 'tag-gray'];
?>
<div class="page-head">
    <h1><?= t('page_notifications') ?></h1>
    <div class="head-actions">
        <span class="muted small">
            <?= t('notif_driver') ?>:
            <strong><?= e($driver) ?></strong>
            <?php if ($driver === 'log'): ?>（<?= t('notif_driver_log') ?>）<?php endif; ?>
        </span>
        <a class="btn btn-ghost" href="<?= url('audit.index') ?>"><?= t('notif_back_audit') ?></a>
    </div>
</div>

<div class="searchbar">
    <a class="filter-btn <?= $st === '' ? 'active' : '' ?>" href="<?= url('audit.notifications') ?>"><?= t('filter_all') ?> (<?= $total ?>)</a>
    <?php foreach (['queued', 'sent', 'failed', 'skipped'] as $s): ?>
        <a class="filter-btn <?= $st === $s ? 'active' : '' ?>" href="<?= url('audit.notifications', ['st' => $s]) ?>">
            <?= t('notif_st_' . $s) ?> (<?= (int) ($counts[$s] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-wrap"><table>
        <thead><tr>
            <th><?= t('audit_time') ?></th>
            <th><?= t('notif_to') ?></th>
            <th><?= t('notif_event') ?></th>
            <th><?= t('audit_target') ?></th>
            <th><?= t('notif_body') ?></th>
            <th><?= t('notif_status') ?></th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6"><div class="empty"><?= t('notif_none') ?></div></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= e($r['sent_at'] ?: $r['created_at']) ?></td>
                <td>
                    <?= e($r['user_name'] ?: '—') ?>
                    <div class="muted small"><?= e($r['phone'] ?: '—') ?></div>
                </td>
                <td class="small"><?= e($r['event']) ?></td>
                <td><?= e($r['label']) ?></td>
                <td class="audit-detail"><?= nl2br(e($r['body'])) ?></td>
                <td>
                    <span class="tag <?= $cls[$r['status']] ?? 'tag-gray' ?>"><?= t('notif_st_' . $r['status']) ?></span>
                    <?php if (!empty($r['error'])): ?>
                        <div class="muted small"><?= e($r['error']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php if ($pages > 1): ?>
    <div class="pager">
        <?php $f = array_filter(['st' => $st], fn($v) => $v !== ''); ?>
        <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= url('audit.notifications', array_merge($f, ['page' => $page - 1])) ?>"><?= t('audit_prev') ?></a><?php endif; ?>
        <span class="muted"><?= sprintf(t('audit_page'), $page, $pages) ?></span>
        <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= url('audit.notifications', array_merge($f, ['page' => $page + 1])) ?>"><?= t('audit_next') ?></a><?php endif; ?>
    </div>
<?php endif; ?>
