<?php
/** @var bool $forced */
/** @var ?string $error */
?>
<div class="card" style="max-width:480px">
    <div class="card-body">
        <?php if (!empty($forced)): ?>
            <div class="alert alert-error" style="margin-bottom:16px">
                <strong><?= t('pwd_force_title') ?></strong><br>
                <?= t('pwd_force_msg') ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= url('account.update_password') ?>">
            <?= Csrf::field() ?>
            <div class="form-group">
                <label><?= t('pwd_current') ?></label>
                <input class="form-input" type="password" name="current_password" required autofocus autocomplete="current-password">
            </div>
            <div class="form-group">
                <label><?= t('pwd_new') ?></label>
                <input class="form-input" type="password" name="new_password" required autocomplete="new-password">
                <div style="font-size:11px;color:var(--text3);margin-top:4px"><?= t('pwd_hint') ?></div>
            </div>
            <div class="form-group">
                <label><?= t('pwd_confirm') ?></label>
                <input class="form-input" type="password" name="confirm_password" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary"><?= t('change_password') ?></button>
        </form>
    </div>
</div>
