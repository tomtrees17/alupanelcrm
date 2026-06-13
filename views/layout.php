<?php
/** @var string $content */
/** @var Auth $auth */
$cfg  = $GLOBALS['config'];
$pdo  = $GLOBALS['pdo'];
$cur  = $_GET['r'] ?? 'dashboard.index';
$user = $auth->user();
$active = fn(string $p) => str_starts_with($cur, $p) ? ' active' : '';

$pendingTasks  = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE done = 0')->fetchColumn();
$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status LIKE 'pending_%'")->fetchColumn();
$initial = mb_substr($user['name'] ?? '?', 0, 1);
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
<div class="app">
    <aside class="sidebar">
        <div class="logo">
            <div class="logo-mark"><?= e($cfg['brand']) ?><span>CRM</span></div>
            <div class="logo-sub"><?= e($cfg['tagline']) ?></div>
        </div>
        <nav class="nav">
            <div class="nav-label">主菜单</div>
            <a class="nav-item<?= $active('dashboard') ?>" href="<?= url('dashboard.index') ?>"><span class="nav-icon">⬡</span> 数据看板</a>
            <a class="nav-item<?= $active('customers') ?>" href="<?= url('customers.index') ?>"><span class="nav-icon">◎</span> 客户管理</a>
            <a class="nav-item<?= $active('pipeline') ?>" href="<?= url('pipeline.index') ?>"><span class="nav-icon">⟋</span> 销售漏斗</a>
            <a class="nav-item<?= $active('tasks') ?>" href="<?= url('tasks.index') ?>"><span class="nav-icon">◻</span> 任务提醒<?php if ($pendingTasks): ?><span class="nav-badge"><?= $pendingTasks ?></span><?php endif; ?></a>
            <a class="nav-item<?= $active('finance') ?>" href="<?= url('finance.index') ?>"><span class="nav-icon">◈</span> 财务管理</a>
            <div class="nav-label">扩展功能</div>
            <a class="nav-item<?= $active('orders') ?>" href="<?= url('orders.index') ?>"><span class="nav-icon">✦</span> 订单审批<?php if ($pendingOrders): ?><span class="nav-badge" style="background:var(--warning)"><?= $pendingOrders ?></span><?php endif; ?></a>
            <a class="nav-item<?= $active('inventory') ?>" href="<?= url('inventory.index') ?>"><span class="nav-icon">▣</span> 库存管理</a>
            <?php if ($auth->isAdmin()): ?>
                <a class="nav-item<?= $active('users') ?>" href="<?= url('users.index') ?>"><span class="nav-icon">⚙</span> 用户管理</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= e($initial) ?></div>
                <div>
                    <div class="user-name"><?= e($user['name'] ?? '') ?></div>
                    <div class="user-role"><?= e($user['title'] ?? role_label($user['role'] ?? '')) ?></div>
                    <a class="user-logout" href="<?= url('auth.logout') ?>">退出登录</a>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <div class="topbar-title"><?= e($pageTitle ?? $cfg['app_name']) ?></div>
                <div class="topbar-sub"><?= e($pageSub ?? '') ?></div>
            </div>
            <div class="ml-auto"></div>
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
