<?php
declare(strict_types=1);

/**
 * Application bootstrap: session, config, core services.
 * Included by public/index.php on every request.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/../config.php';

require __DIR__ . '/helpers.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/Csrf.php';
require __DIR__ . '/Auth.php';

// Make config globally reachable to helpers/views.
$GLOBALS['config'] = $config;

$pdo  = Database::connect($config['db_path']);
Database::migrate($pdo);

$auth = new Auth($pdo);

// Expose core services to views (which run in function scope).
$GLOBALS['auth'] = $auth;
$GLOBALS['pdo']  = $pdo;
