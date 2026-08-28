<?php
declare(strict_types=1);

/** @var string $action */
/** @var Auth $auth */

switch ($action) {
    case 'login':
        if ($auth->check()) {
            redirect('dashboard.index');
        }
        view('auth.login', ['error' => null], false);
        break;

    case 'authenticate':
        if (!is_post()) {
            redirect('auth.login');
        }
        Csrf::verify();

        $locked = login_lockout_minutes($pdo);
        if ($locked > 0) {
            view('auth.login', ['error' => sprintf(t('login_locked'), $locked)], false);
            break;
        }

        $email    = trim((string) input('email', ''));
        $password = (string) input('password', '');

        if ($auth->attempt($email, $password)) {
            login_clear_failures($pdo);
            audit($pdo, 'auth', 'login', 'user', (int) ($auth->user()['id'] ?? 0), $email, '登录成功');
            flash(t('welcome_back') . '，' . ($auth->user()['name'] ?? ''));
            redirect('dashboard.index');
        }
        login_record_failure($pdo, $email);
        // No actor: recorded against the attempted address + client IP.
        audit($pdo, 'auth', 'login_failed', 'user', null, $email, '密码错误或账号不存在');
        view('auth.login', ['error' => t('login_failed')], false);
        break;

    case 'logout':
        audit($pdo, 'auth', 'logout', 'user', (int) ($auth->user()['id'] ?? 0), (string) ($auth->user()['email'] ?? ''), '');
        $auth->logout();
        redirect('auth.login');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
