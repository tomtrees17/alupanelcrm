<?php

// ── FA-AI-00: the AI task layer (app/AiTask.php) ──
// Every model call is stubbed (Claude::$http); nothing leaves the machine.

$schemaSql = @file_get_contents(__DIR__ . '/../database/schema.sql');
$newDb = function () use ($schemaSql): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec($schemaSql === false ? (string) ($GLOBALS['__schema'] ?? '') : $schemaSql);
    return $p;
};
$pdo = $newDb();
$cfgFull = (array) (@include __DIR__ . '/../config.php');

// Prompts from a throwaway directory: two versions of a demo task.
$promptDir = sys_get_temp_dir() . '/ai-prompts-' . bin2hex(random_bytes(4));
mkdir($promptDir, 0700, true);
file_put_contents("{$promptDir}/demo_v1.txt", "Old wording.\n");
file_put_contents("{$promptDir}/demo_v2.txt", "Read the bank line and answer in JSON.\n");
AiTask::$promptDir = $promptDir;

$cfg = ['ai' => [
    'key' => 'sk-test', 'model' => 'claude-opus-5-5', 'max_tokens' => 4096, 'retries' => 0, 'daily_token_budget' => 0,
    'daily_usd_budget' => 5.0, 'monthly_usd_budget' => 50.0, 'per_record_daily_calls' => 2,
    'tiers' => ['cheap' => 'claude-haiku-5-5', 'standard' => 'claude-opus-5-5'],
    'tasks' => [
        'demo'     => ['tier' => 'cheap', 'effort' => 'low', 'max_tokens' => 1000],
        'demo_std' => ['tier' => 'standard', 'max_tokens' => 1000],
        'demo_pin' => ['model' => 'claude-sonnet-5-5', 'prompt_version' => 1],
        'nomodel'  => ['effort' => 'low'],
    ],
    'prices' => $cfgFull['ai']['prices'],
]];
file_put_contents("{$promptDir}/demo_std_v1.txt", 'Standard tier demo.');
file_put_contents("{$promptDir}/demo_pin_v1.txt", 'Pinned v1.');
file_put_contents("{$promptDir}/demo_pin_v2.txt", 'Pinned v2 (not used).');
file_put_contents("{$promptDir}/nomodel_v1.txt", 'x');

$schema = [
    'type' => 'object',
    'properties' => [
        'invoice_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'confidence'  => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        'amount'      => ['type' => 'number', 'minimum' => 0],
    ],
    'required' => ['invoice_ids', 'confidence', 'amount'],
    'additionalProperties' => false,
];
$good = json_encode(['invoice_ids' => [12], 'confidence' => 'high', 'amount' => 1234500]);

// The fake API: a queue of replies, every request body kept.
$queue = [];
$sent = [];
$reply = fn(string $text, int $in = 1000, int $out = 1000, ?string $model = null): array =>
    [200, json_encode(['model' => $model, 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn',
                       'usage' => ['input_tokens' => $in, 'output_tokens' => $out]]), []];
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$queue, &$sent): array {
    $sent[] = ['body' => json_decode($body, true), 'headers' => $headers];
    return array_shift($queue) ?? [500, 'queue empty', []];
};
$calls = function () use (&$sent): int {
    return count($sent);
};
$lastRow = fn(): array => $pdo->query('SELECT * FROM ai_calls ORDER BY id DESC LIMIT 1')->fetch();

