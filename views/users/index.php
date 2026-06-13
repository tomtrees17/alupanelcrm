<?php /** @var array $users */ /** @var Auth $auth */ ?>
<div class="page-head">
    <h1>用户管理</h1>
    <a class="btn btn-primary" href="<?= url('users.create') ?>">＋ 新建用户</a>
</div>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>姓名</th><th>邮箱</th><th>角色</th><th>职位</th><th class="right">操作</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= e($u['name']) ?></strong></td>
            <td><?= e($u['email']) ?></td>
            <td><span class="tag tag-blue"><?= e(role_label($u['role'])) ?></span></td>
            <td><?= e($u['title']) ?></td>
            <td class="right" style="white-space:nowrap">
                <a class="btn btn-ghost btn-sm" href="<?= url('users.edit', ['id' => $u['id']]) ?>">编辑</a>
                <?php if ((int) $u['id'] !== (int) ($auth->user()['id'] ?? 0)): ?>
                    <form method="post" action="<?= url('users.delete') ?>" style="display:inline" onsubmit="return confirm('删除该用户？')">
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)">删除</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
