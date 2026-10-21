<?php

// ── Expired / forged form tokens: rejected, but the person lands back on a page ──
// Users saw a bare "CSRF token mismatch" page after leaving the login page open
// past the session lifetime.

require_once __DIR__ . '/../app/Csrf.php';

$saved = $_SERVER;
$_SERVER['HTTP_HOST'] = 'www.alupanel.cc';
$_SERVER['HTTP_REFERER'] = 'https://www.alupanel.cc/index.php?r=customers.show&id=5';
ok('back to the same-site page the form was on', Csrf::back_url() === '/index.php?r=customers.show&id=5');
$_SERVER['HTTP_REFERER'] = 'https://evil.example/index.php?r=customers.show&id=5';
ok('a foreign referrer is never followed', Csrf::back_url() === 'index.php');
$_SERVER['HTTP_REFERER'] = 'https://www.alupanel.cc/index.php?r=auth.authenticate';
ok('never back to the POST-only login endpoint', Csrf::back_url() === 'index.php');
unset($_SERVER['HTTP_REFERER']);
ok('no referrer → app root', Csrf::back_url() === 'index.php');
$_SERVER = $saved;
ok('message translated', isset(I18N['zh']['msg_csrf_expired'], I18N['id']['msg_csrf_expired']));

$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
    $db = tempnam(sys_get_temp_dir(), 'csrft');
    $p = new PDO('sqlite:' . $db);
    $p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
    $p->exec("INSERT INTO customers (id, name, owner) VALUES (1, 'Toko A', 'rina')");
    $p = null;
    $run = function (string $ctl, string $action, array $user, ?array $post, ?array $session = null, array $env = []) use ($db, $harness): array {
        $args = [PHP_BINARY, $harness, $db, $ctl, $action, '{}', json_encode($user), $post === null ? '' : json_encode($post)];
        if ($session !== null) {
            $args[] = json_encode($session);
        }
        $envs = '';
        foreach ($env as $k => $v) {
            $envs .= $k . '=' . escapeshellarg($v) . ' ';
        }
        exec($envs . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        $html = implode("\n", $out);
        preg_match('/<!--FLASH:(.*?)-->/s', $html, $fm);
        return [$html, json_decode($fm[1] ?? '[]', true) ?: []];
    };
    $admin = ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];
    [$h, $fl] = $run('customers', 'followup', $admin, ['id' => 1, 'note' => 'stale', '_csrf' => 'expired-token'],
        null, ['TEST_HTTP_HOST' => 'www.alupanel.cc', 'TEST_HTTP_REFERER' => 'https://www.alupanel.cc/index.php?r=customers.show&id=1']);
    ok('stale token: no dead-end page', !str_contains($h, 'CSRF token mismatch'));
    ok('stale token: a friendly message is queued', ($fl[0]['type'] ?? '') === 'error' && ($fl[0]['message'] ?? '') === t('msg_csrf_expired'));
    ok('stale token: nothing was written', (int) (new PDO('sqlite:' . $db))->query('SELECT COUNT(*) FROM customer_followups')->fetchColumn() === 0);
    [$h2] = $run('customers', 'followup', $admin, ['id' => 1, 'note' => 'fresh']);
    ok('a valid token still works', (int) (new PDO('sqlite:' . $db))->query('SELECT COUNT(*) FROM customer_followups')->fetchColumn() === 1);
    [$login] = $run('auth', 'login', [], null, ['flash' => [['type' => 'error', 'message' => t('msg_csrf_expired')]]]);
    ok('the login page shows the expired-page message', str_contains($login, e(t('msg_csrf_expired'))));
    [$login2] = $run('auth', 'login', [], null);
    ok('…and nothing when there is no message', !str_contains($login2, 'alert-error'));
    @unlink($db);
}
