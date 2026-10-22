<?php
declare(strict_types=1);

/**
 * The task layer over Claude::send() (FA-AI-00). A feature names a task
 * ("industry" today; bank matching, receipts and input-tax invoices next),
 * and this class supplies everything around the one HTTP call:
 *
 *   - model, effort, output cap and prompt version from config ai.tasks[...],
 *     never from code; prompts live in prompts/{task}_v{n}.txt, a new wording
 *     is a new file, old versions stay;
 *   - spending: a USD cap per day and per month (ai.daily_usd_budget /
 *     ai.monthly_usd_budget) on top of Claude's daily token budget. Both count
 *     every AI feature: ai_calls rows, plus the inventory assistant's
 *     ai_queries rows priced at their model. Over a cap, nothing is sent, the
 *     row says over_budget, and the boss gets one todo for that day / month;
 *   - a bug guard: the same task on the same record at most
 *     ai.per_record_daily_calls (2) times in 24 hours (calls the API refused
 *     without using tokens do not count);
 *   - the answer must be JSON that fits the task's schema. If not, it is
 *     asked once more and then given up (status invalid, "AI 未处理"): the
 *     caller falls back to manual work. Never a loop. This is separate from
 *     Claude::send()'s bounded retries of transient HTTP errors;
 *   - one ai_calls row per task call: tokens, cost, latency, status.
 *
 * The model only suggests. A suggestion and what a person finally confirmed
 * are kept in ai_suggestions; the usage page reads adoption from there.
 */
final class AiTask
{
    public const STATUSES = ['ok', 'invalid', 'api_error', 'over_budget', 'repeat_skipped'];
    /** Statuses that mean a request actually went out (count for the per-record guard). */
    private const SENT = ['ok', 'invalid', 'api_error'];
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';
    /** USD per million tokens for a model with no price in config: the dearest tier, so the budget never under-counts. */
    private const UNKNOWN_PRICE = ['in' => 10.0, 'out' => 50.0, 'cache_read' => 1.0, 'cache_write' => 12.5];

    /** Test seam: where prompts/{task}_v{n}.txt are read from. */
    public static ?string $promptDir = null;

