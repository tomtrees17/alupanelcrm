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
        $email    = trim((string) input('email', ''));
        $password = (string) input('password', '');

        if ($auth->attempt($email, $password)) {
            flash(t('welcome_back') . '，' . ($auth->user()['name'] ?? ''));
            redirect('dashboard.index');
        }
        view('auth.login', ['error' => '邮箱或密码不正确。'], false);
        break;

    case 'logout':
        $auth->logout();
        redirect('auth.login');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
