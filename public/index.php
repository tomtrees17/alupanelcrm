<?php
declare(strict_types=1);

/**
 * Front controller. All requests route through here:
 *   index.php?r=controller.action
 */

require __DIR__ . '/../app/bootstrap.php';

$route = (string) ($_GET['r'] ?? 'dashboard.index');
[$controller, $action] = array_pad(explode('.', $route, 2), 2, 'index');

$controller = preg_replace('/[^a-z0-9_]/', '', strtolower($controller));
$action     = preg_replace('/[^a-zA-Z0-9_]/', '', $action);

// Routes reachable without logging in.
$publicRoutes = ['auth.login', 'auth.authenticate', 'lang.set'];

if (!in_array("{$controller}.{$action}", $publicRoutes, true) && !$auth->check()) {
    redirect('auth.login');
}

// Force a password change for accounts still on a default/temporary password.
if ($auth->check() && (int) ($auth->user()['must_change_password'] ?? 0) === 1) {
    $pwExempt = ['account.password', 'account.update_password', 'auth.logout', 'lang.set'];
    if (!in_array("{$controller}.{$action}", $pwExempt, true)) {
        redirect('account.password');
    }
}

// Module access control by role (configurable by admin under 权限设置).
$moduleForAccess = ['delivery' => 'orders'][$controller] ?? $controller;
if (in_array($moduleForAccess, controllable_modules(), true) && !can_access($moduleForAccess)) {
    http_response_code(403);
    flash('无权访问该模块 / Tidak punya akses.', 'error');
    redirect('dashboard.index');
}

$file = __DIR__ . "/../app/controllers/{$controller}.php";

if (!is_file($file)) {
    http_response_code(404);
    view('errors.404', [], false);
    exit;
}

// Controller files act on $action, $pdo, $auth, $config.
require $file;
