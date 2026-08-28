<?php
declare(strict_types=1);

/**
 * Zero-dependency test runner.
 *
 *   php tools/run_tests.php
 *
 * Every test runs against an in-memory SQLite database, so it never touches
 * data/crm.sqlite and is safe to run on the server after a deploy.
 * Exits non-zero if anything fails (usable in a pre-deploy check).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

require __DIR__ . '/../app/i18n.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/domain.php';
require __DIR__ . '/../app/Database.php';

$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$GLOBALS['config'] = ['currency' => 'Rp', 'ppn_rate' => 11, 'brand' => 'AluPanel'];
$GLOBALS['permissions'] = [];

/** Stand-in for Auth: the tests only need check()/user()/isAdmin(). */
final class AuthStub
{
    public array $u;

    public function __construct(array $u)
    {
        $this->u = $u;
    }

    public function check(): bool
    {
        return $this->u !== [];
    }

    public function user(): ?array
    {
        return $this->u ?: null;
    }

    public function isAdmin(): bool
    {
        return ($this->u['role'] ?? '') === 'admin';
    }
}

$pass = 0;
$fail = 0;

function ok(string $what, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok    {$what}\n";
    } else {
        $fail++;
        echo "  FAIL  {$what}" . ($extra !== '' ? "  [{$extra}]" : '') . "\n";
    }
}

foreach (glob(__DIR__ . '/../tests/*_test.php') ?: [] as $file) {
    echo "\n── " . basename($file) . " ──\n";
    require $file;
}

echo "\n" . ($fail === 0 ? "ALL {$pass} CHECKS PASSED\n" : "{$pass} passed, {$fail} FAILED\n");
exit($fail === 0 ? 0 : 1);
