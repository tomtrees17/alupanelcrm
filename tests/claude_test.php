<?php

// ── Spending guards on every AI call (app/Claude.php) + .env secrets ──

$calls = [];
$queue = [];
$slept = [];
Claude::$http = function (string $url, string $body, array $headers) use (&$calls, &$queue): array {
    $calls[] = ['body' => json_decode($body, true), 'headers' => $headers];
    return array_shift($queue) ?? [500, 'queue empty', []];
};
Claude::$sleep = function (float $s) use (&$slept): void {
    $slept[] = $s;
};
$ok = fn(string $text = '{"a":1}', string $stop = 'end_turn'): array => [200, json_encode([
    'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]],
    'stop_reason' => $stop, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
]), []];
$cfg = ['ai' => ['key' => 'sk-test', 'max_tokens' => 1000, 'retries' => 2, 'max_input_chars' => 5000]];
$reset = function () use (&$calls, &$queue, &$slept): void {
    $calls = [];
    $queue = [];
    $slept = [];
};

// 1) Output cap is a ceiling, never a suggestion.
$queue = [$ok()];
[$json, $err, $usage, $attempts] = Claude::send($cfg, ['model' => 'm', 'max_tokens' => 64000, 'messages' => []]);
ok('a caller asking for 64000 output tokens is clamped to ai.max_tokens', $calls[0]['body']['max_tokens'] === 1000, (string) $calls[0]['body']['max_tokens']);
ok('success returns the decoded response and usage', $err === '' && $usage['in'] === 100 && $usage['out'] === 20 && $attempts === 1);
ok('structured JSON is read past a thinking block', Claude::text_json($json) === ['a' => 1]);
$reset();
$queue = [$ok()];
Claude::send($cfg, ['model' => 'm', 'max_tokens' => 300, 'messages' => []]);
ok('a smaller request keeps its own max_tokens', $calls[0]['body']['max_tokens'] === 300);
$reset();
$queue = [$ok()];
Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('a missing max_tokens gets the cap', $calls[0]['body']['max_tokens'] === 1000);
ok('limits have sane floors', Claude::limits(['ai' => ['max_tokens' => 0, 'retries' => 99]])['max_tokens'] >= 256 && Claude::limits(['ai' => ['retries' => 99]])['retries'] === 5);

// 2) Oversized requests are refused before any network call.
$reset();
[, $err, , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => [['role' => 'user', 'content' => str_repeat('x', 6000)]]]);
ok('a request over ai.max_input_chars is not sent', $calls === [] && $attempts === 0 && str_contains($err, 'too large'), $err);
$reset();
[, $err] = Claude::send(['ai' => ['key' => '']], ['model' => 'm', 'messages' => []]);
ok('no key → no call', $calls === [] && str_contains($err, 'not configured'));

