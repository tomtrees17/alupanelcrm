<?php

// ── AI inventory lookup ──
// The property under test throughout: the model picks products, the DATABASE
// supplies every number. Nothing the model returns can become a stock figure.

require_once __DIR__ . '/../app/Ai.php';
require_once __DIR__ . '/../app/Csrf.php';   // the ask form renders a CSRF field

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

$mk = function (string $sku, string $zh, string $en, string $spec, int $stock, int $reserved = 0) use ($pdo): int {
    $pdo->prepare('INSERT INTO products (sku,name,color_zh,color_en,spec,size,unit,price,stock,reserved,min_stock)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$sku, $zh . ' ACP', $zh, $en, $spec, '1.220 x 2.440', '张', 235000, $stock, $reserved, 10]);
    return (int) $pdo->lastInsertId();
};
$silver = $mk('AS-4030', '银色拉丝', 'Silver Brushed', '4.0*0.30PVDF', 320, 45);
$white  = $mk('AW-3020', '白色', 'White', '3.0*0.20PE', 80, 0);
$gold   = $mk('AG-4030', '金色拉丝', 'Gold Brushed', '4.0*0.30PVDF', 5, 5);

// 1) The catalogue is what the model matches against — and must not leak stock.
$cat = Ai::catalogue($pdo);
ok('catalogue lists every product', substr_count($cat, "\n") === 2 && str_contains($cat, 'AS-4030'));
ok('catalogue carries the id first', str_starts_with($cat, $silver . '|AS-4030'), explode("\n", $cat)[0]);
ok('catalogue includes both colour languages', str_contains($cat, '银色拉丝/Silver Brushed'));
ok('catalogue does NOT contain stock levels', !str_contains($cat, '320') && !str_contains($cat, '45'));

// Byte-stability matters: the catalogue sits behind a prompt-cache breakpoint,
// so an unstable rendering would silently destroy the cache hit rate.
ok('catalogue rendering is stable', Ai::catalogue($pdo) === $cat);

// 2) The system prompt forbids quoting numbers and pins ids to the catalogue.
$sys = Ai::system_prompt($cat);
ok('prompt embeds the catalogue', str_contains($sys, 'AS-4030'));
ok('prompt forbids inventing ids', str_contains($sys, 'Never invent an id'));
ok('prompt forbids stating quantities', str_contains($sys, 'never state or guess a quantity'));

// 3) The response schema only accepts ids — there is no field a number could arrive in.
$sch = Ai::schema();
ok('schema requires product_ids', in_array('product_ids', $sch['required'], true));
ok('product_ids are integers', $sch['properties']['product_ids']['items']['type'] === 'integer');
ok('schema is closed', ($sch['additionalProperties'] ?? true) === false);
ok('schema has no stock field', !array_key_exists('stock', $sch['properties']) && !array_key_exists('available', $sch['properties']));

// 4) Stock always comes from the database, in the model's ranking.
$rows = ai_resolve_products($pdo, [$white, $silver]);
ok('resolves in the given order', array_column($rows, 'id') === [$white, $silver], implode(',', array_column($rows, 'id')));
ok('available = stock - reserved', (int) $rows[1]['available'] === 275, (string) $rows[1]['available']);
ok('unreserved product available = stock', (int) $rows[0]['available'] === 80);

// A model that hallucinates an id gets nothing back rather than a wrong answer.
$rows = ai_resolve_products($pdo, [99999, $silver, 0, -3]);
ok('unknown ids are dropped', count($rows) === 1 && (int) $rows[0]['id'] === $silver);
ok('empty id list resolves to nothing', ai_resolve_products($pdo, []) === []);
ok('at most 8 products resolved', count(ai_resolve_products($pdo, array_fill(0, 30, $silver))) <= 8);

// Fully reserved stock must read as zero available, not as "in stock".
$rows = ai_resolve_products($pdo, [$gold]);
ok('fully reserved shows zero available', (int) $rows[0]['available'] === 0, (string) $rows[0]['available']);

// 5) The offline stub matches well enough to exercise the whole feature.
[$parsed, $err, $usage] = Ai::match(['ai' => ['driver' => 'stub']], $cat, '4.0 银色拉丝还有多少张？');
ok('stub returns a parsed result', $parsed !== null && $err === '');
ok('stub picks the right product', ($parsed['product_ids'][0] ?? 0) === $silver, implode(',', $parsed['product_ids'] ?? []));
ok('stub matches Indonesian too', (Ai::stub_match($cat, 'stok silver brushed berapa')['product_ids'][0] ?? 0) === $silver);
ok('stub matches by SKU', (Ai::stub_match($cat, 'AW-3020')['product_ids'][0] ?? 0) === $white);
ok('stub reports no match honestly', Ai::stub_match($cat, 'zzzz nonexistent')['product_ids'] === []);

$q = Ai::stub_match($cat, '客户要 50 张 3.0 白色');
ok('stub extracts the quantity asked', $q['qty_asked'] === 50, var_export($q['qty_asked'], true));
ok('quantity is null when not mentioned', Ai::stub_match($cat, '白色还有吗')['qty_asked'] === null);

// 6) A missing API key must fail loudly rather than silently returning nothing.
[$p2, $e2] = Ai::match(['ai' => ['driver' => 'claude', 'key' => '']], $cat, 'x');
ok('missing key is reported', $p2 === null && str_contains($e2, 'key'));

// 7) Rate limiting + usage logging.
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")
    ->execute(['Ahmad', 'a@x.id', 'x', 'sales']);
$uid = (int) $pdo->lastInsertId();
$GLOBALS['auth'] = new AuthStub(['id' => $uid, 'name' => 'Ahmad', 'role' => 'sales']);

ok('starts at zero used today', ai_used_today($pdo, $uid) === 0);
ai_log($pdo, '4.0 银色', [$silver], true, '', ['in' => 12000, 'cached' => 11500, 'out' => 90]);
ok('usage counted', ai_used_today($pdo, $uid) === 1);

$row = $pdo->query('SELECT * FROM ai_queries ORDER BY id DESC LIMIT 1')->fetch();
ok('logs who asked what', $row['user_name'] === 'Ahmad' && $row['question'] === '4.0 银色');
ok('logs the matched ids', $row['matched'] === (string) $silver);
ok('logs token usage for cost tracking', (int) $row['cached_tokens'] === 11500 && (int) $row['out_tokens'] === 90);

ai_log($pdo, 'broken', [], false, 'HTTP 500: upstream');
$row = $pdo->query('SELECT * FROM ai_queries ORDER BY id DESC LIMIT 1')->fetch();
ok('failures are logged too', (int) $row['ok'] === 0 && str_contains((string) $row['error'], 'HTTP 500'));
ok('failed calls still count against the limit', ai_used_today($pdo, $uid) === 2);

// Yesterday's usage must not count against today's allowance.
$pdo->prepare("INSERT INTO ai_queries (user_id,user_name,question,created_at) VALUES (?,?,?,datetime('now','localtime','-1 day'))")
    ->execute([$uid, 'Ahmad', 'old']);
ok('older days do not count', ai_used_today($pdo, $uid) === 2);
ok('other users have their own allowance', ai_used_today($pdo, 999) === 0);

// Logging must never break the answer the user is waiting for.
$brokenPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$threw = false;
try {
    ai_log($brokenPdo, 'q', [1], true);
} catch (Throwable $e) {
    $threw = true;
}
ok('ai_log swallows its own failure', !$threw);

// ── The answer page ──
$viewFile = __DIR__ . '/../views/inventory/ask.php';
$render = function (array $data) use ($viewFile): string {
    $errors = [];
    set_error_handler(function (int $no, string $msg) use (&$errors): bool { $errors[] = $msg; return true; });
    extract($data, EXTR_SKIP);
    ob_start();
    include $viewFile;
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($errors) {
        throw new RuntimeException('view emitted: ' . implode(' | ', $errors));
    }
    return $html;
};
$base = ['question' => '', 'answer' => null, 'error' => '', 'used' => 0, 'limit' => 60, 'stub' => true];

ok('ask page renders empty', str_contains($render($base), t('ask_placeholder')));
ok('stub mode is disclosed', str_contains($render($base), t('ai_stub_mode')));
ok('real mode hides the stub badge', !str_contains($render(array_merge($base, ['stub' => false])), t('ai_stub_mode')));

$answer = ['understood' => '4.0 银色拉丝库存', 'clarify' => null, 'qty' => 50,
           'products' => ai_resolve_products($pdo, [$silver, $gold])];
$html = $render(array_merge($base, ['question' => '要 50 张 4.0 银色', 'answer' => $answer]));
ok('shows the live available figure', str_contains($html, '275'), 'expected 275');
ok('enough-stock verdict shown', str_contains($html, sprintf(t('ask_enough'), 50)));
ok('shortfall computed from live stock', str_contains($html, sprintf(t('ask_short'), 50)));   // gold: 0 available
ok('reserved surfaced so the number is explainable', str_contains($html, t('reserved')));

$html = $render(array_merge($base, ['question' => 'zzz', 'answer' => ['understood' => '', 'clarify' => 'no match', 'qty' => null, 'products' => []]]));
ok('no-match state renders', str_contains($html, t('ask_no_match')));

$html = $render(array_merge($base, ['error' => t('ai_failed')]));
ok('error state renders', str_contains($html, t('ai_failed')));

// The question is echoed back into the textarea — it must be escaped.
$html = $render(array_merge($base, ['question' => '"><script>alert(1)</script>']));
ok('question escaped in the form', !str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'));

// Product text comes from the database, but escape it anyway.
$evilId = $mk('<img src=x onerror=y>', '<script>bad</script>', 'X', 'Y', 1);
$html = $render(array_merge($base, ['answer' => ['understood' => '', 'clarify' => null, 'qty' => null,
        'products' => ai_resolve_products($pdo, [$evilId])]]));
ok('product fields escaped', !str_contains($html, '<script>bad</script>') && !str_contains($html, '<img src=x'));

// Indonesian must be complete for this page — sales staff are the users.
$_SESSION['lang'] = 'id';
$html = $render(array_merge($base, ['answer' => $answer, 'question' => 'x']));
$keys = ['ask_placeholder', 'ask_send', 'ask_hint', 'ask_qty_note', 'ask_enough', 'ask_short', 'ai_used_today', 'page_ask'];
$leak = array_values(array_filter($keys, fn($k) => str_contains($html, $k)));
ok('Indonesian ask page has no raw keys', $leak === [], implode(' ', $leak));
ok('Indonesian ask page is translated', str_contains($html, I18N['id']['ask_send']));
$_SESSION['lang'] = 'zh';

// ── The wire request (the one path that cannot be exercised without a key) ──
// Use a marker the prompt template cannot contain — the rules section quotes
// realistic examples like "4.0 银色拉丝", which would confound the check below.
$marker = 'ZQX-UNIQUE-QUESTION-7731';
$req = Ai::build_request(['model' => 'claude-opus-5'], $cat, $marker);
ok('model is Opus 5 by default', $req['model'] === 'claude-opus-5');
ok('config can override the model', Ai::build_request(['model' => 'claude-haiku-4-5'], $cat, 'x')['model'] === 'claude-haiku-4-5');

// The cache breakpoint must sit on the catalogue, with the question after it —
// putting the question inside the cached prefix would break every cache hit.
ok('system is a block array', is_array($req['system']) && isset($req['system'][0]['text']));
ok('cache breakpoint on the catalogue block', ($req['system'][0]['cache_control']['type'] ?? '') === 'ephemeral');
ok('question is NOT in the cached prefix', !str_contains($req['system'][0]['text'], $marker));
ok('question is in messages', $req['messages'][0]['content'] === $marker && $req['messages'][0]['role'] === 'user');

ok('structured output requested', ($req['output_config']['format']['type'] ?? '') === 'json_schema');
ok('schema travels with the request', ($req['output_config']['format']['schema']['properties']['product_ids']['items']['type'] ?? '') === 'integer');
ok('effort tuned down for a lookup', ($req['output_config']['effort'] ?? '') === 'low');
ok('no deprecated budget_tokens', !isset($req['thinking']['budget_tokens']));
ok('max_tokens leaves room for the answer', (int) $req['max_tokens'] >= 1000);

// It must survive JSON encoding with Chinese text intact (not \uXXXX-mangled).
$encoded = json_encode($req, JSON_UNESCAPED_UNICODE);
ok('request encodes to valid JSON', is_string($encoded) && json_decode($encoded, true) !== null);
ok('Chinese survives encoding', str_contains((string) $encoded, '银色拉丝'));

// The cached prefix must be identical for two different questions, or caching
// silently never hits.
$a = Ai::build_request(['model' => 'claude-opus-5'], $cat, 'question one');
$b = Ai::build_request(['model' => 'claude-opus-5'], $cat, 'question two');
ok('cached prefix identical across questions', $a['system'] === $b['system']);
