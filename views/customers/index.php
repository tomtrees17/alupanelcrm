<?php /** @var array $customers */ /** @var string $q */ /** @var string $tag */ ?>
<div class="page-head">
    <h1>客户管理</h1>
    <a class="btn btn-primary" href="<?= url('customers.create') ?>">＋ 新建客户</a>
</div>

<form class="searchbar" method="get" action="index.php">
    <input type="hidden" name="r" value="customers.index">
    <input class="form-input" type="text" name="q" value="<?= e($q) ?>" placeholder="搜索姓名 / 公司 / 邮箱 / 城市">
    <select class="form-select filter-select" name="tag" onchange="this.form.submit()">
        <option value="">全部标签</option>
        <?php foreach (['重点', '潜在', '成交', '流失'] as $tg): ?>
            <option value="<?= $tg ?>" <?= $tag === $tg ? 'selected' : '' ?>><?= $tg ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">搜索</button>
</form>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>客户</th><th>公司</th><th>联系方式</th><th>城市</th><th>标签</th><th class="right">潜在价值</th></tr></thead>
            <tbody>
            <?php if (!$customers): ?><tr><td colspan="6" class="empty">暂无客户数据</td></tr><?php endif; ?>
            <?php foreach ($customers as $c): ?>
                <tr class="clickable" onclick="location.href='<?= url('customers.show', ['id' => $c['id']]) ?>'">
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td><?= e($c['company']) ?></td>
                    <td><?= e($c['phone']) ?></td>
                    <td><?= e($c['city']) ?></td>
                    <td><span class="tag <?= customer_tag_class($c['tag']) ?>"><?= e($c['tag']) ?></span></td>
                    <td class="right"><?= idr($c['value']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
