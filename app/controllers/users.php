<?php
declare(strict_types=1);

/** @var string $action */
/** @var PDO $pdo */
/** @var Auth $auth */

// User management is admin-only.
if (!$auth->isAdmin()) {
    http_response_code(403);
    flash('只有管理员可以管理用户。', 'error');
    redirect('dashboard.index');
}

switch ($action) {
    case 'index':
        $users = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY id')->fetchAll();
        view('users.index', ['users' => $users]);
        break;

    case 'create':
        view('users.form', ['user' => null]);
        break;

    case 'store':
        Csrf::verify();
        $name  = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role  = input('role') === 'admin' ? 'admin' : 'sales';
        $pass  = (string) input('password', '');

        if ($name === '' || $email === '' || strlen($pass) < 6) {
            flash('姓名、邮箱必填，密码至少 6 位。', 'error');
            redirect('users.create');
        }
        try {
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $e) {
            flash('该邮箱已被使用。', 'error');
            redirect('users.create');
        }
        flash('用户已创建。');
        redirect('users.index');
        break;

    case 'edit':
        view('users.form', ['user' => find_user($pdo, (int) input('id', 0))]);
        break;

    case 'update':
        Csrf::verify();
        $user  = find_user($pdo, (int) input('id', 0));
        $name  = trim((string) input('name', ''));
        $email = trim((string) input('email', ''));
        $role  = input('role') === 'admin' ? 'admin' : 'sales';
        $pass  = (string) input('password', '');

        if ($name === '' || $email === '') {
            flash('姓名、邮箱必填。', 'error');
            redirect('users.edit', ['id' => $user['id']]);
        }
        if ($pass !== '') {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, password_hash=? WHERE id=?')
                ->execute([$name, $email, $role, password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=? WHERE id=?')
                ->execute([$name, $email, $role, $user['id']]);
        }
        flash('用户已更新。');
        redirect('users.index');
        break;

    case 'delete':
        Csrf::verify();
        $user = find_user($pdo, (int) input('id', 0));
        if ($user['id'] === ($auth->user()['id'] ?? null)) {
            flash('不能删除当前登录的账户。', 'error');
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
