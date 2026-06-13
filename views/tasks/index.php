<?php /** @var array $tasks */ /** @var string $filter */ /** @var array $stats */ /** @var array $customers */ ?>
<div class="page-head">
    <h1><?= t('page_tasks') ?></h1>
</div>

<div class="grid-2" style="grid-template-columns: 1fr 300px">
    <div>
        <div class="task-filters">
            <?php foreach (['all' => t('filter_all'), 'today' => t('filter_today'), 'high' => t('filter_high'), 'pending' => t('filter_pending'), 'done' => t('filter_done')] as $k => $lbl): ?>
                <a class="filter-btn <?= $filter === $k ? 'active' : '' ?>" href="<?= url('tasks.index', ['filter' => $k]) ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <div class="task-list">
            <?php if (!$tasks): ?><div class="card"><div class="empty"><?= t('no_tasks') ?></div></div><?php endif; ?>
            <?php foreach ($tasks as $tk): ?>
                <div class="task-item">
                    <form method="post" action="<?= url('tasks.toggle') ?>" style="display:inline">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $tk['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>">
                        <button class="task-check <?= $tk['done'] ? 'done' : '' ?>" type="submit"><?= $tk['done'] ? '✓' : '' ?></button>
                    </form>
                    <div class="task-info">
                        <div class="task-title <?= $tk['done'] ? 'done' : '' ?>"><?= e($tk['title']) ?></div>
                        <div class="task-meta">
                            <span>📅 <?= e($tk['due_date']) ?></span>
                            <?php if ($tk['customer_name']): ?><span>👤 <?= e($tk['customer_name']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="task-priority <?= priority_class($tk['priority']) ?>"><?= e(tr_priority($tk['priority'])) ?></span>
                    <form method="post" action="<?= url('tasks.delete') ?>" onsubmit="return confirm('?')" style="display:inline">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $tk['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= t('week_stats') ?></span></div>
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;margin-bottom:10px"><span class="muted"><?= t('completion_rate') ?></span><strong><?= $stats['rate'] ?>%</strong></div>
                <div style="display:flex;justify-content:space-between;margin-bottom:10px"><span class="muted"><?= t('done_count') ?></span><strong><?= $stats['done'] ?> / <?= $stats['total'] ?></strong></div>
                <div style="display:flex;justify-content:space-between"><span class="muted"><?= t('high_pending') ?></span><strong style="color:var(--danger)"><?= $stats['high'] ?></strong></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title"><?= t('add_task') ?></span></div>
            <div class="card-body">
                <form method="post" action="<?= url('tasks.store') ?>">
                    <?= Csrf::field() ?>
                    <div class="form-group"><label class="form-label"><?= t('f_task_title') ?></label><input class="form-input" name="title" required></div>
                    <div class="form-group"><label class="form-label"><?= t('f_due') ?></label><input class="form-input" type="date" name="due_date" value="2026-05-26"></div>
                    <div class="form-group"><label class="form-label"><?= t('f_priority') ?></label>
                        <select class="form-select" name="priority"><option value="中"><?= t('pri_med') ?></option><option value="高"><?= t('pri_high') ?></option><option value="低"><?= t('pri_low') ?></option></select>
                    </div>
                    <div class="form-group"><label class="form-label"><?= t('f_customer') ?></label>
                        <select class="form-select" name="customer_id"><option value="">—</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-block" type="submit"><?= t('btn_save_task') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
