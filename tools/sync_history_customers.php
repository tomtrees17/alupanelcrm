<?php
declare(strict_types=1);

/**
 * Bring the customer list up to date from the imported sales history.
 *
 *   php tools/sync_history_customers.php            # dry run: what would change
 *   php tools/sync_history_customers.php --apply    # do it, then rebuild the call lists
 *
 * What it does:
 *   1. Customers that only exist in the old app (history rows with no
 *      customer_id, walk-ins excluded) are created — one per distinct name —
 *      owned by the salesperson who sold to them most, tagged 成交, and the
 *      history rows are linked to them.
 *   2. Every customer with history purchases: tag 潜在 → 成交 (they did buy);
 *      an empty owner gets the history salesperson; last_contact moves
 *      forward to the last history purchase if it is later.
 *   3. Call lists (customer_insights) are recomputed so the history counts as
 *      purchases (Insights::compute reads sales_history).
 *
 * Idempotent: a second run finds nothing left to create or change.
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
require __DIR__ . '/../app/Enrich.php';
require __DIR__ . '/../app/Insights.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['apply']);
$apply = isset($opts['apply']);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

$rep = SalesHistory::sync_customers($pdo, $apply);
echo ($apply ? '' : '[dry run] ') . "Customers from history\n";
printf("  to create: %d (%d history documents)\n", count($rep['create']), array_sum(array_map(fn($c) => $c['docs'], $rep['create'])));
foreach ($rep['create'] as $c) {
    printf("      %-40s %-12s %3d docs  Rp %s\n", mb_substr($c['name'], 0, 40), $c['owner'] ?? '-', $c['docs'], number_format($c['amount'], 0, ',', '.'));
}
printf("  existing customers: %d tag → 成交, %d owner filled, %d last_contact moved forward\n", $rep['tagged'], $rep['owned'], $rep['contacted']);
if ($apply) {
    $r = Insights::compute($pdo, $config, date('Y-m-d'));
    printf("Call lists rebuilt: %d customers (A %d / B %d / C %d)\n", $r['customers'], $r['a'], $r['b'], $r['c']);
    echo "Done. Remember: chown -R www:www data\n";
} else {
    echo "Nothing written. Add --apply to do it.\n";
}
