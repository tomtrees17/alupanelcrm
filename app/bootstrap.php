<?php
declare(strict_types=1);

/**
 * Application bootstrap: session, config, core services.
 * Included by public/index.php on every request.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before the session is created.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')   // behind reverse proxy (Baota / Nginx)
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    ini_set('session.use_strict_mode', '1');   // reject attacker-fixated session ids
    ini_set('session.use_only_cookies', '1');  // never accept the id from the URL
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,        // not reachable from JavaScript (mitigates XSS theft)
        'secure'   => $isHttps,    // HTTPS-only when served over TLS
        'samesite' => 'Lax',       // mitigates CSRF on top of the token check
    ]);
    session_name('ALUPANELSESS');
    session_start();
}

$config = require __DIR__ . '/../config.php';

require __DIR__ . '/i18n.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/domain.php';
require __DIR__ . '/Export.php';
require __DIR__ . '/Word.php';
require __DIR__ . '/Docx.php';
require __DIR__ . '/Notify.php';
require __DIR__ . '/Claude.php';
require __DIR__ . '/AiTask.php';
require __DIR__ . '/Bank.php';
require __DIR__ . '/Faktur.php';
require __DIR__ . '/Ai.php';
require __DIR__ . '/Dashboard.php';
require __DIR__ . '/Enrich.php';
require __DIR__ . '/CustomerData.php';
require __DIR__ . '/Insights.php';
require __DIR__ . '/Hr.php';
require __DIR__ . '/HrContract.php';
require __DIR__ . '/HrBpjs.php';
require __DIR__ . '/Visits.php';
require __DIR__ . '/SalesHistory.php';
require __DIR__ . '/CoNumbers.php';
require __DIR__ . '/OrderParse.php';
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

// Load role → module permissions for access control.
$permissions = [];
foreach ($pdo->query('SELECT role, module FROM role_permissions') as $row) {
    $permissions[$row['role']][] = $row['module'];
}
$GLOBALS['permissions'] = $permissions;
