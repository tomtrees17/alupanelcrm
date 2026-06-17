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
            flash(t('welcome_back') . '，' . ($auth->user()['name'] ?? ''));
            redirect('dashboard.index');
        }
        login_record_failure($pdo, $email);
        view('auth.login', ['error' => t('login_failed')], false);
        break;

    case 'logout':
        $auth->logout();
        redirect('auth.login');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}
