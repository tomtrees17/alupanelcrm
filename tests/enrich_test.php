<?php

// ── Customer region + industry inference (app/Enrich.php) ──

$cfgFull = (array) (@include __DIR__ . '/../config.php');
$icfg = $cfgFull['insights'] ?? [];
ok('config has the region map', isset($icfg['region_map']['jakarta utara']));

// 1) Address text → region. Real addresses from the order book.
$cases = [
    ['Jln Pinang No.29 RT 8/6 Lagoa Kec : Koja, Jakarta Utara', 'jkt_utara'],
    ['JL KRAMAT JALAN I NO 8 RT 010 RW 007 KELUIRAHAN TANAH SEREAL KECAMATAN TAMBORA', 'jkt_barat'],
    ['JL KEMUNING I BOJONG MENTENG RT 003 RW 003 KELURAHAN MENTENG KECAMATAN RAWALUMBU', 'bekasi'],
    ['Jl. Raya Bekasi KM 25, Cakung, Jakarta Timur', 'jkt_timur'],
    ['Jl. Raya Bogor Km 30, Kec. Ciracas', 'jkt_timur'],
    ['KP SALEBENDA BLOK - NO 86 RT 003 RW 00 PARAKANJAYA KEMANG KABUPATEN BOGOR JAWA BARAT', 'bogor_depok'],
    ['GRAHA ZIMA KAVLING 1 JALAN TB SIMATUPANG RT GEDONG PASAR REBO KOTA ADM JAKARTA TIMUR DKI JAKARTA', 'jkt_timur'],
    ['JIn pengukiran 2 no 23, Pasar pagi, Pekojan, Jakarta Barat', 'jkt_barat'],
    ['Jl. Percetakan Negara raya C 265, Jakarta Pusat', 'jkt_pusat'],
    ['JL. SAWO, SRIMUKTI, KEC. TAMBUN UTARA, KAB. BEKASI, 17510.', 'bekasi'],
    ['Jl. Pahlawan No 57 Kp Cirewed Duren Jaya Bekasi Timur', 'bekasi'],
    ['Ruko Alam Sutera, Kota Tangerang Selatan', 'tangerang'],
    ['KP PEUSAR RT003 RW001 KEL BINONG KEC CURUG', 'tangerang'],
    ['bogor jawa barat', 'bogor_depok'],
    ['Bekasi', 'bekasi'],
    ['jember jawa timur', 'other'],
];
$bad = [];
foreach ($cases as [$addr, $want]) {
    $got = Enrich::text_region($addr, $icfg)['region'] ?? null;
    if ($got !== $want) {
        $bad[] = "{$addr} → " . var_export($got, true) . " (want {$want})";
    }
}
ok('address text → region on ' . count($cases) . ' real addresses', $bad === [], implode(' | ', $bad));
ok('a street named after a kota does not count ("Jl. Raya Bekasi" in Cakung)', Enrich::text_region('Jl. Raya Bekasi No 5', $icfg) === null);
ok('kecamatan names count only after "Kec" (Menteng the kelurahan ≠ Menteng the kecamatan)', Enrich::text_region('Kelurahan Menteng', $icfg) === null);
ok('unrecognisable text → null', Enrich::text_region('lippo mall nusantara', $icfg) === null && Enrich::text_region('RUKO CBD PLUIT,BLOK B/01', $icfg) === null);
ok('explicit "Kota/Kab" beats a bare mention', Enrich::text_region('Jakarta Timur, perbatasan Kota Bekasi', $icfg)['strength'] === 'explicit');
ok('outside-region markers are weakest', Enrich::text_region('Surabaya', $icfg)['strength'] === 'marker');

$kotas = ['Kota Jakarta Utara' => 'jkt_utara', 'Kota Administrasi Jakarta Selatan' => 'jkt_selatan', 'Kabupaten Bekasi' => 'bekasi',
    'Kota Bekasi' => 'bekasi', 'Kota Depok' => 'bogor_depok', 'Kabupaten Bogor' => 'bogor_depok', 'Kota Tangerang Selatan' => 'tangerang',
    'Kabupaten Tangerang' => 'tangerang', 'Kota Jakarta Pusat' => 'jkt_pusat', 'Kabupaten Jember' => 'other', 'Kota Bandung' => 'other',
    'North Jakarta City' => 'jkt_utara'];
