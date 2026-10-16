<?php
declare(strict_types=1);

/**
 * Nightly customer-analysis job — the single command for the Baota cron:
 *
 *   /www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/nightly.php
 *
 * Runs each step as its own process so one failing step does not stop the
 * next; exits non-zero if any step did.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

$steps = [
    'enrich'   => __DIR__ . '/enrich_customers.php',
    'insights' => __DIR__ . '/compute_insights.php',
];

$rc = 0;
foreach ($steps as $name => $script) {
    echo '── ' . date('Y-m-d H:i:s') . " {$name} ──\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $code);
    if ($code !== 0) {
        echo "{$name} exited with {$code}\n";
        $rc = 1;
    }
}
exit($rc);
