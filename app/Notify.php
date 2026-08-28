<?php
declare(strict_types=1);

/**
 * Outbound WhatsApp notifications.
 *
 * Business code only ever calls Notify::queue(); nothing in a web request talks
 * to the provider, so a slow or dead API can never make a user wait or lose an
 * approval. tools/send_notifications.php drains the queue from cron.
 *
 * The provider lives behind one method (send_via_*), configured in config.php:
 * moving from a local reseller to Meta's official API is a config change.
 */
final class Notify
{
    /** Max delivery attempts before a message is given up on. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Put one message on the queue. Never throws: a notification failing to
     * enqueue must not roll back the business action that triggered it.
     */
    public static function queue(
        PDO $pdo,
        ?int $userId,
        string $event,
        string $body,
        string $entity = '',
        ?int $entityId = null,
        string $label = ''
    ): void {
        try {
            $phone = '';
            if ($userId !== null) {
                $st = $pdo->prepare('SELECT phone FROM users WHERE id = ?');
                $st->execute([$userId]);
                $phone = self::normalise_phone((string) $st->fetchColumn());
            }
            // Queue even without a phone: the admin list then shows who is unreachable,
            // which is more useful than silently dropping the message.
            $pdo->prepare(
                'INSERT INTO notifications (user_id, phone, event, entity, entity_id, label, body, status)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $userId,
                $phone,
                $event,
                $entity,
                $entityId,
                mb_substr($label, 0, 200),
                mb_substr($body, 0, 2000),
                $phone === '' ? 'skipped' : 'queued',
            ]);

            if ($phone === '') {
                $pdo->prepare("UPDATE notifications SET error = ? WHERE id = ?")
                    ->execute(['no phone number on file', (int) $pdo->lastInsertId()]);
            }
        } catch (Throwable $e) {
            // Swallow: the audited business action already succeeded.
        }
    }

    /**
     * Indonesian mobile numbers to the 628xxx form the APIs expect.
     * Accepts 08xxx, +62 8xxx, 62-8xxx, with spaces/dashes.
     */
    public static function normalise_phone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '0')) {
            $d = '62' . substr($d, 1);
        } elseif (str_starts_with($d, '8')) {
            $d = '62' . $d;          // bare local mobile number
        }
        // 62 + 9..13 digits covers every Indonesian mobile range.
        return preg_match('/^62\d{9,13}$/', $d) === 1 ? $d : '';
    }

    /**
     * Drain the queue. Returns [sent, failed, skipped].
     * Called from CLI only (tools/send_notifications.php).
     */
    public static function flush(PDO $pdo, array $config, int $limit = 50): array
    {
        $wa = $config['wa'] ?? [];
        $driver = (string) ($wa['driver'] ?? 'log');
        $throttle = max(0, (int) ($wa['throttle'] ?? 1));

        $rows = $pdo->prepare(
            'SELECT * FROM notifications WHERE status = ? AND attempts < ? ORDER BY id LIMIT ' . max(1, $limit)
        );
        $rows->execute(['queued', self::MAX_ATTEMPTS]);

        $sent = $failed = 0;
        foreach ($rows->fetchAll() as $i => $n) {
            if ($i > 0 && $throttle > 0) {
                sleep($throttle);
            }
            $to = (string) ($wa['test_to'] ?? '') !== ''
                ? self::normalise_phone((string) $wa['test_to'])
                : (string) $n['phone'];

            [$okSend, $err] = self::dispatch($driver, $wa, $to, (string) $n['body']);
            $attempts = (int) $n['attempts'] + 1;

            if ($okSend) {
                $pdo->prepare("UPDATE notifications SET status='sent', attempts=?, error=NULL, sent_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$attempts, $n['id']]);
                $sent++;
            } else {
                // Keep it 'queued' until attempts run out, so a blip retries next run.
                $status = $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued';
                $pdo->prepare('UPDATE notifications SET status=?, attempts=?, error=? WHERE id=?')
                    ->execute([$status, $attempts, mb_substr($err, 0, 500), $n['id']]);
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /** @return array{0:bool,1:string} [ok, error] */
    private static function dispatch(string $driver, array $wa, string $to, string $body): array
    {
        if ($to === '') {
            return [false, 'empty recipient number'];
        }
        return match ($driver) {
            'fonnte' => self::send_via_fonnte($wa, $to, $body),
            'cloud'  => self::send_via_cloud($wa, $to, $body),
            default  => [true, ''],   // 'log': queue works end to end, nothing leaves the server
        };
    }

    private static function send_via_fonnte(array $wa, string $to, string $body): array
    {
        return self::http_post(
            'https://api.fonnte.com/send',
            http_build_query(['target' => $to, 'message' => $body]),
            ['Authorization: ' . (string) ($wa['token'] ?? '')]
        );
    }

    private static function send_via_cloud(array $wa, string $to, string $body): array
    {
        $id = (string) ($wa['sender'] ?? '');
        if ($id === '') {
            return [false, 'wa.sender (phone number ID) is not configured'];
        }
        return self::http_post(
            "https://graph.facebook.com/v21.0/{$id}/messages",
            json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body],
            ], JSON_UNESCAPED_UNICODE) ?: '',
            ['Authorization: Bearer ' . (string) ($wa['token'] ?? ''), 'Content-Type: application/json']
        );
    }

    /** Minimal curl POST — no HTTP library, matching the project's zero-dependency rule. */
    private static function http_post(string $url, string $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [false, 'php curl extension is not available'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            return [false, 'curl: ' . $cerr];
        }
        if ($code < 200 || $code >= 300) {
            return [false, "HTTP {$code}: " . mb_substr((string) $res, 0, 300)];
        }
        return [true, ''];
    }
}
