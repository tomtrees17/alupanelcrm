<?php
/** @var string $content */
/** @var Auth $auth */
$cfg  = $GLOBALS['config'];
$pdo  = $GLOBALS['pdo'];
$cur  = $_GET['r'] ?? 'dashboard.index';
$user = $auth->user();
$module = explode('.', $cur)[0];
$active = fn(string $p) => str_starts_with($cur, $p) ? ' active' : '';
$lang = current_lang();

// Brand logo image (png overrides the committed svg); falls back to the text mark.
$logoFile = null;
foreach (['logo.png', 'logo.svg', 'logo.jpg'] as $cand) {
    if (is_file(__DIR__ . '/../public/assets/img/' . $cand)) { $logoFile = $cand; break; }
}

$pendingTasks  = (int) $pdo->query('SELECT COUNT(*) FROM tasks WHERE done = 0')->fetchColumn();
$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status LIKE 'pending_%'")->fetchColumn();
$pendingReqs = 0;
if (can_access('approvals')) {
    if (approvals_sees_all()) {
        $pendingReqs = (int) $pdo->query("SELECT COUNT(*) FROM admin_requests WHERE status LIKE 'pending_%'")->fetchColumn();
    } else {
        $pr = $pdo->prepare("SELECT COUNT(*) FROM admin_requests WHERE status LIKE 'pending_%' AND applicant = ?");
        $pr->execute([own_name()]);
        $pendingReqs = (int) $pr->fetchColumn();
    }
}
$initial = mb_substr($user['name'] ?? '?', 0, 1);

// Title: explicit override (dynamic names) else route-based translation.
$title = $pageTitle ?? (I18N[$lang]['page_' . $module] ?? $cfg['app_name']);
$sub   = $pageSub ?? (I18N[$lang]['sub_' . $module] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'id' ? 'id' : 'zh-CN' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($cfg['app_name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#00a884">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= e($cfg['brand']) ?>">
    <link rel="apple-touch-icon" href="assets/img/app-icon.svg">
</head>
<body>
<div class="app">
    <div class="sidebar-backdrop" id="navBackdrop"></div>
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <?php if ($logoFile): ?>
                <img class="logo-img" src="assets/img/<?= e($logoFile) ?>" alt="<?= e($cfg['company_logo'] ?? $cfg['brand']) ?>">
            <?php else: ?>
                <div class="logo-mark"><?= e($cfg['brand']) ?><span>CRM</span></div>
            <?php endif; ?>
            <div class="logo-sub"><?= e($cfg['tagline']) ?></div>
        </div>
        <div class="lang-toggle">
            <a class="lang-btn <?= $lang === 'zh' ? 'active' : '' ?>" href="<?= url('lang.set', ['lang' => 'zh']) ?>">中文</a>
            <a class="lang-btn <?= $lang === 'id' ? 'active' : '' ?>" href="<?= url('lang.set', ['lang' => 'id']) ?>">Indonesia</a>
        </div>
        <nav class="nav">
            <div class="nav-label"><?= t('nav_main') ?></div>
            <a class="nav-item<?= $active('dashboard') ?>" href="<?= url('dashboard.index') ?>"><span class="nav-icon">⬡</span> <?= t('nav_dashboard') ?></a>
            <?php if (can_access('customers')): ?><a class="nav-item<?= $active('customers') ?>" href="<?= url('customers.index') ?>"><span class="nav-icon">◎</span> <?= t('nav_customers') ?></a><?php endif; ?>
            <?php if (can_access('pipeline')): ?><a class="nav-item<?= $active('pipeline') ?>" href="<?= url('pipeline.index') ?>"><span class="nav-icon">⟋</span> <?= t('nav_pipeline') ?></a><?php endif; ?>
            <?php if (can_access('tasks')): ?><a class="nav-item<?= $active('tasks') ?>" href="<?= url('tasks.index') ?>"><span class="nav-icon">◻</span> <?= t('nav_tasks') ?><?php if ($pendingTasks): ?><span class="nav-badge"><?= $pendingTasks ?></span><?php endif; ?></a><?php endif; ?>
            <?php if (can_access('finance')): ?><a class="nav-item<?= $active('finance') ?>" href="<?= url('finance.index') ?>"><span class="nav-icon">◈</span> <?= t('nav_finance') ?></a><?php endif; ?>
            <div class="nav-label"><?= t('nav_extra') ?></div>
            <?php if (can_access('orders')): ?><a class="nav-item<?= $active('orders') ?>" href="<?= url('orders.index') ?>"><span class="nav-icon">✦</span> <?= t('nav_orders') ?><?php if ($pendingOrders): ?><span class="nav-badge" style="background:var(--warning)"><?= $pendingOrders ?></span><?php endif; ?></a><?php endif; ?>
            <?php if (can_access('inventory')): ?><a class="nav-item<?= $active('inventory') ?>" href="<?= url('inventory.index') ?>"><span class="nav-icon">▣</span> <?= t('nav_inventory') ?></a><?php endif; ?>
            <?php if (can_access('approvals')): ?><a class="nav-item<?= $active('approvals') ?>" href="<?= url('approvals.index') ?>"><span class="nav-icon">✎</span> <?= t('nav_approvals') ?><?php if ($pendingReqs): ?><span class="nav-badge" style="background:var(--warning)"><?= $pendingReqs ?></span><?php endif; ?></a><?php endif; ?>
            <?php if (can_access('audit')): ?><a class="nav-item<?= $active('audit') ?>" href="<?= url('audit.index') ?>"><span class="nav-icon">☰</span> <?= t('nav_audit') ?></a><?php endif; ?>
            <?php if ($auth->isAdmin()): ?>
                <a class="nav-item<?= $active('users') ?>" href="<?= url('users.index') ?>"><span class="nav-icon">⚙</span> <?= t('nav_users') ?></a>
                <a class="nav-item<?= $active('roles') ?>" href="<?= url('roles.index') ?>"><span class="nav-icon">⛨</span> <?= t('nav_roles') ?></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= e($initial) ?></div>
                <div>
                    <div class="user-name"><?= e($user['name'] ?? '') ?></div>
                    <div class="user-role"><?= e($user['title'] ?? role_label($user['role'] ?? '')) ?></div>
                    <a class="user-logout" href="<?= url('account.password') ?>"><?= t('change_password') ?></a>
                    <a class="user-logout" href="<?= url('auth.logout') ?>"><?= t('logout') ?></a>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <button class="nav-toggle" id="navToggle" type="button" aria-label="<?= t('nav_main') ?>" aria-expanded="false">☰</button>
            <div>
                <div class="topbar-title"><?= e($title) ?></div>
                <div class="topbar-sub"><?= e($sub) ?></div>
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
<script>
(function () {
    var t = document.getElementById('navToggle');
    var sb = document.getElementById('sidebar');
    var bd = document.getElementById('navBackdrop');
    if (!t || !sb) return;
    function set(open) {
        sb.classList.toggle('open', open);
        if (bd) bd.classList.toggle('show', open);
        t.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    t.addEventListener('click', function () { set(!sb.classList.contains('open')); });
    if (bd) bd.addEventListener('click', function () { set(false); });
    sb.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { set(false); }); });
})();
</script>
</body>
</html>
