<?php /** @var ?string $error */ $cfg = $GLOBALS['config']; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 · <?= e($cfg['app_name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <span class="logo">▰</span>
        <h1><?= e($cfg['company']) ?> CRM</h1>
        <p>铝标识板业务管理系统</p>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= url('auth.authenticate') ?>">
        <?= Csrf::field() ?>
        <label>邮箱
            <input type="email" name="email" value="admin@alupanel.local" required autofocus>
        </label>
        <label>密码
            <input type="password" name="password" placeholder="••••••••" required>
        </label>
        <button type="submit" class="btn btn-primary btn-block">登录</button>
    </form>
    <p class="login-hint">默认账号：admin@alupanel.local / admin123</p>
</div>
</body>
</html>
