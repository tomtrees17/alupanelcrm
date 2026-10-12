<?php
declare(strict_types=1);

/**
 * Minimal Alibaba Cloud OSS client: PutObject only, signature V4.
 *
 * Built against the published spec ("Include a V4 signature in the
 * Authorization header"); the canonical-request and final-HMAC steps are
 * pinned by tests to the worked example in that document. Raw curl, no SDK:
 * this project carries no Composer/vendor directory (see CLAUDE.md).
 *
 * Only PutObject is implemented on purpose. The server's key should be a RAM
 * user allowed nothing but oss:PutObject, so a compromised server can add
 * backups but never list, read or delete the ones already offsite.
 */
final class Oss
{
    public const ALGO = 'OSS4-HMAC-SHA256';

    /** Upload a local file. @return array{0:bool,1:string} [ok, message] */
    public static function put_file(array $cfg, string $object, string $path, string $contentType = 'application/zip'): array
    {
        // Keys pasted into the server's config file tend to carry a trailing
        // newline; a single stray byte would break every signature.
        foreach (['bucket', 'region', 'key_id', 'key_secret'] as $k) {
            $cfg[$k] = trim((string) ($cfg[$k] ?? ''));
        }
        foreach (['bucket', 'region', 'key_id', 'key_secret'] as $k) {
            if ($cfg[$k] === '') {
                return [false, "offsite.{$k} is not configured"];
            }
        }
        if (!is_file($path)) {
            return [false, "file not found: {$path}"];
        }
        if (!function_exists('curl_init')) {
            return [false, 'php curl extension is not available'];
        }

        $bucket = (string) $cfg['bucket'];
        $region = (string) $cfg['region'];
        $host = $bucket . '.oss-' . $region . '.aliyuncs.com';
        $now = time();

        $headers = [
            'content-md5'          => base64_encode((string) md5_file($path, true)),   // OSS rejects a corrupted upload
            'content-type'         => $contentType,
            'host'                 => $host,
            'x-oss-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-oss-date'           => gmdate('Ymd\THis\Z', $now),
        ];
        $auth = self::authorization(
            'PUT', '/' . $bucket . '/' . self::encode_key($object), $headers, ['host'],
            (string) $cfg['key_id'], (string) $cfg['key_secret'], $region, $now
        );

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [false, "cannot open {$path}"];
        }
        $wire = [];
        foreach ($headers as $name => $value) {
            $wire[] = $name . ': ' . $value;
        }
        $wire[] = 'Authorization: ' . $auth;

        $ch = curl_init('https://' . $host . '/' . self::encode_key($object));
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => (int) filesize($path),
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 300,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($body === false) {
            return [false, 'curl: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return [false, "HTTP {$code} " . self::error_summary((string) $body)];
        }
        return [true, "uploaded oss://{$bucket}/{$object}"];
    }

    /** Authorization header value for a request. */
    public static function authorization(
        string $method,
        string $canonicalUri,
        array $headers,
        array $additional,
        string $keyId,
        string $secret,
        string $region,
        int $timestamp
    ): string {
        $date = gmdate('Ymd', $timestamp);
        $scope = $date . '/' . $region . '/oss/aliyun_v4_request';
        $canonical = self::canonical_request($method, $canonicalUri, '', $headers, $additional);
        $sts = self::string_to_sign(gmdate('Ymd\THis\Z', $timestamp), $scope, $canonical);
        $signature = self::sign_with_key(self::signing_key($secret, $date, $region), $sts);

        $addl = self::additional_list($additional);
        // An empty AdditionalHeaders= is rejected by OSS, so omit it when unused.
        return self::ALGO . ' Credential=' . $keyId . '/' . $scope
            . ($addl !== '' ? ',AdditionalHeaders=' . $addl : '')
            . ',Signature=' . $signature;
    }

    /**
     * CanonicalRequest per the spec: verb, URI (with bucket), query, headers,
     * additional-header names, payload hash — joined by "\n". Signed headers are
     * Content-Type, Content-MD5, every x-oss-* header, plus those named in
     * $additional; names lowercased, values trimmed, sorted by name.
     */
    public static function canonical_request(string $method, string $uri, string $query, array $headers, array $additional): string
    {
        $wanted = array_map('strtolower', $additional);
        $signed = [];
        foreach ($headers as $name => $value) {
            $n = strtolower(trim((string) $name));
            if ($n === 'content-type' || $n === 'content-md5' || str_starts_with($n, 'x-oss-') || in_array($n, $wanted, true)) {
                $signed[$n] = trim((string) $value);
            }
        }
        ksort($signed, SORT_STRING);
        $lines = '';
        foreach ($signed as $n => $v) {
            $lines .= $n . ':' . $v . "\n";
        }
        return implode("\n", [
            strtoupper($method),
            $uri,
            $query,
            $lines,
            self::additional_list($additional),
            'UNSIGNED-PAYLOAD',
        ]);
    }

    public static function string_to_sign(string $isoTimestamp, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [self::ALGO, $isoTimestamp, $scope, hash('sha256', $canonicalRequest)]);
    }

    /** Derived key (raw bytes): aliyun_v4+SK → date → region → "oss" → "aliyun_v4_request". */
    public static function signing_key(string $secret, string $date, string $region): string
    {
        $k = hash_hmac('sha256', $date, 'aliyun_v4' . $secret, true);
        $k = hash_hmac('sha256', $region, $k, true);
        $k = hash_hmac('sha256', 'oss', $k, true);
        return hash_hmac('sha256', 'aliyun_v4_request', $k, true);
    }

    public static function sign_with_key(string $signingKey, string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $signingKey);
    }

    /** URI-encode an object key, keeping "/" as the path separator. */
    public static function encode_key(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    private static function additional_list(array $additional): string
    {
        $names = array_values(array_unique(array_map(fn($h) => strtolower(trim((string) $h)), $additional)));
        sort($names, SORT_STRING);
        return implode(';', $names);
    }

    /** "SignatureDoesNotMatch: ..." from an OSS XML error body. */
    private static function error_summary(string $body): string
    {
        $code = preg_match('#<Code>(.*?)</Code>#s', $body, $m) ? $m[1] : '';
        $msg = preg_match('#<Message>(.*?)</Message>#s', $body, $m) ? $m[1] : mb_substr(trim($body), 0, 200);
        $sts = preg_match('#<StringToSign>(.*?)</StringToSign>#s', $body, $m) ? ' | server StringToSign: ' . $m[1] : '';
        return trim($code . ': ' . $msg . $sts, ': ');
    }
}
