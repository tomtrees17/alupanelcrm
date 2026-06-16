<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

if (!$auth->isAdmin()) {
    http_response_code(403);
    flash('只有管理员可以管理用户。', 'error');
    redirect('dashboard.index');
}

$roles = all_roles();

switch ($action) {
    case 'index':
        $users = $pdo->query('SELECT id,name,email,role,title,created_at FROM users ORDER BY id')->fetchAll();
        view('users.index', ['users' => $users]);
        break;

    case 'create':
        view('users.form', ['pageTitle' => '新建用户', 'pageSub' => '', 'user' => null, 'roles' => $roles]);
        break;

    case 'store':
        Csrf::verify();
        $name = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role = in_array(input('role'), $roles, true) ? input('role') : 'sales';
        $title = trim((string) input('title', ''));
        $pass = (string) input('password', '');
        if ($name === '' || $email === '' || strlen($pass) < 6) {
            flash('姓名、邮箱必填，密码至少 6 位。', 'error');
            redirect('users.create');
        }
        try {
            $pdo->prepare('INSERT INTO users (name,email,password_hash,role,title) VALUES (?,?,?,?,?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $title]);
        } catch (PDOException $e) {
            flash('该邮箱已被使用。', 'error');
            redirect('users.create');
        }
        flash('用户已创建。');
        redirect('users.index');
        break;

    case 'edit':
        view('users.form', ['pageTitle' => '编辑用户', 'pageSub' => '', 'user' => find_user($pdo, (int) input('id', 0)), 'roles' => $roles]);
        break;

    case 'update':
        Csrf::verify();
        $user = find_user($pdo, (int) input('id', 0));
        $name = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role = in_array(input('role'), $roles, true) ? input('role') : $user['role'];
        $title = trim((string) input('title', ''));
        $pass = (string) input('password', '');
        if ($name === '' || $email === '') {
            flash('姓名、邮箱必填。', 'error');
            redirect('users.edit', ['id' => $user['id']]);
        }
        if ($pass !== '') {
            $pdo->prepare('UPDATE users SET name=?,email=?,role=?,title=?,password_hash=? WHERE id=?')
                ->execute([$name, $email, $role, $title, password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name=?,email=?,role=?,title=? WHERE id=?')
                ->execute([$name, $email, $role, $title, $user['id']]);
        }
        flash('用户已更新。');
        redirect('users.index');
        break;

    case 'delete':
        Csrf::verify();
        $user = find_user($pdo, (int) input('id', 0));
        if ((int) $user['id'] === (int) ($auth->user()['id'] ?? 0)) {
            flash('不能删除当前登录账户。', 'error');
            redirect('users.index');
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
        flash('用户已删除。');
        redirect('users.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function find_user(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('用户不存在');
    }
    return $row;
}
