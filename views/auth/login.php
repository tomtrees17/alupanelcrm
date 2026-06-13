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
        <div class="logo-mark" style="font-size:24px"><?= e($cfg['brand']) ?><span>CRM</span></div>
        <p>铝塑板业务管理系统 · ACP Sales Platform</p>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= url('auth.authenticate') ?>">
        <?= Csrf::field() ?>
        <div class="form-group">
            <label>邮箱</label>
            <input class="form-input" type="email" name="email" value="admin@alupanel.local" required autofocus>
        </div>
        <div class="form-group">
            <label>密码</label>
            <input class="form-input" type="password" name="password" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">登录</button>
    </form>
    <p class="login-hint">
        默认管理员：admin@alupanel.local / admin123<br>
        其他角色：sari@（主管）· mutiara@（经理）· ahmad@（销售）· joko@（仓库），密码同上
    </p>
</div>
</body>
</html>
