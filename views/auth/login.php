<?php /** @var ?string $error */ $cfg = $GLOBALS['config']; $lang = current_lang(); ?>
<!DOCTYPE html>
<html lang="<?= $lang === 'id' ? 'id' : 'zh-CN' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('login_title') ?> · <?= e($cfg['app_name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-brand">
        <div class="logo-mark" style="font-size:24px"><?= e($cfg['brand']) ?><span>CRM</span></div>
        <p><?= t('app_tagline') ?> · ACP Sales Platform</p>
    </div>
    <div class="lang-toggle" style="margin:0 0 18px">
        <a class="lang-btn <?= $lang === 'zh' ? 'active' : '' ?>" href="<?= url('lang.set', ['lang' => 'zh']) ?>">中文</a>
        <a class="lang-btn <?= $lang === 'id' ? 'active' : '' ?>" href="<?= url('lang.set', ['lang' => 'id']) ?>">Indonesia</a>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= url('auth.authenticate') ?>">
        <?= Csrf::field() ?>
        <div class="form-group">
            <label><?= t('login_email') ?></label>
            <input class="form-input" type="email" name="email" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
            <label><?= t('login_password') ?></label>
            <input class="form-input" type="password" name="password" placeholder="••••••••" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= t('login_btn') ?></button>
    </form>
</div>
</body>
</html>
