<?php
/** @var string $content */
/** @var Auth $auth */
$cfg  = $GLOBALS['config'];
$cur  = $_GET['r'] ?? 'dashboard.index';
$nav  = function (string $prefix) use ($cur): string {
    return str_starts_with($cur, $prefix) ? ' class="active"' : '';
};
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($cfg['app_name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <span class="logo">▰</span>
            <div>
                <strong><?= e($cfg['company']) ?></strong>
                <small>CRM</small>
            </div>
        </div>
        <nav>
            <a href="<?= url('dashboard.index') ?>"<?= $nav('dashboard') ?>>仪表盘</a>
            <a href="<?= url('customers.index') ?>"<?= $nav('customers') ?>>客户</a>
            <a href="<?= url('quotes.index') ?>"<?= $nav('quotes') ?>>报价 / 订单</a>
            <a href="<?= url('products.index') ?>"<?= $nav('products') ?>>产品</a>
            <?php if ($auth->isAdmin()): ?>
                <a href="<?= url('users.index') ?>"<?= $nav('users') ?>>用户</a>
            <?php endif; ?>
        </nav>
    </aside>

    <main class="main">
        <header class="topbar">
            <div></div>
            <div class="user-box">
                <span><?= e($auth->user()['name'] ?? '') ?> · <?= e($auth->user()['role'] ?? '') ?></span>
                <a class="btn btn-ghost btn-sm" href="<?= url('auth.logout') ?>">退出</a>
            </div>
        </header>

        <div class="content">
            <?php foreach (flash() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>
    </main>
</div>
</body>
</html>
