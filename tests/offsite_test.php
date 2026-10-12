<?php

// ── Offsite backup: OSS V4 signing, config hygiene, health banner ──

require_once __DIR__ . '/../app/Oss.php';
require_once __DIR__ . '/../app/Csrf.php';

// 1) Pinned to the worked PutObject example in Alibaba Cloud's V4 document.
//    Its AccessKey is masked, so the canonical request, string-to-sign and the
//    final HMAC (from the published signing key) are what can be checked.
$docHeaders = [
    'Content-Disposition'  => 'attachment',
    'Content-Length'       => '3',
    'Content-MD5'          => 'ICy5YqxZB1uWSwcVLSNLcA==',
    'Content-Type'         => 'text/plain',
    'x-oss-content-sha256' => 'UNSIGNED-PAYLOAD',
    'x-oss-date'           => '20250411T064124Z',
];
$canonical = Oss::canonical_request('PUT', '/examplebucket/exampleobject', '', $docHeaders, ['content-disposition', 'content-length']);
$expected = "PUT\n/examplebucket/exampleobject\n\ncontent-disposition:attachment\ncontent-length:3\ncontent-md5:ICy5YqxZB1uWSwcVLSNLcA==\ncontent-type:text/plain\nx-oss-content-sha256:UNSIGNED-PAYLOAD\nx-oss-date:20250411T064124Z\n\ncontent-disposition;content-length\nUNSIGNED-PAYLOAD";
ok('canonical request matches the documented example byte for byte', $canonical === $expected);
ok('canonical request hash matches the document', hash('sha256', $canonical) === 'c46d96390bdbc2d739ac9363293ae9d710b14e48081fcb22cd8ad54b63136eca');

$sts = Oss::string_to_sign('20250411T064124Z', '20250411/cn-hangzhou/oss/aliyun_v4_request', $canonical);
ok('string to sign matches the document', $sts === "OSS4-HMAC-SHA256\n20250411T064124Z\n20250411/cn-hangzhou/oss/aliyun_v4_request\nc46d96390bdbc2d739ac9363293ae9d710b14e48081fcb22cd8ad54b63136eca");
$docKey = (string) hex2bin('3543b7686e65eda71e5e5ca19d548d78423c37e8ddba4dc9d83f90228b457c76');
ok('final signature from the documented signing key matches', Oss::sign_with_key($docKey, $sts) === '053edbf550ebd239b32a9cdfd93b0b2b3f2d223083aa61f75e9ac16856d61f23');

// Header order and case in the input must not matter.
$shuffled = array_reverse($docHeaders, true);
$shuffled = array_combine(array_map('strtoupper', array_keys($shuffled)), array_values($shuffled));
ok('canonical request is independent of header order and case',
    Oss::canonical_request('PUT', '/examplebucket/exampleobject', '', $shuffled, ['CONTENT-LENGTH', 'content-disposition']) === $expected);

// Headers that are neither always-signed nor listed stay out of the signature.
$withNoise = $docHeaders + ['User-Agent' => 'curl/8', 'Accept' => '*/*'];
ok('unlisted headers are not signed', Oss::canonical_request('PUT', '/examplebucket/exampleobject', '', $withNoise, ['content-disposition', 'content-length']) === $expected);

// 2) Key derivation behaves like a derivation.
$k1 = Oss::signing_key('secret-A', '20261013', 'ap-southeast-1');
ok('signing key is 32 raw bytes', strlen($k1) === 32);
ok('signing key is deterministic', $k1 === Oss::signing_key('secret-A', '20261013', 'ap-southeast-1'));
ok('signing key depends on secret, date and region',
    $k1 !== Oss::signing_key('secret-B', '20261013', 'ap-southeast-1')
    && $k1 !== Oss::signing_key('secret-A', '20261014', 'ap-southeast-1')
    && $k1 !== Oss::signing_key('secret-A', '20261013', 'ap-southeast-5'));

// 3) The Authorization header.
$ts = gmmktime(3, 30, 1, 10, 13, 2026);
$auth = Oss::authorization('PUT', '/bkt/alupanel/backup.zip', ['host' => 'bkt.oss-ap-southeast-1.aliyuncs.com', 'x-oss-content-sha256' => 'UNSIGNED-PAYLOAD', 'x-oss-date' => '20261013T033001Z'], ['host'], 'LTAIexample', 's3cret', 'ap-southeast-1', $ts);
ok('authorization header has the documented shape',
    preg_match('#^OSS4-HMAC-SHA256 Credential=LTAIexample/20261013/ap-southeast-1/oss/aliyun_v4_request,AdditionalHeaders=host,Signature=[0-9a-f]{64}$#', $auth) === 1, $auth);
$bare = Oss::authorization('PUT', '/bkt/x', ['x-oss-content-sha256' => 'UNSIGNED-PAYLOAD'], [], 'K', 'S', 'ap-southeast-1', $ts);
ok('no empty AdditionalHeaders= when nothing extra is signed (OSS rejects it)', !str_contains($bare, 'AdditionalHeaders'));
ok('the secret never appears in the header', !str_contains($auth, 's3cret'));

