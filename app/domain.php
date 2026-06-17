<?php
declare(strict_types=1);

/**
 * Shared business logic (loaded on every request via bootstrap).
 */

/** Apply a stock movement and log a transaction. */
function adjust_stock(PDO $pdo, int $productId, string $type, int $qty, string $ref, string $note = ''): void
{
    $delta = $type === 'in' ? $qty : -$qty;
    $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$delta, $productId]);
    $pdo->prepare('INSERT INTO stock_txn (product_id,type,qty,ref,note) VALUES (?,?,?,?,?)')
        ->execute([$productId, $type, $qty, $ref, $note]);
}

/** Best-effort product match for an order line (sku + spec). */
function match_product_id(PDO $pdo, string $sku, string $spec): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND spec = ? LIMIT 1');
    $stmt->execute([$sku, $spec]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $stmt = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $stmt->execute([$sku]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Return items whose ordered qty exceeds current product stock.
 * $items: array of ['sku'=>, 'spec'=>, 'qty'=>]. Unknown products are skipped.
 * Each result: ['sku','spec','need','have'].
 */
function stock_shortages(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
    $short = [];
    foreach ($items as $it) {
        $pid = match_product_id($pdo, (string) ($it['sku'] ?? ''), (string) ($it['spec'] ?? ''));
        if (!$pid) {
            continue;
        }
        $stmt->execute([$pid]);
        $have = (int) $stmt->fetchColumn();
        $need = (int) ceil((float) ($it['qty'] ?? 0));
        if ($need > $have) {
            $short[] = ['sku' => $it['sku'] ?? '', 'spec' => $it['spec'] ?? '', 'need' => $need, 'have' => $have];
        }
    }
    return $short;
}

/**
 * Items whose qty exceeds AVAILABLE stock (= stock − reserved by other open orders).
 * Used when placing a new order. $items: ['product_id'?, 'sku', 'spec', 'qty'].
 */
function available_shortages(PDO $pdo, array $items): array
{
    $stmt = $pdo->prepare('SELECT stock, reserved FROM products WHERE id = ?');
    $short = [];
    foreach ($items as $it) {
        $pid = $it['product_id'] ?? null;
        if (!$pid) {
            $pid = match_product_id($pdo, (string) ($it['sku'] ?? ''), (string) ($it['spec'] ?? ''));
        }
        if (!$pid) {
            continue;
        }
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if (!$row) {
            continue;
        }
        $avail = (int) $row['stock'] - (int) $row['reserved'];
        $need = (int) ceil((float) ($it['qty'] ?? 0));
        if ($need > $avail) {
            $short[] = ['sku' => $it['sku'] ?? '', 'spec' => $it['spec'] ?? '', 'need' => $need, 'have' => max(0, $avail)];
        }
    }
    return $short;
}

/** Recompute every product's reserved qty from currently-open (pending) orders. */
function recompute_reservations(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE products SET reserved = COALESCE((
             SELECT SUM(oi.qty) FROM order_items oi JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = products.id AND o.status LIKE 'pending_%'
         ), 0)"
    );
}

/** Human-readable shortage message (bilingual prefix). */
function shortage_message(array $short): string
{
    $parts = array_map(
        fn($s) => "{$s['sku']} ({$s['spec']}) " . t('need') . " {$s['need']} / " . t('have') . " {$s['have']}",
        $short
    );
    return t('stock_insufficient') . '：' . implode('；', $parts);
}

/** Order subtotal (items) and grand total (with shipping). */
function order_totals(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $subtotal = (float) $stmt->fetchColumn();
    $ship = (float) $pdo->query("SELECT shipping_cost FROM orders WHERE id = $orderId")->fetchColumn();
    return ['subtotal' => $subtotal, 'shipping' => $ship, 'total' => $subtotal + $ship];
}

/** Next sequential sales-order number: 0477/AMI-CO/MM/YY */
function next_order_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT order_no FROM orders") as $r) {
        if (preg_match('/^(\d+)\//', (string) $r['order_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    $seq = str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    return sprintf('%s/AMI-CO/%s/%s', $seq, date('m'), date('y'));
}

/** Next delivery-order number: DO-YYYY-NNN */
function next_do_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT do_no FROM delivery_orders") as $r) {
        if (preg_match('/(\d+)$/', (string) $r['do_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    return sprintf('DO-%s-%03d', date('Y'), $max + 1);
}

/** Next invoice number, matching the company format: "480 - AMI - INV - 04 - 26". */
function next_invoice_no(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT invoice_no FROM invoices") as $r) {
        if (preg_match('/^(\d+)\s*-/', (string) $r['invoice_no'], $mm)) {
            $max = max($max, (int) $mm[1]);
        }
    }
    return sprintf('%d - AMI - INV - %s - %s', $max + 1, date('m'), date('y'));
}

/** Indonesian "terbilang": spell a Rupiah amount in words. */
function terbilang($number): string
{
    $n = (int) round((float) $number);
    if ($n === 0) {
        return 'Nol Rupiah';
    }
    $words = trim(terbilang_helper($n));
    return ucwords($words) . ' Rupiah';
}

function terbilang_helper(int $n): string
{
    $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12) {
        return ' ' . $satuan[$n];
    }
    if ($n < 20) {
        return terbilang_helper($n - 10) . ' belas';
    }
    if ($n < 100) {
        return terbilang_helper(intdiv($n, 10)) . ' puluh' . terbilang_helper($n % 10);
    }
    if ($n < 200) {
        return ' seratus' . terbilang_helper($n - 100);
    }
    if ($n < 1000) {
        return terbilang_helper(intdiv($n, 100)) . ' ratus' . terbilang_helper($n % 100);
    }
    if ($n < 2000) {
        return ' seribu' . terbilang_helper($n - 1000);
    }
    if ($n < 1000000) {
        return terbilang_helper(intdiv($n, 1000)) . ' ribu' . terbilang_helper($n % 1000);
    }
    if ($n < 1000000000) {
        return terbilang_helper(intdiv($n, 1000000)) . ' juta' . terbilang_helper($n % 1000000);
    }
    return terbilang_helper(intdiv($n, 1000000000)) . ' miliar' . terbilang_helper($n % 1000000000);
}

// ──────────────────────────────────────────────────────────
//  Login brute-force throttle (per client IP, sliding window)
// ──────────────────────────────────────────────────────────

const LOGIN_MAX_ATTEMPTS   = 8;     // failures allowed within the window
const LOGIN_WINDOW_SECONDS = 900;   // 15-minute window / lockout

function login_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Remaining lockout for the current IP in minutes (0 = not locked). */
function login_lockout_minutes(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) c, MIN(attempt_time) m FROM login_attempts WHERE ip = ? AND attempt_time > ?'
    );
    $stmt->execute([login_client_ip(), time() - LOGIN_WINDOW_SECONDS]);
    $row = $stmt->fetch();
    if ((int) ($row['c'] ?? 0) < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    // Locked until the oldest in-window failure ages out.
    $remain = ((int) $row['m'] + LOGIN_WINDOW_SECONDS) - time();
    return max(1, (int) ceil($remain / 60));
}

/** Record a failed login and prune attempts older than the window. */
function login_record_failure(PDO $pdo, string $email): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, email, attempt_time) VALUES (?,?,?)')
        ->execute([login_client_ip(), mb_substr($email, 0, 190), time()]);
    $pdo->prepare('DELETE FROM login_attempts WHERE attempt_time < ?')
        ->execute([time() - LOGIN_WINDOW_SECONDS]);
}

/** Clear an IP's failed attempts after a successful login. */
function login_clear_failures(PDO $pdo): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([login_client_ip()]);
}

/** Recompute an invoice's payment_status from amount_paid / due_date. */
function refresh_invoice_status(PDO $pdo, int $invoiceId, string $today): void
{
    $inv = $pdo->prepare('SELECT total, amount_paid, due_date FROM invoices WHERE id = ?');
    $inv->execute([$invoiceId]);
    $row = $inv->fetch();
    if (!$row) {
        return;
    }
    $paid = (float) $row['amount_paid'];
    $total = (float) $row['total'];
    if ($paid >= $total && $total > 0) {
        $status = 'paid';
    } elseif (!empty($row['due_date']) && $row['due_date'] < $today) {
        $status = 'overdue';
    } elseif ($paid > 0) {
        $status = 'partial';
    } else {
        $status = 'pending';
    }
    $pdo->prepare('UPDATE invoices SET payment_status = ? WHERE id = ?')->execute([$status, $invoiceId]);
}
