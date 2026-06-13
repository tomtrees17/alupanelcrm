<?php /** @var array $customers */ /** @var string $q */ ?>
<div class="page-head">
    <h1>客户</h1>
    <a class="btn btn-primary" href="<?= url('customers.create') ?>">+ 新建客户</a>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="customers.index">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="搜索公司 / 联系人 / 邮箱 / 城市">
    <button class="btn" type="submit">搜索</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= url('customers.index') ?>">清除</a><?php endif; ?>
</form>

<div class="card">
    <table class="table">
        <thead>
        <tr><th>公司</th><th>联系人</th><th>电话</th><th>城市</th><th>邮箱</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$customers): ?>
            <tr><td colspan="6" class="empty">暂无客户</td></tr>
        <?php endif; ?>
        <?php foreach ($customers as $c): ?>
            <tr>
                <td><a href="<?= url('customers.show', ['id' => $c['id']]) ?>"><?= e($c['company']) ?></a></td>
                <td><?= e($c['contact_name']) ?></td>
                <td><?= e($c['phone']) ?></td>
                <td><?= e($c['city']) ?></td>
                <td><?= e($c['email']) ?></td>
                <td class="right">
                    <a class="btn btn-ghost btn-sm" href="<?= url('customers.edit', ['id' => $c['id']]) ?>">编辑</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
