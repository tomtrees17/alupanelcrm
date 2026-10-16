<?php
declare(strict_types=1);

/**
 * Rebuild the call lists (该回访 / 快流失 / 值得多跑) in customer_insights.
 * Run nightly by tools/nightly.php, after the enrichment step.
 *
 *   php tools/compute_insights.php                 # as of today
 *   php tools/compute_insights.php --date=2026-12-01   # as of another day (what-if)
 *   php tools/compute_insights.php --show=10       # also print the top 10 of each list
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
require __DIR__ . '/../app/Enrich.php';
require __DIR__ . '/../app/Insights.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['date:', 'show:']);
$today = (string) ($opts['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(64);
}
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

$s = Insights::compute($pdo, $config, $today);
printf("Call lists as of %s: %d customers with shipped orders\n", $today, $s['customers']);
printf("  A 该回访 %d · B 快流失 %d · C 值得多跑 %d  (C threshold: top 20%% = 12-month amount ≥ %s)\n",
    $s['a'], $s['b'], $s['c'], number_format($s['threshold'], 0, ',', '.'));
$src = $pdo->query('SELECT interval_source, COUNT(*) FROM customer_insights GROUP BY interval_source')->fetchAll(PDO::FETCH_KEY_PAIR);
printf("  usual interval from: own history %d · industry median %d · global median %d\n", $src['own'] ?? 0, $src['industry'] ?? 0, $src['global'] ?? 0);

$snoozed = count(Insights::snoozed($pdo, $config, $today));
if ($snoozed) {
    printf("  %d customers currently snoozed (followed up / no need) and hidden from the lists\n", $snoozed);
}

$show = (int) ($opts['show'] ?? 0);
if ($show > 0) {
    $lists = Insights::lists($pdo, $config, $today);
    foreach (['a' => 'A 该回访', 'b' => 'B 快流失', 'c' => 'C 值得多跑'] as $k => $title) {
        echo "\n{$title} (" . count($lists[$k]) . ")\n";
        foreach (array_slice($lists[$k], 0, $show) as $r) {
            printf("  %-28s %-10s last %s (%dd ago, usually every %sd%s)  12m Rp%s%s\n",
                mb_substr((string) $r['name'], 0, 28), (string) $r['owner'] ?: '-', $r['last_order_date'], $r['days_since'],
                $r['avg_interval'] === null ? '?' : (string) round((float) $r['avg_interval']), $r['interval_source'] === 'own' ? '' : ', ' . $r['interval_source'],
                number_format((float) $r['total_amount'], 0, ',', '.'),
                $k === 'b' ? sprintf('  overdue %dd%s', $r['overdue_days'], $r['overdue_red'] ? ' (RED)' : '') : '');
        }
    }
}