// 1) Config and prompts: model per task from config, newest prompt unless pinned.
ok('model from the task tier', AiTask::model($cfg, 'demo') === 'claude-haiku-5-5' && AiTask::model($cfg, 'demo_std') === 'claude-opus-5-5');
ok('a task may name its model', AiTask::model($cfg, 'demo_pin') === 'claude-sonnet-5-5');
ok('newest prompt version by default', AiTask::prompt('demo') === ['Read the bank line and answer in JSON.', 2]);
ok('a pinned version is used; old files stay', AiTask::prompt('demo_pin', 1) === ['Pinned v1.', 1] && is_file("{$promptDir}/demo_pin_v2.txt"));
$threw = false;
try {
    AiTask::prompt('../etc');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ok('task names cannot walk out of prompts/', $threw);
$r = AiTask::run($pdo, $cfg, 'nomodel', ['content' => 'x', 'schema' => $schema]);
ok('a task without a model is refused, logged, not sent', $r['status'] === 'api_error' && str_contains($r['error'], 'ai.tasks.nomodel') && $calls() === 0);

// 2) A good answer: one call, one ledger row with tokens, cost and latency.
$queue = [$reply($good, 1000, 1000, 'claude-haiku-5-5')];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'TRSF E-BANKING CR 0001/AMI-CO/01/26 DEMO PAYER', 'schema' => $schema, 'ref_table' => 'bank_transactions', 'ref_id' => 1]);
$row = $lastRow();
ok('ok: data returned', $r['status'] === 'ok' && $r['data']['invoice_ids'] === [12] && $calls() === 1);
ok('ok: one ai_calls row with the fields of the card', $row['task_type'] === 'demo' && $row['ref_table'] === 'bank_transactions' && (int) $row['ref_id'] === 1
    && $row['model'] === 'claude-haiku-5-5' && (int) $row['prompt_version'] === 2 && $row['status'] === 'ok'
    && (int) $row['input_tokens'] === 1000 && (int) $row['output_tokens'] === 1000 && (int) $row['latency_ms'] >= 0 && (int) $row['attempts'] === 1);
ok('ok: cost at the configured price (Haiku 5.5: $0.10 + $0.50 per M)', abs((float) $row['cost_usd'] - 0.0006) < 1e-9, (string) $row['cost_usd']);
$body = $sent[0]['body'];
ok('request: model, prompt, schema, effort from config', $body['model'] === 'claude-haiku-5-5' && $body['system'] === 'Read the bank line and answer in JSON.'
    && $body['output_config']['format']['schema'] === $schema && $body['output_config']['effort'] === 'low' && $body['max_tokens'] === 1000);
ok('request: no server-side fallback on Haiku (none exists)', !isset($body['fallbacks']) && !str_contains(implode("\n", $sent[0]['headers']), 'server-side-fallback'));
$queue = [$reply($good, 1000, 1000, 'claude-opus-5-5')];
AiTask::run($pdo, $cfg, 'demo_std', ['content' => 'x', 'schema' => $schema]);
ok('request: Opus asks for the server-side fallback', ($sent[1]['body']['fallbacks'] ?? '') === 'default' && str_contains(implode("\n", $sent[1]['headers']), 'server-side-fallback-2026-07-01'));
ok('cost: Opus 5.5 at $4 + $20 per M', abs((float) $lastRow()['cost_usd'] - 0.024) < 1e-9);
ok('cost: cache reads and writes priced separately', abs(AiTask::cost($cfg, 'claude-opus-5-5', ['in' => 0, 'cached' => 1_000_000, 'write' => 1_000_000, 'out' => 0]) - 5.20) < 1e-9);
ok('cost: an unlisted model is priced as default (the dearest), never free', AiTask::cost($cfg, 'claude-something-new', ['in' => 1_000_000]) === 10.0);
$queue = [$reply($good, 1000, 1000, 'claude-sonnet-5-5')];
AiTask::run($pdo, $cfg, 'demo_std', ['content' => 'x', 'schema' => $schema]);
ok('cost: billed at the model that actually answered (fallback)', $lastRow()['model'] === 'claude-sonnet-5-5' && abs((float) $lastRow()['cost_usd'] - 0.012) < 1e-9);

