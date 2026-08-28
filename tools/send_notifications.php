<?php
declare(strict_types=1);

/**
 * Drain the WhatsApp notification queue. Run from cron (宝塔计划任务), e.g.每分钟:
 *
 *   /www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/send_notifications.php
 *
 * Safe to run concurrently-ish: SQLite's busy_timeout serialises writers, and a
 * message that is mid-flight simply retries on the next run (attempts is capped).
 * With driver 'log' nothing leaves the server — useful for checking the wiring.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../app/i18n.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/domain.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Notify.php';

$GLOBALS['config'] = $config;

$pdo = Database::connect($config['db_path']);

$pending = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE status='queued'")->fetchColumn();
if ($pending === 0) {
    exit(0);   // quiet by design: this runs every minute
}

[$sent, $failed] = Notify::flush($pdo, $config, 50);

$driver = (string) ($config['wa']['driver'] ?? 'log');
echo date('Y-m-d H:i:s') . " driver={$driver} sent={$sent} failed={$failed} pending_before={$pending}\n";

// Anything stuck at the attempt cap needs a human; make it visible in the cron log.
$dead = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE status='failed'")->fetchColumn();
if ($dead > 0) {
    echo "  ⚠ {$dead} 条发送失败（已达重试上限），见 系统通知 页面\n";
}
