<?php
/** @var array $perms */   // $perms[role][module] = true
$roles = array_values(array_diff(all_roles(), ['admin']));
$mods = controllable_modules();
?>
<div class="page-head">
    <h1><?= t('page_roles') ?></h1>
</div>

<div class="card"><div class="card-body">
    <p class="muted" style="margin-bottom:14px"><?= t('perms_hint') ?></p>
    <form method="post" action="<?= url('roles.save') ?>">
        <?= Csrf::field() ?>
        <div class="table-wrap"><table>
            <thead>
            <tr>
                <th><?= t('th_role') ?></th>
                <?php foreach ($mods as $m): ?><th class="right"><?= t('nav_' . $m) ?></th><?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <tr style="background:var(--surface2)">
                <td><span class="tag tag-purple"><?= role_label('admin') ?></span></td>
                <?php foreach ($mods as $m): ?><td class="right" style="color:var(--accent)">✓</td><?php endforeach; ?>
            </tr>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td><strong><?= e(role_label($role)) ?></strong></td>
                    <?php foreach ($mods as $m): ?>
                        <td class="right">
                            <input type="checkbox" name="perm[<?= e($role) ?>][]" value="<?= e($m) ?>"
                                   style="width:auto" <?= !empty($perms[$role][$m]) ? 'checked' : '' ?>>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= t('btn_save_perms') ?></button></div>
    </form>
</div></div>