// 3) Acceptance ①: a non-JSON answer is asked once more, then handed to a person. No loop.
$n0 = $calls();
$queue = [$reply('Sure! The invoice is 0001.'), $reply('I think it is 0001.'), $reply($good), $reply($good)];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema, 'ref_table' => 'bank_transactions', 'ref_id' => 2]);
$row = $lastRow();
ok('non-JSON twice → exactly 2 requests, then stop', $calls() - $n0 === 2, (string) ($calls() - $n0));
ok('non-JSON twice → status invalid ("AI 未处理"), no data', $r['status'] === 'invalid' && $r['data'] === null && $row['status'] === 'invalid' && (int) $row['attempts'] === 2);
ok('the error says why, for both attempts', str_contains((string) $row['error'], 'attempt 1: answer is not JSON') && str_contains((string) $row['error'], 'attempt 2'));
ok('both attempts are paid for and counted', (int) $row['input_tokens'] === 2000 && abs((float) $row['cost_usd'] - 0.0012) < 1e-9);
ok('the unused replies are still queued (nothing kept retrying)', count($queue) === 2);
$queue = [$reply('not json'), $reply($good)];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema]);
ok('non-JSON once, then valid → ok on the second attempt', $r['status'] === 'ok' && (int) $lastRow()['attempts'] === 2 && str_contains((string) $lastRow()['error'], 'attempt 1'));
$queue = [$reply(json_encode(['invoice_ids' => [12], 'confidence' => 'sure'])), $reply(json_encode(['invoice_ids' => ['12'], 'confidence' => 'high', 'amount' => 1]))];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema]);
ok('JSON that breaks the schema twice → invalid', $r['status'] === 'invalid' && str_contains($r['error'], '$.amount: missing') && str_contains($r['error'], '$.invoice_ids[0]: expected integer'));
$queue = [$reply($good), $reply($good)];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema, 'check' => fn(array $d): string => $d['amount'] > 1000 ? 'amount above the transfer' : '']);
ok("a task's own rule counts like the schema", $r['status'] === 'invalid' && str_contains($r['error'], 'amount above the transfer'));
$n0 = $calls();
$queue = [[400, '{"type":"error","error":{"type":"invalid_request_error"}}', []], $reply($good)];
$r = AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema]);
ok('an API error is not re-asked (Claude::send already retried what is worth it)', $r['status'] === 'api_error' && $calls() - $n0 === 1 && str_contains($r['error'], 'HTTP 400'));
$queue = [];

// 4) Same task, same record: at most 2 calls in 24 hours.
$pdo->exec('DELETE FROM ai_calls');
$n0 = $calls();
$queue = [$reply($good), $reply($good), $reply($good)];
$req = ['content' => 'x', 'schema' => $schema, 'ref_table' => 'bank_transactions', 'ref_id' => 77];
$a = AiTask::run($pdo, $cfg, 'demo', $req);
$b = AiTask::run($pdo, $cfg, 'demo', $req);
$c = AiTask::run($pdo, $cfg, 'demo', $req);
ok('third call for the same record within 24 h is skipped, not sent', $a['status'] === 'ok' && $b['status'] === 'ok' && $c['status'] === 'repeat_skipped' && $calls() - $n0 === 2);
ok('the skip is logged', $lastRow()['status'] === 'repeat_skipped' && (int) $lastRow()['ref_id'] === 77);
$d = AiTask::run($pdo, $cfg, 'demo', ['ref_id' => 78] + $req);
ok('another record is not affected', $d['status'] === 'ok');
$pdo->exec("UPDATE ai_calls SET created_at = datetime('now','localtime','-25 hours') WHERE ref_id = 77");
$queue[] = $reply($good);
ok('after 24 h the record may be asked again', AiTask::run($pdo, $cfg, 'demo', $req)['status'] === 'ok');
$queue = [];

// 5) Acceptance ②: daily cap $0.01 → once reached, every AI call is skipped and work stays manual.
$pdo->exec('DELETE FROM ai_calls');
$pdo->exec('DELETE FROM hr_todos');
$tight = $cfg;
$tight['ai']['daily_usd_budget'] = 0.01;
$n0 = $calls();
$queue = [$reply($good, 1000, 1000, 'claude-opus-5-5'), $reply($good), $reply($good)];
$first = AiTask::run($pdo, $tight, 'demo_std', ['content' => 'x', 'schema' => $schema]);   // $0.024 > $0.01
$second = AiTask::run($pdo, $tight, 'demo_std', ['content' => 'x', 'schema' => $schema]);
$third = AiTask::run($pdo, $tight, 'demo', ['content' => 'x', 'schema' => $schema]);
ok('first call goes through (nothing spent yet)', $first['status'] === 'ok');
ok('after the cap: calls skipped as over_budget, nothing sent', $second['status'] === 'over_budget' && $third['status'] === 'over_budget' && $calls() - $n0 === 1);
ok('the skip says which cap', str_contains($second['error'], 'USD day') && $lastRow()['status'] === 'over_budget' && (float) $lastRow()['cost_usd'] === 0.0);
ok('every other AI feature is stopped too (Claude::budget_left = 0: inventory assistant, Enrich)', Claude::budget_left($pdo, $tight) === 0);
$todos = $pdo->query("SELECT * FROM hr_todos WHERE type = 'ai_budget'")->fetchAll();
ok('one todo for the boss (not per call)', count($todos) === 1 && $todos[0]['ref'] === 'day:' . date('Y-m-d') && $todos[0]['employee_id'] === null && $todos[0]['status'] === 'open');
ok('ai_budget is a boss todo, kept out of the four HR types', in_array('ai_budget', Hr::BOSS_TODO_TYPES, true) && !in_array('ai_budget', Hr::TODO_TYPES, true));

