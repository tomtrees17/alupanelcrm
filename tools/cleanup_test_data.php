<?php
declare(strict_types=1);

/**
 * Delete test / demo orders and their paperwork.
 *
 * Deleting business records is irreversible, so this tool is deliberately
 * awkward: it prints what it would do and exits. Nothing is written without
 * --apply, and it refuses outright to touch anything with money against it.
 *
 *   php tools/cleanup_test_data.php                        # what looks like test data
 *   php tools/cleanup_test_data.php --customer="8888"      # dry run, one customer
 *   php tools/cleanup_test_data.php --seed                 # dry run, the demo customers
 *   php tools/cleanup_test_data.php --invoice="136 - AMI - INV - 06 - 26"
 *   php tools/cleanup_test_data.php --orphans              # invoices whose order is gone
 *   php tools/cleanup_test_data.php --customer="8888" --apply
 *
 * Options:
 *   --apply          actually delete (default is dry run)
 *   --restore-stock  add back stock that a deleted shipped order had deducted
 *   --force          also delete records that have payments registered (dangerous)
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

/** Demo rows written by Database::seed() on a fresh database. */
const SEED_CUSTOMERS = [
    'PT Maju Bersama', 'CV Dagang Makmur', 'PT Konstruksi Prima', 'CV Anugerah Jaya',
    'PT Grup Sejahtera', 'PT Logistik Prima', 'PT Belanja Online', 'PT Teknologi Maju',
    'Startup Nusantara',
];

$opts = getopt('', ['apply', 'restore-stock', 'force', 'customer:', 'invoice:', 'order:', 'seed', 'orphans']);
$apply   = isset($opts['apply']);
$restore = isset($opts['restore-stock']);
$force   = isset($opts['force']);

$pdo = Database::connect($config['db_path']);
$n = fn($v) => number_format((float) $v, 0, ',', '.');