$bad = [];
foreach ($kotas as $k => $want) {
    if (Enrich::kota_region($k, $icfg) !== $want) {
        $bad[] = $k . ' → ' . Enrich::kota_region($k, $icfg);
    }
}
ok('Google kota names → region', $bad === [], implode(' | ', $bad));

ok('order address: delivery address first', Enrich::order_address(['delivery_address' => 'Jl A no 1 Bekasi', 'address' => 'Jl B']) === 'Jl A no 1 Bekasi');
ok('order address: falls back to the order address', Enrich::order_address(['delivery_address' => '', 'address' => 'Tambun Utara Bekasi']) === 'Tambun Utara Bekasi');
ok('order address: "Ambil Gudang" / "Self Pickup" are not addresses', Enrich::order_address(['delivery_address' => 'Ambil Gudang', 'address' => 'Self Pickup', 'cust_city' => 'KEC KOJA']) === 'KEC KOJA');
ok('"Gn Putri" after Kec is Gunung Putri (Bogor)', (Enrich::text_region('kp cikeas, desa nagrak, kec gn putri', $icfg)['region'] ?? '') === 'bogor_depok');
$tmpPdo = new PDO('sqlite::memory:');
$tmpPdo->exec('CREATE TABLE geocode_cache (addr_key TEXT PRIMARY KEY, query TEXT, status TEXT, lat REAL, lng REAL, kota TEXT, kecamatan TEXT, error TEXT, created_at TEXT)');
$ro = Enrich::resolve_order($tmpPdo, ['insights' => $icfg, 'geo' => ['driver' => '']], ['delivery_address' => 'cust datang ke showroom', 'address' => 'Tambun Utara Bekasi']);
ok('a delivery box with no place in it falls through to the order address', $ro['region'] === 'bekasi');
foreach (['Expedisi', 'expedisi sahabat', 'kirim ke expedisi sahabat', 'dikirim expedisi', 'Ekspedisi JNE', 'cust datang ke showeroom', 'via lalamove'] as $note) {
    if (Enrich::usable_address($note)) {
        ok("courier note is not an address: {$note}", false);
    }
}
ok('courier / pickup notes are never geocoded', true);
ok('…and the order address is used instead', Enrich::order_address(['delivery_address' => 'kirim ke expedisi sahabat', 'address' => 'Jl Kaca 1, Kec Koja']) === 'Jl Kaca 1, Kec Koja');
ok('a real address mentioning a street is still fine', Enrich::usable_address('Jl. Swatantra V RT 09, Jatiasih, Bekasi'));
ok('order address: nothing usable → empty', Enrich::order_address(['delivery_address' => '-', 'address' => '']) === '');

// 2) Google response parsing.
$gOk = json_encode(['status' => 'OK', 'results' => [[
    'address_components' => [
        ['long_name' => 'Koja', 'types' => ['administrative_area_level_3', 'political']],
        ['long_name' => 'Kota Jakarta Utara', 'types' => ['administrative_area_level_2', 'political']],
        ['long_name' => 'Daerah Khusus Ibukota Jakarta', 'types' => ['administrative_area_level_1', 'political']],
    ],
    'geometry' => ['location' => ['lat' => -6.117, 'lng' => 106.906]],
]]]);
$p = Enrich::parse_google($gOk);
ok('google OK → kota, kecamatan, point', $p['status'] === 'ok' && $p['kota'] === 'Kota Jakarta Utara' && $p['kecamatan'] === 'Koja' && $p['lat'] === -6.117);
ok('google ZERO_RESULTS → zero', Enrich::parse_google('{"status":"ZERO_RESULTS","results":[]}')['status'] === 'zero');
$denied = Enrich::parse_google('{"status":"REQUEST_DENIED","error_message":"The provided API key is invalid."}');
ok('google REQUEST_DENIED → error with the reason', $denied['status'] === 'error' && str_contains($denied['error'], 'invalid'));
$coarse = Enrich::parse_google(json_encode(['status' => 'OK', 'results' => [['address_components' => [['long_name' => 'Jawa Barat', 'types' => ['administrative_area_level_1']]], 'geometry' => ['location' => ['lat' => -7, 'lng' => 107]]]]]));
ok('google province-only → coarse', $coarse['status'] === 'coarse');
ok('google garbage → error', Enrich::parse_google('<html>')['status'] === 'error');

