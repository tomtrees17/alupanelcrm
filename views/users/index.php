<?php /** @var array $users */ /** @var Auth $auth */ ?>
<div class="page-head">
    <h1><?= t('page_users') ?></h1>
    <a class="btn btn-primary" href="<?= url('users.create') ?>"><?= t('btn_add_user') ?></a>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th><?= t('th_name') ?></th><th><?= t('th_email') ?></th><th><?= t('th_role') ?></th><th><?= t('th_title') ?></th><th class="right"><?= t('th_action') ?></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td><?= e($u['email']) ?></td>
            <td><span class="tag tag-blue"><?= e(role_label($u['role'])) ?></span></td>
            <td><?= e($u['title']) ?></td>
            <td class="right" style="white-space:nowrap">
                <a class="btn btn-ghost btn-sm" href="<?= url('users.edit', ['id' => $u['id']]) ?>"><?= t('btn_edit') ?></a>
                <?php if ((int) $u['id'] !== (int) ($auth->user()['id'] ?? 0)): ?>
                    <form method="post" action="<?= url('users.delete') ?>" style="display:inline" onsubmit="return confirm('?')">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><?= t('btn_delete') ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
