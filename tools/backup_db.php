<?php
/**
 * Consistent, rotated backup for AluPanel CRM — database + attachments.
 *
 * Produces ../backups/backup_<timestamp>.zip containing:
 *   crm.sqlite        a standalone VACUUM INTO snapshot (consistent even under WAL)
 *   uploads/...       every request attachment from data/uploads/
 * Keeps the newest N and drops the rest. If the zip extension is unavailable it
 * falls back to a DB-only crm_<timestamp>.sqlite snapshot (attachments NOT included).
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

$config  = require __DIR__ . '/../config.php';
$dbPath  = $config['db_path'] ?? (__DIR__ . '/../data/crm.sqlite');
$dataDir = dirname($dbPath);
$uploads = $dataDir . '/uploads';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found at: {$dbPath}\n");
    exit(1);
}

$dir = __DIR__ . '/../backups';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Could not create backup dir: {$dir}\n");
    exit(1);
}

$keep  = 14;
$stamp = date('Y-m-d_His');

// Clean any leftover temp snapshot from a previous interrupted run.
foreach (glob($dir . '/_tmp_*.sqlite') ?: [] as $stale) {
    @unlink($stale);
}

// 1) Consistent database snapshot (SQLite 3.27+, safe under WAL).
$snap = $dir . '/_tmp_' . $stamp . '.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($snap));
} catch (Throwable $e) {
    @unlink($snap);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$rotate = function (string $pattern) use ($dir, $keep): void {
    $files = glob($dir . '/' . $pattern) ?: [];
    rsort($files);   // timestamped names sort newest-first
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
};

// 2) Bundle DB snapshot + attachments into one zip (preferred).
if (class_exists('ZipArchive')) {
    $out = $dir . '/backup_' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($snap);
        fwrite(STDERR, "Could not create zip: {$out}\n");
        exit(1);
    }
    $zip->addFile($snap, 'crm.sqlite');

    $nFiles = 0;
    if (is_dir($uploads)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploads) + 1));
                $zip->addFile($file->getPathname(), $rel);
                $nFiles++;
            }
        }
    }
    $zip->close();
    @unlink($snap);
    $rotate('backup_*.zip');

    $kept = min(count(glob($dir . '/backup_*.zip') ?: []), $keep);
    echo 'Backup OK: ' . $out . ' (' . number_format((int) filesize($out)) . ' bytes, DB + '
        . $nFiles . " attachment file(s)), keeping {$kept} snapshot(s).\n";
    exit(0);
}

// 3) Fallback: DB-only snapshot (zip extension missing).
$out = $dir . '/crm_' . $stamp . '.sqlite';
rename($snap, $out);
$rotate('crm_*.sqlite');
$kept = min(count(glob($dir . '/crm_*.sqlite') ?: []), $keep);
fwrite(STDERR, "Note: zip extension not available — attachments (data/uploads) NOT backed up.\n");
echo 'Backup OK (DB only): ' . $out . ' (' . number_format((int) filesize($out)) . " bytes), keeping {$kept} snapshot(s).\n";
