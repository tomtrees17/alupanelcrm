<?php
declare(strict_types=1);

/**
 * One-off: load the finance workbook's 「1. Mutasi Bank BCA」 IN lines
 * (FA-AI-01, task card item 8). Each line's invoice is looked up by number,
 * the payer name is learned for that customer (seed for rule matching), and
 * the line is kept in bank_book for the blind benchmark below.
 *
 *   php tools/import_bank_book.php --file=Point_2026.xlsx              # dry run: report only
 *   php tools/import_bank_book.php --file=Point_2026.xlsx --apply      # write
 *   php tools/import_bank_book.php --file=… --sheet="1. Mutasi Bank BCA" --apply
 *
 * Blind test (card acceptance: September, invoice numbers hidden):
 *   php tools/import_bank_book.php --benchmark=2026-09                 # rules only, free
 *   php tools/import_bank_book.php --benchmark=2026-09 --ai            # rules + model (costs; counts toward the AI caps)
 *   php tools/import_bank_book.php --benchmark=2026-09 --ai --limit=20 --verbose
 *
 * The real workbook stays on the server (never in git); run from the site
 * root, then `chown -R www:www data`. Re-running is safe: codes and aliases
 * are unique.
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
require __DIR__ . '/../app/Claude.php';
require __DIR__ . '/../app/AiTask.php';
require __DIR__ . '/../app/Bank.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['file:', 'sheet:', 'apply', 'batch:', 'benchmark:', 'ai', 'limit:', 'verbose']);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

if (isset($opts['benchmark'])) {
    $month = (string) $opts['benchmark'];
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        exit("--benchmark=YYYY-MM\n");
    }
    $ai = isset($opts['ai']);
    if ($ai && !AiTask::enabled($config)) {
        exit("--ai needs ai.key (ANTHROPIC_API_KEY in .env)\n");
    }
    $r = Bank::benchmark($pdo, $config, $month, $ai, (int) ($opts['limit'] ?? 0), isset($opts['verbose']) ? fn(string $l) => print("  {$l}\n") : null);
    printf("Benchmark %s: %d lines with a known invoice\n", $r['month'], $r['lines']);
    printf("  rules:  %d matched, %d correct\n", $r['rule'], $r['rule_ok']);
    if ($ai) {
        printf("  model:  %d matched, %d correct; high confidence %d, of which wrong %d (%.1f%%); not processed %d\n",
            $r['ai'], $r['ai_ok'], $r['ai_high'], $r['ai_high_wrong'], $r['ai_high_error_rate'] * 100, $r['ai_failed']);
    }
    printf("  left for a person: %d\n", $r['none']);
    printf("  accuracy (correct / all): %.1f%%   (target ≥ 85%%; high-confidence model errors ≤ 5%%)\n", $r['accuracy'] * 100);
    exit(0);
}

$file = (string) ($opts['file'] ?? '');
if ($file === '' || !is_file($file)) {
    exit("Usage: php tools/import_bank_book.php --file=Point_2026.xlsx [--apply]   |   --benchmark=YYYY-MM [--ai]\n");
}
$sheet = (string) ($opts['sheet'] ?? '1. Mutasi Bank BCA');
$parsed = Bank::book_rows(SalesHistory::read_xlsx($file, $sheet));
foreach ($parsed['errors'] as $e) {
    echo "  ! {$e}\n";
}
$apply = isset($opts['apply']);
$rep = Bank::book_import($pdo, $parsed['rows'], $apply, (string) ($opts['batch'] ?? date('Ymd-His')));
printf("%s: %d IN lines; invoice found for %d; no payer name (cash / platform) %d\n", $sheet, $rep['lines'], $rep['resolved'], $rep['no_payer']);
printf("Payer names learned: %d%s\n", count($rep['aliases']), $apply ? " (new: {$rep['aliases_added']})" : '');
foreach (array_slice($rep['aliases'], 0, 15) as $a) {
    echo "  {$a}\n";
}
if ($rep['conflicts']) {
    echo "Same payer for several customers (kept for each; the rule needs one open invoice to fit):\n";
    foreach ($rep['conflicts'] as $c) {
        echo "  {$c}\n";
    }
}
if ($rep['unresolved']) {
    printf("Invoice number not found in the platform: %d (first 20)\n", count($rep['unresolved']));
    foreach (array_slice($rep['unresolved'], 0, 20) as $u) {
        echo "  {$u}\n";
    }
}
echo $apply ? "Stored {$rep['stored']} new lines in bank_book.\n" : "Dry run: nothing written. Add --apply to store.\n";
if ($apply) {
    // The new payer names apply to lines already waiting on the bank page too.
    printf("Unmatched bank lines now matched by the rules (waiting for finance to confirm): %d\n", Bank::rematch($pdo, $config));
}