// Manual work still works: the enrichment run keeps applying rules, asks no model.
$pdo->exec("INSERT INTO customers (name, company, owner) VALUES ('Ali', 'Toko Kaca Jaya', 'rina'), ('Sinar', 'PT Sinar Abadi', 'rina')");
$enrichCfg = $tight + ['insights' => ($cfgFull['insights'] ?? []) + ['industry_ai' => true]];
$enrichCfg['ai']['tasks']['industry'] = $cfgFull['ai']['tasks']['industry'];
$n0 = $calls();
$lines = [];
Enrich::run($pdo, $enrichCfg, ['geo' => false], function (string $l) use (&$lines): void {
    $lines[] = $l;
});
ok('over budget: Enrich asks no model and says so', $calls() === $n0 && str_contains(implode(' ', $lines), 'AI budget used up'));
ok('over budget: rules still classify (feature works by hand / rules)', $pdo->query("SELECT industry FROM customers WHERE company = 'Toko Kaca Jaya'")->fetchColumn() === 'aluminium_shop'
    && $pdo->query("SELECT industry FROM customers WHERE company = 'PT Sinar Abadi'")->fetchColumn() === null);

// Next day: the day's spend is history, calls go out again, yesterday's todo closes itself.
$pdo->exec("UPDATE ai_calls SET created_at = datetime(created_at, '-1 day')");
$pdo->exec("UPDATE hr_todos SET ref = 'day:' || date('now','localtime','-1 day')");
$queue = [$reply($good, 10, 10)];
$next = AiTask::run($pdo, $tight, 'demo', ['content' => 'x', 'schema' => $schema]);
ok('next day: calls go through again', $next['status'] === 'ok');
ok("next day: yesterday's budget todo closed by the system", $pdo->query("SELECT status || '/' || handled_by FROM hr_todos WHERE type = 'ai_budget'")->fetchColumn() === 'done/system');

// Monthly cap, and the inventory assistant's own ledger counting toward it.
$pdo->exec('DELETE FROM ai_calls');
$pdo->exec('DELETE FROM hr_todos');
$monthCfg = $cfg;
$monthCfg['ai']['daily_usd_budget'] = 0;
$monthCfg['ai']['monthly_usd_budget'] = 0.03;
$pdo->exec("INSERT INTO ai_queries (user_id, user_name, question, in_tokens, out_tokens) VALUES (3, 'Sales', '4.0 银色还有吗', 1000, 1000)");   // Opus: $0.024
ok('inventory assistant spend is priced into the USD total', abs(AiTask::spent_usd($pdo, $monthCfg, 'month') - 0.024) < 1e-9);
ok('…and into the token total (Claude::used_today counts both ledgers)', Claude::used_today($pdo) === 2000);
$queue = [$reply($good, 1000, 1000, 'claude-opus-5-5'), $reply($good)];
ok('monthly cap: one more call fits', AiTask::run($pdo, $monthCfg, 'demo_std', ['content' => 'x', 'schema' => $schema])['status'] === 'ok');
$m = AiTask::run($pdo, $monthCfg, 'demo_std', ['content' => 'x', 'schema' => $schema]);
ok('monthly cap reached → over_budget (month)', $m['status'] === 'over_budget' && str_contains($m['error'], 'USD month'));
ok('the month todo for the boss', $pdo->query("SELECT ref FROM hr_todos WHERE type = 'ai_budget'")->fetchColumn() === 'month:' . date('Y-m'));
ok('caps of 0 mean no cap', AiTask::over_budget($pdo, ['ai' => ['daily_usd_budget' => 0, 'monthly_usd_budget' => 0]]) === '');
$queue = [];

