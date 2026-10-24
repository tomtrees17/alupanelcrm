<?php
declare(strict_types=1);

/**
 * Import the 潜客名单 (Daftar Calon Pelanggan workbook) into prospects (HR-04).
 *
 *   php tools/import_prospects.php --file="Daftar Calon Pelanggan ….xlsx"            # dry run (default)
 *   php tools/import_prospects.php --file=… --apply                                    # write
 *   php tools/import_prospects.php --file=… --sheet="Semua" --alias="Pak Andi=angga"   # sheet / Sales PIC names
 *
 * Reads the "Semua" sheet when there is one (the region sheets repeat its
 * rows), else the first sheet. Same name + point = same prospect: a re-import
 * updates the attributes and fills an empty Sales PIC, never duplicates.
 * Rows marked Sudah Pelanggan are kept but never offered as prospects.
 * Results already typed into the Excel (Hasil / Tanggal / Catatan) are counted
 * and reported, not imported as visits.
 * Run as root on the server, then: chown -R www:www data
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
require __DIR__ . '/../app/Hr.php';
require __DIR__ . '/../app/HrContract.php';
require __DIR__ . '/../app/SalesHistory.php';
require __DIR__ . '/../app/Visits.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['file:', 'sheet:', 'apply', 'alias:', 'db:']);
$file = (string) ($opts['file'] ?? '');
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php tools/import_prospects.php --file=<xlsx> [--sheet=Semua] [--alias=\"Excel name=employee name,…\"] [--apply]\n");
    exit(64);
}
$apply = isset($opts['apply']);
$pdo = Database::connect((string) ($opts['db'] ?? $config['db_path']));
Database::migrate($pdo);

$sheet = isset($opts['sheet']) ? (string) $opts['sheet'] : 'Semua';
try {
    $rows = SalesHistory::read_xlsx($file, $sheet);
} catch (RuntimeException $e) {
    if (isset($opts['sheet'])) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    $sheet = '(first sheet)';
    $rows = SalesHistory::read_xlsx($file);
}
$aliases = [];
foreach (array_filter(explode(',', (string) ($opts['alias'] ?? ''))) as $pair) {
    [$from, $to] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
    if ($from !== '' && $to !== '') {
        $aliases[mb_strtolower($from)] = $to;
    }
}

$r = Visits::import_prospects($pdo, $rows, $apply, basename($file), 'import', $aliases);
if ($r['missing_columns']) {
    printf("Missing columns: %s\n", implode(', ', $r['missing_columns']));
    if (in_array('name', $r['missing_columns'], true)) {
        exit(1);
    }
}
printf("%sSheet %s: %d rows, %d new, %d already imported (updated), %d duplicates skipped, %d marked Sudah Pelanggan\n",
    $apply ? '' : '[dry run] ', $sheet, $r['rows'], $r['new'], $r['updated'], $r['skipped'], $r['customers']);
echo 'By region:   ' . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($r['regions']), $r['regions'])) . "\n";
echo 'By category: ' . implode(', ', array_map(fn($k, $v) => "{$k} {$v}", array_keys($r['categories']), $r['categories'])) . "\n";
if ($r['unknown_pic']) {
    echo 'Sales PIC names not found among employees (left unassigned; map with --alias): ' . implode(', ', $r['unknown_pic']) . "\n";
}
if ($r['filled_results'] > 0) {
    echo "{$r['filled_results']} rows already have Hasil / Tanggal filled in the Excel; they are not imported as visits.\n";
}
if (!$apply) {
    echo "Nothing written. Add --apply to import.\n";
}
