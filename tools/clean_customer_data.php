<?php
declare(strict_types=1);

/**
 * One-off clean-up of customer data before the customer analysis.
 * Dry run by default; --apply snapshots the database first.
 *
 *   php tools/clean_customer_data.php           # show what would change
 *   php tools/clean_customer_data.php --apply
 *
 * Automatic (safe, mechanical):
 *   - salesperson names differing only by case/spaces → one spelling
 *     (customers.owner + orders.submitter; the user account's spelling wins)
 *   - customers.phone: non-numbers ("wechat") and extra numbers move to the
 *     note; numbers become 08xx
 *   - orders with no customer_id get one when exactly one customer has that name
 * Reported only (an admin decides, in 客户管理 → 数据整理):
 *   - names that may be the same person (erpandi / erfandi)
 *   - customers with no owner
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
require __DIR__ . '/../app/CustomerData.php';
$GLOBALS['config'] = $config;

$apply = in_array('--apply', $argv, true);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo);

echo $apply ? "APPLYING\n" : "Dry run (nothing is written; add --apply)\n";

if ($apply) {
    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $snap = $dir . '/pre_clean_' . date('Ymd_His') . '.sqlite';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($snap));
    echo "Snapshot: {$snap}\n";
}

$accounts = $pdo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

// 1) Case / space variants of salesperson names.
$merges = CustomerData::case_merges(CustomerData::name_variants($pdo), $accounts);
echo "\n1) Salesperson spellings to unify: " . count($merges) . "\n";
$nameStats = ['customers' => 0, 'orders' => 0];
if ($apply) {
    $pdo->beginTransaction();
}
foreach ($merges as $raw => $canon) {
    // Exact-raw match here (case_merges already grouped them), so "Fera" is not touched when renaming "fera".
    $c = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE owner = ?');
    $c->execute([$raw]);
    $o = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE submitter = ?');
    $o->execute([$raw]);
    printf("   \"%s\" → \"%s\"  (%d customers, %d orders)\n", $raw, $canon, $c->fetchColumn(), $o->fetchColumn());
    if ($apply) {
        $u1 = $pdo->prepare('UPDATE customers SET owner = ? WHERE owner = ?');
        $u1->execute([$canon, $raw]);
        $u2 = $pdo->prepare('UPDATE orders SET submitter = ? WHERE submitter = ?');
        $u2->execute([$canon, $raw]);
        $nameStats['customers'] += $u1->rowCount();
        $nameStats['orders'] += $u2->rowCount();
    }
}

// 2) Phones.
$phoneRows = $pdo->query("SELECT id, name, phone, note FROM customers WHERE TRIM(COALESCE(phone, '')) <> ''")->fetchAll();
$phoneChanges = 0;
$moved = 0;
$updPhone = $pdo->prepare('UPDATE customers SET phone = ?, note = ? WHERE id = ?');
echo "\n2) Phone numbers\n";
foreach ($phoneRows as $r) {
    [$phone, $toNote] = CustomerData::normalize_phone($r['phone']);
    if ($phone === $r['phone'] && $toNote === '') {
        continue;
    }
    $phoneChanges++;
    $note = (string) $r['note'];
    if ($toNote !== '') {
        $moved++;
        $note = CustomerData::append_note($note, '原电话栏: ' . $toNote);
    }
    if ($phoneChanges <= 15 || $toNote !== '') {
        printf("   #%d %s: \"%s\" → \"%s\"%s\n", $r['id'], $r['name'], $r['phone'], $phone, $toNote !== '' ? "  (to note: {$toNote})" : '');
    }
    if ($apply) {
        $updPhone->execute([$phone, $note, $r['id']]);
    }
}
printf("   %d phone fields change, %d of them move text to the note\n", $phoneChanges, $moved);

// 3) Orders without a customer link.
echo "\n3) Orders with no customer_id\n";
$linked = 0;
foreach ($pdo->query('SELECT id, order_no, customer_name FROM orders WHERE customer_id IS NULL')->fetchAll() as $o) {
    $m = $pdo->prepare('SELECT id FROM customers WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))');
    $m->execute([$o['customer_name']]);
    $ids = $m->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) {
        $linked++;
        printf("   %s \"%s\" → customer #%d\n", $o['order_no'], $o['customer_name'], $ids[0]);
        if ($apply) {
            $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([$ids[0], $o['id']]);
        }
    } else {
        printf("   %s \"%s\" — %s, left as is\n", $o['order_no'], $o['customer_name'], $ids ? count($ids) . ' customers share that name' : 'no customer with that name');
    }
}

if ($apply) {
    $pdo->commit();
    audit($pdo, 'customers', 'cleanup', 'customer', null, 'clean_customer_data',
        sprintf('销售名统一 %d 种写法 (客户 %d / 订单 %d); 电话整理 %d 条 (移入备注 %d); 订单补关联客户 %d',
            count($merges), $nameStats['customers'], $nameStats['orders'], $phoneChanges, $moved, $linked));
}

// 4) For the admin page.
$names = array_unique(array_map(fn($n) => $merges[$n] ?? trim($n), array_keys(CustomerData::name_variants($pdo))));
$ignored = json_decode((string) $pdo->query("SELECT v FROM app_meta WHERE k = 'salesname_ignored'")->fetchColumn(), true) ?: [];
$pairs = CustomerData::similar_pairs($names, $ignored);
echo "\n4) Possibly the same person (decide in 客户管理 → 数据整理): " . count($pairs) . "\n";
foreach ($pairs as [$a, $b]) {
    echo "   {$a}  ↔  {$b}\n";
}
$unowned = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE TRIM(COALESCE(owner, '')) = ''")->fetchColumn();
echo "\n5) Customers with no owner (assign in 客户管理 → 数据整理): {$unowned}\n";
