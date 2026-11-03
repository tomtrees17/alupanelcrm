<?php
declare(strict_types=1);

/**
 * Which private files go into the nightly backup zip next to the database
 * (tools/backup_db.php). Each folder is stored under its own name, the same
 * layout as data/, so a restore is: unzip, put crm.sqlite and the folders
 * back into data/.
 *
 * Until 2026-11-03 only data/uploads was bundled; HR attachments, visit
 * photos, generated Coretax files and signature images were not.
 */
final class Backup
{
    /**
     * zip prefix => absolute folder. Paths follow where each owner writes:
     * uploads and signatures sit next to the database (config db_path);
     * HR, visits and Faktur under the app's data/ (Hr::upload_dir,
     * Visits::photo_dir, Faktur::store_dir).
     */
    public static function folders(array $config, ?string $appData = null): array
    {
        $dbData = dirname((string) ($config['db_path'] ?? (__DIR__ . '/../data/crm.sqlite')));
        $appData ??= dirname(__DIR__) . '/data';
        return [
            'uploads'    => $dbData . '/uploads',
            'signatures' => $dbData . '/signatures',
            'hr'         => $appData . '/hr',
            'visits'     => $appData . '/visits',
            'faktur'     => $appData . '/faktur',
        ];
    }

    /** Add every file of those folders; returns [prefix => file count]. Missing folders count 0. */
    public static function add_folders(ZipArchive $zip, array $folders): array
    {
        $counts = [];
        foreach ($folders as $prefix => $path) {
            $counts[$prefix] = 0;
            if (!is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), $prefix . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1)));
                    $counts[$prefix]++;
                }
            }
        }
        return $counts;
    }
}
