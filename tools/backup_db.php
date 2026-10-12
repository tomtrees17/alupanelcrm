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
 *
 * Offsite: when config 'offsite.driver' is 'oss', each snapshot is also uploaded
 * to Alibaba Cloud OSS. The outcome is recorded in app_meta (offsite_last_ok /
 * offsite_last_error) so the dashboard can warn an admin when uploads stop.
 *   php tools/backup_db.php --test-offsite   # upload a tiny probe, no backup
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$config  = require __DIR__ . '/../config.php';
require __DIR__ . '/../app/Oss.php';

/** Upload one file offsite. @return array{0:?bool,1:string} [ok (null = disabled), message] */
function offsite_push(array $config, string $file): array
{
    $off = $config['offsite'] ?? [];
    if ((string) ($off['driver'] ?? '') !== 'oss') {
        return [null, 'offsite backup not configured'];
    }
    return Oss::put_file($off, (string) ($off['prefix'] ?? '') . basename($file), $file,
        str_ends_with($file, '.zip') ? 'application/zip' : 'application/octet-stream');
}

if (in_array('--test-offsite', $argv, true)) {
    $probe = tempnam(sys_get_temp_dir(), 'probe');
    file_put_contents($probe, 'AluPanel offsite probe ' . date('c') . "\n");
    $named = dirname($probe) . '/_probe.txt';
    rename($probe, $named);
    [$ok, $msg] = offsite_push($config, $named);
    @unlink($named);
    echo ($ok ? 'OK: ' : 'FAILED: ') . $msg . "\n";
    exit($ok ? 0 : 2);
}
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
} else {
    // 3) Fallback: DB-only snapshot (zip extension missing).
    $out = $dir . '/crm_' . $stamp . '.sqlite';
    rename($snap, $out);
    $rotate('crm_*.sqlite');
    $kept = min(count(glob($dir . '/crm_*.sqlite') ?: []), $keep);
    fwrite(STDERR, "Note: zip extension not available — attachments (data/uploads) NOT backed up.\n");
    echo 'Backup OK (DB only): ' . $out . ' (' . number_format((int) filesize($out)) . " bytes), keeping {$kept} snapshot(s).\n";
}

// 4) Offsite copy. A local backup that cannot leave the machine is still a
//    backup, so an upload failure is reported (exit 2) but never undoes it.
[$ok, $msg] = offsite_push($config, $out);
if ($ok === null) {
    echo "Offsite: skipped ({$msg}).\n";
    exit(0);
}
try {
    $meta = $pdo->prepare('INSERT OR REPLACE INTO app_meta (k, v) VALUES (?, ?)');
    $meta->execute([$ok ? 'offsite_last_ok' : 'offsite_last_error', date('Y-m-d H:i:s') . ($ok ? '' : ' ' . $msg)]);
    if ($ok) {
        $meta->execute(['offsite_last_error', '']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not record offsite status: ' . $e->getMessage() . "\n");
}
if (!$ok) {
    fwrite(STDERR, "Offsite upload FAILED: {$msg}\n");
    exit(2);
}
echo "Offsite: {$msg}\n";
