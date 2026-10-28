<?php
declare(strict_types=1);

/**
 * HR-07 销售周评: last week's review, once (docs/任务卡-拜访周评.md §一).
 * A step of tools/nightly.php (01:30 WIB); a week that already has a review
 * is left alone, so it only does work on Monday night, or the night after a
 * missed Monday.
 *
 *   php tools/sales_review.php                      # as of today (WIB)
 *   php tools/sales_review.php --date=2026-11-02    # as of another day
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
require __DIR__ . '/../app/AiTask.php';
require __DIR__ . '/../app/Dashboard.php';
require __DIR__ . '/../app/Hr.php';
require __DIR__ . '/../app/HrContract.php';
require __DIR__ . '/../app/Visits.php';
require __DIR__ . '/../app/VisitImport.php';
require __DIR__ . '/../app/SalesReview.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['date:']);
$today = (string) ($opts['date'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(64);
}
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

$week = SalesReview::last_week($today);
$r = SalesReview::nightly($pdo, $config, $today);
if ($r === null) {
    echo "Sales review for the week of {$week}: already there\n";
} else {
    echo "Sales review for the week of {$week}: #{$r['id']} {$r['status']}" . ($r['error'] !== '' ? " ({$r['error']})" : '') . "\n";
}