// 6) Suggestions and confirmation: adoption and the most-changed fields.
$pdo->exec('DELETE FROM ai_suggestions');
$s1 = AiTask::suggest($pdo, 'demo', 'bank_transactions', 1, ['invoice_id' => 12, 'amount' => 1234500, 'diff_reason' => null], 'high', 1);
$s2 = AiTask::suggest($pdo, 'demo', 'bank_transactions', 2, ['invoice_id' => 13, 'amount' => 1000000, 'diff_reason' => null], 'medium', 2);
$s3 = AiTask::suggest($pdo, 'demo', 'bank_transactions', 3, ['invoice_id' => 14, 'amount' => 500000, 'diff_reason' => null], 'low', null);
$c1 = AiTask::confirm($pdo, $s1, ['invoice_id' => 12, 'amount' => 1234500.0, 'diff_reason' => null], 'Finance');
$c2 = AiTask::confirm($pdo, $s2, ['invoice_id' => 15, 'amount' => 990000, 'diff_reason' => 'fee'], 'Finance');
ok('confirmed as suggested → accepted (1234500 = 1234500.0)', $c1 === ['accepted' => true, 'changed' => []]);
ok('edited → not accepted, changed fields listed', $c2['accepted'] === false && $c2['changed'] === ['invoice_id', 'amount', 'diff_reason']);
ok('a suggestion is confirmed once', AiTask::confirm($pdo, $s1, ['invoice_id' => 99], 'Someone') === null
    && (int) $pdo->query("SELECT invoice_id FROM (SELECT json_extract(final_value, '$.invoice_id') invoice_id FROM ai_suggestions WHERE id = {$s1})")->fetchColumn() === 12);
$row = $pdo->query("SELECT * FROM ai_suggestions WHERE id = {$s2}")->fetch();
ok('confirmation keeps who / when / final value', $row['confirmed_by'] === 'Finance' && $row['confirmed_at'] !== null && json_decode($row['final_value'], true)['invoice_id'] === 15 && (int) $row['ai_accepted'] === 0);

