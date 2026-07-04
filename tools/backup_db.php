<?php
/**
 * Consistent, rotated SQLite backup for AluPanel CRM.
 *
 * Writes a standalone snapshot of data/crm.sqlite into ../backups/ and keeps the
 * newest N. Uses "VACUUM INTO", which produces a complete, consistent copy even
 * while the app is running in WAL mode (no torn reads, no -wal/-shm needed).
 *
 * Schedule daily via the Baota panel (计划任务 → Shell 脚本) or crontab:
 *   /www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/backup_db.php
 * (adjust the PHP path to your version — check with: ls /www/server/php/)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$config = require __DIR__ . '/../config.php';
$dbPath = $config['db_path'] ?? (__DIR__ . '/../data/crm.sqlite');

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found at: {$dbPath}\n");
    exit(1);
}

$dir = __DIR__ . '/../backups';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create backup dir: {$dir}\n");
    exit(1);
}

$keep = 14;                       // keep the newest N snapshots
$out  = $dir . '/crm_' . date('Y-m-d_His') . '.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Consistent snapshot regardless of concurrent writers (SQLite 3.27+).
    $pdo->exec('VACUUM INTO ' . $pdo->quote($out));
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// Rotation: newest first (timestamped names sort lexicographically), drop the rest.
$files = glob($dir . '/crm_*.sqlite') ?: [];
rsort($files);
foreach (array_slice($files, $keep) as $old) {
    @unlink($old);
}

echo 'Backup OK: ' . $out . ' (' . number_format((int) filesize($out)) . " bytes), "
    . 'keeping ' . min(count($files), $keep) . " snapshot(s).\n";
