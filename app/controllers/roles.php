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
        $mods = permission_keys();

        // Snapshot before wiping, so the audit entry can name what actually changed.
        $before = [];
        foreach ($pdo->query('SELECT role, module FROM role_permissions') as $r) {
            $before[$r['role']][] = $r['module'];
        }

        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM role_permissions');
        $ins = $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role, module) VALUES (?, ?)');
        $after = [];
        foreach ($sel as $role => $modules) {
            if (!in_array($role, $roles, true)) {
                continue;
            }
            foreach ((array) $modules as $m) {
                if (in_array($m, $mods, true)) {
                    $ins->execute([$role, $m]);
                    $after[$role][] = $m;
                }
            }
        }
        $pdo->commit();

        $changes = [];
        foreach ($roles as $role) {
            $was = $before[$role] ?? [];
            $now = $after[$role] ?? [];
            $granted = array_diff($now, $was);
            $revoked = array_diff($was, $now);
            if (!$granted && !$revoked) {
                continue;
            }
            $parts = [];
            if ($granted) {
                $parts[] = '授予 ' . implode('/', array_map(fn($m) => t('nav_' . $m), $granted));
            }
            if ($revoked) {
                $parts[] = '收回 ' . implode('/', array_map(fn($m) => t('nav_' . $m), $revoked));
            }
            $changes[] = role_label($role) . ': ' . implode('，', $parts);
        }
        audit($pdo, 'roles', 'permission', 'role_permissions', null, '权限矩阵', $changes ? implode('; ', $changes) : '(无变化)');
        flash('权限已保存。');
        redirect('roles.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
