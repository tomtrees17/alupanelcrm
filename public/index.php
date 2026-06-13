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

$file = __DIR__ . "/../app/controllers/{$controller}.php";

if (!is_file($file)) {
    http_response_code(404);
    view('errors.404', [], false);
    exit;
}

// Controller files act on $action, $pdo, $auth, $config.
require $file;
