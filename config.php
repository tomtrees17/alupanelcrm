<?php
declare(strict_types=1);

// Secrets (API keys, tokens) do NOT belong here: this file is tracked by git,
// and the server's nightly auto-deploy runs `git reset --hard`. Put them in
// .env (see .env.example) — gitignored, outside the web root, survives deploys.
// config.local.php (gitignored) can still override any other setting.
$config = [
    'app_name' => 'AluPanel CRM',
    'brand'    => 'AluPanel',
    'company'  => 'AMI',
    'tagline'  => 'Sales Platform',
    'currency' => 'Rp',
    'ppn_rate' => 11,           // effective PPN % of subtotal (12% VAT on DPP = subtotal×11/12)

    // Where the Pembulatan (rounding, ±Rp1–3) appears on invoices — print, Word
    // and the invoice screen alike (see invoice_display() in app/domain.php):
    // 'vat' = folded into the VAT line (finance's spreadsheet does this; chosen
    // 2026-10-21), 'line' = its own row, 'hide' = not shown.
    'invoice_rounding' => 'vat',

    // ── Company letterhead (printed invoices / delivery orders) ──
    'company_full'  => 'PT ALUPANEL MULIA INDONESIA',
    'company_addr'  => 'JL Pinangsia Raya no 83, kecamatan taman sari, kelurahan pinangsia, kode pos 11110',
    'company_phone' => '',
    'company_logo'  => 'ALUSIGNPANEL',   // text logo; or drop a file at public/assets/img/logo.png
    'signer_name'   => 'MUTIARA FARIANDA',
    'signer_title'  => 'Finance Manager',
    'banks' => [
        [
            'name' => 'Bank ICBC',
            'branch' => 'PT. Industrial and Commercial Bank Of China (Indonesia)',
            'account_name' => 'PT ALUPANEL MULIA INDONESIA',
            'account_no' => '0120010300000397082',
            'swift' => 'ICBKIDJA',
        ],
        [
            'name' => 'Bank BCA',
            'branch' => 'BCA JIEXPO KEMAYORAN',
            'account_name' => 'ALUPANEL MULIA INDONESIA PT',
            'account_no' => '7530306865',
            'swift' => 'CENAIDJA',
        ],
    ],

    // ── WhatsApp notifications ──
    // driver: 'log'    = queue + record only, nothing leaves the server (safe default)
    //         'fonnte' = Fonnte.com HTTP API (Indonesian provider)
    //         'cloud'  = Meta WhatsApp Cloud API
    // Switching provider is a config change; no business code knows the difference.
    'wa' => [
        'driver'   => 'log',
        'token'    => '',      // fonnte: API token | cloud: permanent access token
        'sender'   => '',      // cloud only: phone number ID
        'test_to'  => '',      // if set, EVERY message goes here instead of the real recipient
        'throttle' => 1,       // seconds to wait between sends (avoid provider rate limits)
    ],

    // ── AI assistant (natural-language inventory lookup) ──
    // driver: 'stub'   = deterministic keyword match, no API call, no cost (safe default)
    //         'claude' = Anthropic Messages API
    // The model only ever PICKS PRODUCTS from the catalog it is given; every stock
    // number shown to a user is read from the database afterwards, never from the model.
    'ai' => [
        'driver'      => 'stub',
        'key'         => '',                 // Anthropic API key (console.anthropic.com)
        'model'       => 'claude-opus-5-5',
        'daily_limit' => 60,                 // inventory questions per user per day
        // Spending guards for EVERY AI call (enforced in app/Claude.php):
        'max_tokens'         => 4096,        // hard cap on output tokens per call
        'max_input_chars'    => 60000,       // requests larger than this are not sent
        'retries'            => 2,           // transient failures only (network / 429 / 5xx), with backoff
        'daily_token_budget' => 300000,      // all features + users per day; 0 = unlimited
    ],

    // ── Geocoding (tools/enrich_customers.php: order address → lat/lng + kota) ──
    // driver '' = off: regions come from the address text alone (no coordinates,
    // so no map). 'google' = Google Geocoding API. Key goes in config.local.php.
    'geo' => [
        'driver' => '',
        'key'    => '',
    ],

    // ── Customer analysis (区域 / 行业 / 回访清单) ──
    'insights' => [
        // Industry classification for customers no rule matches. Uses ai.key;
        // off automatically while that key is empty.
        'industry_ai'    => true,
        'industry_model' => 'claude-opus-5-5',
        'min_confidence' => 0.7,      // below this the result is marked 待确认
        'ai_batch'       => 20,       // customers per model call
        'end_owner_max_qty' => 20,    // "personal name, no company, small orders" ⇒ 终端业主

        // Call lists (回访清单), see app/Insights.php
        'due_low'    => 0.9,          // A 该回访: days since ≥ 0.9 × usual interval …
        'due_high'   => 1.5,          // … and ≤ 1.5 ×; B 快流失: beyond 1.5 ×
        'red_factor' => 2.0,          // B: overdue by more than 2 × the interval → red
        'top_share'  => 0.2,          // C 值得多跑: top 20% by 12-month amount …
        'idle_days'  => 90,           // … and no purchase for more than 90 days
        'snooze_followup_days' => 7,  // after 记跟进 the customer leaves the lists for 7 days
        'snooze_no_need_days'  => 30, // after 无需求 for 30 days

        // kota / kabupaten (as Google or the address writes it, lowercase,
        // without the "Kota"/"Kabupaten"/"Adm." prefix) → region.
        // Anything geocoded to a kota not listed here becomes 'other'.
        'region_map' => [
            'jakarta utara' => 'jkt_utara', 'north jakarta' => 'jkt_utara', 'jakut' => 'jkt_utara',
            'jakarta barat' => 'jkt_barat', 'west jakarta' => 'jkt_barat', 'jakbar' => 'jkt_barat',
            'jakarta timur' => 'jkt_timur', 'east jakarta' => 'jkt_timur', 'jaktim' => 'jkt_timur',
            'jakarta selatan' => 'jkt_selatan', 'south jakarta' => 'jkt_selatan', 'jaksel' => 'jkt_selatan',
            'jakarta pusat' => 'jkt_pusat', 'central jakarta' => 'jkt_pusat', 'jakpus' => 'jkt_pusat',
            'tangerang' => 'tangerang', 'tangerang selatan' => 'tangerang', 'south tangerang' => 'tangerang', 'tangsel' => 'tangerang',
            'bekasi' => 'bekasi',
            'bogor' => 'bogor_depok', 'depok' => 'bogor_depok',
        ],
        // Places that are certainly outside the eight regions (address text only).
        'other_markers' => [
            'jawa tengah', 'jawa timur', 'yogyakarta', 'bali', 'sumatera', 'sumatra', 'kalimantan',
            'sulawesi', 'papua', 'lampung', 'riau', 'bandung', 'surabaya', 'semarang', 'medan',
            'makassar', 'karawang', 'purwakarta', 'serang', 'cilegon', 'sukabumi', 'cianjur', 'jember',
            'kepulauan seribu', 'tasikmalaya', 'garut', 'cirebon', 'indramayu', 'subang', 'majalengka',
            'kuningan', 'lebak', 'pandeglang', 'pontianak', 'balikpapan', 'palembang', 'batam', 'pekanbaru',
        ],
    ],

    // ── Offsite backup (tools/backup_db.php uploads each snapshot) ──
    // driver '' = off, 'oss' = Alibaba Cloud OSS. Credentials go in config.local.php.
    'offsite' => [
        'driver'     => '',
        'region'     => 'ap-southeast-5',   // Jakarta (bucket region is fixed at creation)
        'bucket'     => 'alupanel-backup-crm',
        'key_id'     => '',
        'key_secret' => '',
        'prefix'     => 'alupanel/',
    ],

    'db_path'  => __DIR__ . '/data/crm.sqlite',
];

// .env: KEY=VALUE lines. Real environment variables work too.
$env = [];
if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = trim($v);
    }
}
$secret = fn(string $k): string => (string) ($env[$k] ?? (getenv($k) ?: ''));
if ($secret('ANTHROPIC_API_KEY') !== '') {
    $config['ai']['key'] = $secret('ANTHROPIC_API_KEY');
}
if ($secret('GOOGLE_MAPS_API_KEY') !== '') {
    $config['geo'] = ['driver' => 'google', 'key' => $secret('GOOGLE_MAPS_API_KEY')];
}
if ($secret('OSS_ACCESS_KEY_ID') !== '' && $secret('OSS_ACCESS_KEY_SECRET') !== '') {
    $config['offsite']['driver'] = 'oss';
    $config['offsite']['key_id'] = $secret('OSS_ACCESS_KEY_ID');
    $config['offsite']['key_secret'] = $secret('OSS_ACCESS_KEY_SECRET');
}
if ($secret('WA_TOKEN') !== '') {
    $config['wa']['token'] = $secret('WA_TOKEN');
}
unset($env, $secret);

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}
return $config;