    public static function ensure_schema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_calls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_type TEXT NOT NULL, ref_table TEXT, ref_id INTEGER,
            model TEXT, prompt_version INTEGER,
            input_tokens INTEGER NOT NULL DEFAULT 0, cached_tokens INTEGER NOT NULL DEFAULT 0,
            cache_write_tokens INTEGER NOT NULL DEFAULT 0, output_tokens INTEGER NOT NULL DEFAULT 0,
            cost_usd REAL NOT NULL DEFAULT 0, latency_ms INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL, error TEXT, user_id INTEGER, user_name TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_calls_day ON ai_calls(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_calls_ref ON ai_calls(task_type, ref_table, ref_id, created_at)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_suggestions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ai_call_id INTEGER REFERENCES ai_calls(id), task_type TEXT NOT NULL,
            ref_table TEXT NOT NULL, ref_id INTEGER NOT NULL,
            ai_suggestion TEXT NOT NULL, ai_confidence TEXT,
            final_value TEXT, changed_fields TEXT, confirmed_by TEXT, confirmed_at TEXT, ai_accepted INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_sugg_ref ON ai_suggestions(ref_table, ref_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_sugg_task ON ai_suggestions(task_type, created_at)');
    }

    // ───────────────────────────── configuration ─────────────────────────────

    /** @return array{model:string,effort:string,max_tokens:int,prompt_version:?int,max_input_chars:?int} */
    public static function task_config(array $config, string $task): array
    {
        $ai = (array) ($config['ai'] ?? []);
        $t = (array) ($ai['tasks'][$task] ?? []);
        $model = trim((string) ($t['model'] ?? ''));
        if ($model === '' && isset($t['tier'])) {
            $model = trim((string) ($ai['tiers'][$t['tier']] ?? ''));
        }
        return [
            'model'           => $model,
            'effort'          => (string) ($t['effort'] ?? 'low'),
            'max_tokens'      => (int) ($t['max_tokens'] ?? 4096),
            'prompt_version'  => isset($t['prompt_version']) ? (int) $t['prompt_version'] : null,
            'max_input_chars' => isset($t['max_input_chars']) ? (int) $t['max_input_chars'] : null,
        ];
    }

    public static function model(array $config, string $task): string
    {
        return self::task_config($config, $task)['model'];
    }

    /** True when an Anthropic key is configured (no key = every feature stays manual). */
    public static function enabled(array $config): bool
    {
        return trim((string) ($config['ai']['key'] ?? '')) !== '';
    }

    /**
     * The prompt text for a task: the pinned version, or the newest file.
     * @return array{0:string,1:int} [text, version]
     */
    public static function prompt(string $task, ?int $version = null): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $task)) {
            throw new InvalidArgumentException("bad task name: {$task}");
        }
        $dir = self::$promptDir ?? __DIR__ . '/../prompts';
        if ($version === null) {
            $version = 0;
            foreach (glob("{$dir}/{$task}_v*.txt") ?: [] as $f) {
                if (preg_match('/_v(\d+)\.txt$/', $f, $m)) {
                    $version = max($version, (int) $m[1]);
                }
            }
        }
        $file = "{$dir}/{$task}_v{$version}.txt";
        $text = is_file($file) ? file_get_contents($file) : false;
        if ($text === false) {
            throw new RuntimeException("prompt not found: prompts/{$task}_v{$version}.txt");
        }
        // Editors add a final newline; it carries no meaning, so it must not change the request.
        return [rtrim($text), $version];
    }

    /** Safety-classifier declines are retried server-side on these models; Haiku has no server-side fallback. */
    private static function has_fallback(string $model): bool
    {
        return $model !== '' && !str_starts_with($model, 'claude-haiku');
    }

    /** The Messages API body for a task. Public so its wire shape can be asserted offline. */
    public static function build_request(array $config, string $task, string $system, string|array $content, array $schema): array
    {
        $tc = self::task_config($config, $task);
        $body = [
            'model'      => $tc['model'],
            'max_tokens' => $tc['max_tokens'],
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $content]],
            'output_config' => [
                'effort' => $tc['effort'],
                'format' => ['type' => 'json_schema', 'schema' => self::api_schema($schema)],
            ],
        ];
        if (self::has_fallback($tc['model'])) {
            $body['fallbacks'] = 'default';
        }
        return $body;
    }

    /**
     * The schema as the API takes it. Structured outputs reject numeric,
     * string-length and array-size constraints (a 400 before the model runs),
     * so they are left out of the request and enforced by validate() on the
     * answer instead — what Anthropic's own SDKs do.
     */
    public static function api_schema(array $schema): array
    {
        $out = [];
        foreach ($schema as $k => $v) {
            if (in_array($k, ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems'], true)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::api_schema($v) : $v;
        }
        return $out;
    }

    // ───────────────────────────── cost and budget ─────────────────────────────

    /** USD for one response's usage (in / cached / write / out tokens), at the model's configured price. */
    public static function cost(array $config, string $model, array $usage): float
    {
        $prices = (array) ($config['ai']['prices'] ?? []);
        $p = (array) ($prices[$model] ?? $prices['default'] ?? self::UNKNOWN_PRICE);
        $usd = ((int) ($usage['in'] ?? 0) * (float) ($p['in'] ?? 0)
              + (int) ($usage['cached'] ?? 0) * (float) ($p['cache_read'] ?? 0)
              + (int) ($usage['write'] ?? 0) * (float) ($p['cache_write'] ?? 0)
              + (int) ($usage['out'] ?? 0) * (float) ($p['out'] ?? 0)) / 1_000_000;
        return round($usd, 6);
    }

    /** @return array{day:float,month:float} USD caps; 0 = no cap. */
    public static function caps(array $config): array
    {
        $ai = (array) ($config['ai'] ?? []);
        return [
            'day'   => max(0.0, (float) ($ai['daily_usd_budget'] ?? 5.0)),
            'month' => max(0.0, (float) ($ai['monthly_usd_budget'] ?? 50.0)),
        ];
    }

    /**
     * USD spent since the start of today ('day') or of this month ('month'),
     * across every AI feature. The inventory assistant still logs to
     * ai_queries (tokens only), so those rows are priced at its model.
     */
    public static function spent_usd(PDO $pdo, array $config, string $period): float
    {
        $start = $period === 'month' ? 'start of month' : 'start of day';
        $usd = 0.0;
        if (self::has_table($pdo, 'ai_calls')) {
            $usd += (float) $pdo->query("SELECT COALESCE(SUM(cost_usd), 0) FROM ai_calls WHERE created_at >= datetime('now','localtime','{$start}')")->fetchColumn();
        }
        if (self::has_table($pdo, 'ai_queries')) {
            $q = $pdo->query("SELECT COALESCE(SUM(in_tokens),0) i, COALESCE(SUM(cached_tokens),0) c, COALESCE(SUM(out_tokens),0) o
                                FROM ai_queries WHERE created_at >= datetime('now','localtime','{$start}')")->fetch(PDO::FETCH_ASSOC);
            $usd += self::cost($config, (string) ($config['ai']['model'] ?? ''), ['in' => (int) $q['i'], 'cached' => (int) $q['c'], 'out' => (int) $q['o']]);
        }
        return round($usd, 6);
    }

    /** '' while within both USD caps, else 'month' or 'day' (the one hit). */
    public static function over_budget(PDO $pdo, array $config): string
    {
        $caps = self::caps($config);
        if ($caps['month'] > 0 && self::spent_usd($pdo, $config, 'month') >= $caps['month']) {
            return 'month';
        }
        if ($caps['day'] > 0 && self::spent_usd($pdo, $config, 'day') >= $caps['day']) {
            return 'day';
        }
        return '';
    }

    /**
     * The USD check every caller goes through (via Claude::budget_left()):
     * over a cap → one todo for the boss per day / month; a todo whose period
     * has passed (the cap has reset) closes itself.
     */
    public static function budget_guard(PDO $pdo, array $config): string
    {
        $over = self::over_budget($pdo, $config);
        if (!self::has_table($pdo, 'hr_todos')) {
            return $over;
        }
        $day = 'day:' . date('Y-m-d');
        $month = 'month:' . date('Y-m');
        $pdo->prepare("UPDATE hr_todos SET status = 'done', handled_by = 'system', handled_at = datetime('now','localtime'), handled_note = 'budget reset'
                        WHERE type = 'ai_budget' AND status = 'open' AND ref NOT IN (?, ?)")->execute([$day, $month]);
        if ($over !== '') {
            $ref = $over === 'month' ? $month : $day;
            // employee_id is NULL here, and NULLs never collide in a UNIQUE index: check first.
            $has = $pdo->prepare("SELECT 1 FROM hr_todos WHERE type = 'ai_budget' AND ref = ?");
            $has->execute([$ref]);
            if (!$has->fetchColumn()) {
                $pdo->prepare("INSERT INTO hr_todos (type, employee_id, ref, due_date) VALUES ('ai_budget', NULL, ?, ?)")->execute([$ref, date('Y-m-d')]);
            }
        }
        return $over;
    }

    // ───────────────────────────── the call ─────────────────────────────

    /**
     * Run one task call.
     *
     * @param array $req content (string|array, the user message), schema (array),
     *                   ref_table / ref_id (the record it is about; enables the
     *                   per-record guard), check (optional fn(array): string —
     *                   a task's own rule beyond the schema, '' = fine)
     * @return array{status:string,data:?array,error:string,call_id:int,usage:array,cost:float}
     */
    public static function run(PDO $pdo, array $config, string $task, array $req): array
    {
        $refTable = isset($req['ref_table']) ? (string) $req['ref_table'] : null;
        $refId = isset($req['ref_id']) ? (int) $req['ref_id'] : null;
        $usage = ['in' => 0, 'cached' => 0, 'write' => 0, 'out' => 0];
        $row = ['task' => $task, 'ref_table' => $refTable, 'ref_id' => $refId, 'model' => '', 'version' => null,
                'usage' => $usage, 'cost' => 0.0, 'ms' => 0, 'attempts' => 0];
        $done = function (string $status, string $error, ?array $data = null) use ($pdo, &$row): array {
            $id = self::log($pdo, $row + ['status' => $status, 'error' => $error]);
            return ['status' => $status, 'data' => $data, 'error' => $error, 'call_id' => $id, 'usage' => $row['usage'], 'cost' => $row['cost']];
        };

        $tc = self::task_config($config, $task);
        $row['model'] = $tc['model'];
        if ($tc['model'] === '') {
            return $done('api_error', "no model configured for task {$task} (ai.tasks.{$task})");
        }
        [$system, $row['version']] = self::prompt($task, $tc['prompt_version']);

        if (Claude::budget_left($pdo, $config) <= 0) {
            $why = self::over_budget($pdo, $config);
            return $done('over_budget', $why === '' ? 'daily token budget (ai.daily_token_budget) used up' : "USD {$why} budget used up");
        }
        if ($refId !== null) {
            $limit = max(1, (int) ($config['ai']['per_record_daily_calls'] ?? 2));
            $in = implode(',', array_map(fn($s) => "'{$s}'", self::SENT));
            // Only calls the model actually answered count: a request the API refused
            // outright (e.g. a 400 on the schema) used no tokens and must not lock the record.
            $st = $pdo->prepare("SELECT COUNT(*) FROM ai_calls WHERE task_type = ? AND ref_table IS ? AND ref_id = ? AND status IN ({$in})
                                    AND input_tokens + output_tokens > 0 AND created_at >= datetime('now','localtime','-1 day')");
            $st->execute([$task, $refTable, $refId]);
            if ((int) $st->fetchColumn() >= $limit) {
                return $done('repeat_skipped', "already called {$limit} times for this record in 24 h");
            }
        }

        $body = self::build_request($config, $task, $system, $req['content'] ?? '', (array) ($req['schema'] ?? []));
        $sendConfig = $config;
        if ($tc['max_input_chars'] !== null) {   // e.g. a receipt photo is far larger than any text prompt
            $sendConfig['ai']['max_input_chars'] = $tc['max_input_chars'];
        }
        $betas = isset($body['fallbacks']) ? [self::FALLBACK_BETA] : [];
        $notes = [];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $t0 = microtime(true);
            [$json, $err, $u] = Claude::send($sendConfig, $body, $betas);
            $row['ms'] += (int) round((microtime(true) - $t0) * 1000);
            $row['attempts'] = $attempt;
            // Served model: a server-side fallback may answer on another model, billed at its price.
            $served = (string) ($json['model'] ?? $tc['model']);
            $row['model'] = $served;
            foreach ($usage as $k => $_) {
                $row['usage'][$k] += (int) ($u[$k] ?? 0);
            }
            $row['cost'] = round($row['cost'] + self::cost($config, $served, $u), 6);
            if ($json === null) {
                // Transport / HTTP / refusal / cut-off: Claude::send() already
                // retried what was worth retrying. Asking again would only repeat it.
                return $done('api_error', implode('; ', array_merge($notes, [$err])));
            }
            $data = Claude::text_json($json);
            $bad = $data === null ? 'answer is not JSON' : self::validate($data, (array) ($req['schema'] ?? []));   // the full schema, limits included
            if ($bad === '' && isset($req['check']) && is_callable($req['check'])) {
                $bad = (string) ($req['check'])($data);
            }
            if ($bad === '') {
                return $done('ok', implode('; ', $notes), $data);
            }
            $notes[] = "attempt {$attempt}: {$bad}";
        }
        return $done('invalid', implode('; ', $notes));
    }

    /** One ledger row. Never throws: logging must not break the feature that called. */
    private static function log(PDO $pdo, array $r): int
    {
        $auth = $GLOBALS['auth'] ?? null;
        $user = ($auth !== null && $auth->check()) ? $auth->user() : null;
        try {
            $pdo->prepare('INSERT INTO ai_calls (task_type, ref_table, ref_id, model, prompt_version, input_tokens, cached_tokens, cache_write_tokens, output_tokens,
                                                 cost_usd, latency_ms, attempts, status, error, user_id, user_name)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$r['task'], $r['ref_table'], $r['ref_id'], $r['model'], $r['version'],
                           $r['usage']['in'], $r['usage']['cached'], $r['usage']['write'], $r['usage']['out'],
                           $r['cost'], $r['ms'], $r['attempts'], $r['status'], $r['error'] !== '' ? mb_substr($r['error'], 0, 500) : null,
                           $user['id'] ?? null, $user['name'] ?? null]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ───────────────────────────── schema check ─────────────────────────────

    /**
     * The first place $value breaks $schema, '' when it fits. Covers the JSON
     * Schema subset our task schemas use: type (one or a list), enum,
     * properties / required / additionalProperties, items, minItems /
     * maxItems, minimum / maximum, minLength / maxLength.
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): string
    {
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $fits = false;
            foreach ($types as $t) {
                if (self::is_type($value, (string) $t)) {
                    $fits = true;
                    break;
                }
            }
            if (!$fits) {
                return "{$path}: expected " . implode('|', $types) . ', got ' . get_debug_type($value);
            }
        }
        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
            return "{$path}: not one of the allowed values";
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return "{$path}: below {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return "{$path}: above {$schema['maximum']}";
            }
        }
        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
                return "{$path}: shorter than {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
                return "{$path}: longer than {$schema['maxLength']}";
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
                return "{$path}: fewer than {$schema['minItems']} items";
            }
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
                return "{$path}: more than {$schema['maxItems']} items";
            }
            foreach ($value as $i => $item) {
                $bad = self::validate($item, (array) $schema['items'], "{$path}[{$i}]");
                if ($bad !== '') {
                    return $bad;
                }
            }
        }
        if (is_array($value) && (isset($schema['properties']) || isset($schema['required']))) {
            $props = (array) ($schema['properties'] ?? []);
            foreach ((array) ($schema['required'] ?? []) as $k) {
                if (!array_key_exists($k, $value)) {
                    return "{$path}.{$k}: missing";
                }
            }
            foreach ($value as $k => $v) {
                if (isset($props[$k])) {
                    $bad = self::validate($v, (array) $props[$k], "{$path}.{$k}");
                    if ($bad !== '') {
                        return $bad;
                    }
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    return "{$path}.{$k}: not allowed";
                }
            }
        }
        return '';
    }

    private static function is_type(mixed $v, string $type): bool
    {
        return match ($type) {
            'string'  => is_string($v),
            'integer' => is_int($v) || (is_float($v) && floor($v) === $v),
            'number'  => is_int($v) || is_float($v),
            'boolean' => is_bool($v),
            'null'    => $v === null,
            // json_decode(…, true) gives arrays for both; an empty one could be either.
            'object'  => is_array($v) && ($v === [] || !array_is_list($v)),
            'array'   => is_array($v) && array_is_list($v),
            default   => false,
        };
    }

    // ───────────────────────────── suggestions ─────────────────────────────

    /** Keep a model's suggestion for a record until a person confirms it. */
    public static function suggest(PDO $pdo, string $task, string $refTable, int $refId, array $suggestion, string|float|null $confidence, ?int $callId): int
    {
        $pdo->prepare('INSERT INTO ai_suggestions (ai_call_id, task_type, ref_table, ref_id, ai_suggestion, ai_confidence) VALUES (?,?,?,?,?,?)')
            ->execute([$callId ?: null, $task, $refTable, $refId, json_encode($suggestion, JSON_UNESCAPED_UNICODE),
                       $confidence === null ? null : (string) $confidence]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Record what the person confirmed. Fields are compared one by one at the
     * top level; ai_accepted = 1 only when nothing was changed. A suggestion
     * is confirmed once; a later call changes nothing and returns null.
     * @return ?array{accepted:bool,changed:list<string>}
     */
    public static function confirm(PDO $pdo, int $suggestionId, array $final, string $by): ?array
    {
        $st = $pdo->prepare('SELECT ai_suggestion FROM ai_suggestions WHERE id = ? AND confirmed_at IS NULL');
        $st->execute([$suggestionId]);
        $raw = $st->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $ai = (array) json_decode((string) $raw, true);
        $changed = [];
        foreach (array_unique(array_merge(array_keys($ai), array_keys($final))) as $k) {
            if (!self::same($ai[$k] ?? null, $final[$k] ?? null)) {
                $changed[] = (string) $k;
            }
        }
        $pdo->prepare("UPDATE ai_suggestions SET final_value = ?, changed_fields = ?, confirmed_by = ?, confirmed_at = datetime('now','localtime'), ai_accepted = ?
                        WHERE id = ? AND confirmed_at IS NULL")
            ->execute([json_encode($final, JSON_UNESCAPED_UNICODE), implode(',', $changed), $by, $changed ? 0 : 1, $suggestionId]);
        return ['accepted' => $changed === [], 'changed' => $changed];
    }

    /** Equal for a person's purposes: 1000 and 1000.0 are the same amount. */
    private static function same(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return abs((float) $a - (float) $b) < 0.005;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::same($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    // ───────────────────────────── usage page ─────────────────────────────

    /**
     * Everything the 「AI 使用情况」 page shows for one month (YYYY-MM), read
     * straight from ai_calls / ai_suggestions (and ai_queries for the
     * inventory assistant, which keeps its own ledger).
     */
    public static function usage(PDO $pdo, array $config, string $month): array
    {
        $tasks = [];
        $total = ['calls' => 0, 'ok' => 0, 'invalid' => 0, 'api_error' => 0, 'over_budget' => 0, 'repeat_skipped' => 0,
                  'in' => 0, 'out' => 0, 'cost' => 0.0];
        $st = $pdo->prepare("SELECT task_type, status, COUNT(*) n, SUM(input_tokens + cached_tokens + cache_write_tokens) i, SUM(output_tokens) o,
                                    SUM(cost_usd) usd, SUM(latency_ms) ms
                               FROM ai_calls WHERE substr(created_at, 1, 7) = ? GROUP BY task_type, status");
        $st->execute([$month]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (string) $r['task_type'];
            $tasks[$t] ??= ['calls' => 0, 'ok' => 0, 'invalid' => 0, 'api_error' => 0, 'over_budget' => 0, 'repeat_skipped' => 0,
                            'in' => 0, 'out' => 0, 'cost' => 0.0, 'ms' => 0, 'sent' => 0,
                            'suggested' => 0, 'confirmed' => 0, 'accepted' => 0, 'fields' => []];
            $n = (int) $r['n'];
            foreach ([&$tasks[$t], &$total] as &$bucket) {
                $bucket['calls'] += $n;
                if (isset($bucket[$r['status']])) {
                    $bucket[$r['status']] += $n;
                }
                $bucket['in'] += (int) $r['i'];
                $bucket['out'] += (int) $r['o'];
                $bucket['cost'] = round($bucket['cost'] + (float) $r['usd'], 6);
            }
            unset($bucket);
            if (in_array($r['status'], self::SENT, true)) {
                $tasks[$t]['ms'] += (int) $r['ms'];
                $tasks[$t]['sent'] += $n;
            }
        }
        $st = $pdo->prepare("SELECT task_type, COUNT(*) n, SUM(confirmed_at IS NOT NULL) c, SUM(ai_accepted = 1) a
                               FROM ai_suggestions WHERE substr(created_at, 1, 7) = ? GROUP BY task_type");
        $st->execute([$month]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (string) $r['task_type'];
            $tasks[$t] ??= ['calls' => 0, 'ok' => 0, 'invalid' => 0, 'api_error' => 0, 'over_budget' => 0, 'repeat_skipped' => 0,
                            'in' => 0, 'out' => 0, 'cost' => 0.0, 'ms' => 0, 'sent' => 0, 'suggested' => 0, 'confirmed' => 0, 'accepted' => 0, 'fields' => []];
            $tasks[$t]['suggested'] = (int) $r['n'];
            $tasks[$t]['confirmed'] = (int) $r['c'];
            $tasks[$t]['accepted'] = (int) $r['a'];
        }
        $st = $pdo->prepare("SELECT task_type, changed_fields FROM ai_suggestions
                               WHERE substr(created_at, 1, 7) = ? AND COALESCE(changed_fields, '') <> ''");
        $st->execute([$month]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (explode(',', (string) $r['changed_fields']) as $f) {
                $tasks[$r['task_type']]['fields'][$f] = ($tasks[$r['task_type']]['fields'][$f] ?? 0) + 1;
            }
        }
        foreach ($tasks as &$t) {
            arsort($t['fields']);
            $t['fields'] = array_slice($t['fields'], 0, 3, true);
            $t['avg_ms'] = $t['sent'] > 0 ? (int) round($t['ms'] / $t['sent']) : 0;
            $t['adoption'] = $t['confirmed'] > 0 ? $t['accepted'] / $t['confirmed'] : null;
        }
        unset($t);
        ksort($tasks);

        $inventory = ['calls' => 0, 'in' => 0, 'out' => 0, 'cost' => 0.0];
        if (self::has_table($pdo, 'ai_queries')) {
            $st = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(in_tokens),0) i, COALESCE(SUM(cached_tokens),0) c, COALESCE(SUM(out_tokens),0) o
                                   FROM ai_queries WHERE substr(created_at, 1, 7) = ?');
            $st->execute([$month]);
            $q = $st->fetch(PDO::FETCH_ASSOC);
            $inventory = ['calls' => (int) $q['n'], 'in' => (int) $q['i'] + (int) $q['c'], 'out' => (int) $q['o'],
                          'cost' => self::cost($config, (string) ($config['ai']['model'] ?? ''), ['in' => (int) $q['i'], 'cached' => (int) $q['c'], 'out' => (int) $q['o']])];
        }
        return [
            'month' => $month, 'tasks' => $tasks, 'total' => $total, 'inventory' => $inventory,
            'caps' => self::caps($config),
            'spent_today' => self::spent_usd($pdo, $config, 'day'),
            'spent_month' => self::spent_usd($pdo, $config, 'month'),
        ];
    }

    // ───────────────────────────── images ─────────────────────────────

    /**
     * An image content block, long edge at most $maxEdge px: a larger photo
     * costs more tokens without reading any better. Phone photos are turned
     * upright from their EXIF orientation first.
     * @return array{0:?array,1:string} [block, error]
     */
    public static function image_block(string $path, int $maxEdge = 1568): array
    {
        $info = @getimagesize($path);
        $types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];
        if ($info === false || !isset($types[$info[2]])) {
            return [null, 'not a jpg / png / webp / gif image'];
        }
        $orientation = 1;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1);
        }
        $block = fn(string $mime, string $bytes): array => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        if (max($info[0], $info[1]) <= $maxEdge && $orientation === 1) {
            return [$block($types[$info[2]], (string) file_get_contents($path)), ''];
        }
        if (!function_exists('imagecreatefromstring')) {
            return [null, 'image larger than ' . $maxEdge . ' px and the php gd extension is missing'];
        }
        $img = @imagecreatefromstring((string) file_get_contents($path));
        if ($img === false) {
            return [null, 'image could not be decoded'];
        }
        $angle = [3 => 180, 6 => 270, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $img = imagerotate($img, $angle, 0);
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        // White canvas: a transparent PNG must not turn black as a JPEG.
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, 85);
        return [$block('image/jpeg', (string) ob_get_clean()), ''];
    }

    private static function has_table(PDO $pdo, string $name): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (bool) $st->fetchColumn();
    }
}