// ── Pick the targets ─────────────────────────────────────────────────────────
$where = '';
$args = [];
if (isset($opts['customer'])) {
    $where = 'iv.customer LIKE ? OR iv.bill_to_name LIKE ?';
    $args = ['%' . $opts['customer'] . '%', '%' . $opts['customer'] . '%'];
} elseif (isset($opts['invoice'])) {
    $where = 'iv.invoice_no = ?';
    $args = [$opts['invoice']];
} elseif (isset($opts['order'])) {
    $where = 'o.order_no = ?';
    $args = [$opts['order']];
} elseif (isset($opts['seed'])) {
    $where = 'iv.customer IN (' . implode(',', array_fill(0, count(SEED_CUSTOMERS), '?')) . ')';
    $args = SEED_CUSTOMERS;
} elseif (isset($opts['orphans'])) {
    $where = 'iv.order_id IS NULL';
} else {
    // No selector: just survey what is there and stop.
    echo "用法见文件顶部注释。当前数据概览：\n\n";
    echo '  发票总数        ' . $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() . "\n";
    echo '  订单总数        ' . $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() . "\n";
    echo '  孤儿发票        ' . $pdo->query('SELECT COUNT(*) FROM invoices WHERE order_id IS NULL')->fetchColumn() . "  (--orphans)\n";
    echo '  种子示例发票    ' . (int) $pdo->query('SELECT COUNT(*) FROM invoices WHERE customer IN (' .
        implode(',', array_map(fn($c) => $pdo->quote($c), SEED_CUSTOMERS)) . ')')->fetchColumn() . "  (--seed)\n\n";
    echo "按客户看（前 20，可用 --customer=\"名字\" 定位）：\n";
    foreach ($pdo->query('SELECT customer, COUNT(*) c, SUM(total) s,
                          SUM(CASE WHEN amount_paid > 0 THEN 1 ELSE 0 END) paid
                          FROM invoices GROUP BY customer ORDER BY c DESC, s DESC LIMIT 20') as $r) {
        printf("  %3d 张  %16s  %s%s\n", $r['c'], $n($r['s']), $r['customer'],
            (int) $r['paid'] > 0 ? '   ← 有收款记录' : '');
    }
    exit(0);
}

$sql = 'SELECT iv.*, o.order_no, o.status AS order_status, o.id AS oid
        FROM invoices iv LEFT JOIN orders o ON o.id = iv.order_id
        WHERE ' . $where . ' ORDER BY iv.id';
$st = $pdo->prepare($sql);
$st->execute($args);
$targets = $st->fetchAll();

if (!$targets) {
    echo "没有匹配的记录。\n";
    exit(0);
}

// ── Report, and refuse anything with money against it ────────────────────────
$deletable = [];
$blocked = [];
echo ($apply ? "将要删除" : "【预演，不会改动任何数据】将要删除") . "以下记录：\n\n";

foreach ($targets as $t) {
    $items = $pdo->prepare('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ?');
    $items->execute([$t['id']]);
    $pays = $pdo->prepare('SELECT COUNT(*), COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ?');
    $pays->execute([$t['id']]);
    [$payCount, $paySum] = $pays->fetch(PDO::FETCH_NUM);

    $hasMoney = (int) $payCount > 0 || (float) $t['amount_paid'] > 0;

    printf("  发票 %-28s %14s  客户 %s\n", $t['invoice_no'], $n($t['total']), $t['customer']);
    printf("       明细 %d 行", (int) $items->fetchColumn());
    if ((int) $payCount > 0) {
        printf("；收款 %d 笔 合计 %s", (int) $payCount, $n($paySum));
    }
    if ($t['order_no']) {
        printf("；订单 %s (%s)", $t['order_no'], order_status_label((string) $t['order_status']));
    } else {
        echo '；订单已不存在';
    }
    if ($t['do_number']) {
        printf("；送货单 %s", $t['do_number']);
    }
    echo "\n";

    if ($hasMoney && !$force) {
        echo "       ⚠ 有收款记录，已跳过（确认是测试数据才加 --force）\n";
        $blocked[] = $t;
    } else {
        $deletable[] = $t;
    }
    echo "\n";
}

printf("合计：可删 %d 条，因有收款跳过 %d 条。\n", count($deletable), count($blocked));

if (!$apply) {
    echo "\n这是预演。确认无误后加 --apply 执行。\n";
    echo "强烈建议先备份： php tools/backup_db.php\n";
    exit(0);
}
if (!$deletable) {
    echo "没有可删除的记录。\n";
    exit(0);
}

// ── Delete ───────────────────────────────────────────────────────────────────
// One transaction: a half-finished cleanup is worse than no cleanup.
$pdo->beginTransaction();
try {
    $restored = 0;
    foreach ($deletable as $t) {
        $oid = $t['oid'] !== null ? (int) $t['oid'] : null;

        // Put back stock that this order's shipment deducted.
        if ($restore && $oid !== null) {
            $tx = $pdo->prepare("SELECT product_id, qty FROM stock_txn WHERE ref = ? AND type = 'out_auto'");
            $tx->execute([$t['order_no']]);
            foreach ($tx->fetchAll() as $mv) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')
                    ->execute([(int) $mv['qty'], (int) $mv['product_id']]);
                $restored++;
            }
            $pdo->prepare("DELETE FROM stock_txn WHERE ref = ? AND type = 'out_auto'")->execute([$t['order_no']]);
        }

        // invoice_items and payments cascade with the invoice; order_items with the order.
        $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$t['id']]);
        if ($t['do_number']) {
            $pdo->prepare('DELETE FROM delivery_orders WHERE do_no = ?')->execute([$t['do_number']]);
        }
        if ($oid !== null) {
            $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$oid]);
        }
    }
    recompute_reservations($pdo);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\n删除失败，已回滚，数据未改动：\n  " . $e->getMessage() . "\n";
    exit(1);
}

// Logged after commit: the trail records only what actually persisted.
foreach ($deletable as $t) {
    audit(
        $pdo,
        'finance',
        'delete',
        'invoice',
        (int) $t['id'],
        (string) $t['invoice_no'],
        sprintf(
            '清理测试数据（CLI）：客户 %s；金额 %s；订单 %s%s',
            (string) $t['customer'],
            idr((float) $t['total']),
            (string) ($t['order_no'] ?: '(无)'),
            $restore ? '；已回补库存' : ''
        )
    );
}

printf("\n已删除 %d 条。", count($deletable));
if ($restore) {
    printf(" 回补了 %d 条库存流水。", $restored);
}
echo "\n审计日志已记录。别忘了： chown -R www:www data\n";
