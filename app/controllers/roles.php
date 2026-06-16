<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

if (!$auth->isAdmin()) {
    http_response_code(403);
    flash('只有管理员可以设置权限。', 'error');
    redirect('dashboard.index');
}

switch ($action) {
    case 'index':
        $perms = [];
        foreach ($pdo->query('SELECT role, module FROM role_permissions') as $r) {
            $perms[$r['role']][$r['module']] = true;
        }
        view('roles.index', ['perms' => $perms]);
        break;

    case 'save':
        Csrf::verify();
        $sel = (array) input('perm', []);   // perm[role][] = module
        $roles = array_values(array_diff(all_roles(), ['admin']));
        $mods = controllable_modules();

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM role_permissions');
        $ins = $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?, ?)');
        foreach ($sel as $role => $modules) {
            if (!in_array($role, $roles, true)) {
                continue;
            }
            foreach ((array) $modules as $m) {
                if (in_array($m, $mods, true)) {
                    $ins->execute([$role, $m]);
                }
            }
        }
        $pdo->commit();
        flash('权限已保存。');
        redirect('roles.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
