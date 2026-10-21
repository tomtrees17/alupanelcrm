<?php /** @var array $todos */ /** @var string $today */ /** @var array $viewer */ ?>
<div class="page-head">
    <h1><?= t('nav_hr') ?></h1>
    <div class="head-actions">
        <a class="btn btn-ghost" href="<?= url('hr.employees') ?>"><?= t('hr_employees') ?></a>
        <?php if ($viewer['edit']): ?><a class="btn btn-primary" href="<?= url('hr.create') ?>"><?= t('hr_new_employee') ?></a><?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/_tabs.php'; ?>

<div class="card">
    <div class="card-header"><span class="card-title"><?= t('hr_todos') ?></span><span class="muted small"><?= count($todos) ?></span></div>
    <div class="table-wrap"><table>
        <thead><tr><th><?= t('hr_due') ?></th><th><?= t('hr_todo_type') ?></th><th><?= t('hr_employee') ?></th><?php if ($viewer['edit']): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$todos): ?><tr><td colspan="4" class="empty"><?= t('hr_no_todos') ?></td></tr><?php endif; ?>
        <?php foreach ($todos as $td): ?>
            <?php $late = $td['due_date'] !== null && $td['due_date'] < $today; ?>
            <tr class="<?= $late ? 'hr-overdue' : '' ?>">
                <td class="nowrap"><?= e(Hr::dmy($td['due_date'])) ?: '—' ?><?php if ($late): ?> <span class="tag tag-red"><?= t('hr_overdue') ?></span><?php endif; ?></td>
                <td><?= e(t('hrtodo_' . $td['type'])) ?></td>
                <td><?php if ($td['employee_id']): ?><a href="<?= url('hr.show', ['id' => $td['employee_id']]) ?>"><?= e($td['employee_no'] . ' ' . $td['full_name']) ?></a><?php else: ?>—<?php endif; ?></td>
                <?php if ($viewer['edit']): ?>
                    <td class="right">
                        <form method="post" action="<?= url('hr.todo_done') ?>" class="inline-form">
                            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $td['id'] ?>">
                            <input class="form-input" name="note" placeholder="<?= t('hr_handled_note') ?>" style="max-width:180px">
                            <button class="btn btn-ghost btn-sm" type="submit"><?= t('hr_mark_done') ?></button>
                        </form>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