// 3) Transient failures are retried, with backoff, a bounded number of times.
$reset();
$queue = [[529, 'overloaded', []], [503, 'unavailable', []], $ok()];
[$json, $err, , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('529 then 503 then success → succeeds on the 3rd attempt', $json !== null && $attempts === 3 && count($calls) === 3);
ok('backoff grows between attempts', $slept === [1.0, 2.0], json_encode($slept));
$reset();
$queue = [[429, 'slow down', ['retry-after' => '7']], $ok()];
Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('Retry-After is honoured', $slept === [7.0], json_encode($slept));
$reset();
$queue = [[429, 'slow down', ['retry-after' => '600']], $ok()];
Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('a huge Retry-After is capped at 30s', $slept === [30.0], json_encode($slept));
$reset();
$queue = [[500, 'x', []], [500, 'x', []], [500, 'x', []], [500, 'x', []], $ok()];
[$json, $err, , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('gives up after 1 + ai.retries attempts — no endless loop', $json === null && $attempts === 3 && count($calls) === 3 && str_contains($err, 'after 3 attempts'), $err);
$reset();
$queue = [[0, '', [], 'curl: timeout'], $ok()];
[$json, , , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('network errors are retried', $json !== null && $attempts === 2);

// 4) Failures that would repeat identically are NOT retried.
foreach ([400 => 'bad request', 401 => 'bad key', 403 => 'forbidden', 404 => 'no model'] as $code => $msg) {
    $reset();
    $queue = [[$code, $msg, []], $ok()];
    [$json, , , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
    if ($json !== null || $attempts !== 1) {
        ok("HTTP {$code} is not retried", false);
    }
}
ok('client errors (400/401/403/404) are not retried', true);
$reset();
$queue = [$ok('', 'refusal'), $ok()];
[$json, $err, , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('a refusal is not retried', $json === null && $attempts === 1 && str_contains($err, 'declined'));
$reset();
$queue = [$ok('{"a":', 'max_tokens'), $ok()];
[$json, $err, , $attempts] = Claude::send($cfg, ['model' => 'm', 'messages' => []]);
ok('a cut-off answer is reported, not retried', $json === null && $attempts === 1 && str_contains($err, 'max_tokens'), $err);
$reset();
$queue = [$ok()];
Claude::send($cfg, ['model' => 'm', 'messages' => []], ['server-side-fallback-2026-07-01']);
ok('beta headers are sent', in_array('anthropic-beta: server-side-fallback-2026-07-01', $calls[0]['headers'], true));
ok('the key travels only as x-api-key', in_array('x-api-key: sk-test', $calls[0]['headers'], true) && !str_contains(json_encode($calls[0]['body']), 'sk-test'));

// 5) Daily budget across all features.
$bp = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bp->exec("CREATE TABLE ai_queries (id INTEGER PRIMARY KEY, user_id INT, user_name TEXT, question TEXT, matched TEXT, ok INT, error TEXT,
    in_tokens INT, cached_tokens INT, out_tokens INT, created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
$bp->exec("INSERT INTO ai_queries (question, in_tokens, out_tokens) VALUES ('a', 1000, 500), ('b', 2000, 500)");
$bp->exec("INSERT INTO ai_queries (question, in_tokens, out_tokens, created_at) VALUES ('yesterday', 999999, 0, datetime('now','localtime','-1 day'))");
ok('used_today counts only today', Claude::used_today($bp) === 4000, (string) Claude::used_today($bp));
ok('budget_left subtracts today', Claude::budget_left($bp, ['ai' => ['daily_token_budget' => 10000]]) === 6000);
ok('budget never goes negative', Claude::budget_left($bp, ['ai' => ['daily_token_budget' => 1000]]) === 0);
ok('budget 0 means unlimited', Claude::budget_left($bp, ['ai' => ['daily_token_budget' => 0]]) === PHP_INT_MAX);

// 6) The inventory assistant goes through the same door.
$reset();
$queue = [[500, 'x', []], [500, 'x', []], [500, 'x', []]];
[$p, $e] = Ai::match(['ai' => ['driver' => 'claude', 'key' => 'k', 'retries' => 2, 'max_tokens' => 2000]], '1|SJ|x|a/b|4.0|1.22', 'q');
ok('inventory assistant: bounded retries, then a clean failure', $p === null && count($calls) === 3 && str_contains($e, 'after 3 attempts'), $e);
ok('inventory assistant: its 4000-token request is clamped', $calls[0]['body']['max_tokens'] === 2000);

Claude::$http = null;
Claude::$sleep = null;

// 7) .env → config, against a copy of the real config.php.
$src = __DIR__ . '/../config.php';
if (is_file($src)) {
    $tmp = sys_get_temp_dir() . '/envtest_' . bin2hex(random_bytes(4));
    mkdir($tmp);
    copy($src, $tmp . '/config.php');
    file_put_contents($tmp . '/.env', "# comment\nANTHROPIC_API_KEY=sk-ant-api03-abc \nGOOGLE_MAPS_API_KEY=\"AIzaXYZ\"\n\nBROKEN LINE\nWA_TOKEN='tok'\n");
    $c = (function (string $f) {
        return require $f;
    })($tmp . '/config.php');
    ok('.env: ANTHROPIC_API_KEY → ai.key (trimmed)', ($c['ai']['key'] ?? '') === 'sk-ant-api03-abc');
    ok('.env: GOOGLE_MAPS_API_KEY → geo (quotes stripped, driver on)', ($c['geo'] ?? []) === ['driver' => 'google', 'key' => 'AIzaXYZ']);
    ok('.env: WA_TOKEN → wa.token', ($c['wa']['token'] ?? '') === 'tok');
    ok('.env: absent OSS keys leave offsite alone', ($c['offsite']['driver'] ?? 'x') === '');
    unlink($tmp . '/.env');
    $c2 = (function (string $f) {
        return require $f;
    })($tmp . '/config.php');
    ok('no .env → keys stay empty', ($c2['ai']['key'] ?? 'x') === '' && ($c2['geo']['key'] ?? 'x') === '');
    unlink($tmp . '/config.php');
    rmdir($tmp);
}
$gi = (string) @file_get_contents(__DIR__ . '/../.gitignore');
if ($gi !== '') {
    ok('.env is gitignored', preg_match('#^/\.env$#m', $gi) === 1);
}
