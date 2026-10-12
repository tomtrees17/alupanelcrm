<?php
declare(strict_types=1);

/**
 * One-off correction: make existing invoice totals equal what the customer was
 * quoted, by recording the 1–3 rupiah rounding gap as Pembulatan.
 *
 * Before this fix, Subtotal + VAT could differ from the order's tax-inclusive
 * total by a rupiah or two. A customer who paid the exact order amount was then
 * left owing Rp 1 and showed as overdue. Subtotal, DPP and VAT are NOT changed —
 * only `rounding` is set and `total` moved to the order amount.
 *
 *   php tools/fix_invoice_rounding.php            # dry run: report only
 *   php tools/fix_invoice_rounding.php --apply    # write, in one transaction
 *
 * Safe to run more than once: already-correct invoices are left alone.
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
$GLOBALS['config'] = $config;

$apply = in_array('--apply', $argv, true);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);   // make sure invoices.rounding exists
$rate = (float) ($config['ppn_rate'] ?? 11);
$n = fn($v) => number_format((float) $v, 0, ',', '.');

$fix = [];
$skips = [];
$ok = 0;
foreach ($pdo->query('SELECT * FROM invoices ORDER BY id') as $inv) {
    $plan = invoice_rounding_plan($pdo, $inv, $rate);
    if ($plan['action'] === 'fix') {
        $fix[] = [$inv, $plan];
    } elseif ($plan['action'] === 'skip') {
        $skips[] = [$inv, $plan['reason']];
    } else {
        $ok++;
    }
}

// Who stops showing a phantom Rp 1–3 debt once the total is right?
$cleared = array_filter($fix, fn($f) => (float) $f[0]['amount_paid'] < (float) $f[0]['total'] - 0.005
                                       && (float) $f[0]['amount_paid'] >= $f[1]['total'] - 0.005);

echo ($apply ? '' : "【预演，不会改动任何数据】\n") . "\n";
printf("  已正确      %4d 张\n", $ok);
printf("  需要订正    %4d 张  （其中 %d 张客户已按订单金额付清，订正后不再显示欠款）\n", count($fix), count($cleared));
printf("  跳过        %4d 张\n\n", count($skips));

foreach (array_slice($fix, 0, 8) as [$inv, $plan]) {
    printf("  %-28s 合计 %14s → %14s   Pembulatan %s\n",
        $inv['invoice_no'], $n($inv['total']), $n($plan['total']), rp_signed($plan['rounding']));
}
if (count($fix) > 8) {
    echo '  ... 还有 ' . (count($fix) - 8) . " 张\n";
}
if ($skips) {
    echo "\n跳过的发票（不会改动）：\n";
    foreach ($skips as [$inv, $why]) {
        printf("  %-28s %s\n", $inv['invoice_no'], $why);
    }
}

if (!$apply) {
    echo "\n这是预演。确认无误后加 --apply 执行（先备份： php tools/backup_db.php）。\n";
    exit(0);
}
if (!$fix) {
    echo "\n没有需要订正的发票。\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare('UPDATE invoices SET rounding = ?, total = ? WHERE id = ?');
    foreach ($fix as [$inv, $plan]) {
        $upd->execute([$plan['rounding'], $plan['total'], (int) $inv['id']]);
        refresh_invoice_status($pdo, (int) $inv['id'], date('Y-m-d'));
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n订正失败，已回滚，数据未改动：\n  " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($fix as [$inv, $plan]) {
    audit($pdo, 'finance', 'update', 'invoice', (int) $inv['id'], (string) $inv['invoice_no'],
        sprintf('尾差订正（CLI）：合计 %s → %s，Pembulatan %s；Subtotal/VAT 不变',
            idr((float) $inv['total']), idr($plan['total']), rp_signed($plan['rounding'])));
}
printf("\n已订正 %d 张，审计日志已记录。别忘了： chown -R www:www data\n", count($fix));
