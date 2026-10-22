<?php
declare(strict_types=1);

/**
 * Import the old bookkeeping app's 销售单 export (.xlsx) as historical sales.
 *
 *   php tools/import_sales_history.php --file=销售单.xlsx                 # dry run: what would be imported
 *   php tools/import_sales_history.php --file=销售单.xlsx --apply         # write it
 *   php tools/import_sales_history.php --file=销售单.xlsx --until=2026-08-03 --apply
 *   php tools/import_sales_history.php --file=销售单.xlsx --alias="erpandi=erfandi,游总=Yuan" --apply
 *   php tools/import_sales_history.php --purge-batch=20261022-110000     # undo one import
 *
 * Options:
 *   --until=DATE     last document date to take (default 2026-08-03, the day
 *                    before the platform's first real order — later documents
 *                    are already in the platform and must not be counted twice)
 *   --alias=a=b,...  map an old-app salesperson name to a platform user name
 *   --batch=LABEL    label stored on every row (default: timestamp)
 *   --apply          write; without it nothing is changed
 *
 * Re-running is safe: documents whose 单据编号 is already imported are skipped.
 * History never touches orders, invoices, stock or receivables.
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
require __DIR__ . '/../app/SalesHistory.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['file:', 'until:', 'alias:', 'batch:', 'apply', 'purge-batch:']);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

if (isset($opts['purge-batch'])) {
    $n = SalesHistory::purge_batch($pdo, (string) $opts['purge-batch']);
    echo "Removed $n documents of batch {$opts['purge-batch']}\n";
    exit(0);
}
if (!isset($opts['file']) || !is_file((string) $opts['file'])) {
    fwrite(STDERR, "Usage: --file=<export.xlsx> [--until=YYYY-MM-DD] [--alias=a=b,...] [--apply]\n");
    exit(64);
}
$aliases = [];
foreach (array_filter(explode(',', (string) ($opts['alias'] ?? ''))) as $pair) {
    [$a, $b] = array_pad(explode('=', $pair, 2), 2, '');
    if (trim($a) !== '' && trim($b) !== '') {
        $aliases[trim($a)] = trim($b);
    }
}
$apply = isset($opts['apply']);
$until = (string) ($opts['until'] ?? SalesHistory::DEFAULT_UNTIL);

$rows = SalesHistory::read_xlsx((string) $opts['file']);
$parsed = SalesHistory::parse($rows);
foreach ($parsed['errors'] as $e) {
    echo "  ! $e\n";
}
if ($parsed['docs'] === []) {
    fwrite(STDERR, "Nothing to import.\n");
    exit(1);
}
echo ($apply ? '' : '[dry run] ') . count($parsed['docs']) . " documents in file, taking those dated up to $until\n";

$rep = SalesHistory::import($pdo, $parsed['docs'], [
    'until' => $until, 'aliases' => $aliases, 'batch' => $opts['batch'] ?? null, 'by' => 'cli',
], $apply);

printf("  documents: %d (%d lines), %s – %s, total Rp %s\n", $rep['docs'], $rep['items'], $rep['first'] ?? '-', $rep['last'] ?? '-', number_format($rep['amount'], 0, ',', '.'));
printf("  skipped: %d after %s, %d already imported, %d unreadable\n", $rep['skipped_after'], $until, $rep['skipped_existing'], $rep['skipped_bad']);
printf("  customers: %d matched, %d walk-in, %d names not in the platform\n", $rep['customers_matched'], $rep['walk_in'], count($rep['customers_unmatched']));
foreach (array_slice($rep['customers_unmatched'], 0, 40, true) as $n => $c) {
    echo "      $n ($c)\n";
}
if (count($rep['customers_unmatched']) > 40) {
    echo '      … ' . (count($rep['customers_unmatched']) - 40) . " more\n";
}
echo '  products not in catalogue: ' . count($rep['products_unmatched']) . "\n";
foreach ($rep['products_unmatched'] as $n => $c) {
    echo "      $n ($c)\n";
}
echo '  salespeople not matching a user: ' . count($rep['users_unmatched']) . "\n";
foreach ($rep['users_unmatched'] as $n => $c) {
    echo "      $n ($c)\n";
}
if ($apply && isset($rep['batch'])) {
    echo "Imported as batch {$rep['batch']}. Undo with --purge-batch={$rep['batch']}\n";
} else {
    echo "Nothing written. Add --apply to import.\n";
}
