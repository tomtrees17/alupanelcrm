<?php

// ── 粘贴识别: pasted sales message → order form fields ──

require_once __DIR__ . '/../app/Csrf.php';

// ── 1) Spec normalisation (what the salespeople type vs the catalogue) ──────
ok('3mm x 0.04 → 3.0*0.04', OrderParse::normalize_spec('3mm x 0.04') === '3.0*0.04');
ok('4mm x 0,21 → 4.0*0.21', OrderParse::normalize_spec('4mm x 0,21') === '4.0*0.21');
ok('catalogue spec passes through', OrderParse::normalize_spec('3.0*0.06背涂') === '3.0*0.06背涂');
ok('short catalogue form passes through', OrderParse::normalize_spec('3.10拉丝') === '3.10拉丝');
ok('empty spec recovered from the raw line', OrderParse::normalize_spec('', '( 3mm x 0.06 ) SJ8001 ( PURE WHITE ) = 50') === '3.0*0.06');

// ── 2) Resolving the model's answer against the platform's records ─────────
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Tania', 't@x', 'x', 'sales'), ('Ridwan', 'r@x', 'x', 'sales')");
$pdo->exec("INSERT INTO customers (name, company, phone, owner) VALUES ('Karno Utomo', NULL, '0878-8953-9820', 'Fera'), ('Arif', 'CV Kita Murah', '0812', 'Ridwan')");
$pdo->exec("INSERT INTO products (sku, name, color_en, color_zh, spec, size, price, stock) VALUES
    ('SJ8019', 'x', 'Silver Metallic', '闪银', '3.0*0.04背涂', '1.220 x 2.440', 130000, 100),
    ('SJ8019', 'x', 'Silver Metallic', '闪银', '4.0*0.21', '1.220 x 2.440', 440000, 100),
    ('SJ2023-012', 'x', 'Italy White Marble', '意大利白', '4.0*0.15石纹', '1.220 x 2.440', 450000, 10),
    ('SJ8023', 'x', 'Coffee Champagne', '咖啡香槟', '3.0*0.06背涂', '1.220 x 2.440', 150000, 50),
    ('SJ8001', 'x', 'Pure White', '纯白', '3.0*0.06背涂', '1.220 x 2.440', 150000, 500),
    ('SJ8001', 'x', 'Pure White', '纯白', '4.0*0.15石纹', '1.220 x 2.440', 300000, 50),
    ('SJ8001', 'x', 'Pure White', '纯白', '4.0*0.15抗刮木纹', '1.220 x 2.440', 450000, 50)");

$cat = OrderParse::catalogue($pdo);
ok('catalogue: one line per SKU with all specs', substr_count($cat, "\n") === 3 && str_contains($cat, 'SJ8019: Silver Metallic / 闪银 — specs: 3.0*0.04背涂 | 4.0*0.21'), $cat);
ok('schema lists the delivery services the form offers', in_array('Lala Move', OrderParse::schema()['properties']['delivery_service']['enum'], true));

// Sample 1: "Bantu siapin orderan : Tania ( Cust Denny ) … SJ8019 … SJ2023-012 … Lalamove"
$model = [
    'sales_name' => 'Tania', 'customer_name' => 'Denny', 'company' => '', 'phone' => '', 'address' => '',
    'delivery_address' => 'Jl. Samba No.24, Tanah Tinggi, Johar Baru, Jakarta Pusat', 'delivery_service' => 'Lala Move',
    'delivery_date' => '2026-10-24', 'payment_term' => '', 'custom_days' => 0, 'note' => 'Besok dipickup Lalamove',
    'items' => [
        ['sku' => 'SJ8019', 'spec' => '3mm x 0.04', 'color' => 'SILVER', 'qty' => 18, 'price' => null, 'raw' => '( 3mm x 0.04 ) SJ8019 ( SILVER ) = 18'],
        ['sku' => 'SJ2023-012', 'spec' => '4.0*0.15石纹', 'color' => 'Italy White Marble', 'qty' => 1, 'price' => null, 'raw' => '( 4mm x 0.15 ) SJ2023-012 ( Italy White Marble ) = 1'],
    ],
    'uncertain' => [],
];
$d = OrderParse::resolve($pdo, $model);
ok('unknown customer flagged as new, name kept', $d['new_customer'] && $d['customer_id'] === null && $d['customer_name'] === 'Denny');
ok('salesperson matched to the user', $d['sales_name'] === 'Tania');
ok('typed spec resolved to the catalogue product (unique prefix)', $d['items'][0]['matched'] && $d['items'][0]['spec'] === '3.0*0.04背涂' && $d['items'][0]['price'] === 130000.0 && $d['items'][0]['color'] === 'Silver Metallic', json_encode($d['items'][0]));
ok('catalogue spec matched exactly', $d['items'][1]['matched'] && $d['items'][1]['product_id'] === 3 && $d['items'][1]['qty'] === 1.0);
ok('delivery fields pass through', $d['delivery_service'] === 'Lala Move' && $d['delivery_date'] === '2026-10-24' && $d['uncertain'] === []);

// Sample 2: existing customer, no salesperson named → the customer's owner
$model2 = $model;
$model2['sales_name'] = '';
$model2['customer_name'] = 'Karno Utomo';
$model2['items'] = [['sku' => 'sj8023', 'spec' => '3mm x 0.06', 'color' => 'COFFEE CHAMPAGNE', 'qty' => 2, 'price' => null, 'raw' => '']];
$d = OrderParse::resolve($pdo, $model2);
ok('existing customer linked, phone filled from the record, owner proposed as salesperson', $d['customer_id'] === 1 && $d['phone'] === '0878-8953-9820' && $d['sales_name'] === 'Fera' && !$d['new_customer']);
ok('lower-case sku still matches', $d['items'][0]['matched'] && $d['items'][0]['sku'] === 'SJ8023');

// Sample 3: company given, ambiguous spec, written price
$model3 = $model;
$model3['sales_name'] = 'Bp Ridwan';
$model3['customer_name'] = 'Arif';
$model3['company'] = 'CV Kita Murah';
$model3['items'] = [
    ['sku' => 'SJ8001', 'spec' => '4mm x 0.15', 'color' => 'PURE WHITE', 'qty' => 50, 'price' => 320000, 'raw' => ''],
    ['sku' => 'SJ9999', 'spec' => '3mm x 0.06', 'color' => 'NOPE', 'qty' => 5, 'price' => null, 'raw' => '( 3mm x 0.06 ) SJ9999 ( NOPE ) = 5'],
];
$d = OrderParse::resolve($pdo, $model3);
ok('customer matched through the company name', $d['customer_id'] === 2 && $d['customer_name'] === 'Arif' && $d['company'] === 'CV Kita Murah');
ok('honorific-less salesperson name still matched', $d['sales_name'] === 'Ridwan');
ok('two catalogue specs share the prefix → not matched, flagged', !$d['items'][0]['matched'] && in_array('items.0.spec', $d['uncertain'], true) && $d['items'][0]['price'] === 320000.0);
ok('unknown sku → row left for the assistant, flagged', !$d['items'][1]['matched'] && in_array('items.1', $d['uncertain'], true) && $d['items'][1]['spec'] === '3.0*0.06');

// ── 3) Through AiTask with a faked model ────────────────────────────────────
$cfg = ['ai' => ['key' => 'sk-test', 'model' => 'claude-opus-5-5', 'max_tokens' => 4096, 'retries' => 0, 'daily_token_budget' => 0,
    'tiers' => ['standard' => 'claude-opus-5-5'], 'tasks' => ['order_parse' => ['tier' => 'standard', 'effort' => 'low', 'max_tokens' => 4096]],
    'prices' => ['default' => ['in' => 1, 'out' => 1, 'cache_read' => 0, 'cache_write' => 0]]]];
$GLOBALS['config'] = $cfg + ($GLOBALS['config'] ?? []);
$GLOBALS['auth'] = null;   // an earlier test file leaves a string in $auth (offsite_test); the ledger reads the global
$sent = [];
$reply = fn(string $text) => [200, json_encode(['model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2000, 'output_tokens' => 300]]), []];
Claude::$http = function (string $url, string $body, array $headers) use (&$sent, $reply, $model): array {
    $sent[] = json_decode($body, true);
    return $reply(json_encode($model, JSON_UNESCAPED_UNICODE));
};
Claude::$sleep = function (float $s): void {};
$r = OrderParse::parse($pdo, $cfg, "Bantu siapin orderan :   Tania     (   Cust Denny  )\n( 3mm x 0.04 ) SJ8019 ( SILVER ) = 18", '2026-10-23');
ok('parse runs through the task layer and resolves', $r['ok'] && $r['data']['items'][0]['matched'] && $r['data']['sales_name'] === 'Tania', json_encode($r));
$sentMsg = json_decode($sent[0]['messages'][0]['content'][0]['text'] ?? ($sent[0]['messages'][0]['content'] ?? ''), true);
ok('the model gets the message, today and the catalogue', is_array($sentMsg) && str_contains($sentMsg['message'], 'Cust Denny') && $sentMsg['today'] === '2026-10-23' && str_contains($sentMsg['catalogue'], 'SJ8019'), json_encode(array_keys((array) $sentMsg)));
ok('prompt file is the newest order_parse version', AiTask::prompt('order_parse')[1] >= 1 && str_contains(AiTask::prompt('order_parse')[0], 'Tanggal Pengiriman'));
ok('a call is logged', (int) $pdo->query("SELECT COUNT(*) FROM ai_calls WHERE task_type = 'order_parse' AND status = 'ok'")->fetchColumn() === 1);
ok('empty text is refused without a call', !OrderParse::parse($pdo, $cfg, '   ')['ok'] && count($sent) === 1);
Claude::$http = null;
Claude::$sleep = null;

// ── 4) Endpoint and form ────────────────────────────────────────────────────
$harness = __DIR__ . '/support/run_controller.php';
if (function_exists('exec') && is_file($harness)) {
    $db = tempnam(sys_get_temp_dir(), 'op') . '.sqlite';
    $f = new PDO('sqlite:' . $db);
    $f->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $f->exec("INSERT INTO role_permissions (role, module) VALUES ('sales','orders')");
    $f = null;
    $run = function (string $action, array $user, array $get = [], ?array $post = null) use ($db, $harness): string {
        $args = [PHP_BINARY, $harness, $db, 'orders', $action, json_encode($get ?: new stdClass()), json_encode($user), $post === null ? '' : json_encode($post)];
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out);
        return implode("\n", $out);
    };
    $sales = ['id' => 3, 'name' => 'Ridwan', 'role' => 'sales'];
    $html = $run('create', $sales);
    ok('new-order page carries the paste box', str_contains($html, 'id="parse-text"') && str_contains($html, 'id="parse-btn"') && str_contains($html, t('parse_title')));
    $json = $run('parse', $sales, [], ['text' => 'anything']);
    ok('endpoint answers JSON and says when AI is off (no key in the test config)', str_contains($json, '"ok":false') && str_contains($json, t('parse_ai_off')), substr($json, 0, 200));
    @unlink($db);
}
