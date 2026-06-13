<?php /** @var array $tasks */ /** @var string $filter */ /** @var array $stats */ /** @var array $customers */ ?>
<div class="page-head">
    <h1>任务提醒</h1>
</div>

<div class="grid-2" style="grid-template-columns: 1fr 300px">
    <div>
        <div class="task-filters">
            <?php foreach (['all' => '全部', 'today' => '今天', 'high' => '高优先级', 'pending' => '待办', 'done' => '已完成'] as $k => $lbl): ?>
                <a class="filter-btn <?= $filter === $k ? 'active' : '' ?>" href="<?= url('tasks.index', ['filter' => $k]) ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <div class="task-list">
            <?php if (!$tasks): ?><div class="card"><div class="empty">没有符合条件的任务</div></div><?php endif; ?>
            <?php foreach ($tasks as $t): ?>
                <div class="task-item">
                    <form method="post" action="<?= url('tasks.toggle') ?>" style="display:inline">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>">
                        <button class="task-check <?= $t['done'] ? 'done' : '' ?>" type="submit" title="切换完成"><?= $t['done'] ? '✓' : '' ?></button>
                    </form>
                    <div class="task-info">
                        <div class="task-title <?= $t['done'] ? 'done' : '' ?>"><?= e($t['title']) ?></div>
                        <div class="task-meta">
                            <span>📅 <?= e($t['due_date']) ?></span>
                            <?php if ($t['customer_name']): ?><span>👤 <?= e($t['customer_name']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <span class="task-priority <?= priority_class($t['priority']) ?>"><?= e($t['priority']) ?></span>
                    <form method="post" action="<?= url('tasks.delete') ?>" onsubmit="return confirm('删除任务？')" style="display:inline">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header"><span class="card-title">本周统计</span></div>
            <div class="card-body">
                <div style="display:flex;justify-content:space-between;margin-bottom:10px"><span class="muted">完成率</span><strong><?= $stats['rate'] ?>%</strong></div>
                <div style="display:flex;justify-content:space-between;margin-bottom:10px"><span class="muted">已完成</span><strong><?= $stats['done'] ?> / <?= $stats['total'] ?></strong></div>
                <div style="display:flex;justify-content:space-between"><span class="muted">高优先级待办</span><strong style="color:var(--danger)"><?= $stats['high'] ?></strong></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">添加任务</span></div>
            <div class="card-body">
                <form method="post" action="<?= url('tasks.store') ?>">
                    <?= Csrf::field() ?>
                    <div class="form-group"><label class="form-label">任务标题 *</label><input class="form-input" name="title" required></div>
                    <div class="form-group"><label class="form-label">截止日期</label><input class="form-input" type="date" name="due_date" value="2026-05-26"></div>
                    <div class="form-group"><label class="form-label">优先级</label>
                        <select class="form-select" name="priority"><option>中</option><option>高</option><option>低</option></select>
                    </div>
                    <div class="form-group"><label class="form-label">关联客户</label>
                        <select class="form-select" name="customer_id"><option value="">—</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-block" type="submit">保存任务</button>
                </form>
            </div>
        </div>
    </div>
</div>
