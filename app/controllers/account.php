<?php
declare(strict_types=1);

/**
 * Self-service account settings (any logged-in user).
 * Currently: change own password (also the target of the forced-change gate).
 *
 * @var string $action
 * @var PDO    $pdo
 * @var Auth   $auth
 */

switch ($action) {
    case 'password':
        render_password_form($auth);
        break;

    case 'update_password':
        Csrf::verify();
        $user    = $auth->user();
        $current = (string) input('current_password', '');
        $new     = (string) input('new_password', '');
        $confirm = (string) input('confirm_password', '');

        $error = null;
        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = t('pwd_err_current');
        } elseif (mb_strlen($new) < 8) {
            $error = t('pwd_err_short');
        } elseif ($new !== $confirm) {
            $error = t('pwd_err_mismatch');
        } elseif (password_verify($new, (string) $user['password_hash'])) {
            $error = t('pwd_err_same');
        }

        if ($error !== null) {
            render_password_form($auth, $error);
            break;
        }

        $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        flash(t('pwd_changed'));
        redirect('dashboard.index');
        break;

    default:
        http_response_code(404);
        echo 'Not found';
}

function render_password_form(Auth $auth, ?string $error = null): void
{
    view('account.password', [
        'pageTitle' => t('change_password'),
        'pageSub'   => '',
        'forced'    => (int) ($auth->user()['must_change_password'] ?? 0) === 1,
        'error'     => $error,
    ]);
}
