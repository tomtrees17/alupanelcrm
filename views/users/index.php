<?php /** @var array $users */ /** @var Auth $auth */ ?>
<div class="page-head">
    <h1>用户</h1>
    <a class="btn btn-primary" href="<?= url('users.create') ?>">+ 新建用户</a>
</div>

<div class="card">
    <table class="table">
        <thead><tr><th>姓名</th><th>邮箱</th><th>角色</th><th>创建时间</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><span class="badge badge-<?= $u['role'] === 'admin' ? 'accepted' : 'sent' ?>"><?= $u['role'] === 'admin' ? '管理员' : '销售' ?></span></td>
                <td><?= e($u['created_at']) ?></td>
                <td class="right">
                    <a class="btn btn-ghost btn-sm" href="<?= url('users.edit', ['id' => $u['id']]) ?>">编辑</a>
                    <?php if ($u['id'] !== ($auth->user()['id'] ?? null)): ?>
                        <form method="post" action="<?= url('users.delete') ?>" class="inline" onsubmit="return confirm('确定删除该用户？')">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button class="btn btn-ghost btn-sm danger-text" type="submit">删除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
