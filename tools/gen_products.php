<?php
declare(strict_types=1);

/**
 * One-off generator: extract the PRODUCTS catalog from the planning
 * prototype HTML and emit an INSERT-based SQL seed file.
 *
 * Usage: php tools/gen_products.php "C:\path\to\crm-system_25.html"
 */

$src = $argv[1] ?? 'C:\\Users\\yuans\\Downloads\\crm-system_25.html';
$html = file_get_contents($src);
if ($html === false) {
    fwrite(STDERR, "Cannot read: $src\n");
    exit(1);
}

// Each product literal is uniform; capture the fields we need.
$re = "/\\{id:\\d+,sku:'([^']*)',name:'([^']*)',spec:'([^']*)',color_zh:'([^']*)',color_en:'([^']*)',stock:(\\d+),minStock:(\\d+),unit:'([^']*)',price:(\\d+),category:'([^']*)'\\}/u";

preg_match_all($re, $html, $m, PREG_SET_ORDER);
fwrite(STDERR, 'Matched products: ' . count($m) . "\n");
if (!$m) {
    exit(1);
}

$q = fn($s) => "'" . str_replace("'", "''", $s) . "'";

$lines = [];
$lines[] = '-- Auto-generated product catalog seed (from planning prototype).';
$lines[] = 'INSERT INTO products (sku, name, color_zh, color_en, spec, size, category, unit, price, stock, min_stock) VALUES';

$rows = [];
foreach ($m as $p) {
    [$full, $sku, $name, $spec, $cz, $ce, $stock, $minStock, $unit, $price, $category] = $p;
    $rows[] = sprintf(
        '(%s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d)',
        $q($sku), $q($name), $q($cz), $q($ce), $q($spec),
        $q('1.220 x 2.440'), $q($category), $q($unit),
        (int) $price, (int) $stock, (int) $minStock
    );
}
$lines[] = implode(",\n", $rows) . ';';

file_put_contents(__DIR__ . '/../database/seed_products.sql', implode("\n", $lines) . "\n");
fwrite(STDERR, "Wrote database/seed_products.sql\n");
