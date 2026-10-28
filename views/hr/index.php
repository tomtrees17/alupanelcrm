<?php /** @var array $todos */ /** @var string $today */ /** @var array $viewer */ ?>
<div class="page-head">
    <h1><?= t('nav_hr') ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('hr.employees') ?>"><?= t('hr_employees') ?></a>
        <?php if ($viewer['edit']): ?><a class="btn btn-primary" href="<?= url('hr.create') ?>"><?= t('hr_new_employee') ?></a><?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>

<?php $boss = isset($auth) && $auth->isAdmin(); $handleCol = $viewer['edit'] || $boss; ?>
<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hr_todos') ?></span><span class="muted small"><?= count($todos) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('hr_due') ?></th><th><?= t('hr_todo_type') ?></th><th><?= t('hr_employee') ?></th><?php if ($handleCol): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$todos): ?><tr><td colspan="4" class="empty"><?= t('hr_no_todos') ?></td></tr><?php endif; ?>
        <?php foreach ($todos as $td): ?>
            <?php $late = ($td['due_date'] !== null && $td['due_date'] < $today) || $td['type'] === 'contract_expired'; ?>
            <?php $cid = str_starts_with((string) $td['ref'], 'contract:') ? (int) substr((string) $td['ref'], 9) : 0; ?>
            <?php $bossType = in_array($td['type'], Hr::BOSS_TODO_TYPES, true); $canHandle = $bossType ? $boss : $viewer['edit']; ?>
            <tr class="<?= $late ? 'hr-overdue' : '' ?>">
                <td class="nowrap"><?= e(Hr::dmy($td['due_date'])) ?: '—' ?><?php if ($late): ?> <span class="tag tag-red"><?= t('hr_overdue') ?></span><?php endif; ?></td>
                <td><?= e(t('hrtodo_' . $td['type'])) ?><?php if ($td['type'] === 'bpjs_overdue'): ?> · <?= $td['ref'] === 'kesehatan' ? 'Kesehatan' : 'Ketenagakerjaan' ?><?php endif; ?><?php if (in_array($td['type'], ['bpjs_overdue', 'bpjs_stop'], true) && $td['employee_id']): ?> · <a href="<?= url('hr.show', ['id' => $td['employee_id'], 'tab' => 'bpjs']) ?>"><?= t('hrc_open') ?></a><?php endif; ?><?php if ($cid): ?> · <a href="<?= url(in_array($td['type'], ['contract_expiring', 'contract_expired'], true) && $viewer['edit'] ? 'hr.contract_decide' : 'hr.contract', ['id' => $cid]) ?>"><?= t('hrc_open') ?></a><?php endif; ?><?php if ($td['type'] === 'ai_budget'): ?> · <?= e(str_starts_with((string) $td['ref'], 'month:') ? sprintf(t('ai_todo_month'), substr((string) $td['ref'], 6)) : sprintf(t('ai_todo_day'), Hr::dmy(substr((string) $td['ref'], 4)))) ?><?php if (can_access('ai_usage')): ?> · <a href="<?= url('ai.usage') ?>"><?= t('hrc_open') ?></a><?php endif; ?><?php endif; ?><?php if ($td['type'] === 'sales_review'): ?> · <?= e(sprintf(t('srv_week_of'), Hr::dmy((string) $td['ref']))) ?> · <a href="<?= url('reviews.index', ['week' => $td['ref']]) ?>"><?= t('hrc_open') ?></a><?php endif; ?></td>
                <td><?php if ($td['employee_id']): ?><a href="<?= url('hr.show', ['id' => $td['employee_id']]) ?>"><?= e($td['employee_no'] . ' ' . $td['full_name']) ?></a><?php else: ?>—<?php endif; ?></td>
                <?php if ($handleCol): ?>
                    <td class="right"><?php if ($canHandle): ?>
                        <form method="post" action="<?= url('hr.todo_done') ?>" class="inline-form">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $td['id'] ?>">
                            <input class="form-input" name="note" placeholder="<?= t('hr_handled_note') ?>" style="max-width:180px">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= t('hr_mark_done') ?></button>
                        </form>
                    <?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
