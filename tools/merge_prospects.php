<?php
declare(strict_types=1);

/**
 * Merge duplicate prospects (the same store met on a visit and then found in
 * the imported list): visits move to the kept row, the group's spellings
 * become aliases, the other row is hidden (never deleted).
 *
 *   php tools/merge_prospects.php --pairs="12=345,14=377"            # dry run: from=into
 *   php tools/merge_prospects.php --pairs="12=345,14=377" --apply
 * Run as root on the server, then: chown -R www:www data
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}
$config = require __DIR__ . '/../config.php';
foreach (['i18n', 'helpers', 'domain', 'Database', 'Hr', 'HrContract', 'Visits', 'WaChat', 'VisitImport'] as $f) {
    require __DIR__ . "/../app/{$f}.php";
}
$GLOBALS['config'] = $config;
$opts = getopt('', ['pairs:', 'apply', 'db:']);
$pdo = Database::connect((string) ($opts['db'] ?? $config['db_path']));
Database::migrate($pdo);
$name = $pdo->prepare('SELECT name, source, merged_into, (SELECT COUNT(*) FROM visits WHERE prospect_id = prospects.id) n FROM prospects WHERE id = ?');
foreach (array_filter(explode(',', (string) ($opts['pairs'] ?? ''))) as $pair) {
    [$from, $into] = array_map('intval', explode('=', $pair, 2) + [1 => 0]);
    $name->execute([$from]);
    $a = $name->fetch();
    $name->execute([$into]);
    $b = $name->fetch();
    if (!$a || !$b) {
        echo "#{$from} → #{$into}: not found\n";
        continue;
    }
    printf("%s#%d %s (%s, %d visits) → #%d %s (%s)\n", isset($opts['apply']) ? '' : '[dry run] ', $from, $a['name'], $a['source'], $a['n'], $into, $b['name'], $b['source']);
    if (isset($opts['apply'])) {
        echo '  moved ' . VisitImport::merge_prospect($pdo, $from, $into, 'merge tool') . " visits\n";
    }
}
if (!isset($opts['apply'])) {
    echo "Nothing written. Add --apply to merge.\n";
}
