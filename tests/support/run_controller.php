<?php
declare(strict_types=1);

/**
 * Test helper (not a test itself — the runner only picks up *_test.php):
 * renders one controller action against a given SQLite file and prints the
 * HTML. Controllers declare functions, so a second include in the same
 * process would fatal; tests call this once per render via a subprocess.
 *
 *   php run_controller.php <db-file> <controller> <action> <json-get> <json-user> [<json-post>]
 *
 * With <json-post> the request is a POST carrying a valid CSRF token. Flash
 * messages are appended to the output as <!--FLASH:[...]--> so tests can see
 * the outcome of an action that ends in redirect().
 */

if (PHP_SAPI !== 'cli' || $argc < 6) {
    exit(64);
}

require __DIR__ . '/../../app/i18n.php';
require __DIR__ . '/../../app/helpers.php';
require __DIR__ . '/../../app/domain.php';
require __DIR__ . '/../../app/Database.php';
require __DIR__ . '/../../app/Csrf.php';
foreach (['Notify', 'Claude', 'Ai', 'Dashboard', 'Export', 'Word', 'Enrich', 'CustomerData'] as $cls) {
    if (is_file(__DIR__ . "/../../app/{$cls}.php")) {
        require_once __DIR__ . "/../../app/{$cls}.php";
    }
}

final class AuthStub
{
    public function __construct(public array $u) {}
    public function check(): bool { return $this->u !== []; }
    public function user(): ?array { return $this->u ?: null; }
    public function isAdmin(): bool { return ($this->u['role'] ?? '') === 'admin'; }
}

[, $dbFile, $controller, $action, $getJson, $userJson] = $argv;
if (!preg_match('/^[a-z]+$/', $controller)) {
    exit(64);
}
$_SESSION = [];
$_GET = (array) json_decode($getJson, true);
$_SERVER['REQUEST_METHOD'] = 'GET';
if (isset($argv[6])) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = (array) json_decode($argv[6], true) + ['_csrf' => Csrf::token()];
}
register_shutdown_function(function (): void {
    echo "\n<!--FLASH:" . json_encode($_SESSION['flash'] ?? [], JSON_UNESCAPED_UNICODE) . "-->";
});
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$config = (array) (require __DIR__ . '/../../config.php');
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$auth = new AuthStub((array) json_decode($userJson, true));
$GLOBALS['config'] = $config;
$GLOBALS['pdo'] = $pdo;
$GLOBALS['auth'] = $auth;
$GLOBALS['permissions'] = load_permissions_for_tests($pdo);

// Any notice/warning is a failure: print it where the test will see it.
set_error_handler(function (int $no, string $msg, string $f = '', int $l = 0): bool {
    fwrite(STDERR, "PHP: {$msg} @ " . basename($f) . ":{$l}\n");
    return true;
});
include __DIR__ . '/../../app/controllers/' . $controller . '.php';

function load_permissions_for_tests(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT role, module FROM role_permissions') as $r) {
        $out[$r['role']][] = $r['module'];
    }
    return $out;
}
