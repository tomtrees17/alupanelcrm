<?php
declare(strict_types=1);

/**
 * Import the Google Form "Request CO Number (Responses)" sheet as the CO
 * number register, and optionally link old platform orders to their number.
 *
 *   php tools/import_co_requests.php --file="Request CO Number (Responses).xlsx"          # dry run
 *   php tools/import_co_requests.php --file=… --apply                                      # import new numbers
 *   php tools/import_co_requests.php --link                                                # propose links for orders since 2026-08-04
 *   php tools/import_co_requests.php --link --alias="erfandi=erpandi,angga=angga w" --apply
 *
 * Re-importing a newer export only adds the numbers issued since (seq is
 * unique). --link never renames an order: it fills orders.co_no, the number
 * the customer knows, while order_no stays what DO and invoice carry.
 * Linking is applied only for unambiguous matches; the rest are listed for
 * finance to link by hand on the order page.
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
require __DIR__ . '/../app/CoNumbers.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['file:', 'apply', 'link', 'from:', 'alias:', 'batch:']);
$apply = isset($opts['apply']);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

if (isset($opts['file'])) {
    if (!is_file((string) $opts['file'])) {
        fwrite(STDERR, "No such file: {$opts['file']}\n");
        exit(64);
    }
    $rows = CoNumbers::parse(SalesHistory::read_xlsx((string) $opts['file']));
    if ($rows === []) {
        fwrite(STDERR, "No register rows found in the file.\n");
        exit(1);
    }
    $rep = CoNumbers::import($pdo, $rows, (string) ($opts['batch'] ?? date('Ymd-His')), $apply);
    printf("%s%d numbers in file (up to %04d), %d new, %d already in the register\n", $apply ? '' : '[dry run] ', count($rows), $rep['max_seq'], $rep['added'], $rep['existing']);
    printf("Next order number the platform will issue: %s\n", next_order_no($pdo));
    if (!$apply) {
        echo "Nothing written. Add --apply to import.\n";
    }
}

if (isset($opts['link'])) {
    $aliases = [];
    foreach (array_filter(explode(',', (string) ($opts['alias'] ?? ''))) as $pair) {
        [$a, $b] = array_pad(explode('=', $pair, 2), 2, '');
        if (trim($a) !== '' && trim($b) !== '') {
            $aliases[mb_strtolower(trim($a))] = mb_strtolower(trim(explode(' ', trim($b))[0]));
        }
    }
    $from = (string) ($opts['from'] ?? '2026-08-04');
    $res = CoNumbers::propose($pdo, $from, $aliases);
    $line = fn(array $e): string => sprintf('  %-20s %-28s %-10s %s', $e['order']['order_no'], mb_substr((string) ($e['order']['company'] ?: $e['order']['customer_name']), 0, 28), $e['order']['d'], $e['order']['submitter']);
    printf("%sOrders since %s without a CO number: %d\n", $apply ? '' : '[dry run] ', $from, count($res['matched']) + count($res['ambiguous']) + count($res['unmatched']));
    printf("  sure matches: %d\n", count($res['matched']));
    foreach ($res['matched'] as $e) {
        echo $line($e) . sprintf('  → %s  %s / %s  (%s, %.1f)', $e['req']['co_no'], $e['req']['company'], $e['req']['client_name'], $e['req']['sales_name'], $e['score']) . "\n";
    }
    printf("  needs a look (best guess shown, not applied): %d\n", count($res['ambiguous']));
    foreach ($res['ambiguous'] as $e) {
        echo $line($e) . sprintf('  ?  %s  %s / %s  (%s, %.1f vs %.1f)', $e['req']['co_no'], $e['req']['company'], $e['req']['client_name'], $e['req']['sales_name'], $e['score'], $e['second']) . "\n";
    }
    printf("  no candidate in the register: %d\n", count($res['unmatched']));
    foreach ($res['unmatched'] as $e) {
        echo $line($e) . "\n";
    }
    if ($apply && $res['matched'] !== []) {
        foreach ($res['matched'] as $e) {
            CoNumbers::link($pdo, (int) $e['order']['id'], (string) $e['req']['co_no'], 'cli', false, 'auto');
        }
        printf("Linked %d orders.\n", count($res['matched']));
    } elseif (!$apply) {
        echo "Nothing written. Add --apply to link the sure matches.\n";
    }
}

if (!isset($opts['file']) && !isset($opts['link'])) {
    fwrite(STDERR, "Usage: --file=<responses.xlsx> [--apply] | --link [--from=YYYY-MM-DD] [--alias=a=b,...] [--apply]\n");
    exit(64);
}
