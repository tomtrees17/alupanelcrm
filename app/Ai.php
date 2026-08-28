<?php
declare(strict_types=1);

/**
 * Natural-language product lookup.
 *
 * The safety property this class exists to enforce: **the model only picks
 * products, it never reports numbers.** It is handed the catalogue (sku /
 * colour / spec / size) and returns product ids; every stock figure a user
 * sees is read from the database afterwards by the caller. A hallucinated
 * quantity is therefore impossible — the worst failure is the wrong product,
 * which the UI shows by full name for the human to catch.
 *
 * Raw HTTP on purpose: this project carries no Composer/vendor directory
 * (see CLAUDE.md), same as the WhatsApp integration.
 */
final class Ai
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    /** What we ask the model to return. Deliberately small: ids and a reason. */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'understood' => [
                    'type' => 'string',
                    'description' => 'One short sentence, in the same language as the question, restating what was asked.',
                ],
                'product_ids' => [
                    'type' => 'array',
                    'description' => 'Catalogue ids of every product that matches, best first, at most 8. Empty if nothing matches.',
                    'items' => ['type' => 'integer'],
                ],
                'qty_asked' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Quantity the user mentioned needing, or null if they did not mention one.',
                ],
                'clarify' => [
                    'type' => ['string', 'null'],
                    'description' => 'If the question is too vague to match anything, a short question back. Otherwise null.',
                ],
            ],
            'required' => ['understood', 'product_ids', 'qty_asked', 'clarify'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Render the catalogue the model matches against. Kept byte-stable (ordered
     * by id, no timestamps) so it can sit behind a cache breakpoint — stock
     * levels are deliberately excluded, both because they change every hour and
     * because the model must not be able to quote them.
     */
    public static function catalogue(PDO $pdo): string
    {
        $lines = [];
        foreach ($pdo->query('SELECT id, sku, name, color_zh, color_en, spec, size FROM products ORDER BY id') as $p) {
            $lines[] = sprintf(
                '%d|%s|%s|%s/%s|%s|%s',
                $p['id'],
                (string) $p['sku'],
                (string) $p['name'],
                (string) $p['color_zh'],
                (string) $p['color_en'],
                (string) $p['spec'],
                (string) $p['size']
            );
        }
        return implode("\n", $lines);
    }

    public static function system_prompt(string $catalogue): string
    {
        return <<<TXT
            You match a warehouse question to products in an aluminium composite panel (ACP) catalogue.

            Catalogue, one product per line, fields separated by "|":
            id|sku|name|colour_zh/colour_en|spec|size

            {$catalogue}

            Rules:
            - Return only ids that appear in the catalogue above. Never invent an id.
            - Questions come in Chinese, Indonesian or English, often mixing them
              (e.g. "4.0 银色拉丝", "silver brushed 4mm", "berapa stok 4.0 perak").
              Colour words may be in either language.
            - A vague question ("4.0" alone) matching many products is fine: return
              the closest ones, best first. Only set "clarify" when you truly cannot
              narrow it down at all.
            - You do NOT know stock levels and must never state or guess a quantity.
              The system reads live stock from the database using the ids you return.
            TXT;
    }

    /**
     * The request body sent to the Messages API. Public so its exact wire shape
     * can be asserted in tests without making a network call.
     */
    public static function build_request(array $ai, string $catalogue, string $question): array
    {
        return [
            'model' => (string) ($ai['model'] ?? 'claude-opus-5'),
            'max_tokens' => 4000,
            // The catalogue is the stable prefix; the cache breakpoint sits at
            // its end so every later question is billed at the cached rate.
            // Anything volatile must stay *after* this block.
            'system' => [[
                'type' => 'text',
                'text' => self::system_prompt($catalogue),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [['role' => 'user', 'content' => $question]],
            'output_config' => [
                'effort' => 'low',            // a lookup, not a reasoning problem
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
        ];
    }

    /**
     * Ask the model to match a question against the catalogue.
     *
     * @return array{0:?array,1:string,2:array} [parsed, error, usage]
     */
    public static function match(array $config, string $catalogue, string $question): array
    {
        $ai = $config['ai'] ?? [];
        if ((string) ($ai['driver'] ?? 'stub') !== 'claude') {
            return [self::stub_match($catalogue, $question), '', []];
        }

        $key = (string) ($ai['key'] ?? '');
        if ($key === '') {
            return [null, 'ai.key is not configured', []];
        }

        [$raw, $err] = self::post($key, self::build_request($ai, $catalogue, $question));
        if ($err !== '') {
            return [null, $err, []];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return [null, 'malformed API response', []];
        }
        if (($json['stop_reason'] ?? '') === 'refusal') {
            return [null, 'request declined by the model', []];
        }

        $usage = [
            'in'     => (int) ($json['usage']['input_tokens'] ?? 0),
            'cached' => (int) ($json['usage']['cache_read_input_tokens'] ?? 0),
            'out'    => (int) ($json['usage']['output_tokens'] ?? 0),
        ];

        // Structured output arrives as JSON in the first text block; thinking
        // blocks may precede it, so scan rather than assuming content[0].
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parsed = json_decode((string) $block['text'], true);
                if (is_array($parsed)) {
                    return [$parsed, '', $usage];
                }
            }
        }
        return [null, 'no parsable content in response', $usage];
    }

    /** @return array{0:string,1:string} [responseBody, error] */
    private static function post(string $key, array $body): array
    {
        if (!function_exists('curl_init')) {
            return ['', 'php curl extension is not available'];
        }
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE) ?: '',
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            return ['', 'curl: ' . $cerr];
        }
        if ($code < 200 || $code >= 300) {
            return ['', "HTTP {$code}: " . mb_substr((string) $res, 0, 300)];
        }
        return [(string) $res, ''];
    }

    /**
     * Offline stand-in: plain keyword scoring over the same catalogue lines.
     * Lets the whole feature — UI, rate limit, logging, live-stock rendering —
     * be exercised before an API key exists, and keeps working as a fallback.
     */
    public static function stub_match(string $catalogue, string $question): array
    {
        $q = mb_strtolower(trim($question));
        // Split on spaces and punctuation but keep CJK runs and alphanumerics.
        $terms = array_values(array_filter(
            preg_split('/[\s,，。？?、\/]+/u', $q) ?: [],
            fn($t) => mb_strlen($t) > 0
        ));

        $scored = [];
        foreach (explode("\n", $catalogue) as $line) {
            if ($line === '') {
                continue;
            }
            $id = (int) strtok($line, '|');
            $hay = mb_strtolower($line);
            $score = 0;
            foreach ($terms as $t) {
                if (mb_strlen($t) >= 2 && mb_strpos($hay, $t) !== false) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[$id] = $score;
            }
        }
        arsort($scored);
        $ids = array_slice(array_keys($scored), 0, 8);

        preg_match('/(\d+)\s*(张|pcs|lembar|sheet)/u', $q, $m);

        return [
            'understood'  => $question,
            'product_ids' => $ids,
            'qty_asked'   => isset($m[1]) ? (int) $m[1] : null,
            'clarify'     => $ids === [] ? 'no match' : null,
        ];
    }
}
