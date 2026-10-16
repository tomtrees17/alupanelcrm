<?php
declare(strict_types=1);

/**
 * Infer customer region (from order addresses) and industry (rules, then
 * Claude). Safe to re-run: incremental by default, never overwrites an
 * industry a salesperson confirmed by hand.
 *
 *   php tools/enrich_customers.php              # incremental (nightly)
 *   php tools/enrich_customers.php --all        # re-resolve every order, re-ask the model for AI-set industries
 *   php tools/enrich_customers.php --dry-run    # report only: no writes, no paid API calls
 *   php tools/enrich_customers.php --no-ai --no-geo --limit=50
 *
 * Keys (config.local.php): geo.key = Google Geocoding, ai.key = Anthropic.
 * Without them it still runs on the address text and the rules alone.
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
require __DIR__ . '/../app/Claude.php';
require __DIR__ . '/../app/Enrich.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['all', 'dry-run', 'no-ai', 'no-geo', 'limit:']);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

$opt = [
    'all'   => isset($opts['all']),
    'dry'   => isset($opts['dry-run']),
    'geo'   => !isset($opts['no-geo']),
    'ai'    => !isset($opts['no-ai']),
    'limit' => (int) ($opts['limit'] ?? 0),
];
$t0 = microtime(true);
echo ($opt['dry'] ? '[dry run] ' : '') . 'Enriching customers' . ($opt['all'] ? ' (full re-run)' : '') . "\n";
echo '  geocoding: ' . (Enrich::geo_enabled($config) ? 'Google' : 'off — address text only (set geo.key)') . "\n";
echo '  industry AI: ' . (Enrich::ai_enabled($config) ? (string) $config['insights']['industry_model'] : 'off — rules only (set ai.key)') . "\n";

$s = Enrich::run($pdo, $config, $opt, function (string $line): void {
    echo $line . "\n";
});

// Coverage over the whole book, not just this run.
$shipped = Enrich::SHIPPED;
$tot = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE {$shipped}")->fetchColumn();
$known = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE {$shipped} AND delivery_region IS NOT NULL AND delivery_region <> 'unknown'")->fetchColumn();
$points = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE {$shipped} AND delivery_lat IS NOT NULL")->fetchColumn();
$ind = $pdo->query('SELECT COALESCE(industry_source, \'(none)\') s, COUNT(*) c FROM customers GROUP BY s')->fetchAll(PDO::FETCH_KEY_PAIR);
$pending = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE industry_pending = 1 AND COALESCE(industry_source, \'\') <> \'manual\'')->fetchColumn();

$src = [];
foreach ($s['by_source'] as $k => $v) {
    $src[] = "{$k} {$v}";
}
printf("Orders processed this run: %d (%s)%s\n", $s['orders'], implode(', ', $src) ?: '-', $s['geo_errors'] ? ", geocode errors {$s['geo_errors']}" : '');
printf("Shipped orders with a region: %d / %d (%.0f%%), with a map point: %d\n", $known, $tot, $tot ? 100 * $known / $tot : 0, $points);
printf("Customer regions changed: %d\n", $s['customers_region']);
printf("Industry this run: rule %d, AI %d (待确认 %d, failed %d), manual kept %d\n", $s['rule'], $s['ai'], $s['ai_pending'], $s['ai_failed'], $s['manual_kept']);
printf("Industry overall by source: %s; 待确认 %d\n", json_encode($ind, JSON_UNESCAPED_UNICODE), $pending);
if ($s['ai_in'] || $s['ai_out']) {
    printf("AI tokens: in %d, out %d\n", $s['ai_in'], $s['ai_out']);
}
printf("Done in %.1fs\n", microtime(true) - $t0);

if (!$opt['dry']) {
    $summary = sprintf('订单 %d; 区域覆盖 %d/%d; 行业 规则 %d / AI %d (待确认 %d, 失败 %d)', $s['orders'], $known, $tot, $s['rule'], $s['ai'], $s['ai_pending'], $s['ai_failed']);
    $pdo->prepare("INSERT OR REPLACE INTO app_meta (k, v) VALUES ('enrich_last_run', ?)")->execute([date('Y-m-d H:i:s') . ' ' . $summary]);
    if ($s['orders'] > 0 || $s['rule'] + $s['ai'] > 0) {
        audit($pdo, 'customers', 'enrich', 'customer', null, 'enrich_customers', $summary);
    }
}
exit($s['geo_errors'] > 0 || $s['ai_failed'] > 0 ? 2 : 0);