ok('object keys keep their slashes', Oss::encode_key('alupanel/backup_2026-10-13_033001.zip') === 'alupanel/backup_2026-10-13_033001.zip');
ok('object keys are URI-encoded per segment', Oss::encode_key('/备份 a+b/x.zip') === rawurlencode('备份 a+b') . '/x.zip');

// 4) Fails closed without credentials — and without touching the network.
[$okUp, $msg] = Oss::put_file(['bucket' => 'b', 'region' => 'ap-southeast-1', 'key_id' => '', 'key_secret' => ''], 'x', __FILE__);
ok('missing credentials are reported, not attempted', $okUp === false && str_contains($msg, 'key_id'));
[$okUp, $msg] = Oss::put_file(['bucket' => 'b', 'region' => 'r', 'key_id' => 'k', 'key_secret' => 's'], 'x', '/no/such/file');
ok('a missing file is reported', $okUp === false && str_contains($msg, 'not found'));
[$okUp, $msg] = Oss::put_file(['bucket' => 'b', 'region' => 'r', 'key_id' => "\n ", 'key_secret' => 's'], 'x', __FILE__);
ok('whitespace-only credentials count as missing', $okUp === false && str_contains($msg, 'key_id'));

// 5) The tracked config file must never carry credentials: it is in git, and
//    the server's nightly `git reset --hard` would wipe anything edited there.
$cfgSrc = (string) @file_get_contents(__DIR__ . '/../config.php');
if ($cfgSrc !== '') {
    $cfg = (function () { return require __DIR__ . '/../config.php'; })();
    ok('config.php defines offsite defaults', isset($cfg['offsite']['driver'], $cfg['offsite']['region']));
    ok('config.php ships offsite disabled', ($cfg['offsite']['driver'] ?? 'x') === '' || is_file(__DIR__ . '/../config.local.php'));
    ok('config.php loads config.local.php overrides', str_contains($cfgSrc, "config.local.php") && str_contains($cfgSrc, 'array_replace_recursive'));
    preg_match_all("/'(key_secret|key_id|token|key)'\\s*=>\\s*'([^']*)'/", $cfgSrc, $mm);
    ok('no credential has a value in tracked config.php', array_filter($mm[2]) === [], implode(',', array_filter($mm[2])));
}
$gi = (string) @file_get_contents(__DIR__ . '/../.gitignore');
if ($gi !== '') {
    ok('config.local.php is gitignored', str_contains($gi, '/config.local.php'));
}

// 6) The admin banner.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE app_meta (k TEXT PRIMARY KEY, v TEXT)');
$now = strtotime('2026-10-13 09:00:00');
$on = ['offsite' => ['driver' => 'oss']];
ok('unconfigured → warn', Dashboard::offsite_health($pdo, ['offsite' => ['driver' => '']], $now)[0] === 'dash_offsite_none');
ok('configured but never succeeded → warn', Dashboard::offsite_health($pdo, $on, $now)[0] === 'dash_offsite_never');
$pdo->exec("INSERT INTO app_meta VALUES ('offsite_last_ok', '2026-10-13 03:30:05')");
ok('fresh success → no banner', Dashboard::offsite_health($pdo, $on, $now) === null);
ok('stale success → warn with hours', Dashboard::offsite_health($pdo, $on, strtotime('2026-10-16 09:00:00')) === ['dash_offsite_stale', [77]]);
$pdo->exec("INSERT OR REPLACE INTO app_meta VALUES ('offsite_last_error', '2026-10-14 03:30:04 HTTP 403 AccessDenied: The bucket you access does not belong to you.')");
$h = Dashboard::offsite_health($pdo, $on, $now);
ok('a recorded failure → warn with the reason', $h[0] === 'dash_offsite_error' && str_contains($h[1][0], 'AccessDenied'));
$pdo->exec("UPDATE app_meta SET v = '' WHERE k = 'offsite_last_error'");
ok('a later success clears the failure', Dashboard::offsite_health($pdo, $on, $now) === null);
foreach (['dash_offsite_none', 'dash_offsite_never', 'dash_offsite_stale', 'dash_offsite_error'] as $key) {
    if (!isset(I18N['zh'][$key], I18N['id'][$key])) {
        ok("{$key} translated", false);
    }
}
ok('banner messages render with their arguments', str_contains(vsprintf(t('dash_offsite_stale'), [77]), '77'));

// 7) The real backup script, end to end where no network is needed.
$script = __DIR__ . '/../tools/backup_db.php';
if (is_file($script) && function_exists('exec')) {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --test-offsite 2>&1', $out, $rc);
    $text = implode("\n", $out);
    $configured = is_file(__DIR__ . '/../config.local.php');
    if (!$configured) {
        ok('--test-offsite without config fails clearly (exit 2)', $rc === 2 && str_contains($text, 'not configured'), $rc . ' ' . $text);
    }
}
