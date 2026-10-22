<?php
declare(strict_types=1);

/**
 * One-off: fill buyers' tax data (NPWP, name and address as registered,
 * NITKU) from files finance already has (FA-TAX-01). Each row's invoice
 * number finds the platform invoice, and through its order the customer.
 *
 *   php tools/import_customer_tax.php --file=ACP_Faktur_PK_Template_v1.6.1.xlsx              # dry run (sheet "Faktur")
 *   php tools/import_customer_tax.php --file=ACP_Faktur_PK_Template_v1.6.1.xlsx --apply
 *   php tools/import_customer_tax.php --file=DETAIL_DATA_INVOICE.xlsx --sheet="Sheet1"       # any sheet with an invoice-number and an NPWP column
 *
 * Columns are found by header: Referensi / No. Invoice, NPWP/NIK Pembeli or
 * NPWP, and optionally Nama Pembeli, Alamat Pembeli, ID TKU Pembeli, Email.
 * Only customers whose tax data is still empty are filled, so finance's own
 * edits are never overwritten. Real files stay on the server, never in git.
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
require __DIR__ . '/../app/Faktur.php';
$GLOBALS['config'] = $config;

$opts = getopt('', ['file:', 'sheet:', 'apply']);
$file = (string) ($opts['file'] ?? '');
if ($file === '' || !is_file($file)) {
    exit("Usage: php tools/import_customer_tax.php --file=… [--sheet=Faktur] [--apply]\n");
}
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);
$apply = isset($opts['apply']);
$rep = Faktur::import_customers($pdo, SalesHistory::read_xlsx($file, (string) ($opts['sheet'] ?? 'Faktur')), $apply, 'import');
if ($rep['header'] === []) {
    exit("No header row with an invoice-number column and an NPWP column was found.\n");
}
printf("Columns found: %s\n", implode(', ', $rep['header']));
printf("Rows: %d; invoice found: %d; customers to fill: %d; already filled (kept): %d\n", $rep['rows'], $rep['matched'], count($rep['updates']), $rep['skipped_filled']);
foreach (array_slice($rep['updates'], 0, 15) as $u) {
    echo "  {$u}\n";
}
foreach ($rep['conflicts'] as $c) {
    echo "  ! different NPWP for one customer, first kept: {$c}\n";
}
if ($rep['unmatched']) {
    printf("Invoice number not in the platform: %d (first 10): %s\n", count($rep['unmatched']), implode(' | ', array_slice($rep['unmatched'], 0, 10)));
}
echo $apply ? "Filled {$rep['applied']} customers.\n" : "Dry run: nothing written. Add --apply to write.\n";