// 3) resolve(): cache, precedence, no network when off.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$schema = @file_get_contents(__DIR__ . '/../database/schema.sql');
$pdo->exec($schema === false ? (string) ($GLOBALS['__schema'] ?? '') : $schema);

$geoCalls = [];
$geoReply = [];   // address substring => response json
Enrich::$http = function (string $url, ?string $body, array $headers) use (&$geoCalls, &$geoReply): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $geoCalls[] = $q;
    foreach ($geoReply as $needle => $json) {
        if (stripos((string) $q['address'], $needle) !== false) {
            return [200, $json];
        }
    }
    return [200, '{"status":"ZERO_RESULTS","results":[]}'];
};
$gResp = fn(string $kota, float $lat, float $lng) => json_encode(['status' => 'OK', 'results' => [[
    'address_components' => [['long_name' => $kota, 'types' => ['administrative_area_level_2']]],
    'geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]]]]]);

$off = ['insights' => $icfg, 'geo' => ['driver' => '', 'key' => '']];
$on = ['insights' => $icfg, 'geo' => ['driver' => 'google', 'key' => 'AIzaTEST']];
$r = Enrich::resolve($pdo, $off, 'Jl Pinang, Kec Koja');
ok('geocoding off: text region, no point, no network', $r['region'] === 'jkt_utara' && $r['lat'] === null && $r['source'] === 'text' && $geoCalls === []);

$geoReply = ['Pluit' => $gResp('Kota Jakarta Utara', -6.12, 106.79)];
$r = Enrich::resolve($pdo, $on, 'RUKO CBD PLUIT, BLOK B/01');
ok('google fills what the text cannot', $r['region'] === 'jkt_utara' && $r['lat'] === -6.12 && $r['source'] === 'google');
ok('lookups are restricted to Indonesia', ($geoCalls[0]['components'] ?? '') === 'country:ID' && ($geoCalls[0]['key'] ?? '') === 'AIzaTEST');
Enrich::resolve($pdo, $on, 'Ruko CBD Pluit,  Blok B-01');
ok('the same address (normalised) is never paid for twice', count($geoCalls) === 1);

$geoReply = ['Kota Bekasi' => $gResp('Kota Jakarta Timur', -6.2, 106.9)];
$r = Enrich::resolve($pdo, $on, 'Jl Mawar 3, Kota Bekasi');
ok('text names a kota, Google disagrees → text wins, point dropped', $r['region'] === 'bekasi' && $r['lat'] === null && $r['source'] === 'text');

$geoReply = ['Nowhere' => '{"status":"REQUEST_DENIED","error_message":"bad key"}'];
$r = Enrich::resolve($pdo, $on, 'Nowhere Street, Kec Tambora');
ok('key error → falls back to text and reports it', $r['region'] === 'jkt_barat' && str_contains($r['geo_error'], 'bad key'));
$before = count($geoCalls);
Enrich::resolve($pdo, $on, 'Nowhere Street, Kec Tambora');
ok('errors are not cached (retried next run)', count($geoCalls) === $before + 1);
Enrich::resolve($pdo, $on, 'zz unknown place 1');
$before = count($geoCalls);
Enrich::resolve($pdo, $on, 'zz unknown place 1');
ok('"no result" is cached', count($geoCalls) === $before);
ok('empty address → unknown without a call', Enrich::resolve($pdo, $on, '  ')['region'] === 'unknown');

// 4) Customer region from the last three shipped orders.
ok('mode of the last 3', Enrich::customer_region(['bekasi', 'jkt_timur', 'bekasi', 'jkt_barat', 'jkt_barat']) === 'bekasi');
ok('tie → most recent', Enrich::customer_region(['jkt_timur', 'bekasi']) === 'jkt_timur');
ok('unknowns ignored', Enrich::customer_region(['unknown', 'bekasi', 'unknown']) === 'bekasi');
ok('only older orders known → still unknown (last 3 only)', Enrich::customer_region(['unknown', 'unknown', 'unknown', 'bekasi']) === 'unknown');
ok('no shipped orders → null', Enrich::customer_region([]) === null);

// 5) Industry rules.
$rule = fn(array $c, array $s = []) => Enrich::industry_rule($c, $s + ['client_types' => [], 'addresses' => [], 'total_qty' => 0, 'orders' => 0], $icfg)[0] ?? null;
ok('Advertising → signage', $rule(['name' => 'Budi', 'company' => 'CV. RAJA KARYA ADVERTISING']) === 'signage');
ok('Alumunium (common misspelling) → aluminium shop', $rule(['name' => 'X', 'company' => 'Raffa Alumunium']) === 'aluminium_shop');
ok('Kaca → aluminium shop', $rule(['name' => 'X', 'company' => 'TOKO ALUMNI KACA']) === 'aluminium_shop');
ok('Konstruksi → contractor', $rule(['name' => 'X', 'company' => 'PT Konstruksi Prima']) === 'contractor');
ok('"Pembangunan" is not the word "Bangun"', $rule(['name' => 'X', 'company' => 'PT Pembangunan Sejahtera']) === null);
ok('Interior / Renovasi → renovation', $rule(['name' => 'X', 'company' => 'Aluma Interior']) === 'renovation' && $rule(['name' => 'X', 'company' => 'PT OUYA RENOVASI INDONESIA']) === 'renovation');
ok('order client type Distributor → distributor', $rule(['name' => 'X', 'company' => 'PT Sinar'], ['client_types' => ['Distributor' => 3, 'End User' => 1]]) === 'distributor');
ok('"End User" (form default) decides nothing', $rule(['name' => 'X', 'company' => 'PT Sinar'], ['client_types' => ['End User' => 5]]) === null);
ok('"Proyek" in the delivery address → contractor', $rule(['name' => 'X', 'company' => 'PT Sinar'], ['addresses' => ['Proyek Apartemen Green Bay, Pluit']]) === 'contractor');
ok('personal name, no company, small qty → end owner', $rule(['name' => 'Hendra', 'company' => ''], ['orders' => 1, 'total_qty' => 8]) === 'end_owner');
ok('company equal to the name counts as no company', $rule(['name' => 'Hendra', 'company' => 'HENDRA'], ['orders' => 2, 'total_qty' => 12]) === 'end_owner');
ok('personal name but big volume → left for the model', $rule(['name' => 'Hendra', 'company' => ''], ['orders' => 4, 'total_qty' => 300]) === null);
ok('a business-looking name is not an end owner', $rule(['name' => 'Toko Sinar', 'company' => ''], ['orders' => 1, 'total_qty' => 5]) === null);
ok('no orders → end-owner rule does not fire', $rule(['name' => 'Hendra', 'company' => '']) === null);

// 6) The model call: request shape and defensive parsing.
$req = Enrich::ai_request(['insights' => ['industry_model' => 'claude-opus-5-5']], [['id' => 7, 'name' => 'Toko 名字']]);
ok('model from config', $req['model'] === 'claude-opus-5-5');
ok('structured output with the industry enum', ($req['output_config']['format']['schema']['properties']['results']['items']['properties']['industry']['enum'] ?? []) === Enrich::INDUSTRIES);
ok('low effort, no budget_tokens, no prefill', $req['output_config']['effort'] === 'low' && !isset($req['thinking']) && end($req['messages'])['role'] === 'user');
ok('server-side fallback on refusal', $req['fallbacks'] === 'default');
ok('per-call output cap is modest', $req['max_tokens'] <= 4096);
ok('customer facts travel as JSON lines, unicode intact', str_contains($req['messages'][0]['content'], '"Toko 名字"'));

$resp = ['content' => [['type' => 'text', 'text' => json_encode(['results' => [
    ['id' => 7, 'industry' => 'signage', 'confidence' => 0.92, 'reason' => '公司名像广告'],
    ['id' => 999, 'industry' => 'signage', 'confidence' => 0.9, 'reason' => 'not asked'],
    ['id' => 8, 'industry' => 'bakery', 'confidence' => 0.9, 'reason' => 'off-enum'],
    ['id' => 9, 'industry' => 'other', 'confidence' => 7, 'reason' => 'x'],
]])]]];
$parsed = Enrich::parse_ai($resp, [7, 8, 9]);
ok('ids not asked about are dropped', !isset($parsed[999]));
ok('industries outside the enum are dropped', !isset($parsed[8]));
ok('confidence clamped to [0,1]', $parsed[9]['confidence'] === 1.0);
ok('valid answer kept', $parsed[7]['industry'] === 'signage' && $parsed[7]['confidence'] === 0.92);

// 7) A whole run, end to end, with both APIs stubbed.
$pdo->exec('DELETE FROM geocode_cache');
$geoCalls = [];
$aiCalls = [];
$aiReply = [];   // id => [industry, confidence]
Claude::$sleep = function (float $s): void {};
Claude::$http = function (string $url, string $body, array $headers) use (&$aiCalls, &$aiReply): array {
    $b = json_decode($body, true);
    $aiCalls[] = $b;
    $results = [];
    foreach (explode("\n", $b['messages'][0]['content']) as $line) {
        $row = json_decode($line, true);
        [$ind, $conf] = $aiReply[$row['id']] ?? ['other', 0.5];
        $results[] = ['id' => $row['id'], 'industry' => $ind, 'confidence' => $conf, 'reason' => 'stub'];
    }
    return [200, json_encode(['content' => [['type' => 'text', 'text' => json_encode(['results' => $results])]],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 500, 'output_tokens' => 100]]), []];
};

$addC = function (string $name, string $company, string $extra = '') use ($pdo): int {
    $pdo->prepare('INSERT INTO customers (name, company, owner) VALUES (?,?,?)')->execute([$name, $company, 'rina']);
    return (int) $pdo->lastInsertId();
};
$addO = function (int $cid, string $addr, string $status = 'approved', string $date = '2026-09-01', float $qty = 10, string $type = 'End User') use ($pdo): int {
    $pdo->prepare('INSERT INTO orders (order_no, customer_id, customer_name, delivery_address, status, client_type, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute(['SO-' . uniqid('', true), $cid, 'c', $addr, $status, $type, $date . ' 10:00:00']);
    $oid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO order_items (order_id, sku, spec, qty, price) VALUES (?, 'SJ1', '4.0*0.21', ?, 100000)")->execute([$oid, $qty]);
    return $oid;
};
$cKaca = $addC('Ali', 'Toko Kaca Jaya');
$cHendra = $addC('Hendra', '');
$cSinar = $addC('Sinar', 'PT Sinar Abadi');
$cMaju = $addC('Maju', 'Maju Bersama');
$cManual = $addC('Rudi', 'Reklame Rudi');
$cNoOrders = $addC('Baru', 'CV Baru');
$pdo->prepare("UPDATE customers SET industry = 'other', industry_source = 'manual' WHERE id = ?")->execute([$cManual]);

$addO($cKaca, 'Jl Kaca 1, Kec Koja', 'approved', '2026-08-01');
$addO($cKaca, 'Jl Kaca 1, Kec Koja', 'approved', '2026-09-01');
$addO($cKaca, 'Gudang Bekasi, Kota Bekasi', 'approved', '2026-10-01');
$addO($cHendra, 'Rumah, Ruko Pluit', 'approved', '2026-09-10', 6);
$addO($cSinar, 'zz unknown place 9', 'approved', '2026-09-11', 200);
$addO($cMaju, '', 'approved', '2026-09-12', 50);
$draft = $addO($cMaju, 'Kota Depok', 'draft', '2026-10-10');
$addO($cManual, 'Kec Tambora', 'approved', '2026-09-13');
$geoReply = ['Pluit' => $gResp('Kota Jakarta Utara', -6.12, 106.79)];
$aiReply = [$cSinar => ['distributor', 0.9], $cMaju => ['renovation', 0.4], $cNoOrders => ['other', 0.8]];

$runCfg = ['insights' => $icfg + ['industry_ai' => true], 'geo' => ['driver' => 'google', 'key' => 'AIzaTEST'],
    'ai' => ['key' => 'sk-test', 'max_tokens' => 4096, 'retries' => 1, 'daily_token_budget' => 100000]];
$logLines = [];
$sum = Enrich::run($pdo, $runCfg, [], function (string $l) use (&$logLines): void {
    $logLines[] = $l;
});
$c = fn(int $id): array => $pdo->query("SELECT * FROM customers WHERE id = {$id}")->fetch();
$o = fn(int $id): array => $pdo->query("SELECT * FROM orders WHERE id = {$id}")->fetch();

ok('run: rule customers decided by rule', $c($cKaca)['industry'] === 'aluminium_shop' && $c($cKaca)['industry_source'] === 'rule' && $c($cHendra)['industry'] === 'end_owner');
ok('run: model customers decided by AI', $c($cSinar)['industry'] === 'distributor' && $c($cSinar)['industry_source'] === 'ai' && (int) $c($cSinar)['industry_pending'] === 0);
ok('run: low confidence → 待确认', $c($cMaju)['industry'] === 'renovation' && (int) $c($cMaju)['industry_pending'] === 1);
ok('run: a manual answer is never overwritten (even when a rule would match)', $c($cManual)['industry'] === 'other' && $c($cManual)['industry_source'] === 'manual');
ok('run: customers without orders still get an industry', $c($cNoOrders)['industry'] === 'other');
ok('run: rule hits never reach the model', !str_contains(json_encode($aiCalls), 'Toko Kaca Jaya') && !str_contains(json_encode($aiCalls), 'Reklame Rudi'));
ok('run: one batched call for the model customers', count($aiCalls) === 1, (string) count($aiCalls));
ok('run: AI usage is recorded in the ledger (feeds the daily budget)', Claude::used_today($pdo) === 600);

ok('run: customer region = mode of last 3 (2× Koja, 1× Bekasi → Jakarta Utara)', $c($cKaca)['region'] === 'jkt_utara');
ok('run: delivery spread stored', json_decode((string) $c($cKaca)['delivery_regions'], true) === ['jkt_utara' => 2, 'bekasi' => 1]);
ok('run: geocoded order has a map point', $c($cHendra)['region'] === 'jkt_utara' && $o($pdo->query("SELECT id FROM orders WHERE customer_id = {$cHendra}")->fetchColumn())['delivery_lat'] !== null);
ok('run: unresolvable → region unknown', $c($cSinar)['region'] === 'unknown');
ok('run: no address at all → unknown', $c($cMaju)['region'] === 'unknown');
ok('run: customers with no shipped order have no region', $c($cNoOrders)['region'] === null);
ok('run: draft orders are not processed', $o($draft)['delivery_region'] === null);

// Second (nightly) run: nothing new → no paid calls at all.
$geoBefore = count($geoCalls);
$aiBefore = count($aiCalls);
Enrich::run($pdo, $runCfg, [], fn($l) => null);
ok('incremental run: no repeat geocoding', count($geoCalls) === $geoBefore, (string) (count($geoCalls) - $geoBefore));
ok('incremental run: no repeat model calls', count($aiCalls) === $aiBefore);

// Salesperson confirms Maju by hand; a full re-run must keep it.
$pdo->prepare("UPDATE customers SET industry = 'contractor', industry_source = 'manual', industry_pending = 0 WHERE id = ?")->execute([$cMaju]);
Enrich::run($pdo, $runCfg, ['all' => true], fn($l) => null);
ok('--all re-run keeps the manual answer', $c($cMaju)['industry'] === 'contractor' && $c($cMaju)['industry_source'] === 'manual');

// A text-only run (key removed) must not erase points found earlier.
$textCfg = $runCfg;
$textCfg['geo'] = ['driver' => '', 'key' => ''];
Enrich::run($pdo, $textCfg, ['all' => true], fn($l) => null);
ok('text-only re-run keeps earlier map points', $pdo->query("SELECT COUNT(*) FROM orders WHERE delivery_lat IS NOT NULL")->fetchColumn() >= 1);

// Guards: the budget stops the model; failures stop after three batches.
$pdo->exec("UPDATE customers SET industry = NULL, industry_source = NULL WHERE COALESCE(industry_source,'') <> 'manual'");
$tight = $runCfg;
$tight['ai']['daily_token_budget'] = 600;    // already spent today
$aiBefore = count($aiCalls);
$lines = [];
$s = Enrich::run($pdo, $tight, [], function (string $l) use (&$lines): void {
    $lines[] = $l;
});
ok('daily budget spent → no model call', count($aiCalls) === $aiBefore && str_contains(implode(' ', $lines), 'budget'));

for ($i = 0; $i < 90; $i++) {
    $addC('Nama' . $i, 'Usaha ' . $i);
}
Claude::$http = function () use (&$aiCalls): array {
    $aiCalls[] = 'fail';
    return [400, '{"error":"bad"}', []];
};
$aiBefore = count($aiCalls);
$lines = [];
$s = Enrich::run($pdo, $runCfg + [], [], function (string $l) use (&$lines): void {
    $lines[] = $l;
});
ok('three failed batches in a row → the run stops calling', count($aiCalls) - $aiBefore === 3 && str_contains(implode(' ', $lines), '3 failed batches'), (string) (count($aiCalls) - $aiBefore));
ok('stopped customers are counted as failed, not lost', $s['ai_failed'] >= 90);

Enrich::$http = null;
Claude::$http = null;
Claude::$sleep = null;