// 7) Acceptance ③: the usage numbers equal the ai_calls totals.
$pdo->exec('DELETE FROM ai_calls');
$queue = [$reply($good, 1200, 300, 'claude-haiku-5-5'), $reply('x'), $reply('y'), [500, 'down', []]];
AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema, 'ref_table' => 't', 'ref_id' => 1]);
AiTask::run($pdo, $cfg, 'demo', ['content' => 'x', 'schema' => $schema, 'ref_table' => 't', 'ref_id' => 2]);
AiTask::run($pdo, $cfg, 'demo_std', ['content' => 'x', 'schema' => $schema]);
$pdo->exec("INSERT INTO ai_calls (task_type, status, cost_usd, input_tokens, output_tokens, created_at) VALUES ('demo', 'ok', 9.99, 5, 5, '2026-01-15 10:00:00')");   // another month
$month = date('Y-m');
$u = AiTask::usage($pdo, $cfg, $month);
$sql = $pdo->prepare("SELECT COUNT(*) n, ROUND(SUM(cost_usd), 6) usd, SUM(input_tokens + cached_tokens + cache_write_tokens) i, SUM(output_tokens) o FROM ai_calls WHERE substr(created_at,1,7) = ?");
$sql->execute([$month]);
$agg = $sql->fetch();
ok('usage: total calls = COUNT(ai_calls) for the month', $u['total']['calls'] === (int) $agg['n'] && $u['total']['calls'] === 3);
ok('usage: total cost = SUM(cost_usd)', abs($u['total']['cost'] - (float) $agg['usd']) < 1e-9);
ok('usage: tokens = SUM of the rows', $u['total']['in'] === (int) $agg['i'] && $u['total']['out'] === (int) $agg['o']);
$byStatus = $pdo->query("SELECT task_type || ':' || status k, COUNT(*) n FROM ai_calls WHERE substr(created_at,1,7) = '{$month}' GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
ok('usage: per task per status = GROUP BY', $u['tasks']['demo']['ok'] === (int) ($byStatus['demo:ok'] ?? 0) && $u['tasks']['demo']['invalid'] === (int) ($byStatus['demo:invalid'] ?? 0)
    && $u['tasks']['demo_std']['api_error'] === (int) ($byStatus['demo_std:api_error'] ?? 0) && $u['tasks']['demo_std']['api_error'] === 1);
ok('usage: another month is not mixed in', $u['total']['cost'] < 9.99 && AiTask::usage($pdo, $cfg, '2026-01')['total']['calls'] === 1);
ok('usage: adoption = accepted / confirmed, most-changed fields', abs($u['tasks']['demo']['adoption'] - 0.5) < 1e-9 && $u['tasks']['demo']['suggested'] === 3
    && array_keys($u['tasks']['demo']['fields']) === ['invoice_id', 'amount', 'diff_reason']);
ok('usage: inventory assistant shown from its own ledger', $u['inventory']['calls'] === 1 && abs($u['inventory']['cost'] - 0.024) < 1e-9);
$queue = [];

// 8) Images: long edge ≤ 1568 px, small ones untouched.
if (function_exists('imagecreatetruecolor')) {
    $big = $promptDir . '/big.png';
    imagepng(imagecreatetruecolor(3000, 1500), $big);
    [$blk, $err] = AiTask::image_block($big);
    $dim = $blk ? getimagesizefromstring(base64_decode($blk['source']['data'])) : [0, 0];
    ok('image: a 3000×1500 photo is sent at 1568×784 JPEG', $err === '' && $blk['source']['media_type'] === 'image/jpeg' && $dim[0] === 1568 && $dim[1] === 784, "{$dim[0]}x{$dim[1]}");
    $small = $promptDir . '/small.png';
    imagepng(imagecreatetruecolor(800, 600), $small);
    [$blk] = AiTask::image_block($small);
    ok('image: a small one is sent as it is', $blk['source']['media_type'] === 'image/png' && base64_decode($blk['source']['data']) === file_get_contents($small));
}
file_put_contents($promptDir . '/note.txt', 'hello');
ok('image: not an image → error, no block', AiTask::image_block($promptDir . '/note.txt')[0] === null);

// 9) Schema check, the cases our task schemas rely on.
ok('validate: fits', AiTask::validate(['invoice_ids' => [1, 2], 'confidence' => 'low', 'amount' => 0.5], $schema) === '');
ok('validate: integer accepts 5.0 from JSON', AiTask::validate(5.0, ['type' => 'integer']) === '');
ok('validate: missing / extra / enum / minimum', str_contains(AiTask::validate(['invoice_ids' => []], $schema), 'missing')
    && str_contains(AiTask::validate(['invoice_ids' => [], 'confidence' => 'low', 'amount' => 1, 'x' => 1], $schema), 'not allowed')
    && str_contains(AiTask::validate(['invoice_ids' => [], 'confidence' => 'meh', 'amount' => 1], $schema), 'allowed values')
    && str_contains(AiTask::validate(['invoice_ids' => [], 'confidence' => 'low', 'amount' => -1], $schema), 'below'));
ok('validate: nullable types', AiTask::validate(null, ['type' => ['string', 'null']]) === '' && AiTask::validate(3, ['type' => ['string', 'null']]) !== '');
ok('validate: a list is not an object', AiTask::validate([1, 2], ['type' => 'object']) !== '' && AiTask::validate(['a' => 1], ['type' => 'array']) !== '');

// 10) Acceptance ④: industry inference unchanged by the move (the existing enrich tests run as before).
AiTask::$promptDir = null;
ok('industry prompt is byte-identical to the pre-migration text', md5(Enrich::industry_prompt()) === 'f082ce1d937d33844959a8b088d6ab16');
ok('industry prompt lives in prompts/industry_v1.txt', AiTask::prompt('industry') === [Enrich::industry_prompt(), 1]);
$req = Enrich::ai_request($cfgFull, [['id' => 7, 'name' => 'Toko A']]);
ok('industry request: same model / effort / cap / fallback as before', $req['model'] === 'claude-opus-5-5' && $req['output_config']['effort'] === 'low'
    && $req['max_tokens'] === 4096 && $req['fallbacks'] === 'default' && array_keys($req) === ['model', 'max_tokens', 'system', 'messages', 'output_config', 'fallbacks']);
ok('legacy insights.industry_model still overrides', Enrich::ai_request(['insights' => ['industry_model' => 'claude-sonnet-5-5']] + $cfgFull, [['id' => 1]])['model'] === 'claude-sonnet-5-5');
$keyed = $cfgFull;
$keyed['ai']['key'] = 'sk-test';
$queue = [$reply(json_encode(['results' => [['id' => 7, 'industry' => 'signage', 'confidence' => 0.9, 'reason' => '广告']]]), 500, 100, 'claude-opus-5-5')];
[$res, $err] = Enrich::ai_classify($pdo, $keyed, [['id' => 7, 'name' => 'Toko A']]);
ok('industry call goes through the task layer: ai_calls row, task industry, v1', $err === '' && $res[7]['industry'] === 'signage'
    && $lastRow()['task_type'] === 'industry' && (int) $lastRow()['prompt_version'] === 1 && $lastRow()['ref_table'] === 'customers');
ok('…and no longer writes token rows into ai_queries', (int) $pdo->query("SELECT COUNT(*) FROM ai_queries WHERE user_name = 'enrich'")->fetchColumn() === 0);

Claude::$http = null;
Claude::$sleep = null;
array_map('unlink', glob("{$promptDir}/*") ?: []);
@rmdir($promptDir);

// 11) Upgrade path: a production DB from before FA-AI-00.
$old = $newDb();
$old->exec('DROP TABLE ai_suggestions');
$old->exec('DROP TABLE ai_calls');
$old->exec('CREATE TABLE IF NOT EXISTS app_meta (k TEXT PRIMARY KEY, v TEXT)');
$old->exec("INSERT OR IGNORE INTO app_meta (k, v) VALUES ('perm_performance','1'),('perm_roles_v2','1'),('perm_export','1'),('pwd_policy_v1','1'),('perm_approvals','1'),('perm_insights','1'),('perm_insights_v2','1'),('perm_hr_v1','1'),('hr_employees_v1','1')");
$old->exec("INSERT INTO role_permissions (role, module) VALUES ('finance_manager', 'finance'), ('sales', 'orders')");
$old->exec("INSERT INTO ai_queries (user_id, user_name, question, in_tokens, out_tokens) VALUES (3, 'Sales', 'stok 4.0', 100, 50)");
$old->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Admin', 'admin@alupanel.local', 'x', 'admin')");
$permsBefore = $old->query("SELECT role || ':' || module FROM role_permissions ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
Database::migrate($old);
$tables = $old->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('ai_calls', 'ai_suggestions') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
ok('migrate: ai_calls and ai_suggestions created', $tables === ['ai_calls', 'ai_suggestions']);
$permsAfter = $old->query("SELECT role || ':' || module FROM role_permissions ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
ok('migrate: only finance_manager gains ai_usage', array_values(array_diff($permsAfter, $permsBefore)) === ['finance_manager:ai_usage']);
ok('migrate: the inventory ledger is untouched', $old->query("SELECT question || '|' || in_tokens FROM ai_queries")->fetchAll(PDO::FETCH_COLUMN) === ['stok 4.0|100']);
$old->exec("DELETE FROM role_permissions WHERE module = 'ai_usage'");   // the admin takes it away again
Database::migrate($old);
ok('migrate: runs once — a later revocation sticks, re-run is harmless', (int) $old->query("SELECT COUNT(*) FROM role_permissions WHERE module = 'ai_usage'")->fetchColumn() === 0
    && count($old->query('PRAGMA table_info(ai_calls)')->fetchAll()) === 18);
ok('fresh install: finance_manager has ai_usage by default', in_array('ai_usage', default_permissions()['finance_manager'], true) && in_array('ai_usage', view_permissions(), true));

// 12) The pages for real: who sees 「AI 使用情况」, and the boss's budget todo.
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = sys_get_temp_dir() . '/ai-usage-' . bin2hex(random_bytes(4)) . '.sqlite';
    $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Database::migrate($p);   // a fresh install: schema + seed + default permissions
    $p->exec("INSERT INTO ai_calls (task_type, status, model, input_tokens, output_tokens, cost_usd, latency_ms, attempts) VALUES
              ('industry', 'ok', 'claude-opus-5-5', 1000, 200, 0.008, 1500, 1),
              ('industry', 'invalid', 'claude-opus-5-5', 2000, 400, 0.016, 3000, 2),
              ('industry', 'over_budget', 'claude-opus-5-5', 0, 0, 0, 0, 0)");
    $p->exec("INSERT INTO hr_todos (type, employee_id, ref, due_date) VALUES ('ai_budget', NULL, 'day:" . date('Y-m-d') . "', '" . date('Y-m-d') . "')");
    $todoId = (int) $p->lastInsertId();
    $expect = AiTask::usage($p, $cfgFull, date('Y-m'));
    $p = null;
    $run = function (string $controller, string $action, array $user, array $get = [], ?array $post = null) use ($db, $harness): string {
        $args = [PHP_BINARY, $harness, $db, $controller, $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $boss = ['id' => 1, 'name' => 'Admin', 'role' => 'admin'];
    $fin = ['id' => 7, 'name' => 'Fin', 'role' => 'finance_manager'];
    $sales = ['id' => 3, 'name' => 'Rina', 'role' => 'sales'];
    $hrUser = ['id' => 5, 'name' => 'HR', 'role' => 'hr'];

    $html = $run('ai', 'usage', $boss);
    ok('usage page renders for the boss, no notices', str_contains($html, t('ai_usage_title')) && !str_contains($html, 'PHP:'), mb_substr($html, 0, 300));
    preg_match('/data-total-calls>([^<]*)</', $html, $mc);
    preg_match('/data-total-cost>([^<]*)</', $html, $mu);
    ok('usage page: calls on the page = ai_calls count', ($mc[1] ?? '') === num($expect['total']['calls']) && $expect['total']['calls'] === 3, $mc[1] ?? '');
    ok('usage page: cost on the page = SUM(cost_usd)', ($mu[1] ?? '') === '$0.0240', $mu[1] ?? '');
    ok('usage page: per-status columns from the ledger', (bool) preg_match('#data-task="industry">.*?<td class="right num">3</td>\s*<td class="right num">1</td>\s*<td class="right num">1</td>\s*<td class="right num">0</td>\s*<td class="right num">1</td>#s', $html));
    ok('usage page: finance manager may see it (default permission)', str_contains($run('ai', 'usage', $fin), 'data-total-calls'));
    $denied = $run('ai', 'usage', $sales);
    ok('usage page: sales may not', !str_contains($denied, 'data-total-calls') && str_contains($denied, t('msg_no_module')));
    ok('finance page links to it for the finance manager', str_contains($run('finance', 'index', $fin), 'r=ai.usage'));

    $home = $run('hr', 'index', $boss);
    ok('HR todo home: the boss sees the AI budget todo', str_contains($home, t('hrtodo_ai_budget')) && str_contains($home, 'r=ai.usage') && !str_contains($home, 'PHP:'));
    ok('HR todo home: HR does not (boss only)', !str_contains($run('hr', 'index', $hrUser), t('hrtodo_ai_budget')));
    $r1 = $run('hr', 'todo_done', $hrUser, [], ['id' => $todoId, 'note' => 'x']);
    $chk = new PDO('sqlite:' . $db);
    ok('HR cannot close the boss todo', str_contains($r1, t('hr_msg_read_only')) && $chk->query("SELECT status FROM hr_todos WHERE id = {$todoId}")->fetchColumn() === 'open');
    $run('hr', 'todo_done', $boss, [], ['id' => $todoId, 'note' => 'Console 已加额度']);
    ok('the boss can mark it done (audited like any todo)', $chk->query("SELECT status FROM hr_todos WHERE id = {$todoId}")->fetchColumn() === 'done'
        && (int) $chk->query("SELECT COUNT(*) FROM audit_log WHERE action = 'handle' AND entity_id = {$todoId}")->fetchColumn() === 1);
    $chk = null;
    @unlink($db);
}
