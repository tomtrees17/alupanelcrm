<?php
declare(strict_types=1);

/**
 * The one door to the Anthropic Messages API. Every AI feature goes through
 * send(), which enforces the spending guards in one place:
 *
 *   - a hard ceiling on output tokens per call (ai.max_tokens): a caller
 *     asking for more is clamped, never trusted;
 *   - a ceiling on request size (ai.max_input_chars), checked before sending;
 *   - bounded retries (ai.retries) — only for transient failures (network,
 *     408/409/429/5xx/529), with backoff, honouring Retry-After. Client errors,
 *     refusals and truncated answers are never retried: repeating them would
 *     spend the same tokens for the same failure;
 *   - a daily token budget across all features and users (ai.daily_token_budget),
 *     which callers check with budget_left() before each call.
 *
 * Raw HTTP on purpose: no Composer/vendor directory in this project.
 */
final class Claude
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const RETRYABLE = [408, 409, 429, 500, 502, 503, 504, 529];

    /**
     * Test seams. $http: fn(string $url, string $body, array $headers): array{0:int,1:string,2:array}
     * returning [httpCode, body, responseHeaders (lowercase name => value)].
     * $sleep: fn(float $seconds).
     * @var ?callable
     */
    public static $http = null;
    /** @var ?callable */
    public static $sleep = null;

    /** @return array{max_tokens:int,max_input_chars:int,retries:int,daily_token_budget:int} */
    public static function limits(array $config): array
    {
        $ai = (array) ($config['ai'] ?? []);
        return [
            'max_tokens'         => max(256, (int) ($ai['max_tokens'] ?? 4096)),
            'max_input_chars'    => max(1000, (int) ($ai['max_input_chars'] ?? 60000)),
            'retries'            => max(0, min(5, (int) ($ai['retries'] ?? 2))),
            'daily_token_budget' => max(0, (int) ($ai['daily_token_budget'] ?? 300000)),
        ];
    }

    /**
     * Send one request.
     * @return array{0:?array,1:string,2:array{in:int,cached:int,out:int},3:int}
     *   [decoded response, error ('' on success), usage, attempts made]
     */
    public static function send(array $config, array $body, array $betas = []): array
    {
        $none = ['in' => 0, 'cached' => 0, 'out' => 0];
        $key = trim((string) ($config['ai']['key'] ?? ''));
        if ($key === '') {
            return [null, 'ai.key is not configured', $none, 0];
        }
        $lim = self::limits($config);
        $body['max_tokens'] = min((int) ($body['max_tokens'] ?? $lim['max_tokens']), $lim['max_tokens']);
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [null, 'request could not be encoded', $none, 0];
        }
        if (strlen($payload) > $lim['max_input_chars']) {
            return [null, sprintf('request too large (%d bytes > ai.max_input_chars %d), not sent', strlen($payload), $lim['max_input_chars']), $none, 0];
        }
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . self::API_VERSION,
        ];
        if ($betas) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }

        $attempt = 0;
        $err = '';
        while (true) {
            $attempt++;
            [$code, $res, $rh, $transport] = self::post($payload, $headers);
            if ($transport === '' && $code >= 200 && $code < 300) {
                break;
            }
            $err = $transport !== '' ? $transport : "HTTP {$code}: " . mb_substr($res, 0, 300);
            $retryable = $transport !== '' || in_array($code, self::RETRYABLE, true);
            if (!$retryable || $attempt > $lim['retries']) {
                return [null, $err . ($attempt > 1 ? " (after {$attempt} attempts)" : ''), $none, $attempt];
            }
            $wait = isset($rh['retry-after']) && is_numeric($rh['retry-after'])
                ? (float) $rh['retry-after']
                : (float) (2 ** ($attempt - 1));
            self::pause(min(30.0, max(0.5, $wait)));
        }

        $json = json_decode($res, true);
        if (!is_array($json)) {
            return [null, 'malformed API response', $none, $attempt];
        }
        $usage = [
            'in'     => (int) ($json['usage']['input_tokens'] ?? 0),
            'cached' => (int) ($json['usage']['cache_read_input_tokens'] ?? 0),
            'out'    => (int) ($json['usage']['output_tokens'] ?? 0),
        ];
        $stop = (string) ($json['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return [null, 'request declined by the model', $usage, $attempt];
        }
        if ($stop === 'max_tokens') {
            return [null, 'answer cut off at the max_tokens cap (' . $body['max_tokens'] . ')', $usage, $attempt];
        }
        return [$json, '', $usage, $attempt];
    }

    /** JSON from the first text block (structured output); thinking blocks may precede it. */
    public static function text_json(array $response): ?array
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parsed = json_decode((string) $block['text'], true);
                return is_array($parsed) ? $parsed : null;
            }
        }
        return null;
    }

    /** Tokens spent today across every AI feature (ai_queries is the ledger). */
    public static function used_today(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COALESCE(SUM(COALESCE(in_tokens,0) + COALESCE(out_tokens,0)), 0) FROM ai_queries
              WHERE created_at >= date('now','localtime')"
        )->fetchColumn();
    }

    /** Tokens left in today's budget; 0 budget = unlimited (PHP_INT_MAX). */
    public static function budget_left(PDO $pdo, array $config): int
    {
        $budget = self::limits($config)['daily_token_budget'];
        return $budget === 0 ? PHP_INT_MAX : max(0, $budget - self::used_today($pdo));
    }

    /** @return array{0:int,1:string,2:array,3:string} [code, body, headers, transportError] */
    private static function post(string $payload, array $headers): array
    {
        if (self::$http !== null) {
            $r = (self::$http)(self::ENDPOINT, $payload, $headers);
            return [(int) $r[0], (string) $r[1], (array) ($r[2] ?? []), (string) ($r[3] ?? '')];
        }
        if (!function_exists('curl_init')) {
            return [0, '', [], 'php curl extension is not available'];
        }
        $rh = [];
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$rh): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $rh[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return [0, '', [], 'curl: ' . $cerr];
        }
        return [$code, (string) $res, $rh, ''];
    }

    private static function pause(float $seconds): void
    {
        if (self::$sleep !== null) {
            (self::$sleep)($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }
}
